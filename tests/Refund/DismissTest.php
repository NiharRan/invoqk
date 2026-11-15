<?php
use Invoqk\Refund\Dismiss;
use Invoqk\Refund\Open;
use PHPUnit\Framework\TestCase;

final class DismissTest extends TestCase {
	private WC_Order $o;

	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
		$GLOBALS['invoqk_options'] = [];
		$this->o                  = new WC_Order( 5 );
		$GLOBALS['invoqk_orders'] = [ 5 => $this->o ];
		Open::onRefunded( 5, 11 );
	}

	public function test_requires_capability_and_nonce(): void {
		$this->assertFalse( Dismiss::handle( 5, 11, false, true, 1, 'A' ) );
		$this->assertFalse( Dismiss::handle( 5, 11, true, false, 1, 'A' ) );
		$this->assertSame( [ 11 ], Open::ids( $this->o ) );
		$this->assertSame( [], $this->o->notes );
	}

	public function test_dismiss_clears_flag_and_writes_note_with_user(): void {
		$this->assertTrue( Dismiss::handle( 5, 11, true, true, 7, 'Erika' ) );
		$this->assertSame( [], Open::ids( $this->o ) );
		$this->assertSame( 0, Open::count() );
		$this->assertSame( 'Invoqk: no corrective e-invoice needed for refund #11, set by Erika.', $this->o->notes[0] );
	}

	public function test_unknown_refund_returns_false(): void {
		$this->assertFalse( Dismiss::handle( 5, 99, true, true, 7, 'Erika' ) );
		$this->assertFalse( Dismiss::handle( 404, 11, true, true, 7, 'Erika' ) );
		$this->assertSame( [], $this->o->notes );
	}

	public function test_not_pro_changes_nothing(): void {
		$GLOBALS['invoqk_filters'] = [];
		$this->assertFalse( Dismiss::handle( 5, 11, true, true, 7, 'Erika' ) );
		$this->assertSame( [ 11 ], Open::ids( $this->o ) );
		$this->assertSame( [], $this->o->notes );
	}
}
