<?php

namespace AggregateIt\Publish;

defined( 'ABSPATH' ) || exit;

/**
 * Generic field-condition engine. Each rule tests an extracted field and, when it matches,
 * writes a meta key to a value. The value may be a literal or reference fields via {field}
 * or {field|date-format} placeholders. Rules run in order; later writes to the same key win.
 */
final class Rules {

	public const OPS = [ 'always', 'equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty', 'date_past', 'date_future', 'gt', 'lt' ];

	/**
	 * @param array<string,string>             $values field name => value
	 * @param array<int,array<string,mixed>>   $rules
	 * @return array<string,string> meta key => value
	 */
	public static function apply( array $values, array $rules, int $now ): array {
		$out = [];
		foreach ( $rules as $rule ) {
			$set_key = sanitize_key( (string) ( $rule['set_key'] ?? '' ) );
			if ( $set_key === '' ) {
				continue;
			}
			$field = (string) ( $rule['field'] ?? '' );
			$raw   = (string) ( $values[ $field ] ?? '' );
			if ( self::matches( (string) ( $rule['op'] ?? 'always' ), $raw, (string) ( $rule['value'] ?? '' ), $now ) ) {
				$out[ $set_key ] = self::resolve( (string) ( $rule['set_value'] ?? '' ), $values );
			}
		}
		return $out;
	}

	private static function matches( string $op, string $raw, string $value, int $now ): bool {
		switch ( $op ) {
			case 'always':
				return true;
			case 'equals':
				return $raw === $value;
			case 'not_equals':
				return $raw !== $value;
			case 'contains':
				return $value !== '' && stripos( $raw, $value ) !== false;
			case 'not_contains':
				return $value !== '' && stripos( $raw, $value ) === false;
			case 'empty':
				return trim( $raw ) === '';
			case 'not_empty':
				return trim( $raw ) !== '';
			case 'date_past':
				$ts = strtotime( $raw );
				return $ts !== false && $ts < $now;
			case 'date_future':
				$ts = strtotime( $raw );
				return $ts !== false && $ts > $now;
			case 'gt':
				return is_numeric( $raw ) && is_numeric( $value ) && (float) $raw > (float) $value;
			case 'lt':
				return is_numeric( $raw ) && is_numeric( $value ) && (float) $raw < (float) $value;
			default:
				return false;
		}
	}

	/** @param array<string,string> $values */
	private static function resolve( string $template, array $values ): string {
		if ( strpos( $template, '{' ) === false ) {
			return $template;
		}
		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)(?:\|([^}]+))?\}/i',
			static function ( array $m ) use ( $values ): string {
				$raw = (string) ( $values[ strtolower( $m[1] ) ] ?? '' );
				if ( isset( $m[2] ) && $m[2] !== '' ) {
					$ts = strtotime( $raw );
					return $ts !== false ? gmdate( $m[2], $ts ) : '';
				}
				return $raw;
			},
			$template
		);
	}
}
