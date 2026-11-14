<?php
namespace Invoqk\Sources;

/** Reads the e-invoice of one invoice plugin. Read only: never generate invoices. */
interface SourceAdapter {
	/** Stable machine id, stored in order meta (e.g. `germanized_pro`). */
	public function id(): string;

	/** Human name shown in the admin (e.g. `Germanized Pro`). */
	public function label(): string;

	/** True when the source plugin is active. */
	public function isAvailable(): bool;

	/** The order's e-invoice file, or null if this plugin has none for it. */
	public function find( \WC_Order $order ): ?InvoiceFile;
}
