<?php
use Invoqk\Admin\Revalidate;
use PHPUnit\Framework\TestCase;

final class RevalidateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_as'] = [];
	}

	public function test_requires_capability_and_nonce(): void {
		$this->assertFalse( Revalidate::handle( 5, false, true ) );
		$this->assertFalse( Revalidate::handle( 5, true, false ) );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_allowed_enqueues_forced_job(): void {
		$this->assertTrue( Revalidate::handle( 5, true, true ) );
		$this->assertSame( [ 5, 1, true ], $GLOBALS['invoqk_as'][0]['args'] );
	}

	public function test_order_id_comes_from_the_link_query_arg(): void {
		$this->assertSame( 5, Revalidate::requestedId( [ 'order_id' => '5' ] ) );
		$this->assertSame( 0, Revalidate::requestedId( [] ) );
	}

	public function test_double_click_enqueues_one(): void {
		Revalidate::handle( 5, true, true );
		Revalidate::handle( 5, true, true );
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
	}
}
