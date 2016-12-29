<?php
class Test_GP_LocalCI_Route extends PHPUnit_Framework_TestCase {
	function setUp() {
		$this->gp_localci = new GP_Route_LocalCI(
			$ci = new Mock_Build_CI(),
			$db = new Mock_DB_Adapter()
		);
		$this->gp_localci->api = true;
	}

	/**
	 * @expectedException      Exception
	 * @expectedExceptionCode  400
	 */
	function test_relay_new_strings_to_gh_empty_payload() {
		$this->gp_localci->relay_new_strings_to_gh();
	}

	/**
	 * @expectedException         Exception
	 * @expectedExceptionMessage  status-ok
	 */
	function test_relay_new_strings_to_gh_already_master_branch() {
		$this->gp_localci->ci->payload = (object) array(
			'owner'  => 'Somebody',
			'repo'   => 'Something',
			'sha'    => 'fc9df6ee7b05acd4ff34c1f112b2c9dd3c53f70e',
			'branch' => 'master',
		);

		$this->gp_localci->relay_new_strings_to_gh();
	}

	/**
	 * @expectedException         Exception
	 * @expectedExceptionCode     400
	 */
	function test_relay_new_strings_to_gh_bad_project_id() {
		$this->gp_localci->ci->payload = (object) array(
			'owner'  => 'Somebody',
			'repo'   => 'Something',
			'sha'    => 'fc9df6ee7b05acd4ff34c1f112b2c9dd3c53f70e',
			'branch' => 'fix/whatever',
		);

		$this->gp_localci->relay_new_strings_to_gh();
	}

	function relay_string_freeze_from_gh() {
	}
}
