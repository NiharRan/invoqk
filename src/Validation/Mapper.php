<?php
namespace Invoqk\Validation;

use Invoqk\Jobs\Scheduler;

/** Turns an API response into the stored record. Only a parsed 200 with verdict "fail" yields "failed". */
final class Mapper {
	private const MAX_ISSUES = 100;
	private const MAX_MSG    = 500;
	private const MAX_WAIT   = 21600;

	public static function map( int $http, ?array $body, ?string $retryAfter, string $hash, string $ref ): array {
		$body    = self::normalize( $body );
		$verdict = is_array( $body ) ? ( $body['verdict'] ?? null ) : null;
		if ( 200 === $http && in_array( $verdict, [ 'pass', 'fail' ], true ) ) {
			$issues = [];
			$raw    = is_array( $body['issues'] ?? null ) ? $body['issues'] : [];
			foreach ( $raw as $i ) {
				if ( is_array( $i ) ) {
					$issues[] = self::issue( $i );
				}
			}
			$rs = [];
			foreach ( [ 'name', 'version', 'date' ] as $k ) {
				if ( is_string( $body['ruleSet'][ $k ] ?? null ) ) {
					$rs[ $k ] = mb_substr( $body['ruleSet'][ $k ], 0, 100 );
				}
			}
			return [
				'status'     => 'pass' === $verdict ? 'passed' : 'failed',
				'checkedAt'  => time(),
				'hash'       => $hash,
				'ref'        => $ref,
				'ruleSet'    => $rs,
				'issues'     => array_slice( $issues, 0, self::MAX_ISSUES ),
				'issueCount' => count( $issues ),
				'error'      => null,
				'attempt'    => 1,
			];
		}
		$retryable = is_array( $body ) && true === ( $body['retryable'] ?? null );
		$code      = $retryable ? 'unavailable' : match ( true ) {
			401 === $http => 'unauthorized',
			403 === $http => 'wrong_site',
			429 === $http => 'quota_exceeded',
			in_array( $http, [ 400, 415, 422 ], true ) => 'rejected',
			default => 'unavailable', // 5xx, timeout (0), garbage 200, anything unexpected
		};
		$r = self::error( $code, $http, $hash, $ref );
		if ( 'quota_exceeded' === $code && null !== $retryAfter && ctype_digit( trim( $retryAfter ) ) ) {
			$r['error']['retryAfter'] = (int) $retryAfter;
		}
		return $r;
	}

	/** The live service says valid/invalid with errors[] and warnings[]; the record uses pass/fail with issues[]. */
	private static function normalize( ?array $body ): ?array {
		if ( ! is_array( $body ) || ! in_array( $body['verdict'] ?? null, [ 'valid', 'invalid' ], true ) ) {
			return $body;
		}
		$issues = [];
		foreach ( [ 'errors' => 'error', 'warnings' => 'warning' ] as $k => $sev ) {
			foreach ( is_array( $body[ $k ] ?? null ) ? $body[ $k ] : [] as $i ) {
				if ( is_array( $i ) ) {
					$issues[] = [ 'severity' => $sev, 'message' => $i['text'] ?? null, 'xpath' => $i['location'] ?? null ] + $i;
				}
			}
		}
		return [ 'verdict' => 'valid' === $body['verdict'] ? 'pass' : 'fail', 'issues' => $issues ] + $body;
	}

	public static function error( string $code, int $http = 0, string $hash = '', string $ref = '' ): array {
		return [
			'status'     => 'error',
			'checkedAt'  => time(),
			'hash'       => $hash,
			'ref'        => $ref,
			'ruleSet'    => [],
			'issues'     => [],
			'issueCount' => 0,
			'error'      => [ 'code' => $code, 'http' => $http, 'retryAfter' => null ],
			'attempt'    => 1,
		];
	}

	/** Seconds until retry number $attempt (1-based), null = give up. */
	public static function retryDelay( array $record, int $attempt ): ?int {
		$code = $record['error']['code'] ?? '';
		if ( 'unavailable' === $code ) {
			return Scheduler::backoff( $attempt );
		}
		$wait = $record['error']['retryAfter'] ?? null;
		if ( 'quota_exceeded' === $code && 1 === $attempt && null !== $wait ) {
			return min( (int) $wait, self::MAX_WAIT );
		}
		return null;
	}

	private static function issue( array $i ): array {
		$s = static fn( $k, int $max ) => is_string( $i[ $k ] ?? null ) ? mb_substr( $i[ $k ], 0, $max ) : null;
		$bt = is_array( $i['bt'] ?? null ) ? array_slice( array_values( array_filter( $i['bt'], 'is_string' ) ), 0, 20 ) : [];
		return [
			'id'        => $s( 'id', 100 ) ?? '',
			'bt'        => array_map( static fn( $b ) => mb_substr( $b, 0, 50 ), $bt ),
			'severity'  => 'warning' === ( $i['severity'] ?? null ) ? 'warning' : 'error',
			'xpath'     => $s( 'xpath', 500 ) ?? '',
			'message'   => $s( 'message', self::MAX_MSG ) ?? '',
			'messageDe' => $s( 'messageDe', self::MAX_MSG ),
			'fix'       => $s( 'fix', 100 ),
		];
	}
}
