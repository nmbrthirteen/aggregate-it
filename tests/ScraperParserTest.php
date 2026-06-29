<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\Parser\ScraperParser;
use PHPUnit\Framework\TestCase;

final class ScraperParserTest extends TestCase {

	private function parser(): ScraperParser {
		// HttpFetcher is unused by the pure entry-building methods under test.
		return new ScraperParser( new \AggregateIt\Source\HttpFetcher() );
	}

	private function config(): array {
		return [
			'discovery'  => [ 'mode' => 'list', 'item_selector' => 'tr.event' ],
			'extraction' => [
				'fields' => [
					'title'    => [ 'selector' => 'a.name', 'attr' => 'text' ],
					'url'      => [ 'selector' => 'a.name', 'attr' => 'href' ],
					'date'     => [ 'selector' => '.date', 'attr' => 'text' ],
					'location' => [ 'selector' => '.loc', 'attr' => 'text' ],
				],
			],
		];
	}

	public function test_extracts_rows_with_standard_and_custom_fields(): void {
		$html = '<table><tbody>'
			. '<tr class="event"><td class="date">2026-07-01</td><td><a class="name" href="/igb-live_123.aspx">iGB LiVE 2026</a></td><td class="loc">London, UK</td></tr>'
			. '<tr class="event"><td class="date">2026-07-06</td><td><a class="name" href="https://other.test/sigma">SiGMA</a></td><td class="loc">Malta</td></tr>'
			. '</tbody></table>';

		$entries = $this->parser()->entries_from_html( $html, $this->config(), 'https://www.igamingcalendar.com/' );

		$this->assertCount( 2, $entries );

		$this->assertSame( 'iGB LiVE 2026', $entries[0]['title'] );
		$this->assertSame( 'https://www.igamingcalendar.com/igb-live_123.aspx', $entries[0]['url'] );
		$this->assertSame( 'https://www.igamingcalendar.com/igb-live_123.aspx', $entries[0]['guid'] );
		$this->assertSame( 'London, UK', $entries[0]['fields']['location'] );
		$this->assertGreaterThan( 0, $entries[0]['date'] );

		// Absolute URLs in the source are left intact.
		$this->assertSame( 'https://other.test/sigma', $entries[1]['url'] );
		$this->assertSame( 'Malta', $entries[1]['fields']['location'] );
	}

	public function test_no_rows_when_item_selector_misses(): void {
		$entries = $this->parser()->entries_from_html( '<div>nothing</div>', $this->config(), 'https://x.test/' );
		$this->assertSame( [], $entries );
	}

	public function test_regex_narrows_a_field(): void {
		$cfg = [
			'discovery'  => [ 'item_selector' => '.row' ],
			'extraction' => [
				'fields' => [
					'guid' => [ 'selector' => '.id', 'attr' => 'text', 'regex' => '_(\\d+)\\.aspx' ],
				],
			],
		];
		$html    = '<div class="row"><span class="id">event-name_99887.aspx</span></div>';
		$entries = $this->parser()->entries_from_html( $html, $cfg, 'https://x.test/' );

		$this->assertCount( 1, $entries );
		$this->assertSame( '99887', $entries[0]['guid'] );
	}

	public function test_sitemap_mode_filters_locs(): void {
		$xml = '<urlset>'
			. '<url><loc>https://x.test/Event-One_001.aspx</loc></url>'
			. '<url><loc>https://x.test/about.aspx</loc></url>'
			. '<url><loc>https://x.test/Event-Two_002.aspx</loc></url>'
			. '</urlset>';

		$entries = $this->parser()->entries_from_sitemap( $xml, '_\\d+\\.aspx$' );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'https://x.test/Event-One_001.aspx', $entries[0]['url'] );
		$this->assertSame( 'https://x.test/Event-Two_002.aspx', $entries[1]['guid'] );
	}
}
