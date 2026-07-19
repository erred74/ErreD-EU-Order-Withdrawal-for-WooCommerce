<?php
/**
 * Server-rendered withdrawal flow controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Email\WithdrawalLinkEmail;
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
use Recesso54bis\Support\RateLimiter;

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
	 * Flow URL builder (for the lookup magic link).
	 *
	 * @var FlowUrls
	 */
	private FlowUrls $urls;

	/**
	 * Rate limiter (throttles the on-page lookup form).
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Construct the controller.
	 *
	 * @param WithdrawalService  $service      Coordination service.
	 * @param RequestRepository  $requests     Request repository.
	 * @param PermissionGate     $gate         Permission gate.
	 * @param EligibilityAdapter $eligibility  Eligibility adapter.
	 * @param FlowUrls           $urls         Flow URL builder.
	 * @param RateLimiter        $rate_limiter Rate limiter.
	 */
	public function __construct(
		WithdrawalService $service,
		RequestRepository $requests,
		PermissionGate $gate,
		EligibilityAdapter $eligibility,
		FlowUrls $urls,
		RateLimiter $rate_limiter
	) {
		$this->service      = $service;
		$this->requests     = $requests;
		$this->gate         = $gate;
		$this->eligibility  = $eligibility;
		$this->urls         = $urls;
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Register the admin-post handlers (available to logged-in and guest submitters).
	 */
	public function register(): void {
		add_action( 'admin_post_recesso_dig_declare', array( $this, 'handle_declare' ) );
		add_action( 'admin_post_nopriv_recesso_dig_declare', array( $this, 'handle_declare' ) );
		add_action( 'admin_post_recesso_dig_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_nopriv_recesso_dig_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_recesso_dig_lookup', array( $this, 'handle_lookup' ) );
		add_action( 'admin_post_nopriv_recesso_dig_lookup', array( $this, 'handle_lookup' ) );
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
				// No signed link on the URL: offer the order-lookup form, which emails a signed link to
				// the order's own address (never rendering the flow inline), so orders are not enumerable.
				return $this->render_lookup();
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
	 * Render the order-lookup form shown when the page is reached without a signed link (the footer
	 * link, or a direct visit). On submit the plugin emails a signed withdrawal link to the order's own
	 * address; the flow itself is never rendered inline, so orders cannot be enumerated here.
	 */
	private function render_lookup(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a uniform post-submit notice.
		$result = isset( $_GET['recesso_dig_lookup'] ) ? sanitize_key( wp_unslash( $_GET['recesso_dig_lookup'] ) ) : '';

		$notice      = '';
		$notice_type = 'info';
		switch ( $result ) {
			case 'sent':
				// Deliberately uniform: identical whether or not an order matched, to avoid enumeration.
				$notice      = __( 'If an order matching those details exists, we have sent a withdrawal link to the email address on file. Please check your inbox.', 'erred-eu-order-withdrawal-for-woocommerce' );
				$notice_type = 'success';
				break;
			case 'invalid':
				$notice      = __( 'Please enter both your order number and the email address used for the order.', 'erred-eu-order-withdrawal-for-woocommerce' );
				$notice_type = 'error';
				break;
			case 'throttled':
				$notice      = __( 'Too many requests. Please wait a few minutes and try again.', 'erred-eu-order-withdrawal-for-woocommerce' );
				$notice_type = 'error';
				break;
		}

		return Templates::render(
			'lookup',
			array(
				'action_url'   => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'nonce_action' => Nonces::LOOKUP,
				'nonce_name'   => '_recesso_dig_nonce',
				'flow_url'     => $this->current_page_url(),
				'notice'       => $notice,
				'notice_type'  => $notice_type,
			)
		);
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
	 * Handle the order-lookup POST: email a signed withdrawal link to the order's own address.
	 *
	 * Anti-enumeration by design: the response is always uniform (`sent`) whether or not an order
	 * matched, the link is delivered only to the order's stored email (never to the address typed in
	 * the browser), attempts are rate-limited per IP, and a honeypot drops bots. The flow is never
	 * rendered inline from here.
	 */
	public function handle_lookup(): void {
		check_admin_referer( Nonces::LOOKUP, '_recesso_dig_nonce' );

		$flow_url = isset( $_POST['flow_url'] ) ? esc_url_raw( wp_unslash( $_POST['flow_url'] ) ) : '';
		$flow_url = wp_validate_redirect( $flow_url, FlowPage::url() );

		// Honeypot: a hidden field only automated bots complete. If filled, show the uniform result.
		if ( isset( $_POST['recesso_dig_hp'] ) && '' !== sanitize_text_field( wp_unslash( $_POST['recesso_dig_hp'] ) ) ) {
			$this->redirect( $this->lookup_result_url( $flow_url, 'sent' ) );
		}

		// Throttle per IP. Fail closed on abuse: report the uniform result without sending or probing.
		$bucket = 'lookup_' . ClientIp::get();
		if ( $this->rate_limiter->too_many_attempts( $bucket ) ) {
			$this->redirect( $this->lookup_result_url( $flow_url, 'throttled' ) );
		}
		$this->rate_limiter->hit( $bucket );

		$order_number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
		$email        = isset( $_POST['order_email'] ) ? sanitize_email( wp_unslash( $_POST['order_email'] ) ) : '';

		if ( '' === $order_number || false === is_email( $email ) ) {
			$this->redirect( $this->lookup_result_url( $flow_url, 'invalid' ) );
		}

		// Email the link whenever the order and email match, regardless of eligibility: a legitimate
		// consumer must always get a response on their own address. Eligibility is (re)checked when the
		// link is opened — the declaration screen then explains any ineligibility (expired window, etc.)
		// instead of leaving the consumer with silence that looks like a broken mailer.
		$order = $this->match_order( $order_number, $email );
		if ( $order instanceof \WC_Order ) {
			$this->send_link_email( $order, $flow_url );
		}

		// Always the same response, so the page never reveals whether an order exists.
		$this->redirect( $this->lookup_result_url( $flow_url, 'sent' ) );
	}

	/**
	 * Resolve the order for a lookup only when both the order number and the email match. The email is
	 * compared in constant time to avoid a timing oracle. Returns null on any mismatch (fail closed).
	 *
	 * @param string $order_number The order number as the consumer sees it.
	 * @param string $email        The email address entered.
	 */
	private function match_order( string $order_number, string $email ): ?\WC_Order {
		$candidate_id = absint( $order_number );
		if ( $candidate_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $candidate_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// The value the consumer typed must be the order's own number (covers custom numbering schemes),
		// not merely any id that parses out of it.
		if ( 0 !== strcasecmp( trim( $order->get_order_number() ), trim( $order_number ) )
			&& (string) $order->get_id() !== trim( $order_number ) ) {
			return null;
		}

		$order_email = strtolower( (string) $order->get_billing_email() );
		if ( '' === $order_email || ! hash_equals( $order_email, strtolower( $email ) ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Email a freshly-signed withdrawal link to the order's own address through the store's mailer.
	 *
	 * @param \WC_Order $order    The matched order.
	 * @param string    $flow_url The page hosting the flow (base for the link).
	 */
	private function send_link_email( \WC_Order $order, string $flow_url ): void {
		if ( ! function_exists( 'WC' ) || ! class_exists( '\WC_Email' ) ) {
			return;
		}

		$url = $this->urls->declaration_url( $flow_url, $order->get_id(), $this->lookup_token_expiry() );

		WC()->mailer();
		( new WithdrawalLinkEmail() )->trigger_for( $order, $url );
	}

	/**
	 * The expiry (Unix timestamp) for a lookup-issued withdrawal-link token. Shares the same generous,
	 * filterable lifetime as the entry links in order emails, so the link stays usable for the whole
	 * period the consumer might act.
	 */
	private function lookup_token_expiry(): int {
		/** This filter is documented in src/Frontend/Hooks.php */
		$ttl = (int) apply_filters( 'recesso_dig_entry_token_ttl', 60 * DAY_IN_SECONDS );

		return time() + max( DAY_IN_SECONDS, $ttl );
	}

	/**
	 * Build the redirect URL that shows a uniform lookup result on the flow page.
	 *
	 * @param string $flow_url The page hosting the flow.
	 * @param string $result   Result token: `sent`, `invalid` or `throttled`.
	 */
	private function lookup_result_url( string $flow_url, string $result ): string {
		return add_query_arg( 'recesso_dig_lookup', $result, $flow_url );
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
