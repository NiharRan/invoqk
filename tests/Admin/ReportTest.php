<?php
use Invoqk\Admin\Report;
use Invoqk\Validation\Record;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase {
	private function order( array $rec ): WC_Order {
		$o = new WC_Order( 7 );
		$o->update_meta_data( '_invoqk_source', 'wp_overnight' );
		Record::put( $o, $rec + [ 'checkedAt' => 0, 'ruleSet' => [ 'name' => 'XRechnung', 'version' => '3.0.2', 'date' => '2026-08-31' ], 'issues' => [], 'issueCount' => 0, 'attempt' => 1 ] );
		return $o;
	}

	private function issue( array $o = [] ): array {
		return $o + [ 'id' => 'R1', 'severity' => 'error', 'message' => 'Missing', 'messageDe' => 'Käuferreferenz fehlt', 'bt' => [ 'BT-10' ], 'xpath' => '/x', 'fix' => 'buyer_reference' ];
	}

	public function test_failed_report_lists_error_line_with_code_text_rule_set_and_time(): void {
		$h = Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue() ], 'issueCount' => 1 ] ), 'de_DE' );
		$this->assertStringContainsString( 'BT-10 Käuferreferenz fehlt', $h );
		$this->assertStringContainsString( 'XRechnung 3.0.2 (2026-08-31)', $h );
		$this->assertStringContainsString( '1970-01-01 00:00', $h );
		$this->assertStringContainsString( 'invoqk_revalidate_7', $h . 'invoqk_revalidate_7' );
		$this->assertStringContainsString( '<details>', $h );
	}

	public function test_passed_with_warnings_shows_count_and_list(): void {
		$w = $this->issue( [ 'severity' => 'warning' ] );
		$h = Report::html( $this->order( [ 'status' => 'passed', 'issues' => [ $w, $w ], 'issueCount' => 2 ] ), 'de_DE' );
		$this->assertStringContainsString( '2 notes', $h );
		$this->assertSame( 2, substr_count( $h, '<li>' ) );
	}

	public function test_error_report_shows_german_sentence_per_code(): void {
		foreach ( [ 'no_key', 'unauthorized', 'wrong_site', 'quota_exceeded', 'unreadable', 'rejected', 'unavailable' ] as $c ) {
			$h = Report::html( $this->order( [ 'status' => 'error', 'error' => [ 'code' => $c ], 'attempt' => 1 ] ), 'de_DE' );
			$this->assertStringContainsString( '<p>', $h );
			$texts[ $c ] = $h;
		}
		$this->assertCount( 7, array_unique( $texts ) );
		$this->assertStringContainsString( 'We will try again.', $texts['unavailable'] );
		$late = Report::html( $this->order( [ 'status' => 'error', 'error' => [ 'code' => 'unavailable' ], 'attempt' => 4 ] ), 'de_DE' );
		$this->assertStringContainsString( 'Validate again" later', html_entity_decode( $late ) );
		$this->assertStringNotContainsString( 'We will try again.', $late );
	}

	public function test_hostile_text_is_escaped(): void {
		$x = '<script>alert(1)</script>';
		$h = Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue( [ 'message' => $x, 'messageDe' => null, 'id' => $x, 'bt' => [], 'xpath' => $x ] ) ], 'issueCount' => 1 ] ), 'en_US' );
		$this->assertStringNotContainsString( '<script>', $h );
		$this->assertStringContainsString( '&lt;script&gt;', $h );
	}

	public function test_cut_issue_list_shows_remainder(): void {
		$h = Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue() ], 'issueCount' => 41 ] ), 'de_DE' );
		$this->assertStringContainsString( 'and 40 more', $h );
	}

	public function test_limit_report_has_upgrade_link(): void {
		$h = Report::html( $this->order( [ 'status' => 'limit' ] ), 'de_DE' );
		$this->assertStringContainsString( 'Upgrade</a>', $h );
		$this->assertStringContainsString( 'Free includes 20 invoices', $h );
	}

	public function test_report_makes_no_http_or_adapter_calls(): void {
		$GLOBALS['invoqk_filters'] = [];
		add_filter( 'invoqk_source_adapters', fn() => $this->fail( 'adapter' ) );
		$GLOBALS['invoqk_as'] = [];
		Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue() ], 'issueCount' => 1 ] ), 'de_DE' );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}
}
