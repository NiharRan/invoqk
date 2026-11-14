<?php
namespace Invoqk\Sources;

final class InvoiceFile {
	/**
	 * @param int|string $reference attachment ID or absolute path
	 * @param string     $mime      application/xml or application/pdf
	 * @param string     $format    ubl|cii|zugferd|facturx|unknown
	 */
	public function __construct(
		public readonly int|string $reference,
		public readonly string $mime,
		public readonly string $format = 'unknown'
	) {}
}
