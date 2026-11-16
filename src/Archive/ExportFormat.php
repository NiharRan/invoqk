<?php
namespace Invoqk\Archive;

/** Pure helpers for the Steuerberater export: CSV lines and ZIP file names. */
final class ExportFormat {
	public const HEADER = [ 'Rechnungsnr', 'Datum', 'Kunde', 'Netto', 'USt', 'Brutto', 'Währung', 'Validierungsstatus', 'Version', 'Erneut gesendet', 'Art', 'Bezug auf Rechnungsnr' ];

	public static function header(): string {
		return "\xEF\xBB\xBF" . implode( ';', self::HEADER ) . "\r\n";
	}

	public static function line( array $row, string $parentNumber = '' ): string {
		$customer = self::formula( (string) ( $row['customer'] ?? '' ) );
		$money = static fn( $v ): string => null === $v || '' === $v ? '' : number_format( (float) $v, 2, ',', '' );
		$date  = self::date( $row );
		$cn    = 'credit_note' === ( $row['kind'] ?? 'invoice' );
		return self::csv( [
			(string) ( $row['invoice_number'] ?? '' ),
			'' === $date ? '' : gmdate( 'd.m.Y', strtotime( $date . ' UTC' ) ),
			$customer,
			$money( $row['net'] ?? null ),
			$money( $row['vat'] ?? null ),
			$money( $row['gross'] ?? null ),
			(string) ( $row['currency'] ?? '' ),
			(string) ( $row['validation_status'] ?? '' ),
			(string) (int) ( $row['version'] ?? 1 ),
			! $cn && (int) ( $row['version'] ?? 1 ) > 1 ? 'ja' : '',
			$cn ? 'Gutschrift' : 'Rechnung',
			$cn ? self::formula( $parentNumber ) : '',
		] );
	}

	/** Neutralise spreadsheet formulas. */
	private static function formula( string $v ): string {
		return '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
	}

	public static function date( array $row ): string {
		if ( ! empty( $row['invoice_date'] ) ) {
			return (string) $row['invoice_date'];
		}
		return ! empty( $row['sent_at'] ) ? gmdate( 'Y-m-d', (int) $row['sent_at'] ) : '';
	}

	public static function fileBase( array $row, array &$used ): string {
		$number = (string) ( $row['invoice_number'] ?? '' );
		$v      = (int) ( $row['version'] ?? 1 );
		$base   = ( '' !== $number ? sanitize_file_name( $number ) : 'order-' . (int) $row['order_id'] ) . '_v' . $v;
		if ( isset( $used[ strtolower( $base ) ] ) ) {
			$base .= '-' . (int) $row['id'];
		}
		$used[ strtolower( $base ) ] = true;
		return $base;
	}

	private static function csv( array $cells ): string {
		$h = fopen( 'php://temp', 'r+' );
		fputcsv( $h, $cells, ';', '"', '', "\r\n" );
		rewind( $h );
		$s = (string) stream_get_contents( $h );
		fclose( $h );
		return $s;
	}
}
