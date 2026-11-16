<?php
use Invoqk\Detection\Result;
use Invoqk\Fallback\Generate;
use Invoqk\Fallback\Seller;
use Invoqk\Fallback\Store;
use Invoqk\Sources\FallbackAdapter;
use PHPUnit\Framework\TestCase;

final class GenerateTest extends TestCase {
	private WC_Order $o;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_log']     = [];
		$GLOBALS['invoqk_uploads'] = sys_get_temp_dir() . '/invoqk-gen-' . bin2hex( random_bytes( 4 ) );
		add_filter( 'invoqk_is_pro', fn() => true );
		add_filter( 'invoqk_source_adapters', fn() => [ new FallbackAdapter() ] ); // other suites leave a wcpdf stub behind
		update_option( Seller::OPTION, Seller::sanitize( [ 'name' => 'Shop GmbH', 'street' => 'Weg 1', 'postcode' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789',
			'contact_name' => 'Ann', 'contact_phone' => '030 123', 'contact_email' => 'a@example.com', 'iban' => 'DE89370400440532013000' ] ) );
		$o           = new WC_Order( 42 );
		$o->number   = '1042';
		$o->company  = 'Kunde AG';
		$o->first    = 'Max';
		$o->last     = 'Kunde';
		$o->street   = 'Hauptstr. 5';
		$o->postcode = '80331';
		$o->city     = 'München';
		$o->email    = 'einkauf@kunde.example';
		$o->total    = '500.00';
		$o->tax      = '79.83';
		$o->created  = strtotime( '2026-11-01 12:00:00 UTC' );
		$o->items    = [ new WC_Order_Item_Product( 'Widget', '420.17', '79.83', 1 ) ];
		$this->o                     = $o;
		$GLOBALS['invoqk_orders']    = [ 42 => $o ];
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}

	public function test_success_stores_invoice_and_detects_fallback_source(): void {
		$r = Generate::run( 42 );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( 'RE-' . wp_date( 'Y' ) . '-00001', $r['number'] );
		$this->assertSame( $r['number'], Store::get( $this->o )['number'] );
		$this->assertSame( 'invoqk_fallback', Result::fromOrder( $this->o )->source );
		$this->assertSame( [ 'invoqk_validate_order' ], array_column( $GLOBALS['invoqk_as'], 'hook' ) );
		$this->assertArrayNotHasKey( 'invoqk_fallback_gen_42', $GLOBALS['invoqk_options'] );
	}

	public function test_refusal_burns_no_number(): void {
		$this->o->items[0] = new WC_Order_Item_Product( 'Widget', '420.17', '33.61', 1 );
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$this->assertNull( $r['number'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_counter', $GLOBALS['invoqk_options'] );
		$this->assertNull( Store::get( $this->o ) );
		$this->assertArrayNotHasKey( 'invoqk_fallback_gen_42', $GLOBALS['invoqk_options'] );
	}

	public function test_builder_refusal_for_missing_email_burns_no_number(): void {
		$this->o->email = '';
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( ( new Invoqk\Fallback\Unsupported( 'buyer_email' ) )->message(), $r['message'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_counter', $GLOBALS['invoqk_options'] );
		$this->assertNull( Store::get( $this->o ) );
	}

	public function test_missing_seller_fields_listed_and_nothing_generated(): void {
		update_option( Seller::OPTION, Seller::sanitize( [ 'name' => 'Shop GmbH' ] ) );
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( Seller::label( 'iban' ), $r['message'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_counter', $GLOBALS['invoqk_options'] );
		$this->assertNull( Store::get( $this->o ) );
	}

	public function test_second_concurrent_call_creates_nothing(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_gen_42'] = time() . ':other';
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'already being created', $r['message'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_counter', $GLOBALS['invoqk_options'] );
		$this->assertNull( Store::get( $this->o ) );
		$this->assertSame( time() . ':other', $GLOBALS['invoqk_options']['invoqk_fallback_gen_42'], 'foreign lock stays' );
		$this->assertDirectoryDoesNotExist( $GLOBALS['invoqk_uploads'] . '/invoqk-fallback' );
	}

	public function test_stale_lock_is_taken_over(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_gen_42'] = ( time() - 61 ) . ':old';
		$this->assertTrue( Generate::run( 42 )['ok'] );
	}

	public function test_plugin_invoice_created_after_the_box_was_shown_blocks_generation(): void {
		$late = new class() implements Invoqk\Sources\SourceAdapter {
			public int $calls = 0;
			public function id(): string { return 'late_plugin'; }
			public function label(): string { return 'Late'; }
			public function isAvailable(): bool { return true; }
			public function find( WC_Order $order ): ?Invoqk\Sources\InvoiceFile { return ++$this->calls > 1 ? new Invoqk\Sources\InvoiceFile( '/x.xml', 'application/xml', 'cii', true ) : null; }
			public function read( WC_Order $order, Invoqk\Sources\InvoiceFile $file ): ?string { return null; }
			public function findCreditNotes( WC_Order $order ): array { return []; }
		};
		$GLOBALS['invoqk_filters']['invoqk_source_adapters'] = [ fn() => [ $late, new FallbackAdapter() ] ];
		$this->assertTrue( Invoqk\Fallback\Offer::available( $this->o ), 'the box saw no invoice yet' );
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$this->assertNull( $r['number'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_counter', $GLOBALS['invoqk_options'] );
		$this->assertNull( Store::get( $this->o ) );
		$this->assertSame( 'late_plugin', Result::fromOrder( $this->o )->source );
	}

	public function test_second_call_after_success_returns_existing_number(): void {
		$first  = Generate::run( 42 );
		$second = Generate::run( 42 );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( $first['number'], $second['number'] );
		$this->assertStringContainsString( $first['number'], $second['message'] );
		$this->assertSame( 1, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
	}

	public function test_write_failure_logs_void_number(): void {
		mkdir( $GLOBALS['invoqk_uploads'], 0777, true );
		file_put_contents( $GLOBALS['invoqk_uploads'] . '/invoqk-fallback', 'a file, not a dir' );
		$r = Generate::run( 42 );
		$this->assertFalse( $r['ok'] );
		$n = 'RE-' . wp_date( 'Y' ) . '-00001';
		$this->assertSame( [ "fallback void number $n for #42: RuntimeException" ], array_values( array_filter( $GLOBALS['invoqk_log'], fn( $l ) => str_starts_with( $l, 'fallback' ) ) ) );
		$this->assertNull( Store::get( $this->o ) );
		$this->assertArrayNotHasKey( 'invoqk_fallback_gen_42', $GLOBALS['invoqk_options'] );
	}
}
