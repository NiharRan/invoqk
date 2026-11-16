<?php
use Invoqk\Fallback\Unsupported;
use PHPUnit\Framework\TestCase;

/** The documented limits must name every refusal class of the fallback generator. */
final class LimitsDocTest extends TestCase {
	private const KEYWORDS = [ 'Nullsatz', 'refund', 'multi-currency', 'XML', 'hybrid', 'credit note', 'advance payment', 'partial deliver', 'split period', 'allowance', '19', 'EUR', 'cent' ];

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_every_unsupported_reason_has_german_text(): void {
		$po = (string) file_get_contents( self::root() . '/languages/invoqk-de_DE.po' );
		foreach ( Unsupported::REASONS as $r ) {
			$id = ( new Unsupported( $r ) )->message(); // __() returns the msgid in tests
			$this->assertMatchesRegularExpression( '/msgid "' . preg_quote( addcslashes( $id, '"' ), '/' ) . '"\nmsgstr "[^"]/', $po, $r );
		}
	}

	/** @return array<string, array{string}> */
	public static function readmes(): array {
		return [ 'README.md' => [ 'README.md' ], 'readme.txt' => [ 'readme.txt' ] ];
	}

	/** @dataProvider readmes */
	public function test_readme_lists_each_limit( string $file ): void {
		$text = (string) file_get_contents( self::root() . '/' . $file );
		foreach ( self::KEYWORDS as $k ) {
			$this->assertStringContainsStringIgnoringCase( $k, $text, "$file lacks limit keyword: $k" );
		}
	}

	public function test_adapters_doc_states_fallback_exception(): void {
		$text = (string) file_get_contents( self::root() . '/ADAPTERS.md' );
		$this->assertStringContainsString( 'FallbackAdapter', $text );
	}
}
