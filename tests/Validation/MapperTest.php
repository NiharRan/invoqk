<?php
use Invoqk\Validation\Mapper;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase {
	private function issue( int $i = 1, string $msg = 'm' ): array {
		return [ 'id' => "BR-$i", 'bt' => [ 'BT-1' ], 'severity' => 'warning', 'xpath' => '/x', 'message' => $msg, 'messageDe' => null, 'fix' => null ];
	}

	public function test_pass_maps_to_passed(): void {
		$rs = [ 'name' => 'XRechnung', 'version' => '3.0.2', 'date' => '2026-08-31' ];
		$r  = Mapper::map( 200, [ 'verdict' => 'pass', 'ruleSet' => $rs, 'issues' => [ $this->issue() ] ], null, 'h', '12' );
		$this->assertSame( 'passed', $r['status'] );
		$this->assertCount( 1, $r['issues'] );
		$this->assertSame( $rs, $r['ruleSet'] );
		$this->assertSame( 'h', $r['hash'] );
		$this->assertSame( '12', $r['ref'] );
		$this->assertSame( 1, $r['attempt'] );
		$this->assertNull( $r['error'] );
	}

	public function test_fail_maps_to_failed(): void {
		$r = Mapper::map( 200, [ 'verdict' => 'fail', 'issues' => [ $this->issue() ] ], null, 'h', '1' );
		$this->assertSame( 'failed', $r['status'] );
	}

	public function test_500_503_timeout_are_error_unavailable(): void {
		foreach ( [ 500, 503, 0 ] as $http ) {
			$r = Mapper::map( $http, null, null, 'h', '1' );
			$this->assertSame( 'error', $r['status'] );
			$this->assertSame( 'unavailable', $r['error']['code'] );
		}
	}

	public function test_401_403_422(): void {
		$this->assertSame( 'unauthorized', Mapper::map( 401, null, null, '', '' )['error']['code'] );
		$this->assertSame( 'wrong_site', Mapper::map( 403, null, null, '', '' )['error']['code'] );
		foreach ( [ 400, 415, 422 ] as $http ) {
			$this->assertSame( 'rejected', Mapper::map( $http, null, null, '', '' )['error']['code'] );
		}
	}

	public function test_429_keeps_retry_after(): void {
		$r = Mapper::map( 429, null, '120', '', '' );
		$this->assertSame( 'quota_exceeded', $r['error']['code'] );
		$this->assertSame( 120, $r['error']['retryAfter'] );
	}

	public function test_body_retryable_true_is_unavailable(): void {
		foreach ( [ 400, 422, 429 ] as $http ) {
			$this->assertSame( 'unavailable', Mapper::map( $http, [ 'retryable' => true ], null, '', '' )['error']['code'] );
		}
	}

	public function test_garbage_200_is_unavailable(): void {
		foreach ( [ null, [ 'issues' => [] ], [ 'verdict' => 'maybe' ] ] as $body ) {
			$r = Mapper::map( 200, $body, null, '', '' );
			$this->assertSame( 'error', $r['status'] );
			$this->assertSame( 'unavailable', $r['error']['code'] );
		}
	}

	public function test_issue_cap(): void {
		$issues = [];
		for ( $i = 0; $i < 140; $i++ ) { $issues[] = $this->issue( $i, str_repeat( 'a', 600 ) ); }
		$r = Mapper::map( 200, [ 'verdict' => 'fail', 'issues' => $issues ], null, '', '' );
		$this->assertCount( 100, $r['issues'] );
		$this->assertSame( 140, $r['issueCount'] );
		$this->assertSame( 500, strlen( $r['issues'][0]['message'] ) );
	}

	public function test_odd_issue_shapes(): void {
		$odd = [ 'id' => 'X', 'bt' => 'BT-1', 'message' => 'm' ];
		$r   = Mapper::map( 200, [ 'verdict' => 'fail', 'issues' => [ 'junk', 5, $odd ] ], null, '', '' );
		$this->assertCount( 1, $r['issues'] );
		$this->assertSame( [], $r['issues'][0]['bt'] );
		$r = Mapper::map( 200, [ 'verdict' => 'pass', 'issues' => 'nope' ], null, '', '' );
		$this->assertSame( [], $r['issues'] );
		$this->assertSame( 0, $r['issueCount'] );
	}

	public function test_retry_delay(): void {
		$u = Mapper::error( 'unavailable', 500 );
		$this->assertSame( 60, Mapper::retryDelay( $u, 1 ) );
		$this->assertSame( 300, Mapper::retryDelay( $u, 2 ) );
		$this->assertSame( 1800, Mapper::retryDelay( $u, 3 ) );
		$this->assertNull( Mapper::retryDelay( $u, 4 ) );

		$q = Mapper::map( 429, null, '120', '', '' );
		$this->assertSame( 120, Mapper::retryDelay( $q, 1 ) );
		$this->assertNull( Mapper::retryDelay( $q, 2 ) );
		$q = Mapper::map( 429, null, '99999', '', '' );
		$this->assertSame( 21600, Mapper::retryDelay( $q, 1 ) );

		foreach ( [ 'unauthorized', 'wrong_site', 'rejected', 'no_key', 'unreadable' ] as $code ) {
			$this->assertNull( Mapper::retryDelay( Mapper::error( $code ), 1 ) );
		}
	}
}
