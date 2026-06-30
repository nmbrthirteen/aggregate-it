<?php

namespace AggregateIt\Tests;

use AggregateIt\Publish\Rules;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase {

	private const NOW = 1_000_000_000;

	public function test_date_condition_sets_status(): void {
		$rules = [
			[ 'field' => 'date', 'op' => 'date_future', 'set_key' => 'event_status', 'set_value' => 'Upcoming' ],
			[ 'field' => 'date', 'op' => 'date_past', 'set_key' => 'event_status', 'set_value' => 'Past' ],
		];

		$future = Rules::apply( [ 'date' => gmdate( 'Y-m-d', self::NOW + 200000 ) ], $rules, self::NOW );
		$past   = Rules::apply( [ 'date' => gmdate( 'Y-m-d', self::NOW - 200000 ) ], $rules, self::NOW );

		$this->assertSame( 'Upcoming', $future['event_status'] );
		$this->assertSame( 'Past', $past['event_status'] );
	}

	public function test_value_template_copies_and_formats_fields(): void {
		$out = Rules::apply(
			[ 'end' => '2026-07-01 18:00:00', 'venue' => 'ExCeL' ],
			[
				[ 'field' => 'end', 'op' => 'not_empty', 'set_key' => 'expire_event', 'set_value' => '{end|Y-m-d}' ],
				[ 'field' => '', 'op' => 'always', 'set_key' => 'place', 'set_value' => '{venue}' ],
			],
			self::NOW
		);

		$this->assertSame( '2026-07-01', $out['expire_event'] );
		$this->assertSame( 'ExCeL', $out['place'] );
	}

	public function test_equals_contains_and_numeric_ops(): void {
		$rules = [
			[ 'field' => 'type', 'op' => 'equals', 'value' => 'conference', 'set_key' => 'kind', 'set_value' => 'Conference' ],
			[ 'field' => 'title', 'op' => 'contains', 'value' => 'expo', 'set_key' => 'flag', 'set_value' => 'yes' ],
			[ 'field' => 'price', 'op' => 'gt', 'value' => '100', 'set_key' => 'tier', 'set_value' => 'premium' ],
		];

		$out = Rules::apply( [ 'type' => 'conference', 'title' => 'Gaming Expo 2026', 'price' => '250' ], $rules, self::NOW );

		$this->assertSame( 'Conference', $out['kind'] );
		$this->assertSame( 'yes', $out['flag'] );
		$this->assertSame( 'premium', $out['tier'] );
	}

	public function test_unmatched_rule_writes_nothing_and_empty_key_skipped(): void {
		$out = Rules::apply(
			[ 'type' => 'meetup' ],
			[
				[ 'field' => 'type', 'op' => 'equals', 'value' => 'conference', 'set_key' => 'kind', 'set_value' => 'Conference' ],
				[ 'field' => 'type', 'op' => 'always', 'set_key' => '', 'set_value' => 'x' ],
			],
			self::NOW
		);

		$this->assertSame( [], $out );
	}
}
