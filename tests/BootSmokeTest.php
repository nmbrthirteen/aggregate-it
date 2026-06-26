<?php

namespace AggregateIt\Tests;

use AggregateIt\Ai\AiProvider;
use AggregateIt\Database\Schema;
use AggregateIt\Pipeline\Pipeline;
use AggregateIt\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Boots the whole container with the WP stubs to catch wiring / constructor-arity bugs
 * automatically — the thing only a real-ish boot can find.
 */
final class BootSmokeTest extends TestCase {

	public function test_container_boots_and_every_active_stage_is_registered(): void {
		$GLOBALS['__options'] = [];

		$plugin = Plugin::instance();
		$plugin->boot();

		$pipeline = $plugin->pipeline();
		foreach ( Pipeline::default_order() as $state ) {
			if ( in_array( $state, Schema::TERMINAL, true ) ) {
				continue;
			}
			$this->assertNotNull( $pipeline->stage_for( $state ), "missing stage for '{$state}'" );
		}
	}

	public function test_accessors_and_provider_resolve(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( \AggregateIt\Settings::class, $plugin->settings() );
		$this->assertInstanceOf( \AggregateIt\Queue\ItemStore::class, $plugin->items() );
		$this->assertInstanceOf( \AggregateIt\Source\SourceRepository::class, $plugin->sources() );
		$this->assertInstanceOf( \AggregateIt\Entity\DelegationRules::class, $plugin->rules() );
		$this->assertInstanceOf( AiProvider::class, $plugin->providers()->get() );
	}
}
