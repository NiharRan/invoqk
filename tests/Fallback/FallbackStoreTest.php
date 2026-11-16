<?php
use Invoqk\Fallback\Store;
use PHPUnit\Framework\TestCase;

final class FallbackStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_uploads'] = sys_get_temp_dir() . '/invoqk-store-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}

	public function test_save_writes_file_outside_guessable_name_and_records_meta(): void {
		$o = new WC_Order( 7 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$m = Store::get( $o );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\.xml$/', $m['file'] );
		$this->assertSame( 'RE-1', $m['number'] );
		$this->assertSame( 'xrechnung', $m['profile'] );
		$this->assertSame( hash( 'sha256', '<x/>' ), $m['hash'] );
		$this->assertNotSame( '', $m['date'] );
		$this->assertSame( '<x/>', file_get_contents( Store::path( $o ) ) );
		$this->assertFileExists( Store::dir() . '/index.php' );
		$ht = file_get_contents( Store::dir() . '/.htaccess' );
		$this->assertStringContainsString( "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>", $ht );
		$this->assertStringContainsString( "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>", $ht );
		$this->assertTrue( (bool) get_option( 'invoqk_fallback_used' ) );
		$this->assertSame( 1, $o->metaSaves );
		$this->assertSame( 0, $o->saves );
	}

	public function test_path_rejects_meta_pointing_outside_dir(): void {
		$o = new WC_Order( 7 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		foreach ( [ '../../etc/passwd', '/etc/passwd', str_repeat( 'a', 32 ) . '.xml/../x.xml' ] as $bad ) {
			$o->update_meta_data( Store::META, [ 'file' => $bad ] + Store::get( $o ) );
			$this->assertNull( Store::path( $o ), $bad );
		}
		$o->update_meta_data( Store::META, [ 'file' => str_repeat( 'a', 32 ) . '.xml' ] );
		$this->assertNull( Store::path( $o ), 'missing file' );
		$this->assertNull( Store::path( new WC_Order( 8 ) ) );
	}

	public function test_save_throws_when_dir_not_writable(): void {
		mkdir( $GLOBALS['invoqk_uploads'], 0777, true );
		file_put_contents( $GLOBALS['invoqk_uploads'] . '/invoqk-fallback', 'a file, not a dir' );
		$o = new WC_Order( 7 );
		try {
			Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
			$this->fail( 'expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertNull( Store::get( $o ) );
			$this->assertSame( 0, $o->metaSaves );
		}
	}

	public function test_path_on_missing_folder_returns_null_and_creates_nothing(): void {
		$this->assertNull( Store::path( new WC_Order( 9 ) ) );
		$o = new WC_Order( 9 );
		$o->update_meta_data( Store::META, [ 'file' => str_repeat( 'a', 32 ) . '.xml' ] );
		$this->assertNull( Store::path( $o ) );
		$this->assertDirectoryDoesNotExist( $GLOBALS['invoqk_uploads'] . '/invoqk-fallback' );
	}

	public function test_failed_meta_save_removes_the_file(): void {
		$o = new class( 7 ) extends WC_Order {
			public function save_meta_data(): void { throw new \RuntimeException( 'db down' ); }
		};
		try {
			Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
			$this->fail( 'expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'db down', $e->getMessage() );
		}
		$this->assertSame( [], array_values( array_diff( scandir( Store::dir() ), [ '.', '..', 'index.php', '.htaccess' ] ) ) );
	}
}
