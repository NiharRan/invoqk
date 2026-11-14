<?php
use Invoqk\Jobs\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_as'] = [];
		$GLOBALS['invoqk_orders_args'] = null;
		$GLOBALS['invoqk_orders_result'] = [];
	}

	public function test_backoff_values(): void {
		$this->assertSame( 60, Scheduler::backoff( 1 ) );
		$this->assertSame( 300, Scheduler::backoff( 2 ) );
		$this->assertSame( 1800, Scheduler::backoff( 3 ) );
		$this->assertNull( Scheduler::backoff( 4 ) );
	}

	public function test_status_change_enqueues_one_async_job_not_duplicates(): void {
		Scheduler::enqueue( 7 );
		Scheduler::enqueue( 7 );
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( [ 7 ], $GLOBALS['invoqk_as'][0]['args'] );
		$this->assertSame( 'invoqk', $GLOBALS['invoqk_as'][0]['group'] );
	}

	public function test_sweep_enqueues_orders_without_result_younger_than_30_days(): void {
		$GLOBALS['invoqk_orders_result'] = [ 11, 12 ];
		Scheduler::sweep();
		$args = $GLOBALS['invoqk_orders_args'];
		$this->assertSame( 'ids', $args['return'] );
		$this->assertSame( '>' . strtotime( '-30 days' ), $args['date_created'] );
		$this->assertSame( '_invoqk_source', $args['meta_query'][0]['key'] );
		$this->assertSame( 'NOT EXISTS', $args['meta_query'][0]['compare'] );
		$this->assertCount( 2, $GLOBALS['invoqk_as'] );
	}

	public function test_failed_job_reschedules_with_backoff_then_gives_up(): void {
		$fail = fn() => throw new \RuntimeException( 'x' );
		Scheduler::handle( 5, 1, $fail );
		$this->assertSame( [ 5, 2 ], $GLOBALS['invoqk_as'][0]['args'] );
		$this->assertEqualsWithDelta( time() + 60, $GLOBALS['invoqk_as'][0]['when'], 2 );
		$GLOBALS['invoqk_as'] = [];
		Scheduler::handle( 5, 4, $fail );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_no_remote_http_in_plugin_source(): void {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) ) as $f ) {
			if ( $f->isFile() ) {
				$this->assertDoesNotMatchRegularExpression( '/wp_remote_|curl_init|https?:\/\//', file_get_contents( $f->getPathname() ), $f->getFilename() );
			}
		}
	}
}
