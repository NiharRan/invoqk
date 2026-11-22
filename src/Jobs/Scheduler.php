<?php
namespace Invoqk\Jobs;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Detector;
use Invoqk\Detection\Result;
use Invoqk\Onboarding\State;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Client;
use Invoqk\Validation\Job;
use Invoqk\Validation\Record;

final class Scheduler {
	public const GROUP  = 'invoqk';
	public const DETECT = 'invoqk_detect_order';
	public const SWEEP  = 'invoqk_sweep';
	public const VALIDATE = 'invoqk_validate_order';

	public static function boot(): void {
		add_action( 'woocommerce_order_status_changed', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( 'woocommerce_update_order', static fn( $id ) => self::reclassifyIfChanged( (int) $id ) );
		add_action( 'invoqk_schedule_detect', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( self::DETECT, static fn( $id, $attempt = 1 ) => self::handle( (int) $id, (int) $attempt ), 10, 2 );
		add_action( self::VALIDATE, static fn( $id, $attempt = 1, $force = false ) => Job::handle( (int) $id, (int) $attempt, (bool) $force ), 10, 3 );
		add_action( self::SWEEP, [ self::class, 'sweep' ] );
		// WP Overnight created or saved an invoice document: detect at once instead of waiting for a status change.
		add_action( 'wpo_wcpdf_save_document', static fn( $document, $order ) => $order instanceof \WC_Order && self::enqueueBoth( $order ), 10, 2 );
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

	/** Order saved by anyone: queue a detect job only when the classification inputs changed. */
	public static function reclassifyIfChanged( int $orderId ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			return;
		}
		$old = Classifier::stored( $order );
		$new = Classifier::classify( $order );
		if ( ! $old || $old['required'] !== $new['required'] || $old['reason'] !== $new['reason'] || ( $old['hint'] ?? null ) !== $new['hint'] ) {
			self::enqueue( $orderId );
		}
	}

	private static function enqueueBoth( \WC_Order $order ): void {
		self::enqueue( $order->get_id() );
		self::enqueueValidate( $order->get_id() );
	}

	public static function enqueueValidate( int $orderId, bool $force = false ): void {
		$args = [ $orderId, 1, $force ];
		if ( ! as_has_scheduled_action( self::VALIDATE, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::VALIDATE, $args, self::GROUP );
		}
	}

	/** Whether the sweep should queue validation for this order (it has a file). */
	private static function needsValidation( \WC_Order $order, string $status ): bool {
		if ( 'pending' === $status || 'limit' === $status ) { // 'limit': legacy records from the old free cap
			return true;
		}
		if ( 'error' !== $status ) {
			return false;
		}
		$rec  = Record::get( $order ) ?? [];
		$code = $rec['error']['code'] ?? '';
		if ( 'unreadable' === $code ) {
			return true;
		}
		if ( 'no_key' === $code ) {
			return '' !== Client::apiKey();
		}
		// 401/403 retry once, after the key was changed since the check.
		return in_array( $code, [ 'unauthorized', 'wrong_site' ], true ) && (int) ( $rec['checkedAt'] ?? 0 ) < (int) get_option( 'invoqk_key_changed_at', 0 );
	}

	/**
	 * Queue detection for recent never-detected orders and validation for ones that need it, each with its own
	 * cap of 100 per run so neither starves the other (5 pages scanned).
	 * Filters in PHP: the legacy (non-HPOS) order store silently ignores `meta_query`.
	 */
	public static function sweep(): void {
		$detect = 0;
		$valid  = 0;
		for ( $page = 1; $page <= 5 && ( $detect < 100 || $valid < 100 ); $page++ ) {
			$ids = wc_get_orders(
				[
					'type'         => 'shop_order',
					'limit'        => 100,
					'paged'        => $page,
					'return'       => 'ids',
					'date_created' => '>' . strtotime( '-30 days' ),
				]
			);
			if ( ! $ids ) {
				return;
			}
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				if ( '' === (string) $order->get_meta( '_invoqk_source' ) ) {
					if ( $detect < 100 ) {
						self::enqueue( (int) $id );
						++$detect;
					}
				} elseif ( $valid < 100 && $order->get_meta( Result::FILE ) && Classifier::isRequired( $order ) && self::needsValidation( $order, Record::status( $order ) ) ) {
					self::enqueueValidate( (int) $id );
					++$valid;
				}
			}
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
