<?php
namespace Invoqk\Archive;

/** Registers every archive hook; called outside is_admin() because capture runs in cron and on the front end. */
final class Hooks {
	public static function boot(): void {
		Capture::boot();
		Entry::boot();
		Actions::boot();
		add_action( Verify::HOOK, static fn( $afterId, $prevHash ) => Verify::run( (int) $afterId, (string) $prevHash ), 10, 2 );
		add_action( 'admin_init', [ WpdbIndex::class, 'ensure' ] );
	}
}
