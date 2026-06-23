<?php
/**
 * Integration tests for durable-medium receipt generation.
 *
 * Verifies the receipt PDF is generated, hashed and stored in the protected directory, that the
 * receipt hash and acknowledged timestamp are write-once, and that generation is idempotent.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Email;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Email\WithdrawalAcknowledgementEmail;
use Recesso54bis\Integration\RequestedItemsResolver;
use Recesso54bis\Pdf\ReceiptBuilder;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class ReceiptTest extends TestCase {

	private Container $container;
	private int $order_id   = 0;
	private int $request_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container = new Container();
		$order           = $this->make_order();
		$this->order_id  = $order->get_id();

		$service = $this->container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'mario@example.org',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );
		$this->request_id = $request->id;
	}

	protected function tearDown(): void {
		$record = $this->container->request_repository()->find_by_id( $this->request_id );
		if ( null !== $record && null !== $record->receipt_path && is_file( $record->receipt_path ) ) {
			wp_delete_file( $record->receipt_path );
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Receipt Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	public function test_confirmation_generates_receipt_immediately(): void {
		// Regression: the durable receipt must be produced (and the acknowledgement email triggered)
		// synchronously the moment the consumer confirms — never deferred to an async job that may not
		// run until an admin acts. setUp() only calls confirm(); no explicit generate() is invoked.
		$record = $this->container->request_repository()->find_by_id( $this->request_id );

		$this->assertNotNull( $record );
		$this->assertTrue( $record->has_receipt(), 'The receipt must exist immediately after confirmation.' );
		$this->assertSame( RequestStatus::ACKNOWLEDGED, $record->status );
		$this->assertIsString( $record->receipt_path );
		$this->assertTrue( is_file( (string) $record->receipt_path ) );
	}

	public function test_receipt_is_stored_even_when_filesystem_method_is_not_direct(): void {
		// Regression: on hosts where WP_Filesystem auto-detects a non-"direct" method (file ownership
		// differs from the web-server user), the consumer-facing confirmation previously failed to store
		// the durable receipt. The builder must force the direct method and still write the PDF.
		global $wp_filesystem;
		$original = $wp_filesystem;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately forcing re-init for this regression test; restored in finally.
		$wp_filesystem = null;
		$force_ftp     = static fn(): string => 'ftpext';
		add_filter( 'filesystem_method', $force_ftp );

		try {
			$order   = wc_get_order( $this->order_id );
			$request = $this->container->request_repository()->find_by_id( $this->request_id );

			$result = $this->container->receipt_builder()->build( $request, $order );

			$this->assertArrayHasKey( 'path', $result );
			$this->assertTrue( is_file( (string) $result['path'] ), 'The receipt PDF must be stored despite a non-direct filesystem method.' );
			wp_delete_file( (string) $result['path'] );
		} finally {
			remove_filter( 'filesystem_method', $force_ftp );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the global captured above.
			$wp_filesystem = $original;
		}
	}

	public function test_acknowledgement_email_is_sent_even_when_the_email_toggle_is_off(): void {
		// Regression: the durable-medium acknowledgement is legally required and must be delivered even
		// though a custom WC_Email with no saved settings reports is_enabled() === false. We override
		// send() to record the attempt rather than route through the store mailer, so the assertion is
		// about the is_enabled() bypass, not the host's mail transport.
		$request = $this->container->request_repository()->find_by_id( $this->request_id );

		// Explicitly turn the email OFF in its WooCommerce settings to prove the legal send bypasses it.
		update_option( 'woocommerce_recesso_dig_acknowledgement_settings', array( 'enabled' => 'no' ) );

		$email = new class() extends \Recesso54bis\Email\WithdrawalAcknowledgementEmail {
			/**
			 * Captured recipient of the (intercepted) send.
			 *
			 * @var string
			 */
			public string $sent_to = '';

			/**
			 * Captured attachments of the (intercepted) send.
			 *
			 * @var array<int, string>
			 */
			public array $sent_attachments = array();

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter -- test double overriding WC_Email::send().
			public function send( $to, $subject, $message, $headers, $attachments ) {
				$this->sent_to          = (string) $to;
				$this->sent_attachments = (array) $attachments;
				return true;
			}
		};

		$this->assertFalse( $email->is_enabled(), 'Precondition: the email is disabled in its settings.' );

		$sent = $email->trigger_for( $request, (string) $request->receipt_path, 'https://example.test/receipt' );

		$this->assertTrue( $sent, 'The legally-required acknowledgement must be sent regardless of the toggle.' );
		$this->assertSame( 'mario@example.org', $email->sent_to );
		$this->assertNotEmpty( $email->sent_attachments, 'The PDF receipt must be attached.' );

		delete_option( 'woocommerce_recesso_dig_acknowledgement_settings' );
	}

	public function test_confirmation_attempts_email_delivery_to_the_consumer(): void {
		// Regression: the acknowledgement is sent from the consumer-facing confirmation, where
		// WC()->mailer() has not been initialised. The scheduler must initialise it so the email send
		// does not throw (which previously left the email unsent while the admin "regenerate" worked).
		// pre_wp_mail captures the attempt and short-circuits the (unavailable) transport.
		$captured = array();
		$capture  = static function ( $pre, array $atts ) use ( &$captured ) {
			$captured[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );

		$order = $this->make_order();
		try {
			$service = $this->container->withdrawal_service();
			$request = $service->create_declaration(
				$order,
				array(
					'consumer_name'      => 'Mario Rossi',
					'contract_reference' => '#' . $order->get_id(),
					'confirmation_email' => 'mario@example.org',
				),
				null
			);
			$service->confirm( $request->id, 'consumer' );

			$record = $this->container->request_repository()->find_by_id( $request->id );
			$this->assertNotNull( $record );
			$this->assertTrue( $record->has_receipt(), 'The receipt must be stored on confirmation.' );

			$recipients  = array();
			$attachments = array();
			foreach ( $captured as $mail ) {
				$recipients  = array_merge( $recipients, (array) $mail['to'] );
				$attachments = array_merge( $attachments, (array) ( $mail['attachments'] ?? array() ) );
			}
			$this->assertContains( 'mario@example.org', $recipients, 'The acknowledgement email must be attempted to the consumer.' );
			$this->assertNotEmpty( $attachments, 'The acknowledgement email must carry the PDF attachment.' );

			if ( null !== $record->receipt_path && is_file( (string) $record->receipt_path ) ) {
				wp_delete_file( (string) $record->receipt_path );
			}
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $order->get_id() ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $order->get_id() ) );
			$order->delete( true );
		}
	}

	public function test_generate_creates_stored_hashed_receipt(): void {
		$this->container->receipt_scheduler()->generate( $this->request_id );

		$record = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $record );
		$this->assertSame( RequestStatus::ACKNOWLEDGED, $record->status );
		$this->assertNotNull( $record->acknowledged_at_gmt );
		$this->assertIsString( $record->receipt_hash );
		$this->assertSame( 64, strlen( (string) $record->receipt_hash ) );

		$this->assertIsString( $record->receipt_path );
		$this->assertTrue( is_file( (string) $record->receipt_path ) );
		$this->assertStringContainsString( ReceiptBuilder::PRIVATE_DIR, (string) $record->receipt_path );

		// The stored file is a PDF.
		$this->assertSame( '%PDF', substr( (string) file_get_contents( (string) $record->receipt_path ), 0, 4 ) );
	}

	public function test_receipt_items_show_product_name_not_line_id(): void {
		// Regression: the receipt previously printed the raw order-line id (e.g. "8") as the item.
		$order   = wc_get_order( $this->order_id );
		$request = $this->container->request_repository()->find_by_id( $this->request_id );

		$builder = $this->container->receipt_builder();
		$method  = new \ReflectionMethod( $builder, 'canonical_payload' );
		$method->setAccessible( true );
		$payload = $method->invoke( $builder, $request, $order );

		$this->assertArrayHasKey( 'items', $payload );
		$this->assertCount( 1, $payload['items'] );
		$this->assertSame( 'Receipt Test', $payload['items'][0]['name'] );
		$this->assertSame( 1, $payload['items'][0]['quantity'] );
	}

	public function test_canonical_payload_items_come_from_the_shared_resolver_unchanged(): void {
		// Guards the receipt hash: the hashed `items` must be exactly the shared resolver's name/quantity
		// projection, in order. If a future presentation field leaked into the payload the hash would
		// change for existing receipts — this pins the shape to what was hashed before the refactor.
		$order   = wc_get_order( $this->order_id );
		$request = $this->container->request_repository()->find_by_id( $this->request_id );

		$builder = $this->container->receipt_builder();
		$method  = new \ReflectionMethod( $builder, 'canonical_payload' );
		$method->setAccessible( true );
		$payload = $method->invoke( $builder, $request, $order );

		$expected = array();
		foreach ( RequestedItemsResolver::resolve( $request, $order ) as $row ) {
			$expected[] = array(
				'name'     => $row['name'],
				'quantity' => $row['quantity'],
			);
		}

		$this->assertSame( $expected, $payload['items'] );
	}

	public function test_acknowledgement_email_lists_products_and_consumer_data(): void {
		// The durable acknowledgement must carry the content of the declaration (consumer name, order
		// reference, products being withdrawn), the submission/transmission timestamps and — as the
		// consumer's proof of submission — the receipt verification code (hash).
		$request = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $request );
		$this->assertIsString( $request->receipt_hash, 'Precondition: the request must have a receipt hash.' );

		$email = new WithdrawalAcknowledgementEmail();
		$ref   = new \ReflectionObject( $email );
		$prop  = $ref->getProperty( 'request' );
		$prop->setAccessible( true );
		$prop->setValue( $email, $request );

		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
		}

		$html  = $email->get_content_html();
		$plain = $email->get_content_plain();

		foreach ( array(
			'html'  => $html,
			'plain' => $plain,
		) as $label => $body ) {
			$this->assertStringContainsString( 'Receipt Test', $body, "Product name missing from {$label} body." );
			$this->assertStringContainsString( 'Mario Rossi', $body, "Consumer name missing from {$label} body." );
			$this->assertStringContainsString( '#' . $this->order_id, $body, "Order reference missing from {$label} body." );
			$this->assertStringContainsString( (string) $request->submitted_at_gmt, $body, "Submission time missing from {$label} body." );
			$this->assertStringContainsString( (string) $request->confirmed_at_gmt, $body, "Transmission time missing from {$label} body." );
			$this->assertStringContainsString( (string) $request->receipt_hash, $body, "Receipt verification code (hash) missing from {$label} body." );
		}
	}

	public function test_status_update_and_rejection_emails_include_request_details_and_hash(): void {
		// Every status-change notice to the consumer must restate the request details and carry the
		// receipt verification code (hash), so the message is self-contained proof.
		$request = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $request );
		$this->assertIsString( $request->receipt_hash );

		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
		}

		$status   = new \Recesso54bis\Email\WithdrawalStatusUpdateEmail();
		$ref      = new \ReflectionObject( $status );
		$status_p = $ref->getProperty( 'request' );
		$status_p->setAccessible( true );
		$status_p->setValue( $status, $request );
		$status_s = $ref->getProperty( 'status' );
		$status_s->setAccessible( true );
		$status_s->setValue( $status, RequestStatus::ACCEPTED );

		$rejection   = new \Recesso54bis\Email\WithdrawalRejectionEmail();
		$rref        = new \ReflectionObject( $rejection );
		$rejection_p = $rref->getProperty( 'request' );
		$rejection_p->setAccessible( true );
		$rejection_p->setValue( $rejection, $request );
		$rejection_r = $rref->getProperty( 'reason' );
		$rejection_r->setAccessible( true );
		$rejection_r->setValue( $rejection, 'Not eligible.' );

		foreach ( array(
			'status-html'     => $status->get_content_html(),
			'status-plain'    => $status->get_content_plain(),
			'rejection-html'  => $rejection->get_content_html(),
			'rejection-plain' => $rejection->get_content_plain(),
		) as $label => $body ) {
			$this->assertStringContainsString( 'Mario Rossi', $body, "Consumer name missing from {$label} body." );
			$this->assertStringContainsString( '#' . $this->order_id, $body, "Order reference missing from {$label} body." );
			$this->assertStringContainsString( 'Receipt Test', $body, "Product name missing from {$label} body." );
			$this->assertStringContainsString( (string) $request->receipt_hash, $body, "Receipt verification code (hash) missing from {$label} body." );
		}
	}

	public function test_receipt_is_write_once_and_generation_idempotent(): void {
		$scheduler = $this->container->receipt_scheduler();
		$scheduler->generate( $this->request_id );

		$first = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $first );
		$hash = $first->receipt_hash;
		$path = $first->receipt_path;
		$ack  = $first->acknowledged_at_gmt;

		// Running again must not change the legal receipt fields.
		$scheduler->generate( $this->request_id );

		$second = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $second );
		$this->assertSame( $hash, $second->receipt_hash );
		$this->assertSame( $path, $second->receipt_path );
		$this->assertSame( $ack, $second->acknowledged_at_gmt );
	}
}
