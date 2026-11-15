<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Licensing\Api;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private array $seen = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$this->seen = [];
	}

	private function transport( $reply ): callable {
		return function ( string $url, array $args ) use ( $reply ) {
			$this->seen[] = [ $url, $args ];
			return $reply;
		};
	}

	public function test_posts_json_without_key_header_when_not_asked(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_x';
		$res = Api::call( 'trial', [ 'domain' => 'a.de' ], false, $this->transport( [ 'response' => [ 'code' => 200 ], 'body' => '{"plan":"trial"}' ] ) );
		[ $url, $args ] = $this->seen[0];
		$this->assertSame( 'https://invoqk.com/api/v1/license/trial', $url );
		$this->assertSame( '{"domain":"a.de"}', $args['body'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame( 'shop.example', $args['headers']['X-Invoqk-Site'] );
		$this->assertSame( 200, $res['http'] );
		$this->assertSame( 'trial', $res['body']['plan'] );
	}

	public function test_sends_bearer_key_when_asked(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_x';
		Api::call( 'check', [], true, $this->transport( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] ) );
		$this->assertSame( 'Bearer sk_x', $this->seen[0][1]['headers']['Authorization'] );
	}

	public function test_wp_error_is_http_zero(): void {
		$res = Api::call( 'check', [], true, $this->transport( new \WP_Error() ) );
		$this->assertSame( [ 'http' => 0, 'body' => null ], $res );
	}

	public function test_error_maps_codes(): void {
		$this->assertSame( 'unreachable', Api::error( [ 'http' => 0, 'body' => null ] ) );
		$this->assertSame( 'trial_used', Api::error( [ 'http' => 409, 'body' => [ 'error' => 'trial_used' ] ] ) );
		$this->assertSame( 'unknown', Api::error( [ 'http' => 500, 'body' => [] ] ) );
	}

	public function test_domain_is_lowercase_host(): void {
		$this->assertSame( 'shop.example', Api::domain() );
	}

	public function test_explicit_key_overrides_stored_key(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_stored';
		Api::call( 'activate', [], true, $this->transport( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] ), 'iqk_typed' );
		$this->assertSame( 'Bearer iqk_typed', $this->seen[0][1]['headers']['Authorization'] );
	}
}
