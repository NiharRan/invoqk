<?php
use Invoqk\Sources\WpOvernightAdapter;
use PHPUnit\Framework\TestCase;

// Stand-ins for WP Overnight's global functions (recorded from PDF Invoices & Packing Slips 5.16.6).
$GLOBALS['wpo'] = [ 'edi' => true, 'format' => 'ubl-2p1', 'exists' => true, 'contents' => '<Invoice/>', 'init_called' => false ];
if ( ! function_exists( 'wcpdf_get_document' ) ) {
	// wpo_ips_edi_is_available() lives in tests/bootstrap.php and reads $GLOBALS['invoqk_edi'].
	function wpo_ips_edi_get_current_format( bool $full = false ) { return $GLOBALS['wpo']['format']; }
	function wcpdf_get_document( string $type, $order, bool $init = false ) {
		$GLOBALS['wpo']['init_called'] = $GLOBALS['wpo']['init_called'] || $init;
		return new class { public function exists(): bool { return $GLOBALS['wpo']['exists']; } };
	}
	function wpo_ips_edi_write_file( $doc, bool $attachment = false, bool $contents_only = false ) {
		return $contents_only ? $GLOBALS['wpo']['contents'] : false;
	}
}

final class WpOvernightAdapterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpo'] = [ 'edi' => true, 'format' => 'ubl-2p1', 'exists' => true, 'contents' => '<Invoice/>', 'init_called' => false ];
		$GLOBALS['invoqk_edi'] = true;
	}

	/** @dataProvider formats */
	public function test_finds_invoice_per_format( string $wpoFormat, string $expected ): void {
		$GLOBALS['wpo']['format'] = $wpoFormat;
		$f = ( new WpOvernightAdapter() )->find( new WC_Order() );
		$this->assertSame( $expected, $f->format );
		$this->assertSame( 'application/xml', $f->mime );
		$this->assertTrue( $f->onDemand );
	}

	public static function formats(): array {
		return [
			'ubl'      => [ 'ubl-2p1', 'ubl' ],
			'peppol'   => [ 'peppol-bis-3p0', 'ubl' ],
			'cii'      => [ 'cii-d16b', 'cii' ],
			'facturx'  => [ 'factur-x-1p0', 'facturx' ],
			'zugferd2' => [ 'zugferd-2p0', 'zugferd' ],
			'zugferd1' => [ 'zugferd-1p0', 'zugferd' ],
		];
	}

	public function test_returns_null_when_no_invoice_created_yet(): void {
		$GLOBALS['wpo']['exists'] = false;
		$this->assertNull( ( new WpOvernightAdapter() )->find( new WC_Order() ) );
	}

	public function test_find_never_creates_the_invoice(): void {
		( new WpOvernightAdapter() )->find( new WC_Order() );
		$this->assertFalse( $GLOBALS['wpo']['init_called'] );
	}

	public function test_is_available_false_when_edi_disabled(): void {
		$GLOBALS['invoqk_edi'] = false;
		$this->assertFalse( ( new WpOvernightAdapter() )->isAvailable() );
	}

	public function test_read_builds_contents_in_memory(): void {
		$a = new WpOvernightAdapter();
		$o = new WC_Order();
		$this->assertSame( '<Invoice/>', $a->read( $o, $a->find( $o ) ) );
	}

	public function test_read_returns_null_when_build_fails(): void {
		$GLOBALS['wpo']['contents'] = false;
		$a = new WpOvernightAdapter();
		$o = new WC_Order();
		$this->assertNull( $a->read( $o, $a->find( $o ) ) );
	}
}
