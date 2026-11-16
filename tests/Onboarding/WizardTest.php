<?php
use Invoqk\Onboarding\State;
use Invoqk\Onboarding\Wizard;
use PHPUnit\Framework\TestCase;

final class WizardTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options']   = [];
		$GLOBALS['invoqk_as']        = [];
		$GLOBALS['invoqk_filters']   = [];
		$GLOBALS['invoqk_actions']   = [];
		$GLOBALS['invoqk_transients'] = [];
		$GLOBALS['invoqk_orders']    = [];
		$GLOBALS['invoqk_can']       = true;
		$GLOBALS['invoqk_nonce_ok']  = true;
		$GLOBALS['invoqk_network']   = false;
		$_GET = [];
		$_POST = $_REQUEST = [];
	}

	private function adapter( bool $available ): void {
		$GLOBALS['invoqk_filters']['invoqk_source_adapters'][] = fn() => [ new class( $available ) implements \Invoqk\Sources\SourceAdapter {
			public function __construct( private bool $a ) {}
			public function id(): string { return 'fake'; }
			public function label(): string { return 'Fake Invoices'; }
			public function isAvailable(): bool { return $this->a; }
			public function find( \WC_Order $o ): ?\Invoqk\Sources\InvoiceFile { return null; }
			public function read( \WC_Order $o, \Invoqk\Sources\InvoiceFile $f ): ?string { return null; }
			public function findCreditNotes( \WC_Order $o ): array { return []; }
		} ];
	}

	public function test_finish_requires_turnover(): void {
		$this->assertFalse( Wizard::finish( [ 'api_key' => 'k' ] ) );
		$this->assertFalse( Wizard::finish( [ 'turnover' => 'huge' ] ) );
		$this->assertSame( [], $GLOBALS['invoqk_options'] );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_finish_saves_band_key_and_starts_report(): void {
		$this->assertTrue( Wizard::finish( [ 'turnover' => 'over', 'api_key' => 'secret' ] ) );
		$this->assertSame( 'over', State::turnover() );
		$this->assertSame( 'secret', get_option( 'invoqk_api_key' ) );
		$this->assertSame( 'running', State::get() );
		$this->assertSame( 'invoqk_first_report', $GLOBALS['invoqk_as'][0]['hook'] );
	}

	public function test_finish_ignores_masked_key(): void {
		update_option( 'invoqk_api_key', 'old' );
		Wizard::finish( [ 'turnover' => 'under', 'api_key' => '••••••••' ] );
		$this->assertSame( 'old', get_option( 'invoqk_api_key' ) );
		Wizard::finish( [ 'turnover' => 'under', 'api_key' => '' ] );
		$this->assertSame( 'old', get_option( 'invoqk_api_key' ) );
	}

	public function test_finish_after_done_does_not_restart(): void {
		Wizard::finish( [ 'turnover' => 'over' ] );
		$report = get_option( 'invoqk_first_report' );
		State::set( 'done' );
		$this->assertTrue( Wizard::finish( [ 'turnover' => 'under', 'api_key' => 'new' ] ) );
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( $report, get_option( 'invoqk_first_report' ) );
		$this->assertSame( 'under', State::turnover() );
		$this->assertSame( 'new', get_option( 'invoqk_api_key' ) );
		$this->assertSame( 'done', State::get() );
	}

	public function test_finish_while_running_keeps_running(): void {
		Wizard::finish( [ 'turnover' => 'over' ] );
		Wizard::finish( [ 'turnover' => 'over' ] ); // double click
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( 'running', State::get() );
	}

	public function test_skip_sets_skipped(): void {
		Wizard::skip();
		$this->assertSame( 'skipped', State::get() );
	}

	public function test_turnover_endpoint_saves_band_and_rejects_unknown(): void {
		$this->assertTrue( Wizard::saveTurnover( 'under' ) );
		$this->assertSame( 'under', State::turnover() );
		$this->assertFalse( Wizard::saveTurnover( 'x' ) );
		$this->assertSame( 'under', State::turnover() );
	}

	/** @return array<string,callable> */
	public static function handlers(): array {
		return [
			'finish'   => [ [ Wizard::class, 'finishCallback' ] ],
			'skip'     => [ [ Wizard::class, 'skipCallback' ] ],
			'turnover' => [ [ Wizard::class, 'turnoverCallback' ] ],
			'status'   => [ [ Wizard::class, 'statusCallback' ] ],
		];
	}

	/** @dataProvider handlers */
	public function test_endpoints_reject_without_cap_or_nonce( callable $h ): void {
		$_POST = $_REQUEST = [ 'turnover' => 'over', 'band' => 'over' ];
		foreach ( [ [ false, true ], [ true, false ] ] as [ $can, $nonce ] ) {
			$GLOBALS['invoqk_can']      = $can;
			$GLOBALS['invoqk_nonce_ok'] = $nonce;
			try {
				$h();
				$this->fail( 'expected 403' );
			} catch ( \InvoqkTestDie $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
			$this->assertSame( [], $GLOBALS['invoqk_options'] );
			$this->assertSame( [], $GLOBALS['invoqk_as'] );
		}
	}

	public function test_status_endpoint_returns_status(): void {
		try {
			Wizard::statusCallback();
			$this->fail();
		} catch ( \InvoqkTestJson $e ) {
			$this->assertSame( [ 'total' => 0, 'done' => 0, 'state' => 'new' ], $e->payload );
		}
	}

	public function test_step1_lists_available_adapter_label(): void {
		$this->adapter( true );
		$h = Wizard::render( 1 );
		$this->assertStringContainsString( '✓', $h );
		$this->assertStringContainsString( 'Fake Invoices', $h );
		$this->assertStringContainsString( 'step=2', $h );
	}

	public function test_step1_none_found_lists_supported_and_no_continue(): void {
		$this->adapter( false );
		$h = Wizard::render( 1 );
		$this->assertStringNotContainsString( 'Fake Invoices', $h );
		$this->assertStringContainsString( 'Germanized Pro', $h );
		$this->assertStringContainsString( 'German Market', $h );
		$this->assertStringContainsString( 'WP Overnight', $h );
		$this->assertStringNotContainsString( 'step=2', $h );
		$this->assertStringContainsString( 'invoqk_wizard_skip', $h );
	}

	public function test_step2_shows_deadlines_disclaimer_and_key_field(): void {
		$h = Wizard::render( 2 );
		$this->assertStringContainsString( '1.1.2027', $h );
		$this->assertStringContainsString( '1.1.2028', $h );
		$this->assertStringContainsString( 'No legal or tax advice.', $h );
		$this->assertStringContainsString( 'name="api_key"', $h );
		$this->assertStringContainsString( 'invoqk_wizard_finish', $h );
	}

	public function test_step2_hides_key_field_when_key_set(): void {
		update_option( 'invoqk_api_key', 'topsecret' );
		$h = Wizard::render( 2 );
		$this->assertStringNotContainsString( 'api_key', $h );
		$this->assertStringNotContainsString( 'topsecret', $h );
	}

	public function test_progress_enqueues_script_with_config(): void {
		$h = Wizard::progress();
		$this->assertStringContainsString( 'invoqk-progress', $h );
		$this->assertArrayHasKey( 'invoqk-admin', $GLOBALS['invoqk_scripts'] );
		$d = $GLOBALS['invoqk_localized']['invoqk-admin'][1];
		$this->assertSame( [ 'ajaxUrl', 'nonce', 'dashboardUrl' ], array_keys( $d ) );
	}

	public function test_redirect_target_and_consumed(): void {
		set_transient( 'invoqk_activation_redirect', 1 );
		$this->assertStringEndsWith( 'admin.php?page=invoqk', (string) Wizard::redirectTarget() );
		$this->assertFalse( get_transient( 'invoqk_activation_redirect' ) );
		$this->assertNull( Wizard::redirectTarget() );
	}

	public function test_redirect_skipped_on_bulk_activation(): void {
		set_transient( 'invoqk_activation_redirect', 1 );
		$_GET['activate-multi'] = '1';
		$this->assertNull( Wizard::redirectTarget() );
		$this->assertFalse( get_transient( 'invoqk_activation_redirect' ) );
	}

	public function test_redirect_skipped_on_network_admin_or_no_cap(): void {
		set_transient( 'invoqk_activation_redirect', 1 );
		$GLOBALS['invoqk_network'] = true;
		$this->assertNull( Wizard::redirectTarget() );
		set_transient( 'invoqk_activation_redirect', 1 );
		$GLOBALS['invoqk_network'] = false;
		$GLOBALS['invoqk_can']     = false;
		$this->assertNull( Wizard::redirectTarget() );
	}

	public function test_step2_submit_says_go_to_dashboard_once_report_exists(): void {
		$this->assertStringContainsString( 'Start first check', Wizard::render( 2 ) );
		update_option( 'invoqk_first_report', [ 'ids' => [], 'startedAt' => 1 ] );
		$h = Wizard::render( 2 );
		$this->assertStringContainsString( 'Go to dashboard', $h );
		$this->assertStringNotContainsString( 'Start first check', $h );
	}

	public function test_redirect_skipped_during_ajax(): void {
		set_transient( 'invoqk_activation_redirect', 1 );
		$GLOBALS['invoqk_ajax'] = true;
		$this->assertNull( Wizard::redirectTarget() );
		$GLOBALS['invoqk_ajax'] = false;
		$this->assertNotNull( Wizard::redirectTarget() ); // not consumed by the ajax request
	}
}
