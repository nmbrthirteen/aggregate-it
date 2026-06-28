<?php

namespace AggregateIt\Admin;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/** @var Settings $settings */

$notice = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$post_types = get_post_types( [ 'public' => true ], 'objects' );
$keyword_list = implode( "\n", $settings->keyword_list() );
$has_key = $settings->api_key() !== '';
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Aggregate It — Settings', 'aggregate-it' ); ?></h1>
	</div>

	<?php if ( $notice === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'aggregate-it' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aggregate_it_save_settings">
		<?php wp_nonce_field( 'aggregate_it_save_settings' ); ?>

		<h2><?php esc_html_e( 'AI service', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="provider"><?php esc_html_e( 'AI service', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="provider" id="provider">
						<option value="mock" <?php selected( $settings->provider_key(), 'mock' ); ?>><?php esc_html_e( 'Mock (free, for testing)', 'aggregate-it' ); ?></option>
						<option value="gemini" <?php selected( $settings->provider_key(), 'gemini' ); ?>><?php esc_html_e( 'Google Gemini (cheapest)', 'aggregate-it' ); ?></option>
						<option value="openai" <?php selected( $settings->provider_key(), 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'aggregate-it' ); ?></option>
						<option value="anthropic" <?php selected( $settings->provider_key(), 'anthropic' ); ?>><?php esc_html_e( 'Claude (Anthropic)', 'aggregate-it' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ai_model"><?php esc_html_e( 'Model', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="ai_model" id="ai_model" type="text" class="regular-text" list="ai-model-suggestions"
						value="<?php echo esc_attr( $settings->ai_model() ); ?>" placeholder="<?php esc_attr_e( 'use the recommended default', 'aggregate-it' ); ?>">
					<datalist id="ai-model-suggestions">
						<option value="gemini-2.0-flash-lite"></option>
						<option value="gemini-2.5-flash-lite"></option>
						<option value="gpt-4o-mini"></option>
						<option value="gpt-4.1-mini"></option>
						<option value="claude-haiku-4-5"></option>
						<option value="claude-sonnet-4-6"></option>
						<option value="claude-opus-4-8"></option>
					</datalist>
				</td>
			</tr>
			<tr>
				<th><label for="api_key"><?php esc_html_e( 'API key', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="api_key" id="api_key" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (saved)', 'aggregate-it' ) : ''; ?>">
						<p>
							<button type="button" class="button" id="ai-test-key"><?php esc_html_e( 'Test connection', 'aggregate-it' ); ?></button>
							<span id="ai-test-result" class="description"></span>
						</p>
						<p class="description"><?php esc_html_e( 'Save your settings first, then test.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="voyage_api_key"><?php esc_html_e( 'Voyage key (for Claude)', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="voyage_api_key" id="voyage_api_key" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo $settings->voyage_api_key() !== '' ? esc_attr__( '•••••••• (saved)', 'aggregate-it' ) : esc_attr__( 'optional — only if you use Claude', 'aggregate-it' ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="max_output_tokens"><?php esc_html_e( 'Max article length', 'aggregate-it' ); ?></label></th>
				<td><input name="max_output_tokens" id="max_output_tokens" type="number" min="1024" step="512" class="small-text" value="<?php echo esc_attr( (string) $settings->max_output_tokens() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="daily_spend_cap_usd"><?php esc_html_e( 'Daily cost limit (USD)', 'aggregate-it' ); ?></label></th>
				<td><input name="daily_spend_cap_usd" id="daily_spend_cap_usd" type="number" step="0.5" min="0" class="small-text" value="<?php echo esc_attr( (string) $settings->daily_spend_cap_usd() ); ?>"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Publishing', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="brand_name"><?php esc_html_e( 'Site / publisher brand', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="brand_name" id="brand_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings->brand_name() ); ?>">
					<p class="description"><?php esc_html_e( 'Used in generated article context and SEO metadata. The plugin menu stays named Aggregate It.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="target_post_type"><?php esc_html_e( 'Where to publish', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="target_post_type" id="target_post_type">
						<?php foreach ( $post_types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $settings->target_post_type(), $pt->name ); ?>><?php echo esc_html( $pt->labels->singular_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="publish_status"><?php esc_html_e( 'Publish as', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="publish_status" id="publish_status">
						<option value="publish" <?php selected( $settings->publish_status(), 'publish' ); ?>><?php esc_html_e( 'Published (go live right away)', 'aggregate-it' ); ?></option>
						<option value="draft" <?php selected( $settings->publish_status(), 'draft' ); ?>><?php esc_html_e( 'Draft (review before publishing)', 'aggregate-it' ); ?></option>
						<option value="pending" <?php selected( $settings->publish_status(), 'pending' ); ?>><?php esc_html_e( 'Pending review', 'aggregate-it' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="writing_instructions"><?php esc_html_e( 'Your writing style', 'aggregate-it' ); ?></label></th>
				<td><textarea name="writing_instructions" id="writing_instructions" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Optional. e.g. Friendly and to the point. Use British spelling. Always include a short intro.', 'aggregate-it' ); ?>"><?php echo esc_textarea( $settings->writing_instructions() ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="article_length"><?php esc_html_e( 'Article length', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="article_length" id="article_length">
						<option value="auto" <?php selected( $settings->article_length(), 'auto' ); ?>><?php esc_html_e( 'As needed (recommended)', 'aggregate-it' ); ?></option>
						<option value="short" <?php selected( $settings->article_length(), 'short' ); ?>><?php esc_html_e( 'Short (~300 words)', 'aggregate-it' ); ?></option>
						<option value="medium" <?php selected( $settings->article_length(), 'medium' ); ?>><?php esc_html_e( 'Medium (~600 words)', 'aggregate-it' ); ?></option>
						<option value="long" <?php selected( $settings->article_length(), 'long' ); ?>><?php esc_html_e( 'Long (~1000 words)', 'aggregate-it' ); ?></option>
						<option value="match" <?php selected( $settings->article_length(), 'match' ); ?>><?php esc_html_e( 'Match the original', 'aggregate-it' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="author_id"><?php esc_html_e( 'Author', 'aggregate-it' ); ?></label></th>
				<td><?php wp_dropdown_users( [ 'name' => 'author_id', 'id' => 'author_id', 'selected' => $settings->author_id() ] ); ?></td>
			</tr>
			<tr>
				<th><label for="image_mode"><?php esc_html_e( 'Featured images', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="image_mode" id="image_mode">
						<option value="import" <?php selected( $settings->image_mode(), 'import' ); ?>><?php esc_html_e( 'Save images to my media library', 'aggregate-it' ); ?></option>
						<option value="off" <?php selected( $settings->image_mode(), 'off' ); ?>><?php esc_html_e( 'Off', 'aggregate-it' ); ?></option>
					</select>
					<select name="image_source" id="image_source" style="margin-top:6px;">
						<option value="share" <?php selected( $settings->image_source(), 'share' ); ?>><?php esc_html_e( 'Use the article\'s share image (recommended)', 'aggregate-it' ); ?></option>
						<option value="feed" <?php selected( $settings->image_source(), 'feed' ); ?>><?php esc_html_e( 'Use the image from the feed', 'aggregate-it' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tell search engines instantly', 'aggregate-it' ); ?></th>
				<td><label><input name="indexnow_enabled" type="checkbox" <?php checked( $settings->indexnow_enabled() ); ?>> <?php esc_html_e( 'Let search engines know right away when an article is published or updated', 'aggregate-it' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Wikipedia lookup', 'aggregate-it' ); ?></th>
				<td><label><input name="wikipedia_research" type="checkbox" <?php checked( $settings->wikipedia_research() ); ?>> <?php esc_html_e( 'Add a short summary and a Wikipedia link to entity pages (companies, people, etc.) when one is found', 'aggregate-it' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Related articles', 'aggregate-it' ); ?></th>
				<td><label><input name="related_articles" type="checkbox" <?php checked( $settings->related_articles() ); ?>> <?php esc_html_e( 'Automatically link each article to your other articles on similar topics', 'aggregate-it' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Auto categories', 'aggregate-it' ); ?></th>
				<td><label><input name="ai_categorize" type="checkbox" <?php checked( $settings->ai_categorize() ); ?>> <?php esc_html_e( 'Let the AI pick each article\'s category by its topic (reuses your existing categories, creates one only when none fits). When off, posts use the feed\'s category.', 'aggregate-it' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Stories & keywords', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="similarity_threshold"><?php esc_html_e( 'Duplicate sensitivity', 'aggregate-it' ); ?></label></th>
				<td><input name="similarity_threshold" id="similarity_threshold" type="number" step="0.01" min="0" max="1" class="small-text" value="<?php echo esc_attr( (string) $settings->similarity_threshold() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="cluster_window_days"><?php esc_html_e( 'Keep updating a story for (days)', 'aggregate-it' ); ?></label></th>
				<td><input name="cluster_window_days" id="cluster_window_days" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->cluster_window_days() ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Keyword focus', 'aggregate-it' ); ?></th>
				<td><label><input name="strategic_mode" type="checkbox" <?php checked( $settings->strategic_mode() ); ?>> <?php esc_html_e( 'Only publish articles that match my keywords', 'aggregate-it' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="keyword_list"><?php esc_html_e( 'My keywords', 'aggregate-it' ); ?></label></th>
				<td><textarea name="keyword_list" id="keyword_list" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'One keyword per line', 'aggregate-it' ); ?>"><?php echo esc_textarea( $keyword_list ); ?></textarea></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Processing', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Run by itself', 'aggregate-it' ); ?></th>
				<td>
					<label><input name="processing_enabled" type="checkbox" <?php checked( $settings->processing_enabled() ); ?>> <?php esc_html_e( 'Turn new articles into posts automatically in the background', 'aggregate-it' ); ?></label>				</td>
			</tr>
			<tr>
				<th><label for="processing_interval_minutes"><?php esc_html_e( 'Run every (minutes)', 'aggregate-it' ); ?></label></th>
				<td><input name="processing_interval_minutes" id="processing_interval_minutes" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->processing_interval_minutes() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="retention_days"><?php esc_html_e( 'Keep the article history for (days)', 'aggregate-it' ); ?></label></th>
				<td><input name="retention_days" id="retention_days" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) $settings->retention_days() ); ?>"> <span class="description"><?php esc_html_e( '0 = keep forever. Your published posts are never deleted.', 'aggregate-it' ); ?></span></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Feeds', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="import_interval_minutes"><?php esc_html_e( 'Check for new posts every (minutes)', 'aggregate-it' ); ?></label></th>
				<td><input name="import_interval_minutes" id="import_interval_minutes" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->import_interval_minutes() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="feed_dead_after"><?php esc_html_e( 'Give up on a feed after this many failed tries', 'aggregate-it' ); ?></label></th>
				<td><input name="feed_dead_after" id="feed_dead_after" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->feed_dead_after() ); ?>"></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
	<script>
	( function () {
		var cfg = window.AggregateItAdmin || {};
		var provider = document.getElementById( 'provider' );
		var voyage = document.getElementById( 'voyage_api_key' );
		var voyageRow = voyage ? voyage.closest( 'tr' ) : null;
		var imageMode = document.getElementById( 'image_mode' );
		var imageSource = document.getElementById( 'image_source' );

		function sync() {
			if ( voyageRow && provider ) {
				voyageRow.style.display = provider.value === 'anthropic' ? '' : 'none';
			}
			if ( imageSource && imageMode ) {
				imageSource.style.display = imageMode.value === 'off' ? 'none' : '';
			}
		}
		if ( provider ) { provider.addEventListener( 'change', sync ); }
		if ( imageMode ) { imageMode.addEventListener( 'change', sync ); }
		sync();

		var test = document.getElementById( 'ai-test-key' );
		var result = document.getElementById( 'ai-test-result' );
		if ( test && result ) {
			test.addEventListener( 'click', function () {
				cfg = window.AggregateItAdmin || cfg;
				if ( ! cfg.root ) { return; }
				test.disabled = true;
				test.setAttribute( 'aria-busy', 'true' );
				result.textContent = cfg.i18n.testing;
				fetch( cfg.root + 'test', {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					result.textContent = ( d && d.ok ) ? cfg.i18n.testOk : ( cfg.i18n.testFail + ' ' + ( ( d && d.error ) ? d.error : '' ) );
				} ).catch( function () {
					result.textContent = cfg.i18n.testFail;
				} ).then( function () {
					test.disabled = false;
					test.removeAttribute( 'aria-busy' );
				} );
			} );
		}
	} )();
	</script>
</div>
