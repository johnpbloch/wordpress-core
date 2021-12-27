<?php
/**
 * WordPress Administration for Navigation Menus
 * Interface functions
 *
 * @version 2.0.0
 *
 * @package WordPress
 * @subpackage Administration
 */

/** Load WordPress Administration Bootstrap */
require_once( 'admin.php' );

// Load all the nav menu interface functions
require_once( ABSPATH . 'wp-admin/includes/nav-menu.php' );

if ( ! current_theme_supports( 'menus' ) && ! current_theme_supports( 'widgets' ) )
	wp_die( __( 'Your theme does not support navigation menus or widgets.' ) );

// Permissions Check
if ( ! current_user_can('edit_theme_options') )
	wp_die( __( 'Cheatin&#8217; uh?' ) );

// Nav Menu CSS
wp_admin_css( 'nav-menu' );

// jQuery
wp_enqueue_script( 'jquery' );
wp_enqueue_script( 'jquery-ui-draggable' );
wp_enqueue_script( 'jquery-ui-droppable' );
wp_enqueue_script( 'jquery-ui-sortable' );

// Nav Menu functions
wp_enqueue_script( 'nav-menu' );

// Metaboxes
wp_enqueue_script( 'common' );
wp_enqueue_script( 'wp-lists' );
wp_enqueue_script( 'postbox' );

// Container for any messages displayed to the user
$messages = array();

// Container that stores the name of the active menu
$nav_menu_selected_title = '';

// The menu id of the current menu being edited
$nav_menu_selected_id = isset( $_REQUEST['menu'] ) ? (int) $_REQUEST['menu'] : 0;

// Allowed actions: add, update, delete
$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'edit';

switch ( $action ) {
	case 'add-menu-item':
		check_admin_referer( 'add-menu_item', 'menu-settings-column-nonce' );
		if ( isset( $_REQUEST['nav-menu-locations'] ) )
			set_theme_mod( 'nav_menu_locations', array_map( 'absint', $_REQUEST['menu-locations'] ) );
		elseif ( isset( $_REQUEST['menu-item'] ) )
			wp_save_nav_menu_items( $nav_menu_selected_id, $_REQUEST['menu-item'] );
		break;
	case 'move-down-menu-item' :
		// moving down a menu item is the same as moving up the next in order
		check_admin_referer( 'move-menu_item' );
		$menu_item_id = isset( $_REQUEST['menu-item'] ) ? (int) $_REQUEST['menu-item'] : 0;
		if ( is_nav_menu_item( $menu_item_id ) ) {
			$menus = isset( $_REQUEST['menu'] ) ? array( (int) $_REQUEST['menu'] ) : wp_get_object_terms( $menu_item_id, 'nav_menu', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $menus ) && ! empty( $menus[0] ) ) {
				$menu_id = (int) $menus[0];
				$ordered_menu_items = wp_get_nav_menu_items( $menu_id );
				$menu_item_data = (array) wp_setup_nav_menu_item( get_post( $menu_item_id ) );

				// setup the data we need in one pass through the array of menu items
				$dbids_to_orders = array();
				$orders_to_dbids = array();
				foreach( (array) $ordered_menu_items as $ordered_menu_item_object ) {
					if ( isset( $ordered_menu_item_object->ID ) ) {
						if ( isset( $ordered_menu_item_object->menu_order ) ) {
							$dbids_to_orders[$ordered_menu_item_object->ID] = $ordered_menu_item_object->menu_order;
							$orders_to_dbids[$ordered_menu_item_object->menu_order] = $ordered_menu_item_object->ID;
						}
					}
				}

				// get next in order
				if (
					isset( $orders_to_dbids[$dbids_to_orders[$menu_item_id] + 1] )
				) {
					$next_item_id = $orders_to_dbids[$dbids_to_orders[$menu_item_id] + 1];
					$next_item_data = (array) wp_setup_nav_menu_item( get_post( $next_item_id ) );

					// if not siblings of same parent, bubble menu item up but keep order
					if (
						! empty( $menu_item_data['menu_item_parent'] ) &&
						(
							empty( $next_item_data['menu_item_parent'] ) ||
							$next_item_data['menu_item_parent'] != $menu_item_data['menu_item_parent']
						)
					) {

						$p