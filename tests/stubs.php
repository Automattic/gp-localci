<?php
$GLOBALS['localci_sha_lock'] = array();

function set_transient( $dummy1, $shas, $dummy3 ) {
	global $localci_sha_lock;
	$localci_sha_lock = $shas;
}

function get_transient( $dummy1 ) {
	global $localci_sha_lock;
	return $localci_sha_lock;
}

function add_action( $dummy1, $dummy2 ) {
	// stub
}

function gp_startswith( $haystack, $needle ) {
	return 0 === strpos( $haystack, $needle );
}

function gp_endswith( $haystack, $needle ) {
	return substr( $haystack, -strlen( $needle ) ) === $needle;
}

function gp_in( $needle, $haystack ) {
	return false !== strpos( $haystack, $needle );
}

function wp_safe_remote_post( $dummy1 ) {
	return true;
}

function wp_json_encode( $what ) {
	return json_encode( $what );
}
