<?php
use Invoqk\Admin\Badge;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase {
	public function test_each_status_has_text_and_class(): void {
		foreach ( [ 'passed' => [ 'Valid', 'status-completed' ], 'failed' => [ 'Invalid', 'status-failed' ], 'error' => [ 'Could not check', 'status-on-hold' ], 'limit' => [ 'Limit reached', 'status-on-hold' ], 'pending' => [ 'Checking', 'status-pending' ] ] as $s => [ $t, $c ] ) {
			$h = Badge::html( $s );
			$this->assertStringContainsString( "<span>$t</span>", $h );
			$this->assertStringContainsString( $c, $h );
		}
		$this->assertSame( '', Badge::html( 'none' ) );
	}

	public function test_refund_state(): void {
		$this->assertStringContainsString( '<span>Corrective e-invoice open</span>', Badge::html( 'refund' ) );
	}
}
