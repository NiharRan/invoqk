<?php
namespace Invoqk\Archive;

/** Row store for the archive chain. Rows are never updated except status and tombstone fields. */
interface Index {
	/** Under lock: set prev_hash and hash, insert, update the head option. Returns the stored row. @throws \RuntimeException when the lock is not obtained. */
	public function append( array $row, callable $hash ): array;
	public function find( int $id ): ?array;
	public function last(): ?array;
	public function count(): int;
	/** Rows with id > $id, ascending. */
	public function after( int $id, int $limit ): array;
	/**
	 * Filters: q (invoice number or customer prefix), from, to (Y-m-d on invoice_date), deletable + before (sent_at < before).
	 * Tombstones included, newest id first.
	 *
	 * @return array{rows: array, total: int}
	 */
	public function search( array $f, int $page, int $per ): array;
	public function maxVersion( int $orderId ): int;
	/** Key of a live row's xml or pdf with this content hash. */
	public function keyForHash( string $hash ): ?string;
	/** Live rows other than $exceptId that reference this key. */
	public function liveRefs( string $key, int $exceptId ): int;
	/** Sets validation_status on the newest row of the order. */
	public function setStatus( int $orderId, string $status ): void;
	public function tombstone( int $id, int $userId, string $reason, int $at ): void;
	/** Sum of file sizes over distinct keys of live rows. */
	public function usage(): int;
}
