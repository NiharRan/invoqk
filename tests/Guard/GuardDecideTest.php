<?php
use Invoqk\Guard\Guard;
use Invoqk\Guard\Hold;
use PHPUnit\Framework\TestCase;

final class GuardDecideTest extends TestCase {
	private array $sent = [];
	private array $notes = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_log']     = [];
		$GLOBALS['invoqk_edi']     = true;
		$this->sent  = [];
		$this->notes = [];
		Guard::reset();
		Guard::$notify = fn( int $id, string $kind ) => $this->notes[] = [ $id, $kind ];
		add_filter( 'invoqk_is_pro', fn() => true );
	}

	private function order( string $status, string $state = 'waiting', int $id = 5 ): \WC_Order {
		$o = $GLOBALS['invoqk_orders'][ $id ] = new \WC_Order( $id );
		$o->meta['_invoqk_source'] = 'wpo';
		if ( 'none' === $status ) {
			$o->meta['_invoqk_source'] = 'none';
		} elseif ( 'pending' !== $status ) {
			$o->meta['_invoqk_validation'] = [ 'status' => $status, 'issues' => [
				[ 'id' => 'W1', 'severity' => 'warning' ],
				[ 'id' => 'BR-DE-1', 'severity' => 'error' ],
			] ];
		}
		Hold::set( $o, $state );
		return $o;
	}

	/** Runs send() with a recording mailer by wrapping decide through send's default is not possible, so stub WC(). */
	private function decide( int $id = 5, bool $timeout = false ): void {
		$GLOBALS['invoqk_mailer'] = function ( int $i ) { $this->sent[] = $i; };
		Guard::decide( $id, $timeout );
	}

	public function test_passed_sends_once(): void {
		$o = $this->order( 'passed' );
		$this->decide();
		$this->decide( 5, true );
		$this->assertSame( [ 5 ], $this->sent );
		$this->assertSame( 'sent', Hold::get( $o )['state'] );
		$this->assertSame( [], $this->notes );
	}

	public function test_timeout_after_result_sends_nothing(): void {
		$this->order( 'passed', 'sent' );
		$this->decide( 5, true );
		$this->assertSame( [], $this->sent );
	}

	public function test_no_guard_state_is_ignored(): void {
		$o = $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
		$this->decide();
		$this->assertSame( [], $this->sent );
		$this->assertNull( Hold::get( $o ) );
	}

	public function test_failed_block_holds_with_reason_and_notifies(): void {
		$o = $this->order( 'failed' );
		$this->decide();
		$this->assertSame( [], $this->sent );
		$this->assertSame( 'held', Hold::get( $o )['state'] );
		$this->assertSame( 'BR-DE-1', Hold::get( $o )['reason'] );
		$this->assertContains( 5, $GLOBALS['invoqk_options']['invoqk_guard_held'] );
		$this->assertSame( [ [ 5, 'held' ] ], $this->notes );
	}

	public function test_failed_warn_sends_and_notifies_warn(): void {
		$GLOBALS['invoqk_options']['invoqk_guard_mode'] = 'warn';
		$o = $this->order( 'failed' );
		$this->decide();
		$this->assertSame( [ 5 ], $this->sent );
		$this->assertSame( 'sent', Hold::get( $o )['state'] );
		$this->assertSame( [ [ 5, 'warn' ] ], $this->notes );
	}

	public function test_error_and_limit_send_skipped(): void {
		foreach ( [ 'error', 'limit' ] as $i => $st ) {
			$id = 10 + $i;
			$o  = $this->order( $st, 'waiting', $id );
			$this->decide( $id );
			$this->assertSame( 'skipped', Hold::get( $o )['state'] );
			$this->assertSame( [ $id, 'skipped' ], end( $this->notes ) );
		}
		$this->assertSame( [ 10, 11 ], $this->sent );
	}

	public function test_pending_waits_until_timeout_then_skips(): void {
		$o = $this->order( 'pending' );
		$this->decide();
		$this->assertSame( 'waiting', Hold::get( $o )['state'] );
		$this->assertSame( [], $this->sent );
		$this->decide( 5, true );
		$this->assertSame( 'skipped', Hold::get( $o )['state'] );
		$this->assertSame( [ [ 5, 'skipped' ] ], $this->notes );
	}

	public function test_none_at_timeout_notifies_no_einvoice(): void {
		$o = $this->order( 'none' );
		$this->decide();
		$this->assertSame( [], $this->sent );
		$this->decide( 5, true );
		$this->assertSame( [ [ 5, 'no_einvoice' ] ], $this->notes );
		$this->assertSame( 'skipped', Hold::get( $o )['state'] );
	}

	public function test_late_failure_on_skipped_warns_once(): void {
		$o = $this->order( 'failed', 'skipped' );
		$this->decide();
		$this->decide();
		$this->assertSame( [ [ 5, 'warn' ] ], $this->notes );
		$this->assertTrue( Hold::get( $o )['warned'] );
		$this->assertSame( [], $this->sent );
	}

	public function test_deleted_order_decide_is_quiet(): void {
		$GLOBALS['invoqk_options']['invoqk_guard_held'] = [ 99 ];
		$this->decide( 99, true );
		$this->assertSame( [], $GLOBALS['invoqk_options']['invoqk_guard_held'] );
		$this->assertSame( [], $this->notes );
		$this->assertSame( [], $this->sent );
	}

	public function test_mailer_throw_restores_state(): void {
		$o  = $this->order( 'passed' );
		$ok = Guard::send( 5, 'sent', [], function () { throw new \RuntimeException( 'SECRET' ); } );
		$this->assertFalse( $ok );
		$this->assertSame( 'waiting', Hold::get( $o )['state'] );
		$this->assertFalse( Guard::sending( 5 ) );
		$this->assertStringNotContainsString( 'SECRET', implode( '', $GLOBALS['invoqk_log'] ) );
	}

	public function test_send_refuses_final_state(): void {
		$this->order( 'passed', 'sent' );
		$this->assertFalse( Guard::send( 5, 'sent', [], fn() => $this->fail( 'mailed' ) ) );
	}

	public function test_own_send_adds_invoice_and_keeps_attachment(): void {
		$o   = $this->order( 'passed' );
		$doc = (object) [ 'order' => $o ];
		$in  = [ '/tmp/inv.pdf' ];
		$seen = [];
		Guard::record( '/tmp/inv.pdf', 'invoice', $doc, 'pdf' );
		Guard::send( 5, 'sent', [], function () use ( $o, $doc, $in, &$seen ) {
			$seen['att']   = Guard::filterAttachments( $in, 'customer_invoice', $o );
			$seen['types'] = Guard::addInvoiceToOwnSend( [], 'customer_invoice', $o );
			$seen['other'] = Guard::addInvoiceToOwnSend( [], 'customer_completed_order', $o );
			$seen['reuse'] = Guard::noReuseDuringSend( true, $doc );
		} );
		$this->assertSame( $in, $seen['att'] );
		$this->assertSame( [ 'invoice' ], $seen['types']['pdf'] );
		$this->assertSame( [ 'invoice' ], $seen['types']['xml'] );
		$this->assertSame( [], $seen['other'] );
		$this->assertFalse( $seen['reuse'] );
		$this->assertSame( [], Guard::addInvoiceToOwnSend( [], 'customer_invoice', $o ) );
		$this->assertTrue( Guard::noReuseDuringSend( true, $doc ) );
	}
}
