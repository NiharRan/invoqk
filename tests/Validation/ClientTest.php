<?php
use Invoqk\Validation\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {
	private array $seen = [];

	protected function setUp(): void {
		$this->seen = [];
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => ' sekret ', 'invoqk_api_base' => 'https://api.test' ];
	}

	private function fake( $ret ): callable {
		return function ( string $url, array $args ) use ( $ret ) {
			$this->seen = [ $url, $args ];
			return $ret;
		};
	}

	private function resp( int $code, string $body, array $headers = [] ): array {
		return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => $headers ];
	}

	public function test_url_defaults_to_absolute_production_base(): void {
		foreach ( [ [], [ 'invoqk_api_base' => '' ] ] as $opts ) {
			$GLOBALS['invoqk_options'] = $opts;
			$this->assertSame( Client::DEFAULT_BASE . '/api/v1/einvoice/validate', Client::url() );
		}
		$this->assertStringStartsWith( 'https://', Client::url() );
	}

	public function test_sends_raw_bytes_with_content_type_and_key_header(): void {
		Client::post( '<x/>', 'application/xml', $this->fake( $this->resp( 200, '{}' ) ) );
		[ $url, $args ] = $this->seen;
		$this->assertSame( 'https://api.test/api/v1/einvoice/validate', $url );
		$this->assertSame( '<x/>', $args['body'] );
		$this->assertSame( 'application/xml', $args['headers']['Content-Type'] );
		$this->assertSame( 'Authorization', Client::KEY_HEADER );
		$this->assertSame( 'Bearer sekret', $args['headers'][ Client::KEY_HEADER ] );
	}

	public function test_timeout_and_redirect_args(): void {
		Client::post( 'b', 'application/pdf', $this->fake( $this->resp( 200, '{}' ) ) );
		$a = $this->seen[1];
		$this->assertSame( 20, $a['timeout'] );
		$this->assertSame( 0, $a['redirection'] );
		$this->assertTrue( $a['sslverify'] );
	}

	public function test_decodes_json_body(): void {
		$r = Client::post( 'b', 'application/xml', $this->fake( $this->resp( 200, '{"verdict":"pass"}' ) ) );
		$this->assertSame( 200, $r['http'] );
		$this->assertSame( [ 'verdict' => 'pass' ], $r['body'] );
	}

	public function test_non_json_body_is_null_body(): void {
		foreach ( [ '<html>', '"str"', '' ] as $b ) {
			$this->assertNull( Client::post( 'b', 'application/xml', $this->fake( $this->resp( 502, $b ) ) )['body'] );
		}
	}

	public function test_transport_error_is_http_0(): void {
		$r = Client::post( 'b', 'application/xml', $this->fake( new WP_Error() ) );
		$this->assertSame( [ 'http' => 0, 'body' => null, 'retryAfter' => null ], $r );
	}

	public function test_retry_after_header_passed_through(): void {
		$r = Client::post( 'b', 'application/xml', $this->fake( $this->resp( 429, '', [ 'retry-after' => '120' ] ) ) );
		$this->assertSame( '120', $r['retryAfter'] );
		$this->assertNull( Client::post( 'b', 'application/xml', $this->fake( $this->resp( 200, '{}' ) ) )['retryAfter'] );
	}
}
