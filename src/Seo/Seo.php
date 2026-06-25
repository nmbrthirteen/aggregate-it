<?php

namespace AggregateIt\Seo;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO seam. At publish time it writes the AI-generated title/description/keyword into
 * whichever SEO plugin is active (Yoast / Rank Math / SEOPress), or stores native meta as
 * a fallback. On the front end it emits our rich JSON-LD and suppresses the active
 * plugin's competing Article schema on our posts, so there's exactly one graph.
 */
final class Seo {

	private ?string $detected = null;

	public function __construct(
		private Settings $settings,
		private SchemaGraph $graph
	) {}

	public function register(): void {
		add_action( 'wp_head', [ $this, 'output_schema' ], 99 );
		add_filter( 'the_content', [ $this, 'append_disclosure' ] );

		switch ( $this->detect() ) {
			case 'yoast':
				add_filter( 'wpseo_json_ld_output', fn ( $data ) => $this->is_current_ours() ? [] : $data, 10 );
				break;
			case 'rankmath':
				add_filter( 'rank_math/json_ld', fn ( $data ) => $this->is_current_ours() ? [] : $data, 99 );
				break;
			case 'seopress':
				add_filter( 'seopress_schemas_auto_disable', fn ( $disable ) => $this->is_current_ours() ? true : $disable, 10 );
				break;
			default:
				add_filter( 'document_title_parts', [ $this, 'native_title' ] );
				add_action( 'wp_head', [ $this, 'native_description' ], 1 );
		}
	}

	/**
	 * @param array{title?:string,description?:string,focus_keyword?:string} $seo
	 */
	public function write_meta( int $post_id, array $seo ): void {
		$title       = $seo['title'] ?? '';
		$description = $seo['description'] ?? '';
		$keyword     = $seo['focus_keyword'] ?? '';

		// Always store native copies (used by the fallback + schema description).
		update_post_meta( $post_id, '_ai_meta_title', $title );
		update_post_meta( $post_id, '_ai_meta_description', $description );
		update_post_meta( $post_id, '_ai_focus_keyword', $keyword );

		$map = [
			'yoast'    => [ '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ],
			'rankmath' => [ 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ],
			'seopress' => [ '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw' ],
		];

		$keys = $map[ $this->detect() ] ?? null;
		if ( $keys ) {
			update_post_meta( $post_id, $keys[0], $title );
			update_post_meta( $post_id, $keys[1], $description );
			update_post_meta( $post_id, $keys[2], $keyword );
		}
	}

	public function append_disclosure( string $content ): string {
		$text = $this->settings->disclosure();
		if ( $text === '' || ! is_singular() || ! in_the_loop() || ! is_main_query() || ! $this->is_ours( get_queried_object_id() ) ) {
			return $content;
		}
		return $content . '<p class="aggregate-it-disclosure"><em>' . esc_html( $text ) . '</em></p>';
	}

	public function output_schema(): void {
		if ( ! is_singular() || ! $this->is_ours( get_queried_object_id() ) ) {
			return;
		}
		$graph = $this->graph->build( get_queried_object_id() );
		if ( $graph ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $graph ) . '</script>' . "\n";
		}
	}

	public function native_title( array $parts ): array {
		if ( is_singular() && $this->is_ours( get_queried_object_id() ) ) {
			$title = get_post_meta( get_queried_object_id(), '_ai_meta_title', true );
			if ( $title ) {
				$parts['title'] = $title;
			}
		}
		return $parts;
	}

	public function native_description(): void {
		if ( ! is_singular() || ! $this->is_ours( get_queried_object_id() ) ) {
			return;
		}
		$desc = get_post_meta( get_queried_object_id(), '_ai_meta_description', true );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}

	public function detect(): string {
		if ( $this->detected !== null ) {
			return $this->detected;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$this->detected = 'yoast';
		} elseif ( class_exists( 'RankMath' ) ) {
			$this->detected = 'rankmath';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$this->detected = 'seopress';
		} else {
			$this->detected = 'native';
		}
		return $this->detected;
	}

	private function is_current_ours(): bool {
		return is_singular() && $this->is_ours( get_queried_object_id() );
	}

	private function is_ours( int $post_id ): bool {
		if ( ! $post_id || get_post_type( $post_id ) !== $this->settings->target_post_type() ) {
			return false;
		}
		return (bool) get_post_meta( $post_id, '_ai_cluster_id', true );
	}
}
