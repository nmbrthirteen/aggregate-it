<?php

namespace AggregateIt\Admin;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * @var Settings             $settings
 * @var string|false         $flash
 * @var string               $flash_type
 * @var string               $blacklist
 * @var array<int,array>     $events
 * @var array<string,string> $info
 */

$post_action = esc_url( admin_url( 'admin-post.php' ) );

$info_text = '';
foreach ( $info as $label => $value ) {
	$info_text .= $label . ': ' . $value . "\n";
}
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Tools', 'aggregate-it' ); ?></h1>
	</div>

	<?php if ( $flash ) : ?>
		<div class="notice notice-<?php echo $flash_type === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
	<?php endif; ?>

	<div class="ai-grid ai-cols-2">
		<div>
			<div class="ai-panel ai-narrow">
				<h2><?php esc_html_e( 'Add many feeds at once', 'aggregate-it' ); ?></h2>
				<form method="post" action="<?php echo $post_action; ?>">
					<input type="hidden" name="action" value="aggregate_it_bulk_add_sources">
					<?php wp_nonce_field( 'aggregate_it_bulk_add_sources' ); ?>
					<p>
						<label for="ai-bulk-urls" class="ai-muted"><?php esc_html_e( 'One feed address per line.', 'aggregate-it' ); ?></label>
						<textarea id="ai-bulk-urls" name="urls" rows="6" class="large-text code" placeholder="https://example.com/feed&#10;https://news.example.org/rss"></textarea>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add feeds', 'aggregate-it' ); ?></button></p>
				</form>
			</div>

			<div class="ai-panel ai-narrow">
				<h2><?php esc_html_e( 'Blacklist', 'aggregate-it' ); ?></h2>
				<form method="post" action="<?php echo $post_action; ?>">
					<input type="hidden" name="action" value="aggregate_it_save_blacklist">
					<?php wp_nonce_field( 'aggregate_it_save_blacklist' ); ?>
					<p>
						<label for="ai-blacklist" class="ai-muted"><?php esc_html_e( 'One word or domain per line. New articles mentioning any of these are skipped on import.', 'aggregate-it' ); ?></label>
						<textarea id="ai-blacklist" name="blacklist" rows="6" class="large-text code"><?php echo esc_textarea( $blacklist ); ?></textarea>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save blacklist', 'aggregate-it' ); ?></button></p>
				</form>
			</div>

			<div class="ai-panel ai-narrow">
				<h2><?php esc_html_e( 'Backup &amp; restore', 'aggregate-it' ); ?></h2>
				<form method="post" action="<?php echo $post_action; ?>">
					<input type="hidden" name="action" value="aggregate_it_export_config">
					<?php wp_nonce_field( 'aggregate_it_export_config' ); ?>
					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Export configuration', 'aggregate-it' ); ?></button>
						<span class="ai-muted"><?php esc_html_e( 'Downloads your feeds, settings and blacklist as a JSON file (API keys are never included).', 'aggregate-it' ); ?></span>
					</p>
				</form>
				<form method="post" action="<?php echo $post_action; ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="aggregate_it_import_config">
					<?php wp_nonce_field( 'aggregate_it_import_config' ); ?>
					<p>
						<label for="ai-config" class="ai-muted"><?php esc_html_e( 'Restore an Aggregate It backup, or import settings and feeds from another RSS aggregator export (JSON).', 'aggregate-it' ); ?></label>
						<input type="file" id="ai-config" name="config" accept=".json,application/json" required>
					</p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Import file', 'aggregate-it' ); ?></button></p>
				</form>
			</div>
		</div>

		<div>
			<div class="ai-panel">
				<h2><?php esc_html_e( 'System info', 'aggregate-it' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $info as $label => $value ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td><?php echo esc_html( $value ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<label for="ai-sysinfo" class="ai-muted"><?php esc_html_e( 'Copy for support:', 'aggregate-it' ); ?></label>
					<textarea id="ai-sysinfo" rows="4" class="large-text code" readonly onclick="this.select();"><?php echo esc_textarea( $info_text ); ?></textarea>
				</p>
			</div>

			<div class="ai-panel">
				<h2><?php esc_html_e( 'Activity log', 'aggregate-it' ); ?></h2>
				<?php if ( ! $events ) : ?>
					<p class="ai-empty"><?php esc_html_e( 'Nothing has happened yet.', 'aggregate-it' ); ?></p>
				<?php else : ?>
					<ul class="ai-events">
						<?php foreach ( $events as $event ) : ?>
							<li class="ai-event ai-event--<?php echo esc_attr( $event['level'] ?? 'info' ); ?>">
								<time><?php echo esc_html( $event['time'] ?? '' ); ?></time>
								<?php echo esc_html( $event['message'] ?? '' ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo $post_action; ?>">
						<input type="hidden" name="action" value="aggregate_it_clear_logs">
						<?php wp_nonce_field( 'aggregate_it_clear_logs' ); ?>
						<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Clear the activity log?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Clear', 'aggregate-it' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="ai-panel ai-danger">
				<h2><?php esc_html_e( 'Reset', 'aggregate-it' ); ?></h2>
				<form method="post" action="<?php echo $post_action; ?>" class="ai-inline">
					<input type="hidden" name="action" value="aggregate_it_reset">
					<?php wp_nonce_field( 'aggregate_it_reset' ); ?>
					<label for="ai-reset" class="screen-reader-text"><?php esc_html_e( 'What to reset', 'aggregate-it' ); ?></label>
					<select name="reset" id="ai-reset">
						<option value="settings"><?php esc_html_e( 'Reset settings to defaults', 'aggregate-it' ); ?></option>
						<option value="queue"><?php esc_html_e( 'Clear the work queue', 'aggregate-it' ); ?></option>
					</select>
					<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'This cannot be undone. Continue?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Reset', 'aggregate-it' ); ?></button>
				</form>
				<p class="ai-muted"><?php esc_html_e( 'Your published posts are never deleted. “Clear the work queue” only removes import history.', 'aggregate-it' ); ?></p>
			</div>
		</div>
	</div>
</div>
