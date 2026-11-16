<?php
use Invoqk\Archive\Archive;
use Invoqk\Archive\Capture;
use Invoqk\Archive\LocalStorage;
use Invoqk\Fallback\Mail;
use Invoqk\Fallback\Store;
use Invoqk\Validation\Record;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_actions'] = [];
		Capture::reset();
		Archive::reset();
		$this->base               = sys_get_temp_dir() . '/invoqk-mail-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['invoqk_uploads'] = $this->base . '/up';
		Archive::$storage         = new LocalStorage( $this->base . '/arch' );
		add_filter( 'invoqk_is_pro', fn() => true );
	}

	protected function tearDown(): void {
		Capture::reset();
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function order( string $status = 'passed', string $source = 'invoqk_fallback' ): \WC_Order {
		$o = $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
		Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$o->update_meta_data( '_invoqk_source', $source );
		if ( 'pending' !== $status ) {
			$o->update_meta_data( Record::KEY, [ 'status' => $status ] );
		}
		return $o;
	}

	private function email( bool $customer = true ): object {
		return new class( $customer ) {
			public function __construct( private bool $c ) {}
			public function is_customer_email(): bool { return $this->c; }
		};
	}

	public function test_attaches_when_passed(): void {
		$o = $this->order();
		$this->assertSame( [ Store::path( $o ) ], Mail::attach( [], 'customer_completed_order', $o, $this->email() ) );
		$this->assertSame( 1, count( Mail::attach( [], 'customer_processing_order', $o, $this->email() ) ) );
	}

	public function test_not_attached_when_failed_pending_or_error(): void {
		foreach ( [ 'failed', 'pending', 'error' ] as $s ) {
			$this->assertSame( [], Mail::attach( [], 'customer_completed_order', $this->order( $s ), $this->email() ), $s );
		}
	}

	public function test_never_attached_to_admin_mail(): void {
		$this->assertSame( [], Mail::attach( [], 'customer_completed_order', $this->order(), $this->email( false ) ) );
		$this->assertSame( [], Mail::attach( [], 'customer_completed_order', $this->order(), null ) );
	}

	public function test_not_attached_on_free(): void {
		$GLOBALS['invoqk_filters'] = [];
		$this->assertSame( [], Mail::attach( [], 'customer_completed_order', $this->order(), $this->email() ) );
	}

	public function test_not_attached_for_other_email_ids(): void {
		$this->assertSame( [], Mail::attach( [], 'customer_invoice', $this->order(), $this->email() ) );
	}

	public function test_records_path_for_archive_capture(): void {
		$o   = $this->order();
		$att = Mail::attach( [], 'customer_completed_order', $o, $this->email() );
		Capture::stage( $att, 'customer_completed_order', $o, $this->email() );
		$sets = glob( Archive::storage()->staging() . '/*', GLOB_ONLYDIR );
		$this->assertCount( 1, $sets );
		$m = json_decode( file_get_contents( "{$sets[0]}/manifest.json" ), true );
		$this->assertSame( [ 'xml' ], array_column( $m['files'], 'format' ) );
	}

	public function test_other_attachments_untouched(): void {
		$r = Mail::attach( [ '/a.pdf' ], 'customer_completed_order', $this->order(), $this->email() );
		$this->assertSame( '/a.pdf', $r[0] );
		$this->assertCount( 2, $r );
		$this->assertSame( 'junk', Mail::attach( 'junk', 'customer_completed_order', $this->order(), $this->email() ) );
	}

	public function test_not_attached_when_source_is_not_fallback(): void {
		$this->assertSame( [], Mail::attach( [], 'customer_completed_order', $this->order( 'passed', 'wp_overnight' ), $this->email() ) );
	}

	public function test_not_attached_twice(): void {
		$o    = $this->order();
		$once = Mail::attach( [], 'customer_completed_order', $o, $this->email() );
		$this->assertSame( $once, Mail::attach( $once, 'customer_completed_order', $o, $this->email() ) );
	}
}
