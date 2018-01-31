<?php

class GP_Route_Main {
	function tmpl( $arg1 ) {
		throw new Exception( $arg1 );
	}

	function die_with_error( $message, $status = 500 ) {
		throw new Exception( $message, $status );
	}
}

class Mock_Build_CI {
	var $payload;

	function get_gh_data() {
		if ( ! is_object( $this->payload ) ) {
			return false;
		}

		return (object) array(
			'owner'  => $this->payload->owner,
			'repo'   => $this->payload->repo,
			'sha'    => $this->payload->sha,
			'branch' => $this->payload->branch,
		);
	}

	function get_new_strings_pot() {
		return '';
	}
}

class Mock_DB_Adapter {
}

class Mock_Github_Adapter {
}
