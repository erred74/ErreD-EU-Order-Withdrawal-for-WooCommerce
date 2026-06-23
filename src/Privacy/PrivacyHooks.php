<?php
/**
 * GDPR privacy integration.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Privacy;

use Recesso54bis\Persistence\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress's privacy tools: a personal-data exporter and eraser keyed on the
 * consumer's email, plus a suggested privacy-policy snippet. The eraser anonymises the consumer's
 * identifying fields but retains the legal acknowledgement (confirmation timestamp and tamper-evident
 * receipt), which the law requires to be kept; this is surfaced to the admin via a "data retained"
 * message.
 */
final class PrivacyHooks {

	private const GROUP_ID = 'recesso_dig_requests';

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Construct the provider.
	 *
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Hook the exporter, eraser and policy content.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['recesso-digitale'] = array(
			'exporter_friendly_name' => __( 'Recesso Digitale withdrawal requests', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Export the withdrawal personal data for an email address.
	 *
	 * @param string $email The email to export.
	 * @param int    $page  The export page (unused; all rows fit in one page).
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		unset( $page );

		$items = array();
		foreach ( $this->requests->export_for_email( $email ) as $row ) {
			$items[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Withdrawal requests', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'item_id'     => 'recesso-dig-request-' . (int) ( $row['id'] ?? 0 ),
				'data'        => array(
					array(
						'name'  => __( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['order_id'] ?? '' ),
					),
					array(
						'name'  => __( 'Status', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['status'] ?? '' ),
					),
					array(
						'name'  => __( 'Name', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['consumer_name'] ?? '' ),
					),
					array(
						'name'  => __( 'Email', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['confirmation_email'] ?? '' ),
					),
					array(
						'name'  => __( 'Refund IBAN', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['refund_iban'] ?? '' ),
					),
					array(
						'name'  => __( 'Reason for withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['withdrawal_reason'] ?? '' ),
					),
					array(
						'name'  => __( 'IP address', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => $this->readable_ip( $row['request_ip'] ?? null ),
					),
					array(
						'name'  => __( 'Declaration submitted (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['submitted_at_gmt'] ?? '' ),
					),
					array(
						'name'  => __( 'Confirmed (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ),
						'value' => (string) ( $row['confirmed_at_gmt'] ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['recesso-digitale'] = array(
			'eraser_friendly_name' => __( 'Recesso Digitale withdrawal requests', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Anonymise the withdrawal personal data for an email address, retaining the legal record.
	 *
	 * @param string $email The email to erase.
	 * @param int    $page  The erase page (unused).
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$count    = $this->requests->anonymise_for_email( $email );
		$messages = array();
		if ( $count > 0 ) {
			$messages[] = __( 'Withdrawal personal data was anonymised; the legal acknowledgement (confirmation timestamp and receipt) is retained as required by law.', 'erred-eu-order-withdrawal-for-woocommerce' );
		}

		return array(
			'items_removed'  => $count > 0,
			'items_retained' => $count > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggest privacy-policy content describing the withdrawal data collected.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = __( 'When you exercise your right of withdrawal, we store your name, email address, order reference, the date and time of your declaration and confirmation, your IP address, and a tamper-evident receipt. These records are kept as evidence of the withdrawal for the legally required period.', 'erred-eu-order-withdrawal-for-woocommerce' );

		wp_add_privacy_policy_content(
			__( 'ErreD EU Order Withdrawal for WooCommerce', 'erred-eu-order-withdrawal-for-woocommerce' ),
			wp_kses_post( wpautop( $content ) )
		);
	}

	/**
	 * Convert a packed IP (inet_pton) to a readable string, defensively.
	 *
	 * @param mixed $packed Packed IP bytes.
	 */
	private function readable_ip( $packed ): string {
		if ( ! is_string( $packed ) || ! in_array( strlen( $packed ), array( 4, 16 ), true ) ) {
			return '';
		}

		$ip = inet_ntop( $packed );

		return is_string( $ip ) ? $ip : '';
	}
}
