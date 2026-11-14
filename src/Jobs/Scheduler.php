<?php
namespace Invoqk\Jobs;

use Invoqk\Detection\Detector;
use Invoqk\Sources\Registry;

final class Scheduler {
	public const GROUP  = 'invoqk';
	public const DETECT = 'invoqk_detect_order';
	public const SWEEP  = 'invoqk_sweep';

	public static function boot(): void {
		add_action( 'woocommerce_order_status_changed', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( 'invoqk_schedule_detect', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( self::DETECT, static fn( $id, $attempt = 1 ) => self::handle( (int) $id, (int) $attempt ), 10, 2 );
		add_action( self::SWEEP, [ self::class, 'sweep' ] );
		// WP Overnight created or saved an invoice document: detect at once instead of waiting for a status change.
		add_action( 'wpo_wcpdf_save_document', static fn( $document, $order ) => $order instanceof \WC_Order && self::enqueue( $order->get_id() ), 10, 2 );
		add_action( 'init', [ self::class, 'ensureSweep' ] );
	}

	/** Schedules the hourly sweep once per plugin version; later requests only read an autoloaded option. */
	public static function ensureSweep(): void {
		if ( INVOQK_VERSION === get_option( 'invoqk_sweep_scheduled' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::SWEEP, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, self::SWEEP, [], self::GROUP );
		}
		update_option( 'invoqk_sweep_scheduled', INVOQK_VERSION );
	}

	public static function deactivate(): void {
		delete_option( 'invoqk_sweep_scheduled' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::GROUP );
		}
	}

	/** Seconds to wait before retry number $attempt (1-based), null = give up. */
	public static function backoff( int $attempt ): ?int {
		return [ 1 => 60, 2 => 300, 3 => 1800 ][ $attempt ] ?? null;
	}

	public static function enqueue( int $orderId ): void {
		if ( ! as_has_scheduled_action( self::DETECT, [ $orderId ], self::GROUP ) ) {
			as_enqueue_async_action( self::DETECT, [ $orderId ], self::GROUP );
		}
	}

	/** Queue detection for recent orders that were never checked. */
	public static function sweep(): void {
		$ids = wc_get_orders(
			[
				'type'         => 'shop_order',
				'limit'        => 100,
				'return'       => 'ids',
				'date_created' => '>' . strtotime( '-30 days' ),
				'meta_query'   => [ [ 'key' => '_invoqk_source', 'compare' => 'NOT EXISTS' ] ],
			]
		);
		foreach ( $ids as $id ) {
			self::enqueue( (int) $id );
		}
	}

	public static function handle( int $orderId, int $attempt = 1, ?callable $run = null ): void {
		$run ??= static fn() => ( new Detector( Registry::default() ) )->run( $orderId );
		try {
			$run();
		} catch ( \Throwable $e ) {
			$delay = self::backoff( $attempt );
			wc_get_logger()->error( "detect #{$orderId} attempt {$attempt}: " . $e->getMessage(), [ 'source' => 'invoqk' ] );
			if ( null !== $delay ) {
				as_schedule_single_action( time() + $delay, self::DETECT, [ $orderId, $attempt + 1 ], self::GROUP );
			}
		}
	}
}
