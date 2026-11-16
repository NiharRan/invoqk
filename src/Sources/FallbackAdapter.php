<?php
namespace Invoqk\Sources;

use Invoqk\Fallback\Store;
use Invoqk\Validation\Limit;

/** Invoices Invoqk generated itself when no invoice plugin made one. Stays readable after Pro ends. */
final class FallbackAdapter implements SourceAdapter {
	public function id(): string {
		return 'invoqk_fallback';
	}

	public function label(): string {
		return 'Invoqk fallback';
	}

	public function isAvailable(): bool {
		return Limit::isPro() || (bool) get_option( Store::OPTION );
	}

	public function find( \WC_Order $order ): ?InvoiceFile {
		$path = Store::path( $order );
		return $path ? new InvoiceFile( $path, 'application/xml', 'cii', false ) : null;
	}

	public function read( \WC_Order $order, InvoiceFile $file ): ?string {
		$bytes = is_string( $file->reference ) ? @file_get_contents( $file->reference ) : false;
		return false === $bytes ? null : $bytes;
	}

	public function findCreditNotes( \WC_Order $order ): array {
		return [];
	}
}
