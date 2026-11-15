<?php
namespace Invoqk\Archive;

use Invoqk\Jobs\Scheduler;

final class Verify {
	public const HOOK  = 'invoqk_archive_verify';
	public const BATCH = 200;
	private const OPT  = 'invoqk_archive_verify';

	public static function start(): void {
		$r = self::result();
		// A run saves `at` every batch, so only a stale "running" (crashed job) may be restarted.
		if ( 'running' === $r['state'] && ( $r['at'] ?? 0 ) > time() - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::OPT, [ 'state' => 'running', 'checked' => 0, 'at' => time() ], false );
		as_enqueue_async_action( self::HOOK, [ 0, '' ], Scheduler::GROUP );
	}

	public static function result(): array {
		$r = get_option( self::OPT, [] );
		return is_array( $r ) && $r ? $r : [ 'state' => 'none', 'checked' => 0 ];
	}

	public static function run( int $afterId, string $prevHash ): void {
		$prev = $afterId > 0 ? $prevHash : str_repeat( '0', 64 );
		$res  = self::result();
		$n    = (int) $res['checked'];
		$rows = Archive::index()->after( $afterId, self::BATCH );
		foreach ( $rows as $row ) {
			$kind = self::check( $row, $prev );
			if ( $kind ) {
				self::save( [ 'state' => 'broken', 'checked' => $n, 'brokenId' => (int) $row['id'], 'kind' => $kind ] );
				return;
			}
			$prev = $row['hash'];
			++$n;
			$afterId = (int) $row['id'];
		}
		if ( count( $rows ) === self::BATCH ) {
			self::save( [ 'state' => 'running', 'checked' => $n ] );
			as_enqueue_async_action( self::HOOK, [ $afterId, $prev ], Scheduler::GROUP );
			return;
		}
		$head = get_option( 'invoqk_archive_head', [] );
		$last = Archive::index()->last();
		if ( (int) ( $head['id'] ?? 0 ) !== (int) ( $last['id'] ?? 0 ) || (string) ( $head['hash'] ?? '' ) !== (string) ( $last['hash'] ?? '' ) || (int) ( $head['count'] ?? 0 ) !== Archive::index()->count() ) {
			self::save( [ 'state' => 'broken', 'checked' => $n, 'brokenId' => (int) ( $last['id'] ?? 0 ), 'kind' => 'head' ] );
			return;
		}
		self::save( [ 'state' => 'ok', 'checked' => $n ] );
	}

	private static function check( array $row, string $prev ): ?string {
		if ( ! hash_equals( (string) $row['hash'], Chain::hash( $row ) ) ) {
			return 'row';
		}
		if ( (string) $row['prev_hash'] !== $prev ) {
			return 'link';
		}
		if ( ! empty( $row['deleted_at'] ) ) {
			return null;
		}
		foreach ( [ 'xml', 'pdf' ] as $t ) {
			$key = $row[ $t . '_key' ] ?? null;
			if ( ! $key ) {
				if ( ! empty( $row[ $t . '_hash' ] ) ) {
					return 'missing';
				}
				continue;
			}
			$path = Archive::storage()->path( $key );
			if ( null === $path ) {
				return 'missing';
			}
			if ( ! hash_equals( (string) ( $row[ $t . '_hash' ] ?? '' ), (string) hash_file( 'sha256', $path ) ) ) {
				return 'file';
			}
		}
		return null;
	}

	private static function save( array $r ): void {
		update_option( self::OPT, $r + [ 'at' => time() ], false );
	}
}
