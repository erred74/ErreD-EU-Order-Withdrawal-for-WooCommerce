<?php
/**
 * Integration tests for the CSV export URL.
 *
 * Regression: the URL was built with wp_nonce_url(), which HTML-escapes what it returns. That is
 * correct for markup, but this value is handed to the admin app as JSON and set straight onto an
 * anchor, so the browser sent "amp;_wpnonce" instead of "_wpnonce" and the export refused every
 * request with "The link you followed has expired".
 *
 * The same mistake broke the receipt link (see Rest\ReceiptUrlTest). These are the only two places
 * where the plugin builds a URL server-side and hands it to JavaScript; both are covered.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Admin\CsvExporter;

final class CsvExporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->admin_id() );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_export_url_carries_query_parameters_a_browser_will_actually_send(): void {
		$url = CsvExporter::url();

		$this->assertStringNotContainsString(
			'&amp;',
			$url,
			'The URL is delivered as JSON, not markup: an HTML-escaped separator would be sent literally by the browser.'
		);

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertSame( CsvExporter::ACTION, $params['action'] ?? null );
		$this->assertNotEmpty( $params['_wpnonce'] ?? '', 'The nonce must survive into the query string.' );
	}

	public function test_the_nonce_in_the_export_url_verifies(): void {
		parse_str( (string) wp_parse_url( CsvExporter::url(), PHP_URL_QUERY ), $params );

		$this->assertNotFalse(
			wp_verify_nonce( (string) ( $params['_wpnonce'] ?? '' ), CsvExporter::ACTION ),
			'check_admin_referer() runs this exact comparison; if it fails the export reports a stale link.'
		);
	}

	public function test_the_filters_survive_into_the_url(): void {
		parse_str( (string) wp_parse_url( CsvExporter::url( 'confirmed', 'rossi' ), PHP_URL_QUERY ), $params );

		$this->assertSame( 'confirmed', $params['status'] ?? null );
		$this->assertSame( 'rossi', $params['search'] ?? null );
	}

	/**
	 * An administrator user id.
	 */
	private function admin_id(): int {
		$existing = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( array() !== $existing ) {
			return (int) $existing[0];
		}

		return (int) wp_insert_user(
			array(
				'user_login' => 'recesso_export_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
	}
}
