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
		$this->assertSame( "RE-1;05.03.2026;Kunde;100,00;19,00;119,00;EUR;valid;2;ja\r\n", ExportFormat::line( $this->row( [ 'version' => 2 ] ) ) );
	}

	public function test_header_starts_with_bom(): void {
		$h = ExportFormat::header();
		$this->assertStringStartsWith( "\xEF\xBB\xBFRechnungsnr;Datum;Kunde;", $h );
		$this->assertStringEndsWith( ";\"Erneut gesendet\"\r\n", $h );
	}

	public function test_formula_prefix_is_neutralised(): void {
		foreach ( [ '=HYPERLINK("x")', '@a', '+1', '-1' ] as $c ) {
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
