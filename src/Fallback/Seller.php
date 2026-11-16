<?php
namespace Invoqk\Fallback;

/** Seller data for the fallback e-invoice generator (Pro). Stored as one option. */
final class Seller {
	public const OPTION  = 'invoqk_fallback_seller';
	public const DEFAULT_FORMAT = 'RE-{YYYY}-{00001}';
	private const TEXT   = [ 'name', 'street', 'postcode', 'city', 'tax_number', 'contact_name', 'contact_phone', 'terms', 'bic' ];
	private const KEYS   = [ 'name', 'street', 'postcode', 'city', 'vat_id', 'tax_number', 'contact_name', 'contact_phone', 'contact_email', 'iban', 'bic', 'terms', 'due_days', 'number_format', 'profile', 'nullsatz' ];
	private const NEEDED = [ 'name', 'street', 'postcode', 'city', 'vat_id', 'contact_name', 'contact_phone', 'contact_email', 'iban' ];

	/** Keys that must be filled in for an invoice to be generated. */
	public static function needed(): array {
		return self::NEEDED;
	}

	public static function get(): array {
		$saved    = get_option( self::OPTION, [] );
		$defaults = array_fill_keys( self::KEYS, '' );
		$defaults = [ 'profile' => 'xrechnung', 'due_days' => 14, 'number_format' => self::DEFAULT_FORMAT, 'nullsatz' => false ] + $defaults;
		return array_intersect_key( ( is_array( $saved ) ? $saved : [] ) + $defaults, $defaults );
	}

	/** Values found in WooCommerce for keys still empty in $saved; only suggestions, never saved by themselves. */
	public static function suggest( array $saved ): array {
		$bacs  = get_option( 'woocommerce_bacs_accounts' );
		$bank  = is_array( $bacs ) && is_array( reset( $bacs ) ) ? reset( $bacs ) : [];
		$found = [
			'name'          => (string) get_option( 'blogname' ),
			'street'        => (string) get_option( 'woocommerce_store_address' ),
			'postcode'      => (string) get_option( 'woocommerce_store_postcode' ),
			'city'          => (string) get_option( 'woocommerce_store_city' ),
			'contact_email' => (string) get_option( 'admin_email' ),
			'iban'          => (string) ( $bank['iban'] ?? '' ),
			'bic'           => (string) ( $bank['bic'] ?? '' ),
		];
		$found['iban'] = strtoupper( preg_replace( '/\s+/', '', $found['iban'] ) );
		return array_filter( array_diff_key( $found, array_filter( $saved, 'strlen' ) ), 'strlen' );
	}

	/** Required keys that are empty or malformed. A tax number alone satisfies the vat_id requirement. */
	public static function missing( ?array $s = null ): array {
		$s ??= self::get();
		$bad = [];
		foreach ( self::NEEDED as $k ) {
			$v  = (string) $s[ $k ];
			$ok = '' !== $v;
			if ( 'vat_id' === $k ) {
				$ok = '' !== (string) $s['tax_number'] || ( '' !== $v && 1 === preg_match( '/^[A-Z]{2}[A-Z0-9]{8,12}$/', $v ) );
			} elseif ( 'iban' === $k ) {
				$ok = 1 === preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v );
			} elseif ( 'contact_email' === $k ) {
				$ok = false !== filter_var( $v, FILTER_VALIDATE_EMAIL );
			}
			if ( ! $ok ) {
				$bad[] = $k;
			}
		}
		return $bad;
	}

	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$out = [];
		foreach ( self::TEXT as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $raw[ $k ] ?? '' ) );
		}
		$out['vat_id']        = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) ( $raw['vat_id'] ?? '' ) ) ) );
		$out['iban']          = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) ( $raw['iban'] ?? '' ) ) ) );
		$out['contact_email'] = sanitize_email( (string) ( $raw['contact_email'] ?? '' ) );
		$out['due_days']      = max( 0, min( 365, (int) ( $raw['due_days'] ?? 14 ) ) );
		$fmt                  = sanitize_text_field( (string) ( $raw['number_format'] ?? '' ) );
		$out['number_format'] = 1 === preg_match( '/\{0+1?\}/', $fmt ) ? $fmt : self::DEFAULT_FORMAT;
		$out['profile']       = 'en16931' === ( $raw['profile'] ?? '' ) ? 'en16931' : 'xrechnung';
		$out['nullsatz']      = ! empty( $raw['nullsatz'] );
		return $out;
	}

	public static function label( string $key ): string {
		$l = [
			'name'          => __( 'Company name', 'invoqk' ),
			'street'        => __( 'Street and number', 'invoqk' ),
			'postcode'      => __( 'Postcode', 'invoqk' ),
			'city'          => __( 'City', 'invoqk' ),
			'vat_id'        => __( 'VAT ID', 'invoqk' ),
			'tax_number'    => __( 'Tax number', 'invoqk' ),
			'contact_name'  => __( 'Contact person', 'invoqk' ),
			'contact_phone' => __( 'Contact phone', 'invoqk' ),
			'contact_email' => __( 'Contact email', 'invoqk' ),
			'iban'          => __( 'IBAN', 'invoqk' ),
			'bic'           => __( 'BIC', 'invoqk' ),
			'terms'         => __( 'Payment terms text', 'invoqk' ),
			'due_days'      => __( 'Payment due (days)', 'invoqk' ),
			'number_format' => __( 'Invoice number pattern', 'invoqk' ),
			'profile'       => __( 'E-invoice profile', 'invoqk' ),
			'nullsatz'      => __( '0 % lines are zero-rated (Nullsatz), not tax-exempt', 'invoqk' ),
		];
		return $l[ $key ] ?? $key;
	}
}
