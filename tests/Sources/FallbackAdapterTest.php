<?php
use Invoqk\Fallback\Store;
use Invoqk\Sources\FallbackAdapter;
use PHPUnit\Framework\TestCase;

final class FallbackAdapterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_uploads'] = sys_get_temp_dir() . '/invoqk-fb-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}

	public function test_find_returns_cii_file_when_stored(): void {
		$o = new WC_Order( 3 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$f = ( new FallbackAdapter() )->find( $o );
		$this->assertSame( Store::path( $o ), $f->reference );
		$this->assertSame( 'application/xml', $f->mime );
		$this->assertSame( 'cii', $f->format );
		$this->assertFalse( $f->onDemand );
		$this->assertSame( 'invoqk_fallback', ( new FallbackAdapter() )->id() );
		$this->assertSame( 'Invoqk fallback', ( new FallbackAdapter() )->label() );
	}

	public function test_find_null_without_invoice(): void {
		$this->assertNull( ( new FallbackAdapter() )->find( new WC_Order( 3 ) ) );
	}

	public function test_existing_invoice_still_found_after_pro_ends(): void {
		add_filter( 'invoqk_is_pro', fn() => true );
		$o = new WC_Order( 3 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$GLOBALS['invoqk_filters'] = [];
		add_filter( 'invoqk_is_pro', fn() => false );
		$a = new FallbackAdapter();
		$this->assertTrue( $a->isAvailable() );
		$this->assertNotNull( $a->find( $o ) );
	}

	public function test_unavailable_on_free_before_any_generation(): void {
		add_filter( 'invoqk_is_pro', fn() => false );
		$this->assertFalse( ( new FallbackAdapter() )->isAvailable() );
		add_filter( 'invoqk_is_pro', fn() => true );
		$this->assertTrue( ( new FallbackAdapter() )->isAvailable() );
	}

	public function test_read_returns_bytes(): void {
		$o = new WC_Order( 3 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$a = new FallbackAdapter();
		$this->assertSame( '<x/>', $a->read( $o, $a->find( $o ) ) );
	}

	public function test_credit_notes_empty(): void {
		$this->assertSame( [], ( new FallbackAdapter() )->findCreditNotes( new WC_Order( 3 ) ) );
	}
}
