<?php
use Invoqk\Onboarding\FirstReport;
use Invoqk\Onboarding\State;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;
use Invoqk\Tests\Sources\FakeAdapter;
use PHPUnit\Framework\TestCase;

final class FirstReportTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options']       = [];
		$GLOBALS['invoqk_as']            = [];
		$GLOBALS['invoqk_as_opt']        = [];
		$GLOBALS['invoqk_orders']        = [];
		$GLOBALS['invoqk_orders_result'] = [];
		$GLOBALS['invoqk_files']         = [ 5 => __FILE__ ];
	}

	private function reg(): Registry {
		return new Registry( new FakeAdapter( 'a', true, new InvoiceFile( 5, 'application/xml', 'ubl' ) ) );
	}

	/** @param int[] $ids */
	private function orders( array $ids, bool $required = true ): void {
		foreach ( $ids as $id ) {
			$o                              = new WC_Order( $id );
			$o->company                     = $required ? 'Acme GmbH' : '';
			$GLOBALS['invoqk_orders'][ $id ] = $o;
		}
		$GLOBALS['invoqk_orders_result'] = array_merge( $GLOBALS['invoqk_orders_result'], $ids );
	}

	private function validates(): array {
		return array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => 'invoqk_validate_order' === $a['hook'] ) );
	}

	public function test_start_once_only(): void {
		$this->assertTrue( FirstReport::start() );
		$this->assertFalse( FirstReport::start() );
		$this->assertSame( 'running', State::get() );
		$this->assertCount( 1, array_filter( $GLOBALS['invoqk_as'], fn( $a ) => FirstReport::HOOK === $a['hook'] ) );
	}

	public function test_run_keeps_20_required_with_file(): void {
		FirstReport::start();
		$this->orders( range( 100, 124 ) );
		$this->orders( [ 200, 201, 202 ], false );
		FirstReport::run( $this->reg() );
		$this->assertSame( range( 100, 119 ), State::firstReport()['ids'] );
		$this->assertSame( range( 100, 119 ), array_map( fn( $a ) => $a['args'][0], $this->validates() ) );
		$this->assertSame( 200, $GLOBALS['invoqk_orders_args']['limit'] );
		$this->assertSame( 'done', State::get() );
	}

	public function test_not_required_not_kept(): void {
		FirstReport::start();
		$this->orders( [ 1, 2 ], false );
		FirstReport::run( $this->reg() );
		$this->assertSame( [], State::firstReport()['ids'] );
	}

	public function test_run_skips_missing_order(): void {
		FirstReport::start();
		$this->orders( [ 1, 3 ] );
		$GLOBALS['invoqk_orders_result'] = [ 1, 2, 3 ];
		FirstReport::run( $this->reg() );
		$this->assertSame( [ 1, 3 ], State::firstReport()['ids'] );
	}

	public function test_run_sets_done_even_when_detector_throws(): void {
		FirstReport::start();
		$this->orders( [ 1 ] );
		$bad = new Registry( new FakeAdapter( 'a', true, null, true ) );
		try {
			FirstReport::run( $bad );
		} catch ( Throwable $e ) {
		}
		$this->assertSame( 'done', State::get() );
	}

	public function test_ids_saved_before_validation_enqueued(): void {
		FirstReport::start();
		$this->orders( [ 1, 2 ] );
		FirstReport::run( $this->reg() );
		$this->assertSame( [ 1, 2 ], $GLOBALS['invoqk_as_opt'][1]['ids'] ); // [0] is start()'s own enqueue
		$this->assertSame( [ 1, 2 ], $GLOBALS['invoqk_as_opt'][2]['ids'] );
	}

	public function test_status_counts_non_pending(): void {
		FirstReport::start();
		$this->orders( [ 1, 2, 3 ] );
		FirstReport::run( $this->reg() );
		$GLOBALS['invoqk_orders'][1]->update_meta_data( '_invoqk_validation', [ 'status' => 'passed' ] );
		unset( $GLOBALS['invoqk_orders'][3] );
		$this->assertSame( [ 'total' => 3, 'done' => 1, 'state' => 'done' ], FirstReport::status() );
	}
}
