<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\WpdbIndex;
use PHPUnit\Framework\TestCase;

final class WpdbIndexTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['invoqk_options'] = [];
	}

	public function test_schema_recorded_only_when_table_exists(): void {
		$root = sys_get_temp_dir() . '/invoqk-abspath';
		@mkdir( "$root/wp-admin/includes", 0777, true );
		file_put_contents( "$root/wp-admin/includes/upgrade.php", '<?php if ( ! function_exists( "dbDelta" ) ) { function dbDelta( $q ) { return []; } }' );
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $root . '/' );
		}
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['wpdb']           = new class {
			public string $prefix = 'wp_';
			public array $tables  = [];
			public function get_charset_collate(): string { return ''; }
			public function prepare( string $q, ...$a ): string { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
			public function get_var( string $q ) { return preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) && in_array( $m[1], $this->tables, true ) ? $m[1] : null; }
		};
		WpdbIndex::ensure();
		$this->assertFalse( get_option( 'invoqk_archive_db' ), 'failed dbDelta is retried next time' );
		$GLOBALS['wpdb']->tables = [ 'wp_invoqk_archive' ];
		WpdbIndex::ensure();
		$this->assertSame( WpdbIndex::SCHEMA, get_option( 'invoqk_archive_db' ) );
	}
}
