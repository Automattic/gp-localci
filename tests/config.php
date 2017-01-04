<?php

define( 'LOCALCI_BUILD_CI', 'circleci' );
define( 'LOCALCI_DESIRED_LOCALES', array( 'de', 'ja', 'he', 'ru' ) );
define( 'LOCALCI_GITHUB_API_URL', 'https://api.github.com' );
define( 'LOCALCI_GITHUB_API_MANAGEMENT_TOKEN', '' );

class GP_LocalCI_Config {
	public static $repo_metadata = array(
		'unit-test/abc123' => array(
			'build_ci_api_token'     => 'the cat in the hat comes back',
			'github_webhook_secret'  => 'but i dont like green eggs n ham',
			'gp_project_id'          => 9999,
		)
	);

	public static function get_value( $owner, $repo, $key ) {
		$fqrn = strtolower( "$owner/$repo" );
		return isset( self::$repo_metadata[ $fqrn ][ $key ] ) ? self::$repo_metadata[ $fqrn ][ $key ] : false;
	}
}
