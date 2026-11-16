<?php
namespace Invoqk\Fallback;

use Invoqk\Detection\Detector;
use Invoqk\Sources\Registry;

/** Creates the fallback e-invoice for one order: number, XML, file, source detection. */
final class Generate {
	private const STALE = 60;

	/** @return array{ok: bool, message: string, number: ?string} */
	public static function run( int $orderId ): array {
		// shortcut: same option lock as Numbers (stale takeover is delete+add, so two requests seeing a >60 s old lock at once can both enter); upgrade together with Numbers
		$key   = 'invoqk_fallback_gen_' . $orderId;
		$token = time() . ':' . bin2hex( random_bytes( 8 ) );
		if ( ! add_option( $key, $token, '', false ) ) {
			if ( time() - (int) get_option( $key, 0 ) <= self::STALE ) {
				return self::held( $orderId );
			}
			delete_option( $key );
			if ( ! add_option( $key, $token, '', false ) ) {
				return self::held( $orderId );
			}
		}
		try {
			return self::create( $orderId );
		} finally {
			if ( get_option( $key ) === $token ) {
				delete_option( $key );
			}
		}
	}

	private static function held( int $orderId ): array {
		$o = wc_get_order( $orderId );
		$m = $o instanceof \WC_Order ? Store::get( $o ) : null;
		return $m ? self::exists( $m ) : self::fail( __( 'An invoice is already being created for this order. Please try again in a moment.', 'invoqk' ) );
	}

	private static function exists( array $m ): array {
		return [ 'ok' => false, 'message' => sprintf( __( 'The invoice already exists: %s', 'invoqk' ), $m['number'] ), 'number' => (string) $m['number'] ];
	}

	private static function fail( string $message ): array {
		return [ 'ok' => false, 'message' => $message, 'number' => null ];
	}

	private static function create( int $orderId ): array {
		$o = wc_get_order( $orderId );
		if ( ! $o instanceof \WC_Order ) {
			return self::fail( __( 'The fallback e-invoice is not offered for this order.', 'invoqk' ) );
		}
		if ( $m = Store::get( $o ) ) {
			return self::exists( $m );
		}
		if ( ! Offer::available( $o ) ) {
			return self::fail( __( 'The fallback e-invoice is not offered for this order.', 'invoqk' ) );
		}
		if ( $missing = Seller::missing() ) {
			return self::fail( sprintf( __( 'Missing seller data: %s', 'invoqk' ), implode( ', ', array_map( [ Seller::class, 'label' ], $missing ) ) ) );
		}
		$seller = Seller::get();
		$number = null;
		try {
			$invoice = OrderReader::read( $o, (bool) $seller['nullsatz'] );
			Builder::build( $invoice, $seller, 'TEST', $seller['profile'] ); // dry run: refusals must not burn a number
			$number = Numbers::next();
			$xml    = Builder::build( $invoice, $seller, $number, $seller['profile'] );
			Store::save( $o, $xml, $number, $seller['profile'] );
		} catch ( Unsupported $e ) {
			if ( null === $number ) {
				return self::fail( $e->message() );
			}
			self::voided( $number, $orderId, $e );
			return self::fail( __( 'The invoice could not be created. Nothing was saved.', 'invoqk' ) );
		} catch ( \Throwable $e ) {
			if ( null !== $number ) {
				self::voided( $number, $orderId, $e );
			}
			return self::fail( __( 'The invoice could not be created. Nothing was saved.', 'invoqk' ) );
		}
		try {
			( new Detector( Registry::default() ) )->run( $orderId );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( sprintf( 'fallback detect after save failed for #%d: %s', $orderId, get_class( $e ) ), [ 'source' => 'invoqk' ] );
		}
		return [ 'ok' => true, 'message' => sprintf( __( 'Invoice %s created.', 'invoqk' ), $number ), 'number' => $number ];
	}

	private static function voided( string $number, int $orderId, \Throwable $e ): void {
		wc_get_logger()->error( sprintf( 'fallback void number %s for #%d: %s', $number, $orderId, get_class( $e ) ), [ 'source' => 'invoqk' ] );
	}
}
