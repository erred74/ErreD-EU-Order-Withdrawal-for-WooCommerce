<?php
/**
 * Server-rendered withdrawal flow controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Domain\Eligibility\LineWindow;
use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Email\WithdrawalLinkEmail;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Integration\NotEligibleException;
use Recesso54bis\Integration\RequestedItemsResolver;
use Recesso54bis\Integration\WithdrawalService;
use Recesso54bis\Persistence\DuplicateOpenRequestException;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\EligibilityController;
use Recesso54bis\Rest\PermissionGate;
use Recesso54bis\Support\ClientIp;
use Recesso54bis\Support\Color;
use Recesso54bis\Support\Nonces;
use Recesso54bis\Support\OrderNumber;
use Recesso54bis\Support\RateLimiter;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the two-step withdrawal flow with plain server rendering and full page reloads, so it works
 * without JavaScript. The mandatory wording is honoured: the entry control reads
 * «recedere dal contratto qui» and step two is «conferma recesso». Every state change is a POST to
 * admin-post.php guarded by a nonce plus capability/token authorisation, validated and sanitised.
 */
final class FlowController {

	/**
	 * Codes for the standalone message screen.
	 *
	 * The screen used to take its text straight from the query string. Escaped, so never an injection
	 * — but it let anyone hand out a link that displayed wording of their choosing inside the store's
	 * own withdrawal page, which on a page about money and legal deadlines is a ready-made phishing
	 * surface. Only these codes travel in the URL now, and anything unrecognised renders nothing.
	 */
	private const MSG_DUPLICATE    = 'duplicate';
	private const MSG_STORE_FAILED = 'store_failed';
	private const MSG_LINK_EXPIRED = 'link_expired';

	/**
	 * Prefix marking a message code that carries an eligibility reason (validated against
	 * {@see Reason::all()} before it is resolved to a label).
	 */
	private const MSG_REASON_PREFIX = 'reason_';

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
	 * Audit log repository (records lookups that matched no order).
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Construct the controller.
	 *
	 * @param WithdrawalService  $service      Coordination service.
	 * @param RequestRepository  $requests     Request repository.
	 * @param PermissionGate     $gate         Permission gate.
	 * @param EligibilityAdapter $eligibility  Eligibility adapter.
	 * @param FlowUrls           $urls         Flow URL builder.
	 * @param RateLimiter        $rate_limiter Rate limiter.
	 * @param LogRepository      $log          Audit log repository.
	 */
	public function __construct(
		WithdrawalService $service,
		RequestRepository $requests,
		PermissionGate $gate,
		EligibilityAdapter $eligibility,
		FlowUrls $urls,
		RateLimiter $rate_limiter,
		LogRepository $log
	) {
		$this->service      = $service;
		$this->requests     = $requests;
		$this->gate         = $gate;
		$this->eligibility  = $eligibility;
		$this->urls         = $urls;
		$this->rate_limiter = $rate_limiter;
		$this->log          = $log;
	}

	/**
	 * Record a lookup whose order number and email matched no order, so the merchant can reconcile a
	 * mistyped reference against their own records.
	 *
	 * Deliberately *not* a withdrawal record: the requests table only ever holds declarations bound to
	 * a real, authorised order, which is what makes a stored `confirmed_at_gmt` trustworthy. The email
	 * is masked before it is written, because this log is not covered by the personal-data eraser and
	 * the merchant only needs enough to recognise the customer, not a full address.
	 *
	 * @param string $order_number The reference the visitor submitted.
	 * @param string $email        The address the visitor submitted.
	 */
	private function record_unmatched_lookup( string $order_number, string $email ): void {
		$this->log->record(
			0,
			LogRepository::EVENT_LOOKUP_UNMATCHED,
			'consumer',
			array(
				'order_number' => $order_number,
				'email'        => $this->mask_email( $email ),
			)
		);
	}

