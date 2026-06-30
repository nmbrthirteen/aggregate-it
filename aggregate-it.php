<?php
/**
 * Plugin Name: Aggregate It
 * Plugin URI:  https://github.com/nmbrthirteen/aggregate-it
 * Description: An SEO content engine that uses RSS as raw material — it imports feeds, faithfully rewrites them with AI, deduplicates stories into living topic pages, and auto-builds entity hubs with an internal-link graph.
 *
 * Features:
 * - Import RSS/Atom and JSON feeds on a per-feed schedule, with categories, tags, include/exclude keywords, and per-feed length and publish overrides.
 * - Bulk-add feeds, OPML import (paste or upload), and a global keyword/domain blacklist.
 * - Faithful AI rewrite that keeps the facts and strips promo — choose Google Gemini, OpenAI, or Claude with your own key.
 * - Model, article-length, and writing-style controls, a daily spend cap, and a one-click connection test.
 * - Semantic deduplication into one canonical post per story, a novelty gate, and dated "In the news" timelines.
 * - Automatic entity hub pages with schema.org types, sameAs links, optional Wikipedia enrichment, an internal-link graph, and related articles.
 * - SEO built in: Article/NewsArticle and entity schema, meta tags, clean slugs, and instant IndexNow submission.
 * - Featured-image import, history retention, and hands-off background processing.
 * - Dashboard with charts and an activity log, bulk article actions, and a Tools page for export/import, logs, system info, and reset.
 * Version:     0.1.46
 * Author:      Nika Siradze
 * Author URI:  https://nikusha.com
 * Text Domain: aggregate-it
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'AGGREGATE_IT_VERSION', '0.1.46' );
define( 'AGGREGATE_IT_FILE', __FILE__ );
define( 'AGGREGATE_IT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AGGREGATE_IT_URL', plugin_dir_url( __FILE__ ) );

$aggregate_it_composer = AGGREGATE_IT_PATH . 'vendor/autoload.php';
if ( is_readable( $aggregate_it_composer ) ) {
	require $aggregate_it_composer;
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'AggregateIt\\';
			$len    = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				return;
			}
			$relative = substr( $class, $len );
			$file     = AGGREGATE_IT_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

$aggregate_it_puc = AGGREGATE_IT_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $aggregate_it_puc ) ) {
	require $aggregate_it_puc;

	$aggregate_it_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/nmbrthirteen/aggregate-it/',
		AGGREGATE_IT_FILE,
		'aggregate-it'
	);
	$aggregate_it_updater->setBranch( 'main' );
	$aggregate_it_updater->getVcsApi()->enableReleaseAssets();
}

register_activation_hook( __FILE__, [ AggregateIt\Database\Schema::class, 'install' ] );
register_deactivation_hook( __FILE__, [ AggregateIt\Plugin::class, 'deactivate' ] );

add_action(
	'init',
	static function () {
		if ( PHP_VERSION_ID < 80000 ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Aggregate It requires PHP 8.0 or newer.', 'aggregate-it' ) .
						'</p></div>';
				}
			);
			return;
		}
		try {
			AggregateIt\Plugin::instance()->boot();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Aggregate It] boot failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}
);
