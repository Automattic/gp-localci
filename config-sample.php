<?php

define( 'LOCALCI_BUILD_CI', 'circleci' );
define( 'LOCALCI_DESIRED_LOCALES', '' );
define( 'LOCALCI_GITHUB_API_URL', 'https://api.github.com' );
define( 'LOCALCI_GITHUB_API_MANAGEMENT_TOKEN', '' );
define( 'LOCALCI_GITHUB_USER_NAME', 'botname' );
define( 'LOCALCI_GITHUB_STRING_FREEZE_LABEL', 'string freeze' );

class GP_LocalCI_Config {
	public static $repo_metadata = array();

	public static function get_value( $owner, $repo, $key ) {
		$fqrn = strtolower( "$owner/$repo" );
		return isset( self::$repo_metadata[ $fqrn ][ $key ] ) ? self::$repo_metadata[ $fqrn ][ $key ] : false;
	}
}
