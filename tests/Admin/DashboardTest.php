<?php
use Invoqk\Admin\Dashboard;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase {
	private function data( array $o = [] ): array {
		return $o + [ 'passed' => 13, 'failed' => 5, 'missing' => 3, 'unchecked' => 2, 'notRequired' => 48, 'required' => 21, 'score' => 62, 'capped' => false, 'top' => [] ];
	}

	public function test_score_counts_and_bar_widths(): void {
		$h = Dashboard::html( $this->data(), 'under', true );
		$this->assertStringContainsString( '62 %', $h );
		$this->assertStringContainsString( '13 of 21', $h );
		$this->assertStringContainsString( 'width:62%', $h );
		$this->assertStringContainsString( 'width:24%', $h );
		$this->assertStringContainsString( 'width:14%', $h );
		foreach ( [ 'invoqk-pill ok">13', 'invoqk-pill bad">5', 'invoqk-pill warn">3', 'invoqk-pill na">48' ] as $p ) {
			$this->assertStringContainsString( $p, $h );
		}
		$this->assertStringNotContainsString( 'invoqk-empty', $h );
		$this->assertStringContainsString( 'invoqk_wizard=1', $h );
	}

	public function test_null_score_shows_dash_and_empty_state(): void {
		$h = Dashboard::html( $this->data( [ 'required' => 0, 'passed' => 0, 'failed' => 0, 'missing' => 0, 'score' => null ] ), null, true );
		$this->assertStringContainsString( '–', $h );
		$this->assertStringContainsString( 'invoqk-empty', $h );
		$this->assertStringContainsString( 'No B2B invoices found yet', $h );
		$this->assertStringNotContainsString( 'NAN', $h );
		$this->assertStringNotContainsString( 'width:', $h );
	}

	public function test_deadline_over_and_under_and_unset(): void {
		$this->assertStringContainsString( '1.1.2027', Dashboard::html( $this->data(), 'over', true ) );
		$this->assertStringNotContainsString( '1.1.2028', Dashboard::html( $this->data(), 'over', true ) );
		$this->assertStringContainsString( '1.1.2028', Dashboard::html( $this->data(), 'under', true ) );
		$h = Dashboard::html( $this->data(), null, true );
		$this->assertStringContainsString( '1.1.2027', $h );
		$this->assertStringContainsString( '1.1.2028', $h );
		$this->assertStringContainsString( 'name="action" value="invoqk_turnover"', $h );
		$this->assertStringContainsString( 'name="band"', $h );
		$this->assertStringContainsString( 'name="_wpnonce"', $h );
	}

	public function test_disclaimer_always_present(): void {
		foreach ( [ 'over', 'under', null ] as $t ) {
			$this->assertStringContainsString( 'No legal or tax advice.', Dashboard::html( $this->data(), $t, true ) );
		}
	}

	public function test_top_error_row_escaped_with_fix_link(): void {
		$top = [
			[ 'code' => '<script>x</script>', 'text' => 'Bad <b>', 'count' => 5, 'raw' => 'r', 'fix' => [ 'label' => 'Fix', 'url' => '/f?a=1&b=2' ] ],
			[ 'code' => 'BT-31', 'text' => 'No VAT id', 'count' => 4, 'raw' => 'r', 'fix' => null ],
		];
		$h = Dashboard::html( $this->data( [ 'top' => $top ] ), 'under', true );
		$this->assertStringNotContainsString( '<script>', $h );
		$this->assertStringContainsString( '&lt;script&gt;', $h );
		$this->assertStringContainsString( '5×', $h );
		$this->assertStringContainsString( 'href="/f?a=1&amp;b=2"', $h );
		$this->assertSame( 1, substr_count( $h, 'Fix →' ) );
	}

	public function test_pro_cta_links_upgrade_url_and_labels_pro(): void {
		$h = Dashboard::html( $this->data(), 'under', true );
		$this->assertStringContainsString( 'href="' . esc_url( \Invoqk\Validation\Limit::upgradeUrl() ) . '"', $h );
		$this->assertStringContainsString( 'Try Pro for 14 days', $h );
		$this->assertSame( 2, substr_count( $h, 'invoqk-lock">PRO' ) );
	}

	public function test_capped_note_and_no_key_notice(): void {
		$this->assertStringNotContainsString( 'newest 2,000', Dashboard::html( $this->data(), 'under', true ) );
		$this->assertStringContainsString( 'newest 2,000', Dashboard::html( $this->data( [ 'capped' => true ] ), 'under', true ) );
		$this->assertStringNotContainsString( 'invoqk-nokey', Dashboard::html( $this->data(), 'under', true ) );
		$this->assertStringContainsString( 'invoqk-nokey', Dashboard::html( $this->data(), 'under', false ) );
	}

	public function test_no_key_notice_links_to_key_field(): void {
		$h = Dashboard::html( $this->data(), 'under', false );
		$this->assertStringContainsString( 'href="#invoqk_api_key"', $h );
		$this->assertStringNotContainsString( 'WooCommerce &gt; Invoqk', $h );
	}
}
