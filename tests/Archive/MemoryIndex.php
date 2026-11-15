<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Index;

/** Array-backed Index with the same contract as WpdbIndex. */
class MemoryIndex implements Index {
	/** @var array<int, array> */
	public array $rows = [];

	public function append( array $row, callable $hash ): array {
		$row              = Archive::normalise( $row );
		$last             = $this->last();
		$row['prev_hash'] = $last['hash'] ?? str_repeat( '0', 64 );
		$row['hash']      = $hash( $row );
		$row['id']        = ( $last['id'] ?? 0 ) + 1;
		$this->rows[ $row['id'] ] = $row;
		update_option( 'invoqk_archive_head', [ 'id' => $row['id'], 'hash' => $row['hash'], 'count' => count( $this->rows ) ], false );
		return $row;
	}

	public function find( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function last(): ?array {
		return $this->rows ? end( $this->rows ) : null;
	}

	public function count(): int {
		return count( $this->rows );
	}

	public function after( int $id, int $limit ): array {
		return array_slice( array_values( array_filter( $this->rows, fn( $r ) => $r['id'] > $id ) ), 0, $limit );
	}

	public function search( array $f, int $page, int $per ): array {
		$q    = (string) ( $f['q'] ?? '' );
		$rows = array_filter(
			$this->rows,
			function ( $r ) use ( $f, $q ) {
				$d = $r['invoice_date'] ?? '';
				return ( '' === $q || 0 === stripos( (string) ( $r['invoice_number'] ?? '' ), $q ) || 0 === stripos( (string) ( $r['customer'] ?? '' ), $q ) )
					&& ( empty( $f['from'] ) || $d >= $f['from'] )
					&& ( empty( $f['to'] ) || $d <= $f['to'] )
					&& ( empty( $f['deletable'] ) || ! isset( $f['before'] ) || ( isset( $r['sent_at'] ) && $r['sent_at'] < $f['before'] ) );
			}
		);
		$rows = array_reverse( array_values( $rows ) );
		return [ 'rows' => array_slice( $rows, max( 0, $page - 1 ) * $per, $per ), 'total' => count( $rows ) ];
	}

	public function maxVersion( int $orderId ): int {
		$v = array_column( array_filter( $this->rows, fn( $r ) => $r['order_id'] === $orderId ), 'version' );
		return $v ? max( $v ) : 0;
	}

	public function keyForHash( string $hash ): ?string {
		foreach ( $this->live() as $r ) {
			foreach ( [ 'xml', 'pdf' ] as $t ) {
				if ( ( $r[ $t . '_hash' ] ?? null ) === $hash ) {
					return $r[ $t . '_key' ] ?? null;
				}
			}
		}
		return null;
	}

	public function liveRefs( string $key, int $exceptId ): int {
		return count( array_filter( $this->live(), fn( $r ) => $r['id'] !== $exceptId && ( ( $r['xml_key'] ?? null ) === $key || ( $r['pdf_key'] ?? null ) === $key ) ) );
	}

	public function setStatus( int $orderId, string $status ): void {
		foreach ( array_reverse( $this->rows, true ) as $id => $r ) {
			if ( $r['order_id'] === $orderId ) {
				$this->rows[ $id ]['validation_status'] = $status;
				return;
			}
		}
	}

	public function tombstone( int $id, int $userId, string $reason, int $at ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]['deleted_at']    = $at;
			$this->rows[ $id ]['deleted_by']    = $userId;
			$this->rows[ $id ]['delete_reason'] = $reason;
		}
	}

	public function usage(): int {
		$sizes = [];
		foreach ( $this->live() as $r ) {
			foreach ( [ 'xml', 'pdf' ] as $t ) {
				if ( ! empty( $r[ $t . '_key' ] ) ) {
					$sizes[ $r[ $t . '_key' ] ] = (int) ( $r[ $t . '_size' ] ?? 0 );
				}
			}
		}
		return array_sum( $sizes );
	}

	private function live(): array {
		return array_filter( $this->rows, fn( $r ) => empty( $r['deleted_at'] ) );
	}
}
