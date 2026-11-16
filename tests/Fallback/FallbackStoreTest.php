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
		$this->assertStringContainsString( 'denied', file_get_contents( Store::dir() . '/.htaccess' ) );
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
}
