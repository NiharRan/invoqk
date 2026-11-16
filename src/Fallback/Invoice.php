<?php
namespace Invoqk\Fallback;

/** Order data the Builder needs. Dates are Y-m-d; buyer keys: name, company, street, postcode, city, country, email, vat_id, reference. */
final class Invoice {
	/** @param InvoiceLine[] $lines */
	public function __construct(
		public readonly string $orderNumber,
		public readonly string $issueDate,
		public readonly string $deliveryDate,
		public readonly array $lines,
		public readonly array $buyer,
		public readonly bool $nullsatz,
	) {}
}
