<?php
/**
 * Server-rendered withdrawal flow controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Integration\NotEligibleException;
use Recesso54bis\Integration\RequestedItemsResolver;
use Recesso54bis\Integration\WithdrawalService;
use Recesso54bis\Persistence\DuplicateOpenRequestException;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\EligibilityController;
use Recesso54bis\Rest\PermissionGate;
use Recesso54bis\Support\ClientIp;
use Recesso54bis\Support\Nonces;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the two-step withdrawal flow with plain server rendering and full page reloads, so it works
 * without JavaScript. The mandatory wording is honoured: the entry control reads
 * «recedere dal contratto qui» and step two is «conferma recesso». Every state change is a POST to
 * admin-post.php guarded by a nonce plus capability/token authorisation, validated and sanitised.
 */
final class FlowController {

	/**
	 * Coordination service.
	 *
	 * @var WithdrawalService
	 */
	private WithdrawalService $service;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Permission gate.
	 *
	 * @var PermissionGate
	 */
	private PermissionGate $gate;

	/**
	 * Eligibility adapter.
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Construct the controller.
	 *
	 * @param WithdrawalService  $service     Coordination service.
	 * @param RequestRepository  $requests    Request repository.
	 * @param PermissionGate     $gate        Permission gate.
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 */
	public function __construct(
		WithdrawalService $service,
		RequestRepository $requests,
		PermissionGate $gate,
		EligibilityAdapter $eligibility
	) {
		$this->service     = $service;
		$this->requests    = $requests;
		$this->gate        = $gate;
		$this->eligibility = $eligibility;
	}

	/**
	 * Register the admin-post handlers (available to logged-in and guest submitters).
	 */
	public function register(): void {
		add_action( 'admin_post_recesso_dig_declare', array( $this, 'handle_declare' ) );
		add_action( 'admin_post_nopriv_recesso_dig_declare', array( $this, 'handle_declare' ) );
		add_action( 'admin_post_recesso_dig_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_nopriv_recesso_dig_confirm', array( $this, 'handle_confirm' ) );
	}

	/**
	 * Render the appropriate screen for the current request (used by the block and the shortcode).
	 * Returns an empty string when no flow step is active on the page.
	 */
	public function render(): string {
		$output = $this->render_step();

		// Enqueue the flow's view script and stylesheet only when a flow step is actually on the page
		// (covers both the block and the shortcode); the flow works without either.
		if ( '' !== $output ) {
			$this->enqueue_view_script();
			$this->enqueue_view_style();
		}

		return $output;
	}

	/**
	 * Resolve and render the active flow step (empty string when none is active on the page).
	 */
	private function render_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing; state changes go through nonce-protected POST handlers.
		$step = isset( $_GET[ FlowUrls::QV_STEP ] ) ? sanitize_key( wp_unslash( $_GET[ FlowUrls::QV_STEP ] ) ) : '';

		switch ( $step ) {
			case FlowUrls::STEP_DECLARE:
				return $this->render_declaration();
			case FlowUrls::STEP_CONFIRM:
				return $this->render_confirm();
			case FlowUrls::STEP_DONE:
				return $this->render_done();
			case 'message':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect message.
				$msg = isset( $_GET['recesso_dig_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['recesso_dig_msg'] ) ) : '';
				return '' === $msg ? '' : $this->message( $msg, 'info' );
			default:
				return '';
		}
	}

	/**
	 * Enqueue the flow's view script (the block's registered viewScript handle), with the plugin's
	 * bundled JS translations. Idempotent and safe whether the flow is shown via the block or the
	 * shortcode; does nothing if the build is not present.
	 */
	private function enqueue_view_script(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}

		$handle = generate_block_asset_handle( 'recesso-digitale/withdrawal-button', 'viewScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_enqueue_script( $handle );
		wp_set_script_translations(
			$handle,
			'erred-eu-order-withdrawal-for-woocommerce',
			plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'languages'
		);
	}

	/**
	 * Enqueue the flow's view stylesheet (the block's registered viewStyle handle). Idempotent and safe
	 * whether the flow is shown via the block or the shortcode; does nothing if the build is not present.
	 */
	private function enqueue_view_style(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}

		$handle = generate_block_asset_handle( 'recesso-digitale/withdrawal-button', 'viewStyle' );
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			return;
		}

