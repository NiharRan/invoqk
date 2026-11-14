<?php
use Invoqk\Requirements;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase {
	public function test_ok_on_minimum_versions(): void {
		$this->assertSame( [], Requirements::problems( '6.5', '8.1.0', '9.0.0' ) );
	}

	public function test_php_too_old(): void {
		$p = Requirements::problems( '6.5', '8.0.30', '9.0.0' );
		$this->assertCount( 1, $p );
		$this->assertStringContainsString( '8.1', $p[0] );
	}

	public function test_wp_too_old(): void {
		$p = Requirements::problems( '6.4', '8.1.0', '9.0.0' );
		$this->assertCount( 1, $p );
		$this->assertStringContainsString( '6.5', $p[0] );
	}

	public function test_woocommerce_missing(): void {
		$p = Requirements::problems( '6.5', '8.1.0', null );
		$this->assertCount( 1, $p );
		$this->assertStringContainsString( 'WooCommerce', $p[0] );
	}

	public function test_multiple_problems_all_reported(): void {
		$this->assertCount( 2, Requirements::problems( '6.5', '8.0.0', null ) );
	}
}
