<?php
namespace Invoqk\Fallback;

/** Gap-free invoice numbers for the fallback generator: one continuous counter, formatted by the seller's pattern. */
final class Numbers {
	public const COUNTER = 'invoqk_fallback_counter';
	public const LOCK    = 'invoqk_fallback_lock';
	private const STALE  = 30;

	public static function format( string $pattern, int $counter, int $year ): string {
		$pattern = str_replace( '{YYYY}', sprintf( '%04d', $year ), $pattern );
		return (string) preg_replace_callback(
			'/\{(0+1?)\}/',
			static fn( array $m ): string => str_pad( (string) $counter, strlen( $m[1] ), '0', STR_PAD_LEFT ),
			$pattern,
			1
		);
	}

	/** @throws \RuntimeException When another request holds the lock. */
	public static function next( ?int $year = null ): string {
		// shortcut: option lock, not a DB transaction; upgrade to a dedicated table if two shops share a database
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK, 0 ) <= self::STALE ) {
				throw new \RuntimeException( 'Invoice number lock is held.' );
			}
			delete_option( self::LOCK );
			if ( ! add_option( self::LOCK, time(), '', false ) ) {
				throw new \RuntimeException( 'Invoice number lock is held.' );
			}
		}
		try {
			$counter = (int) get_option( self::COUNTER, 0 ) + 1;
			update_option( self::COUNTER, $counter, false );
			return self::format( (string) Seller::get()['number_format'], $counter, $year ?? (int) gmdate( 'Y' ) );
		} finally {
			delete_option( self::LOCK );
		}
	}
}
