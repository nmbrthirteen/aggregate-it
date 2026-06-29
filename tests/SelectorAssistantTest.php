<?php

namespace AggregateIt\Tests;

use AggregateIt\Ai\MockProvider;
use AggregateIt\Source\Scrape\SelectorAssistant;
use PHPUnit\Framework\TestCase;

final class SelectorAssistantTest extends TestCase {

	public function test_sample_strips_scripts_styles_comments_and_collapses_whitespace(): void {
		$html = "<html>\n<head><style>.a{color:red}</style></head>\n"
			. "<body><script>var x = 1;</script><!-- hidden -->\n"
			. "<table>   <tr class=\"event\">  <td>Title</td>  </tr></table></body></html>";

		$sample = SelectorAssistant::sample( $html );

		$this->assertStringNotContainsString( 'var x', $sample );
		$this->assertStringNotContainsString( 'color:red', $sample );
		$this->assertStringNotContainsString( 'hidden', $sample );
		$this->assertStringContainsString( 'tr class="event"', $sample );
		$this->assertStringNotContainsString( '  ', $sample ); // whitespace collapsed
	}

	public function test_sample_is_truncated(): void {
		$big    = '<div>' . str_repeat( 'x', 50000 ) . '</div>';
		$sample = SelectorAssistant::sample( $big );
		$this->assertLessThanOrEqual( 18000, mb_strlen( $sample ) );
	}

	public function test_suggest_returns_envelope(): void {
		$result = ( new SelectorAssistant( new MockProvider() ) )->suggest( '<table><tr class="event"><td>x</td></tr></table>' );

		$this->assertArrayHasKey( 'suggestion', $result );
		$this->assertIsArray( $result['suggestion'] );
		$this->assertArrayHasKey( 'tokens', $result );
		$this->assertArrayHasKey( 'cost_usd', $result );
	}
}
