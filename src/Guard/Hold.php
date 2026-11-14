<?php
namespace Invoqk\Guard;

final class Hold {
	public const KEY   = '_invoqk_guard';
	public const FINAL = [ 'sent', 'skipped', 'released' ];
	private const STATES = [ 'waiting', 'held', 'sent', 'skipped', 'released' ];

	public static function get( \WC_Order $o ): ?array {
		$v = $o->get_meta( self::KEY );
		return is_array( $v ) && $v ? $v : null;
	}

	public static function set( \WC_Order $o, string $state, array $extra = [] ): void {
		if ( ! in_array( $state, self::STATES, true ) ) {
			return;
		}
		$old    = self::get( $o ) ?? [];
		$record = array_merge( $old, $extra, [ 'state' => $state ] );
		if ( ( $old['state'] ?? null ) !== $state || ! isset( $record['since'] ) ) {
			$record['since'] = time();
		}
		$o->update_meta_data( self::KEY, $record );
		$o->save_meta_data();

		$id   = $o->get_id();
		$held = array_values( array_diff( self::stored(), [ $id ] ) );
		if ( 'held' === $state ) {
			$held[] = $id;
		}
		update_option( 'invoqk_guard_held', $held, false );
	}

	public static function isFinal( \WC_Order $o ): bool {
		return in_array( self::get( $o )['state'] ?? '', self::FINAL, true );
	}

	/** @return int[] */
	public static function heldIds(): array {
		$ids   = self::stored();
		$clean = array_values( array_filter( $ids, static fn( $id ) => (bool) wc_get_order( $id ) ) );
		if ( $clean !== $ids ) {
			update_option( 'invoqk_guard_held', $clean, false );
		}
		return $clean;
	}

	public static function mode(): string {
		return self::sanitizeMode( get_option( 'invoqk_guard_mode' ) );
	}

	public static function sanitizeMode( $v ): string {
		return 'warn' === $v ? 'warn' : 'block';
	}

	/** @return int[] */
	private static function stored(): array {
		$v = get_option( 'invoqk_guard_held', [] );
		return is_array( $v ) ? array_values( array_unique( array_map( 'intval', $v ) ) ) : [];
	}
}
