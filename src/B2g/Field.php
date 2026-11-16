<?php
namespace Invoqk\B2g;

use Invoqk\Classification\Classifier;
use Invoqk\Validation\Limit;

/**
 * Optional Leitweg-ID field at checkout (Pro). Mode option: off (default) | always | business.
 *
 * Classic checkout (WC_Checkout, since 3.0): filter `woocommerce_checkout_fields` (billing section,
 * key without "billing_" prefix so WC_Checkout::create_order does not also store it as `_billing_*` meta),
 * action `woocommerce_after_checkout_validation( $data, WP_Error $errors )`, action
 * `woocommerce_checkout_create_order( WC_Order $order, $data )` (order not saved yet, see Reference::set).
 * No JS: in business mode the field is shown on the page, but on the checkout POST it is only added
 * (so only validated and saved) when billing_company or a VAT ID is posted. VAT field names are
 * Classifier::vatKeys() (filter `invoqk_vat_id_meta_keys`) without the leading "_", as VAT plugins post them.
 *
 * Block checkout, checked against WooCommerce 9.0.0 and 9.9.5 source
 * (src/Blocks/Domain/Services/CheckoutFields.php, functions.php, StoreApi/Schemas/V1/CheckoutSchema.php):
 * - `woocommerce_register_additional_checkout_field( array )` exists since 8.9 (before: __experimental_...);
 *   it defers itself to `woocommerce_blocks_loaded`. We call it on `woocommerce_init`.
 * - id must be "namespace/name"; locations: contact | address | order ("additional" deprecated 8.9 → order);
 *   types: text | select | checkbox. Keys used: id, label, location, type, required, sanitize_callback,
 *   validate_callback. validate_callback( $value, $field ) returns true or a WP_Error (9.9 also treats false
 *   as invalid); the action `woocommerce_validate_additional_field( WP_Error, $key, $value )` exists since 8.7.
 *   There is no `woocommerce_blocks_validate_additional_field` hook.
 * - Stored meta: group prefix + id. location "order" → `_wc_other/invoqk/leitweg_id`; location "address" would
 *   give `_wc_billing/...` and `_wc_shipping/...` and show the field in both address forms, so we use "order".
 * - Conditional `hidden`/`required` rules (JSON schema, CheckoutFieldsSchema/Validation.php) exist only from
 *   9.8.0 (absent in 9.7.0); on 9.0 an array `hidden` is not understood. Minimum is 9.0, so the block field is
 *   shown whenever the mode is not off, and the settings page says so.
 */
final class Field {
	public const OPTION   = 'invoqk_leitweg_mode';
	public const KEY      = 'invoqk_leitweg_id';
	public const BLOCK_ID = 'invoqk/leitweg_id';
	private const MODES   = [ 'always', 'business' ];

	public static function boot(): void {
		if ( 'off' === self::mode() || ! Limit::isPro() ) {
			return;
		}
		add_filter( 'woocommerce_checkout_fields', [ self::class, 'fields' ] );
		add_action( 'woocommerce_after_checkout_validation', [ self::class, 'validate' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order', [ self::class, 'save' ], 10, 2 );
		add_action( 'woocommerce_init', [ self::class, 'registerBlock' ] );
	}

	public static function mode(): string {
		return self::sanitizeMode( get_option( self::OPTION ) );
	}

	public static function sanitizeMode( $v ): string {
		return in_array( $v, self::MODES, true ) ? $v : 'off';
	}

	public static function fields( $fields ) {
		$business = 'business' === self::mode();
		if ( $business && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! self::postedBusiness() ) {
			return $fields;
		}
		$fields['billing'][ self::KEY ] = [
			'type'        => 'text',
			'label'       => __( 'Leitweg-ID / Buyer reference', 'invoqk' ),
			'required'    => false,
			'class'       => [ 'form-row-wide', 'invoqk-leitweg' ],
			'priority'    => 35, // right after the company field
			'description' => $business ? __( 'Only for orders with a company name or VAT ID.', 'invoqk' ) : '',
		];
		return $fields;
	}

	/** Company name or VAT ID in the checkout POST. */
	private static function postedBusiness(): bool {
		$keys = array_merge( [ 'billing_company' ], array_map( static fn( $k ) => ltrim( $k, '_' ), Classifier::vatKeys() ) );
		foreach ( $keys as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout verifies the nonce; read-only decision
			if ( is_scalar( $_POST[ $k ] ?? null ) && '' !== sanitize_text_field( wp_unslash( (string) $_POST[ $k ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param \WP_Error $errors */
	public static function validate( $data, $errors ): void {
		$msg = self::error( (string) ( $data[ self::KEY ] ?? '' ) );
		if ( null !== $msg ) {
			$errors->add( 'invoqk_leitweg_id', $msg );
		}
	}

	public static function save( \WC_Order $order, $data ): void {
		if ( is_array( $data ) && array_key_exists( self::KEY, $data ) ) {
			Reference::set( $order, (string) $data[ self::KEY ] );
		}
	}

	public static function registerBlock(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try { // WooCommerce throws when it rejects a field; on woocommerce_init that would take the site down
			woocommerce_register_additional_checkout_field( [
				'id'                => self::BLOCK_ID,
				'label'             => __( 'Leitweg-ID / Buyer reference', 'invoqk' ),
				'location'          => 'order',
				'type'              => 'text',
				'required'          => false,
				'sanitize_callback' => static fn( $v ) => LeitwegId::normalise( (string) $v ),
				'validate_callback' => static function ( $v ) {
					$msg = self::error( (string) $v );
					return null === $msg ? true : new \WP_Error( 'invoqk_leitweg_id', $msg );
				},
			] );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'leitweg field registration failed: ' . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
	}

	private static function error( string $v ): ?string {
		$code = LeitwegId::check( $v );
		return null === $code ? null : LeitwegId::message( $code );
	}
}
