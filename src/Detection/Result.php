<?php
namespace Invoqk\Detection;

use Invoqk\Sources\InvoiceFile;

final class Result {
	public const SOURCE  = '_invoqk_source';
	public const FILE    = '_invoqk_file';
	public const DETECTED = '_invoqk_detected_at';

	public function __construct(
		public readonly string $source,
		public readonly ?string $label,
		public readonly ?InvoiceFile $file,
		public readonly int $detectedAt
	) {}

	/** Cached detection result; null when the order was never checked. */
	public static function fromOrder( \WC_Order $order ): ?self {
		$source = (string) $order->get_meta( self::SOURCE );
		if ( '' === $source ) {
			return null;
		}
		$f    = $order->get_meta( self::FILE );
		$file = is_array( $f ) && isset( $f['reference'], $f['mime'] )
			? new InvoiceFile( $f['reference'], $f['mime'], $f['format'] ?? 'unknown', ! empty( $f['onDemand'] ) )
			: null;
		return new self( $source, 'none' === $source ? null : (string) $order->get_meta( '_invoqk_label' ), $file, (int) $order->get_meta( self::DETECTED ) );
	}
}
