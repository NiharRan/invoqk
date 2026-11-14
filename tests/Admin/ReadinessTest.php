<?php
use PHPUnit\Framework\TestCase;
use Invoqk\Admin\Readiness;
use Invoqk\Validation\Record;

final class ReadinessTest extends TestCase {
	private int $n = 0;

	protected function setUp(): void {
		$GLOBALS['invoqk_orders']        = [];
		$GLOBALS['invoqk_orders_result'] = [];
		$GLOBALS['invoqk_transients']    = [];
		$GLOBALS['invoqk_options']       = [];
	}

	private function order( string $kind, array $issues = [], int $created = 0 ): \WC_Order {
		$o          = new \WC_Order( ++$this->n );
		$o->created = $created ?: 1000 + $this->n;
		if ( 'notreq' === $kind ) {
			$o->company = '';
			$o->country = 'FR';
		} elseif ( 'undetected' !== $kind ) {
			$o->meta['_invoqk_source'] = 'missing' === $kind ? 'none' : 'wp_overnight';
		}
		if ( in_array( $kind, [ 'passed', 'failed' ], true ) ) {
			$o->meta[ Record::KEY ] = [ 'status' => $kind, 'issues' => $issues ];
		} elseif ( 'limit' === $kind ) {
			$o->meta[ Record::KEY ] = [ 'status' => 'limit' ];
		}
		$GLOBALS['invoqk_orders'][ $o->id ] = $o;
		$GLOBALS['invoqk_orders_result'][]  = $o->id;
		return $o;
	}

	private function issue( string $bt, string $sev = 'error', string $fix = 'buyer_reference' ): array {
		return [ 'id' => 'x', 'bt' => [ $bt ], 'message' => 'En ' . $bt, 'messageDe' => 'De ' . $bt, 'severity' => $sev, 'fix' => $fix ];
	}

	public function test_buckets_and_score(): void {
		$this->order( 'passed' );
		$this->order( 'passed' );
		$this->order( 'failed', [ $this->issue( 'BT-10' ) ] );
		$this->order( 'missing' );
		$this->order( 'undetected' );
		$this->order( 'limit' );
		$this->order( 'notreq' );
		$this->order( 'notreq' );
		$r = Readiness::compute( 'de_DE' );
		$this->assertSame( [ 2, 1, 1, 2, 2, 6, 33 ], [ $r['passed'], $r['failed'], $r['missing'], $r['unchecked'], $r['notRequired'], $r['required'], $r['score'] ] );
		$this->assertFalse( $r['capped'] );
	}

	public function test_zero_required_score_null(): void {
		$this->order( 'notreq' );
		$r = Readiness::compute( 'de_DE' );
		$this->assertNull( $r['score'] );
		$this->assertSame( 0, $r['required'] );
	}

	public function test_top_errors_ranked_per_order_and_capped_at_5(): void {
		$this->order( 'failed', [ $this->issue( 'BT-10' ), $this->issue( 'BT-10' ) ] );
		$this->order( 'failed', [ $this->issue( 'BT-10' ) ] );
		$newest = $this->order( 'failed', [ $this->issue( 'BT-10' ), $this->issue( 'BT-1' ), $this->issue( 'BT-2' ), $this->issue( 'BT-3' ), $this->issue( 'BT-4' ), $this->issue( 'BT-5' ) ], 99999 );
		$top = Readiness::compute( 'de_DE' )['top'];
		$this->assertCount( 5, $top );
		$this->assertSame( [ 'BT-10', 3 ], [ $top[0]['code'], $top[0]['count'] ] );
		$this->assertSame( 'BT-1', $top[1]['code'] );
		$this->assertSame( 'De BT-10', $top[0]['text'] );
		$this->assertStringContainsString( (string) $newest->id, $top[0]['fix']['url'] );
	}

	public function test_warning_only_failed_adds_no_top_error(): void {
		$this->order( 'failed', [ $this->issue( 'BT-10', 'warning' ) ] );
		$r = Readiness::compute( 'de_DE' );
		$this->assertSame( 1, $r['failed'] );
		$this->assertSame( [], $r['top'] );
	}

	public function test_missing_order_skipped(): void {
		$this->order( 'passed' );
		$GLOBALS['invoqk_orders_result'][] = 9999;
		$this->assertSame( 1, Readiness::compute( 'de_DE' )['required'] );
	}

	public function test_record_put_flushes_cache(): void {
		$o = $this->order( 'passed' );
		$this->assertSame( 1, Readiness::get( 'de_DE' )['passed'] );
		Record::put( $o, [ 'status' => 'failed', 'issues' => [] ] );
		$this->assertSame( 1, Readiness::get( 'de_DE' )['failed'] );
	}

	public function test_english_locale_uses_english_text(): void {
		$this->order( 'failed', [ $this->issue( 'BT-10' ) ] );
		$this->assertSame( 'En BT-10', Readiness::get( 'en_US' )['top'][0]['text'] );
	}

	public function test_first_report_orders_outside_30_days_are_counted_once(): void {
		$this->order( 'passed' );
		$old = $this->order( 'failed', [ $this->issue( 'BT-10' ) ] );
		$GLOBALS['invoqk_orders_result'] = [ 1 ]; // order 2 is older than 30 days
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ 1, $old->id, 99 ], 'startedAt' => 1 ]; // 1 dup, 99 deleted
		$r = Readiness::compute( 'de_DE' );
		$this->assertSame( [ 1, 1, 2 ], [ $r['passed'], $r['failed'], $r['required'] ] );
		$this->assertCount( 1, $r['top'] );
	}
}
