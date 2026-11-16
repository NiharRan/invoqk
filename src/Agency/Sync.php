<?php
namespace Invoqk\Agency;

use Invoqk\Admin\Readiness;
use Invoqk\Jobs\Scheduler;
use Invoqk\Licensing\Api;
use Invoqk\Licensing\License;
use Invoqk\Sources\Registry;

/**
 * Pushes this shop's aggregate readiness to the agency dashboard. Only counts, rule codes and German rule texts leave the shop:
 * no order ids, no buyer data, no admin URLs (the `fix` link of Readiness is dropped on purpose).
 */
final class Sync {
	public const LAST  = 'invoqk_agency_synced_at';
	public const EVERY = 21600; // 6 h; the hourly sweep only checks the clock

	public static function boot(): void {
		add_action( Scheduler::SWEEP, static fn() => self::syncIfStale( time() ) );
	}

	public static function syncIfStale( int $now, ?callable $transport = null ): void {
		$l = License::get();
		if ( 'agency' !== $l['plan'] || ! License::activeAt( $l, $now ) || $now - (int) get_option( self::LAST, 0 ) < self::EVERY ) {
			return;
		}
		$res = Api::call( 'agency/sync', self::payload( $now ), true, $transport );
		if ( 200 === $res['http'] ) {
			update_option( self::LAST, $now, false );
		}
	}

	/** @return array<string,mixed> */
	public static function payload( int $now ): array {
		$r = Readiness::get( 'de_DE' );
		return [
			'sent_at'   => $now,
			'version'   => INVOQK_VERSION,
			'sources'   => array_values( array_map( static fn( $a ) => $a->id(), array_filter( Registry::default()->plugins(), static fn( $a ) => $a->isAvailable() ) ) ),
			'counts'    => array_intersect_key( $r, array_flip( [ 'passed', 'failed', 'missing', 'unchecked', 'notRequired', 'required' ] ) ),
			'score'     => $r['score'],
			'capped'    => $r['capped'],
			'top'       => array_map( static fn( $t ) => [ 'code' => $t['code'], 'text' => $t['text'], 'count' => $t['count'] ], $r['top'] ),
		];
	}
}
