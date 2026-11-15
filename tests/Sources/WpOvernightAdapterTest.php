<?php
use Invoqk\Sources\WpOvernightAdapter;
use PHPUnit\Framework\TestCase;

// Stand-ins for WP Overnight's global functions (recorded from PDF Invoices & Packing Slips 5.16.6).
$GLOBALS['wpo'] = [ 'edi' => true, 'format' => 'ubl-2p1', 'exists' => true, 'contents' => '<Invoice/>', 'init_called' => false, 'cn' => true ];
if ( ! function_exists( 'wcpdf_get_document' ) ) {
	// wpo_ips_edi_is_available() lives in tests/bootstrap.php and reads $GLOBALS['invoqk_edi'].
	function wpo_ips_edi_get_current_format( bool $full = false ) { $GLOBALS['wpo']['format_calls'] = ( $GLOBALS['wpo']['format_calls'] ?? 0 ) + 1; return $GLOBALS['wpo']['format']; }
	function wcpdf_get_document( string $type, $order, bool $init = false ) {
		$GLOBALS['wpo']['init_called'] = $GLOBALS['wpo']['init_called'] || $init;
		$GLOBALS['wpo']['targets'][]   = [ $type, $order ];
		if ( 'credit-note' === $type && ! $GLOBALS['wpo']['cn'] ) {
			return false;
		}
		return new class { public function exists(): bool { return $GLOBALS['wpo']['exists']; } };
	}
	function wpo_ips_edi_write_file( $doc, bool $attachment = false, bool $contents_only = false ) {
		return $contents_only ? $GLOBALS['wpo']['contents'] : false;
	}
}

