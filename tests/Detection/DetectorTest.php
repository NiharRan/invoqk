<?php
use Invoqk\Detection\Detector;
use Invoqk\Detection\Result;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;
use Invoqk\Tests\Sources\FakeAdapter;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_orders'] = [];
		$GLOBALS['invoqk_files']  = [ 5 => __FILE__, 6 => '/nonexistent/file.xml' ];
	}

	private function order( int $id = 1 ): WC_Order {
		return $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
	}

	private function file( int $ref = 5 ): InvoiceFile {
		return new InvoiceFile( $ref, 'application/xml', 'ubl' );
	}

	public function test_hit_saves_source_file_and_timestamp(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 1 );
		$r = Result::fromOrder( $o );
		$this->assertSame( 'a', $r->source );
		$this->assertSame( 'A', $r->label );
		$this->assertSame( 5, $r->file->reference );
		$this->assertGreaterThan( 0, $r->detectedAt );
	}

	public function test_miss_saves_source_none(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a' ) ) ) )->run( 1 );
		$r = Result::fromOrder( $o );
		$this->assertSame( 'none', $r->source );
		$this->assertNull( $r->file );
	}

	public function test_deleted_order_exits_quietly(): void {
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 999 );
		$this->assertTrue( true );
	}

	public function test_trashed_order_is_skipped(): void {
		$o = $this->order();
		$o->status = 'trash';
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 1 );
		$this->assertNull( Result::fromOrder( $o ) );
	}

	public function test_two_sources_first_in_registry_order_wins(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ), new FakeAdapter( 'b', true, $this->file() ) ) ) )->run( 1 );
		$this->assertSame( 'a', Result::fromOrder( $o )->source );
	}

	public function test_cached_file_with_missing_source_plugin_stays_until_file_gone(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file( 5 ) ) ) ) )->run( 1 );
		// source plugin deactivated, file still on disk: keep
		( new Detector( new Registry( new FakeAdapter( 'a', false ) ) ) )->run( 1 );
		$this->assertSame( 'a', Result::fromOrder( $o )->source );
		// file removed: now none
		$GLOBALS['invoqk_files'][5] = '/nonexistent/gone.xml';
		( new Detector( new Registry( new FakeAdapter( 'a', false ) ) ) )->run( 1 );
		$this->assertSame( 'none', Result::fromOrder( $o )->source );
	}

	public function test_meta_written_through_order_crud(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 1 );
		$this->assertSame( 'a', $o->get_meta( '_invoqk_source' ) );
		$this->assertNotEmpty( $o->get_meta( '_invoqk_detected_at' ) );
	}

	public function test_refund_object_exits_quietly(): void {
		$GLOBALS['invoqk_orders'][4651] = new stdClass(); // e.g. WC OrderRefund is not a WC_Order
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 4651 );
		$this->assertTrue( true );
	}

	public function test_on_demand_cache_cleared_when_adapter_available_but_finds_nothing(): void {
		$o = $this->order();
		$od = new InvoiceFile( 'wpo:invoice', 'application/xml', 'ubl', true );
		( new Detector( new Registry( new FakeAdapter( 'a', true, $od ) ) ) )->run( 1 );
		( new Detector( new Registry( new FakeAdapter( 'a', true ) ) ) )->run( 1 );
		$this->assertSame( 'none', Result::fromOrder( $o )->source );
	}

	public function test_on_demand_cache_kept_when_adapter_unavailable_or_gone(): void {
		$o = $this->order();
		$od = new InvoiceFile( 'wpo:invoice', 'application/xml', 'ubl', true );
		( new Detector( new Registry( new FakeAdapter( 'a', true, $od ) ) ) )->run( 1 );
		( new Detector( new Registry( new FakeAdapter( 'a', false ) ) ) )->run( 1 );
		$this->assertSame( 'a', Result::fromOrder( $o )->source );
		( new Detector( new Registry() ) )->run( 1 );
		$this->assertSame( 'a', Result::fromOrder( $o )->source );
	}

	public function test_detection_does_not_fire_full_order_save(): void {
		$o = $this->order();
		( new Detector( new Registry( new FakeAdapter( 'a', true, $this->file() ) ) ) )->run( 1 );
		$this->assertSame( 0, $o->saves );
		$this->assertSame( 1, $o->metaSaves );
	}

	public function test_never_checked_order_has_no_result(): void {
		$this->assertNull( Result::fromOrder( $this->order() ) );
	}
}
