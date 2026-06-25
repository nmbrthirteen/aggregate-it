<?php

namespace AggregateIt\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Pipeline states (the `state` column on ai_items). */
	public const STATE_FETCHED      = 'fetched';
	public const STATE_EXTRACTED    = 'extracted';
	public const STATE_EMBEDDED     = 'embedded';
	public const STATE_CLUSTERED    = 'clustered';
	public const STATE_REWRITTEN    = 'rewritten';
	public const STATE_ENTITY_LINKED = 'entity_linked';
	public const STATE_PUBLISHED    = 'published';
	public const STATE_DEAD_LETTER  = 'dead_letter';

	/** Terminal states the worker never re-claims. */
	public const TERMINAL = [ self::STATE_PUBLISHED, self::STATE_DEAD_LETTER ];

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'aggregate_it_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$sources = self::table( 'sources' );
		$items   = self::table( 'items' );
		$clusters = self::table( 'clusters' );
		$vectors = self::table( 'vectors' );
		$log     = self::table( 'log' );

		dbDelta(
			"CREATE TABLE {$sources} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url text NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				settings longtext DEFAULT NULL,
				health longtext DEFAULT NULL,
				last_checked datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				guid varchar(255) NOT NULL DEFAULT '',
				url text NOT NULL,
				raw_content longtext DEFAULT NULL,
				content_hash char(64) NOT NULL DEFAULT '',
				state varchar(20) NOT NULL DEFAULT 'fetched',
				cluster_id bigint(20) unsigned DEFAULT NULL,
				post_id bigint(20) unsigned DEFAULT NULL,
				flags longtext DEFAULT NULL,
				cost_tokens int unsigned NOT NULL DEFAULT 0,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				claim_token varchar(36) DEFAULT NULL,
				next_attempt_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_guid (source_id, guid),
				KEY content_hash (content_hash),
				KEY state (state),
				KEY cluster_id (cluster_id),
				KEY claim_token (claim_token),
				KEY next_attempt_at (next_attempt_at)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$clusters} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				primary_keyword varchar(255) DEFAULT NULL,
				canonical_post_id bigint(20) unsigned DEFAULT NULL,
				primary_entities longtext DEFAULT NULL,
				fact_set longtext DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'live',
				window_until datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY canonical_post_id (canonical_post_id)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$vectors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_type varchar(10) NOT NULL DEFAULT 'item',
				owner_id bigint(20) unsigned NOT NULL,
				vector longblob NOT NULL,
				dims smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY owner (owner_type, owner_id)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_id bigint(20) unsigned DEFAULT NULL,
				source_id bigint(20) unsigned DEFAULT NULL,
				stage varchar(20) DEFAULT NULL,
				level varchar(10) NOT NULL DEFAULT 'info',
				message text DEFAULT NULL,
				tokens int unsigned NOT NULL DEFAULT 0,
				cost_usd decimal(10,5) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY item_id (item_id),
				KEY stage (stage),
				KEY created_at (created_at)
			) {$collate};"
		);

		update_option( 'aggregate_it_db_version', AGGREGATE_IT_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'aggregate_it_db_version' ) !== AGGREGATE_IT_VERSION ) {
			self::install();
		}
	}

	public static function uninstall(): void {
		global $wpdb;
		foreach ( [ 'sources', 'items', 'clusters', 'vectors', 'log' ] as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
		foreach ( [ 'db_version', 'settings', 'events', 'run_token', 'delegation_rules', 'indexnow_key' ] as $option ) {
			delete_option( 'aggregate_it_' . $option );
		}
	}
}
