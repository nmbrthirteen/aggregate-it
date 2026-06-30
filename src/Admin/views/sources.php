<?php

namespace AggregateIt\Admin;

use AggregateIt\Source\Source;

defined( 'ABSPATH' ) || exit;

/**
 * @var Source[]    $sources
 * @var Source|null $editing
 * @var int         $default_interval
 * @var object[]    $public_types
 */

$notice = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$messages = [
	'saved'    => __( 'Feed saved.', 'aggregate-it' ),
	'deleted'  => __( 'Feed deleted.', 'aggregate-it' ),
	'imported' => __( 'Checking that feed now.', 'aggregate-it' ),
	'invalid'  => __( 'Please enter a valid feed address.', 'aggregate-it' ),
	'opml_added' => __( 'Feeds imported.', 'aggregate-it' ),
	'opml_none'  => __( 'No new feeds found in that OPML.', 'aggregate-it' ),
	'bulk_done' => __( 'Selected feeds updated.', 'aggregate-it' ),
	'bulk_none' => __( 'Choose feeds and a bulk action first.', 'aggregate-it' ),
];

$src_type  = $editing ? $editing->source_type() : 'rss';
$scrape    = $editing ? $editing->scrape_config() : [];
$sc_mode   = (string) ( $scrape['discovery']['mode'] ?? 'list' );
$sc_item   = (string) ( $scrape['discovery']['item_selector'] ?? '' );
$sc_filter = (string) ( $scrape['discovery']['url_filter'] ?? '' );
$sc_ptype  = $editing ? $editing->post_type_connection() : '';
$sc_proc   = $editing ? $editing->processing_mode() : 'passthrough';
$sc_robots = $editing ? $editing->respects_robots() : true;
$sc_next   = $editing ? $editing->pagination_next_selector() : '';
$sc_pages  = $editing ? $editing->pagination_max_pages() : 1;
$sc_rules = $editing ? $editing->rules() : [];
if ( ! $sc_rules ) {
	$sc_rules = [ [ 'field' => '', 'op' => 'always', 'value' => '', 'set_key' => '', 'set_value' => '' ] ];
}
$rule_ops = [
	'always'       => __( 'always', 'aggregate-it' ),
	'equals'       => __( 'equals', 'aggregate-it' ),
	'not_equals'   => __( 'does not equal', 'aggregate-it' ),
	'contains'     => __( 'contains', 'aggregate-it' ),
	'not_contains' => __( 'does not contain', 'aggregate-it' ),
	'empty'        => __( 'is empty', 'aggregate-it' ),
	'not_empty'    => __( 'is not empty', 'aggregate-it' ),
	'date_past'    => __( 'date is in the past', 'aggregate-it' ),
	'date_future'  => __( 'date is in the future', 'aggregate-it' ),
	'gt'           => __( 'greater than', 'aggregate-it' ),
	'lt'           => __( 'less than', 'aggregate-it' ),
];

