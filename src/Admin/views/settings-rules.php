<?php

namespace AggregateIt\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * @var object[]                          $public_types
 * @var string                            $rtype
 * @var array<int,array<string,string>>   $rules
 * @var array<string,string>              $rule_ops
 * @var bool                              $saved
 */

if ( ! $rules ) {
	$rules = [ [ 'field' => '', 'op' => 'always', 'value' => '', 'set_key' => '', 'set_value' => '' ] ];
}

$render_rule_row = static function ( array $rule, array $rule_ops ): void {
	?>
	<tr class="ai-rule">
		<td class="ai-rule-handle" title="<?php esc_attr_e( 'Drag to reorder', 'aggregate-it' ); ?>">⠿</td>
		<td><input type="text" name="rule_field[]" list="ai-source-fields" value="<?php echo esc_attr( (string) ( $rule['field'] ?? '' ) ); ?>" placeholder="event_date"></td>
		<td>
			<select name="rule_op[]">
				<?php foreach ( $rule_ops as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) ( $rule['op'] ?? 'always' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="text" name="rule_value[]" value="<?php echo esc_attr( (string) ( $rule['value'] ?? '' ) ); ?>"></td>
		<td><input type="text" name="rule_set_key[]" list="ai-meta-keys" value="<?php echo esc_attr( (string) ( $rule['set_key'] ?? '' ) ); ?>" placeholder="event_status"></td>
		<td><input type="text" name="rule_set_value[]" value="<?php echo esc_attr( (string) ( $rule['set_value'] ?? '' ) ); ?>" placeholder="Upcoming or {event_date|Y-m-d}"></td>
		<td><button type="button" class="button-link ai-rule-del" title="<?php esc_attr_e( 'Remove', 'aggregate-it' ); ?>">✕</button></td>
	</tr>
	<?php
};

$base = admin_url( 'admin.php?page=aggregate-it-settings&tab=rules' );
?>
<?php if ( $saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rules saved. They’ll apply to existing posts shortly.', 'aggregate-it' ); ?></p></div>
<?php endif; ?>

<p>
	<label for="ai-rtype"><strong><?php esc_html_e( 'Rules for post type', 'aggregate-it' ); ?></strong></label>
	<select id="ai-rtype" onchange="window.location.href='<?php echo esc_url( $base ); ?>&rtype='+this.value;">
		<?php foreach ( $public_types as $pt ) : ?>
			<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $rtype, $pt->name ); ?>><?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?> (<?php echo esc_html( $pt->name ); ?>)</option>
		<?php endforeach; ?>
	</select>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="aggregate_it_save_rules">
	<input type="hidden" name="rtype" value="<?php echo esc_attr( $rtype ); ?>">
	<?php wp_nonce_field( 'aggregate_it_save_rules' ); ?>

	<table class="ai-rules-table widefat" id="ai-rules">
		<thead><tr>
			<th></th>
			<th><?php esc_html_e( 'If field (meta key)', 'aggregate-it' ); ?></th>
			<th><?php esc_html_e( 'Condition', 'aggregate-it' ); ?></th>
			<th><?php esc_html_e( 'Value', 'aggregate-it' ); ?></th>
			<th><?php esc_html_e( 'Set meta key', 'aggregate-it' ); ?></th>
			<th><?php esc_html_e( 'To value', 'aggregate-it' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody id="ai-rules-body">
			<?php foreach ( $rules as $rule ) : ?>
				<?php $render_rule_row( (array) $rule, $rule_ops ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<button type="button" class="button" id="ai-rule-add"><?php esc_html_e( 'Add rule', 'aggregate-it' ); ?></button>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save rules', 'aggregate-it' ); ?></button>
	</p>
	<datalist id="ai-source-fields"></datalist>
	<datalist id="ai-meta-keys"></datalist>
	<template id="ai-rule-template"><?php $render_rule_row( [ 'op' => 'always' ], $rule_ops ); ?></template>
</form>

<script>
( function () {
	var cfg = window.AggregateItAdmin || {};
	var body = document.getElementById( 'ai-rules-body' );
	var add = document.getElementById( 'ai-rule-add' );
	var tpl = document.getElementById( 'ai-rule-template' );
	var rtype = <?php echo wp_json_encode( $rtype ); ?>;

	function bind( row ) {
		var del = row.querySelector( '.ai-rule-del' );
		if ( del ) { del.addEventListener( 'click', function () { row.remove(); } ); }
	}
	if ( body ) { body.querySelectorAll( 'tr.ai-rule' ).forEach( bind ); }
	if ( add && body && tpl ) {
		add.addEventListener( 'click', function () {
			body.appendChild( tpl.content.cloneNode( true ) );
			bind( body.lastElementChild );
			if ( window.jQuery && window.jQuery( body ).is( ':data(ui-sortable)' ) ) { window.jQuery( body ).sortable( 'refresh' ); }
		} );
	}

	if ( cfg.root && rtype ) {
		fetch( cfg.root + 'post-type-fields?type=' + encodeURIComponent( rtype ), { headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) { return r.json(); } ).then( function ( data ) {
				if ( ! data || ! data.fields ) { return; }
				[ 'ai-source-fields', 'ai-meta-keys' ].forEach( function ( id ) {
					var dl = document.getElementById( id );
					if ( ! dl ) { return; }
					dl.innerHTML = '';
					data.fields.forEach( function ( k ) { var o = document.createElement( 'option' ); o.value = k; dl.appendChild( o ); } );
				} );
			} ).catch( function () {} );
	}

	window.addEventListener( 'load', function () {
		if ( window.jQuery && window.jQuery.fn.sortable && body ) {
			window.jQuery( body ).sortable( { handle: '.ai-rule-handle', items: '> tr', axis: 'y' } );
		}
	} );
} )();
</script>
