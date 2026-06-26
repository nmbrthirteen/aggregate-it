<?php

namespace AggregateIt;

use AggregateIt\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION = 'aggregate_it_settings';

	private array $data;

	public function __construct() {
		$stored     = get_option( self::OPTION, [] );
		$this->data = is_array( $stored ) ? $stored : [];
	}

	public function all(): array {
		return $this->data;
	}

	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
		update_option( self::OPTION, $this->data );
	}

	public function update( array $values ): void {
		$this->data = array_merge( $this->data, $values );
		update_option( self::OPTION, $this->data );
	}

	public function provider_key(): string {
		return (string) $this->get( 'provider', 'mock' );
	}

	public function ai_model(): string {
		return trim( (string) $this->get( 'ai_model', '' ) );
	}

	public function max_output_tokens(): int {
		return max( 1024, (int) $this->get( 'max_output_tokens', 8000 ) );
	}

	public function voyage_api_key(): string {
		$stored = (string) $this->get( 'voyage_api_key', '' );
		return $stored === '' ? '' : Crypto::decrypt( $stored );
	}

	public function set_voyage_api_key( string $key ): void {
		$this->set( 'voyage_api_key', $key === '' ? '' : Crypto::encrypt( $key ) );
	}

	public function api_key(): string {
		$stored = (string) $this->get( 'api_key', '' );
		return $stored === '' ? '' : Crypto::decrypt( $stored );
	}

	public function set_api_key( string $key ): void {
		$this->set( 'api_key', $key === '' ? '' : Crypto::encrypt( $key ) );
	}

	public function brand_name(): string {
		$name = trim( (string) $this->get( 'brand_name', '' ) );
		return $name !== '' ? $name : __( 'Aggregate It', 'aggregate-it' );
	}

	public function target_post_type(): string {
		return (string) $this->get( 'target_post_type', 'post' );
	}

	public function publish_status(): string {
		$status = (string) $this->get( 'publish_status', 'publish' );
		return in_array( $status, [ 'publish', 'draft', 'pending' ], true ) ? $status : 'publish';
	}

	public function writing_instructions(): string {
		return trim( (string) $this->get( 'writing_instructions', '' ) );
	}

	public function retention_days(): int {
		return max( 0, (int) $this->get( 'retention_days', 90 ) );
	}

	public function daily_spend_cap_usd(): float {
		return (float) $this->get( 'daily_spend_cap_usd', 5.0 );
	}

	public function autopause_threshold(): int {
		return (int) $this->get( 'autopause_threshold', 10 );
	}

	public function import_interval_minutes(): int {
		return max( 1, (int) $this->get( 'import_interval_minutes', 30 ) );
	}

	public function processing_enabled(): bool {
		return (bool) $this->get( 'processing_enabled', true );
	}

	public function processing_interval_minutes(): int {
		return max( 1, (int) $this->get( 'processing_interval_minutes', 1 ) );
	}

	public function strategic_mode(): bool {
		return (bool) $this->get( 'strategic_mode', false );
	}

	public function similarity_threshold(): float {
		return (float) $this->get( 'similarity_threshold', 0.82 );
	}

	public function cluster_window_days(): int {
		return max( 1, (int) $this->get( 'cluster_window_days', 7 ) );
	}

	/** @return string[] */
	public function keyword_list(): array {
		$list = $this->get( 'keyword_list', [] );
		if ( is_string( $list ) ) {
			$list = preg_split( '/\r\n|\r|\n/', $list ) ?: [];
		}
		return array_values( array_filter( array_map( 'trim', (array) $list ) ) );
	}

	public function author_id(): int {
		$id = (int) $this->get( 'author_id', 0 );
		return $id > 0 ? $id : 1;
	}

	public function image_mode(): string {
		$mode = (string) $this->get( 'image_mode', 'import' );
		return in_array( $mode, [ 'off', 'import' ], true ) ? $mode : 'import';
	}

	public function image_source(): string {
		$src = (string) $this->get( 'image_source', 'share' );
		return in_array( $src, [ 'share', 'feed' ], true ) ? $src : 'share';
	}

	public function indexnow_enabled(): bool {
		return (bool) $this->get( 'indexnow_enabled', true );
	}

	public function wikipedia_research(): bool {
		return (bool) $this->get( 'wikipedia_research', false );
	}

	public function disclosure(): string {
		return trim( (string) $this->get( 'disclosure', '' ) );
	}

	public function feed_dead_after(): int {
		return max( 1, (int) $this->get( 'feed_dead_after', 5 ) );
	}
}
