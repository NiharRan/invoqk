<?php
namespace Invoqk\Refund;

use Invoqk\Classification\Classifier;
use Invoqk\Validation\Limit;

/** Tracks refunds on e-invoice-required orders that still lack a credit note (Pro). */
final class Open {
	public const META  = '_invoqk_refund_open';
	public const INDEX = 'invoqk_refund_open_orders';

	public static function boot(): void {
		add_action( 'woocommerce_order_refunded', [ self::class, 'onRefunded' ], 10, 2 );
		add_action( 'woocommerce_refund_deleted', [ self::class, 'onDeleted' ], 10, 2 );
	}

	public static function onRefunded( int $orderId, int $refundId ): void {
		if ( ! Limit::isPro() ) {
			return;
		}
		$o = wc_get_order( $orderId );
		if ( ! $o instanceof \WC_Order || ! apply_filters( 'invoqk_order_requires_einvoice', Classifier::isRequired( $o ), $o ) ) {
			return;
		}
		$ids = self::ids( $o );
		if ( ! in_array( $refundId, $ids, true ) ) {
			$ids[] = $refundId;
			$o->update_meta_data( self::META, $ids );
			$o->save_meta_data();
		}
		$list = self::orders();
		if ( ! in_array( $orderId, $list, true ) ) {
			$list[] = $orderId;
			update_option( self::INDEX, $list, false );
		}
	}

	public static function onDeleted( int $refundId, int $orderId ): void {
		$o = wc_get_order( $orderId );
		if ( $o instanceof \WC_Order ) {
			self::clear( $o, $refundId );
		}
	}

	/** @return list<int> */
	public static function ids( \WC_Order $o ): array {
		$m = $o->get_meta( self::META );
		return is_array( $m ) ? array_values( array_map( 'intval', $m ) ) : [];
	}

	public static function clear( \WC_Order $o, int $refundId ): void {
		$ids = array_values( array_diff( self::ids( $o ), [ $refundId ] ) );
		if ( $ids ) {
			$o->update_meta_data( self::META, $ids );
		} else {
			$o->delete_meta_data( self::META );
		}
		$o->save_meta_data();
		if ( ! $ids ) {
			update_option( self::INDEX, array_values( array_diff( self::orders(), [ $o->get_id() ] ) ), false );
		}
	}

	/**
	 * Drops index entries whose order is gone or trashed and flagged refunds the order no longer has, so a flag
	 * can always be cleared. Returns the live order IDs. Called from the hourly sweep (Pro).
	 *
	 * @return list<int>
	 */
	public static function reconcile(): array {
		$list = self::orders();
		$keep = [];
		foreach ( $list as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o instanceof \WC_Order || 'trash' === $o->get_status() ) {
				continue;
			}
			$ids   = self::ids( $o );
			$valid = array_values( array_intersect( $ids, array_map( static fn( $r ) => (int) $r->get_id(), $o->get_refunds() ) ) );
			if ( $valid !== $ids ) {
				$valid ? $o->update_meta_data( self::META, $valid ) : $o->delete_meta_data( self::META );
				$o->save_meta_data();
			}
			if ( $valid ) {
				$keep[] = $id;
			}
		}
		if ( $keep !== $list ) {
			update_option( self::INDEX, $keep, false );
		}
		return $keep;
	}

	/** @return list<int> */
	public static function orders(): array {
		$l = get_option( self::INDEX, [] );
		return is_array( $l ) ? array_values( array_map( 'intval', $l ) ) : [];
	}

	public static function count(): int {
		return count( self::orders() );
	}
}
