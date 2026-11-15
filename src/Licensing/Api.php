<?php
namespace Invoqk\Licensing;

use Invoqk\Validation\Client;

/** Calls to the Invoqk license API. Never logs the body or the key. */
final class Api {
	/** @return array{http:int,body:?array} */
	public static function call( string $path, array $body, bool $withKey, ?callable $transport = null, ?string $key = null ): array {
		$transport ??= 'wp_remote_post';
		$headers   = [ 'Content-Type' => 'application/json', 'X-Invoqk-Site' => self::domain() ];
		if ( $withKey ) {
			$headers[ Client::KEY_HEADER ] = 'Bearer ' . ( $key ?? Client::apiKey() );
		}
		$res = $transport( Client::base() . '/api/v1/license/' . $path, [
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
			'body'        => (string) json_encode( (object) $body ),
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'http' => 0, 'body' => null ];
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return [ 'http' => (int) wp_remote_retrieve_response_code( $res ), 'body' => is_array( $decoded ) ? $decoded : null ];
	}

	public static function domain(): string {
		return strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** @param array{http:int,body:?array} $res */
	public static function error( array $res ): string {
		if ( 0 === $res['http'] ) {
			return 'unreachable';
		}
		$e = $res['body']['error'] ?? null;
		return is_string( $e ) && preg_match( '/^[a-z_]{1,32}$/', $e ) ? $e : 'unknown';
	}
}
