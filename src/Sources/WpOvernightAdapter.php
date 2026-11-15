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
 *
 * Buyer reference (BT-10), verified in the free 5.16.6 source (wordpress.org zip, read only):
 * - UBL 2.1 and PEPPOL BIS 3.0 have a `buyer_reference` element (BuyerReferenceHandler, `cbc:BuyerReference`),
 *   disabled by default in both structures; when enabled its value is the order ID.
 * - Filter `wpo_ips_edi_document_structure` ( $structure, \WPO\IPS\EDI\Document $doc ) enables it,
 *   filter `wpo_ips_edi_ubl_buyer_reference` ( $element, $data, $options, $handler ) sets its value. Both are
 *   per document: `$doc->order` / `$handler->document->order` is the order, or the refund for a credit note.
 * - CII D16B, Factur-X and ZUGFeRD write no BuyerReference at all, so those formats get the notice instead.
 * boot() applies both filters only when the order (the parent order for a refund) has a stored reference,
 * and returns every other value untouched.
 */
final class WpOvernightAdapter implements SourceAdapter, BuyerReferenceAware {
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
		return new InvoiceFile( 'wpo:invoice', 'application/xml', self::format(), true );
	}

	/** Invoqk format of WP Overnight's current EDI setting. */
	private static function format(): string {
		$f = function_exists( 'wpo_ips_edi_get_current_format' ) ? (string) wpo_ips_edi_get_current_format() : '';
		return self::FORMATS[ $f ] ?? 'unknown';
	}

	public function supportsBuyerReference(): bool {
		return 'ubl' === self::format();
	}

	/** Wires the stored buyer reference into WP Overnight's UBL output. Called once from Plugin::boot(). */
	public static function boot(): void {
		add_filter( 'wpo_ips_edi_document_structure', static function ( $structure, $doc = null ) {
			if ( is_array( $structure ) && isset( $structure['buyer_reference'] ) && '' !== self::reference( $doc ) ) {
				$structure['buyer_reference']['enabled'] = true;
			}
			return $structure;
		}, 10, 2 );
		add_filter( 'wpo_ips_edi_ubl_buyer_reference', static function ( $element, $data = null, $options = null, $handler = null ) {
			$ref = self::reference( is_object( $handler ) ? ( $handler->document ?? null ) : null );
			if ( is_array( $element ) && '' !== $ref ) {
				$element['value'] = $ref;
			}
			return $element;
		}, 10, 4 );
	}

	/** Stored reference of the order an EDI document is built for; '' when none, unknown or not Pro. */
	private static function reference( $doc ): string {
		$o = is_object( $doc ) ? ( $doc->order ?? null ) : null;
		if ( $o instanceof \WC_Order_Refund ) {
			$o = wc_get_order( $o->get_parent_id() );
		}
		return $o instanceof \WC_Order && \Invoqk\Validation\Limit::isPro() ? \Invoqk\B2g\Reference::get( $o ) : '';
	}

	public function findCreditNotes( \WC_Order $order ): array {
		if ( ! function_exists( 'wcpdf_get_document' ) ) {
			return [];
		}
		$files = [];
		foreach ( $order->get_refunds() as $refund ) {
			$doc = wcpdf_get_document( self::CREDIT_NOTE_TYPE, $refund, false );
			if ( $doc && $doc->exists() ) {
				$id           = (int) $refund->get_id();
				$files[ $id ] = new InvoiceFile( 'wpo:credit-note:' . $id, 'application/xml', self::format(), true );
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
