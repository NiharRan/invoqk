<?php
use Invoqk\Validation\Client;
use Invoqk\Validation\Mapper;
use PHPUnit\Framework\TestCase;

/**
 * Golden fixtures (XRechnung and EN 16931 profiles) pass the KoSIT validator.
 * Runs only with INVOQK_KOSIT_URL set to an HTTP endpoint that accepts the XML body (used as API base).
 *
 * @group integration
 */
final class KositTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function fixtures(): array {
		return [ 'xrechnung' => [ 'standard-xrechnung.xml' ], 'en16931' => [ 'standard-en16931.xml' ], 'mixed rates' => [ 'mixed-rates.xml' ] ];
	}

	/** @dataProvider fixtures */
	public function test_generated_standard_orders_pass( string $file ): void {
		$url = (string) getenv( 'INVOQK_KOSIT_URL' );
		if ( '' === $url ) {
			$this->markTestSkipped( 'Set INVOQK_KOSIT_URL to run the golden fixtures against a KoSIT validator.' );
		}
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => (string) getenv( 'INVOQK_API_KEY' ), 'invoqk_api_base' => $url ];
		$xml = (string) file_get_contents( __DIR__ . '/fixtures/' . $file );

		$res    = Client::post( $xml, 'application/xml', [ KositIntegrationTest::class, 'transport' ] );
		$record = Mapper::map( $res['http'], $res['body'], $res['retryAfter'], hash( 'sha256', $xml ), 'fixture' );

		$this->assertSame( 'passed', $record['status'], json_encode( $res['body'] ) );
	}
}
