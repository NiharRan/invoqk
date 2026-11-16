<?php
namespace Invoqk\CreditNote;

use Invoqk\Admin\Badge;
use Invoqk\Archive\Archive;
use Invoqk\Validation\Limit;

/** Order meta box lines for the credit notes stored on an order. */
final class Box {
	public static function html( \WC_Order $o ): string {
		$all = Store::get( $o );
		if ( ! $all || ! Limit::isPro() ) {
			return '';
		}
		$index  = Archive::index();
		$latest = $index->latestForOrder( $o->get_id() )['invoice_number'] ?? '';
		$out    = '';
		foreach ( $all as $refundId => $e ) {
			$row   = ! empty( $e['archived'] ) ? $index->find( (int) $e['archived'] ) : null;
			$pid    = (int) ( $row['parent_id'] ?? 0 );
			$parent = ( $pid ? ( $index->find( $pid )['invoice_number'] ?? null ) : null ) ?? $latest; // the version it was archived against, else the latest
			$line   = esc_html( sprintf(
				/* translators: 1: refund id, 2: credit note number */
				__( 'Refund #%1$d: credit note %2$s', 'invoqk' ),
				(int) $refundId,
				(string) ( $row['invoice_number'] ?? '-' )
			) ) . ' ' . Badge::html( (string) ( $e['validation']['status'] ?? '' ) );
			if ( '' !== (string) $parent ) {
				/* translators: %s: invoice number */
				$line .= ' ' . esc_html( sprintf( __( 'refers to invoice %s', 'invoqk' ), (string) $parent ) );
			}
			$out .= '<p>' . $line . '</p>';
		}
		return $out;
	}
}
