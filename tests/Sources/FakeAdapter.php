<?php
namespace Invoqk\Tests\Sources;

use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\SourceAdapter;

final class FakeAdapter implements SourceAdapter {
	public int $findCalls = 0;
	public function __construct(
		private string $id,
		private bool $available = true,
		private ?InvoiceFile $file = null,
		private bool $throws = false,
		private array $creditNotes = []
	) {}
	public function id(): string { return $this->id; }
	public function label(): string { return strtoupper( $this->id ); }
	public function isAvailable(): bool { return $this->available; }
	public function read( \WC_Order $order, InvoiceFile $file ): ?string { return null; }
	public function findCreditNotes( \WC_Order $order ): array {
		if ( $this->throws ) {
			throw new \RuntimeException( 'boom' );
		}
		return $this->creditNotes;
	}
	public function find( \WC_Order $order ): ?InvoiceFile {
		++$this->findCalls;
		if ( $this->throws ) {
			throw new \RuntimeException( 'boom' );
		}
		return $this->file;
	}
}
