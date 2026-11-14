<?php
namespace Invoqk\Validation;

final class Record {
	public const KEY = '_invoqk_validation';

	public static function get( \WC_Order $o ): ?array {
		$v = $o->get_meta( self::KEY );
		return is_array( $v ) && $v ? $v : null;
	}

	public static function put( \WC_Order $o, array $record ): void {
		$o->update_meta_data( self::KEY, $record );
		$o->save_meta_data();
		\Invoqk\Admin\Readiness::flush();
	}

	/** passed|failed|error|limit|pending|none */
	public static function status( \WC_Order $o ): string {
		$source = (string) $o->get_meta( '_invoqk_source' );
		if ( '' === $source || 'none' === $source ) {
			return 'none';
		}
		$r = self::get( $o );
		return $r ? (string) ( $r['status'] ?? 'error' ) : 'pending';
	}
}
