<?php
namespace Invoqk\Archive;

/** Archive storage meter: label, admin notice and a one-mail-per-crossing warning. Archiving never stops. */
final class Usage {
	public const LIMIT  = 5 * 1024 ** 3;
	public const OPTION = 'invoqk_archive_warned';
	public const CACHE  = 'invoqk_archive_usage';

	/** Cached bytes in use; check() refreshes after each append, Actions::delete drops it. */
	public static function used(): int {
		$v = get_transient( self::CACHE );
		return false !== $v ? (int) $v : self::refresh();
	}

	private static function refresh(): int {
		$used = Archive::index()->usage();
		set_transient( self::CACHE, $used, HOUR_IN_SECONDS );
		return $used;
	}

	public static function level( int $used ): int {
		return $used >= self::LIMIT ? 100 : ( $used >= 0.8 * self::LIMIT ? 80 : 0 );
	}

	public static function label( int $used ): string {
		/* translators: 1: used GB, 2: limit GB */
		return sprintf( __( '%1$s GB of %2$s GB', 'invoqk' ), number_format( $used / 1024 ** 3, 1, ',', '.' ), '5' );
	}

	public static function check( ?callable $mail = null ): void {
		$used   = self::refresh();
		$level  = self::level( $used );
		$stored = (int) get_option( self::OPTION, 0 );
		if ( $level > $stored ) {
			try {
				$mail ??= 'wp_mail';
				$ok     = (bool) $mail(
					get_option( 'admin_email' ),
					/* translators: %d: percent */
					sprintf( __( 'Invoqk: archive at %d %%', 'invoqk' ), $level ),
					self::label( $used ) . "\n" . admin_url( 'admin.php?page=invoqk&tab=archive' )
				);
			} catch ( \Throwable $e ) {
				$ok = false;
			}
			if ( $ok ) {
				update_option( self::OPTION, $level, false );
			} else {
				wc_get_logger()->error( 'archive usage mail failed, will retry', [ 'source' => 'invoqk' ] );
			}
		} elseif ( 0 === $level && $stored > 0 ) {
			update_option( self::OPTION, 0, false );
		}
	}

	public static function notice(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}
		$used  = self::used();
		$level = self::level( $used );
		if ( 0 === $level ) {
			return '';
		}
		/* translators: %s: "1,2 GB of 5 GB" */
		$text = sprintf( __( 'Invoqk archive uses %s.', 'invoqk' ), self::label( $used ) );
		if ( 100 === $level ) {
			$text .= ' ' . __( 'Archiving continues; consider your storage plan.', 'invoqk' );
		}
		return '<div class="notice ' . ( 100 === $level ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $text ) . '</p></div>';
	}
}
