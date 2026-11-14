<?php
namespace Invoqk\Admin;

use Invoqk\Classification\Classifier;
use Invoqk\Validation\FixMap;
use Invoqk\Validation\IssueText;
use Invoqk\Validation\Record;

/** Readiness numbers for the dashboard: last 30 days, up to 2,000 orders, cached 600 s. */
final class Readiness {
	private const CACHE = 'invoqk_readiness';
	private const PAGES = 20;
	private const PER   = 100;

	/** One transient holding the result per locale; flush() drops all of them. */
	public static function get( string $locale ): array {
		$all = get_transient( self::CACHE );
		$all = is_array( $all ) ? $all : [];
		if ( ! isset( $all[ $locale ] ) ) {
			$all[ $locale ] = self::compute( $locale );
			set_transient( self::CACHE, $all, 600 );
		}
		return $all[ $locale ];
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	public static function compute( string $locale ): array {
		$n      = [ 'passed' => 0, 'failed' => 0, 'missing' => 0, 'unchecked' => 0, 'notRequired' => 0 ];
		$groups = [];
		$capped = false;
		for ( $page = 1; $page <= self::PAGES; $page++ ) {
			$ids = wc_get_orders( [
				'type'         => 'shop_order',
				'limit'        => self::PER,
				'paged'        => $page,
				'return'       => 'ids',
				'date_created' => '>' . strtotime( '-30 days' ),
			] );
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( ! $o ) {
					continue;
				}
				if ( ! Classifier::isRequired( $o ) ) {
					++$n['notRequired'];
				} elseif ( '' === (string) $o->get_meta( '_invoqk_source' ) ) {
					++$n['unchecked'];
				} else {
					$s = Record::status( $o );
					++$n[ 'passed' === $s || 'failed' === $s ? $s : ( 'none' === $s ? 'missing' : 'unchecked' ) ];
					if ( 'failed' === $s ) {
						self::group( $groups, $o, $locale );
					}
				}
			}
			if ( count( $ids ) < self::PER ) {
				break;
			}
			$capped = self::PAGES === $page;
		}
		$required = $n['passed'] + $n['failed'] + $n['missing'] + $n['unchecked'];
		return $n + [
			'required' => $required,
			'score'    => $required ? (int) round( 100 * $n['passed'] / $required ) : null,
			'capped'   => $capped,
			'top'      => self::top( $groups ),
		];
	}

	private static function group( array &$groups, \WC_Order $o, string $locale ): void {
		$seen = [];
		foreach ( (array) ( Record::get( $o )['issues'] ?? [] ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$t = IssueText::for( $issue, $locale );
			if ( 'error' !== $t['severity'] || isset( $seen[ $t['code'] ] ) ) {
				continue;
			}
			$seen[ $t['code'] ] = true;
			$g = $groups[ $t['code'] ] ?? [ 'code' => $t['code'], 'text' => $t['text'], 'count' => 0, 'raw' => $t['raw'], 'fixKey' => $issue['fix'] ?? null, 'order' => $o ];
			++$g['count'];
			if ( self::newer( $o, $g['order'] ) ) {
				$g['order']  = $o;
				$g['fixKey'] = $issue['fix'] ?? null;
			}
			$groups[ $t['code'] ] = $g;
		}
	}

	private static function newer( \WC_Order $a, \WC_Order $b ): bool {
		$ta = $a->get_date_created()?->getTimestamp() ?? 0;
		$tb = $b->get_date_created()?->getTimestamp() ?? 0;
		return $ta > $tb || ( $ta === $tb && $a->get_id() > $b->get_id() );
	}

	private static function top( array $groups ): array {
		usort( $groups, static fn( $a, $b ) => [ $b['count'], $a['code'] ] <=> [ $a['count'], $b['code'] ] );
		$out = [];
		foreach ( array_slice( $groups, 0, 5 ) as $g ) {
			$fix   = is_string( $g['fixKey'] ) ? $g['fixKey'] : null;
			$out[] = [ 'code' => $g['code'], 'text' => $g['text'], 'count' => $g['count'], 'raw' => $g['raw'], 'fix' => FixMap::link( $fix, $g['order'] ) ];
		}
		return $out;
	}
}
