<?php
/**
 * WordPress Comment Administration API.
 *
 * @package WordPress
 * @subpackage Administration
 */

/**
 * Determine if a comment exists based on author and date.
 *
 * @since 2.0.0
 * @uses $wpdb
 *
 * @param string $comment_author Author of the comment
 * @param string $comment_date Date of the comment
 * @return mixed Comment post ID on success.
 */
function comment_exists($comment_author, $comment_date) {
	global $wpdb;

	$comment_author = stripslashes($comment_author);
	$comment_date = strip