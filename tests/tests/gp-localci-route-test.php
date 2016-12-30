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

	/**
	 * @expectedException            Exception
	 * @expectedExceptionMessage     status-ok
	 */
	function test_relay_new_strings_to_gh_empty_pot() {
		$this->gp_localci->ci->payload = (object) array(
			'owner'  => 'unit-test',
			'repo'   => 'abc123',
			'sha'    => 'fc9df6ee7b05acd4ff34c1f112b2c9dd3c53f70e',
			'branch' => 'fix/whatever',
		);

		$this->gp_localci->relay_new_strings_to_gh();
	}

	/**
	 * @expectedException         Exception
	 * @expectedExceptionCode     401
	 */
	function test_relay_string_freeze_from_gh_invalid_request() {
		$_SERVER['HTTP_USER_AGENT'] = 'GitHub-Hookshot/Something Something';
		$_SERVER['HTTP_CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
		$_SERVER['HTTP_X_GITHUB_EVENT'] = 'pull_request';
		$_SERVER['HTTP_X_HUB_SIGNATURE'] = 'invalid non-matching sig';
		$_POST['payload'] = json_encode( (object) array(
			'repository' => (object) array(
				'owner' => (object) array(
					'login' => 'unit-test'
				),
				'name' => 'abc123'
			),
			'pull_request' => (object) array(
				'head' => (object) array(
					'ref' => 'fix/whatever',
					'sha' => 'fc9df6ee7b05acd4ff34c1f112b2c9dd3c53f70e'
				)
			)
		) );

		$this->gp_localci->gh->__construct();
		$this->gp_localci->relay_string_freeze_from_gh();
	}
}
