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

	/** @var array<int, true> order ids whose held invoice the guard is sending right now */
	private static array $sending = [];

	/** @var callable (int $id, string $kind): void */
	public static $notify = [ Digest::class, 'add' ];

	public static function boot(): void {
		add_action( 'wpo_wcpdf_email_attachment', [ self::class, 'record' ], 10, 4 );
		add_filter( 'woocommerce_email_attachments', [ self::class, 'filterAttachments' ], 999, 4 );
		add_action( 'invoqk_validated', static fn( $id ) => self::decide( (int) $id ), 10, 2 );
		add_action( self::TIMEOUT, static fn( $id ) => self::decide( (int) $id, true ) );
		add_action( Digest::HOOK, static fn() => Digest::run() );
		add_filter( 'wpo_wcpdf_document_types_for_email', [ self::class, 'addInvoiceToOwnSend' ], 10, 3 );
		add_filter( 'wpo_wcpdf_reuse_document_attachment', [ self::class, 'noReuseDuringSend' ], 10, 2 );
		add_action( 'woocommerce_email_after_order_table', static fn( $order, $sentToAdmin, $plain, $email ) => print self::footer( $order, (bool) $sentToAdmin, (bool) $plain, $email ), 10, 4 );
	}

	/** Test helper: forget recorded paths. */
	public static function reset(): void {
		self::$paths   = [];
		self::$sending = [];
		self::$notify  = [ Digest::class, 'add' ];
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

	public static function sending( int $id ): bool {
		return isset( self::$sending[ $id ] );
	}

	/** Acts on orders the guard put into waiting; $timeout is true when the wait ran out. */
	public static function decide( int $id, bool $timeout = false ): void {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			Hold::heldIds();
			return;
		}
		$hold = Hold::get( $order );
		if ( ! $hold ) {
			return;
		}
		$status = Record::status( $order );
		if ( 'skipped' === $hold['state'] && 'failed' === $status && empty( $hold['warned'] ) ) {
			( self::$notify )( $id, 'warn' );
			Hold::set( $order, 'skipped', [ 'warned' => true ] );
			return;
		}
		if ( 'waiting' !== $hold['state'] ) {
			return;
		}
		switch ( $status ) {
			case 'passed':
				self::send( $id, 'sent' );
				break;
			case 'failed':
				if ( 'block' === Hold::mode() ) {
					$reason = '';
					foreach ( Record::get( $order )['issues'] ?? [] as $issue ) {
						if ( 'error' === ( $issue['severity'] ?? 'error' ) ) {
							$reason = (string) ( $issue['id'] ?? '' );
							break;
						}
					}
					Hold::set( $order, 'held', [ 'reason' => $reason ] );
					( self::$notify )( $id, 'held' );
				} elseif ( self::send( $id, 'sent' ) ) {
					( self::$notify )( $id, 'warn' );
				}
				break;
			case 'error':
			case 'limit':
				if ( self::send( $id, 'skipped' ) ) {
					( self::$notify )( $id, 'skipped' );
				}
				break;
			default: // none, pending
				if ( $timeout && self::send( $id, 'skipped' ) ) {
					( self::$notify )( $id, 'none' === $status ? 'no_einvoice' : 'skipped' );
				}
		}
	}

	public static function send( int $id, string $state, array $extra = [], ?callable $mailer = null ): bool {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() || Hold::isFinal( $order ) ) {
			return false;
		}
		// Atomic on a unique option name: a second runner or click for the same order gets false here.
		$token = "invoqk_guard_sent_{$id}";
		if ( ! add_option( $token, time(), '', false ) ) {
			return false;
		}
		$old = Hold::get( $order );
		Hold::set( $order, $state, $extra );
		self::$sending[ $id ] = true;
		$mailed = true;
		// WC_Email::send does not throw when wp_mail fails; it reports through this action.
		$listen = static function ( $return, $emailId = '' ) use ( &$mailed ): void {
			if ( 'customer_invoice' === $emailId && ! $return ) {
				$mailed = false;
			}
		};
		add_action( 'woocommerce_email_sent', $listen, 10, 2 );
		try {
			$mailer ??= static fn( int $i ) => WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger( $i );
			$mailer( $id );
			$why = $mailed ? '' : 'mail not sent';
		} catch ( \Throwable $e ) {
			$why = get_class( $e );
		} finally {
			remove_action( 'woocommerce_email_sent', $listen, 10 );
			unset( self::$sending[ $id ] );
		}
		if ( '' === $why ) {
			return true;
		}
		if ( $old ) {
			Hold::set( $order, (string) $old['state'], $old );
		}
		delete_option( $token );
		wc_get_logger()->error( "guard send #{$id} failed: {$why}", [ 'source' => 'invoqk' ] );
		return false;
	}

	public static function addInvoiceToOwnSend( array $types, string $emailId, $order ): array {
		if ( 'customer_invoice' !== $emailId || ! $order instanceof \WC_Order || ! self::sending( $order->get_id() ) ) {
			return $types;
		}
		$types['pdf'] = array_values( array_unique( array_merge( (array) ( $types['pdf'] ?? [] ), [ 'invoice' ] ) ) );
		if ( wpo_ips_edi_is_available() && wpo_ips_edi_send_attachments() ) {
			$types['xml'] = array_values( array_unique( array_merge( (array) ( $types['xml'] ?? [] ), [ 'invoice' ] ) ) );
		}
		return $types;
	}

	public static function noReuseDuringSend( bool $reuse, $document ): bool {
		$order = $document->order ?? null;
		return $order instanceof \WC_Order && self::sending( $order->get_id() ) ? false : $reuse;
	}
}
