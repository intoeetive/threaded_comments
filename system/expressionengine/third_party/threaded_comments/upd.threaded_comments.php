<?php

/*
=====================================================
 Threaded Comments
-----------------------------------------------------
 http://www.intoeetive.com/
-----------------------------------------------------
 Copyright (c) 2011-2013 Yuri Salimovskiy
=====================================================
 This software is intended for usage with
 ExpressionEngine CMS, version 2.0 or higher
=====================================================
 File: upd.threaded_comments.php
-----------------------------------------------------
 Purpose: Enables nested comments in ExpressionEngine
=====================================================
*/

if ( ! defined('BASEPATH'))
{
    exit('Invalid file request');
}

require_once PATH_THIRD.'threaded_comments/config.php';

class Threaded_comments_upd {

    var $version = THREADED_COMMENTS_ADDON_VERSION;
    
    function __construct() { 
        // Make a local reference to the ExpressionEngine super object 
        $this->EE =& get_instance(); 
    } 
    
    function install() { 
        
        $this->EE->load->dbforge(); 
        
        //----------------------------------------
		// EXP_MODULES
		// The settings column, Ellislab should have put this one in long ago.
		// No need for a seperate preferences table for each module.
		//----------------------------------------
		if ($this->EE->db->field_exists('settings', 'modules') == FALSE)
		{
			$this->EE->dbforge->add_column('modules', array('settings' => array('type' => 'TEXT') ) );
		}
        
        //----------------------------------------
		// Add fields to exp_comments table
		//---------------------------------------- 	 	
		if ($this->EE->db->field_exists('parent_id', 'comments') == FALSE)
		{
			$this->EE->dbforge->add_column('comments', array('parent_id' => array('type' => 'INT', 'default' => '0') ) );
		}
        if ($this->EE->db->field_exists('root_id', 'comments') == FALSE)
		{
			$this->EE->dbforge->add_column('comments', array('root_id' => array('type' => 'INT', 'default' => '0') ) );
		}
        if ($this->EE->db->field_exists('level', 'comments') == FALSE)
		{
			$this->EE->dbforge->add_column('comments', array('level' => array('type' => 'INT', 'default' => '0') ) );
		}
        if ($this->EE->db->field_exists('thread_id', 'comment_subscriptions') == FALSE)
		{
			$this->EE->dbforge->add_column('comment_subscriptions', array('thread_id' => array('type' => 'INT', 'default' => '0') ) );
		}
        
        $settings = array();
        $data = array( 'module_name' => 'Threaded_comments' , 'module_version' => $this->version, 'has_cp_backend' => 'n', 'settings'=> serialize($settings) ); 
        $this->EE->db->insert('modules', $data); 
        
        $data = array( 'class' => 'Threaded_comments' , 'method' => 'submit' ); 
        $this->EE->db->insert('actions', $data); 
        
        return TRUE; 
        
    } 
    
    function uninstall() { 
        
        $this->EE->load->dbforge(); 
        
        $this->EE->db->select('module_id'); 
        $query = $this->EE->db->get_where('modules', array('module_name' => 'Threaded_comments')); 
        
        $this->EE->db->where('module_id', $query->row('module_id')); 
        $this->EE->db->delete('module_member_groups'); 
        
        $this->EE->db->where('module_name', 'Threaded_comments'); 
        $this->EE->db->delete('modules'); 
        
        $this->EE->db->where('class', 'Threaded_comments'); 
        $this->EE->db->delete('actions'); 
        
        return TRUE; 
    } 
    
    function update($current='') { 
		if ($current < 3.0) { 
            // Do your 3.0 v. update queries 
        } 
        return TRUE; 
    } 
	

}
/* END */
?>