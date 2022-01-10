<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends MX_Controller {

  public function __construct()
  {
    parent::__construct();

    // check user is logged in

    // check permissions

    // load models and libs
  }

	public function index()
	{
		$this->load->view('blog');
	}

  // view 

  // create

  // edit

  // delete
}
