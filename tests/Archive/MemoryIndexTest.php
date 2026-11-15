<?php
namespace Invoqk\Tests\Archive;

use PHPUnit\Framework\TestCase;

final class MemoryIndexTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
	}

	private function row( int $order, array $extra = [] ): array {
		return $extra + [
			'order_id'       => $order,
			'version'        => 1,
			'invoice_number' => 'RE-' . $order,
			'invoice_date'   => '2026-11-0' . $order,
			'customer'       => 'Kunde ' . $order,
		];
	}

	public function test_append_links_prev_hash_and_head(): void {
		$ix   = new MemoryIndex();
		$hash = fn( $r ) => 'h' . $r['order_id'];
		$a    = $ix->append( $this->row( 1 ), $hash );
		$b    = $ix->append( $this->row( 2 ), $hash );
		$this->assertSame( str_repeat( '0', 64 ), $a['prev_hash'] );
		$this->assertSame( 'h1', $b['prev_hash'] );
		$this->assertSame( [ 'id' => 2, 'hash' => 'h2', 'count' => 2 ], $GLOBALS['invoqk_options']['invoqk_archive_head'] );
		$this->assertSame( 2, $ix->count() );
		$this->assertSame( 2, $ix->last()['id'] );
	}

	public function test_key_for_hash_ignores_tombstones(): void {
		$ix = new MemoryIndex();
		$a  = $ix->append( $this->row( 1, [ 'pdf_hash' => 'ph', 'pdf_key' => 'k/pdf' ] ), fn() => 'x' );
		$this->assertSame( 'k/pdf', $ix->keyForHash( 'ph' ) );
		$ix->tombstone( $a['id'], 7, 'why', 100 );
		$this->assertNull( $ix->keyForHash( 'ph' ) );
		$this->assertSame( 7, $ix->find( $a['id'] )['deleted_by'] );
	}

	public function test_usage_counts_shared_key_once(): void {
		$ix = new MemoryIndex();
		$ix->append( $this->row( 1, [ 'pdf_key' => 'k', 'pdf_size' => 100 ] ), fn() => 'x' );
		$b = $ix->append( $this->row( 2, [ 'pdf_key' => 'k', 'pdf_size' => 100 ] ), fn() => 'x' );
		$this->assertSame( 100, $ix->usage() );
		$this->assertSame( 1, $ix->liveRefs( 'k', $b['id'] ) );
	}

	public function test_set_status_updates_newest_row_only(): void {
		$ix = new MemoryIndex();
		$ix->append( $this->row( 1 ), fn() => 'x' );
		$ix->append( $this->row( 1, [ 'version' => 2 ] ), fn() => 'x' );
		$ix->setStatus( 1, 'passed' );
		$this->assertNull( $ix->find( 1 )['validation_status'] ?? null );
		$this->assertSame( 'passed', $ix->find( 2 )['validation_status'] );
		$this->assertSame( 2, $ix->maxVersion( 1 ) );
	}

	public function test_search_prefix_and_dates(): void {
		$ix = new MemoryIndex();
		foreach ( [ 1, 2, 3 ] as $o ) {
			$ix->append( $this->row( $o, [ 'sent_at' => $o * 100 ] ), fn() => 'x' );
		}
		$r = $ix->search( [ 'q' => 'RE-' ], 1, 2 );
		$this->assertSame( 3, $r['total'] );
		$this->assertSame( [ 3, 2 ], array_column( $r['rows'], 'id' ) );
		$this->assertSame( [ 1 ], array_column( $ix->search( [ 'q' => 'Kunde 1' ], 1, 20 )['rows'], 'id' ) );
		$this->assertSame( [ 3, 2 ], array_column( $ix->search( [ 'from' => '2026-11-02', 'to' => '2026-11-03' ], 1, 20 )['rows'], 'id' ) );
		$this->assertSame( [ 2, 1 ], array_column( $ix->search( [ 'deletable' => true, 'before' => 250 ], 1, 20 )['rows'], 'id' ) );
		$this->assertSame( [ 1 ], array_column( $ix->search( [], 2, 2 )['rows'], 'id' ) );
		$this->assertSame( [ 3 ], array_column( $ix->after( 2, 10 ), 'id' ) );
	}
}
