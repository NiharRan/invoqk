<?php
use Invoqk\B2g\LeitwegId;
use PHPUnit\Framework\TestCase;

final class LeitwegIdTest extends TestCase {
	public static function cases(): array {
		return [
			'valid long'      => [ '04011000-1234512345-06', '04011000-1234512345-06', null ],
			'valid short'     => [ '991-01234-96', '991-01234-96', null ],
			'lower case'      => [ '04011000-abc-06', '04011000-ABC-06', null ],
			'padded'          => [ '  991-01234-96  ', '991-01234-96', null ],
			'inner spaces'    => [ '991 - 01234 - 96', '991-01234-96', null ],
			'space separated' => [ '991 01234 96', '991-01234-96', null ],
			'tab and nbsp'    => [ "991\u{00A0}-\t01234 -96", '991-01234-96', null ],
			'invisible chars' => [ "99\u{00AD}1-\u{200B}01234-\u{FEFF}96", '991-01234-96', null ],
			'spaced bad check' => [ '991 - 01234 - 9', '991-01234-9', 'parts' ],
			'space inside part' => [ '991 0 1234-96', '991-0-1234-96', 'parts' ],
			'sharp s'         => [ '991-ß-96', '991-ß-96', 'chars' ],
			'en dash'         => [ "991\u{2013}01234\u{2013}96", '991-01234-96', null ],
			'em dash'         => [ "991\u{2014}01234-96", '991-01234-96', null ],
			'nb hyphen'       => [ "991\u{2011}01234-96", '991-01234-96', null ],
			'unicode minus'   => [ "991\u{2212}01234-96", '991-01234-96', null ],
			'empty'           => [ '', '', null ],
			'blank'           => [ '   ', '', null ],
			'no hyphens'      => [ 'abc', 'ABC', 'parts' ],
			'no check part'   => [ '991-01234', '991-01234', 'parts' ],
			'coarse short'    => [ '1-2-3', '1-2-3', 'length' ],
			'coarse 13'       => [ '1234567890123-AB-12', '1234567890123-AB-12', 'length' ],
			'fine 31'         => [ '991-' . str_repeat( 'A', 31 ) . '-96', '991-' . str_repeat( 'A', 31 ) . '-96', 'length' ],
			'fine 30'         => [ '991-' . str_repeat( 'A', 30 ) . '-96', '991-' . str_repeat( 'A', 30 ) . '-96', null ],
			'check one digit' => [ '991-01234-9', '991-01234-9', 'parts' ],
			'empty fine'      => [ '991--96', '991--96', 'parts' ],
			'four parts'      => [ '991-A-B-96', '991-A-B-96', 'parts' ],
			'coarse letters'  => [ 'AB1-CD-96', 'AB1-CD-96', 'parts' ],
			'underscore'      => [ '991_01234-96', '991_01234-96', 'chars' ],
		];
	}

	/** @dataProvider cases */
	public function test_normalise_and_check( string $raw, string $norm, ?string $code ): void {
		$this->assertSame( $norm, LeitwegId::normalise( $raw ) );
		$this->assertSame( $code, LeitwegId::check( $raw ) );
	}

	public function test_valid_samples_never_error_when_normalised(): void {
		foreach ( self::cases() as $name => [ $raw, $norm, $code ] ) {
			if ( null === $code ) {
				$this->assertNull( LeitwegId::check( LeitwegId::normalise( $raw ) ), $name );
			}
		}
	}

	public function test_messages(): void {
		foreach ( [ 'chars', 'length', 'parts' ] as $code ) {
			$m = LeitwegId::message( $code );
			$this->assertNotSame( '', $m );
			$this->assertDoesNotMatchRegularExpression( '/[<>\[\]{}]/', $m );
		}
		$this->assertNotSame( '', LeitwegId::message( 'unknown' ) );
	}
}
