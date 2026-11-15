<?php
namespace Invoqk\CreditNote;

use Invoqk\Jobs\Scheduler;
use Invoqk\Refund\Open;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Limit;

/** Finds credit notes for refunded orders (Pro) and queues their validation. */
final class Detect {
	public const HOOK     = 'invoqk_detect_credit_notes';
	public const VALIDATE = 'invoqk_validate_credit_note';

	public static function boot(): void {
		add_action( 'woocommerce_order_refunded', static fn( $orderId ) => self::enqueue( (int) $orderId ), 20, 2 );
		add_action( 'invoqk_schedule_detect', static fn( $id ) => self::enqueue( (int) $id ) );
		add_action( Scheduler::SWEEP, static function () {
			foreach ( Open::orders() as $id ) {
				self::enqueue( $id );
			}
		} );
		add_action( self::HOOK, static fn( $id ) => self::handle( (int) $id ) );
		add_action( self::VALIDATE, static fn( $orderId, $refundId, $attempt = 1 ) => \Invoqk\Validation\CreditNoteJob::handle( (int) $orderId, (int) $refundId, (int) $attempt ), 10, 3 );
	}

	public static function enqueue( int $orderId ): void {
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
			Store::put(
				$order,
				$refundId,
				[
					'source'     => $hit['adapter']->id(),
					'file'       => $file,
					'detectedAt' => time(),
					'validation' => $changed ? null : ( $old['validation'] ?? null ),
					'archived'   => $changed ? null : ( $old['archived'] ?? null ),
				]
			);
			Open::clear( $order, $refundId );
			$args = [ $orderId, $refundId, 1 ];
			if ( $changed && ! as_has_scheduled_action( self::VALIDATE, $args, Scheduler::GROUP ) ) {
				as_enqueue_async_action( self::VALIDATE, $args, Scheduler::GROUP );
			}
		}
	}
}
