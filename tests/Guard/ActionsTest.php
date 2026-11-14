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
}
