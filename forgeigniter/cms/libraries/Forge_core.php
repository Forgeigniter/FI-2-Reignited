<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ForgeIgniter
 * `````````````
 * @package . FI Core Lib
 * @author  . ForgeIgniter Team
 * @copyright Copyright (c) 2015, ForgeIgniter
 * @license . http://forgeigniter.net/license
 * @link  . . http://forgeigniter.net
 * @version . 0.1
*/

/*
  TODO
    - Update core and put the functions in their proper places.
    - This should really be for handy core utilities.

*/

// ------------------------------------------------------------------------

class Forge_core
{
    public $CI; // CI instance
    public $table ; // default table
    public $siteID; // id of the site
    public $uri_assoc_segment = 4;  // segment where the magic happens
    public $adminOverRide = false;  // allows for override of siteID
    public $currentPage;
    public $where = array();
    public $set = array();
    public $required = array();

    public function __construct()
    {
        // init vars
        $this->CI =& get_instance();

        // get siteID, if available
        if (defined('SITEID')) {
            $this->siteID = SITEID;
        }

        // TODO CLEAN UP
        // set groupID from session (if set)
        $this->groupID = ($this->CI->session->userdata('groupID')) ? $this->CI->session->userdata('groupID') : 0;
    }

    // TODO MOVE TO USERS
    public function lookup_user($userID, $display = false)
    {
        // default wheres
        $this->CI->db->where('userID', $userID);

        // grab
        $query = $this->CI->db->get('users', 1);

        if ($query->num_rows()) {
            $row = $query->row_array();

            if ($display !== false) {
                return ($row['displayName']) ? $row['displayName'] : trim($row['firstName'].' '.$row['lastName']);
            } else {
                return $row;
            }
        } else {
            return false;
        }
    }

    // TODO MOVE TO WEB FORM
    public function get_web_form_by_ref($formRef)
    {
        $this->CI->db->where('formRef', $formRef);

        $this->CI->db->where('deleted', 0);
        $this->CI->db->where('siteID', $this->siteID);

        $query = $this->CI->db->get('web_forms', 1);

        if ($query->num_rows()) {
            return $query->row_array();
        } else {
            return false;
        }
    }

    // TODO MOVE TO WEB FORM
    public function web_form()
    {
        // get web form
        if (!$webform = $this->CI->core->get_web_form_by_ref($this->CI->forge_core->decode($this->CI->input->post('formID')))) {
            return false;
        }

        // set main required field
        $this->CI->form_validation->set_rules('email', 'Email', 'required|valid_email');

        // find out if a user account needs to be created
        $account = ($webform['account']) ? true : false;

        // get required fields
        $required = $this->CI->input->post('required', true);

        // get optional required fields
        if ($required) {
            $requiredArray = explode('|', $required);
            foreach ($requiredArray as $field) {
                $this->CI->form_validation->set_rules($field, ucfirst($field), 'required');
            }
        }

        // optional captcha (deprecated - use javascript for captcha)
        (@in_array('captcha', $requiredArray)) ? $this->CI->form_validation->set_rules('captcha', 'Captcha', 'required|callback__captcha_check') : '';

        // get first and last name
        if ($this->CI->input->post('firstName', true)) {
            $firstName = $this->CI->input->post('firstName', true);
            $lastName = $this->CI->input->post('lastName', true);
        } elseif ($fullName = $this->CI->input->post('fullName', true)) {
            $fullNameArray = @explode(' ', $fullName);
            $lastName = (sizeof($fullNameArray) > 0) ? ucfirst(trim(end($fullNameArray))) : '';
            $firstName = (sizeof($fullNameArray) > 0) ? ucfirst(trim($fullNameArray[0])) : $fullName;
        } else {
            $firstName = '';
            $lastName = '';
        }

        // at least set the name and email in to a session
        if (!$this->CI->session->userdata('session_user')) {
            $this->CI->session->set_userdata('email', $this->CI->input->post('email', true));
            $this->CI->session->set_userdata('firstName', $firstName);
            $this->CI->session->set_userdata('lastName', $lastName);
        }

        // if capturing check user is unique and a password matches
        if ($account) {
            // email and message are always required
            $this->CI->form_validation->set_rules('email', 'Email', 'required|valid_email|unique[users.email]|trim');

            // check if password was submitted, make it required if so
            if (array_key_exists('password', $_POST)) {
                // require password confirm?
                if (isset($_POST['confirmPassword'])) {
                    $this->form_validation->set_rules('password', 'Password', 'required|matches[confirmPassword]');
                } else {
                    $this->form_validation->set_rules('password', 'Password', 'required');
                }
            }
        }

        // look for files
        $files = false;
        if ($webform['fileTypes'] && count($_FILES)) {
            foreach ($_FILES as $name => $file) {
                $this->CI->uploads->maxSize = '2000';
                $this->CI->uploads->allowedTypes = $webform['fileTypes'];

                // check a file has actually been uploaded
                if ($file['name'] != '') {
                    if ($fileData = $this->CI->uploads->upload_file($name)) {
                        $files[$name] = $fileData;
                    } else {
                        $this->CI->form_validation->set_error($this->CI->uploads->errors);
                    }
                }
            }
        }

        // add ticket
        if ($this->CI->form_validation->run()) {
            if ($account) {
                // create user
                $this->create_user();

                // set admin session name, if given
                if (!$this->CI->site->config['activation']) {
                    $this->CI->load->library('auth');
                    $username = array('field' => 'email', 'label' => 'Email address', 'value' => $this->CI->input->post('email'));
                    $password = ($this->CI->input->post('password')) ? $this->CI->input->post('password', true) : substr(md5(time()), 0, 6);

                    // login or get error message
                    if (!$this->CI->auth->login($username, $password, 'session_user', false)) {
                        $this->CI->form_validation->set_error($this->CI->auth->error);
                    }
                }
            }

            // add ticket
            $this->add_ticket($webform, $files);

            // redirect if set
            if ($redirect = $webform['outcomeRedirect']) {
                redirect($redirect);
            }

            // get message if set
            if ($message = $webform['outcomeMessage']) {
                return $message;
            } else {
                return 'Thank you, your message was sent successfully.';
            }
        } else {
            return false;
        }
    }

    public function add_ticket($webform, $files = '')
    {
        // get web form
        if (!$webform) {
            return false;
        }

        if ($this->CI->input->post('email')) {
            // set system fields
            $fields = array('required', 'formID', 'fieldSet', 'fileTypes', 'account', 'formName', 'outcomeEmails', 'outcomeRedirect', 'outcomeMessage', 'fullName', 'email', 'subject', 'message', 'toEmail', 'captcha', 'firstName', 'lastName', 'password', 'confirmPassword', 'groupID');

            // set default message
            $message = '';
            $filepaths = '';

            // get extra posted info and prepend to message
            if (count($_POST)) {
                foreach ($_POST as $post => $value) {
                    if (!in_array($post, $fields) && !preg_match('/^submit$|^submit\_x$|^submit\_y|^x|^y/i', $post)) {
                        $postValue = $this->CI->input->post($post, true);
                        $message .= "\t".ucfirst($post) . ": ".$value."\n\n";
                    }
                }
            }

            // get files and prepend to message
            if ($files) {
                $message .= "\tFiles: ".count($files).((count($files) != 1) ? ' files' : ' file')." uploaded\n\n";
                $filepaths .= '<br />';
                foreach ($files as $name => $fileData) {
                    $filepaths .= '<br /><a href="'.site_url($this->CI->uploads->uploadsPath.'/'.$fileData['file_name']).'">'.$fileData['client_name'].'</a>';
                }
            }

            // get posted message
            $message .= (strlen($message) > 1) ? "\n" : '';
            $message .= $this->CI->input->post('message', true);

            // set defaults
            $fullName = ($this->CI->input->post('fullName')) ? $this->CI->input->post('fullName', true) : 'N/A';
            $subject = ($this->CI->input->post('subject')) ? $this->CI->input->post('subject', true) : (($webform['formName']) ? $webform['formName'] : 'No Subject');
            $outcomeEmails = ($webform['outcomeEmails']) ? explode(',', $webform['outcomeEmails']) : $this->CI->site->config['siteEmail'];

            // get first name and last name
            $names = explode(' ', $fullName);
            $firstName = (sizeof($names) > 1 && $names[0]) ? ucfirst(trim($names[0])) : '';
            $lastName = (sizeof($names) > 1) ? ucfirst(end($names)) : '';

            // add ticket
            $this->CI->db->set('siteID', $this->siteID);
            $this->CI->db->set('dateCreated', date("Y-m-d H:i:s"));
            ($webform['formName']) ? $this->CI->db->set('formName', $webform['formName']) : '';
            $this->CI->db->set('fullName', $fullName);
            $this->CI->db->set('email', $this->CI->input->post('email', true));
            $this->CI->db->set('subject', $subject);
            $this->CI->db->set('body', $message.$filepaths);
            $this->CI->db->insert('tickets');
            $ticketID = $this->CI->db->insert_id();

            // set header and footer
            $emailHeader = str_replace('{name}', $fullName, $this->CI->site->config['emailHeader']);
            $emailHeader = str_replace('{first-name}', $firstName, $emailHeader);
            $emailHeader = str_replace('{last-name}', $lastName, $emailHeader);
            $emailHeader = str_replace('{email}', $this->CI->input->post('email', true), $emailHeader);
            $emailFooter = str_replace('{name}', $fullName, $this->CI->site->config['emailFooter']);
            $emailFooter = str_replace('{first-name}', $firstName, $emailFooter);
            $emailFooter = str_replace('{last-name}', $lastName, $emailFooter);
            $emailFooter = str_replace('{email}', $this->CI->input->post('email', true), $emailFooter);
            $emailTicket = str_replace('{name}', $fullName, $this->CI->site->config['emailTicket']);
            $emailTicket = str_replace('{first-name}', $firstName, $emailTicket);
            $emailTicket = str_replace('{last-name}', $lastName, $emailTicket);
            $emailTicket = str_replace('{email}', $this->CI->input->post('email', true), $emailTicket);

            // send despatch email to customer
            $body = $emailHeader."\n\n";
            $body .= $emailTicket."\n\n";
            $body .= "\tTicket ID: ".$ticketID."\n";
            $body .= "\tSubject: ".$subject."\n";
            $body .= "\tName: ".$fullName."\n";
            $body .= "\tEmail: ".$this->CI->input->post('email')."\n\n";

            // attach message
            if ($message) {
                $body .= "Message:\n";
                $body .= "---------------------------------------------\n\n";
                $body .= $message."\n\n";
                $body .= "---------------------------------------------\n\n";
            }

            // send username and password
            if ($webform['account']) {
                $body .= "Your login details are below:\n";
                $body .= "---------------------------------------------\n\n";
                $body .= "Your email: \t".$this->CI->input->post('email')."\n";
                $body .= "Your password: \t".(($this->CI->input->post('password', true)) ? $this->CI->input->post('password', true) : substr(md5(time()), 0, 6))."\n\n";
                $body .= "---------------------------------------------\n\n";
            }

            $footerBody = $emailFooter;

            // load email lib and email user and admin
            $this->CI->load->library('email');

            // attach files
            if ($files) {
                foreach ($files as $file) {
                    $this->CI->email->attach($file['full_path']);
                }
            }

            // send to recipient
            $this->CI->email->to($this->CI->input->post('email', true));
            $this->CI->email->from($this->CI->site->config['siteEmail'], $this->CI->site->config['siteName']);
            $this->CI->email->subject('[#'.$ticketID.']: ' . $subject);
            $this->CI->email->message($body.$footerBody);
            $this->CI->email->send();

            $this->CI->email->clear();

            // send to CC or admin
            $this->CI->email->to($outcomeEmails);
            $this->CI->email->from($this->CI->input->post('email', true));
            $this->CI->email->subject('FW: [#'.$ticketID.']: ' . $this->CI->input->post('subject', true));
            $this->CI->email->message("A web form was submitted on ".$this->CI->site->config['siteName'].".\n\n---------------------------------------------\n\n".$body.$footerBody);
            $this->CI->email->send();

            return $ticketID;
        } else {
            return false;
        }
    }

    // TODO MOVE TO USERS
    public function create_user()
    {
        // get values
        $this->CI->forge_core->get_values('users');

        // security check
        if ($this->CI->input->post('username')) {
            $this->CI->core->set['username'] = '';
        }
        if ($this->CI->input->post('subscribed')) {
            $this->CI->core->set['subscribed'] = '';
        }
        if ($this->CI->input->post('plan')) {
            $this->CI->core->set['plan'] = '';
        }
        if ($this->CI->input->post('siteID')) {
            $this->CI->core->set['siteID'] = $this->siteID;
        }
        if ($this->CI->input->post('userID')) {
            $this->CI->core->set['userID'] = '';
        }
        if ($this->CI->input->post('kudos')) {
            $this->CI->core->set['kudos'] = '';
        }
        if ($this->CI->input->post('posts')) {
            $this->CI->core->set['posts'] = '';
        }

        // set folder (making sure it's not an admin folder)
        $permissionGroupsArray = $this->CI->permission->get_groups('admin');
        foreach ((array)$permissionGroupsArray as $group) {
            $permissionGroups[$group['groupID']] = $group['groupName'];
        }
        if ($this->CI->input->post('groupID') > 0 && !@in_array($this->CI->input->post('groupID'), $permissionGroups)) {
            $this->CI->core->set['groupID'] = $this->CI->input->post('groupID');
        }

        // set date
        $this->CI->core->set['dateCreated'] = date("Y-m-d H:i:s");

        // init null name
        $firstName = '';
        $lastName = '';

        // set name if only fullName is posted
        if ($this->CI->input->post('fullName') && (!$this->CI->input->post('firstName') && !$this->CI->input->post('lastName'))) {
            $fullName = $this->CI->input->post('fullName', true);
            $fullNameArray = @explode(' ', $fullName);
            $lastName = (sizeof($fullNameArray) > 0) ? ucfirst(trim(end($fullNameArray))) : '';
            $firstName = (sizeof($fullNameArray) > 0) ? ucfirst(trim($fullNameArray[0])) : $fullName;

            $this->CI->core->set['firstName'] = $firstName;
            $this->CI->core->set['lastName'] = $lastName;
        }

        // set first name
        if ($this->CI->input->post('firstName')) {
            $firstName = ucfirst($this->CI->input->post('firstName', true));
            $this->CI->core->set['firstName'] = $firstName;
        }

        // set last name
        if ($this->CI->input->post('lastName')) {
            $lastName = ucfirst($this->CI->input->post('lastName', true));
            $this->CI->core->set['lastName'] = $lastName;
        }

        // generate password
        if (!$this->CI->input->post('password')) {
            $pass = md5(substr(md5(time()), 0, 6));
            $password = password_hash($pass, PASSWORD_DEFAULT);

            //$password = md5(substr(md5(time()),0,6));
            $this->CI->core->set['password'] = $password;
        }

        // set manual activation
        if ($this->CI->site->config['activation']) {
            $this->CI->core->set['active'] = 0;
        }

        // set email on flash data
        $flashEmail = $this->CI->session->flashdata('email');

        // update table
        if ($this->CI->input->post('email') && ($this->CI->input->post('password') || $password)) {
            if ($this->CI->forge_core->update('users')) {
                $result = array(
                    'userID' => $this->CI->db->insert_id(),
                    'email' => $this->CI->input->post('email', true),
                    'password' => ($this->CI->input->post('password')) ? $this->CI->input->post('password', true) : $password,
                    'firstName' => $firstName,
                    'lastName' => $lastName
                );

                return $result;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function captcha_check()
    {
        // Then see if a captcha exists:
        $exp=time()-600;
        $sql = "SELECT COUNT(*) AS count FROM ha_captcha WHERE word = ? AND ip_address = ? AND captcha_time > ?";
        $binds = array($this->CI->input->post('captcha'), $this->CI->input->ip_address(), $exp);
        $query = $this->CI->db->query($sql, $binds);
        $row = $query->row();

        if ($row->count == 0) {
            $this->CI->form_validation->set_message('_captcha_check', 'The Captcha word was not correct.');
            return false;
        } else {
            return true;
        }
    }


    /* UTILITIES */

    // gets posted values
    public function get_post()
    {
        if (count($_POST)) {
            $post = array();
            foreach ($_POST as $key => $value) {
                $post[$key] = $this->CI->input->post($key);
            }

            return $post;
        } else {
            return false;
        }
    }

    // gets values from post and/or the row
    public function get_values($data = '', $id = '')
    {
        // init array
        $values = array();

        // populate by row if set
        if (@is_array($data)) {
            $row = $data;
            $values = $data;
        }

        // get data from database
        else {
            $table = $data;

            if ($id) {
                $query = $this->CI->db->get_where($table, $id);

                if ($query->num_rows()) {
                    $row = $query->row_array();
                    $values = $row;
                }
            }
        }

        // get post if there is any
        if ($post = $this->get_post()) {
            // check posted data is in fields
            foreach ($post as $field => $value) {
                // make sure the value is just a normal value and not an array
                if (!is_array($value)) {
                    if (isset($row) && isset($row[$field]) && $value == $row[$field]) {
                        unset($this->required[$field]);
                    } else {
                        // prep password
                        if ($field == 'password') {
                            if ($value != '') {
                                $values[$field] = md5($value);
                            }
                        }

                        // overwrite value with posted value
                        else {
                            $values[$field] = $value;
                        }
                    }

                    if (array_key_exists($field, $this->set)) {
                        unset($values[$field]);
                    }
                }
            }
        }

        return $values;
    }

    // is ajax?
    public function is_ajax()
    {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && ($_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'));
    }

    // check for errors
    public function check_errors()
    {
        // set rules for validation
        if (isset($this->required)) {
            $config = array();

            foreach ($this->required as $field => $name) {
                if (is_array($name)) {
                    $config[$field] = array('field' => $field, 'label' => $name['label'], 'rules' => $name['rules']);
                } else {
                    if ($field == 'email') {
                        $config[$field] = array('field' => $field, 'label' => $name, 'rules' => 'required|valid_email');
                    } elseif ($field == 'password') {
                        $config[$field] = array('field' => $field, 'label' => $name, 'rules' => 'required|matches[confirmPassword]');
                    } else {
                        $config[$field] = array('field' => $field, 'label' => $name, 'rules' => 'required');
                    }
                }
            }

            // set rules and fields for validation
            $this->CI->form_validation->set_rules($config);

            if (!$this->CI->form_validation->run() && isset($this->required) && count($this->required)) {
                return false;
            } else {
                return true;
            }
        } else {
            return true;
        }
    }

    // get all rows from a table
    public function viewall($table, $where = '', $order = '', $limit = '')
    {
        // get table fields
        $fields = $this->CI->db->list_fields($table);

        // set limit from uri if set
        $limit = (!$limit) ? $this->CI->site->config['paging'] : $limit;

        // get uri array for ordering
        $uriArray = $this->CI->uri->uri_to_assoc($this->uri_assoc_segment);

        // set order on order array
        if (count($uriArray)) {
            foreach ($uriArray as $key => $value) {
                if ($key) {
                    if ($key == 'orderasc') {
                        $this->CI->db->order_by($value, 'asc');
                    } elseif ($key == 'orderdesc') {
                        $this->CI->db->order_by($value, 'desc');
                    }
                }
            }
        }

        // order override
        elseif ($order && !is_array($order)) {
            $this->CI->db->order_by($order, 'asc');
        } elseif ($order && is_array($order)) {
            $this->CI->db->order_by($order[0], $order[1]);
        }

        if (!(isset($uriArray['orderasc']) || isset($uriArray['orderdesc'])) && in_array('dateCreated', $fields)) {
            $this->CI->db->order_by('dateCreated', 'desc');
        }

        // wheres
        if ($where) {
            $this->CI->db->where($where);
        }
        if (!$this->adminOverRide && $this->siteID) {
            $this->CI->db->where('siteID', $this->siteID);
        }
        if (in_array('deleted', $fields)) {
            $this->CI->db->where('deleted', 0);
        }

        // get and return results
        $query = $this->CI->db->get($table, $limit, $this->CI->pagination->offset);
        $output[$table] = $query->result_array();

        // do same thing again but get count
        if ($where) {
            $this->CI->db->where($where);
        }
        if (!$this->adminOverRide && $this->siteID) {
            $this->CI->db->where('siteID', $this->siteID);
        }
        if (in_array('deleted', $fields)) {
            $this->CI->db->where('deleted', 0);
        }
        $query_total = $this->CI->db->get($table);
        $totalRows = $query_total->num_rows();

        // set pagination config
        $this->set_paging($totalRows, $limit);

        return $output;
    }

    // update table
    public function update($table, $id = '')
    {
        if (count($_POST) || count($_FILES)) {

            // init array
            $row = array();

            // get fields of this table
            $fields = $this->CI->db->list_fields($table);

            // get data from database
            if ($id) {
                $query = $this->CI->db->get_where($table, $id);

                if ($query->num_rows()) {
                    $row = $query->row_array();
                }
            }

            // get values
            $values = $this->get_values($row);

            // check posted data is in fields
            foreach ($values as $field => $value) {
                if (!in_array($field, $fields)) {
                    unset($values[$field]);
                }
                if (array_key_exists($field, $this->set)) {
                    unset($values[$field]);
                }
            }

            //  if validate is unsuccessful show errors (return false) else insert and redirect
            if ($this->check_errors()) {
                // set siteID
                if (!$this->adminOverRide && $this->siteID) {
                    $this->set['siteID'] = SITEID;
                }

                // set fields
                if ($this->set && sizeof($this->set) > 0) {
                    $this->CI->db->set($this->set);
                    unset($this->set);
                }

                // add row
                if (!$row && !$id) {
                    $this->CI->db->insert($table, $values);
                }
                // edit row
                else {
                    if ($this->where && sizeof($this->where) > 0) {
                        $this->CI->db->where($this->where);
                    }
                    $this->CI->db->where($id);
                    $this->CI->db->update($table, $values);
                }

                unset($this->required);

                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    // set paging
    public function set_paging($totalRows, $limit = '')
    {
        // get default limit
        $limit = ($limit) ? $limit : $this->CI->site->config['paging'];

        // set pagination config
        $config['total_rows'] = $totalRows;
        $config['per_page'] = $limit;
        $config['full_tag_open'] = '<div class="pagination"><p>';
        $config['full_tag_close'] = '</p></div>';
        $config['num_links'] = 6;
        $this->CI->pagination->initialize($config);
    }

    // delete permanently
    public function delete($table, $id)
    {
        // delete item from db
        if (!$this->adminOverRide && $this->siteID) {
            $this->CI->db->where('siteID', $this->siteID);
        }
        if ($this->where && sizeof($this->where) > 0) {
            $this->CI->db->where($this->where);
        }
        $this->CI->db->delete($table, $id);

        if ($this->CI->db->affected_rows()) {
            return true;
        } else {
            return false;
        }
    }

    // delete from site but keep in database
    public function soft_delete($table, $id)
    {
        // soft delete item from db
        if (!$this->adminOverRide && $this->siteID) {
            $this->CI->db->where('siteID', $this->siteID);
        }
        $this->CI->db->set('deleted', 1);
        if ($this->where && sizeof($this->where) > 0) {
            $this->CI->db->where($this->where);
        }
        $this->CI->db->where($id);
        $this->CI->db->update($table);

        if ($this->CI->db->affected_rows()) {
            return true;
        } else {
            return false;
        }
    }

    // order rows
    public function order($table = '', $field = '')
    {
        // for each posted item, order it with new row id
        if ($table && $field) {
            foreach ($_POST[$table] as $key => $value) {
                if ($this->siteID) {
                    $this->CI->db->where('siteID', $this->siteID);
                }
                $this->CI->db->where($field.'ID', $value);
                $this->CI->db->update($table, array($field.'Order' => ($key + 1)));
            }
        } else {
            return false;
        }
    }

    // encode url
    public function encode($data)
    {
        return strtr(rtrim(base64_encode($data), '='), '+/', '-_');
    }

    // decode url
    public function decode($base64)
    {
        return base64_decode(strtr($base64, '-_', '+/'));
    }
}
