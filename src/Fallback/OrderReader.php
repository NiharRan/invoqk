<?php
namespace Invoqk\Fallback;

use Invoqk\B2g\Reference;
use Invoqk\Classification\Classifier;

/** Reads a WooCommerce order into an Invoice, or refuses with Unsupported when the fallback cannot represent it faithfully. */
final class OrderReader {
	/** @throws Unsupported */
	public static function read( \WC_Order $order, bool $nullsatz ): Invoice {
		if ( 'DE' !== strtoupper( trim( $order->get_billing_country() ) ) ) {
			throw new Unsupported( 'country' );
		}
		if ( 'EUR' !== $order->get_currency() ) {
			throw new Unsupported( 'currency' );
		}
		if ( [] !== $order->get_refunds() ) {
			throw new Unsupported( 'refund' );
		}
		$total = self::cents( $order->get_total() );
		if ( $total <= 0 ) {
			throw new Unsupported( 'total' );
		}
		$street = trim( $order->get_billing_address_1() );
		$post   = trim( $order->get_billing_postcode() );
		$city   = trim( $order->get_billing_city() );
		if ( '' === $street || '' === $post || '' === $city ) {
			throw new Unsupported( 'buyer_address' );
		}

		$lines = [];
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $item ) {
			$net  = (float) $item->get_total();
			$tax  = (float) $item->get_total_tax();
			$rate = 0.0 === $net ? ( 0.0 === $tax ? 0.0 : -1.0 ) : $tax / $net * 100;
			$line = match ( true ) {
				abs( $rate - 19 ) < 0.2 => 19,
				abs( $rate - 7 ) < 0.2  => 7,
				0.0 === $rate           => 0,
				default                 => throw new Unsupported( 'tax_rate' ),
			};
			if ( '' === trim( $item->get_name() ) ) {
				throw new Unsupported( 'line_name' );
			}
			$lines[] = new InvoiceLine( trim( $item->get_name() ), (string) $item->get_quantity(), self::cents( $item->get_total() ), $line );
		}
		// One source of truth for the grand total: the same figure the Builder prints.
		if ( abs( Builder::grandCents( $lines ) - $total ) > 1 ) {
			throw new Unsupported( 'totals' );
		}

		$date = $order->get_date_completed() ?? $order->get_date_paid() ?? $order->get_date_created();
		$company = trim( $order->get_billing_company() );
		return new Invoice(
			(string) $order->get_order_number(),
			wp_date( 'Y-m-d' ),
			$date ? $date->format( 'Y-m-d' ) : wp_date( 'Y-m-d' ),
			$lines,
			[
				'name'      => '' !== $company ? $company : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'company'   => $company,
				'street'    => $street,
				'postcode'  => $post,
				'city'      => $city,
				'country'   => 'DE',
				'email'     => trim( $order->get_billing_email() ),
				'vat_id'    => Classifier::vatId( $order ),
				'reference' => Reference::get( $order ),
			],
			$nullsatz
		);
	}

	// shortcut: float rounding, fine for WooCommerce's decimal strings; revisit for amounts beyond 2^53 cents.
	private static function cents( $v ): int {
		return (int) round( (float) $v * 100 );
	}
}