		wp_enqueue_style( $handle );
	}

	/**
	 * Render step 1: the declaration form for an authorised, eligible order.
	 */
	private function render_declaration(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; submission is nonce-protected.
		$order_id = isset( $_GET[ FlowUrls::QV_ORDER ] ) ? absint( wp_unslash( $_GET[ FlowUrls::QV_ORDER ] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; submission is nonce-protected.
		$token = isset( $_GET[ FlowUrls::QV_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ FlowUrls::QV_TOKEN ] ) ) : '';

		if ( ! $this->gate->can_act_on_order( $order_id, $token, time() ) ) {
			return $this->message( __( 'This withdrawal link is not valid or has expired.', 'erred-eu-order-withdrawal-for-woocommerce' ), 'error' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $this->message( __( 'This withdrawal link is not valid or has expired.', 'erred-eu-order-withdrawal-for-woocommerce' ), 'error' );
		}

		$eligibility = $this->eligibility->for_order( $order );
		if ( ! $eligibility->is_eligible ) {
			return $this->message( EligibilityController::reason_label( $eligibility->reason ), 'info' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a prior validation error flag.
		$error = isset( $_GET['recesso_dig_error'] ) ? sanitize_text_field( wp_unslash( $_GET['recesso_dig_error'] ) ) : '';

		return Templates::render(
			'declaration',
			array(
				'action_url'         => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'nonce_action'       => Nonces::SUBMIT,
				'nonce_name'         => '_recesso_dig_nonce',
				'order_id'           => $order_id,
				'token'              => $token,
				'flow_url'           => $this->current_page_url(),
				'contract_reference' => $order->get_order_number(),
				'consumer_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'confirmation_email' => $order->get_billing_email(),
				'lines'              => $this->line_choices( $order, $eligibility->available_quantities ),
				'error'              => '' === $error ? '' : __( 'Please provide a valid name and email address, and select at least one item to withdraw.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			)
		);
	}

	/**
	 * Build the selectable line list for the declaration form: each eligible order line with its
	 * display name (variation included), total ordered quantity and the units still available to
	 * withdraw (the input's maximum).
	 *
	 * @param \WC_Order       $order     The order.
	 * @param array<int, int> $available Map line_id => units still available to withdraw.
	 *
	 * @return array<int, array{id: int, label: string, quantity: int, available: int, thumbnail: string}>
	 */
	private function line_choices( \WC_Order $order, array $available ): array {
		$choices = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_id = (int) $item_id;
			if ( ! array_key_exists( $line_id, $available ) ) {
				continue;
			}

			$choices[] = array(
				'id'        => $line_id,
				'label'     => $item->get_name(),
				'quantity'  => (int) $item->get_quantity(),
				'available' => (int) $available[ $line_id ],
				'thumbnail' => RequestedItemsResolver::thumbnail( $item ),
			);
		}

		return $choices;
	}

	/**
	 * The itemised lines a request concerns, for the review and completion screens: each withdrawn line
	 * with its product name, quantity and thumbnail markup. Shares the {@see RequestedItemsResolver}
	 * with the durable receipt and acknowledgement email so every surface itemises the request
	 * identically.
	 *
	 * @param WithdrawalRequest $request The request.
	 *
	 * @return array<int, array{line_id: int, name: string, quantity: int, thumbnail_html?: string}>
	 */
	private function request_items( WithdrawalRequest $request ): array {
		$order = wc_get_order( $request->order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		return RequestedItemsResolver::resolve( $request, $order, true );
	}

	/**
	 * Render step 2: the confirmation screen for a pending request.
	 */
	private function render_confirm(): string {
		$request = $this->authorised_request_from_query();
		if ( ! $request instanceof WithdrawalRequest ) {
			return $this->message( __( 'This withdrawal link is not valid or has expired.', 'erred-eu-order-withdrawal-for-woocommerce' ), 'error' );
		}

		if ( $request->is_confirmed() ) {
			return $this->render_done();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; submission is nonce-protected.
		$token = isset( $_GET[ FlowUrls::QV_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ FlowUrls::QV_TOKEN ] ) ) : '';

		return Templates::render(
			'confirm',
			array(
				'action_url'         => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'nonce_action'       => Nonces::CONFIRM,
				'nonce_name'         => '_recesso_dig_nonce',
				'request_id'         => $request->id,
				'token'              => $token,
				'flow_url'           => $this->current_page_url(),
				'contract_reference' => $request->contract_reference,
				'consumer_name'      => $request->consumer_name,
				'confirmation_email' => $request->confirmation_email,
				'items'              => $this->request_items( $request ),
				'confirm_label'      => __( 'Confirm withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ),
			)
		);
	}

	/**
	 * Render the final "done" screen.
	 */
	private function render_done(): string {
		$request = $this->authorised_request_from_query();
		if ( ! $request instanceof WithdrawalRequest ) {
			return $this->message( __( 'This withdrawal link is not valid or has expired.', 'erred-eu-order-withdrawal-for-woocommerce' ), 'error' );
		}

		return Templates::render(
			'done',
			array(
				'contract_reference' => $request->contract_reference,
				'confirmation_email' => $request->confirmation_email,
				'items'              => $this->request_items( $request ),
			)
		);
	}

	/**
	 * Handle the declaration POST (step 1).
	 */
	public function handle_declare(): void {
		check_admin_referer( Nonces::SUBMIT, '_recesso_dig_nonce' );

		// Honeypot: a hidden field only automated bots complete. If filled, drop the submission quietly.
		if ( isset( $_POST['recesso_dig_hp'] ) && '' !== sanitize_text_field( wp_unslash( $_POST['recesso_dig_hp'] ) ) ) {
			$this->redirect( home_url( '/' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! $this->gate->can_act_on_order( $order_id, $token, time() ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$consumer_name = isset( $_POST['consumer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_name'] ) ) : '';
		$email         = isset( $_POST['confirmation_email'] ) ? sanitize_email( wp_unslash( $_POST['confirmation_email'] ) ) : '';

		// Optional fields: a refund IBAN (normalised to A-Z0-9) and a free-text reason for withdrawing.
		$iban   = isset( $_POST['refund_iban'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['refund_iban'] ) ) ) : '';
		$iban   = (string) preg_replace( '/[^A-Z0-9]/', '', $iban );
		$reason = isset( $_POST['withdrawal_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['withdrawal_reason'] ) ) : '';

		// The consumer picks which lines to withdraw with checkboxes (`requested_lines[]`, carrying the
		// line id) and, for lines ordered in quantity > 1, how many units (`requested_qty[line_id]`).
		// A line counts only when its checkbox is present; an unchecked line is ignored even if a stale
		// quantity is posted for it. Selecting a single-unit line implies one unit. At least one line
		// must be selected. Final clamping to the units available happens in the service (fail closed).
		$selected = array();
		if ( isset( $_POST['requested_lines'] ) && is_array( $_POST['requested_lines'] ) ) {
			$selected = array_filter( array_map( 'absint', (array) wp_unslash( $_POST['requested_lines'] ) ) );
		}

		$raw_quantities = array();
		if ( isset( $_POST['requested_qty'] ) && is_array( $_POST['requested_qty'] ) ) {
			// Sanitise the whole map up front: keys (line ids) and values (quantities) to integers.
			$sanitised = array_map( 'absint', (array) wp_unslash( $_POST['requested_qty'] ) );
			foreach ( $sanitised as $line_id => $quantity ) {
				$raw_quantities[ absint( $line_id ) ] = $quantity;
			}
		}

		$requested_items = array();
		foreach ( $selected as $line_id ) {
			if ( $line_id <= 0 ) {
				continue;
			}
			$quantity                    = $raw_quantities[ $line_id ] ?? 0;
			$requested_items[ $line_id ] = $quantity > 0 ? $quantity : 1;
		}

		if ( '' === $consumer_name || false === is_email( $email ) || array() === $requested_items ) {
			$this->redirect(
				$this->step_url(
					FlowUrls::STEP_DECLARE,
					array(
						FlowUrls::QV_ORDER  => $order_id,
						FlowUrls::QV_TOKEN  => $token,
						'recesso_dig_error' => '1',
					)
				)
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		try {
			$request = $this->service->create_declaration(
				$order,
				array(
					'consumer_name'      => $consumer_name,
					'contract_reference' => $order->get_order_number(),
					'confirmation_email' => $email,
					'requested_items'    => $requested_items,
					'refund_iban'        => $iban,
					'withdrawal_reason'  => $reason,
				),
				ClientIp::packed()
			);
		} catch ( DuplicateOpenRequestException $e ) {
			$this->redirect( $this->message_url( __( 'A withdrawal request is already in progress for this order.', 'erred-eu-order-withdrawal-for-woocommerce' ) ) );
			return;
		} catch ( NotEligibleException $e ) {
			$this->redirect( $this->message_url( EligibilityController::reason_label( $e->reason() ) ) );
			return;
		} catch ( \Throwable $e ) {
			// A legally-required function must never white-screen: degrade to a friendly message and
			// keep the consumer on a usable page (e.g. a transient persistence failure or a pending
			// schema migration). The failure is not silently lost — nothing was recorded, so the
			// consumer can retry.
			$this->redirect( $this->message_url( __( 'We could not record your withdrawal right now. Please try again in a few moments.', 'erred-eu-order-withdrawal-for-woocommerce' ) ) );
			return;
		}

		$this->redirect(
			$this->step_url(
				FlowUrls::STEP_CONFIRM,
				array(
					FlowUrls::QV_ID    => $request->id,
					FlowUrls::QV_TOKEN => $token,
				)
			)
		);
	}

	/**
	 * Handle the confirmation POST (step 2).
	 */
	public function handle_confirm(): void {
		check_admin_referer( Nonces::CONFIRM, '_recesso_dig_nonce' );

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$request = $this->requests->find_by_id( $request_id );
		if ( ! $request instanceof WithdrawalRequest || ! $this->gate->can_act_on_order( $request->order_id, $token, time() ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$this->service->confirm( $request_id, 'consumer' );

		$this->redirect(
			$this->step_url(
				FlowUrls::STEP_DONE,
				array(
					FlowUrls::QV_ID    => $request_id,
					FlowUrls::QV_TOKEN => $token,
				)
			)
		);
	}

	/**
	 * Load and authorise a request referenced by the current query args.
	 */
	private function authorised_request_from_query(): ?WithdrawalRequest {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; submission is nonce-protected.
		$request_id = isset( $_GET[ FlowUrls::QV_ID ] ) ? absint( wp_unslash( $_GET[ FlowUrls::QV_ID ] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; submission is nonce-protected.
		$token = isset( $_GET[ FlowUrls::QV_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ FlowUrls::QV_TOKEN ] ) ) : '';

		$request = $this->requests->find_by_id( $request_id );
		if ( ! $request instanceof WithdrawalRequest ) {
			return null;
		}

		return $this->gate->can_act_on_order( $request->order_id, $token, time() ) ? $request : null;
	}

	/**
	 * Build a flow URL on the current page for a given step.
	 *
	 * @param string               $step Flow step.
	 * @param array<string, mixed> $args Additional query args.
	 */
	private function step_url( string $step, array $args ): string {
		$base = $this->current_base_url();

		return add_query_arg(
			array_merge(
				array(
					FlowUrls::QV_ACTION => 'recesso',
					FlowUrls::QV_STEP   => $step,
				),
				$args
			),
			$base
		);
	}

	/**
	 * Build a flow URL that shows a generic message.
	 *
	 * @param string $message Message to display.
	 */
	private function message_url( string $message ): string {
		return add_query_arg(
			array(
				FlowUrls::QV_STEP => 'message',
				'recesso_dig_msg' => rawurlencode( $message ),
			),
			$this->current_base_url()
		);
	}

	/**
	 * The page URL the flow is hosted on, used as the redirect base. Prefers the explicit, validated
	 * `flow_url` carried by the submitted form (robust against missing/stripped Referer), then the
	 * Referer, then the configured flow page.
	 */
	private function current_base_url(): string {
		$candidates = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers (handle_declare/handle_confirm) verify the nonce before reaching here.
		if ( isset( $_POST['flow_url'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; value is sanitised and redirect-validated below.
			$candidates[] = esc_url_raw( wp_unslash( $_POST['flow_url'] ) );
		}

		$referer = wp_get_referer();
		if ( is_string( $referer ) ) {
			$candidates[] = $referer;
		}

		foreach ( $candidates as $candidate ) {
			$validated = wp_validate_redirect( $candidate, '' );
			if ( '' !== $validated ) {
				return remove_query_arg(
					array( FlowUrls::QV_STEP, FlowUrls::QV_ID, 'recesso_dig_error', 'recesso_dig_msg', '_wpnonce' ),
					$validated
				);
			}
		}

		return FlowPage::url();
	}

	/**
	 * The permalink of the page currently rendering the flow (for the form's hidden base URL).
	 */
	private function current_page_url(): string {
		$id = get_queried_object_id();
		if ( $id > 0 ) {
			$permalink = get_permalink( $id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return FlowPage::url();
	}

	/**
	 * Safely redirect within the site and stop.
	 *
	 * @param string $url Target URL.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( wp_validate_redirect( $url, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Render a generic message screen.
	 *
	 * @param string $message Message text.
	 * @param string $type    One of info|error|success.
	 */
	private function message( string $message, string $type ): string {
		return Templates::render(
			'message',
			array(
				'message' => $message,
				'type'    => $type,
			)
		);
	}
}
