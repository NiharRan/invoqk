<?php
use Invoqk\Archive\Archive;
use Invoqk\Archive\Capture;
use Invoqk\Archive\LocalStorage;
use Invoqk\Guard\Guard;
use PHPUnit\Framework\TestCase;

final class CaptureTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_actions'] = [];
		Guard::reset();
		Capture::reset();
		Archive::reset();
		$this->base       = sys_get_temp_dir() . '/invoqk-cap-' . bin2hex( random_bytes( 4 ) );
		Archive::$storage = new LocalStorage( $this->base );
		add_action( 'woocommerce_email_sent', [ Capture::class, 'sent' ], 10, 3 );
	}

	protected function tearDown(): void {
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function pro(): void {
		add_filter( 'invoqk_is_pro', fn() => true );
	}

	private function order(): \WC_Order {
		return $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
	}

	private function file( string $name, string $body = 'x' ): string {
		@mkdir( $this->base, 0777, true );
		$p = "{$this->base}/$name";
		file_put_contents( $p, $body );
		return $p;
	}

	private function email( bool $customer = true ): object {
		return new class( $customer ) {
			public function __construct( private bool $c ) {}
			public function is_customer_email(): bool { return $this->c; }
		};
	}

	private function rec( \WC_Order $o, string $path, string $format = '' ): void {
		Capture::record( $path, 'invoice', (object) [ 'order' => $o ], $format );
	}

	private function sets(): array {
		return glob( Archive::storage()->staging() . '/*', GLOB_ONLYDIR ) ?: [];
	}

	private function queued(): array {
		return array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => 'invoqk_archive_entry' === $a['hook'] ) );
	}

	public function test_stages_and_queues_on_success(): void {
		$this->pro();
		$o   = $this->order();
		$pdf = $this->file( 'a.pdf', 'PDF' );
		$xml = $this->file( 'a.xml', 'XML' );
		$this->rec( $o, $pdf, 'pdf' );
		$this->rec( $o, $xml );
		$e = $this->email();
		Capture::stage( [ $pdf, $xml ], 'customer_completed_order', $o, $e );
		$sets = $this->sets();
		$this->assertCount( 1, $sets );
		$m = json_decode( file_get_contents( "{$sets[0]}/manifest.json" ), true );
		$this->assertSame( 5, $m['order_id'] );
		$this->assertSame( 'customer_completed_order', $m['email_id'] );
		$this->assertNull( $m['sent'] );
		$this->assertNull( $m['sent_at'] );
		$this->assertSame( [ 'pdf', 'xml' ], array_column( $m['files'], 'format' ) );
		$this->assertSame( 'PDF', file_get_contents( "{$sets[0]}/{$m['files'][0]['file']}" ) );
		do_action( 'woocommerce_email_sent', true, 'customer_completed_order', $e );
		$m = json_decode( file_get_contents( "{$sets[0]}/manifest.json" ), true );
		$this->assertTrue( $m['sent'] );
		$this->assertEqualsWithDelta( time(), $m['sent_at'], 2 );
		$q = $this->queued();
		$this->assertCount( 1, $q );
		$this->assertSame( [ basename( $sets[0] ) ], $q[0]['args'] );
		$this->assertSame( 'invoqk', $q[0]['group'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', basename( $sets[0] ) );
	}

	public function test_mail_failure_deletes_set(): void {
		$this->pro();
		$o   = $this->order();
		$pdf = $this->file( 'a.pdf' );
		$this->rec( $o, $pdf, 'pdf' );
		$e = $this->email();
		Capture::stage( [ $pdf ], 'x', $o, $e );
		$this->assertCount( 1, $this->sets() );
		do_action( 'woocommerce_email_sent', false, 'x', $e );
		$this->assertCount( 0, $this->sets() );
		$this->assertCount( 0, $this->queued() );
	}

	public function test_guard_stripped_paths_not_staged(): void {
		$this->pro();
		$o = $this->order();
		$o->meta['_invoqk_source'] = 'wpo';
		$pdf = $this->file( 'a.pdf' );
		$this->rec( $o, $pdf, 'pdf' );
		Guard::record( $pdf, 'invoice', (object) [ 'order' => $o ], 'pdf' );
		$out = Guard::filterAttachments( [ $pdf ], 'customer_completed_order', $o );
		Capture::stage( $out, 'customer_completed_order', $o, $this->email() );
		$this->assertCount( 0, $this->sets() );
	}

	public function test_guard_own_send_staged_without_pro(): void {
		$o = $this->order();
		$o->meta['_invoqk_source'] = 'wpo';
		$pdf = $this->file( 'a.pdf' );
		$e   = $this->email();
		$mailer = function ( int $id ) use ( $o, $pdf, $e ) {
			$this->rec( $o, $pdf, 'pdf' );
			Capture::stage( [ $pdf ], 'customer_invoice', $o, $e );
			do_action( 'woocommerce_email_sent', true, 'customer_invoice', $e );
		};
		Guard::send( 5, 'released', [], $mailer );
		$this->assertCount( 1, $this->queued() );
	}

	public function test_admin_email_untouched_nothing_staged(): void {
		$this->pro();
		$o   = $this->order();
		$pdf = $this->file( 'a.pdf' );
		$this->rec( $o, $pdf, 'pdf' );
		Capture::stage( [ $pdf ], 'new_order', $o, $this->email( false ) );
		$this->assertCount( 0, $this->sets() );
	}

	public function test_free_nothing_staged(): void {
		$o   = $this->order();
		$pdf = $this->file( 'a.pdf' );
		$this->rec( $o, $pdf, 'pdf' );
		Capture::stage( [ $pdf ], 'x', $o, $this->email() );
		$this->assertCount( 0, $this->sets() );
	}

	public function test_attachments_returned_identical(): void {
		$this->pro();
		$o   = $this->order();
		$pdf = $this->file( 'a.pdf' );
		$this->rec( $o, $pdf, 'pdf' );
		$in = [ '/tmp/terms.pdf', $pdf ];
		$this->assertSame( $in, Capture::stage( $in, 'x', $o, $this->email() ) );
		$this->assertSame( 'junk', Capture::stage( 'junk', 'x', $o, $this->email() ) );
	}
}
