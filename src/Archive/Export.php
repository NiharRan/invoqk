<?php
namespace Invoqk\Archive;

use Invoqk\Jobs\Scheduler;

/** Steuerberater export: builds a ZIP of XML/PDF files plus index.csv in batches. */
final class Export {
	public const HOOK  = 'invoqk_archive_export';
	public const OPT   = 'invoqk_archive_export';
	public const BATCH = 200;

	public static function boot(): void {
		add_action( self::HOOK, static fn( $id, $after ) => self::run( (string) $id, (int) $after ), 10, 2 );
		add_action( 'admin_post_invoqk_archive_export', [ self::class, 'startCallback' ] );
		add_action( 'admin_post_invoqk_archive_export_download', [ self::class, 'downloadCallback' ] );
		add_action( Scheduler::SWEEP, static fn() => self::cleanup( time() ) );
	}

	/** @return string One of nozip|range|busy|started. */
	public static function begin( array $post, int $userId, int $now ): string {
		if ( ! self::available() ) {
			return 'nozip';
		}
		$range = $post['range'] ?? '';
		if ( 'this_month' === $range || 'last_month' === $range ) {
			$first = new \DateTimeImmutable( wp_date( 'Y-m-01', $now ), new \DateTimeZone( 'UTC' ) );
			if ( 'last_month' === $range ) {
				$first = $first->modify( '-1 month' );
			}
			$post = [ 'from' => $first->format( 'Y-m-d' ), 'to' => $first->format( 'Y-m-t' ) ];
		}
		$f = Page::filters( $post, $now );
		if ( ! isset( $f['from'], $f['to'] ) || $f['from'] > $f['to'] ) {
			return 'range';
		}
		return self::start( $f['from'], $f['to'], $userId, $now ) ? 'started' : 'busy';
	}

	public static function startCallback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'invoqk_archive_export' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		$code = self::begin( $_POST, get_current_user_id(), time() ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		wp_safe_redirect( admin_url( 'admin.php?page=invoqk-archive&export=' . $code ) );
		exit;
	}

	public static function downloadCallback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'invoqk_archive_export_download' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		$s    = self::state();
		$path = '';
		if ( 'ready' === $s['state'] ) {
			$path = Archive::storage()->exports() . '/' . basename( (string) ( $s['file'] ?? '' ) );
		}
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'invoqk' ), '', [ 'response' => 404 ] );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="invoqk-export-' . $s['from'] . '-' . $s['to'] . '.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

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
		$zip = $csv = '';
		try {
			$dir = Archive::storage()->exports();
			$zip = "$dir/$id.zip";
			$csv = "$dir/$id.csv";
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
			if ( true !== $z->open( $zip, \ZipArchive::CREATE ) || ! $z->addFile( $csv, 'index.csv' ) || ! $z->close() ) {
				throw new \RuntimeException( 'finish' );
			}
			unlink( $csv );
			$s['state'] = 'ready';
			$s['file']  = "$id.zip";
			update_option( self::OPT, $s, false );
		} catch ( \Throwable $e ) {
			foreach ( [ $zip, $csv ] as $f ) {
				if ( '' !== $f ) {
					@unlink( $f );
				}
			}
			update_option( self::OPT, [ 'state' => 'failed', 'at' => time() ] + $s, false );
			wc_get_logger()->error( "archive export $id failed: " . get_class( $e ) . ': ' . $e->getMessage(), [ 'source' => 'invoqk' ] );
			return;
		}
		self::notify( $s, $mail );
	}

	public static function cleanup( int $now ): void {
		try {
			$dir = Archive::storage()->exports();
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'archive export cleanup failed: ' . get_class( $e ), [ 'source' => 'invoqk' ] );
			return;
		}
		foreach ( array_merge( glob( "$dir/*.zip" ) ?: [], glob( "$dir/*.csv" ) ?: [] ) as $f ) {
			if ( filemtime( $f ) < $now - 7 * DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
		$s = self::state();
		if ( 'ready' === $s['state'] && ! is_file( "$dir/" . ( $s['file'] ?? '' ) ) ) {
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
