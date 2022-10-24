<?php
/**
 * Edit Comments Administration Screen.
 *
 * @package WordPress
 * @subpackage Administration
 */

/** WordPress Administration Bootstrap */
require_once( dirname( __FILE__ ) . '/admin.php' );
if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die(
		'<h1>' . __( 'Cheatin&#8217; uh?' ) . '</h1>' .
		'<p>' . __( 'You are not allowed to edit comments.' ) . '</p>',
		403
	);
}

$wp_list_table = _get_list_table('WP_Comments_List_Table');
$pagenum = $wp_list_table->get_pagenum();

$doaction = $wp_list_table->current_action();

if ( $doaction ) {
	check_admin_referer( 'bulk-comments' );

	if ( 'delete_all' == $doaction && !empty( $_REQUEST['pagegen_timestamp'] ) ) {
		$comment_status = wp_unslash( $_REQUEST['comment_status'] );
		$delete_time = wp_unslash( $_REQUEST['pagegen_timestamp'] );
		$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = %s AND %s > comment_date_gmt", $comment_status, $delete_time ) );
		$doaction = 'delete';
	} elseif ( isset( $_REQUEST['delete_comments'] ) ) {
		$comment_ids = $_REQUEST['delete_comments'];
		$doaction = ( $_REQUEST['action'] != -1 ) ? $_REQUEST['action'] : $_REQUEST['action2'];
	} elseif ( isset( $_REQUEST['ids'] ) ) {
		$comment_ids = array_map( 'absint', explode( ',', $_REQUEST['ids'] ) );
	} elseif ( wp_get_referer() ) {
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	$approved = $unapproved = $spammed = $unspammed = $trashed = $untrashed = $deleted = 0;

	$redirect_to = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'spammed', 'unspammed', 'approved', 'unapproved', 'ids' ), wp_get_referer() );
	$redirect_to = add_query_arg( 'paged', $pagenum, $redirect_to );

	wp_defer_comment_counting( true );

	foreach ( $comment_ids as $comment_id ) { // Check the permissions on each
		if ( !current_user_can( 'edit_comment', $comment_id ) )
			continue;

		switch ( $doaction ) {
			case 'approve' :
				wp_set_comment_status( $comment_id, 'approve' );
				$approved++;
				break;
			case 'unapprove' :
				wp_set_comment_status( $comment_id, 'hold' );
				$unapproved++;
				break;
			case 'spam' :
				wp_spam_comment( $comment_id );
				$spammed++;
				break;
			case 'unspam' :
				wp_unspam_comment( $comment_id );
				$unspammed++;
				break;
			case 'trash' :
				wp_trash_comment( $comment_id );
				$trashed++;
				break;
			case 'untrash' :
				wp_untrash_comment( $comment_id );
				$untrashed++;
				break;
			case 'delete' :
				wp_delete_comment( $comment_id );
				$deleted++;
				break;
		}
	}

	wp_defer_comment_counting( false );

	if ( $approved )
		$redirect_to = add_query_arg( 'approved', $approved, $redirect_to );
	if ( $unapproved )
		$redirect_to = add_query_arg( 'unapproved', $unapproved, $redirect_to );
	if ( $spammed )
		$redirect_to = add_query_arg( 'spammed', $spammed, $redirect_to );
	if ( $unspammed )
		$redirect_to = add_query_arg( 'unspammed', $unspammed, $redirect_to );
	if ( $trashed )
		$redirect_to = add_query_arg( 'trashed', $trashed, $redirect_to );
	if ( $untrashed )
		$redirect_to = add_query_arg( 'untrashed', $untrashed, $redirect_to );
	if ( $deleted )
		$redirect_to = add_query_arg( 'deleted', $deleted, $redirect_to );
	if ( $trashed || $spammed )
		$redirect_to = add_query_arg( 'ids', join( ',', $comment_ids ), $redirect_to );

	wp_safe_redirect( $redirect_to );
	exit;
} elseif ( ! empty( $_GET['_wp_http_referer'] ) ) {
	 wp_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	 exit;
}

$wp_list_table->prepare_items();

wp_enqueue_script('admin-comments');
enqueue_comment_hotkeys_js();

