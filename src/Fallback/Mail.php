<?php
namespace Invoqk\Fallback;

use Invoqk\Archive\Capture;
use Invoqk\Validation\Limit;
use Invoqk\Validation\Record;

/** Sends the stored fallback invoice with customer emails, only once it has passed validation. */
final class Mail {
	private const EMAILS = [ 'customer_processing_order', 'customer_completed_order' ];

	public static function boot(): void {
		add_filter( 'woocommerce_email_attachments', [ self::class, 'attach' ], 900, 4 ); // before Capture::stage (1000)
	}

	/** Untyped: another plugin on this shared hook may pass anything. */
	public static function attach( $attachments, $emailId = '', $object = null, $email = null ) {
		if ( ! is_array( $attachments ) || ! $object instanceof \WC_Order || ! Limit::isPro()
			|| ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || true !== $email->is_customer_email()
			|| ! in_array( $emailId, self::EMAILS, true )
			|| 'invoqk_fallback' !== (string) $object->get_meta( '_invoqk_source' )
			|| 'passed' !== Record::status( $object ) ) {
			return $attachments;
		}
		$path = Store::path( $object );
		$hash = (string) ( Store::get( $object )['hash'] ?? '' );
		// the validation must be of this very file, not a stale record of an earlier one
		if ( null === $path || '' === $hash || ! hash_equals( $hash, (string) ( Record::get( $object )['hash'] ?? '' ) )
			|| ! hash_equals( $hash, (string) hash_file( 'sha256', $path ) ) ) {
			return $attachments;
		}
		if ( ! in_array( $path, $attachments, true ) ) {
			$attachments[] = $path;
		}
		Capture::record( $path, 'invoice', (object) [ 'order' => $object ], 'xml' );
		return $attachments;
	}
}
