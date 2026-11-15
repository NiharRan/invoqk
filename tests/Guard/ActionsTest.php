<?php
use Invoqk\Guard\Actions;
use Invoqk\Guard\Guard;
use Invoqk\Guard\Hold;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase {
	private array $sent = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_can']     = true;
		$GLOBALS['invoqk_nonce_ok'] = true;
		$this->sent = [];
		Guard::reset();
		Actions::$mailer = fn( int $i ) => $this->sent[] = $i;
	}

	protected function tearDown(): void {
		Actions::$mailer = null;
		unset( $GLOBALS['invoqk_can'], $GLOBALS['invoqk_nonce_ok'], $_REQUEST['order_id'] );
	}

	private function held( string $state = 'held' ): \WC_Order {
		$o = $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
		$o->meta['_invoqk_source'] = 'wpo';
		Hold::set( $o, $state, [ 'reason' => 'BR-DE-1' ] );
		return $o;
	}

	private function validate( string $status, array $issues = [] ): callable {
		return function ( int $id ) use ( $status, $issues ) {
			$GLOBALS['invoqk_orders'][ $id ]->meta['_invoqk_validation'] = [ 'status' => $status, 'issues' => $issues ];
		};
	}

	public function test_resend_passed_sends_once(): void {
		$o = $this->held();
		$this->assertSame( 'sent', Actions::resend( 5, $this->validate( 'passed' ), Actions::$mailer ) );
		$this->assertSame( 'noop', Actions::resend( 5, $this->validate( 'passed' ), Actions::$mailer ) );
		$this->assertSame( [ 5 ], $this->sent );
		$this->assertSame( 'sent', Hold::get( $o )['state'] );
	}

	public function test_resend_returns_sent_when_validation_already_sent_it(): void {
		$o = $this->held();
		$GLOBALS['invoqk_mailer'] = fn( int $i ) => $this->sent[] = $i;
		$validate = function ( int $id ) {
			$this->validate( 'passed' )( $id );
			Guard::decide( $id ); // what the invoqk_validated hook does inside Job::handle
		};
		$this->assertSame( 'sent', Actions::resend( 5, $validate, Actions::$mailer ) );
		$this->assertSame( [ 5 ], $this->sent );
		$this->assertSame( 'sent', Hold::get( $o )['state'] );
	}

	public function test_resend_still_failing_stays_held_with_new_reason(): void {
		$o = $this->held();
		$issues = [ [ 'id' => 'BR-CO-9', 'severity' => 'error' ] ];
		$this->assertSame( 'failed', Actions::resend( 5, $this->validate( 'failed', $issues ), Actions::$mailer ) );
		$this->assertSame( 'held', Hold::get( $o )['state'] );
		$this->assertSame( 'BR-CO-9', Hold::get( $o )['reason'] );
		$this->assertSame( [], $this->sent );
	}

	public function test_resend_not_held_is_noop(): void {
		$o = $this->held( 'waiting' );
		$this->assertSame( 'noop', Actions::resend( 5, $this->validate( 'passed' ), Actions::$mailer ) );
		$this->assertSame( 'waiting', Hold::get( $o )['state'] );
		$this->assertSame( [], $this->sent );
	}

	public function test_release_logs_user_and_time_and_note(): void {
		$o = $this->held();
		$this->assertTrue( Actions::release( 5, 7, Actions::$mailer ) );
		$h = Hold::get( $o );
		$this->assertSame( 'released', $h['state'] );
		$this->assertSame( 7, $h['by'] );
		$this->assertEqualsWithDelta( time(), $h['at'], 5 );
		$this->assertSame( [ 'Invoice sent despite validation errors by T.' ], $o->notes );
		$this->assertSame( [ 5 ], $this->sent );
	}

	public function test_release_twice_sends_once(): void {
		$this->held();
		Actions::release( 5, 7, Actions::$mailer );
		$this->assertFalse( Actions::release( 5, 7, Actions::$mailer ) );
		$this->assertSame( [ 5 ], $this->sent );
	}

	public function test_callbacks_reject_without_cap_or_nonce(): void {
		$o = $this->held();
		$before = Hold::get( $o );
		$_REQUEST['order_id'] = '5';
		foreach ( [ 'resendCallback', 'releaseCallback' ] as $cb ) {
			foreach ( [ 'invoqk_can', 'invoqk_nonce_ok' ] as $flag ) {
				$GLOBALS['invoqk_can'] = $GLOBALS['invoqk_nonce_ok'] = true;
				$GLOBALS[ $flag ] = false;
				try {
					Actions::$cb();
					$this->fail( 'expected die' );
				} catch ( \InvoqkTestDie $e ) {
					$this->assertSame( 403, $e->getCode() );
				}
				$this->assertSame( $before, Hold::get( $o ) );
				$this->assertSame( [], $this->sent );
			}
		}
	}

	public function test_box_held_has_both_links_with_nonces_and_confirm(): void {
		$h = Actions::box( $this->held() );
		$this->assertStringContainsString( 'Held by Invoqk', $h );
		$this->assertStringContainsString( 'action=invoqk_guard_resend&amp;order_id=5&amp;_wpnonce=nonce-invoqk_guard_resend_5', $h );
		$this->assertStringContainsString( 'action=invoqk_guard_release&amp;order_id=5&amp;_wpnonce=nonce-invoqk_guard_release_5', $h );
		$this->assertStringContainsString( 'onclick="return confirm(', $h );
		$this->assertStringContainsString( 'Send this invoice although it failed validation?', $h );
	}

	public function test_order_box_shows_links_for_held_order_reclassified_not_required(): void {
		$o          = $this->held();
		$o->company = ''; // B2C now: not required
		ob_start();
		\Invoqk\Admin\OrderBox::show( $o );
		$h = ob_get_clean();
		$this->assertStringContainsString( 'E-invoice not required', $h );
		$this->assertStringContainsString( 'Fix and resend', $h );
		$this->assertStringContainsString( 'action=invoqk_guard_release', $h );
	}

	public function test_box_states_text(): void {
		$o = $this->held( 'waiting' );
		$this->assertStringContainsString( 'Waiting for validation', Actions::box( $o ) );
		Hold::set( $o, 'sent' );
		$this->assertStringContainsString( 'Invoice sent on ', Actions::box( $o ) );
		Hold::set( $o, 'skipped' );
		$this->assertStringContainsString( 'Check skipped – invoice sent without validation', Actions::box( $o ) );
		Hold::set( $o, 'released', [ 'by' => 7, 'at' => 0 ] );
		$this->assertStringContainsString( 'Sent despite errors by T on 1970-01-01 00:00', Actions::box( $o ) );
		$this->assertSame( '', Actions::box( new \WC_Order( 9 ) ) );
	}

	public function test_box_escapes_user_name(): void {
		$o = $this->held( 'released' );
		$GLOBALS['invoqk_user_name'] = '<b>x</b>';
		$h = Actions::box( $o );
		unset( $GLOBALS['invoqk_user_name'] );
		$this->assertStringNotContainsString( '<b>x', $h );
		$this->assertStringContainsString( '&lt;b&gt;x', $h );
	}

	public function test_notice_caps_at_5_and_counts_rest(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			Hold::set( $GLOBALS['invoqk_orders'][ $i ] = new \WC_Order( $i ), 'held' );
		}
		$h = Actions::notice();
		$this->assertStringContainsString( '7 invoices held by Invoqk', $h );
		$this->assertSame( 5, substr_count( $h, '<a ' ) );
		$this->assertStringContainsString( 'and 2 more', $h );
	}

	public function test_notice_empty_without_cap_or_held(): void {
		$this->assertSame( '', Actions::notice() );
		Hold::set( $GLOBALS['invoqk_orders'][1] = new \WC_Order( 1 ), 'held' );
		$GLOBALS['invoqk_can'] = false;
		$this->assertSame( '', Actions::notice() );
	}
}
