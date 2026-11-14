<?php
namespace Invoqk\Sources;

/**
 * WP Overnight "PDF Invoices & Packing Slips for WooCommerce" (checked against 5.16.6).
 *
 * The free plugin builds UBL, PEPPOL BIS, CII, Factur-X and ZUGFeRD e-invoices on request
 * (Settings > EDI, off by default) and never stores them. So the invoice is "found" when EDI is
 * on and the order's invoice document already exists; the XML itself is built in memory by read().
 * Never call wcpdf_get_document( ..., true ): that creates and saves an invoice.
 */
final class WpOvernightAdapter implements SourceAdapter {
	private const FORMATS = [
		'ubl-2p1'        => 'ubl',
		'peppol-bis-3p0' => 'ubl',
		'cii-d16b'       => 'cii',
		'factur-x-1p0'   => 'facturx',
		'zugferd-1p0'    => 'zugferd',
		'zugferd-2p0'    => 'zugferd',
	];

	public function id(): string {
		return 'wp_overnight';
	}

	public function label(): string {
		return 'WP Overnight PDF Invoices';
	}

	public function isAvailable(): bool {
		return function_exists( 'wpo_ips_edi_is_available' ) && wpo_ips_edi_is_available();
	}

	public function find( \WC_Order $order ): ?InvoiceFile {
		$doc = wcpdf_get_document( 'invoice', $order, false );
		if ( ! $doc || ! $doc->exists() ) {
			return null;
		}
		$format = (string) wpo_ips_edi_get_current_format();
		return new InvoiceFile( 'wpo:invoice', 'application/xml', self::FORMATS[ $format ] ?? 'unknown', true );
	}

	public function read( \WC_Order $order, InvoiceFile $file ): ?string {
		$doc = wcpdf_get_document( 'invoice', $order, false );
		if ( ! $doc || ! $doc->exists() ) {
			return null;
		}
		$contents = wpo_ips_edi_write_file( $doc, false, true );
		return is_string( $contents ) && '' !== $contents ? $contents : null;
	}
}
