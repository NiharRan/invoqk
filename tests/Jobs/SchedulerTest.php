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
		$mk = function ( int $id, ?string $status, ?string $code = null ) use ( $file ) {
			$o = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
			$o->update_meta_data( '_invoqk_source', 'a' );
			$o->update_meta_data( '_invoqk_file', $file );
			if ( $status ) {
				$o->update_meta_data( '_invoqk_validation', [ 'status' => $status ] + ( $code ? [ 'error' => [ 'code' => $code ] ] : [] ) );
			}
		};
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'k';
		$GLOBALS['invoqk_orders'] = [];
		$mk( 21, 'passed' );
		$mk( 22, 'error', 'no_key' );
		$mk( 23, null );
		$mk( 24, 'limit' );
		$mk( 25, 'error', 'unauthorized' );
		$mk( 26, 'error', 'unavailable' );
		$mk( 27, 'error', 'unreadable' );
		$GLOBALS['invoqk_orders_result'] = [ 21, 22, 23, 24, 25, 26, 27 ];
		$GLOBALS['invoqk_filters'] = [];
		Scheduler::sweep();
		$this->assertSame( [ [ 22, 1, false ], [ 23, 1, false ], [ 27, 1, false ] ], array_column( $GLOBALS['invoqk_as'], 'args' ) );
		$GLOBALS['invoqk_as'] = [];
		add_filter( 'invoqk_is_pro', fn() => true );
		Scheduler::sweep();
		$this->assertSame( [ 22, 23, 24, 27 ], array_column( array_column( $GLOBALS['invoqk_as'], 'args' ), 0 ) );
		$GLOBALS['invoqk_filters'] = [];
	}

	private function activationSweep(): void {
		$GLOBALS['invoqk_options']['invoqk_activated_at'] = 1000;
		$GLOBALS['invoqk_orders'] = [];
		foreach ( [ 31 => 500, 32 => 2000 ] as $id => $created ) {
			$o = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
			$o->created = $created;
			$o->update_meta_data( '_invoqk_source', 'a' );
			$o->update_meta_data( '_invoqk_file', [ 'reference' => 5, 'mime' => 'application/xml' ] );
			$o->update_meta_data( '_invoqk_validation', [ 'status' => 'pending' ] );
		}
		$GLOBALS['invoqk_orders_result'] = [ 31, 32 ];
		$GLOBALS['invoqk_filters'] = [];
	}

	public function test_sweep_skips_orders_older_than_activation_on_free(): void {
		$this->activationSweep();
		Scheduler::sweep();
		$this->assertSame( [ 32 ], array_column( array_column( $GLOBALS['invoqk_as'], 'args' ), 0 ) );
	}

	public function test_sweep_validates_old_orders_on_pro(): void {
		$this->activationSweep();
		add_filter( 'invoqk_is_pro', fn() => true );
		Scheduler::sweep();
		$this->assertSame( [ 31, 32 ], array_column( array_column( $GLOBALS['invoqk_as'], 'args' ), 0 ) );
		$GLOBALS['invoqk_filters'] = [];
	}

	private function preActivationUndetected( int $id ): WC_Order {
		$GLOBALS['invoqk_options']   = [ 'invoqk_activated_at' => 1000, 'invoqk_api_key' => 'k' ];
		$GLOBALS['invoqk_orders']    = [];
		$GLOBALS['invoqk_filters']   = [];
		$GLOBALS['invoqk_files']     = [ 5 => __FILE__ ];
		$o                           = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
		$o->created                  = 500;
		$GLOBALS['invoqk_orders_result'] = [ $id ];
		return $o;
	}

	private function sweepAndDetect( int $id ): void {
		Scheduler::sweep();
		$this->assertSame( [ Scheduler::DETECT ], array_column( $GLOBALS['invoqk_as'], 'hook' ) );
		$reg = new \Invoqk\Sources\Registry( new \Invoqk\Tests\Sources\FakeAdapter( 'a', true, new \Invoqk\Sources\InvoiceFile( 5, 'application/xml', 'ubl' ) ) );
		Scheduler::handle( $id, 1, fn() => ( new \Invoqk\Detection\Detector( $reg ) )->run( $id ) );
	}

	public function test_sweep_detect_of_pre_activation_order_takes_no_free_slot(): void {
		$this->preActivationUndetected( 41 );
		$this->sweepAndDetect( 41 );
		$this->assertNotContains( Scheduler::VALIDATE, array_column( $GLOBALS['invoqk_as'], 'hook' ) );
	}

	public function test_sweep_detect_of_pre_activation_order_validates_on_pro(): void {
		$this->preActivationUndetected( 42 );
		add_filter( 'invoqk_is_pro', fn() => true );
		$this->sweepAndDetect( 42 );
		$this->assertContains( Scheduler::VALIDATE, array_column( $GLOBALS['invoqk_as'], 'hook' ) );
		$GLOBALS['invoqk_filters'] = [];
	}

	public function test_wpo_save_of_pre_activation_order_skips_validate_on_free(): void {
		$o = $this->preActivationUndetected( 43 );
		$GLOBALS['invoqk_actions'] = [];
		Scheduler::boot();
		do_action( 'wpo_wcpdf_save_document', new stdClass(), $o );
		$this->assertSame( [ Scheduler::DETECT ], array_column( $GLOBALS['invoqk_as'], 'hook' ) );
	}

	public function test_first_report_order_requeued_after_key_entered_despite_activation(): void {
		$GLOBALS['invoqk_options'] = [ 'invoqk_activated_at' => 1000, 'invoqk_first_report' => [ 'ids' => [ 44 ], 'startedAt' => 900 ] ];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_filters'] = [];
		$this->fileOrder( 44, [ 'status' => 'error', 'error' => [ 'code' => 'no_key' ] ] )->created = 500;
		$GLOBALS['invoqk_orders_result'] = [ 44 ];
		Scheduler::sweep();
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'k';
		Scheduler::sweep();
		$this->assertSame( [ [ 44, 1, false ] ], array_column( $GLOBALS['invoqk_as'], 'args' ) );
	}

	private const NET = 'remote_(get|post|request|head)|WP_Http|fsockopen|stream_socket_client';

	private function fileOrder( int $id, ?array $rec ): WC_Order {
		$o = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
		$o->update_meta_data( '_invoqk_source', 'a' );
		$o->update_meta_data( '_invoqk_file', [ 'reference' => 5, 'mime' => 'application/xml' ] );
		if ( $rec ) {
			$o->update_meta_data( '_invoqk_validation', $rec );
		}
		return $o;
	}

	public function test_sweep_skips_no_key_records_while_key_is_missing(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$this->fileOrder( 31, [ 'status' => 'error', 'error' => [ 'code' => 'no_key' ] ] );
		$GLOBALS['invoqk_orders_result'] = [ 31 ];
		Scheduler::sweep();
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'k';
		Scheduler::sweep();
		$this->assertSame( [ [ 31, 1, false ] ], array_column( $GLOBALS['invoqk_as'], 'args' ) );
	}

	public function test_validation_queuing_cannot_starve_detection(): void {
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => 'k' ];
		$GLOBALS['invoqk_orders']  = [];
		$ids = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->fileOrder( $i, null ); // pending validation
			$ids[] = $i;
		}
		$this->fileOrder( 500, null )->update_meta_data( '_invoqk_source', '' ); // never detected
		$ids[] = 500;
		$GLOBALS['invoqk_orders_result'] = $ids;
		Scheduler::sweep();
		$hooks = array_count_values( array_column( $GLOBALS['invoqk_as'], 'hook' ) );
		$this->assertSame( 100, $hooks[ Scheduler::VALIDATE ] );
		$this->assertSame( 1, $hooks[ Scheduler::DETECT ] );
	}

	public function test_key_change_requeues_unauthorized_and_wrong_site_checked_before_it(): void {
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => 'k', 'invoqk_key_changed_at' => 1000 ];
		$GLOBALS['invoqk_orders']  = [];
		$this->fileOrder( 41, [ 'status' => 'error', 'checkedAt' => 500, 'error' => [ 'code' => 'unauthorized' ] ] );
		$this->fileOrder( 42, [ 'status' => 'error', 'checkedAt' => 500, 'error' => [ 'code' => 'wrong_site' ] ] );
		$this->fileOrder( 43, [ 'status' => 'error', 'checkedAt' => 2000, 'error' => [ 'code' => 'unauthorized' ] ] );
		$GLOBALS['invoqk_orders_result'] = [ 41, 42, 43 ];
		Scheduler::sweep();
		$this->assertSame( [ 41, 42 ], array_column( array_column( $GLOBALS['invoqk_as'], 'args' ), 0 ) );
	}

	/** Remote-HTTP patterns forbidden in a file; only src/Validation/Client.php may call wp_remote_*. */
	private static function forbidden( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		if ( str_ends_with( $path, '/src/Validation/Limit.php' ) ) {
			return self::NET . '|curl_init'; // only the upgrade-URL literal is exempt
		}
		return str_ends_with( $path, '/src/Validation/Client.php' ) ? 'wp_safe_remote_|wp_remote_(get|request|head)|curl_init|WP_Http|fsockopen|stream_socket_client|http:\/\/' : self::NET . '|curl_init|https?:\/\/';
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
		$this->assertSame( 0, preg_match( '/' . self::forbidden( 'C:\\p\\src\\Validation\\Client.php' ) . '/', 'https://x' ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Validation/Client.php' ) . '/', 'http://x' ) );
		$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Validation/Client.php' ) . '/', 'wp_safe_remote_post( $u );' ) );
	}

	public function test_guard_flags_other_network_apis_outside_client(): void {
		foreach ( [ 'wp_safe_remote_post( $u );', 'new WP_Http();', 'fsockopen( $h );', 'stream_socket_client( $h );', 'wp_remote_head( $u );' ] as $code ) {
			$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Other/File.php' ) . '/', $code ), $code );
			$this->assertSame( 1, preg_match( '/' . self::forbidden( '/x/src/Validation/Limit.php' ) . '/', $code ), $code );
		}
	}

	public function test_guard_https_exemption_is_scoped_to_limit(): void {
		$f = fn( string $p, string $code ) => preg_match( '/' . self::forbidden( $p ) . '/', $code );
		$this->assertSame( 0, $f( '/x/src/Validation/Limit.php', 'https://invoqk.com' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Limit.php', 'wp_remote_get();' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Limit.php', 'curl_init();' ) );
		$this->assertSame( 0, $f( '/x/src/Validation/Client.php', 'https://x' ) );
		$this->assertSame( 1, $f( '/x/src/Other/Limit.php', 'https://x' ) );
		$this->assertSame( 1, $f( '/x/src/Validation/Mapper.php', 'http://x' ) );
	}

	public function test_sweep_skips_validation_for_not_required_orders(): void {
		$b2c          = new WC_Order( 21 );
		$b2c->company = '';
		$b2c->update_meta_data( '_invoqk_source', 'x' );
		$b2c->update_meta_data( '_invoqk_file', [ 'reference' => 5, 'mime' => 'application/xml', 'format' => 'ubl' ] );
		$GLOBALS['invoqk_orders']        = [ 21 => $b2c ];
		$GLOBALS['invoqk_orders_result'] = [ 21 ];
		Scheduler::sweep();
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_update_order_enqueues_only_when_classification_changed(): void {
		$o = new WC_Order( 31 );
		$GLOBALS['invoqk_orders'] = [ 31 => $o ];
		\Invoqk\Classification\Classifier::save( $o );
		Scheduler::reclassifyIfChanged( 31 );
		$this->assertSame( [], $GLOBALS['invoqk_as'], 'unchanged result: nothing queued' );
		$o->company = '';
		Scheduler::reclassifyIfChanged( 31 );
		Scheduler::reclassifyIfChanged( 31 );
		$this->assertCount( 1, $GLOBALS['invoqk_as'], 'changed: queued once, deduped' );
	}
}
