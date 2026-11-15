<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Archive;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase {
	public function test_dir_accepts_only_absolute_paths(): void {
		$this->assertSame( '/srv/archive', Archive::dir( '/srv/archive' ) );
		$this->assertSame( 'C:\\archive', Archive::dir( 'C:\\archive' ) );
		$this->assertSame( 'D:/archive', Archive::dir( 'D:/archive' ) );
		$this->assertNull( Archive::dir( '' ) );
		$this->assertNull( Archive::dir( 'archive' ) );
		$this->assertNull( Archive::dir( 5 ) );
		$this->assertNull( Archive::dir( null ) );
	}
}
