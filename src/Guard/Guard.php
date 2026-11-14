<?php
namespace Invoqk\Guard;

use Invoqk\Classification\Classifier;
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\WpOvernightAdapter;
use Invoqk\Validation\Limit;
use Invoqk\Validation\Record;

/** Keeps WP Overnight's invoice off customer emails until the e-invoice is validated. */
final class Guard {
	public const TIMEOUT = 'invoqk_guard_timeout';
	public const WAIT    = 300;

	/** @var array<int, string[]> order id => invoice file paths seen this request */
	private static array $paths = [];

	public static function boot(): void {
		add_action( 'wpo_wcpdf_email_attachment', [ self::class, 'record' ], 10, 4 );
		add_filter( 'woocommerce_email_attachments', [ self::class, 'filterAttachments' ], 999, 4 );
		add_action( 'woocommerce_email_after_order_table', static fn( $order, $sentToAdmin, $plain, $email ) => print self::footer( $order, (bool) $sentToAdmin, (bool) $plain, $email ), 10, 4 );
	}

	/** Test helper: forget recorded paths. */
	public static function reset(): void {
		self::$paths = [];
	}

	public static function record( string $path, string $documentType, $document, string $format ): void {
		$order = $document->order ?? null;
		if ( 'invoice' === $documentType && $order instanceof \WC_Order ) {
			self::$paths[ $order->get_id() ][] = $path;
		}
	}

	public static function covers( \WC_Order $o ): bool {
		return Limit::isPro()
			&& Classifier::isRequired( $o )
			&& ( new WpOvernightAdapter() )->isAvailable()
			&& ! Hold::isFinal( $o )
			&& ! self::sending( $o->get_id() )
			&& 'passed' !== Record::status( $o );
	}

	public static function filterAttachments( array $attachments, string $emailId, $object, $email = null ): array {
		if ( is_object( $email ) && method_exists( $email, 'is_customer_email' ) && ! $email->is_customer_email() ) {
			if ( $object instanceof \WC_Order ) {
				unset( self::$paths[ $object->get_id() ] );
			}
			return $attachments;
		}
		if ( ! $object instanceof \WC_Order || ! self::covers( $object ) ) {
			return $attachments;
		}
		$id    = $object->get_id();
		$paths = self::$paths[ $id ] ?? [];
		if ( ! $paths ) {
			return $attachments;
		}
		unset( self::$paths[ $id ] );

		$hold   = Hold::get( $object ) ?? [];
		$emails = array_values( array_unique( array_merge( $hold['emails'] ?? [], [ $emailId ] ) ) );
		Hold::set( $object, 'held' === ( $hold['state'] ?? '' ) ? 'held' : 'waiting', [ 'emails' => $emails ] );
		Scheduler::enqueue( $id );
		if ( ! as_has_scheduled_action( self::TIMEOUT, [ $id ], Scheduler::GROUP ) ) {
			as_schedule_single_action( time() + self::WAIT, self::TIMEOUT, [ $id ], Scheduler::GROUP );
		}
		return array_values( array_diff( $attachments, $paths ) );
	}

	public static function footer( $order, bool $sentToAdmin, bool $plain, $email ): string {
		if ( $sentToAdmin || ! $order instanceof \WC_Order || ! self::covers( $order ) ) {
			return '';
		}
		$docs = \WPO_WCPDF()->main->get_documents_for_email( $email->id ?? '', $order );
		$has  = false;
		foreach ( (array) $docs as $types ) {
			$has = $has || in_array( 'invoice', (array) $types, true );
		}
		if ( ! $has ) {
			return '';
		}
		$text = __( 'Your invoice will follow in a separate email.', 'invoqk' );
		return $plain ? $text . "\n" : '<p>' . esc_html( $text ) . '</p>';
	}

	/** Task 3 sets this while it sends the held invoice itself. */
	public static function sending( int $id ): bool {
		return false;
	}
}
