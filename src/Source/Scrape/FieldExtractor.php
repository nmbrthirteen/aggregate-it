<?php

namespace AggregateIt\Source\Scrape;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls one configured field off a DOM node. A field rule is
 * { selector, attr, regex?, transform? }: locate a node (CSS selector, relative to the
 * given context), take its text/inner-HTML/attribute, optionally narrow with a capture
 * regex, then normalize. An empty/missing match yields '' rather than throwing.
 */
final class FieldExtractor {

	public function __construct( private \DOMXPath $xpath ) {}

	/** @param array{selector?:string,attr?:string,regex?:string,transform?:string} $rule */
	public function value( \DOMNode $context, array $rule ): string {
		$node     = $context;
		$selector = trim( (string) ( $rule['selector'] ?? '' ) );

		if ( $selector !== '' ) {
			$found = $this->xpath->query( CssToXpath::convert( $selector ), $context );
			if ( ! $found || $found->length === 0 ) {
				return '';
			}
			$node = $found->item( 0 );
		}

		$raw = $this->raw( $node, (string) ( $rule['attr'] ?? 'text' ) );

		$regex = (string) ( $rule['regex'] ?? '' );
		if ( $regex !== '' ) {
			$raw = $this->apply_regex( $regex, $raw );
		}

		return $this->transform( $raw, (string) ( $rule['transform'] ?? '' ) );
	}

	private function raw( \DOMNode $node, string $attr ): string {
		if ( $attr === '' || $attr === 'text' ) {
			return trim( $node->textContent );
		}
		if ( $attr === 'html' ) {
			$doc = $node->ownerDocument;
			$out = '';
			if ( $doc && $node->hasChildNodes() ) {
				foreach ( $node->childNodes as $child ) {
					$out .= $doc->saveHTML( $child );
				}
			}
			return trim( $out );
		}
		if ( $node instanceof \DOMElement ) {
			return trim( $node->getAttribute( $attr ) );
		}
		return '';
	}

	private function apply_regex( string $pattern, string $subject ): string {
		$delimited = '~' . str_replace( '~', '\~', $pattern ) . '~';
		if ( @preg_match( $delimited, $subject, $m ) === 1 ) {
			return $m[1] ?? $m[0];
		}
		return '';
	}

	private function transform( string $value, string $transform ): string {
		switch ( $transform ) {
			case 'lower':
				return mb_strtolower( $value );
			case 'upper':
				return mb_strtoupper( $value );
			case 'collapse':
				return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
			default:
				return trim( $value );
		}
	}
}
