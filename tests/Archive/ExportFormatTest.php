<?php
use Invoqk\Archive\ExportFormat;
use PHPUnit\Framework\TestCase;

final class ExportFormatTest extends TestCase {
	private function row( array $o = [] ): array {
		return $o + [
			'id' => 4, 'order_id' => 7, 'version' => 1, 'invoice_number' => 'RE-1', 'invoice_date' => '2026-03-05',
			'customer' => 'Kunde', 'net' => '100.00', 'vat' => '19.00', 'gross' => '119.00', 'currency' => 'EUR',
			'validation_status' => 'valid', 'sent_at' => 1772668800,
		];
	}

	public function test_line_formats_money_date_and_resend(): void {
		$this->assertSame( "RE-1;05.03.2026;Kunde;100,00;19,00;119,00;EUR;valid;2;ja;Rechnung;\r\n", ExportFormat::line( $this->row( [ 'version' => 2 ] ) ) );
	}

	public function test_first_version_has_empty_resend_cell(): void {
		$this->assertStringContainsString( ";1;;Rechnung;\r\n", ExportFormat::line( $this->row() ) );
	}

	public function test_header_starts_with_bom(): void {
		$h = ExportFormat::header();
		$this->assertStringStartsWith( "\xEF\xBB\xBFRechnungsnr;Datum;Kunde;", $h );
		$this->assertStringEndsWith( ";Erneut gesendet;Art;Bezug auf Rechnungsnr\r\n", $h );
	}

	public function test_header_keeps_existing_columns_first(): void {
		$this->assertSame( [ 'Rechnungsnr', 'Datum', 'Kunde', 'Netto', 'USt', 'Brutto', 'Währung', 'Validierungsstatus', 'Version', 'Erneut gesendet', 'Art', 'Bezug auf Rechnungsnr' ], ExportFormat::HEADER );
	}

	public function test_credit_note_line_has_art_and_bezug_and_no_resent_flag(): void {
		$c = str_getcsv( rtrim( ExportFormat::line( $this->row( [ 'kind' => 'credit_note', 'version' => 1, 'gross' => '-119.00' ] ), 'RE-1' ) ), ';', '"', '' );
		$this->assertSame( [ '', 'Gutschrift', 'RE-1' ], array_slice( $c, 9 ) );
	}

	public function test_invoice_line_has_art_rechnung(): void {
		$c = str_getcsv( rtrim( ExportFormat::line( $this->row( [ 'kind' => 'invoice' ] ) ) ), ';', '"', '' );
		$this->assertSame( [ 'Rechnung', '' ], array_slice( $c, 10 ) );
	}

	public function test_formula_prefix_is_neutralised(): void {
		foreach ( [ '=HYPERLINK("x")', '@a', '+1', '-1', "\tx", "\rx" ] as $c ) {
			$cells = str_getcsv( rtrim( ExportFormat::line( $this->row( [ 'customer' => $c ] ) ) ), ';', '"', '' );
			$this->assertSame( "'" . $c, $cells[2] );
		}
	}

	public function test_customer_with_semicolon_and_quote_is_quoted(): void {
		$line = ExportFormat::line( $this->row( [ 'customer' => 'A;"B"' ] ) );
		$this->assertStringContainsString( ';"A;""B""";', $line );
	}

	public function test_date_falls_back_to_sent_at(): void {
		$this->assertSame( '2026-03-05', ExportFormat::date( $this->row( [ 'invoice_date' => null ] ) ) );
	}

	public function test_file_base_fallback_without_number(): void {
		$u = [];
		$this->assertSame( 'order-7_v1', ExportFormat::fileBase( $this->row( [ 'invoice_number' => '' ] ), $u ) );
	}

	public function test_file_base_collision_appends_row_id(): void {
		$u = [];
		$this->assertSame( 'RE-1_v1', ExportFormat::fileBase( $this->row( [ 'id' => 4 ] ), $u ) );
		$this->assertSame( 'RE-1_v1-9', ExportFormat::fileBase( $this->row( [ 'id' => 9 ] ), $u ) );
		$this->assertSame( 're-1_v1-11', ExportFormat::fileBase( $this->row( [ 'id' => 11, 'invoice_number' => 're-1' ] ), $u ) );
	}
}
