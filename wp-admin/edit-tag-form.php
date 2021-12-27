<?php
/**
 * Edit tag form for inclusion in administration panels.
 *
 * @package WordPress
 * @subpackage Administration
 */

// don't load directly
if ( !defined('ABSPATH') )
	die('-1');

if ( empty($tag_ID) ) { ?>
	<div id="message" class="updated"><p><strong><?php _e( 'You did not select an item for editing.' ); ?></strong></p></div>
<?php
	return;
}

// Back compat hooks
if ( 'category' == $taxonomy )
	do_action('edit_category_form_pre', $tag );
elseif ( 'link_category' == $taxonomy )
	do_action('edit_link_category_form_pre', $tag );
else
	do_action('edit_tag_form_pre', $tag);

do_action($taxonomy . '_pre_edit_form', $tag, $taxonomy);  ?>

<div class="wrap">
<?php screen_icon(); ?>
<h2><?php echo $tax->labels->edit_item; ?></h2>
<div id="ajax-response"></div>
<form name="edittag" id="edittag" method="post" action="edit-tags.php" class="validate">
<input type="hidden" name="action" value="editedtag" />
<input type="hidden" name="tag_ID" value="<?php echo esc_attr($tag->term_id) ?>" />
<input type="hidden"