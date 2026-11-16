<?php
namespace Invoqk\Fallback;

/** MySQL named lock (as Archive\WpdbIndex uses): atomic, and released by MySQL if the connection dies. Names stay at 64 chars or fewer. */
final class Lock {
	public static function acquire( string $name ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) );
	}

	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
	}
}
