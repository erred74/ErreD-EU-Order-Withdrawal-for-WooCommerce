<?php
/**
 * Integration tests for the flow page resolver.
 *
 * Regression: uninstall must only ever delete pages the plugin itself auto-created. The flow page
 * option may point to any page the merchant selected in settings, so deletion is keyed on the
 * created-by-plugin post meta marker instead — these tests pin down when that marker is (and is
 * not) applied.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Frontend\FlowPage;

final class FlowPageTest extends TestCase {

	/**
	 * Pages created during a test, removed in tearDown.
	 *
	 * @var int[]
	 */
	private array $pages = array();

	/**
	 * The flow page option value before the test, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_option;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_option = get_option( FlowPage::OPTION, 0 );
	}

	protected function tearDown(): void {
		foreach ( $this->pages as $page_id ) {
			wp_delete_post( $page_id, true );
		}
		$this->pages = array();
		update_option( FlowPage::OPTION, $this->previous_option );
		parent::tearDown();
	}

	private function make_page( string $content ): int {
		$id = wp_insert_post(
			array(
				'post_title'   => 'Flow page test',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->pages[] = $id;

		return $id;
	}

	public function test_ensure_marks_the_auto_created_page(): void {
		delete_option( FlowPage::OPTION );

		$id            = FlowPage::ensure();
		$this->pages[] = $id;

		$this->assertGreaterThan( 0, $id, 'ensure() creates the flow page.' );
		$this->assertSame( '1', get_post_meta( $id, FlowPage::CREATED_META, true ), 'The auto-created page carries the created-by-plugin marker.' );
	}

	public function test_ensure_backfills_the_marker_on_a_pristine_auto_created_page(): void {
		// A page auto-created by a release that predates the marker: default content, no meta.
		$id = $this->make_page( FlowPage::DEFAULT_CONTENT );
		update_option( FlowPage::OPTION, $id );

		$this->assertSame( $id, FlowPage::ensure(), 'The existing page is reused, not recreated.' );
		$this->assertSame( '1', get_post_meta( $id, FlowPage::CREATED_META, true ), 'The pristine default page is back-filled with the marker.' );
	}

	public function test_ensure_never_marks_a_merchant_selected_page(): void {
		// A pre-existing page the merchant pointed the setting at: it must never become deletable.
		$id = $this->make_page( '<p>Store returns policy.</p>[recesso_digitale]' );
		update_option( FlowPage::OPTION, $id );

		$this->assertSame( $id, FlowPage::ensure(), 'The merchant-selected page is reused.' );
		$this->assertSame( '', (string) get_post_meta( $id, FlowPage::CREATED_META, true ), 'A merchant-selected page must not receive the created-by-plugin marker.' );
	}
}
