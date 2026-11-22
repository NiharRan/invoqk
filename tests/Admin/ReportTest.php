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
		$this->assertStringContainsString( 'action=invoqk_revalidate', $h );
		$this->assertStringContainsString( 'order_id=7', $h );
		$this->assertStringContainsString( '_wpnonce=nonce-invoqk_revalidate_7', $h );
		$this->assertStringContainsString( 'Validated: 1970-01-01 00:00', $h );
		$this->assertStringContainsString( '<details>', $h );
	}

	public function test_report_has_no_nested_form_inside_order_edit_form(): void {
		$h = Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue() ], 'issueCount' => 1 ] ), 'de_DE' );
		$this->assertStringNotContainsString( '<form', $h );
		$this->assertStringContainsString( '<a class="button"', $h );
	}

	public function test_rule_set_line_skips_missing_parts(): void {
		$mk = fn( array $rs ) => Report::html( $this->order( [ 'status' => 'passed', 'ruleSet' => $rs ] ), 'de_DE' );
		$this->assertStringContainsString( 'XRechnung 3.0.2</p>', $mk( [ 'name' => 'XRechnung', 'version' => '3.0.2' ] ) );
		$this->assertStringContainsString( 'XRechnung (2026-08-31)</p>', $mk( [ 'name' => 'XRechnung', 'date' => '2026-08-31' ] ) );
		$this->assertStringContainsString( 'XRechnung</p>', $mk( [ 'name' => 'XRechnung' ] ) );
	}

	public function test_passed_with_warnings_shows_count_and_list(): void {
		$w = $this->issue( [ 'severity' => 'warning' ] );
		$h = Report::html( $this->order( [ 'status' => 'passed', 'issues' => [ $w, $w ], 'issueCount' => 2 ] ), 'de_DE' );
		$this->assertStringContainsString( '2 notes', $h );
		$this->assertSame( 2, substr_count( $h, '<li>' ) );
	}

	public function test_error_report_shows_sentence_per_code(): void {
		$exp = [
			'no_key'         => 'No API key is set yet. Enter it under WooCommerce &gt; Invoqk.',
			'unauthorized'   => 'The API key is invalid or was revoked.',
			'wrong_site'     => 'This API key belongs to a different domain.',
			'unreadable'     => 'The invoice file could not be read.',
			'rejected'       => 'The file is not a supported e-invoice format.',
			'quota_exceeded' => 'The check quota is used up. Please click “Validate again” later.',
			'unavailable'    => 'The validation service is not reachable right now. We will try again.',
		];
		foreach ( $exp as $c => $t ) {
			$this->assertStringContainsString( "<p>$t</p>", Report::html( $this->order( [ 'status' => 'error', 'error' => [ 'code' => $c ] ] ), 'de_DE' ), $c );
		}
	}

	public function test_retry_sentence_follows_job_policy(): void {
		$h = fn( string $c, int $n, array $e = [] ) => Report::html( $this->order( [ 'status' => 'error', 'error' => [ 'code' => $c ] + $e, 'attempt' => $n ] ), 'de_DE' );
		$this->assertStringContainsString( 'We will try again later.', $h( 'quota_exceeded', 1, [ 'retryAfter' => 120 ] ) );
		$this->assertStringContainsString( 'Validate again', $h( 'quota_exceeded', 1 ) );
		$this->assertStringContainsString( 'Validate again', $h( 'quota_exceeded', 2, [ 'retryAfter' => 120 ] ) );
		$this->assertStringContainsString( 'We will try again.', $h( 'unavailable', 1 ) );
		$this->assertStringContainsString( 'Validate again', $h( 'unavailable', 4 ) );
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

	public function test_report_makes_no_http_or_adapter_calls(): void {
		$GLOBALS['invoqk_filters'] = [];
		add_filter( 'invoqk_source_adapters', fn() => $this->fail( 'adapter' ) );
		$GLOBALS['invoqk_as'] = [];
		$GLOBALS['invoqk_http'] = [];
		Report::html( $this->order( [ 'status' => 'failed', 'issues' => [ $this->issue() ], 'issueCount' => 1 ] ), 'de_DE' );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$this->assertSame( [], $GLOBALS['invoqk_http'] );
	}
}
