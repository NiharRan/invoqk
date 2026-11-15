<?php
namespace Invoqk\Archive;

final class Chain {
	/** validation_status and tombstone fields are deliberately not part of the hash. */
	public static function hash( array $r ): string {
		$money = fn( $k ) => isset( $r[ $k ] ) ? number_format( (float) $r[ $k ], 2, '.', '' ) : '';
		$f     = fn( $k ) => (string) ( $r[ $k ] ?? '' );
		return hash(
			'sha256',
			implode( '|', [ $f( 'prev_hash' ), $f( 'xml_hash' ), $f( 'pdf_hash' ), $f( 'order_id' ), $f( 'version' ), $f( 'invoice_number' ), $f( 'invoice_date' ), $money( 'gross' ), $money( 'vat' ), $f( 'sent_at' ) ] )
		);
	}
}
