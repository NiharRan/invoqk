<?php
use Invoqk\Validation\Client;
use Invoqk\Validation\Mapper;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance for S2: an XRechnung with a Leitweg-ID in BT-10 passes the KoSIT validator behind the API.
 * Real network call: runs only with INVOQK_INTEGRATION=1 and INVOQK_API_KEY (optional INVOQK_API_BASE).
 *
 * @group integration
 */
final class KositIntegrationTest extends TestCase {
	public function test_xrechnung_with_leitweg_id_passes(): void {
		$key = (string) getenv( 'INVOQK_API_KEY' );
		if ( '1' !== getenv( 'INVOQK_INTEGRATION' ) || '' === $key ) {
			$this->markTestSkipped( 'Set INVOQK_INTEGRATION=1 and INVOQK_API_KEY to run against the real validation API.' );
		}
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => $key, 'invoqk_api_base' => (string) getenv( 'INVOQK_API_BASE' ) ];
		$xml = file_get_contents( dirname( __DIR__ ) . '/Sources/fixtures/xrechnung-leitweg.xml' );

		$res    = Client::post( $xml, 'application/xml', [ self::class, 'transport' ] );
		$record = Mapper::map( $res['http'], $res['body'], $res['retryAfter'], hash( 'sha256', $xml ), 'fixture' );

		$this->assertSame( 'passed', $record['status'], json_encode( $res['body'] ) );
	}

	/** Real HTTP in the shape of wp_remote_post's result; the test bootstrap's wp_remote_post is a stub. */
	public static function transport( string $url, array $args ): array {
		$headers = [];
		foreach ( $args['headers'] as $k => $v ) {
			$headers[] = "$k: $v";
		}
		$body = file_get_contents( $url, false, stream_context_create( [
			'http' => [ 'method' => 'POST', 'header' => $headers, 'content' => $args['body'], 'timeout' => $args['timeout'], 'follow_location' => 0, 'ignore_errors' => true ],
		] ) );
		$lines = function_exists( 'http_get_last_response_headers' ) ? (array) http_get_last_response_headers() : ( $http_response_header ?? [] );
		$code  = preg_match( '#^HTTP/\S+ (\d{3})#', (string) ( $lines[0] ?? '' ), $m ) ? (int) $m[1] : 0;
		$out   = [];
		foreach ( array_slice( $lines, 1 ) as $l ) {
			if ( str_contains( $l, ':' ) ) {
				[ $k, $v ]                       = explode( ':', $l, 2 );
				$out[ strtolower( trim( $k ) ) ] = trim( $v );
			}
		}
		return [ 'response' => [ 'code' => $code ], 'body' => (string) $body, 'headers' => $out ];
	}
}
