<?php

namespace AggregateIt\Tests;

use AggregateIt\Cluster\Deduplicator;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase {

	private function similarity( string $a_title, string $a_body, string $b_title, string $b_body ): float {
		$dedup  = new Deduplicator( new Settings() );
		$ref    = new \ReflectionClass( $dedup );
		$tokens = $ref->getMethod( 'tokens' );
		$tokens->setAccessible( true );
		$cosine = $ref->getMethod( 'cosine' );
		$cosine->setAccessible( true );

		$a  = $tokens->invoke( $dedup, $a_title, $a_body );
		$b  = $tokens->invoke( $dedup, $b_title, $b_body );
		$na = sqrt( array_sum( array_map( static fn ( $v ) => $v * $v, $a ) ) );
		$nb = sqrt( array_sum( array_map( static fn ( $v ) => $v * $v, $b ) ) );

		return $cosine->invoke( $dedup, $a, $na, $b, $nb );
	}

	public function test_same_story_paraphrases_score_above_threshold(): void {
		$sim = $this->similarity(
			"Bally's Faces Increased Pressure in Chicago, Las Vegas with New York Project on Horizon",
			"Bally's faces mounting pressure in Chicago and Las Vegas as its New York casino project approaches construction. The company must juggle several developments at once.",
			"Bally's Faces Challenges as New York Casino Construction Approaches",
			"Bally's is under pressure in Chicago and Las Vegas while the New York casino construction project looms, forcing the company to manage multiple developments."
		);
		$this->assertGreaterThan( 0.50, $sim );
	}

	public function test_different_stories_about_same_entity_score_low(): void {
		$sim = $this->similarity(
			'Ballys reports strong quarterly earnings beating estimates',
			'Ballys posted quarterly revenue above analyst estimates driven by online betting growth and interactive segment gains.',
			'Ballys Faces Challenges as New York Casino Construction Approaches',
			'Ballys is under pressure in Chicago and Las Vegas while the New York casino construction project looms.'
		);
		$this->assertLessThan( 0.40, $sim );
	}

	public function test_unrelated_stories_score_near_zero(): void {
		$sim = $this->similarity(
			'UKGC fines Stakelogic over slot speed rule breaches',
			'The UK Gambling Commission fined Stakelogic for breaching slot speed rules designed to slow play.',
			'Entain sells 20% of CEE business launching full exit strategy',
			'Entain announced the sale of a stake in its central and eastern Europe business as part of an exit.'
		);
		$this->assertLessThan( 0.40, $sim );
	}
}
