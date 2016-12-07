<?php
interface GP_LocalCI_CI_Adapter {
	public function get_gh_data();

	public function get_new_strings_po();

	public function get_gp_project_id();

	public function safe_get( $url );
}

