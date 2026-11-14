<?php
namespace Invoqk\Sources;

final class Registry {
	/** @var SourceAdapter[] */
	private array $adapters;

	public function __construct( SourceAdapter ...$adapters ) {
		$this->adapters = $adapters;
	}

	/** Built-in adapters in fixed priority order, then the `invoqk_source_adapters` filter. */
	public static function default(): self {
		$list = [];
		foreach ( [ GermanizedProAdapter::class, GermanMarketAdapter::class, WpOvernightAdapter::class ] as $class ) {
			if ( class_exists( $class ) ) {
				$list[] = new $class();
			}
		}
		$list = apply_filters( 'invoqk_source_adapters', $list );
		return new self( ...array_values( array_filter( $list, fn( $a ) => $a instanceof SourceAdapter ) ) );
	}

	/** @return SourceAdapter[] */
	public function all(): array {
		return $this->adapters;
	}

	public function get( string $id ): ?SourceAdapter {
		foreach ( $this->adapters as $a ) {
			if ( $a->id() === $id ) {
				return $a;
			}
		}
		return null;
	}

	/** @return array{adapter: SourceAdapter, file: InvoiceFile}|null first hit wins */
	public function detect( \WC_Order $order ): ?array {
		foreach ( $this->adapters as $adapter ) {
			try {
				if ( ! $adapter->isAvailable() ) {
					continue;
				}
				$file = $adapter->find( $order );
			} catch ( \Throwable $e ) {
				wc_get_logger()->error( $adapter->id() . ': ' . $e->getMessage(), [ 'source' => 'invoqk' ] );
				continue;
			}
			if ( $file ) {
				return [ 'adapter' => $adapter, 'file' => $file ];
			}
		}
		return null;
	}
}
