<?php
namespace Invoqk\Validation;

/** Posts an invoice to the validation API. Never logs or throws invoice bytes or the key. */
final class Client {
	// Provisional: header scheme pending the API spec (see E3 spec, Open Questions).
	public const KEY_HEADER = 'Authorization';

	public static function apiKey(): string {
		return trim( (string) get_option( 'invoqk_api_key', '' ) );
	}

	public static function url(): string {
		$base = defined( 'INVOQK_API_BASE' ) ? (string) INVOQK_API_BASE : (string) get_option( 'invoqk_api_base', '' );
		return rtrim( $base, '/' ) . '/api/v1/einvoice/validate';
	}

	/** @return array{http:int,body:?array,retryAfter:?string} */
	public static function post( string $bytes, string $mime, ?callable $transport = null ): array {
		$transport ??= 'wp_remote_post';
		$res = $transport( self::url(), [
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => [ 'Content-Type' => $mime, self::KEY_HEADER => 'Bearer ' . self::apiKey() ],
			'body'        => $bytes,
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'http' => 0, 'body' => null, 'retryAfter' => null ];
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$retry = wp_remote_retrieve_header( $res, 'retry-after' );
		return [
			'http'       => (int) wp_remote_retrieve_response_code( $res ),
			'body'       => is_array( $body ) ? $body : null,
			'retryAfter' => '' === $retry || is_array( $retry ) ? null : (string) $retry,
		];
	}
}
