<?php
use Invoqk\Archive\Archive;
use Invoqk\Archive\Usage;
use Invoqk\Tests\Archive\MemoryIndex;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase {
	private array $mails = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_can']     = true;
		$this->mails               = [];
		Archive::reset();
		Archive::$index = new MemoryIndex();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_can'] );
		Archive::reset();
	}

	private function fill( int $bytes ): void {
		Archive::$index = new MemoryIndex();
		Archive::$index->append( [ 'order_id' => 1, 'pdf_key' => 'k' . $bytes, 'pdf_size' => $bytes ], fn() => 'h' );
	}

	private function mail( bool $ok = true ): callable {
		return function ( $to, $subject, $body ) use ( $ok ) {
			$this->mails[] = [ $subject, $body ];
			return $ok;
		};
	}

	public function test_level_thresholds(): void {
		$L = Usage::LIMIT;
		$this->assertSame( 0, Usage::level( (int) ( 0.79 * $L ) ) );
		$this->assertSame( 80, Usage::level( (int) ( 0.8 * $L ) ) );
		$this->assertSame( 100, Usage::level( $L ) );
		$this->assertSame( 100, Usage::level( (int) ( 1.3 * $L ) ) );
	}

	public function test_label(): void {
		$this->assertSame( '1,2 GB of 5 GB', Usage::label( (int) ( 1.2 * 1024 ** 3 ) ) );
	}

	public function test_mails_once_per_crossing(): void {
		$this->fill( (int) ( 0.85 * Usage::LIMIT ) );
		Usage::check( $this->mail() );
		Usage::check( $this->mail() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'Invoqk: archive at 80 %', $this->mails[0][0] );
		$this->assertStringContainsString( 'invoqk-archive', $this->mails[0][1] );
		$this->assertSame( 80, get_option( 'invoqk_archive_warned' ) );
		$this->fill( Usage::LIMIT + 1 );
		Usage::check( $this->mail() );
		$this->assertCount( 2, $this->mails );
		$this->assertSame( 100, get_option( 'invoqk_archive_warned' ) );
	}

	public function test_reset_below_80(): void {
		update_option( 'invoqk_archive_warned', 100 );
		$this->fill( 10 );
		Usage::check( $this->mail() );
		$this->assertSame( 0, get_option( 'invoqk_archive_warned' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_mail_failure_retries_next_check(): void {
		$this->fill( Usage::LIMIT );
		Usage::check( $this->mail( false ) );
		$this->assertSame( 0, (int) get_option( 'invoqk_archive_warned', 0 ) );
		Usage::check( static fn() => throw new \RuntimeException( 'x' ) );
		$this->assertSame( 0, (int) get_option( 'invoqk_archive_warned', 0 ) );
		Usage::check( $this->mail() );
		$this->assertSame( 100, get_option( 'invoqk_archive_warned' ) );
	}

	public function test_notice(): void {
		$this->assertSame( '', Usage::notice() );
		$this->fill( Usage::LIMIT );
		$h = Usage::notice();
		$this->assertStringContainsString( 'notice-error', $h );
		$this->assertStringContainsString( 'Archiving continues', $h );
		$this->fill( (int) ( 0.85 * Usage::LIMIT ) );
		$h = Usage::notice();
		$this->assertStringContainsString( 'notice-warning', $h );
		$this->assertStringNotContainsString( 'Archiving continues', $h );
		$GLOBALS['invoqk_can'] = false;
		$this->assertSame( '', Usage::notice() );
	}
}
