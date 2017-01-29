<?php

trait GP_Localci_Log {
	function log( $type, $context, $message ) {
		do_action( 'gp_localci_log', $type, $context, $message );
	}
}

trait GP_LocalCI_Cached_Remote_Get {

	use GP_Localci_Log;

	function cached_get( $url, $cache_time = 0, $args = array() ) {
		$cache_group = 'circleci_artifacts_get';
		$cache_key   = md5( $url );

		if ( false !== $cache = wp_cache_get( $cache_key, $cache_group ) ) {
			return $cache;
		}

		$this->log( 'remote-request', 'cached', array(
			'url' => $url,
			'type' => 'GET',
			'args' => $args,
		) );

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$content = wp_remote_retrieve_body( $response );
		wp_cache_add( $cache_key, $content, $cache_group, $cache_time );

		return $content;
	}
}
