<?php
namespace Invoqk\Archive;

/** Admin-post endpoints of the archive page. Thin callback() over testable delete(). */
final class Actions {
	public const RETENTION_OPTION = 'invoqk_archive_retention_years';

	public static function boot(): void {
		add_action( 'admin_post_invoqk_archive_delete', [ self::class, 'callback' ] );
	}

	public static function callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'invoqk_archive_delete' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		$ids     = array_map( 'absint', array_filter( (array) wp_unslash( $_POST['ids'] ?? [] ), 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		$confirm = sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$reason  = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$r       = self::delete( $ids, get_current_user_id(), $confirm, $reason, time() );
		wp_safe_redirect( admin_url( 'admin.php?page=invoqk-archive&deleted=' . $r['deleted'] . '&refused=' . $r['refused'] ) );
		exit;
	}

	/** @return array{deleted:int, refused:int} Tombstoned and unknown ids are skipped, not counted. */
	public static function delete( array $ids, int $userId, string $confirm, string $reason, int $now ): array {
		$ix  = Archive::index();
		$out = [ 'deleted' => 0, 'refused' => 0 ];
		$reason = trim( $reason );
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$row = $ix->find( $id );
			if ( ! $row || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			if ( ! self::deletable( $row, $now ) && ( __( 'DELETE', 'invoqk' ) !== $confirm || '' === $reason ) ) {
				++$out['refused'];
				continue;
			}
			$ix->tombstone( $id, $userId, $reason, $now );
			wc_get_logger()->info( "archive delete #{$id} by user {$userId}", [ 'source' => 'invoqk' ] );
			++$out['deleted'];
			// Tombstone first: a failed unlink leaves an orphan file, never a live row without a record.
			foreach ( array_unique( array_filter( [ $row['xml_key'] ?? null, $row['pdf_key'] ?? null ] ) ) as $key ) {
				try {
					if ( 0 === $ix->liveRefs( $key, $id ) ) {
						Archive::storage()->delete( $key );
					}
				} catch ( \Throwable $e ) {
					wc_get_logger()->info( "archive delete #{$id} file kept: " . get_class( $e ), [ 'source' => 'invoqk' ] );
				}
			}
		}
		return $out;
	}

	public static function retentionYears(): int {
		return self::sanitizeYears( get_option( self::RETENTION_OPTION, 8 ) );
	}

	public static function sanitizeYears( $v ): int {
		return is_numeric( $v ) ? max( 1, min( 30, (int) $v ) ) : 8;
	}

	public static function deletable( array $row, int $now ): bool {
		return isset( $row['sent_at'] ) && $row['sent_at'] + self::retentionYears() * 365 * DAY_IN_SECONDS < $now;
	}
}
