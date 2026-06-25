<?php

namespace AggregateIt\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Writes AI-generated SEO inputs into the active SEO plugin (Yoast / Rank Math /
 * SEOPress) and suppresses that plugin's competing schema on our objects. A native
 * fallback handles the no-SEO-plugin case. Resolved by detecting the active plugin.
 */
interface SeoAdapter {

	public function key(): string;

	public function is_active(): bool;

	/**
	 * @param array{title?:string,description?:string,focus_keyword?:string} $seo
	 */
	public function write_meta( int $post_id, array $seo ): void;

	public function suppress_schema( int $post_id ): void;
}
