<?php
use Invoqk\Validation\IssueText;
use PHPUnit\Framework\TestCase;

final class IssueTextTest extends TestCase {
	private function issue( array $o = [] ): array {
		return $o + [ 'id' => 'R1', 'bt' => [ 'BT-10' ], 'severity' => 'error', 'message' => 'Missing', 'messageDe' => 'Fehlt', 'fix' => null ];
	}

	public function test_german_locale_uses_message_de(): void {
		foreach ( [ 'de_DE', 'de_AT', 'de_DE_formal' ] as $l ) {
			$r = IssueText::for( $this->issue(), $l );
			$this->assertSame( 'Fehlt', $r['text'] );
			$this->assertFalse( $r['raw'] );
		}
	}

	public function test_german_locale_without_message_de_flags_raw(): void {
		$r = IssueText::for( $this->issue( [ 'messageDe' => null ] ), 'de_DE' );
		$this->assertSame( 'Missing', $r['text'] );
		$this->assertTrue( $r['raw'] );
	}

	public function test_other_locale_uses_english(): void {
		foreach ( [ 'en_US', 'fr_FR' ] as $l ) {
			$r = IssueText::for( $this->issue(), $l );
			$this->assertSame( 'Missing', $r['text'] );
			$this->assertFalse( $r['raw'] );
		}
	}

	public function test_code_from_bt_joined_or_falls_back_to_id(): void {
		$this->assertSame( 'BT-10, BG-6', IssueText::for( $this->issue( [ 'bt' => [ 'BT-10', 'BG-6' ] ] ), 'en_US' )['code'] );
		$this->assertSame( 'R1', IssueText::for( $this->issue( [ 'bt' => [] ] ), 'en_US' )['code'] );
		$this->assertSame( 'R1', IssueText::for( $this->issue( [ 'bt' => 'BT-1' ] ), 'en_US' )['code'] );
	}

	public function test_unknown_severity_is_error(): void {
		$this->assertSame( 'error', IssueText::for( $this->issue( [ 'severity' => 'fatal' ] ), 'en_US' )['severity'] );
		$this->assertSame( 'warning', IssueText::for( $this->issue( [ 'severity' => 'warning' ] ), 'en_US' )['severity'] );
	}

	public function test_non_string_fields_do_not_throw(): void {
		$r = IssueText::for( [ 'id' => 5, 'bt' => 'BT-1', 'message' => 42, 'messageDe' => [] ], 'de_DE' );
		$this->assertSame( '42', $r['text'] );
		$this->assertTrue( $r['raw'] );
		$this->assertSame( '5', $r['code'] );
	}
}
