<?php
namespace Invoqk\Fallback;

/** Gap-free invoice numbers for the fallback generator: one continuous counter, formatted by the seller's pattern. */
final class Numbers {
	public const COUNTER = 'invoqk_fallback_counter';
	public const LOCK    = 'invoqk_fb_num';

	public static function format( string $pattern, int $counter, int $year ): string {
		$pattern = str_replace( '{YYYY}', sprintf( '%04d', $year ), $pattern );
		return (string) preg_replace_callback(
			'/\{(0+1?)\}/',
			static fn( array $m ): string => str_pad( (string) $counter, strlen( $m[1] ), '0', STR_PAD_LEFT ),
			$pattern,
			1
		);
	}

	/** @throws \RuntimeException When another request holds the lock or the counter cannot be saved. */
	public static function next( ?int $year = null ): string {
		if ( ! Lock::acquire( self::LOCK ) ) {
			throw new \RuntimeException( 'Invoice number lock is held.' );
		}
		try {
			$counter = (int) get_option( self::COUNTER, 0 ) + 1;
			$number  = self::format( (string) Seller::get()['number_format'], $counter, $year ?? (int) wp_date( 'Y' ) );
			if ( ! update_option( self::COUNTER, $counter, false ) ) {
				throw new \RuntimeException( 'Invoice counter could not be saved.' );
			}
			return $number;
		} finally {
			Lock::release( self::LOCK );
		}
	}
}
