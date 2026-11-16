<?php
namespace Invoqk\Tests\Fallback;

/** $wpdb stand-in for MySQL named locks: $held maps a lock name to 'me' (this connection) or any other owner. */
final class LockDb {
	public array $held = [];
	public array $queries = [];

	public function prepare( string $q, ...$a ): string {
		return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $q ), $a );
	}

	public function get_var( string $q ) {
		$this->queries[] = $q;
		if ( preg_match( "/^SELECT GET_LOCK\\( '(.+)', 0 \\)$/", $q, $m ) ) {
			if ( isset( $this->held[ $m[1] ] ) ) {
				return '0';
			}
			$this->held[ $m[1] ] = 'me';
			return '1';
		}
		if ( preg_match( "/^SELECT RELEASE_LOCK\\( '(.+)' \\)$/", $q, $m ) && 'me' === ( $this->held[ $m[1] ] ?? null ) ) {
			unset( $this->held[ $m[1] ] );
			return '1';
		}
		return '0';
	}
}
