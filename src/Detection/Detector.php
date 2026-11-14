<?php
namespace Invoqk\Detection;

use Invoqk\Classification\Classifier;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Record;

final class Detector {
	public function __construct( private Registry $registry ) {}

	public function run( int $orderId ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			return;
		}

		Classifier::save( $order ); // own save: the cached-file early return below never reaches the final one

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
		if ( $hit ) {
			$rec = Record::get( $order );
			if ( ! $rec || ( $rec['ref'] ?? null ) !== (string) $hit['file']->reference ) {
				Scheduler::enqueueValidate( $orderId );
			}
		}
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
