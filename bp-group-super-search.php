<?php
/*
Plugin Name: BP Group Super Search
Version: 0.1-alpha
Description: SUPER SEARCH
Author: Boone Gorges
Author URI: http://boone.gorg.es
Text Domain: bp-group-super-search
Domain Path: /languages
*/

function bpgss_init() {
	if ( ! bp_is_active( 'groups' ) ) {
		return;
	}

	require dirname( __FILE__ ) . '/includes/bpgss.php';
}
add_action( 'bp_include', 'bpgss_init' );

