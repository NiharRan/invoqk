<?php
namespace Invoqk\Archive;

/** File store for archived XML and PDF files, addressed by key. */
interface Storage {
	/** Moves/copies the file into the store and returns its key. */
	public function put( string $sourcePath, string $ext, int $time ): string;
	/** Absolute path, or null when the key is missing or invalid. */
	public function path( string $key ): ?string;
	public function delete( string $key ): void;
	/** Directory for files not yet stored. */
	public function staging(): string;
	/** Directory for Steuerberater export ZIPs. */
	public function exports(): string;
}
