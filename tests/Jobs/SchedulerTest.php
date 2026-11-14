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

	public function test_sweep_enqueues_only_unchecked_recent_orders(): void {
		$checked = new WC_Order( 12 );
		$checked->update_meta_data( '_invoqk_source', 'none' );
		$GLOBALS['invoqk_orders'] = [ 11 => new WC_Order( 11 ), 12 => $checked ];
		$GLOBALS['invoqk_orders_result'] = [ 11, 12 ];
		Scheduler::sweep();
		$args = $GLOBALS['invoqk_orders_args'];
		$this->assertSame( 'ids', $args['return'] );
		$this->assertSame( '>' . strtotime( '-30 days' ), $args['date_created'] );
		$this->assertArrayNotHasKey( 'meta_query', $args ); // ignored by the legacy (non-HPOS) order store
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( [ 11 ], $GLOBALS['invoqk_as'][0]['args'] );
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

	public function test_sweep_only_asks_for_shop_orders_not_refunds(): void {
		Scheduler::sweep();
		$this->assertSame( 'shop_order', $GLOBALS['invoqk_orders_args']['type'] );
	}

	public function test_sweep_scheduled_once_per_version_without_querying_each_time(): void {
		$GLOBALS['invoqk_options'] = [];
		Scheduler::ensureSweep();
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$GLOBALS['invoqk_as'] = [];
		Scheduler::ensureSweep(); // flag set: must not touch Action Scheduler at all
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_deactivate_clears_sweep_flag(): void {
		Scheduler::ensureSweep();
		Scheduler::deactivate();
		$this->assertFalse( get_option( 'invoqk_sweep_scheduled' ) );
	}

	public function test_wp_overnight_invoice_save_enqueues_detection(): void {
		$GLOBALS['invoqk_actions'] = [];
		Scheduler::boot();
		do_action( 'wpo_wcpdf_save_document', new stdClass(), new WC_Order( 33 ) );
		$this->assertSame( [ 33 ], $GLOBALS['invoqk_as'][0]['args'] );
	}

	public function test_enqueue_validate_dedupes_same_args(): void {
		Scheduler::enqueueValidate( 7 );
		Scheduler::enqueueValidate( 7 );
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( Scheduler::VALIDATE, $GLOBALS['invoqk_as'][0]['hook'] );
		$this->assertSame( [ 7, 1, false ], $GLOBALS['invoqk_as'][0]['args'] );
	}

	public function test_force_and_non_force_are_distinct_jobs_but_double_click_force_is_one(): void {
		Scheduler::enqueueValidate( 7 );
		Scheduler::enqueueValidate( 7, true );
		Scheduler::enqueueValidate( 7, true );
		$this->assertCount( 2, $GLOBALS['invoqk_as'] );
		$this->assertSame( [ 7, 1, true ], $GLOBALS['invoqk_as'][1]['args'] );
	}

	public function test_wpo_save_document_enqueues_detect_and_validate(): void {
		Scheduler::boot();
		do_action( 'wpo_wcpdf_save_document', new stdClass(), new WC_Order( 34 ) );
		$hooks = array_column( $GLOBALS['invoqk_as'], 'hook' );
		$this->assertContains( Scheduler::DETECT, $hooks );
		$this->assertContains( Scheduler::VALIDATE, $hooks );
	}

	public function test_sweep_queues_validation_for_error_and_missing_record_only(): void {
		$file = [ 'reference' => 5, 'mime' => 'application/xml' ];
		$mk = function ( int $id, ?string $status ) use ( $file ) {
			$o = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
			$o->update_meta_data( '_invoqk_source', 'a' );
			$o->update_meta_data( '_invoqk_file', $file );
			if ( $status ) {
				$o->update_meta_data( '_invoqk_validation', [ 'status' => $status ] );
			}
		};
		$GLOBALS['invoqk_orders'] = [];
		$mk( 21, 'passed' );
		$mk( 22, 'error' );
		$mk( 23, null );
		$mk( 24, 'limit' );
		$GLOBALS['invoqk_orders_result'] = [ 21, 22, 23, 24 ];
		$GLOBALS['invoqk_filters'] = [];
		Scheduler::sweep();
		$this->assertSame( [ [ 22, 1, false ], [ 23, 1, false ] ], array_column( $GLOBALS['invoqk_as'], 'args' ) );
		$GLOBALS['invoqk_as'] = [];
		add_filter( 'invoqk_is_pro', fn() => true );
		Scheduler::sweep();
		$this->assertSame( [ 22, 23, 24 ], array_column( array_column( $GLOBALS['invoqk_as'], 'args' ), 0 ) );
		$GLOBALS['invoqk_filters'] = [];
	}

	/** Remote-HTTP patterns forbidden in a file; only src/Validation/Client.php may call wp_remote_*. */
	private static function forbidden( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		if ( str_ends_with( $path, '/src/Validation/Limit.php' ) ) {
			return 'wp_remote_|curl_init'; // only the upgrade-URL literal is exempt
		}
		return str_ends_with( $path, '/src/Validation/Client.php' ) ? 'curl_init|https?:\/\/' : 'wp_remote_|curl_init|https?:\/\/';
	}

	public function test_no_remote_http_in_plugin_source(): void {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) ) as $f ) {
			if ( $f->isFile() ) {
				$this->assertDoesNotMatchRegularExpression( '/' . self::forbidden( $f->getPathname() ) . '/', file_get_contents( $f->getPathname() ), $f->getPathname() );
			}
		}
	}

	public function test_guard_exemption_is_scoped_to_validation_client(): void {
		$code = 'wp_remote_post( $u );';
		$this->assertSame( 0, preg_match( '/' . self::forbidden( '/x/src/Validation/Client.php' ) . '/', $code ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Other/Client.php' ) . '/', $code ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Validation/Mapper.php' ) . '/', $code ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Validation/Client.php' ) . '/', 'curl_init();' ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( 'C:\\p\\src\\Validation\\Client.php' ) . '/', 'https://x' ) );
	}

	public function test_guard_https_exemption_is_scoped_to_limit(): void {
		$f = fn( string $p, string $code ) => preg_match( '/' . self::forbidden( $p ) . '/', $code );
		$this->assertSame( 0, $f( '/x/src/Validation/Limit.php', 'https://invoqk.com' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Limit.php', 'wp_remote_get();' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Limit.php', 'curl_init();' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Client.php', 'https://x' ) );
		$this->assertSame( 1, $f( '/x/src/Other/Limit.php', 'https://x' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Mapper.php', 'http://x' ) );
	}
}