	/**
	 * Mask an email address to its first character and domain (`a***@example.com`), enough to
	 * recognise a customer without storing the address itself.
	 *
	 * @param string $email The address.
	 */
	private function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}

		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
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
		if ( '' === $output ) {
			return '';
		}

		// Enqueue the flow's view script and stylesheet only when a flow step is actually on the page
		// (covers both the block and the shortcode); the flow works without either.
		$settings = new Settings();
		$this->enqueue_view_script();
		$this->enqueue_view_style( $settings );

		if ( ! $settings->button_uses_theme_style() ) {
			return $output;
		}

		// The merchant asked for the theme's own button styling. The wrapper is what the stylesheet
		// tests for, and it lives outside the templates on purpose: a theme override of any flow
		// template — which is exactly what a merchant who cares about styling is likely to have —
		// would otherwise never carry the class, and the setting would silently do nothing.
		return '<div class="recesso-dig-theme-buttons">' . $output . '</div>';
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
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect message code.
				$code = isset( $_GET['recesso_dig_msg'] ) ? sanitize_key( wp_unslash( $_GET['recesso_dig_msg'] ) ) : '';
				$text = $this->message_for_code( $code );
				if ( '' === $text ) {
					return '';
				}
				if ( self::MSG_LINK_EXPIRED === $code ) {
					// A dead link is only actionable alongside the means to get a live one, so the lookup
					// form comes with the explanation rather than leaving the consumer at a dead end.
					return $this->message( $text, 'error' ) . $this->render_lookup();
				}
				if ( str_starts_with( $code, self::MSG_REASON_PREFIX ) ) {
					// The same refusal the declaration screen shows, reached by redirect after a POST, so
					// it carries the merchant's follow-up too. Anything else here is the plugin's own
					// operational wording and has nothing for a merchant to add.
					$settings = new Settings();
					$addendum = $settings->ineligible_addendum();
					$link     = $settings->ineligible_link();

					return $this->message(
						'' !== $addendum ? $text . ' ' . $addendum : $text,
						'info',
						$link['url'],
						$link['text']
					);
				}
				return $this->message( $text, 'info' );
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
	 *
	 * When the merchant has chosen an accent of their own, three custom properties are appended to
	 * override the bundled ones. Only the accent is theirs: the hover shade and the label colour are
	 * derived, so a pale accent cannot push the label below the WCAG contrast floor. Every value is
	 * re-validated as a hex colour by {@see Settings::button_accent()} and {@see Color::hover()}, so
	 * nothing but `#rrggbb` can reach the stylesheet.
	 *
	 * @param Settings $settings The plugin settings.
	 */
	private function enqueue_view_style( Settings $settings ): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}

		$handle = generate_block_asset_handle( 'recesso-digitale/withdrawal-button', 'viewStyle' );
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			return;
		}

		wp_enqueue_style( $handle );

		$accent = $settings->button_accent();
		if ( Settings::DEFAULT_ACCENT === $accent || $settings->button_uses_theme_style() ) {
			// The stylesheet already carries the bundled accent, and in theme mode nothing is coloured
			// by us at all: the overwhelming majority of sites therefore add no inline CSS.
			return;
		}

		wp_add_inline_style(
			$handle,
			'.wp-block-recesso-digitale-flow{'
				. '--recesso-dig-accent:' . $accent . ';'
				. '--recesso-dig-accent-hover:' . Color::hover( $accent ) . ';'
				. '--recesso-dig-accent-text:' . Color::readable_text( $accent ) . ';'
				. '}'
		);
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

		$settings = new Settings();

		return Templates::render(
			'lookup',
			array(
				'action_url'      => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'nonce_action'    => Nonces::LOOKUP,
				'nonce_name'      => '_recesso_dig_nonce',
				'flow_url'        => $this->current_page_url(),
				'notice'          => $notice,
				'notice_type'     => $notice_type,
				'title'           => $settings->lookup_title(),
				'intro'           => $settings->lookup_intro(),
				'email_hint'      => $settings->lookup_email_hint(),
				'order_hint'      => $settings->lookup_order_hint(),
				'submit_label'    => $settings->lookup_submit_label(),
				'show_intro'      => $settings->lookup_intro_enabled(),
				'show_email_hint' => $settings->lookup_email_hint_enabled(),
				'show_order_hint' => $settings->lookup_order_hint_enabled(),
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

		// The consumer who came back from the review step to amend their declaration: it is their own
		// unconfirmed request holding the claim, so without this they would be told a request is
		// already in progress and refused the chance to correct a form they never sent. Nothing is
		// released here — submitting the amended declaration discards the pending one atomically.
		$draft = $this->editable_draft( $order );
		if ( ! $eligibility->is_eligible && $draft instanceof WithdrawalRequest ) {
			$eligibility = $this->eligibility->for_order_ignoring_claim( $order, $draft );
		}

		if ( ! $eligibility->is_eligible ) {
			return $this->ineligible_message( $order, $eligibility->reason );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a prior validation error flag.
		$error = isset( $_GET['recesso_dig_error'] ) ? sanitize_text_field( wp_unslash( $_GET['recesso_dig_error'] ) ) : '';

		$settings = new Settings();

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
				// Editing an unsent declaration shows what the consumer actually typed, not the order's
				// billing details, which is what they were correcting in the first place.
				'consumer_name'      => $draft instanceof WithdrawalRequest && '' !== $draft->consumer_name
					? $draft->consumer_name
					: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'confirmation_email' => $draft instanceof WithdrawalRequest && '' !== $draft->confirmation_email
					? $draft->confirmation_email
					: $order->get_billing_email(),
				'lines'              => $this->line_choices(
					$order,
					$eligibility->available_quantities,
					$draft instanceof WithdrawalRequest ? $draft->requested_items : array(),
					$this->line_deadlines( $eligibility )
				),
				'intro'              => $settings->form_intro_enabled() ? $settings->form_intro_text( $order->get_order_number() ) : '',
				'declaration_text'   => $settings->consumer_declaration_enabled() ? $settings->consumer_declaration_text() : '',
				'deadline'           => $this->deadline_notice( $eligibility ),
				'error'              => '' === $error ? '' : $this->declaration_error_message( $settings ),
			)
		);
	}

	/**
	 * The screen shown when an order cannot be withdrawn from.
	 *
	 * "A withdrawal request is already in progress" was the generic answer for the commonest case of
	 * all — the consumer who came back to the form after already sending one. On its own it reads like
	 * a refusal, so it is worth saying plainly that their request arrived, when it arrived, and where
	 * they can watch it.
	 *
	 * Every other reason states a legal position and then stops. Accurate, and a dead end: the consumer
	 * is told no with no way to ask a person about it. The reason stays the plugin's wording — it says
	 * what the law says — but the merchant can append their own follow-up and a link to it.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $reason A {@see Reason} constant.
	 */
	private function ineligible_message( \WC_Order $order, string $reason ): string {
		if ( Reason::DUPLICATE_OPEN !== $reason ) {
			$settings = new Settings();
			$addendum = $settings->ineligible_addendum();
			$link     = $settings->ineligible_link();

			$text = EligibilityController::reason_label( $reason );
			if ( '' !== $addendum ) {
				$text .= ' ' . $addendum;
			}

			return $this->message( $text, 'info', $link['url'], $link['text'] );
		}

		$existing = $this->requests->latest_for_order( $order->get_id() );
		$sent_on  = $existing instanceof WithdrawalRequest
			? $this->local_datetime( (string) $existing->confirmed_at_gmt )
			: '';

		$text = '' !== $sent_on
			? sprintf(
				/* translators: %s: date and time the earlier request was sent. */
				__( 'You already sent a withdrawal request for this order on %s. We have it — there is no need to send another.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$sent_on
			)
			: __( 'A withdrawal request for this order is already in progress. We have it — there is no need to send another.', 'erred-eu-order-withdrawal-for-woocommerce' );

		return $this->message(
			$text,
			'info',
			$this->account_url(),
			__( 'Follow this request in your account', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The validation message shown when a declaration submission is rejected. It names the consumer
	 * self-declaration only when the merchant actually asks for it, so the consumer is never told to
	 * tick a box that is not on the form.
	 *
	 * @param Settings $settings Settings reader.
	 */
	private function declaration_error_message( Settings $settings ): string {
		if ( $settings->consumer_declaration_enabled() ) {
			return __( 'Please provide a valid name and email address, select at least one item to withdraw, and confirm that you bought as a consumer.', 'erred-eu-order-withdrawal-for-woocommerce' );
		}

		return __( 'Please provide a valid name and email address, and select at least one item to withdraw.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Build the selectable line list for the declaration form: each eligible order line with its
	 * display name (variation included), total ordered quantity and the units still available to
	 * withdraw (the input's maximum).
	 *
	 * @param \WC_Order          $order     The order.
	 * @param array<int, int>    $available Map line_id => units still available to withdraw.
	 * @param array<int, int>    $selected  Map line_id => quantity to pre-select (when amending a draft).
	 * @param array<int, string> $deadlines Map line_id => that line's own deadline sentence; empty when
	 *                                      every line shares one, which is then stated once above.
	 *
	 * @return array<int, array{id: int, label: string, quantity: int, available: int, thumbnail: string, selected: bool, selected_qty: int, deadline: string}>
	 */
	private function line_choices( \WC_Order $order, array $available, array $selected = array(), array $deadlines = array() ): array {
		$choices = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_id = (int) $item_id;
			if ( ! array_key_exists( $line_id, $available ) ) {
				continue;
			}

			$units = (int) $available[ $line_id ];
			$want  = (int) ( $selected[ $line_id ] ?? 0 );

			$choices[] = array(
				'id'           => $line_id,
				'label'        => $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				'available'    => $units,
				'thumbnail'    => RequestedItemsResolver::thumbnail( $item ),
				'selected'     => $want > 0,
				'selected_qty' => $want > 0 ? min( $want, $units ) : $units,
				'deadline'     => $deadlines[ $line_id ] ?? '',
			);
		}

		return $choices;
	}

	/**
	 * The consumer's own unconfirmed declaration for this order, if there is one.
	 *
	 * Only a pending request qualifies: once confirmed it is a legal record and nothing about it may
	 * be rewritten. The caller has already been authorised for the order, so this is their own draft.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function editable_draft( \WC_Order $order ): ?WithdrawalRequest {
		$latest = $this->requests->latest_for_order( $order->get_id() );

		return $latest instanceof WithdrawalRequest && RequestStatus::PENDING === $latest->status
			? $latest
			: null;
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

		// Arriving at step two on a request that is already confirmed means the consumer came back —
		// browser back button, a bookmark, a second click on the emailed link. Rendering the plain
		// success screen told them the withdrawal had just been recorded, so a second visit was
		// indistinguishable from the first and invited them to wonder whether it had gone through twice.
		if ( $request->is_confirmed() ) {
			return $this->render_done( true );
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
				// Back to step one. Nothing needs undoing first: re-submitting the declaration discards
				// the pending, unconfirmed request (see WithdrawalService::create_declaration()). If the
				// link has since gone stale or the request was confirmed in another tab, step one says so
				// rather than silently re-rendering.
				'back_url'           => $this->step_url(
					FlowUrls::STEP_DECLARE,
					array(
						FlowUrls::QV_ORDER => $request->order_id,
						FlowUrls::QV_TOKEN => $token,
					)
				),
			)
		);
	}

	/**
	 * Render the final "done" screen.
	 *
	 * @param bool $already_confirmed Whether this is a return visit to a request confirmed earlier,
	 *                                rather than the screen shown right after confirming.
	 */
	private function render_done( bool $already_confirmed = false ): string {
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
				'already_confirmed'  => $already_confirmed,
				'confirmed_on'       => $this->local_datetime( (string) $request->confirmed_at_gmt ),
				'account_url'        => $this->account_url(),
			)
		);
	}

	/**
	 * The My Account withdrawal tab, when it is switched on and there is a customer to show it to.
	 */
	private function account_url(): string {
		if ( ! ( new Settings() )->account_endpoint_enabled() || ! is_user_logged_in() ) {
			return '';
		}

		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return '';
		}

		return (string) wc_get_account_endpoint_url( AccountEndpoint::ENDPOINT );
	}

	/**
	 * The sentence telling the consumer when their withdrawal period ends.
	 *
	 * The window end has always been computed and then thrown away: the consumer was told a duration
	 * ("you have 14 days from delivery") and left to work out the date, which is the one thing they
	 * actually need. The period is the consumer's deadline as much as the merchant's, so it belongs on
	 * the screen where they are about to use it.
	 *
	 * Stated once, above the form, when every line the consumer can choose shares one deadline — the
	 * ordinary case. When they differ (goods counted from delivery next to a service counted from the
	 * purchase) no single date is true, so this returns '' and each line states its own
	 * ({@see self::line_deadlines()}).
	 *
	 * Returns an empty string when there is no date to state — an order whose window has not started
	 * has no end — and the wording follows the enforcement mode: in the default advisory mode the date
	 * is guidance, and promising a hard cut-off the plugin does not enforce would be a lie.
	 *
	 * @param \Recesso54bis\Domain\Eligibility\EligibilityResult $eligibility The evaluated eligibility.
	 */
	private function deadline_notice( $eligibility ): string {
		$ends = $this->offered_line_ends( $eligibility );
		if ( null === $ends ) {
			// No per-line periods (a result replaced through the recesso_dig_is_eligible filter): fall
			// back to the order-level bound, as before per-line periods existed.
			$ends_at = $eligibility->window_ends_at;
		} elseif ( 1 === count( array_unique( $ends ) ) ) {
			$ends_at = $this->instant( (string) reset( $ends ) );
		} else {
			return '';
		}

		$date = $ends_at instanceof \DateTimeImmutable ? $this->local_datetime( $ends_at->format( 'Y-m-d H:i:s' ) ) : '';
		if ( '' === $date ) {
			return '';
		}

		if ( ( new Settings() )->enforcement_strict() ) {
			return sprintf(
				/* translators: %s: date and time the withdrawal period closes. */
				__( 'You can withdraw from this order until %s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$date
			);
		}

		return sprintf(
			/* translators: %s: date and time the withdrawal period closes. */
			__( 'The ordinary withdrawal period for this order runs until %s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$date
		);
	}

	/**
	 * Each offered line's own deadline sentence, when the lines do not share one.
	 *
	 * A line whose period has not opened says so instead of giving a date: in advisory mode a T-shirt
	 * not yet delivered is still offered next to a course already running, and "until" an unknown date
	 * would be no answer at all.
	 *
	 * @param \Recesso54bis\Domain\Eligibility\EligibilityResult $eligibility The evaluated eligibility.
	 *
	 * @return array<int, string> Map line_id => sentence; empty when one deadline covers every line.
	 */
	private function line_deadlines( $eligibility ): array {
		$ends = $this->offered_line_ends( $eligibility );
		if ( null === $ends || count( array_unique( $ends ) ) <= 1 ) {
			return array();
		}

		$strict    = ( new Settings() )->enforcement_strict();
		$sentences = array();
		foreach ( $ends as $line_id => $end ) {
			$ends_at = $this->instant( $end );
			$date    = $ends_at instanceof \DateTimeImmutable ? $this->local_datetime( $ends_at->format( 'Y-m-d H:i:s' ) ) : '';

			if ( '' === $date ) {
				$sentences[ $line_id ] = __( 'The withdrawal period for this item starts on delivery.', 'erred-eu-order-withdrawal-for-woocommerce' );
				continue;
			}

			$sentences[ $line_id ] = $strict
				? sprintf(
					/* translators: %s: date and time the withdrawal period for one item closes. */
					__( 'You can withdraw this item until %s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
					$date
				)
				: sprintf(
					/* translators: %s: date and time the withdrawal period for one item closes. */
					__( 'The ordinary withdrawal period for this item runs until %s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
					$date
				);
		}

		return $sentences;
	}

	/**
	 * The period end of every line the consumer is offered, as comparable strings.
	 *
	 * @param \Recesso54bis\Domain\Eligibility\EligibilityResult $eligibility The evaluated eligibility.
	 *
	 * @return array<int, string>|null Map line_id => 'Y-m-d H:i:s' UTC ('' while the period has not
	 *                                 opened); null when the result carries no per-line periods.
	 */
	private function offered_line_ends( $eligibility ): ?array {
		if ( array() === $eligibility->line_windows ) {
			return null;
		}

		$ends = array();
		foreach ( array_keys( $eligibility->available_quantities ) as $line_id ) {
			$window = $eligibility->line_windows[ $line_id ] ?? null;
			if ( ! $window instanceof LineWindow ) {
				return null;
			}
			$ends[ (int) $line_id ] = $window->ends_at instanceof \DateTimeImmutable ? $window->ends_at->format( 'Y-m-d H:i:s' ) : '';
		}

		return $ends;
	}

	/**
	 * Parse a 'Y-m-d H:i:s' UTC string back to an instant ('' gives null).
	 *
	 * @param string $value Datetime string.
	 */
	private function instant( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return $parsed instanceof \DateTimeImmutable ? $parsed : null;
	}

	/**
	 * Render a stored GMT datetime in the site's timezone and format.
	 *
	 * @param string $gmt Datetime in GMT ('' when absent).
	 */
	private function local_datetime( string $gmt ): string {
		$gmt = trim( $gmt );
		if ( '' === $gmt ) {
			return '';
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$timestamp
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
			$this->deny_stale_submission();
			return;
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

		// The optional "I bought as a consumer" self-declaration. The exact wording shown on the form
		// is stored, not a bare flag, so the receipt records what the consumer actually agreed to.
		$settings             = new Settings();
		$declaration_required = $settings->consumer_declaration_enabled();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer above.
		$declaration_given = isset( $_POST['consumer_declaration'] );
		$declaration       = ( $declaration_required && $declaration_given ) ? $settings->consumer_declaration_text() : '';

		if ( '' === $consumer_name || false === is_email( $email ) || array() === $requested_items || ( $declaration_required && ! $declaration_given ) ) {
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
			$this->deny_stale_submission();
			return;
		}

		try {
			$request = $this->service->create_declaration(
				$order,
				array(
					'consumer_name'        => $consumer_name,
					'contract_reference'   => $order->get_order_number(),
					'confirmation_email'   => $email,
					'requested_items'      => $requested_items,
					'refund_iban'          => $iban,
					'withdrawal_reason'    => $reason,
					'consumer_declaration' => $declaration,
				),
				ClientIp::packed()
			);
		} catch ( DuplicateOpenRequestException $e ) {
			$this->redirect( $this->message_url( self::MSG_DUPLICATE ) );
			return;
		} catch ( NotEligibleException $e ) {
			$this->redirect( $this->message_url( $this->reason_code( $e->reason() ) ) );
			return;
		} catch ( \Throwable $e ) {
			// A legally-required function must never white-screen: degrade to a friendly message and
			// keep the consumer on a usable page (e.g. a transient persistence failure or a pending
			// schema migration). The failure is not silently lost — nothing was recorded, so the
			// consumer can retry.
			$this->redirect( $this->message_url( self::MSG_STORE_FAILED ) );
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
			$this->deny_stale_submission();
			return;
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
		$flow_url = wp_validate_redirect( $flow_url, self::flow_url_or_home() );

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
		} else {
			$this->record_unmatched_lookup( $order_number, $email );
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
		$typed = OrderNumber::normalise( $order_number );

		$candidate_id = absint( $typed );
		if ( $candidate_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $candidate_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// The value the consumer typed must be the order's own number (covers custom numbering schemes),
		// not merely any id that parses out of it. The third comparison is numeric and comes last, so a
		// zero-padded reference (`01234` against order 1234, or the reverse) still resolves without the
		// padding being stripped anywhere — for a sequential-numbering plugin those zeros are part of
		// the number the customer was shown.
		$own_number = OrderNumber::normalise( (string) $order->get_order_number() );
		$own_id     = (string) $order->get_id();

		if ( 0 !== strcasecmp( $own_number, $typed )
			&& $own_id !== $typed
			&& ! OrderNumber::same_number( $own_number, $typed )
			&& ! OrderNumber::same_number( $own_id, $typed ) ) {
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
	private function send_link_email( \WC_Order $order, string $flow_url ): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		// Initialise WooCommerce's mailer FIRST: it loads the abstract WC_Email class that
		// WithdrawalLinkEmail extends. WooCommerce does not autoload WC_Email (its autoloader looks in
		// includes/, but the class lives in includes/emails/), so constructing the email — or guarding
		// on class_exists( 'WC_Email' ) — before the mailer is initialised bails out on a plain
		// front-end/admin-post request, which is why the lookup link was never sent.
		WC()->mailer();

		if ( ! class_exists( '\WC_Email' ) ) {
			return false;
		}

		$url = $this->urls->declaration_url( $flow_url, $order->get_id(), $this->lookup_token_expiry() );

		return ( new WithdrawalLinkEmail() )->trigger_for( $order, $url );
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
	 * @param string $code One of the MSG_* codes, or a reason code from {@see self::reason_code()}.
	 */
	private function message_url( string $code ): string {
		return add_query_arg(
			array(
				FlowUrls::QV_STEP => 'message',
				'recesso_dig_msg' => rawurlencode( $code ),
			),
			$this->current_base_url()
		);
	}

	/**
	 * Resolve a message code to its text. Unknown codes resolve to '' and render nothing, so only
	 * wording this plugin authored can ever appear on the message screen.
	 *
	 * @param string $code Code from the URL.
	 */
	private function message_for_code( string $code ): string {
		switch ( $code ) {
			case self::MSG_DUPLICATE:
				return __( 'A withdrawal request is already in progress for this order.', 'erred-eu-order-withdrawal-for-woocommerce' );

			case self::MSG_STORE_FAILED:
				return __( 'We could not record your withdrawal right now. Please try again in a few moments.', 'erred-eu-order-withdrawal-for-woocommerce' );

			case self::MSG_LINK_EXPIRED:
				return __( 'This withdrawal link is no longer valid — it may have expired, or it may already have been used. You can request a new one below.', 'erred-eu-order-withdrawal-for-woocommerce' );
		}

		if ( str_starts_with( $code, self::MSG_REASON_PREFIX ) ) {
			$reason = substr( $code, strlen( self::MSG_REASON_PREFIX ) );

			// Only a reason the domain actually defines is resolved: the URL selects from a fixed list,
			// it never supplies wording.
			return in_array( $reason, Reason::all(), true ) ? EligibilityController::reason_label( $reason ) : '';
		}

		return '';
	}

	/**
	 * The message code for an eligibility reason.
	 *
	 * @param string $reason A {@see Reason} constant.
	 */
	private function reason_code( string $reason ): string {
		return self::MSG_REASON_PREFIX . $reason;
	}

	/**
	 * End a POST that could not be authorised by sending the consumer to the message screen instead of
	 * a bare wp_die.
	 *
	 * A stale form — left open past the token's lifetime, or submitted twice — used to produce an
	 * unstyled 403 outside the theme with no way back, for what is usually a legitimate consumer
	 * acting slowly on a legally mandated function. They now land on the withdrawal page with an
	 * explanation and the lookup form, which issues a fresh link to the order's own address.
	 *
	 * The wording deliberately does not distinguish an expired link from a forged one: telling them
	 * apart would confirm to an attacker that a signature was genuine.
	 */
	private function deny_stale_submission(): void {
		$this->redirect( $this->message_url( self::MSG_LINK_EXPIRED ) );
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

		return self::flow_url_or_home();
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

		return self::flow_url_or_home();
	}

	/**
	 * The flow page URL, falling back to the site home.
	 *
	 * Only for the redirect targets and form bases in this class, where the request has to land
	 * *somewhere* and an empty string would be a broken redirect. Never use it to decide whether to
	 * offer a withdrawal control: that decision reads {@see FlowPage::url()} directly, so a missing
	 * page suppresses the link instead of pointing it at the shop front page.
	 */
	private static function flow_url_or_home(): string {
		$url = FlowPage::url();

		return '' !== $url ? $url : home_url( '/' );
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
	 * @param string $message   Message text.
	 * @param string $type      One of info|error|success.
	 * @param string $link_url  Optional follow-up link.
	 * @param string $link_text Label for that link (both must be present for it to render).
	 */
	private function message( string $message, string $type, string $link_url = '', string $link_text = '' ): string {
		return Templates::render(
			'message',
			array(
				'message'   => $message,
				'type'      => $type,
				'link_url'  => $link_url,
				'link_text' => $link_text,
			)
		);
	}
}
