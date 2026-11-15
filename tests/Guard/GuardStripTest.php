<?php
use Invoqk\Guard\Guard;
use Invoqk\Guard\Hold;
use PHPUnit\Framework\TestCase;

final class GuardStripTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options']          = [];
		$GLOBALS['invoqk_orders']           = [];
		$GLOBALS['invoqk_as']               = [];
		$GLOBALS['invoqk_filters']          = [];
		$GLOBALS['invoqk_edi']              = true;
		$GLOBALS['invoqk_wpo_email_docs']   = [];
		Guard::reset();
		add_filter( 'invoqk_is_pro', fn() => true );
	}

	private function order( int $id = 5 ): \WC_Order {
		$o                  = $GLOBALS['invoqk_orders'][ $id ] = new \WC_Order( $id );
		$o->meta['_invoqk_source'] = 'wpo';
		return $o;
	}

	private function rec( \WC_Order $o, string $path, string $type = 'invoice' ): void {
		Guard::record( $path, $type, (object) [ 'order' => $o ], 'pdf' );
	}

	private function actions( string $hook ): array {
		return array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => $a['hook'] === $hook ) );
	}

	public function test_strips_only_recorded_invoice_paths(): void {
		$o = $this->order();
		$this->rec( $o, '/tmp/inv.pdf' );
		$this->rec( $o, '/tmp/inv.xml' );
		$out = Guard::filterAttachments( [ '/tmp/terms.pdf', '/tmp/inv.pdf', '/tmp/inv.xml' ], 'customer_completed_order', $o );
		$this->assertSame( [ '/tmp/terms.pdf' ], $out );
		$this->assertSame( 'waiting', Hold::get( $o )['state'] );
		$this->assertSame( [ 'customer_completed_order' ], Hold::get( $o )['emails'] );
		$this->assertCount( 1, $this->actions( 'invoqk_detect_order' ) );
		$t = $this->actions( Guard::TIMEOUT );
		$this->assertCount( 1, $t );
		$this->assertSame( [ 5 ], $t[0]['args'] );
		$this->assertEqualsWithDelta( time() + 300, $t[0]['when'], 2 );
	}

	public function test_packing_slip_paths_not_recorded(): void {
		$o = $this->order();
		$this->rec( $o, '/tmp/slip.pdf', 'packing-slip' );
		$in = [ '/tmp/slip.pdf' ];
		$this->assertSame( $in, Guard::filterAttachments( $in, 'customer_completed_order', $o ) );
		$this->assertNull( Hold::get( $o ) );
	}

	public function test_untouched_when_b2c_passing_free_or_not_order(): void {
		$in = [ '/tmp/inv.pdf' ];
		$b2c          = $this->order( 1 );
		$b2c->company = '';
		$passing      = $this->order( 2 );
		$passing->meta['_invoqk_validation'] = [ 'status' => 'passed' ];
		$free = $this->order( 3 );
		foreach ( [ $b2c, $passing, $free ] as $o ) {
			$this->rec( $o, '/tmp/inv.pdf' );
		}
		$GLOBALS['invoqk_filters']['invoqk_is_pro'] = [];
		$this->assertSame( $in, Guard::filterAttachments( $in, 'e', $free ) );
		add_filter( 'invoqk_is_pro', fn() => true );
		$this->assertSame( $in, Guard::filterAttachments( $in, 'e', $b2c ) );
		$this->assertSame( $in, Guard::filterAttachments( $in, 'e', $passing ) );
		$this->assertSame( $in, Guard::filterAttachments( $in, 'e', 'not an order' ) );
		foreach ( [ $b2c, $passing, $free ] as $o ) {
			$this->assertArrayNotHasKey( Hold::KEY, $o->meta );
		}
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_second_email_appends_and_schedules_one_timeout(): void {
		$o = $this->order();
		$this->rec( $o, '/tmp/a.pdf' );
		Guard::filterAttachments( [ '/tmp/a.pdf' ], 'customer_processing_order', $o );
		$this->rec( $o, '/tmp/a.pdf' );
		Guard::filterAttachments( [ '/tmp/a.pdf' ], 'customer_completed_order', $o );
		$this->assertSame( [ 'customer_processing_order', 'customer_completed_order' ], Hold::get( $o )['emails'] );
		$this->assertCount( 1, $this->actions( Guard::TIMEOUT ) );
	}

	public function test_held_order_stays_held_when_stripped_again(): void {
		$o = $this->order();
		Hold::set( $o, 'held' );
		$this->rec( $o, '/tmp/a.pdf' );
		$this->assertSame( [], Guard::filterAttachments( [ '/tmp/a.pdf' ], 'customer_completed_order', $o ) );
		$this->assertSame( 'held', Hold::get( $o )['state'] );
	}

	public function test_held_invoice_stays_stripped_when_guard_no_longer_covers(): void {
		$GLOBALS['invoqk_wpo_email_docs']['customer_completed_order'] = [ 'pdf' => [ 'invoice' ] ];
		$email = (object) [ 'id' => 'customer_completed_order' ];
		$free  = $this->order( 1 );
		$pass  = $this->order( 2 );
		$pass->meta['_invoqk_validation'] = [ 'status' => 'passed' ];
		$b2c          = $this->order( 3 );
		$b2c->company = '';
		$GLOBALS['invoqk_filters']['invoqk_is_pro'] = [];
		foreach ( [ $free, $pass, $b2c ] as $o ) {
			Hold::set( $o, 'held' );
			$this->rec( $o, '/tmp/a.pdf' );
			$this->assertSame( [ '/tmp/t.pdf' ], Guard::filterAttachments( [ '/tmp/t.pdf', '/tmp/a.pdf' ], 'customer_completed_order', $o ), (string) $o->get_id() );
			$this->assertSame( 'held', Hold::get( $o )['state'] );
			$this->assertStringContainsString( 'will follow', Guard::footer( $o, false, false, $email ) );
		}
		$admin = new class { public function is_customer_email(): bool { return false; } };
		$this->rec( $free, '/tmp/a.pdf' );
		$this->assertSame( [ '/tmp/a.pdf' ], Guard::filterAttachments( [ '/tmp/a.pdf' ], 'new_order', $free, $admin ) );
	}

	public function test_final_state_lets_attachment_through(): void {
		$o = $this->order();
		Hold::set( $o, 'sent' );
		$this->rec( $o, '/tmp/a.pdf' );
		$this->assertSame( [ '/tmp/a.pdf' ], Guard::filterAttachments( [ '/tmp/a.pdf' ], 'customer_completed_order', $o ) );
	}

	public function test_footer_line_only_when_invoice_will_be_attached(): void {
		$o = $this->order();
		$GLOBALS['invoqk_wpo_email_docs']['customer_completed_order'] = [ 'pdf' => [ 'invoice' ] ];
		$email = (object) [ 'id' => 'customer_completed_order' ];
		$this->assertSame( '<p>Your invoice will follow in a separate email.</p>', Guard::footer( $o, false, false, $email ) );
		$this->assertStringContainsString( 'will follow', Guard::footer( $o, false, true, $email ) );
		$this->assertSame( '', Guard::footer( $o, false, false, (object) [ 'id' => 'other' ] ) );
		$this->assertSame( '', Guard::footer( $o, true, false, $email ) );
	}

	public function test_hook_callbacks_pass_unexpected_input_through(): void {
		$o = $this->order();
		$this->rec( $o, '/tmp/a.pdf' );
		$this->assertNull( Guard::filterAttachments( null, 'x', $o ) );
		$this->assertSame( 'str', Guard::filterAttachments( 'str', null, $o ) );
		Guard::record( null, null, null, null );
		Guard::record( [ 'x' ], 'invoice', (object) [ 'order' => $o ], null );
		$this->assertNull( Guard::addInvoiceToOwnSend( null, null, $o ) );
		$this->assertSame( 1, Guard::noReuseDuringSend( 1, null ) );
		$this->assertSame( '', Guard::footer( $o, null, null, null ) );
		$this->assertArrayNotHasKey( Hold::KEY, $o->meta );
	}

	public function test_admin_email_untouched(): void {
		$o = $this->order();
		$this->rec( $o, '/tmp/inv.pdf' );
		$admin = new class { public function is_customer_email(): bool { return false; } };
		$in    = [ '/tmp/inv.pdf' ];
		$this->assertSame( $in, Guard::filterAttachments( $in, 'new_order', $o, $admin ) );
		$this->assertArrayNotHasKey( Hold::KEY, $o->meta );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		// Paths were forgotten: a later customer email in the same request is not stripped.
		$this->assertSame( $in, Guard::filterAttachments( $in, 'customer_completed_order', $o ) );
	}
}
