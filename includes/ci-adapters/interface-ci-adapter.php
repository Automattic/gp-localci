<?php
interface GP_LocalCI_CI_Adapter {
	public function get_build_owner();

	public function get_build_repo();

	public function get_build_sha();

	public function get_new_strings_po();

	public function get_gp_project_id();
}

