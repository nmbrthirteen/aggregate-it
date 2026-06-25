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
	<h1><?php esc_html_e( 'Aggregate It — Settings', 'aggregate-it' ); ?></h1>

	<?php if ( $notice === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'aggregate-it' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aggregate_it_save_settings">
		<?php wp_nonce_field( 'aggregate_it_save_settings' ); ?>

		<h2><?php esc_html_e( 'AI provider', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="provider"><?php esc_html_e( 'Provider', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="provider" id="provider">
						<option value="mock" <?php selected( $settings->provider_key(), 'mock' ); ?>><?php esc_html_e( 'Mock (free, deterministic)', 'aggregate-it' ); ?></option>
						<option value="gemini" <?php selected( $settings->provider_key(), 'gemini' ); ?>><?php esc_html_e( 'Google Gemini (cheapest)', 'aggregate-it' ); ?></option>
						<option value="openai" <?php selected( $settings->provider_key(), 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'aggregate-it' ); ?></option>
						<option value="anthropic" <?php selected( $settings->provider_key(), 'anthropic' ); ?>><?php esc_html_e( 'Claude (Anthropic)', 'aggregate-it' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Mock runs free for testing. Gemini Flash-Lite and OpenAI gpt-4o-mini are the cheapest live options. Custom providers can register via the aggregate_it_ai_provider filter.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ai_model"><?php esc_html_e( 'Model', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="ai_model" id="ai_model" type="text" class="regular-text" list="ai-model-suggestions"
						value="<?php echo esc_attr( $settings->ai_model() ); ?>" placeholder="<?php esc_attr_e( 'provider default', 'aggregate-it' ); ?>">
					<datalist id="ai-model-suggestions">
						<option value="gemini-2.0-flash-lite"></option>
						<option value="gemini-2.5-flash-lite"></option>
						<option value="gpt-4o-mini"></option>
						<option value="gpt-4.1-mini"></option>
						<option value="claude-haiku-4-5"></option>
						<option value="claude-sonnet-4-6"></option>
						<option value="claude-opus-4-8"></option>
					</datalist>
					<p class="description"><?php esc_html_e( 'Leave blank to use the selected provider\'s cheapest default. Type any current model ID for that provider.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="api_key"><?php esc_html_e( 'Provider API key', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="api_key" id="api_key" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (stored)', 'aggregate-it' ) : ''; ?>">
					<p class="description"><?php esc_html_e( 'For the selected provider (OpenAI / Gemini / Anthropic). Stored encrypted. Leave blank to keep the current key.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="voyage_api_key"><?php esc_html_e( 'Voyage embeddings key', 'aggregate-it' ); ?></label></th>
				<td>
					<input name="voyage_api_key" id="voyage_api_key" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo $settings->voyage_api_key() !== '' ? esc_attr__( '•••••••• (stored)', 'aggregate-it' ) : esc_attr__( 'optional — Claude only', 'aggregate-it' ); ?>">
					<p class="description"><?php esc_html_e( 'Only needed with Claude (Anthropic has no embeddings endpoint). OpenAI and Gemini embed with their own key. Falls back to a local lexical embedding when empty.', 'aggregate-it' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="max_output_tokens"><?php esc_html_e( 'Max output tokens', 'aggregate-it' ); ?></label></th>
				<td><input name="max_output_tokens" id="max_output_tokens" type="number" min="1024" step="512" class="small-text" value="<?php echo esc_attr( (string) $settings->max_output_tokens() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="daily_spend_cap_usd"><?php esc_html_e( 'Daily spend cap (USD)', 'aggregate-it' ); ?></label></th>
				<td><input name="daily_spend_cap_usd" id="daily_spend_cap_usd" type="number" step="0.5" min="0" class="small-text" value="<?php echo esc_attr( (string) $settings->daily_spend_cap_usd() ); ?>">
					<p class="description"><?php esc_html_e( '0 = no cap. When reached, paid stages pause; free stages continue.', 'aggregate-it' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Publishing', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="brand_name"><?php esc_html_e( 'Brand name', 'aggregate-it' ); ?></label></th>
				<td><input name="brand_name" id="brand_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings->brand_name() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="target_post_type"><?php esc_html_e( 'Target post type', 'aggregate-it' ); ?></label></th>
				<td>
					<select name="target_post_type" id="target_post_type">
						<?php foreach ( $post_types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $settings->target_post_type(), $pt->name ); ?>><?php echo esc_html( $pt->labels->singular_name ); ?></option>
						<?php endforeach; ?>
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
						<option value="import" <?php selected( $settings->image_mode(), 'import' ); ?>><?php esc_html_e( 'Import into media library', 'aggregate-it' ); ?></option>
						<option value="off" <?php selected( $settings->image_mode(), 'off' ); ?>><?php esc_html_e( 'Off', 'aggregate-it' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="disclosure"><?php esc_html_e( 'AI disclosure', 'aggregate-it' ); ?></label></th>
				<td><input name="disclosure" id="disclosure" type="text" class="large-text" value="<?php echo esc_attr( $settings->disclosure() ); ?>" placeholder="<?php esc_attr_e( 'This article was AI-assisted from cited sources.', 'aggregate-it' ); ?>">
					<p class="description"><?php esc_html_e( 'Appended to each generated post. Leave blank to omit.', 'aggregate-it' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'IndexNow', 'aggregate-it' ); ?></th>
				<td><label><input name="indexnow_enabled" type="checkbox" <?php checked( $settings->indexnow_enabled() ); ?>> <?php esc_html_e( 'Ping IndexNow on publish/update', 'aggregate-it' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Clustering & keywords', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="similarity_threshold"><?php esc_html_e( 'Similarity threshold', 'aggregate-it' ); ?></label></th>
				<td><input name="similarity_threshold" id="similarity_threshold" type="number" step="0.01" min="0" max="1" class="small-text" value="<?php echo esc_attr( (string) $settings->similarity_threshold() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="cluster_window_days"><?php esc_html_e( 'Cluster window (days)', 'aggregate-it' ); ?></label></th>
				<td><input name="cluster_window_days" id="cluster_window_days" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->cluster_window_days() ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Strategic mode', 'aggregate-it' ); ?></th>
				<td><label><input name="strategic_mode" type="checkbox" <?php checked( $settings->strategic_mode() ); ?>> <?php esc_html_e( 'Only publish content matching a target keyword below', 'aggregate-it' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="keyword_list"><?php esc_html_e( 'Target keywords', 'aggregate-it' ); ?></label></th>
				<td><textarea name="keyword_list" id="keyword_list" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'One keyword per line', 'aggregate-it' ); ?>"><?php echo esc_textarea( $keyword_list ); ?></textarea></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Feeds', 'aggregate-it' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="import_interval_minutes"><?php esc_html_e( 'Default import interval (min)', 'aggregate-it' ); ?></label></th>
				<td><input name="import_interval_minutes" id="import_interval_minutes" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->import_interval_minutes() ); ?>"></td>
			</tr>
			<tr>
				<th><label for="feed_dead_after"><?php esc_html_e( 'Mark feed dead after N fails', 'aggregate-it' ); ?></label></th>
				<td><input name="feed_dead_after" id="feed_dead_after" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $settings->feed_dead_after() ); ?>"></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
