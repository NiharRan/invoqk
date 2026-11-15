<?php
use Invoqk\Validation\Limit;
use PHPUnit\Framework\TestCase;

final class LimitTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
	}

	private function slots(): array {
		return $GLOBALS['invoqk_options']['invoqk_free_slots'] ?? [];
	}

	private function fill(): void {
		for ( $i = 1; $i <= 20; $i++ ) { Limit::allow( $i ); }
	}

	public function test_first_20_distinct_orders_allowed(): void {
		for ( $i = 1; $i <= 20; $i++ ) { $this->assertTrue( Limit::allow( $i ) ); }
		$this->assertFalse( Limit::allow( 21 ) );
	}

	public function test_counted_order_always_allowed_again(): void {
		Limit::allow( 5 );
		$this->assertTrue( Limit::allow( 5 ) );
		$this->fill();
		$this->assertTrue( Limit::allow( 5 ) );
		$this->assertCount( 20, $this->slots() );
	}

	public function test_denied_order_takes_no_slot(): void {
		$this->fill();
		Limit::allow( 21 );
		$this->assertNotContains( 21, $this->slots() );
		$this->assertFalse( Limit::allow( 21 ) );
	}

	public function test_first_report_ids_bypass_without_slot(): void {
		$this->fill();
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ 99 ], 'startedAt' => time() ];
		$this->assertTrue( Limit::allow( 99 ) );
		$this->assertFalse( Limit::allow( 100 ) );
		$this->assertNotContains( 99, $this->slots() );
	}

	public function test_pro_filter_allows_everything_and_adds_no_slots(): void {
		add_filter( 'invoqk_is_pro', fn() => true );
		$this->assertTrue( Limit::isPro() );
		$this->assertTrue( Limit::allow( 99 ) );
		$this->assertArrayNotHasKey( 'invoqk_free_slots', $GLOBALS['invoqk_options'] );
	}

	public function test_garbage_option_is_tolerated(): void {
		$GLOBALS['invoqk_options']['invoqk_free_slots'] = 'junk';
		$this->assertTrue( Limit::allow( 3 ) );
		$GLOBALS['invoqk_options']['invoqk_free_slots'] = [ '3', 'x', null, 4 ];
		$this->assertTrue( Limit::allow( 5 ) );
		$this->assertSame( [ 3, 4, 5 ], $this->slots() );
	}

	public function test_upgrade_url_default(): void {
		$this->assertSame( 'https://invoqk.com/plugin', Limit::upgradeUrl() );
	}
}
