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
 File: mcp.threaded_comments.php
-----------------------------------------------------
 Purpose: Enables nested comments in ExpressionEngine
=====================================================
*/

if ( ! defined('BASEPATH'))
{
    exit('Invalid file request');
}

require_once PATH_THIRD.'threaded_comments/config.php';


class Threaded_comments_mcp {

    var $version = THREADED_COMMENTS_ADDON_VERSION;
    
    var $settings 		= array();
    
    function __construct() { 
        // Make a local reference to the ExpressionEngine super object 
        $this->EE =& get_instance(); 

    } 
    
    
    function index()
    {
        return TRUE;    
    }


}
/* END */
?>