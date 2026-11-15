<?php
namespace Invoqk\Archive;

use Invoqk\Jobs\Scheduler;

/** Steuerberater export: builds a ZIP of XML/PDF files plus index.csv in batches. */
final class Export {
	public const HOOK  = 'invoqk_archive_export';
	public const OPT   = 'invoqk_archive_export';
	public const BATCH = 200;

	public static function available(): bool {
		return class_exists( \ZipArchive::class );
	}

	public static function state(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) && $s ? $s : [ 'state' => 'none' ];
	}

	public static function start( string $from, string $to, int $userId, int $now ): bool {
		$s = self::state();
		// `at` is refreshed every batch, so only a stale "running" (crashed job) may be replaced.
		if ( 'running' === $s['state'] && (int) ( $s['at'] ?? 0 ) > $now - HOUR_IN_SECONDS ) {
			return false;
		}
		$id = bin2hex( random_bytes( 16 ) );
		update_option( self::OPT, [ 'id' => $id, 'state' => 'running', 'from' => $from, 'to' => $to, 'user' => $userId, 'at' => $now ], false );
		as_enqueue_async_action( self::HOOK, [ $id, 0 ], Scheduler::GROUP );
		return true;
	}

	public static function run( string $id, int $afterId, ?callable $mail = null ): void {
		$s = self::state();
		if ( 'running' !== $s['state'] || ( $s['id'] ?? '' ) !== $id ) {
			return;
		}
		$dir = Archive::storage()->exports();
		$zip = "$dir/$id.zip";
		$csv = "$dir/$id.csv";
		try {
			$rows = Archive::index()->after( $afterId, self::BATCH );
			$z    = new \ZipArchive();
			if ( true !== $z->open( $zip, \ZipArchive::CREATE ) ) {
				throw new \RuntimeException( 'zip' );
			}
			$used = [];
			for ( $i = 0; $i < $z->numFiles; $i++ ) {
				$used[ strtolower( pathinfo( (string) $z->getNameIndex( $i ), PATHINFO_FILENAME ) ) ] = true;
			}
			$out = '';
			if ( 0 === $afterId ) {
				$out = ExportFormat::header();
			}
			$added = 0;
			foreach ( $rows as $row ) {
				$afterId = (int) $row['id'];
				$date    = ExportFormat::date( $row );
				if ( ! empty( $row['deleted_at'] ) || $date < $s['from'] || $date > $s['to'] ) {
					continue;
				}
				$base = ExportFormat::fileBase( $row, $used );
				foreach ( [ 'xml', 'pdf' ] as $ext ) {
					if ( empty( $row[ $ext . '_key' ] ) ) {
						continue;
					}
					$path = Archive::storage()->path( $row[ $ext . '_key' ] );
					if ( null === $path || ! $z->addFile( $path, "$base.$ext" ) ) {
						throw new \RuntimeException( 'missing' );
					}
				}
				$out .= ExportFormat::line( $row );
				++$added;
			}
			// ZipArchive reads added files at close(), so a vanished file surfaces here.
			if ( ! $z->close() || ( '' !== $out && false === file_put_contents( $csv, $out, FILE_APPEND ) ) ) {
				throw new \RuntimeException( 'write' );
			}
			$s['count'] = (int) ( $s['count'] ?? 0 ) + $added;
			$s['at']    = time();
			if ( count( $rows ) === self::BATCH ) {
				update_option( self::OPT, $s, false );
				as_enqueue_async_action( self::HOOK, [ $id, $afterId ], Scheduler::GROUP );
				return;
			}
			if ( ! $z->open( $zip, \ZipArchive::CREATE ) || ! $z->addFile( $csv, 'index.csv' ) || ! $z->close() ) {
				throw new \RuntimeException( 'finish' );
			}
			unlink( $csv );
			$s['state'] = 'ready';
			$s['file']  = "$id.zip";
			update_option( self::OPT, $s, false );
		} catch ( \Throwable $e ) {
			@unlink( $zip );
			@unlink( $csv );
			update_option( self::OPT, [ 'state' => 'failed', 'at' => time() ] + $s, false );
			wc_get_logger()->error( "archive export $id failed: " . get_class( $e ) . ': ' . $e->getMessage(), [ 'source' => 'invoqk' ] );
			return;
		}
		self::notify( $s, $mail );
	}

	public static function cleanup( int $now ): void {
		$dir = Archive::storage()->exports();
		foreach ( array_merge( glob( "$dir/*.zip" ) ?: [], glob( "$dir/*.csv" ) ?: [] ) as $f ) {
			if ( filemtime( $f ) < $now - 7 * DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
		$s = self::state();
		if ( 'ready' === $s['state'] && ! is_file( "$dir/" . $s['file'] ) ) {
			update_option( self::OPT, [ 'state' => 'none' ], false );
		}
	}

	private static function notify( array $s, ?callable $mail ): void {
		$user = get_userdata( (int) $s['user'] );
		$to   = $user ? (string) ( $user->user_email ?? '' ) : '';
		if ( '' === $to ) {
			return;
		}
		try {
			$mail ??= 'wp_mail';
			$mail(
				$to,
				__( 'Invoqk: your export is ready', 'invoqk' ),
				__( 'Your Steuerberater export is ready for download:', 'invoqk' ) . "\n" . admin_url( 'admin.php?page=invoqk-archive' )
			);
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'archive export mail failed: ' . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
	}
}
