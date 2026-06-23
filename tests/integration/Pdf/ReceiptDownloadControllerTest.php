<?php
/**
 * Integration tests for the receipt download controller's path hardening.
 *
 * Focuses on the directory-boundary check that prevents a stored path in a sibling directory
 * with a similar prefix (e.g. `recesso-digitale-private-x`) from being served as if it lived
 * inside the protected receipts directory.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Pdf;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Pdf\ReceiptBuilder;
use Recesso54bis\Pdf\ReceiptDownloadController;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\PermissionGate;
use Recesso54bis\Support\OrderToken;

final class ReceiptDownloadControllerTest extends TestCase {

	private string $base_dir    = '';
	private string $sibling_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$uploads           = wp_upload_dir();
		$this->base_dir    = trailingslashit( $uploads['basedir'] ) . ReceiptBuilder::PRIVATE_DIR;
		$this->sibling_dir = $this->base_dir . '-public';

		wp_mkdir_p( $this->base_dir );
		wp_mkdir_p( $this->sibling_dir );
		file_put_contents( $this->base_dir . '/legit.pdf', '%PDF-1.4 test' );
		file_put_contents( $this->sibling_dir . '/sneaky.pdf', '%PDF-1.4 test' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->base_dir . '/legit.pdf', $this->sibling_dir . '/sneaky.pdf' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		parent::tearDown();
	}

	private function is_within_private_dir( string $path ): bool {
		$controller = new ReceiptDownloadController( new RequestRepository(), new PermissionGate( new OrderToken() ) );
		$method     = new \ReflectionMethod( $controller, 'is_within_private_dir' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $controller, $path );
	}

	public function test_file_inside_private_dir_is_accepted(): void {
		$this->assertTrue( $this->is_within_private_dir( $this->base_dir . '/legit.pdf' ) );
	}

	public function test_file_in_similarly_prefixed_sibling_dir_is_rejected(): void {
		// `recesso-digitale-private-public` shares the prefix but is outside the protected dir.
		$this->assertFalse( $this->is_within_private_dir( $this->sibling_dir . '/sneaky.pdf' ) );
	}

	public function test_empty_path_is_rejected(): void {
		$this->assertFalse( $this->is_within_private_dir( '' ) );
	}
}
