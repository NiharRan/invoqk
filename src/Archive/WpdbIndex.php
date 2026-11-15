<?php
namespace Invoqk\Archive;

/** Index on a custom table. Covered by the live smoke test, not by unit tests. */
final class WpdbIndex implements Index {
	public const SCHEMA = '1';
	private const LOCK  = 'invoqk_archive';
	private const COLS  = [
		'order_id', 'version', 'invoice_number', 'invoice_date', 'customer', 'net', 'vat', 'gross', 'currency',
		'validation_status', 'email_id', 'sent_at', 'sent_confirmed', 'xml_key', 'xml_hash', 'xml_size',
		'pdf_key', 'pdf_hash', 'pdf_size', 'prev_hash', 'hash', 'deleted_at', 'deleted_by', 'delete_reason',
	];

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'invoqk_archive';
	}

	public static function ensure(): void {
		if ( self::SCHEMA === get_option( 'invoqk_archive_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			version int(10) unsigned NOT NULL,
			invoice_number varchar(64) NOT NULL DEFAULT '',
			invoice_date date DEFAULT NULL,
			customer varchar(255) NOT NULL DEFAULT '',
			net decimal(18,2) DEFAULT NULL,
			vat decimal(18,2) DEFAULT NULL,
			gross decimal(18,2) DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT '',
			validation_status varchar(32) DEFAULT NULL,
			email_id varchar(64) DEFAULT NULL,
			sent_at bigint(20) unsigned DEFAULT NULL,
			sent_confirmed tinyint(1) NOT NULL DEFAULT 1,
			xml_key varchar(255) DEFAULT NULL,
			xml_hash char(64) DEFAULT NULL,
			xml_size bigint(20) unsigned DEFAULT NULL,
			pdf_key varchar(255) DEFAULT NULL,
			pdf_hash char(64) DEFAULT NULL,
			pdf_size bigint(20) unsigned DEFAULT NULL,
			prev_hash char(64) NOT NULL,
			hash char(64) NOT NULL,
			deleted_at bigint(20) unsigned DEFAULT NULL,
			deleted_by bigint(20) unsigned DEFAULT NULL,
			delete_reason text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_version (order_id,version),
			KEY invoice_number (invoice_number),
			KEY invoice_date (invoice_date),
			KEY customer (customer(32)),
			KEY sent_at (sent_at),
			KEY xml_hash (xml_hash),
			KEY pdf_hash (pdf_hash)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'invoqk_archive_db', self::SCHEMA, false );
	}

	public function append( array $row, callable $hash ): array {
		global $wpdb;
		self::ensure();
		$t = self::table();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', self::LOCK ) ) ) {
			throw new \RuntimeException( 'invoqk archive lock not obtained' );
		}
		try {
			$row              = Archive::normalise( $row );
			$last             = $this->last();
			$row['prev_hash'] = $last['hash'] ?? str_repeat( '0', 64 );
			$row['hash']      = $hash( $row );
			$data             = array_intersect_key( $row, array_flip( self::COLS ) );
			if ( false === $wpdb->insert( $t, $data ) ) {
				throw new \RuntimeException( 'invoqk archive insert failed' );
			}
			$row['id'] = (int) $wpdb->insert_id;
			update_option( 'invoqk_archive_head', [ 'id' => $row['id'], 'hash' => $row['hash'], 'count' => $this->count() ], false );
			return $row;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) );
		}
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $r ? Archive::normalise( $r ) : null;
	}

	public function last(): ?array {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 1", ARRAY_A );
		return $r ? Archive::normalise( $r ) : null;
	}

	public function count(): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
	}

	public function after( int $id, int $limit ): array {
		global $wpdb;
		$t = self::table();
		return array_map( [ Archive::class, 'normalise' ], $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT %d", $id, $limit ), ARRAY_A ) ?: [] );
	}

	public function search( array $f, int $page, int $per ): array {
		global $wpdb;
		$t     = self::table();
		$where = [ '1=1' ];
		if ( ! empty( $f['q'] ) ) {
			$like    = $wpdb->esc_like( (string) $f['q'] ) . '%';
			$where[] = $wpdb->prepare( '( invoice_number LIKE %s OR customer LIKE %s )', $like, $like );
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = $wpdb->prepare( 'invoice_date >= %s', $f['from'] );
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = $wpdb->prepare( 'invoice_date <= %s', $f['to'] );
		}
		if ( ! empty( $f['deletable'] ) && isset( $f['before'] ) ) {
			$where[] = $wpdb->prepare( 'sent_at IS NOT NULL AND sent_at < %d', $f['before'] );
		}
		$w     = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$w}" );
		$rows  = array_map( [ Archive::class, 'normalise' ], $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $per, max( 0, $page - 1 ) * $per ), ARRAY_A ) ?: [] );
		return [ 'rows' => $rows, 'total' => $total ];
	}

	public function maxVersion( int $orderId ): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$t} WHERE order_id = %d", $orderId ) );
	}

	public function keyForHash( string $hash ): ?string {
		global $wpdb;
		$t   = self::table();
		$key = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT IF(xml_hash = %s, xml_key, pdf_key) FROM {$t} WHERE deleted_at IS NULL AND (xml_hash = %s OR pdf_hash = %s) ORDER BY id ASC LIMIT 1",
				$hash,
				$hash,
				$hash
			)
		);
		return $key ?: null;
	}

	public function liveRefs( string $key, int $exceptId ): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE deleted_at IS NULL AND id <> %d AND (xml_key = %s OR pdf_key = %s)", $exceptId, $key, $key ) );
	}

	public function setStatus( int $orderId, string $status ): void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET validation_status = %s WHERE order_id = %d ORDER BY id DESC LIMIT 1", $status, $orderId ) );
	}

	public function tombstone( int $id, int $userId, string $reason, int $at ): void {
		global $wpdb;
		$wpdb->update( self::table(), [ 'deleted_at' => $at, 'deleted_by' => $userId, 'delete_reason' => $reason ], [ 'id' => $id ] );
	}

	public function usage(): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(s), 0) FROM (
				SELECT MAX(xml_size) s FROM {$t} WHERE deleted_at IS NULL AND xml_key IS NOT NULL GROUP BY xml_key
				UNION ALL
				SELECT MAX(pdf_size) s FROM {$t} WHERE deleted_at IS NULL AND pdf_key IS NOT NULL GROUP BY pdf_key
			) u"
		);
	}
}
