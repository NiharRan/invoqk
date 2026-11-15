<?php
namespace Invoqk\Sources;

/**
 * WP Overnight "PDF Invoices & Packing Slips for WooCommerce" (checked against 5.16.6).
 *
 * The free plugin builds UBL, PEPPOL BIS, CII, Factur-X and ZUGFeRD e-invoices on request
 * (Settings > EDI, off by default) and never stores them. So the invoice is "found" when EDI is
 * on and the order's invoice document already exists; the XML itself is built in memory by read().
 * Never call wcpdf_get_document( ..., true ): that creates and saves an invoice.
 *
 * Credit notes: UNVERIFIED. Checked the free 5.16.6 source (downloaded from wordpress.org, read only).
 * It has no credit-note document class (includes/Documents has only Invoice and PackingSlip) but
 * references the slug 'credit-note' in OrderDocument (refund handling) and in the EDI AbstractHandler
 * (credit note detection, CreditNote root element), so the slug 'credit-note' is consistent with the
 * Professional extension, which registers the document. Not checked: the Professional extension itself
 * (paid, not obtainable), so whether it builds EDI for credit notes via wpo_ips_edi_write_file() for a
 * refund-bound document is assumed. Without the extension wcpdf_get_document() returns false and
 * findCreditNotes() returns []. A credit note document is bound to the refund, not the parent order.
 * Reference convention: `wpo:credit-note:<refund id>`.
 */
final class WpOvernightAdapter implements SourceAdapter {
	private const CREDIT_NOTE_TYPE = 'credit-note';

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

	public function findCreditNotes( \WC_Order $order ): array {
		if ( ! function_exists( 'wcpdf_get_document' ) ) {
			return [];
		}
		$format = (string) wpo_ips_edi_get_current_format();
		$files  = [];
		foreach ( $order->get_refunds() as $refund ) {
			$doc = wcpdf_get_document( self::CREDIT_NOTE_TYPE, $refund, false );
			if ( $doc && $doc->exists() ) {
				$id           = (int) $refund->get_id();
				$files[ $id ] = new InvoiceFile( 'wpo:credit-note:' . $id, 'application/xml', self::FORMATS[ $format ] ?? 'unknown', true );
			}
		}
		return $files;
	}

	public function read( \WC_Order $order, InvoiceFile $file ): ?string {
		$target = $order;
		$type   = 'invoice';
		if ( preg_match( '/^wpo:credit-note:(\d+)$/', (string) $file->reference, $m ) ) {
			$type   = self::CREDIT_NOTE_TYPE;
			$target = null;
			foreach ( $order->get_refunds() as $refund ) {
				if ( (int) $refund->get_id() === (int) $m[1] ) {
					$target = $refund;
				}
			}
			if ( ! $target ) {
				return null;
			}
		}
		$doc = wcpdf_get_document( $type, $target, false );
		if ( ! $doc || ! $doc->exists() ) {
			return null;
		}
		$contents = wpo_ips_edi_write_file( $doc, false, true );
		return is_string( $contents ) && '' !== $contents ? $contents : null;
	}
}
