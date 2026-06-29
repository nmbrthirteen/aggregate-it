<?php

namespace AggregateIt\Source\Scrape;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the common CSS-selector subset to an XPath 1.0 expression so scrape configs can
 * be written in CSS (what people know) while extraction uses DOMXPath. Supports tag, #id,
 * .class, [attr], [attr=val], [attr^=val], [attr*=val], descendant (space) and child (>)
 * combinators. A value that already looks like XPath is passed through untouched.
 */
final class CssToXpath {

	public static function convert( string $selector ): string {
		$selector = trim( $selector );
		if ( $selector === '' ) {
			return './/*';
		}
		if ( $selector[0] === '/' || str_starts_with( $selector, './' ) || str_starts_with( $selector, '(' ) ) {
			return $selector;
		}

		$parts = preg_split( '/\s*(>)\s*|\s+/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$xpath = '.';
		$child = false;

		foreach ( (array) $parts as $part ) {
			if ( $part === '>' ) {
				$child = true;
				continue;
			}
			$xpath .= ( $child ? '/' : '//' ) . self::step( $part );
			$child = false;
		}

		return $xpath;
	}

	private static function step( string $compound ): string {
		$tag   = '*';
		$preds = [];
		$i     = 0;
		$len   = strlen( $compound );

		if ( $i < $len && ctype_alpha( $compound[ $i ] ) ) {
			$j = $i;
			while ( $j < $len && ( ctype_alnum( $compound[ $j ] ) || $compound[ $j ] === '-' || $compound[ $j ] === '_' ) ) {
				$j++;
			}
			$tag = substr( $compound, $i, $j - $i );
			$i   = $j;
		}

		while ( $i < $len ) {
			$c = $compound[ $i ];
			if ( $c === '#' ) {
				$i++;
				$preds[] = '@id=' . self::quote( self::ident( $compound, $i ) );
			} elseif ( $c === '.' ) {
				$i++;
				$class   = self::ident( $compound, $i );
				$preds[] = "contains(concat(' ', normalize-space(@class), ' '), " . self::quote( ' ' . $class . ' ' ) . ')';
			} elseif ( $c === '[' ) {
				$i++;
				$close   = strpos( $compound, ']', $i );
				$expr    = $close === false ? substr( $compound, $i ) : substr( $compound, $i, $close - $i );
				$i       = $close === false ? $len : $close + 1;
				$preds[] = self::attr( trim( $expr ) );
			} else {
				$i++; // pseudo-classes and unknown tokens are ignored rather than failing
			}
		}

		return $preds ? $tag . '[' . implode( ' and ', $preds ) . ']' : $tag;
	}

	private static function ident( string $s, int &$i ): string {
		$start = $i;
		$len   = strlen( $s );
		while ( $i < $len && ( ctype_alnum( $s[ $i ] ) || $s[ $i ] === '-' || $s[ $i ] === '_' ) ) {
			$i++;
		}
		return substr( $s, $start, $i - $start );
	}

	private static function attr( string $expr ): string {
		foreach ( [ '*=' => 'contains', '^=' => 'starts-with' ] as $op => $fn ) {
			$pos = strpos( $expr, $op );
			if ( $pos !== false ) {
				$name = trim( substr( $expr, 0, $pos ) );
				$val  = self::unquote( substr( $expr, $pos + 2 ) );
				return $fn . '(@' . $name . ', ' . self::quote( $val ) . ')';
			}
		}

		$pos = strpos( $expr, '=' );
		if ( $pos !== false ) {
			$name = trim( substr( $expr, 0, $pos ) );
			$val  = self::unquote( substr( $expr, $pos + 1 ) );
			return '@' . $name . '=' . self::quote( $val );
		}

		return '@' . trim( $expr );
	}

	private static function unquote( string $v ): string {
		$v = trim( $v );
		if ( strlen( $v ) >= 2 && ( $v[0] === '"' || $v[0] === "'" ) && $v[ strlen( $v ) - 1 ] === $v[0] ) {
			return substr( $v, 1, -1 );
		}
		return $v;
	}

	/** XPath 1.0 has no string escapes, so a value containing a quote must be built with concat(). */
	private static function quote( string $v ): string {
		if ( strpos( $v, "'" ) === false ) {
			return "'" . $v . "'";
		}
		if ( strpos( $v, '"' ) === false ) {
			return '"' . $v . '"';
		}
		$parts = explode( "'", $v );
		return 'concat(' . implode( ", \"'\", ", array_map( static fn ( $p ) => "'" . $p . "'", $parts ) ) . ')';
	}
}
