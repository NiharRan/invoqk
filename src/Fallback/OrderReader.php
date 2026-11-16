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

		$lines   = [];
		$free    = []; // keys of zero-net, zero-tax product lines
		$taxed   = 0; // rate of the taxed lines, 0 when there are none
		$untaxed = false;
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $item ) {
			$cents = self::cents( $item->get_total() );
			self::cents( $item->get_total_tax() ); // guards a non-numeric tax value
			// A negative line would need a document-level allowance; refuse before a number is taken.
			if ( $cents < 0 ) {
				throw new Unsupported( 'discount' );
			}
			$net   = (float) $item->get_total();
			$tax   = (float) $item->get_total_tax();
			$product = $item instanceof \WC_Order_Item_Product;
			if ( 0.0 === $net && 0.0 === $tax ) {
				if ( ! $product ) {
					continue; // free shipping or a zero fee is not an invoice line
				}
				$free[] = count( $lines );
			}
			$rate = 0.0 === $net ? ( 0.0 === $tax ? 0.0 : -1.0 ) : $tax / $net * 100;
			$line = match ( true ) {
				abs( $rate - 19 ) < 0.2 => 19,
				abs( $rate - 7 ) < 0.2  => 7,
				0.0 === $rate           => 0,
				default                 => throw new Unsupported( 'tax_rate' ),
			};
			if ( 0.0 !== $net ) {
				if ( 0 !== $line ) {
					$taxed = $line;
				} else {
					$untaxed = true;
				}
			}
			if ( '' === trim( $item->get_name() ) ) {
				throw new Unsupported( 'line_name' );
			}
			$lines[] = new InvoiceLine( trim( $item->get_name() ), $product ? (string) $item->get_quantity() : '1', $cents, $line );
		}
		// Never guess tax: untaxed lines with a net amount are only accepted when the whole order is untaxed.
		if ( $taxed && $untaxed ) {
			throw new Unsupported( 'tax_rate' );
		}
		// A free product on a taxed order joins a taxed rate group (adds nothing to it) instead of forcing a 0 % group.
		foreach ( $taxed ? $free : [] as $k ) {
			$lines[ $k ] = new InvoiceLine( $lines[ $k ]->name, $lines[ $k ]->quantity, 0, $taxed );
		}
		// One source of truth for the grand total: the same figure the Builder prints.
		if ( abs( Builder::grandCents( $lines ) - $total ) > 1 ) {
			throw new Unsupported( 'totals' );
		}

		$date = $order->get_date_completed() ?? $order->get_date_paid() ?? $order->get_date_created();
		$company = trim( $order->get_billing_company() );
		$name    = '' !== $company ? $company : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			throw new Unsupported( 'buyer_address' );
		}
		return new Invoice(
			(string) $order->get_order_number(),
			wp_date( 'Y-m-d' ),
			$date ? $date->format( 'Y-m-d' ) : wp_date( 'Y-m-d' ),
			$lines,
			[
				'name'      => $name,
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

	// shortcut: float rounding; half-cent ties (e.g. 1.005) may round down, absorbed by the 1 cent reconcile tolerance.
	private static function cents( $v ): int {
		if ( ! is_numeric( $v ) ) {
			throw new Unsupported( 'totals' );
		}
		return (int) round( (float) $v * 100 );
	}
}
