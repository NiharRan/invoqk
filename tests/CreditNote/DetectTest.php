<?php
use Invoqk\CreditNote\Detect;
use Invoqk\CreditNote\Store;
use Invoqk\Refund\Open;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;
use Invoqk\Tests\Sources\FakeAdapter;
use PHPUnit\Framework\TestCase;

final class DetectTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
	}

	protected function tearDown(): void {
		$GLOBALS['invoqk_filters'] = [];
	}

	private function order( array $open = [ 11 ] ): WC_Order {
		$o = $GLOBALS['invoqk_orders'][1] = new WC_Order( 1 );
		$o->meta[ Open::META ] = $open;
		return $o;
	}

	private function reg( array $notes ): Registry {
		return new Registry( new FakeAdapter( 'a', true, null, false, $notes ) );
	}

	private function note( int $ref = 5 ): InvoiceFile {
		return new InvoiceFile( $ref, 'application/xml', 'ubl' );
	}

	private function validations(): array {
		return array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => Detect::VALIDATE === $a['hook'] ) );
	}

	public function test_found_credit_note_is_stored_and_validation_queued(): void {
		$o = $this->order();
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$e = Store::get( $o )[11];
		$this->assertSame( 'a', $e['source'] );
		$this->assertSame( 5, $e['file']['reference'] );
		$this->assertNull( $e['validation'] );
		$this->assertSame( [ [ 1, 11, 1 ] ], array_column( $this->validations(), 'args' ) );
		$this->assertSame( [], Open::ids( $o ) );
	}

	public function test_only_matched_refund_clears_its_flag(): void {
		$o = $this->order( [ 11, 12 ] );
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$this->assertSame( [ 12 ], Open::ids( $o ) );
	}

	public function test_unchanged_entry_keeps_validation_and_is_not_requeued(): void {
		$o = $this->order();
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$e = Store::get( $o )[11];
		$e['validation'] = [ 'status' => 'passed' ];
		$e['archived']   = 99;
		Store::put( $o, 11, $e );
		$GLOBALS['invoqk_as'] = [];
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$this->assertSame( [ 'status' => 'passed' ], Store::get( $o )[11]['validation'] );
		$this->assertSame( 99, Store::get( $o )[11]['archived'] );
		$this->assertSame( [], $this->validations() );
		Detect::handle( 1, $this->reg( [ 11 => $this->note( 6 ) ] ) ); // changed reference
		$this->assertNull( Store::get( $o )[11]['validation'] );
		$this->assertCount( 1, $this->validations() );
	}

	public function test_free_install_does_nothing(): void {
		$GLOBALS['invoqk_filters'] = [];
		$o = $this->order();
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$this->assertSame( [], Store::get( $o ) );
		$this->assertSame( [ 11 ], Open::ids( $o ) );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_no_credit_note_changes_nothing(): void {
		$o = $this->order();
		Detect::handle( 1, $this->reg( [] ) );
		$this->assertSame( [], Store::get( $o ) );
		$this->assertSame( [ 11 ], Open::ids( $o ) );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_non_order_and_trashed_exit_quietly(): void {
		$GLOBALS['invoqk_orders'][1] = new stdClass();
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$o = $this->order();
		$o->status = 'trash';
		Detect::handle( 1, $this->reg( [ 11 => $this->note() ] ) );
		$this->assertSame( [], Store::get( $o ) );
	}

	public function test_sweep_enqueues_open_orders_only_once(): void {
		update_option( Open::INDEX, [ 1, 2 ] );
		Detect::boot();
		foreach ( $GLOBALS['invoqk_actions']['invoqk_sweep'] as [ $cb ] ) {
			$cb();
			$cb();
		}
		$jobs = array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => Detect::HOOK === $a['hook'] ) );
		$this->assertSame( [ [ 1 ], [ 2 ] ], array_column( $jobs, 'args' ) );
	}
}