$render_rule_row = static function ( array $rule, array $rule_ops ): void {
	?>
	<tr class="ai-rule">
		<td class="ai-rule-handle" title="<?php esc_attr_e( 'Drag to reorder', 'aggregate-it' ); ?>">⠿</td>
		<td><input type="text" name="rule_field[]" list="ai-source-fields" value="<?php echo esc_attr( (string) ( $rule['field'] ?? '' ) ); ?>" placeholder="date"></td>
		<td>
			<select name="rule_op[]">
				<?php foreach ( $rule_ops as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) ( $rule['op'] ?? 'always' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="text" name="rule_value[]" value="<?php echo esc_attr( (string) ( $rule['value'] ?? '' ) ); ?>"></td>
		<td><input type="text" name="rule_set_key[]" list="ai-meta-keys" value="<?php echo esc_attr( (string) ( $rule['set_key'] ?? '' ) ); ?>" placeholder="event_status"></td>
		<td><input type="text" name="rule_set_value[]" value="<?php echo esc_attr( (string) ( $rule['set_value'] ?? '' ) ); ?>" placeholder="Upcoming or {date|Y-m-d}"></td>
		<td><button type="button" class="button-link ai-rule-del" title="<?php esc_attr_e( 'Remove', 'aggregate-it' ); ?>">✕</button></td>
	</tr>
	<?php
};

$sc_fields = (array) ( $scrape['extraction']['fields'] ?? [] );
$sc_map    = (array) ( $scrape['mapping']['fields'] ?? [] );
$field_rows = [];
foreach ( $sc_fields as $f_name => $f_rule ) {
	$dest     = (string) ( $sc_map[ $f_name ]['dest'] ?? '' );
	$dest_sel = 'default';
	if ( str_starts_with( $dest, 'meta:' ) ) {
		$dest_sel = 'meta';
	} elseif ( str_starts_with( $dest, 'taxonomy:' ) ) {
		$dest_sel = 'taxonomy';
	} elseif ( $dest !== '' ) {
		$dest_sel = $dest;
	}
	$field_rows[] = [
		'name'     => (string) $f_name,
		'selector' => (string) ( $f_rule['selector'] ?? '' ),
		'attr'     => (string) ( $f_rule['attr'] ?? 'text' ),
		'regex'    => (string) ( $f_rule['regex'] ?? '' ),
		'dest'     => $dest_sel,
	];
}
while ( count( $field_rows ) < 6 ) {
	$field_rows[] = [ 'name' => '', 'selector' => '', 'attr' => 'text', 'regex' => '', 'dest' => 'default' ];
}

$dest_options = [
	'default'        => __( 'Default', 'aggregate-it' ),
	'post_title'     => __( 'Title', 'aggregate-it' ),
	'post_content'   => __( 'Content', 'aggregate-it' ),
	'post_excerpt'   => __( 'Excerpt', 'aggregate-it' ),
	'post_date'      => __( 'Date', 'aggregate-it' ),
	'featured_image' => __( 'Featured image', 'aggregate-it' ),
	'meta'           => __( 'Custom field', 'aggregate-it' ),
	'taxonomy'       => __( 'Taxonomy term', 'aggregate-it' ),
];
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Feeds', 'aggregate-it' ); ?></h1>
	</div>

	<?php if ( $notice && isset( $messages[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo $notice === 'invalid' ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $messages[ $notice ] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="postbox">
		<h2 class="hndle"><span><?php echo $editing ? esc_html__( 'Edit feed', 'aggregate-it' ) : esc_html__( 'Add a feed', 'aggregate-it' ); ?></span></h2>
		<div class="inside">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aggregate_it_save_source">
			<input type="hidden" name="id" value="<?php echo (int) ( $editing->id ?? 0 ); ?>">
			<?php wp_nonce_field( 'aggregate_it_save_source' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ai-source-type"><?php esc_html_e( 'Source type', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="source_type" id="ai-source-type">
							<option value="rss" <?php selected( $src_type, 'rss' ); ?>><?php esc_html_e( 'Feed (RSS / Atom / JSON)', 'aggregate-it' ); ?></option>
							<option value="scrape" <?php selected( $src_type, 'scrape' ); ?>><?php esc_html_e( 'Website (scrape HTML)', 'aggregate-it' ); ?></option>
						</select>
						<p class="description ai-scrape-row"><?php esc_html_e( 'Pull repeating items (events, listings) from a page that has no feed.', 'aggregate-it' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ai-url"><span class="ai-label-rss"><?php esc_html_e( 'Feed address', 'aggregate-it' ); ?></span><span class="ai-label-scrape ai-scrape-row"><?php esc_html_e( 'Page URL', 'aggregate-it' ); ?></span></label></th>
					<td><input name="url" id="ai-url" type="url" class="regular-text" required
						value="<?php echo esc_attr( $editing->url ?? '' ); ?>" placeholder="https://example.com/feed"></td>
				</tr>

				<tr>
					<th><label for="ai-post-type"><?php esc_html_e( 'Save items as (post type)', 'aggregate-it' ); ?></label></th>
					<td>
						<input name="post_type" id="ai-post-type" type="text" class="regular-text" list="ai-post-types"
							value="<?php echo esc_attr( $sc_ptype ); ?>" placeholder="post">
						<datalist id="ai-post-types">
							<?php foreach ( $public_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt->name ); ?>"><?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?></option>
							<?php endforeach; ?>
						</datalist>
					</td>
				</tr>
				<tr class="ai-scrape-row">
					<th><label for="ai-processing"><?php esc_html_e( 'Processing', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="processing" id="ai-processing">
							<option value="passthrough" <?php selected( $sc_proc, 'passthrough' ); ?>><?php esc_html_e( 'Import as-is (no AI changes)', 'aggregate-it' ); ?></option>
							<option value="rewrite" <?php selected( $sc_proc, 'rewrite' ); ?>><?php esc_html_e( 'AI rewrite (like feeds)', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="ai-scrape-row">
					<th><label for="ai-scrape-mode"><?php esc_html_e( 'Find items by', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="scrape_mode" id="ai-scrape-mode">
							<option value="list" <?php selected( $sc_mode, 'list' ); ?>><?php esc_html_e( 'Rows on a listing page', 'aggregate-it' ); ?></option>
							<option value="sitemap" <?php selected( $sc_mode, 'sitemap' ); ?>><?php esc_html_e( 'URLs in a sitemap', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="ai-scrape-row ai-scrape-list">
					<th><label for="ai-item-selector"><?php esc_html_e( 'Each item is (CSS selector)', 'aggregate-it' ); ?></label></th>
					<td>
						<input name="item_selector" id="ai-item-selector" type="text" class="regular-text code"
							value="<?php echo esc_attr( $sc_item ); ?>" placeholder="tr.event">
						<button type="button" class="button" id="ai-suggest"><?php esc_html_e( 'Suggest fields with AI', 'aggregate-it' ); ?></button>
						<span id="ai-suggest-status" class="description"></span>
					</td>
				</tr>
				<tr class="ai-scrape-row ai-scrape-sitemap">
					<th><label for="ai-url-filter"><?php esc_html_e( 'Only URLs matching (regex)', 'aggregate-it' ); ?></label></th>
					<td><input name="url_filter" id="ai-url-filter" type="text" class="regular-text code"
						value="<?php echo esc_attr( $sc_filter ); ?>" placeholder="_\d+\.aspx$"></td>
				</tr>
				<tr class="ai-scrape-row">
					<th><?php esc_html_e( 'Politeness', 'aggregate-it' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="respect_robots" value="1" <?php checked( $sc_robots ); ?>>
							<?php esc_html_e( 'Respect robots.txt', 'aggregate-it' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only turn this off for sites you own or have permission to scrape — you are responsible for their terms.', 'aggregate-it' ); ?></p>
					</td>
				</tr>
				<tr class="ai-scrape-row">
					<th><?php esc_html_e( 'Fields', 'aggregate-it' ); ?></th>
					<td>
						<table class="ai-fields-table widefat">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'aggregate-it' ); ?></th>
									<th><?php esc_html_e( 'Selector (CSS, in the item)', 'aggregate-it' ); ?></th>
									<th><?php esc_html_e( 'Attribute', 'aggregate-it' ); ?></th>
									<th><?php esc_html_e( 'Regex', 'aggregate-it' ); ?></th>
									<th><?php esc_html_e( 'Maps to', 'aggregate-it' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $field_rows as $row ) : ?>
									<tr>
										<td><input type="text" name="field_name[]" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="title"></td>
										<td><input type="text" name="field_selector[]" class="code" value="<?php echo esc_attr( $row['selector'] ); ?>" placeholder="a.name"></td>
										<td><input type="text" name="field_attr[]" value="<?php echo esc_attr( $row['attr'] ); ?>" placeholder="text"></td>
										<td><input type="text" name="field_regex[]" class="code" value="<?php echo esc_attr( $row['regex'] ); ?>"></td>
										<td>
											<select name="field_dest[]">
												<?php foreach ( $dest_options as $val => $label ) : ?>
													<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $row['dest'], $val ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
				<tr class="ai-scrape-row ai-scrape-list">
					<th><label for="ai-next-selector"><?php esc_html_e( 'Next-page link', 'aggregate-it' ); ?></label></th>
					<td>
						<input name="next_selector" id="ai-next-selector" type="text" class="regular-text code"
							value="<?php echo esc_attr( $sc_next ); ?>" placeholder="a.next">
						<label class="ai-inline"><?php esc_html_e( 'up to', 'aggregate-it' ); ?>
							<input name="max_pages" type="number" min="1" max="50" class="small-text" value="<?php echo esc_attr( (string) $sc_pages ); ?>">
							<?php esc_html_e( 'pages', 'aggregate-it' ); ?>
						</label>
					</td>
				</tr>
				<tr class="ai-scrape-row">
					<th><?php esc_html_e( 'Rules', 'aggregate-it' ); ?></th>
					<td>
						<table class="ai-rules-table widefat" id="ai-rules">
							<thead><tr>
								<th></th>
								<th><?php esc_html_e( 'If field', 'aggregate-it' ); ?></th>
								<th><?php esc_html_e( 'Condition', 'aggregate-it' ); ?></th>
								<th><?php esc_html_e( 'Value', 'aggregate-it' ); ?></th>
								<th><?php esc_html_e( 'Set meta key', 'aggregate-it' ); ?></th>
								<th><?php esc_html_e( 'To value', 'aggregate-it' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody id="ai-rules-body">
								<?php foreach ( $sc_rules as $rule ) : ?>
									<?php $render_rule_row( (array) $rule, $rule_ops ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button" id="ai-rule-add"><?php esc_html_e( 'Add rule', 'aggregate-it' ); ?></button></p>
						<datalist id="ai-source-fields"></datalist>
						<datalist id="ai-meta-keys"></datalist>
						<template id="ai-rule-template"><?php $render_rule_row( [ 'op' => 'always' ], $rule_ops ); ?></template>
					</td>
				</tr>
				<tr class="ai-scrape-row ai-scrape-list">
					<th><?php esc_html_e( 'Preview', 'aggregate-it' ); ?></th>
					<td>
						<button type="button" class="button" id="ai-preview-btn"><?php esc_html_e( 'Preview extraction', 'aggregate-it' ); ?></button>
						<span id="ai-preview-status" class="description"></span>
						<div id="ai-preview" class="ai-preview"></div>
					</td>
				</tr>
				<tr>
					<th><label for="ai-title"><?php esc_html_e( 'Title', 'aggregate-it' ); ?></label></th>
					<td><input name="title" id="ai-title" type="text" class="regular-text"
						value="<?php echo esc_attr( $editing->title ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-interval"><?php esc_html_e( 'Check for new posts every (minutes)', 'aggregate-it' ); ?></label></th>
					<td><input name="interval_minutes" id="ai-interval" type="number" min="1" class="small-text"
						value="<?php echo esc_attr( (string) ( $editing ? $editing->interval_minutes( $default_interval ) : $default_interval ) ); ?>"></td>
				</tr>
				<?php
				$selected_cats = $editing ? $editing->categories() : [];
				$all_cats      = get_categories( [ 'hide_empty' => false, 'number' => 200 ] );
				?>
				<?php if ( $all_cats ) : ?>
				<tr>
					<th><?php esc_html_e( 'Put posts in', 'aggregate-it' ); ?></th>
					<td>
						<div class="ai-checklist">
							<?php foreach ( $all_cats as $cat ) : ?>
								<label>
									<input type="checkbox" name="categories[]" value="<?php echo (int) $cat->term_id; ?>"
										<?php checked( in_array( (int) $cat->term_id, $selected_cats, true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><label for="ai-tags"><?php esc_html_e( 'Tags', 'aggregate-it' ); ?></label></th>
					<td><input name="tags" id="ai-tags" type="text" class="regular-text"
						value="<?php echo esc_attr( $editing ? implode( ', ', $editing->tags() ) : '' ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated', 'aggregate-it' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-include"><?php esc_html_e( 'Only import if it mentions', 'aggregate-it' ); ?></label></th>
					<td><input name="include_keywords" id="ai-include" type="text" class="regular-text"
						value="<?php echo esc_attr( (string) ( $editing->settings['include_keywords'] ?? '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated — leave blank for all', 'aggregate-it' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-exclude"><?php esc_html_e( 'Skip if it mentions', 'aggregate-it' ); ?></label></th>
					<td><input name="exclude_keywords" id="ai-exclude" type="text" class="regular-text"
						value="<?php echo esc_attr( (string) ( $editing->settings['exclude_keywords'] ?? '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated', 'aggregate-it' ); ?>"></td>
				</tr>
				<?php $feed_status = $editing ? (string) ( $editing->settings['publish_status'] ?? 'default' ) : 'default'; ?>
				<tr>
					<th><label for="ai-publish"><?php esc_html_e( 'Publish as', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="publish_status" id="ai-publish">
							<option value="default" <?php selected( $feed_status, 'default' ); ?>><?php esc_html_e( 'Use the global setting', 'aggregate-it' ); ?></option>
							<option value="publish" <?php selected( $feed_status, 'publish' ); ?>><?php esc_html_e( 'Published', 'aggregate-it' ); ?></option>
							<option value="draft" <?php selected( $feed_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'aggregate-it' ); ?></option>
							<option value="pending" <?php selected( $feed_status, 'pending' ); ?>><?php esc_html_e( 'Pending review', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php $feed_length = $editing ? (string) ( $editing->settings['article_length'] ?? 'default' ) : 'default'; ?>
				<tr>
					<th><label for="ai-length"><?php esc_html_e( 'Article length', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="article_length" id="ai-length">
							<option value="default" <?php selected( $feed_length, 'default' ); ?>><?php esc_html_e( 'Use the global setting', 'aggregate-it' ); ?></option>
							<option value="auto" <?php selected( $feed_length, 'auto' ); ?>><?php esc_html_e( 'As needed', 'aggregate-it' ); ?></option>
							<option value="short" <?php selected( $feed_length, 'short' ); ?>><?php esc_html_e( 'Short (~300 words)', 'aggregate-it' ); ?></option>
							<option value="medium" <?php selected( $feed_length, 'medium' ); ?>><?php esc_html_e( 'Medium (~600 words)', 'aggregate-it' ); ?></option>
							<option value="long" <?php selected( $feed_length, 'long' ); ?>><?php esc_html_e( 'Long (~1000 words)', 'aggregate-it' ); ?></option>
							<option value="match" <?php selected( $feed_length, 'match' ); ?>><?php esc_html_e( 'Match the original', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php if ( $editing ) : ?>
				<tr>
					<th><label for="ai-status"><?php esc_html_e( 'Status', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="status" id="ai-status">
							<option value="active" <?php selected( $editing->status, 'active' ); ?>><?php esc_html_e( 'Active', 'aggregate-it' ); ?></option>
							<option value="paused" <?php selected( $editing->status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<p>
				<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save feed', 'aggregate-it' ) : esc_html__( 'Add a feed', 'aggregate-it' ); ?></button>
				<?php if ( $editing ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-sources' ) ); ?>"><?php esc_html_e( 'Cancel', 'aggregate-it' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		</div>
	</div>

	<script>
	( function () {
		var type = document.getElementById( 'ai-source-type' );
		var mode = document.getElementById( 'ai-scrape-mode' );
		if ( ! type ) { return; }
		function show( selector, on ) {
			document.querySelectorAll( selector ).forEach( function ( el ) { el.style.display = on ? '' : 'none'; } );
		}
		function apply() {
			var scrape = type.value === 'scrape';
			show( '.ai-scrape-row', scrape );
			show( '.ai-label-rss', ! scrape );
			var sitemap = mode && mode.value === 'sitemap';
			show( '.ai-scrape-list', scrape && ! sitemap );
			show( '.ai-scrape-sitemap', scrape && sitemap );
		}
		type.addEventListener( 'change', apply );
		if ( mode ) { mode.addEventListener( 'change', apply ); }
		apply();

		var suggest = document.getElementById( 'ai-suggest' );
		var cfg = window.AggregateItAdmin || {};
		if ( suggest && cfg.root ) {
			suggest.addEventListener( 'click', function () {
				var url = ( document.getElementById( 'ai-url' ) || {} ).value || '';
				var status = document.getElementById( 'ai-suggest-status' );
				if ( ! url ) { if ( status ) { status.textContent = 'Enter the page URL first.'; } return; }
				suggest.disabled = true;
				if ( status ) { status.textContent = 'Looking at the page…'; }

				fetch( cfg.root + 'suggest-selectors', {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { url: url } )
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					suggest.disabled = false;
					if ( ! data || ! data.ok ) {
						if ( status ) { status.textContent = ( data && data.error ) || 'Could not suggest fields.'; }
						return;
					}
					fill( data.suggestion || {} );
					if ( status ) { status.textContent = 'Filled — review and save.'; }
				} ).catch( function () {
					suggest.disabled = false;
					if ( status ) { status.textContent = 'Could not suggest fields.'; }
				} );
			} );
		}

		function readFields() {
			var out = [];
			document.querySelectorAll( '.ai-fields-table tbody tr' ).forEach( function ( row ) {
				var name = ( row.querySelector( 'input[name="field_name[]"]' ) || {} ).value || '';
				if ( ! name ) { return; }
				out.push( {
					name: name,
					selector: ( row.querySelector( 'input[name="field_selector[]"]' ) || {} ).value || '',
					attr: ( row.querySelector( 'input[name="field_attr[]"]' ) || {} ).value || 'text',
					regex: ( row.querySelector( 'input[name="field_regex[]"]' ) || {} ).value || ''
				} );
			} );
			return out;
		}

		function robots() {
			var box = document.querySelector( 'input[name="respect_robots"]' );
			return box ? box.checked : true;
		}

		var previewBtn = document.getElementById( 'ai-preview-btn' );
		if ( previewBtn && cfg.root ) {
			previewBtn.addEventListener( 'click', function () {
				var url = ( document.getElementById( 'ai-url' ) || {} ).value || '';
				var item = ( document.getElementById( 'ai-item-selector' ) || {} ).value || '';
				var status = document.getElementById( 'ai-preview-status' );
				var box = document.getElementById( 'ai-preview' );
				if ( ! url || ! item ) { if ( status ) { status.textContent = 'Enter the URL and item selector first.'; } return; }
				previewBtn.disabled = true;
				if ( status ) { status.textContent = 'Fetching…'; }
				if ( box ) { box.innerHTML = ''; }

				fetch( cfg.root + 'scrape-preview', {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { url: url, item_selector: item, fields: readFields(), respect_robots: robots() } )
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					previewBtn.disabled = false;
					if ( ! data || ! data.ok ) {
						if ( status ) { status.textContent = ( data && data.error ) || 'Preview failed.'; }
						return;
					}
					if ( status ) { status.textContent = data.count + ' item(s) found.'; }
					renderPreview( box, data.sample || [] );
				} ).catch( function () {
					previewBtn.disabled = false;
					if ( status ) { status.textContent = 'Preview failed.'; }
				} );
			} );
		}

		function renderPreview( box, rows ) {
			if ( ! box ) { return; }
			box.innerHTML = '';
			if ( ! rows.length ) { return; }
			var table = document.createElement( 'table' );
			table.className = 'widefat striped';
			rows.forEach( function ( r ) {
				var fields = r.fields || {};
				var parts = [];
				if ( r.title ) { parts.push( r.title ); }
				if ( r.url ) { parts.push( r.url ); }
				if ( r.date ) { parts.push( String( r.date ) ); }
				Object.keys( fields ).forEach( function ( k ) { parts.push( k + ': ' + fields[ k ] ); } );
				var tr = document.createElement( 'tr' );
				var td = document.createElement( 'td' );
				td.textContent = parts.join( '  •  ' ) || '(empty)';
				tr.appendChild( td );
				table.appendChild( tr );
			} );
			box.appendChild( table );
		}

		function fill( s ) {
			var item = document.getElementById( 'ai-item-selector' );
			if ( item && s.item_selector ) { item.value = s.item_selector; }
			var rows = document.querySelectorAll( '.ai-fields-table tbody tr' );
			var fields = s.fields || [];
			rows.forEach( function ( row, i ) {
				var f = fields[ i ];
				var name = row.querySelector( 'input[name="field_name[]"]' );
				var sel = row.querySelector( 'input[name="field_selector[]"]' );
				var attr = row.querySelector( 'input[name="field_attr[]"]' );
				var dest = row.querySelector( 'select[name="field_dest[]"]' );
				if ( ! f ) {
					if ( name ) { name.value = ''; }
					if ( sel ) { sel.value = ''; }
					return;
				}
				if ( name ) { name.value = f.name || ''; }
				if ( sel ) { sel.value = f.selector || ''; }
				if ( attr ) { attr.value = f.attr || 'text'; }
				if ( dest && f.dest ) {
					var ok = Array.prototype.some.call( dest.options, function ( o ) { return o.value === f.dest; } );
					dest.value = ok ? f.dest : 'default';
				}
			} );
		}

		var rulesBody = document.getElementById( 'ai-rules-body' );
		var ruleAdd = document.getElementById( 'ai-rule-add' );
		var ruleTpl = document.getElementById( 'ai-rule-template' );

		function bindRule( row ) {
			var del = row.querySelector( '.ai-rule-del' );
			if ( del ) { del.addEventListener( 'click', function () { row.remove(); } ); }
		}
		if ( rulesBody ) {
			rulesBody.querySelectorAll( 'tr.ai-rule' ).forEach( bindRule );
		}
		if ( ruleAdd && rulesBody && ruleTpl ) {
			ruleAdd.addEventListener( 'click', function () {
				rulesBody.appendChild( ruleTpl.content.cloneNode( true ) );
				bindRule( rulesBody.lastElementChild );
				if ( window.jQuery && window.jQuery( rulesBody ).is( ':data(ui-sortable)' ) ) {
					window.jQuery( rulesBody ).sortable( 'refresh' );
				}
			} );
		}

		function fillSourceFields() {
			var dl = document.getElementById( 'ai-source-fields' );
			if ( ! dl ) { return; }
			var names = {};
			document.querySelectorAll( 'input[name="field_name[]"]' ).forEach( function ( i ) { if ( i.value ) { names[ i.value ] = 1; } } );
			dl.innerHTML = '';
			Object.keys( names ).forEach( function ( n ) { var o = document.createElement( 'option' ); o.value = n; dl.appendChild( o ); } );
		}
		document.querySelectorAll( 'input[name="field_name[]"]' ).forEach( function ( i ) { i.addEventListener( 'input', fillSourceFields ); } );
		fillSourceFields();

		function fillMetaKeys() {
			var dl = document.getElementById( 'ai-meta-keys' );
			var pt = ( document.getElementById( 'ai-post-type' ) || {} ).value || '';
			if ( ! dl || ! cfg.root || ! pt ) { return; }
			fetch( cfg.root + 'post-type-fields?type=' + encodeURIComponent( pt ), { headers: { 'X-WP-Nonce': cfg.nonce } } )
				.then( function ( r ) { return r.json(); } ).then( function ( data ) {
					if ( ! data || ! data.fields ) { return; }
					dl.innerHTML = '';
					data.fields.forEach( function ( k ) { var o = document.createElement( 'option' ); o.value = k; dl.appendChild( o ); } );
				} ).catch( function () {} );
		}
		var ptInput = document.getElementById( 'ai-post-type' );
		if ( ptInput ) { ptInput.addEventListener( 'change', fillMetaKeys ); }
		fillMetaKeys();

		window.addEventListener( 'load', function () {
			if ( window.jQuery && window.jQuery.fn.sortable && rulesBody ) {
				window.jQuery( rulesBody ).sortable( { handle: '.ai-rule-handle', items: '> tr', axis: 'y' } );
			}
		} );
	} )();
	</script>

	<details class="postbox">
		<summary><?php esc_html_e( 'Import many feeds at once (OPML)', 'aggregate-it' ); ?></summary>
		<div class="inside">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aggregate_it_import_opml">
			<?php wp_nonce_field( 'aggregate_it_import_opml' ); ?>
			<p><input type="file" id="ai-opml-file" accept=".opml,.xml,text/xml"></p>
			<textarea name="opml" id="ai-opml" rows="6" class="large-text code"
				aria-label="<?php esc_attr_e( 'OPML to import', 'aggregate-it' ); ?>"
				placeholder="<?php esc_attr_e( 'Choose a file above, or paste your OPML export here', 'aggregate-it' ); ?>"></textarea>
			<p><button type="submit" class="button"><?php esc_html_e( 'Import feeds', 'aggregate-it' ); ?></button></p>
		</form>
		<script>
		( function () {
			var input = document.getElementById( 'ai-opml-file' );
			var area = document.getElementById( 'ai-opml' );
			if ( ! input || ! area ) { return; }
			input.addEventListener( 'change', function () {
				var file = input.files && input.files[0];
				if ( ! file ) { return; }
				var reader = new FileReader();
				reader.onload = function () { area.value = String( reader.result || '' ); };
				reader.readAsText( file );
			} );
		} )();
		</script>
		</div>
	</details>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ai-bulk-sources">
	<input type="hidden" name="action" value="aggregate_it_bulk_sources">
	<?php wp_nonce_field( 'aggregate_it_bulk_sources' ); ?>
	<div class="ai-inline" style="margin-bottom: 12px;">
		<label for="ai-source-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'aggregate-it' ); ?></label>
		<select name="bulk_action" id="ai-source-bulk-action">
			<option value=""><?php esc_html_e( 'Bulk actions', 'aggregate-it' ); ?></option>
			<option value="activate"><?php esc_html_e( 'Activate', 'aggregate-it' ); ?></option>
			<option value="pause"><?php esc_html_e( 'Pause', 'aggregate-it' ); ?></option>
			<option value="auto_categories"><?php esc_html_e( 'Auto-match categories', 'aggregate-it' ); ?></option>
			<option value="set_category"><?php esc_html_e( 'Set category', 'aggregate-it' ); ?></option>
			<option value="clear_categories"><?php esc_html_e( 'Clear categories', 'aggregate-it' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></option>
		</select>
		<?php $bulk_cats = get_categories( [ 'hide_empty' => false, 'number' => 200 ] ); ?>
		<label for="ai-bulk-category" class="screen-reader-text"><?php esc_html_e( 'Category', 'aggregate-it' ); ?></label>
		<select name="bulk_category" id="ai-bulk-category">
			<option value="0"><?php esc_html_e( 'Choose category', 'aggregate-it' ); ?></option>
			<?php foreach ( $bulk_cats as $cat ) : ?>
				<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Apply', 'aggregate-it' ); ?></button>
	</div>
	<table class="widefat striped">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column"><input type="checkbox" id="ai-source-select-all"></td>
				<th><?php esc_html_e( 'Title', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Feed address', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Last checked', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'How it is doing', 'aggregate-it' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $sources ) : ?>
				<tr><td colspan="6" class="ai-empty"><?php esc_html_e( 'No feeds yet. Add one above to get started.', 'aggregate-it' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $sources as $source ) : ?>
				<?php
				$health = $source->health;
				$health_text = ! empty( $health['last_error'] )
					? esc_html( sprintf( /* translators: %s: error message */ __( 'Problem: %s', 'aggregate-it' ), $health['last_error'] ) )
					: ( isset( $health['last_imported'] ) ? sprintf( /* translators: %d: article count */ esc_html__( '%d new articles last time', 'aggregate-it' ), (int) $health['last_imported'] ) : '—' );
				$import_url = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_import_now&id=' . $source->id ), 'aggregate_it_import_now_' . $source->id );
				$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_delete_source&id=' . $source->id ), 'aggregate_it_delete_source_' . $source->id );
				$edit_url   = admin_url( 'admin.php?page=aggregate-it-sources&edit=' . $source->id );
				?>
				<tr>
					<th scope="row" class="check-column"><input type="checkbox" name="source_ids[]" value="<?php echo (int) $source->id; ?>"></th>
					<td>
						<strong><?php echo esc_html( $source->title ?: '—' ); ?></strong>
						<div class="row-actions">
							<span class="check"><a href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Check now', 'aggregate-it' ); ?></a> | </span>
							<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'aggregate-it' ); ?></a> | </span>
							<span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this feed?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></a></span>
						</div>
					</td>
					<td class="ai-trunc"><?php echo esc_html( $source->url ); ?></td>
					<td><span class="post-state"><?php echo esc_html( $source->status ); ?></span></td>
					<td><?php echo esc_html( $source->last_checked ?: '—' ); ?></td>
					<td><?php echo $health_text; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</form>
	<script>
	( function () {
		var form = document.getElementById( 'ai-bulk-sources' );
		var all = document.getElementById( 'ai-source-select-all' );
		if ( all && form ) {
			all.addEventListener( 'change', function () {
				form.querySelectorAll( 'input[name="source_ids[]"]' ).forEach( function ( input ) {
					input.checked = all.checked;
				} );
			} );
		}
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var action = document.getElementById( 'ai-source-bulk-action' );
				if ( action && action.value === 'delete' && ! confirm( '<?php echo esc_js( __( 'Delete selected feeds?', 'aggregate-it' ) ); ?>' ) ) {
					event.preventDefault();
				}
			} );
		}
	} )();
	</script>
</div>
