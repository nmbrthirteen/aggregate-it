<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\Scrape\CssToXpath;
use PHPUnit\Framework\TestCase;

final class CssToXpathTest extends TestCase {

	public function test_class_selector(): void {
		$this->assertSame(
			".//*[contains(concat(' ', normalize-space(@class), ' '), ' event ')]",
			CssToXpath::convert( '.event' )
		);
	}

	public function test_tag_with_class(): void {
		$this->assertSame(
			".//a[contains(concat(' ', normalize-space(@class), ' '), ' name ')]",
			CssToXpath::convert( 'a.name' )
		);
	}

	public function test_id_selector(): void {
		$this->assertSame( ".//*[@id='main']", CssToXpath::convert( '#main' ) );
	}

	public function test_descendant_and_child_combinators(): void {
		$this->assertSame( './/table//tr', CssToXpath::convert( 'table tr' ) );
		$this->assertSame( './/ul/li', CssToXpath::convert( 'ul > li' ) );
	}

	public function test_attribute_selectors(): void {
		$this->assertSame( './/a[@href]', CssToXpath::convert( 'a[href]' ) );
		$this->assertSame( ".//*[@data-id='5']", CssToXpath::convert( '[data-id="5"]' ) );
		$this->assertSame( ".//a[starts-with(@href, '/event')]", CssToXpath::convert( 'a[href^="/event"]' ) );
	}

	public function test_passthrough_xpath(): void {
		$this->assertSame( '//div[@id="x"]', CssToXpath::convert( '//div[@id="x"]' ) );
	}
}
