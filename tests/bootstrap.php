<?php
error_reporting( E_ALL ^ E_STRICT );

define( 'GP_LOCALCI_UNIT_TEST', true );
define( 'HOUR_IN_SECONDS', 60 * 60 );

require __DIR__ . '/mocks.php';
require __DIR__ . '/stubs.php';

if ( file_exists( __DIR__ . '/../gp-localci.php' ) ) {
	// local test
	require __DIR__ . '/config.php';
	require __DIR__ . '/../gp-localci.php';
} else {
	die( 'Unknown environment' );
}