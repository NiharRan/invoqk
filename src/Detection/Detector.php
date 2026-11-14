<?php
namespace Invoqk\Detection;

use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;

final class Detector {
	public function __construct( private Registry $registry ) {}

	public function run( int $orderId ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			return;
		}

		$hit = $this->registry->detect( $order );
		if ( $hit ) {
			$f = $hit['file'];
			$order->update_meta_data( Result::SOURCE, $hit['adapter']->id() );
			$order->update_meta_data( '_invoqk_label', $hit['adapter']->label() );
			$order->update_meta_data( Result::FILE, [ 'reference' => $f->reference, 'mime' => $f->mime, 'format' => $f->format, 'onDemand' => $f->onDemand ] );
		} else {
			$cached = Result::fromOrder( $order );
			if ( $cached && $cached->file && $this->cacheStillValid( $cached ) ) {
				return; // source plugin may be off; the file is still there
			}
			$order->update_meta_data( Result::SOURCE, 'none' );
			$order->update_meta_data( Result::FILE, '' );
		}
		$order->update_meta_data( Result::DETECTED, time() );
		$order->save_meta_data(); // not save(): that fires woocommerce_update_order and order.updated webhooks
	}

	/** Stored file: still on disk. On-demand: only trust the cache while its source plugin is off or gone. */
	private function cacheStillValid( Result $cached ): bool {
		if ( $cached->file->onDemand ) {
			$adapter = $this->registry->get( $cached->source );
			return ! $adapter || ! $adapter->isAvailable();
		}
		return self::fileExists( $cached->file );
	}

	public static function fileExists( InvoiceFile $f ): bool {
		$path = is_int( $f->reference ) ? get_attached_file( $f->reference ) : $f->reference;
		return is_string( $path ) && file_exists( $path );
	}
}
