<?php
namespace Invoqk\Fallback;

/**
 * Pure EN 16931 CII (D16B) builder for the fallback: Invoice + Seller::get() array in, UTF-8 XML out. No I/O.
 * Money is integer cents; tax is rounded once per rate group, never per line.
 */
final class Builder {
	private const NS = [
		'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
		'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
		'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
		'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
	];
	private const GUIDELINE = [
		'xrechnung' => 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
		'en16931'   => 'urn:cen.eu:en16931:2017',
	];

	public static function build( Invoice $i, array $seller, string $number, string $profile ): string {
		$guideline = self::GUIDELINE[ $profile ] ?? throw new Unsupported( 'settings' );
		$b         = $i->buyer;
		// XRechnung 3.0 makes the buyer electronic address (BT-49) mandatory.
		if ( 'xrechnung' === $profile && '' === self::clean( (string) ( $b['email'] ?? '' ) ) ) {
			throw new Unsupported( 'buyer_email' );
		}
		$groups = self::groups( $i->lines );
		if ( isset( $groups[0] ) && ! $i->nullsatz ) {
			throw new Unsupported( 'settings' );
		}

		$d   = new \DOMDocument();
		$d->formatOutput = true;
		$xmlns = '';
		foreach ( self::NS as $p => $uri ) {
			$xmlns .= " xmlns:$p=\"$uri\"";
		}
		// Parsed rather than set as attributes, so libxml declares the four namespaces once, on the root.
		$d->loadXML( "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rsm:CrossIndustryInvoice$xmlns/>" );
		$root = $d->documentElement;

		$ctx = self::el( $root, 'rsm:ExchangedDocumentContext' );
		// BT-23: XRechnung 3.0 rejects without it (PEPPOL-EN16931-R001).
		self::el( self::el( $ctx, 'ram:BusinessProcessSpecifiedDocumentContextParameter' ), 'ram:ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0' );
		self::el( self::el( $ctx, 'ram:GuidelineSpecifiedDocumentContextParameter' ), 'ram:ID', $guideline );
		$doc = self::el( $root, 'rsm:ExchangedDocument' );
		self::el( $doc, 'ram:ID', $number );
		self::el( $doc, 'ram:TypeCode', '380' );
		self::date( self::el( $doc, 'ram:IssueDateTime' ), $i->issueDate );

		$tx  = self::el( $root, 'rsm:SupplyChainTradeTransaction' );
		$net = 0;
		foreach ( array_values( $i->lines ) as $n => $l ) {
			$net += $l->netCents;
			$li   = self::el( $tx, 'ram:IncludedSupplyChainTradeLineItem' );
			self::el( self::el( $li, 'ram:AssociatedDocumentLineDocument' ), 'ram:LineID', (string) ( $n + 1 ) );
			self::el( self::el( $li, 'ram:SpecifiedTradeProduct' ), 'ram:Name', $l->name );
			self::el( self::el( self::el( $li, 'ram:SpecifiedLineTradeAgreement' ), 'ram:NetPriceProductTradePrice' ), 'ram:ChargeAmount', self::unitPrice( $l ) );
			self::el( self::el( $li, 'ram:SpecifiedLineTradeDelivery' ), 'ram:BilledQuantity', $l->quantity )->setAttribute( 'unitCode', 'H87' );
			$ls  = self::el( $li, 'ram:SpecifiedLineTradeSettlement' );
			$tax = self::el( $ls, 'ram:ApplicableTradeTax' );
			self::el( $tax, 'ram:TypeCode', 'VAT' );
			self::el( $tax, 'ram:CategoryCode', $l->ratePercent ? 'S' : 'Z' );
			self::el( $tax, 'ram:RateApplicablePercent', (string) $l->ratePercent );
			self::el( self::el( $ls, 'ram:SpecifiedTradeSettlementLineMonetarySummation' ), 'ram:LineTotalAmount', self::money( $l->netCents ) );
		}

		$ag = self::el( $tx, 'ram:ApplicableHeaderTradeAgreement' );
		$ref = self::clean( (string) ( $b['reference'] ?? '' ) );
		self::el( $ag, 'ram:BuyerReference', '' !== $ref ? $ref : $i->orderNumber );

		$sp = self::el( $ag, 'ram:SellerTradeParty' );
		// BR-CO-26 wants BT-29, BT-30 or BT-31; a seller with only a tax number carries it as BT-29.
		if ( '' === self::clean( (string) $seller['vat_id'] ) ) {
			self::opt( $sp, 'ram:ID', $seller['tax_number'] );
		}
		self::el( $sp, 'ram:Name', (string) $seller['name'] );
		$ct = self::el( $sp, 'ram:DefinedTradeContact' );
		self::opt( $ct, 'ram:PersonName', $seller['contact_name'] );
		if ( '' !== self::clean( (string) $seller['contact_phone'] ) ) {
			self::el( self::el( $ct, 'ram:TelephoneUniversalCommunication' ), 'ram:CompleteNumber', (string) $seller['contact_phone'] );
		}
		if ( '' !== self::clean( (string) $seller['contact_email'] ) ) {
			self::el( self::el( $ct, 'ram:EmailURIUniversalCommunication' ), 'ram:URIID', (string) $seller['contact_email'] );
		}
		self::address( $sp, $seller['street'], $seller['postcode'], $seller['city'], 'DE' );
		self::email( $sp, $seller['contact_email'] );
		self::taxId( $sp, $seller['vat_id'], 'VA' );
		self::taxId( $sp, $seller['tax_number'], 'FC' );

		$bp      = self::el( $ag, 'ram:BuyerTradeParty' );
		$company = self::clean( (string) ( $b['company'] ?? '' ) );
		self::el( $bp, 'ram:Name', '' !== $company ? $company : (string) ( $b['name'] ?? '' ) );
		self::address( $bp, $b['street'] ?? '', $b['postcode'] ?? '', $b['city'] ?? '', $b['country'] ?? '' );
		self::email( $bp, $b['email'] ?? '' );
		self::taxId( $bp, $b['vat_id'] ?? '', 'VA' );

		self::date( self::el( self::el( self::el( $tx, 'ram:ApplicableHeaderTradeDelivery' ), 'ram:ActualDeliverySupplyChainEvent' ), 'ram:OccurrenceDateTime' ), $i->deliveryDate );

		$hs = self::el( $tx, 'ram:ApplicableHeaderTradeSettlement' );
		self::el( $hs, 'ram:InvoiceCurrencyCode', 'EUR' );
		$pm = self::el( $hs, 'ram:SpecifiedTradeSettlementPaymentMeans' );
		self::el( $pm, 'ram:TypeCode', '58' );
		self::el( self::el( $pm, 'ram:PayeePartyCreditorFinancialAccount' ), 'ram:IBANID', (string) $seller['iban'] );
		if ( '' !== self::clean( (string) $seller['bic'] ) ) {
			self::el( self::el( $pm, 'ram:PayeeSpecifiedCreditorFinancialInstitution' ), 'ram:BICID', (string) $seller['bic'] );
		}
		$taxTotal = 0;
		foreach ( $groups as $rate => [ $basis, $tax ] ) {
			$taxTotal += $tax;
			$t = self::el( $hs, 'ram:ApplicableTradeTax' );
			self::el( $t, 'ram:CalculatedAmount', self::money( $tax ) );
			self::el( $t, 'ram:TypeCode', 'VAT' );
			self::el( $t, 'ram:BasisAmount', self::money( $basis ) );
			self::el( $t, 'ram:CategoryCode', $rate ? 'S' : 'Z' );
			self::el( $t, 'ram:RateApplicablePercent', (string) $rate );
		}
		$pt = self::el( $hs, 'ram:SpecifiedTradePaymentTerms' );
		self::opt( $pt, 'ram:Description', $seller['terms'] );
		$due = ( new \DateTimeImmutable( $i->issueDate ) )->modify( '+' . (int) $seller['due_days'] . ' days' )->format( 'Y-m-d' );
		self::date( self::el( $pt, 'ram:DueDateDateTime' ), $due );

		$grand = self::grandCents( $i->lines );
		$sum   = self::el( $hs, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation' );
		self::el( $sum, 'ram:LineTotalAmount', self::money( $net ) );
		self::el( $sum, 'ram:TaxBasisTotalAmount', self::money( $net ) );
		self::el( $sum, 'ram:TaxTotalAmount', self::money( $taxTotal ) )->setAttribute( 'currencyID', 'EUR' );
		self::el( $sum, 'ram:GrandTotalAmount', self::money( $grand ) );
		self::el( $sum, 'ram:DuePayableAmount', self::money( $grand ) );

		return $d->saveXML();
	}

	/** Grand total in cents: per rate group, basis plus round( basis * rate / 100 ) half up. @param InvoiceLine[] $lines */
	public static function grandCents( array $lines ): int {
		$g = 0;
		foreach ( self::groups( $lines ) as [ $basis, $tax ] ) {
			$g += $basis + $tax;
		}
		return $g;
	}

	/** rate => [ basis, tax ] in cents, highest rate first. @throws Unsupported tax_rate for any rate but 19, 7, 0. */
	private static function groups( array $lines ): array {
		$basis = [];
		foreach ( $lines as $l ) {
			if ( ! in_array( $l->ratePercent, [ 19, 7, 0 ], true ) ) {
				throw new Unsupported( 'tax_rate' );
			}
			$basis[ $l->ratePercent ] = ( $basis[ $l->ratePercent ] ?? 0 ) + $l->netCents;
		}
		krsort( $basis );
		$out = [];
		foreach ( $basis as $rate => $b ) {
			$x            = $b * $rate;
			$out[ $rate ] = [ $b, ( $x <=> 0 ) * intdiv( abs( $x ) + 50, 100 ) ];
		}
		return $out;
	}

	/** BT-146: line net / quantity, half up, two to four decimals. */
	private static function unitPrice( InvoiceLine $l ): string {
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d{1,6}))?$/', $l->quantity, $m ) || 0 === (int) ( $m[1] . ( $m[2] ?? '' ) ) ) {
			throw new Unsupported( 'totals' );
		}
		$q   = (int) ( $m[1] . ( $m[2] ?? '' ) );
		$x   = $l->netCents * 100 * 10 ** strlen( $m[2] ?? '' ); // price in 1/10000 EUR = x / q
		$p   = ( $x <=> 0 ) * intdiv( 2 * abs( $x ) + $q, 2 * $q );
		$abs = abs( $p );
		$dec = rtrim( sprintf( '%04d', $abs % 10000 ), '0' );
		return ( $p < 0 ? '-' : '' ) . intdiv( $abs, 10000 ) . '.' . str_pad( $dec, 2, '0' );
	}

	private static function money( int $c ): string {
		return ( $c < 0 ? '-' : '' ) . intdiv( abs( $c ), 100 ) . '.' . sprintf( '%02d', abs( $c ) % 100 );
	}

	/** Drops bytes and characters XML 1.0 cannot carry (NUL, form feed, invalid UTF-8). */
	private static function clean( string $s ): string {
		return trim( (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub( $s, 'UTF-8' ) ) );
	}

	private static function el( \DOMElement $parent, string $qname, ?string $text = null ): \DOMElement {
		$e = $parent->ownerDocument->createElementNS( self::NS[ strstr( $qname, ':', true ) ], $qname );
		if ( null !== $text ) {
			$e->appendChild( $parent->ownerDocument->createTextNode( self::clean( $text ) ) );
		}
		return $parent->appendChild( $e );
	}

	private static function opt( \DOMElement $parent, string $qname, $text ): void {
		if ( '' !== self::clean( (string) $text ) ) {
			self::el( $parent, $qname, (string) $text );
		}
	}

	private static function date( \DOMElement $parent, string $ymd ): void {
		self::el( $parent, 'udt:DateTimeString', str_replace( '-', '', $ymd ) )->setAttribute( 'format', '102' );
	}

	private static function address( \DOMElement $party, $street, $postcode, $city, $country ): void {
		$a = self::el( $party, 'ram:PostalTradeAddress' );
		self::opt( $a, 'ram:PostcodeCode', $postcode );
		self::opt( $a, 'ram:LineOne', $street );
		self::opt( $a, 'ram:CityName', $city );
		self::el( $a, 'ram:CountryID', (string) $country );
	}

	private static function email( \DOMElement $party, $email ): void {
		if ( '' !== self::clean( (string) $email ) ) {
			self::el( self::el( $party, 'ram:URIUniversalCommunication' ), 'ram:URIID', (string) $email )->setAttribute( 'schemeID', 'EM' );
		}
	}

	private static function taxId( \DOMElement $party, $id, string $scheme ): void {
		if ( '' !== self::clean( (string) $id ) ) {
			self::el( self::el( $party, 'ram:SpecifiedTaxRegistration' ), 'ram:ID', (string) $id )->setAttribute( 'schemeID', $scheme );
		}
	}
}
