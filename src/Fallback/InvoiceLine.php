<?php
namespace Invoqk\Fallback;

/** One invoice line: net amount in cents (authoritative), quantity as a decimal string, VAT rate 19, 7 or 0. */
final class InvoiceLine {
	public function __construct(
		public readonly string $name,
		public readonly string $quantity,
		public readonly int $netCents,
		public readonly int $ratePercent,
	) {}
}
