<?php
use Invoqk\Onboarding\State;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
	}

	public function test_missing_state_is_new(): void {
		$this->assertSame( 'new', State::get() );
	}

	public function test_unknown_state_ignored(): void {
		State::set( 'skipped' );
		State::set( 'bogus' );
		$this->assertSame( 'skipped', State::get() );
		$GLOBALS['invoqk_options']['invoqk_onboarding'] = 'bogus';
		$this->assertSame( 'new', State::get() );
	}

	public function test_stale_running_reads_as_done(): void {
		State::set( 'running' );
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ 1 ], 'startedAt' => time() - 601 ];
		$this->assertSame( 'done', State::get() );
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ 1 ], 'startedAt' => time() - 10 ];
		$this->assertSame( 'running', State::get() );
	}

	public function test_deadline_by_band(): void {
		$this->assertNull( State::deadline() );
		$this->assertFalse( State::setTurnover( 'x' ) );
		$this->assertTrue( State::setTurnover( 'over' ) );
		$this->assertSame( '1.1.2027', State::deadline() );
		$this->assertTrue( State::setTurnover( 'under' ) );
		$this->assertSame( '1.1.2028', State::deadline() );
		$this->assertSame( 'under', State::turnover() );
	}

	public function test_activated_at_backfills_once(): void {
		$GLOBALS['invoqk_options'] = [];
		$first = State::activatedAt();
		$this->assertSame( $first, $GLOBALS['invoqk_options']['invoqk_activated_at'] );
		$GLOBALS['invoqk_options']['invoqk_activated_at'] = $first - 500;
		$this->assertSame( $first - 500, State::activatedAt() );
	}

	public function test_first_report_shape(): void {
		$this->assertNull( State::firstReport() );
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ '3', 4 ], 'startedAt' => 5 ];
		$this->assertSame( [ 'ids' => [ 3, 4 ], 'startedAt' => 5 ], State::firstReport() );
	}
}
