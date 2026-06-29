<?php

namespace AggregateIt\Source\Parser;

use AggregateIt\Source\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a source into a list of normalized entries for the importer. RSS/JSON feeds and
 * HTML scrapers are different implementations behind this one seam.
 *
 * @phpstan-type Entry array{guid:string,url:string,title:string,content:string,image:string,date:int,fields?:array<string,string>}
 */
interface SourceParser {

	/** @return array<int,array<string,mixed>> */
	public function parse( Source $source ): array;
}