if ( $post_id ) {
	$comments_count = wp_count_comments( $post_id );
	$draft_or_post_title = wp_html_excerpt( _draft_or_post_title( $post_id ), 50, '&hellip;' );
	if ( $comments_count->moderated > 0 ) {
		/* translators: 1: comments count 2: post title */
		$title = sprintf( __( 'Comments (%1$s) on &#8220;%2$s&#8221;' ),
			number_format_i18n( $comments_count->moderated ),
			$draft_or_post_title
		);
	} else {
		/* translators: %s: post title */
		$title = sprintf( __( 'Comments on &#8220;%s&#8221;' ),
			$draft_or_post_title
		);
	}
} else {
	$comments_count = wp_count_comments();
	if ( $comments_count->moderated > 0 ) {
		/* translators: %s: comments count */
		$title = sprintf( __( 'Comments (%s)' ),
			number_format_i18n( $comments_count->moderated )
		);
	} else {
		$title = __( 'Comments' );
	}
}

add_screen_option( 'per_page' );

get_current_screen()->add_help_tab( array(
'id'		=> 'overview',
'title'		=> __('Overview'),
'content'	=>
	'<p>' . __( 'You can manage comments made on your site similar to the way you manage posts and other content. This screen is customizable in the same ways as other management screens, and you can act on comments using the on-hover action links or the Bulk Actions.' ) . '</p>'
) );
get_current_screen()->add_help_tab( array(
'id'		=> 'moderating-comments',
'title'		=> __('Moderating Comments'),
'content'	=>
		'<p>' . __( 'A red bar on the left means the comment is waiting for you to moderate it.' ) . '</p>' .
		'<p>' . __( 'In the <strong>Author</strong> column, in addition to the author&#8217;s name, email address, and blog URL, the commenter&#8217;s IP address is shown. Clicking on this link will show you all the comments made from this IP address.' ) . '</p>' .
		'<p>' . __( 'In the <strong>Comment</strong> column, hovering over any comment gives you options to approve, reply (and approve), quick edit, edit, spam mark, or trash that comment.' ) . '</p>' .
		'<p>' . __( 'In the <strong>In Response To</strong> column, there are three elements. The text is the name of the post that inspired the comment, and links to the post editor for that entry. The View Post link leads to that post on your live site. The small bubble with the number in it shows the number of approved comments that post has received. If there are pending comments, a red notification circle with the number of pending comments is displayed. Clicking the notification circle will filter the comments screen to show only pending comments on that post.' ) . '</p>' .
		'<p>' . __( 'In the <strong>Submitted On</strong> column, the date and time the comment was left on your site appears. Clicking on the date/time link will take you to that comment on your live site.' ) . '</p>' .
		'<p>' . __( 'Many people take advantage of keyboard shortcuts to moderate their comments more quickly. Use the link to the side to learn more.' ) . '</p>'
) );

get_current_screen()->set_help_sidebar(
	'<p><strong>' . __( 'For more information:' ) . '</strong></p>' .
	'<p>' . __( '<a href="https://codex.wordpress.org/Administration_Screens#Comments" target="_blank">Documentation on Comments</a>' ) . '</p>' .
	'<p>' . __( '<a href="https://codex.wordpress.org/Comment_Spam" target="_blank">Documentation on Comment Spam</a>' ) . '</p>' .
	'<p>' . __( '<a href="https://codex.wordpress.org/Keyboard_Shortcuts" target="_blank">Documentation on Keyboard Shortcuts</a>' ) . '</p>' .
	'<p>' . __( '<a href="https://wordpress.org/support/" target="_blank">Support Forums</a>' ) . '</p>'
);

get_current_screen()->set_screen_reader_content( array(
	'heading_views'      => __( 'Filter comments list' ),
	'heading_pagination' => __( 'Comments list navigation' ),
	'heading_list'       => __( 'Comments list' ),
) );

require_once( ABSPATH . 'wp-admin/admin-header.php' );
?>

<div class="wrap">
<h1><?php
if ( $post_id ) {
	/* translators: %s: link to post */
	printf( __( 'Comments on &#8220;%s&#8221;' ),
		sprintf( '<a href="%1$s">%2$s</a>',
			get_edit_post_link( $post_id ),
			wp_html_excerpt( _draft_or