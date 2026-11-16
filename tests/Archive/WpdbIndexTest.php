<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\WpdbIndex;
use PHPUnit\Framework\TestCase;

final class WpdbIndexTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['invoqk_options'] = [];
	}

	private function fake(): object {
		$root = sys_get_temp_dir() . '/invoqk-abspath';
		@mkdir( "$root/wp-admin/includes", 0777, true );
		file_put_contents( "$root/wp-admin/includes/upgrade.php", '<?php if ( ! function_exists( "dbDelta" ) ) { function dbDelta( $q ) { return []; } }' );
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $root . '/' );
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		$GLOBALS['invoqk_options'] = [];
		return $GLOBALS['wpdb']    = new class {
			public string $prefix = 'wp_';
			public array $tables  = [];
			public array $columns = [];
			public array $rows    = [];
			public function get_charset_collate(): string { return ''; }
			public function prepare( string $q, ...$a ): string { return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $q ), $a ); }
			public function get_var( string $q ) {
				if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) ) {
					return in_array( $m[1], $this->tables, true ) ? $m[1] : null;
				}
				if ( preg_match( "/^SHOW COLUMNS FROM (\\S+) LIKE '(.+)'$/", $q, $m ) ) {
					return in_array( $m[1], $this->tables, true ) && in_array( $m[2], $this->columns, true ) ? $m[2] : null;
				}
				return null;
			}
			public function get_row( string $q, $out = null ) { $this->rows[] = $q; return null; }
		};
	}

	public function test_schema_recorded_only_when_table_exists(): void {
		$db = $this->fake();
		$db->columns = [ 'kind', 'parent_id' ];
		WpdbIndex::ensure();
		$this->assertFalse( get_option( 'invoqk_archive_db' ), 'failed dbDelta is retried next time' );
		$db->tables = [ 'wp_invoqk_archive' ];
		WpdbIndex::ensure();
		$this->assertSame( WpdbIndex::SCHEMA, get_option( 'invoqk_archive_db' ) );
	}

	public function test_schema_not_recorded_when_the_upgrade_columns_are_missing(): void {
		$db = $this->fake();
		$db->tables = [ 'wp_invoqk_archive' ]; // old table, ALTER did not run
		WpdbIndex::ensure();
		$this->assertFalse( get_option( 'invoqk_archive_db' ) );
		$db->columns = [ 'kind' ];
		WpdbIndex::ensure();
		$this->assertFalse( get_option( 'invoqk_archive_db' ), 'both new columns are needed' );
		$db->columns = [ 'kind', 'parent_id' ];
		WpdbIndex::ensure();
		$this->assertSame( WpdbIndex::SCHEMA, get_option( 'invoqk_archive_db' ) );
	}

	public function test_latest_for_order_upgrades_the_schema_first(): void {
		$db          = $this->fake();
		$db->tables  = [ 'wp_invoqk_archive' ];
		$db->columns = [ 'kind', 'parent_id' ];
		$this->assertNull( ( new WpdbIndex() )->latestForOrder( 5 ) );
		$this->assertSame( WpdbIndex::SCHEMA, get_option( 'invoqk_archive_db' ) );
		$this->assertStringContainsString( "kind = 'invoice'", $db->rows[0] );
	}
}
