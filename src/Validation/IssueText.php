<?php
namespace Invoqk\Validation;

/** Picks display text for one stored issue. Returns plain strings; callers must escape on output. */
final class IssueText {
	public static function isGerman( string $locale ): bool {
		return str_starts_with( $locale, 'de' );
	}

	public static function for( array $issue, string $locale ): array {
		$str = static fn( $v ): string => is_scalar( $v ) ? (string) $v : '';
		$bt  = is_array( $issue['bt'] ?? null ) ? array_filter( $issue['bt'], 'is_string' ) : [];
		$de  = $str( $issue['messageDe'] ?? null );
		$use = self::isGerman( $locale ) && '' !== $de;
		return [
			'code'     => $bt ? implode( ', ', $bt ) : $str( $issue['id'] ?? null ),
			'text'     => $use ? $de : $str( $issue['message'] ?? null ),
			'raw'      => self::isGerman( $locale ) && ! $use,
			'severity' => 'warning' === ( $issue['severity'] ?? null ) ? 'warning' : 'error',
		];
	}
}
