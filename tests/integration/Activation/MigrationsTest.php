<?php
/**
 * Integration tests for the schema migrations.
 *
 * Regression: an in-place plugin update must add the v4 optional columns (refund_iban,
 * withdrawal_reason) so the requests queries do not fail until a manual re-activation, and the
 * installed version must only advance once those columns actually exist.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Activation;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Persistence\Schema;

final class MigrationsTest extends TestCase {

	protected function tearDown(): void {
		// Leave the schema current for the rest of the suite.
		Migrations::run();
		parent::tearDown();
	}

	private function columns(): array {
		global $wpdb;
		$table = Schema::requests_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	}

	public function test_run_adds_the_optional_columns_in_place_and_advances_the_version(): void {
		global $wpdb;
		$table = Schema::requests_table();

		// Ensure the table exists, then simulate a pre-v4 schema by dropping the optional columns and
		// rewinding the recorded version, as an in-place update from an older release would leave it.
		Migrations::run();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN refund_iban" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN withdrawal_reason" );
		update_option( Migrations::VERSION_OPTION, '3' );

		$this->assertNotContains( 'refund_iban', $this->columns(), 'Precondition: the column was dropped.' );

		Migrations::run();

		$columns = $this->columns();
		$this->assertContains( 'refund_iban', $columns, 'The migration must add refund_iban in place.' );
		$this->assertContains( 'withdrawal_reason', $columns, 'The migration must add withdrawal_reason in place.' );
		$this->assertSame( Migrations::CURRENT_VERSION, (string) get_option( Migrations::VERSION_OPTION ), 'The version advances once the columns exist.' );
	}

	public function test_run_adds_the_v5_consumer_declaration_column_in_place(): void {
		global $wpdb;
		$table = Schema::requests_table();

		Migrations::run();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN consumer_declaration" );
		update_option( Migrations::VERSION_OPTION, '4' );

		$this->assertNotContains( 'consumer_declaration', $this->columns(), 'Precondition: the column was dropped.' );

		Migrations::run();

		$this->assertContains( 'consumer_declaration', $this->columns(), 'The migration must add consumer_declaration in place.' );
		$this->assertSame( Migrations::CURRENT_VERSION, (string) get_option( Migrations::VERSION_OPTION ) );
	}

	public function test_run_recovers_a_table_that_can_no_longer_take_an_instant_column(): void {
		// Regression: repeated in-place upgrades can leave InnoDB refusing a further ADD COLUMN with
		// "Row size too large", even though the same schema creates cleanly from scratch. The
		// migration must rebuild the table and complete rather than leaving the schema half-applied —
		// a half-applied schema breaks every requests query until a manual re-activation.
		global $wpdb;
		$table = Schema::requests_table();

		Migrations::run();

		// Churn the optional columns with instant DDL until InnoDB starts refusing, or we give up.
		for ( $i = 0; $i < 12; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN withdrawal_reason, ALGORITHM=INSTANT" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN withdrawal_reason text, ALGORITHM=INSTANT" );
			if ( '' !== $wpdb->last_error ) {
				break;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN withdrawal_reason" );
		update_option( Migrations::VERSION_OPTION, '3' );

		Migrations::run();

		$this->assertContains( 'withdrawal_reason', $this->columns(), 'The migration recovers by rebuilding the table.' );
		$this->assertSame( Migrations::CURRENT_VERSION, (string) get_option( Migrations::VERSION_OPTION ) );
	}
}
