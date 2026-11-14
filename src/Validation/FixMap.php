<?php
namespace Invoqk\Validation;

use Invoqk\Detection\Result;

/** Maps the API's `fix` keys to an admin location. Keys are a seed; the real E1-S9 list of 20 replaces them before release. */
final class FixMap {
	private const SOURCE_SETTINGS = [
		'wp_overnight'   => 'admin.php?page=wpo_wcpdf_options_page',
		'germanized_pro' => 'admin.php?page=wc-settings&tab=germanized',
	];

	public const KEYS = [
		'buyer_reference'           => [ self::class, 'orderField' ],
		'seller_vat_id'             => [ self::class, 'sourceSettings' ],
		'seller_contact'            => [ self::class, 'storeSettings' ],
		'seller_electronic_address' => [ self::class, 'storeSettings' ],
		'buyer_electronic_address'  => [ self::class, 'orderField' ],
		'payment_means'             => [ self::class, 'paymentSettings' ],
		'buyer_address'             => [ self::class, 'orderField' ],
	];

	public static function link( ?string $fixKey, \WC_Order $order ): ?array {
		if ( null === $fixKey || ! isset( self::KEYS[ $fixKey ] ) ) {
			return null;
		}
		return ( self::KEYS[ $fixKey ] )( $order );
	}

	public static function orderField( \WC_Order $o ): array {
		$url = method_exists( $o, 'get_edit_order_url' )
			? $o->get_edit_order_url()
			: admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' );
		return [ 'label' => __( 'Edit the order billing details', 'invoqk' ), 'url' => $url ];
	}

	public static function storeSettings( \WC_Order $o ): array {
		return [ 'label' => __( 'Open WooCommerce store settings', 'invoqk' ), 'url' => admin_url( 'admin.php?page=wc-settings&tab=general' ) ];
	}

	public static function paymentSettings( \WC_Order $o ): array {
		return [ 'label' => __( 'Open WooCommerce payment settings', 'invoqk' ), 'url' => admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ];
	}

	/** Falls back to the WooCommerce settings when the source plugin's page is not known. */
	public static function sourceSettings( \WC_Order $o ): array {
		$src = Result::fromOrder( $o )?->source ?? '';
		if ( isset( self::SOURCE_SETTINGS[ $src ] ) ) {
			return [ 'label' => __( 'Open the invoice plugin settings', 'invoqk' ), 'url' => admin_url( self::SOURCE_SETTINGS[ $src ] ) ];
		}
		return self::storeSettings( $o );
	}
}
