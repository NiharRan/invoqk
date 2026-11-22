<?php
namespace Invoqk\Onboarding;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Detector;
use Invoqk\Detection\Result;
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Record;

/** One-off onboarding scan: detect recent orders, keep up to 20 required ones with a file, validate them. */
final class FirstReport {
	public const HOOK = 'invoqk_first_report';
	private const KEEP = 20;
	private const SCAN = 200;

	public static function boot(): void {
		add_action( self::HOOK, static fn() => self::run() );
	}

	public static function start(): bool {
		// A finished report stays; an empty one (no B2B orders existed then) may be run again.
		if ( 'running' === State::get() || ( State::firstReport()['ids'] ?? [] ) ) {
			return false;
		}
		update_option( 'invoqk_first_report', [ 'ids' => [], 'startedAt' => time() ], false );
		State::set( 'running' );
		as_enqueue_async_action( self::HOOK, [], Scheduler::GROUP );
		return true;
	}

	public static function run( ?Registry $r = null ): void {
		$d     = new Detector( $r ?? Registry::default() );
		$keep  = [];
		$start = State::firstReport()['startedAt'] ?? time();
		try {
			$ids = wc_get_orders( [ 'type' => 'shop_order', 'limit' => self::SCAN, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ] );
			foreach ( $ids as $id ) {
				$d->run( (int) $id, false );
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order && Classifier::isRequired( $order ) && Result::fromOrder( $order )?->file ) {
					$keep[] = (int) $id;
					if ( count( $keep ) >= self::KEEP ) {
						break;
					}
				}
			}
			update_option( 'invoqk_first_report', [ 'ids' => $keep, 'startedAt' => $start ], false );
			foreach ( $keep as $id ) {
				Scheduler::enqueueValidate( $id );
			}
		} finally {
			State::set( 'done' );
		}
	}

	/** Runs one waiting Invoqk job. Local and low-traffic sites may not trigger Action Scheduler for minutes; the open progress page does it instead. */
	public static function nudge(): void {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}
		$ids = \ActionScheduler::store()->query_actions( [ 'group' => Scheduler::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object(), 'date_compare' => '<=', 'per_page' => 1, 'orderby' => 'date', 'order' => 'ASC' ] );
		if ( $ids ) {
			\ActionScheduler::runner()->process_action( (int) $ids[0], 'Invoqk progress page' );
		}
	}

	/** @return array{total:int,done:int,state:string} */
	public static function status(): array {
		$ids  = State::firstReport()['ids'] ?? [];
		$done = 0;
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o instanceof \WC_Order && 'pending' !== Record::status( $o ) ) {
				++$done;
			}
		}
		return [ 'total' => count( $ids ), 'done' => $done, 'state' => State::get() ];
	}
}
