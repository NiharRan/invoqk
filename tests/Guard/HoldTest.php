<?php
use Invoqk\Guard\Hold;
use PHPUnit\Framework\TestCase;

final class HoldTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
	}

	private function order( int $id ): \WC_Order {
		return $GLOBALS['invoqk_orders'][ $id ] = new \WC_Order( $id );
	}

	public function test_set_unknown_state_ignored(): void {
		$o = $this->order( 1 );
		Hold::set( $o, 'bogus' );
		$this->assertNull( Hold::get( $o ) );
	}

	public function test_held_list_follows_state(): void {
		$o = $this->order( 1 );
		Hold::set( $o, 'held' );
		Hold::set( $o, 'held' );
		$this->assertSame( [ 1 ], $GLOBALS['invoqk_options']['invoqk_guard_held'] );
		Hold::set( $o, 'sent' );
		$this->assertSame( [], $GLOBALS['invoqk_options']['invoqk_guard_held'] );
		$this->assertTrue( Hold::isFinal( $o ) );
		$this->assertSame( 0, $o->saves );
	}

	public function test_since_only_changes_with_state(): void {
		$o = $this->order( 1 );
		Hold::set( $o, 'waiting' );
		$o->meta[ Hold::KEY ]['since'] = 100;
		Hold::set( $o, 'waiting', [ 'reason' => 'x' ] );
		$this->assertSame( 100, Hold::get( $o )['since'] );
		$this->assertSame( 'x', Hold::get( $o )['reason'] );
		Hold::set( $o, 'held' );
		$this->assertGreaterThan( 100, Hold::get( $o )['since'] );
	}

	public function test_held_ids_drop_deleted_orders(): void {
		$GLOBALS['invoqk_options']['invoqk_guard_held'] = [ 7 ];
		$this->assertSame( [], Hold::heldIds() );
		$this->assertSame( [], $GLOBALS['invoqk_options']['invoqk_guard_held'] );
	}

	public function test_mode_defaults_to_block(): void {
		$this->assertSame( 'block', Hold::mode() );
		$GLOBALS['invoqk_options']['invoqk_guard_mode'] = 'x';
		$this->assertSame( 'block', Hold::mode() );
		$GLOBALS['invoqk_options']['invoqk_guard_mode'] = 'warn';
		$this->assertSame( 'warn', Hold::mode() );
		$this->assertSame( 'block', Hold::sanitizeMode( 'x' ) );
		$this->assertSame( 'warn', Hold::sanitizeMode( 'warn' ) );
	}
}
