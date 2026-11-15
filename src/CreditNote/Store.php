<?php
namespace Invoqk\CreditNote;

use Invoqk\Admin\Readiness;

/** Per-refund credit-note entries on the parent order. */
final class Store {
	public const META = '_invoqk_credit_notes';

	/** @return array<int, array{source: string, file: array{reference: int|string, mime: string, format: string, onDemand: bool}, detectedAt: int, validation: ?array, archived: ?int}> */
	public static function get( \WC_Order $o ): array {
		$m = $o->get_meta( self::META );
		return is_array( $m ) ? $m : [];
	}

	public static function put( \WC_Order $o, int $refundId, array $entry ): void {
		$all              = self::get( $o );
		$all[ $refundId ] = $entry;
		$o->update_meta_data( self::META, $all );
		$o->save_meta_data(); // not save(): that fires woocommerce_update_order and order.updated webhooks
		Readiness::flush();
	}
}
