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

	/*
	 * Regression: url() used to fall back to home_url('/'). A merchant who trashed or unpublished the
	 * page then had every withdrawal link — order emails, My Account, the footer — silently point at
	 * the shop front page, dropping the customer there with a valid signed token, no form and no
	 * explanation. It must now return '' so callers suppress the link, and health() must name the
	 * problem so the merchant is told rather than left to discover it from a customer complaint.
	 */

	public function test_the_url_is_empty_when_no_page_is_configured(): void {
		delete_option( FlowPage::OPTION );

		$this->assertSame( '', FlowPage::url(), 'With no page configured there is nothing to link to.' );
		$this->assertSame( FlowPage::HEALTH_NOT_SET, FlowPage::health() );
	}

	public function test_the_url_is_empty_when_the_page_was_deleted(): void {
		$id = $this->make_page( FlowPage::DEFAULT_CONTENT );
		update_option( FlowPage::OPTION, $id );
		$this->assertNotSame( '', FlowPage::url(), 'Sanity: a published page with the shortcode resolves.' );

		wp_delete_post( $id, true );
		$this->pages = array();

		$this->assertSame( '', FlowPage::url(), 'A deleted page must not resolve to the site home.' );
		$this->assertSame( FlowPage::HEALTH_MISSING, FlowPage::health() );
	}

	public function test_the_url_is_empty_when_the_page_is_a_draft(): void {
		$id = $this->make_page( FlowPage::DEFAULT_CONTENT );
		update_option( FlowPage::OPTION, $id );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( '', FlowPage::url(), 'A draft is not reachable by a customer, so no link may be offered.' );
		$this->assertSame( FlowPage::HEALTH_NOT_PUBLISHED, FlowPage::health() );
	}

	public function test_the_url_is_empty_when_the_page_is_in_the_trash(): void {
		$id = $this->make_page( FlowPage::DEFAULT_CONTENT );
		update_option( FlowPage::OPTION, $id );

		wp_trash_post( $id );

		$this->assertSame( '', FlowPage::url(), 'A trashed page resolves to a permalink that 404s; suppress the link instead.' );
		$this->assertSame( FlowPage::HEALTH_NOT_PUBLISHED, FlowPage::health() );
	}

	public function test_a_published_page_without_the_form_still_resolves_but_is_reported(): void {
		// Advisory only, and deliberately so: page builders keep their content in post meta and a
		// synced pattern hides the block, so plenty of working pages fail this check. Warn, never
		// withdraw the link.
		$id = $this->make_page( '<p>Nothing to see here.</p>' );
		update_option( FlowPage::OPTION, $id );

		$this->assertNotSame( '', FlowPage::url(), 'A published page keeps its link: the form check is a heuristic.' );
		$this->assertSame( FlowPage::HEALTH_NO_FORM, FlowPage::health() );
	}

	public function test_the_block_counts_as_hosting_the_form(): void {
		$id = $this->make_page( '<!-- wp:' . FlowPage::BLOCK . ' /-->' );
		update_option( FlowPage::OPTION, $id );

		$this->assertSame( FlowPage::HEALTH_OK, FlowPage::health(), 'The block hosts the flow just as the shortcode does.' );
	}

	public function test_the_shortcode_counts_as_hosting_the_form(): void {
		$id = $this->make_page( '<p>Returns policy.</p>[' . FlowPage::SHORTCODE . ']' );
		update_option( FlowPage::OPTION, $id );

		$this->assertSame( FlowPage::HEALTH_OK, FlowPage::health() );
	}

	public function test_the_plugins_other_shortcodes_do_not_pass_for_the_flow(): void {
		// The model form and the exclusion notice share the prefix but do not host the flow, so a page
		// carrying only one of them must still be reported as missing the form.
		$id = $this->make_page( '[' . FlowPage::SHORTCODE . '_modulo]' );
		update_option( FlowPage::OPTION, $id );

		$this->assertSame( FlowPage::HEALTH_NO_FORM, FlowPage::health() );
	}
}
