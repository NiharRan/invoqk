<?php
namespace Invoqk\CreditNote;

use Invoqk\Jobs\Scheduler;
use Invoqk\Refund\Open;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Client;
use Invoqk\Validation\Limit;

/** Finds credit notes for refunded orders (Pro) and queues their validation. */
final class Detect {
	public const HOOK     = 'invoqk_detect_credit_notes';
	public const VALIDATE = 'invoqk_validate_credit_note';
	/** Order IDs with a credit note not archived yet, revisited by the sweep. */
	public const PENDING = 'invoqk_credit_note_pending_orders';
	private const SWEEP_CAP = 100;

	public static function boot(): void {
		add_action( 'woocommerce_order_refunded', static fn( $orderId ) => self::enqueue( (int) $orderId ), 20, 2 );
		add_action( 'invoqk_schedule_detect', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( Scheduler::SWEEP, [ self::class, 'sweep' ] );
		add_action( self::HOOK, static fn( $id ) => self::handle( (int) $id ) );
		add_action( self::VALIDATE, static fn( $orderId, $refundId, $attempt = 1 ) => \Invoqk\Validation\CreditNoteJob::handle( (int) $orderId, (int) $refundId, (int) $attempt ), 10, 3 );
	}

	/** Re-detects orders with open refunds or unfinished credit notes. Missing or trashed orders are not queued. */
	public static function sweep(): void {
		if ( ! Limit::isPro() ) {
			return;
		}
		$pending = self::pending();
		$live    = array_values( array_filter( $pending, static fn( $id ) => wc_get_order( $id ) instanceof \WC_Order ) );
		if ( $live !== $pending ) {
			update_option( self::PENDING, $live, false );
		}
		$live = array_filter( $live, static fn( $id ) => 'trash' !== wc_get_order( $id )->get_status() );
		// shortcut: a fixed cap, no rotation; more than 100 stuck orders would starve the rest, add a cursor if that is seen.
		foreach ( array_slice( array_values( array_unique( array_merge( Open::reconcile(), $live ) ) ), 0, self::SWEEP_CAP ) as $id ) {
			self::enqueue( $id );
		}
	}

	/** @return list<int> */
	public static function pending(): array {
		$l = get_option( self::PENDING, [] );
		return is_array( $l ) ? array_values( array_map( 'intval', $l ) ) : [];
	}

	private static function setPending( int $orderId, bool $on ): void {
		$l = self::pending();
		if ( $on === in_array( $orderId, $l, true ) ) {
			return;
		}
		update_option( self::PENDING, $on ? [ ...$l, $orderId ] : array_values( array_diff( $l, [ $orderId ] ) ), false );
	}

	/** Whether an unarchived entry should be validated again now (auth and key errors wait for a key change, as invoices do). */
	private static function retryable( ?array $v ): bool {
		$code = 'error' === ( $v['status'] ?? '' ) ? ( $v['error']['code'] ?? '' ) : '';
		if ( 'no_key' === $code ) {
			return '' !== Client::apiKey();
		}
		if ( in_array( $code, [ 'unauthorized', 'wrong_site' ], true ) ) {
			return (int) ( $v['checkedAt'] ?? 0 ) < (int) get_option( 'invoqk_key_changed_at', 0 );
		}
		return true;
	}

	/** Any attempt (1 to 4, see Mapper::retryDelay) already scheduled: a backoff retry is not doubled. */
	private static function queued( int $orderId, int $refundId ): bool {
		foreach ( [ 1, 2, 3, 4 ] as $a ) {
			if ( as_has_scheduled_action( self::VALIDATE, [ $orderId, $refundId, $a ], Scheduler::GROUP ) ) {
				return true;
			}
		}
		return false;
	}

	public static function enqueue( int $orderId ): void {
		if ( ! Limit::isPro() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, [ $orderId ], Scheduler::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, [ $orderId ], Scheduler::GROUP );
		}
	}

	public static function handle( int $orderId, ?Registry $registry = null ): void {
		if ( ! Limit::isPro() ) {
			return;
		}
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			return;
		}
		$hit = ( $registry ?? Registry::default() )->creditNotes( $order );
		if ( ! $hit ) {
			return;
		}
		$stored = Store::get( $order );
		foreach ( $hit['files'] as $refundId => $f ) {
			$refundId = (int) $refundId;
			$file     = [ 'reference' => $f->reference, 'mime' => $f->mime, 'format' => $f->format, 'onDemand' => $f->onDemand ];
			$old      = $stored[ $refundId ] ?? null;
			$changed  = ! $old || ( $old['file']['reference'] ?? null ) !== $f->reference;
			$entry    = [
				'source'     => $hit['adapter']->id(),
				'file'       => $file,
				'detectedAt' => time(),
				'validation' => $changed ? null : ( $old['validation'] ?? null ),
				'archived'   => $changed ? null : ( $old['archived'] ?? null ),
			];
			Store::put( $order, $refundId, $entry );
			Open::clear( $order, $refundId );
			// Unarchived (new, lost job, error, or archive failed): validate again; the job re-fires archiving on an unchanged hash.
			if ( empty( $entry['archived'] ) && self::retryable( $entry['validation'] ) && ! self::queued( $orderId, $refundId ) ) {
				as_enqueue_async_action( self::VALIDATE, [ $orderId, $refundId, 1 ], Scheduler::GROUP );
			}
		}
		self::setPending( $orderId, (bool) array_filter( Store::get( $order ), static fn( $e ) => empty( $e['archived'] ) ) );
	}
}
