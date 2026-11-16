<?php
namespace Invoqk\Fallback;

/** MySQL named lock (as Archive\WpdbIndex uses): atomic, and released by MySQL if the connection dies. */
final class Lock {
	/** GET_LOCK names are server-wide, so each site gets its own namespace; MySQL caps names at 64 chars. */
	public static function name( string $name ): string {
		global $wpdb;
		return substr( 'invoqk_' . md5( DB_NAME . $wpdb->prefix ) . '_' . $name, 0, 64 );
	}

	public static function acquire( string $name ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::name( $name ) ) );
	}

	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::name( $name ) ) );
	}
}