final class WpOvernightAdapterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpo'] = [ 'edi' => true, 'format' => 'ubl-2p1', 'exists' => true, 'contents' => '<Invoice/>', 'init_called' => false, 'cn' => true ];
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

	private function refund( int $id ): object {
		return new class( $id ) { public function __construct( private int $id ) {} public function get_id(): int { return $this->id; } };
	}

	public function test_find_credit_notes_returns_empty_without_the_document_type(): void {
		$GLOBALS['wpo']['cn'] = false;
		$o = new WC_Order();
		$o->refunds = [ $this->refund( 11 ) ];
		$this->assertSame( [], ( new WpOvernightAdapter() )->findCreditNotes( $o ) );
	}

	public function test_find_credit_notes_keys_files_by_refund_id(): void {
		$o = new WC_Order();
		$o->refunds = [ $this->refund( 11 ) ];
		$a = new WpOvernightAdapter();
		$files = $a->findCreditNotes( $o );
		$this->assertSame( [ 11 ], array_keys( $files ) );
		$this->assertSame( 'wpo:credit-note:11', $files[11]->reference );
		$this->assertSame( 'application/xml', $files[11]->mime );
		$this->assertTrue( $files[11]->onDemand );
		$this->assertFalse( $GLOBALS['wpo']['init_called'] );
		$this->assertSame( '<Invoice/>', $a->read( $o, $files[11] ) );
		$this->assertSame( [ 'credit-note', $o->refunds[0] ], end( $GLOBALS['wpo']['targets'] ) );
	}

	public function test_find_credit_notes_reads_the_format_only_for_an_existing_credit_note(): void {
		$GLOBALS['wpo']['cn'] = false;
		$o = new WC_Order();
		$o->refunds = [ $this->refund( 11 ) ];
		( new WpOvernightAdapter() )->findCreditNotes( $o );
		$this->assertArrayNotHasKey( 'format_calls', $GLOBALS['wpo'] );
	}

	public function test_read_returns_null_for_an_unknown_refund(): void {
		$o = new WC_Order();
		$o->refunds = [ $this->refund( 11 ) ];
		$file = new \Invoqk\Sources\InvoiceFile( 'wpo:credit-note:99', 'application/xml', 'ubl', true );
		$this->assertNull( ( new WpOvernightAdapter() )->read( $o, $file ) );
		$this->assertArrayNotHasKey( 'targets', $GLOBALS['wpo'] );
	}

	/** A stand-in for WP Overnight's EDI Document / handler: both expose the order being built. */
	private function edi( $order ): object {
		$doc = new class { public $order; };
		$doc->order = $order;
		$handler = new class { public $document; };
		$handler->document = $doc;
		return $handler;
	}

	private function wired( bool $pro = true ): WC_Order {
		$GLOBALS['invoqk_filters'] = [];
		if ( $pro ) {
			add_filter( 'invoqk_is_pro', fn() => true );
		}
		WpOvernightAdapter::boot();
		$o = new WC_Order( 7 );
		$o->meta[ \Invoqk\B2g\Reference::META ] = '04011000-12345-67';
		return $o;
	}

	private const STRUCTURE = [ 'buyer_reference' => [ 'enabled' => false, 'handler' => 'X' ], 'order_reference' => [ 'enabled' => true, 'handler' => 'Y' ] ];
	private const ELEMENT   = [ 'name' => 'cbc:BuyerReference', 'value' => 7 ];

	public function test_buyer_reference_filter_returns_the_stored_value(): void {
		$o = $this->wired();
		$h = $this->edi( $o );
		$this->assertSame( [ 'name' => 'cbc:BuyerReference', 'value' => '04011000-12345-67' ], apply_filters( 'wpo_ips_edi_ubl_buyer_reference', self::ELEMENT, [], [], $h ) );
		$s = apply_filters( 'wpo_ips_edi_document_structure', self::STRUCTURE, $h->document );
		$this->assertTrue( $s['buyer_reference']['enabled'] );
		$this->assertSame( self::STRUCTURE['order_reference'], $s['order_reference'] );
	}

	public function test_buyer_reference_of_a_credit_note_comes_from_the_parent_order(): void {
		$o = $this->wired();
		$GLOBALS['invoqk_orders'][7] = $o;
		$h = $this->edi( new WC_Order_Refund( 11, 7 ) );
		$this->assertSame( '04011000-12345-67', apply_filters( 'wpo_ips_edi_ubl_buyer_reference', self::ELEMENT, [], [], $h )['value'] );
		$this->assertTrue( apply_filters( 'wpo_ips_edi_document_structure', self::STRUCTURE, $h->document )['buyer_reference']['enabled'] );
		unset( $GLOBALS['invoqk_orders'][7] );
	}

	public function test_buyer_reference_filters_change_nothing_without_a_reference(): void {
		$this->wired();
		$h = $this->edi( new WC_Order( 8 ) );
		$this->assertSame( self::ELEMENT, apply_filters( 'wpo_ips_edi_ubl_buyer_reference', self::ELEMENT, [], [], $h ) );
		$this->assertSame( self::STRUCTURE, apply_filters( 'wpo_ips_edi_document_structure', self::STRUCTURE, $h->document ) );
	}

	public function test_buyer_reference_filters_change_nothing_without_pro(): void {
		$h = $this->edi( $this->wired( false ) );
		$this->assertSame( self::ELEMENT, apply_filters( 'wpo_ips_edi_ubl_buyer_reference', self::ELEMENT, [], [], $h ) );
		$this->assertSame( self::STRUCTURE, apply_filters( 'wpo_ips_edi_document_structure', self::STRUCTURE, $h->document ) );
	}

	public function test_buyer_reference_filters_ignore_unexpected_arguments(): void {
		$this->wired();
		$this->assertSame( self::ELEMENT, apply_filters( 'wpo_ips_edi_ubl_buyer_reference', self::ELEMENT, [], [], null ) );
		$this->assertSame( 'x', apply_filters( 'wpo_ips_edi_document_structure', 'x', null ) );
	}

	/** @dataProvider formats */
	public function test_supports_buyer_reference_only_for_ubl_formats( string $wpoFormat, string $expected ): void {
		$GLOBALS['wpo']['format'] = $wpoFormat;
		$a = new WpOvernightAdapter();
		$this->assertInstanceOf( \Invoqk\Sources\BuyerReferenceAware::class, $a );
		$this->assertSame( 'ubl' === $expected, $a->supportsBuyerReference() );
	}
}
