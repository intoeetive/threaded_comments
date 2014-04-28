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
 File: mod.threaded_comments.php
-----------------------------------------------------
 Purpose: Enables nested comments in ExpressionEngine
=====================================================
*/


if ( ! defined('BASEPATH'))
{
    exit('Invalid file request');
}


class Threaded_comments {

    var $return_data	= ''; 						// Bah!
    
    var $settings 		= array();
    
    var $comment 		= array(); //comments to display; array of objects
    var $thread_start_ids		= array(); //
    var $thread_end_ids		= array(); //
    var $thread_open_ids		= array(); //
    var $thread_close_ids		= array(); //
    
    var $prev_level = 0; //
    var $displayed_prev = 0; //
    var $displayed_prev_root = 0; //
    
    

    /** ----------------------------------------
    /**  Constructor
    /** ----------------------------------------*/

    function __construct()
    {        
    	$this->EE =& get_instance(); 

        $this->EE->lang->loadfile('threaded_comments');
        $this->EE->lang->loadfile('comment');    
		
		$this->EE->load->helper('string');    
    }
    /* END */
    

    /** ----------------------------------------
    /**  Submit comment
    /** ----------------------------------------*/
    function submit()
    {

        $DB = $this->EE->db;
        $PREFS = $this->EE->config;
        $FNS = $this->EE->functions;
        $LANG = $this->EE->lang;
        $SESS = $this->EE->session;
        $OUT = $this->EE->output;
        $IN = $this->EE->input;
        $EXT = $this->EE->extensions;
        $LOC = $this->EE->localize;
        $STAT = $this->EE->stats;
        
        $exit = FALSE;    

		// Basic input check
        // entry_id provided?
        if ( ! is_numeric($_POST['entry_id']))
        {
        	$exit = TRUE;
        }
        //comment provided?
        if ($_POST['comment'] == '')
        {
            
            return $OUT->show_user_error('submission', array($LANG->line('cmt_missing_comment')));
        }
        
        $sql = "SELECT exp_channel_titles.entry_id, 
                       exp_channel_titles.title, 
                       exp_channel_titles.url_title,
                       exp_channel_titles.channel_id,
                       exp_channel_titles.author_id,
                       exp_channel_titles.comment_total,
                       exp_channel_titles.allow_comments,
                       exp_channel_titles.entry_date,
                       exp_channel_titles.comment_expiration_date,
                       exp_channels.channel_name,
                       exp_channels.channel_title,
                       exp_channels.comment_system_enabled,
                       exp_channels.comment_max_chars,
                       exp_channels.comment_use_captcha,
                       exp_channels.comment_timelock,
                       exp_channels.comment_require_membership,
                       exp_channels.comment_moderate,
                       exp_channels.comment_require_email,
                       exp_channels.comment_notify,
                       exp_channels.comment_notify_authors,
                       exp_channels.comment_notify_emails,
                       exp_channels.comment_expiration
                FROM   exp_channel_titles, exp_channels
                WHERE  exp_channel_titles.channel_id = exp_channels.channel_id
                AND    exp_channel_titles.entry_id = '".$DB->escape_str($_POST['entry_id'])."'
				AND    exp_channel_titles.status != 'closed' ";
        
        // -------------------------------------------
		// 'insert_comment_preferences_sql' hook.
		//  - Rewrite or add to the comment preference sql query
		//  - Could be handy for comment/weblog restrictions
		//
			if ($EXT->active_hook('insert_comment_preferences_sql') === TRUE)
			{
				$sql = $EXT->call('insert_comment_preferences_sql', $sql);
				if ($EXT->end_script === TRUE) return $edata;
			}
		//
        // -------------------------------------------
        
        $channel_entry = $DB->query($sql);
                
        //entry_id exists?
        if ($channel_entry->num_rows()==0)
        {
            $exit = TRUE;
        }
        
        if ($exit === TRUE)
        {
            if ( $_POST['RET'] != '')
        	{
        		$FNS->redirect($_POST['RET']);
        	}
            
            return false;
        }
        
        //user banned?        
        if ($SESS->userdata['is_banned'] == TRUE)
        {            
            return $OUT->show_user_error('general', array($LANG->line('not_authorized')));
        }
                
        // IP and User Agent required?
                
        if ($PREFS->item('require_ip_for_posting') == 'y')
        {
        	if ($IN->ip_address() == '0.0.0.0' || $SESS->userdata['user_agent'] == "")
        	{            
            	return $OUT->show_user_error('general', array($LANG->line('not_authorized')));
        	}        	
        } 
        
		$SESS->nation_ban_check();			
        
        //commenthing allowed for user?
        if ($SESS->userdata['can_post_comments'] == 'n')
        { 
            return $OUT->show_user_error('general', array($LANG->line('cmt_no_authorized_for_comments')));
        }
        
        //blacklisted?
        if ($this->EE->blacklist->blacklisted == 'y' && $this->EE->blacklist->whitelisted == 'n')
        {
        	return $OUT->show_user_error('general', array($LANG->line('not_authorized')));
        }  
        
        //membership required?
        if ($channel_entry->row('comment_require_membership') == 'y')
        {        
            if ($SESS->userdata('member_id') == 0)
            {                
                return $OUT->show_user_error('submission', array($LANG->line('cmt_must_be_member')));
            }

            if ($SESS->userdata['group_id'] == 4)
            {                
                return $OUT->show_user_error('general', array($LANG->line('cmt_account_not_active')));
            }         
        }
        
        
        // -------------------------------------------
        // 'insert_comment_start' hook.
        //  - Allows complete rewrite of comment submission routine.
        //  - Or could be used to modify the POST data before processing
        //
        	$edata = $EXT->call('insert_comment_start');
        	if ($EXT->end_script === TRUE) return;
        //
        // -------------------------------------------
        

        //comments allowed?
        if ($channel_entry->row('allow_comments') == 'n' || $channel_entry->row('comment_system_enabled') == 'n')
        {            
            return $OUT->show_user_error('submission', $LANG->line('cmt_comments_not_allowed'));
        }
        
        //commenting expired?
        if (($channel_entry->row('comment_expiration_date') > 0) && ($LOC->now > $channel_entry->row('comment_expiration_date')))
        {					
            return $OUT->show_user_error('submission', $LANG->line('cmt_commenting_has_expired'));
		}        

        // timelock?
        if ($channel_entry->row('comment_timelock') > 0)
        {
			if ($SESS->userdata['group_id'] != 1)        
			{
				$time = $LOC->now - $channel_entry->row('comment_timelock');
			
				$q = $DB->query("SELECT comment_id FROM exp_comments WHERE comment_date > '$time' AND ip_address = '$IN->ip_address()' LIMIT 1");
			
				if ($q->num_rows() > 0)
				{
					return $OUT->show_user_error('submission', str_replace("%s", $channel_entry->row('comment_timelock'), $LANG->line('cmt_comments_timelock')));
				}
			}
        }
        
        //duplicate data?
        if ($PREFS->item('deny_duplicate_data') == 'y')
        {
			if ($SESS->userdata['group_id'] != 1)        
			{			
				$q = $DB->query("SELECT comment_id FROM exp_comments WHERE comment = '".$DB->escape_str($_POST['comment'])."' LIMIT 1");
			
				if ($q->num_rows() > 0)
				{					
					return $OUT->show_user_error('submission', array($LANG->line('cmt_duplicate_comment_warning')));
				}
			}
        }
        
        if ($PREFS->item('secure_forms') == 'y')
        {
            if ($this->EE->security->secure_forms_check($this->EE->input->post('XID')) == FALSE)
			{
				return $OUT->show_user_error('submission', array($LANG->line('security_hash_error')));
			}		
        }

        //received data
        if ($SESS->userdata['member_id']==0)
        {
            $name = $this->EE->input->post('name', true);
            $email =  $this->EE->input->post('email', true);
            $url =  (isset($_POST['url']))?$this->EE->input->post('url', true):'';
            $location =  (isset($_POST['location']))?$this->EE->input->post('location', true):'';
        }
        else
        {
            $name = ($SESS->userdata['screen_name']!='') ? $SESS->userdata['screen_name'] : $SESS->userdata['username']!='';
            $email = $SESS->userdata['email'];
            $url = $SESS->userdata['url'];
            $location = $SESS->userdata['location'];
        }
        
        $comment = $this->EE->security->xss_clean($_POST['comment']);
        
        if ($_POST['parent_id']!='' && $_POST['parent_id']!=0)
        {
            $parent_id = $this->EE->security->xss_clean($_POST['parent_id']);
            $root_id = $parent_id;
            $level = 0;
            do {
                $level++;
                $q = $DB->query("SELECT parent_id FROM exp_comments WHERE comment_id='".$DB->escape_str($root_id)."'");
                if ($q->row('parent_id')==0) break;
                $root_id = $q->row('parent_id');
            } while ($root_id!=0);
            
        }
        else
        {
            $parent_id = 0;
            $root_id = 0;
            $level = 0;
        }
        
        //missing data?
        $this->EE->load->library('email');
        
        $errors = array();
        if ($name == '')
        {
            $errors[] = $LANG->line('cmt_missing_name');
        }

		if ($SESS->ban_check('screen_name', $name))
		{
            $errors[] = $LANG->line('cmt_name_not_allowed');
		}
    
        if ($channel_entry->row('comment_require_email') == 'y')
        {
            if ($email == '')
            {
                $errors[] = $LANG->line('cmt_missing_email');
            }
            elseif ( ! $this->EE->email->valid_email($email))
            {
                $errors[] = $LANG->line('cmt_invalid_email');
            }
        }
		
		if ($email != '')
		{
			if ($SESS->ban_check('email', $email))
			{
				$errors[] = $LANG->line('cmt_banned_email');
			}
		}	
        
        if ($channel_entry->row('comment_max_chars') > 0)
        {        
            if (strlen($comment) > $channel_entry->row('comment_max_chars'))
            {
                $str = str_replace("%n", strlen($comment), $LANG->line('cmt_too_large'));
                
                $str = str_replace("%x", $channel_entry->row('comment_max_chars'), $str);
            
                $errors[] = $str;
            }
        }
  
        if (count($errors) > 0)
        {
           return $OUT->show_user_error('submission', $errors);
        }                      
            
		//CAPTCHA, anyone?
		if ($channel_entry->row('comment_use_captcha') == 'y')
		{	
			if ($PREFS->item('captcha_require_members') == 'y'  ||  ($PREFS->item('captcha_require_members') == 'n' AND $SESS->userdata('member_id') == 0))
			{
				if ( $_POST['captcha'] == '')
				{
					return $OUT->show_user_error('submission', array($LANG->line('captcha_required')));
				}
				else
				{
					$q = $DB->query("SELECT COUNT(*) AS count FROM exp_captcha WHERE word='".$DB->escape_str($_POST['captcha'])."' AND ip_address = '".$IN->ip_address()."' AND date > UNIX_TIMESTAMP()-7200");
				
					if ($q->row('count') == 0)
					{
						return $OUT->show_user_error('submission', array($LANG->line('captcha_incorrect')));
					}
				
					$DB->query("DELETE FROM exp_captcha WHERE (word='".$DB->escape_str($_POST['captcha'])."' AND ip_address = '".$IN->ip_address()."') OR date < UNIX_TIMESTAMP()-7200");
				}
			}
		}


        
        $comment_total = $channel_entry->row('comment_total') + 1;
        $notify = $IN->post('notify_me') ? 'y' : 'n';
        $notify_thread = $IN->post('notify_thread') ? 'y' : 'n';
        $moderate		= ($SESS->userdata['group_id'] == 1 || $SESS->userdata['exclude_from_moderation'] == 'y') ? 'n' : $channel_entry->row('comment_moderate');
        
        $data = array(
                        'channel_id'     => $channel_entry->row('channel_id'),
                        'entry_id'      => $_POST['entry_id'],
                        'author_id'     => $SESS->userdata('member_id'),
                        'name'          => $name,
                        'email'         => $email,
                        'url'           => $url,
                        'location'      => $location,
                        'comment'       => $comment,
                        'comment_date'  => $LOC->now,
                        'ip_address'    => $IN->ip_address(),
                        //'notify'        => $notify,
                        'status'		=> ($moderate == 'y') ? 'p' : 'o',
                        'site_id'		=> $PREFS->item('site_id'),
                        'parent_id'     => $parent_id,
                        'root_id'       => $root_id,
                        'level'         => $level
                        //'notify_thread' => $notify_thread
                     );
                     
        // -------------------------------------------
		// 'insert_comment_insert_array' hook.
		//  - Modify any of the soon to be inserted values
		//
			if ($EXT->active_hook('insert_comment_insert_array') === TRUE)
			{
				$data = $EXT->call('insert_comment_insert_array', $data);
				if ($EXT->end_script === TRUE) return $edata;
			}
		//
        // -------------------------------------------

      
        $DB->query($DB->insert_string('exp_comments', $data));
        $comment_id = $DB->insert_id();
      
        if ($PREFS->item('secure_forms') == 'y')
        {
            //$DB->query("DELETE FROM exp_security_hashes WHERE (hash='".$DB->escape_str($_POST['XID'])."' AND ip_address = '".$IN->ip_address()."') OR date < UNIX_TIMESTAMP()-7200");
        }

        $this->EE->load->library('typography');
        $this->EE->typography->initialize();
 		$this->EE->typography->smileys = FALSE;
		$comment = $this->EE->typography->parse_type( $comment, 
				   array(
							'text_format'   => 'none',
							'html_format'   => 'none',
							'auto_links'    => 'n',
							'allow_img_url' => 'n'
						)
				);
    
         //admin notifications    
        $notify_address = ($channel_entry->row('comment_notify') == 'y' AND $channel_entry->row('comment_notify_emails') != '') ? $channel_entry->row('comment_notify_emails') : '';    
        $this->EE->load->helper('string');
        $notify_address = reduce_multiples($notify_address, ",", TRUE); 
        $recipients = explode(",",$notify_address);     
        //if comments are moderated, include admin email
        if ($moderate == 'y') $recipients[] = $PREFS->item('webmaster_email');
        $recipients = array_unique($recipients);  
        $key = array_search($email, $recipients);
        if ($key!==FALSE)
        {
            unset($recipients[$key]);
        }
       
        $sent = array();
        $this->EE->load->helper('text'); 
        if (count($recipients)>0)
        {         
			$swap = array(
							'name'				=> $name,
							'name_of_commenter'	=> $name,
							'email'				=> $email,
							'url'				=> $url,
							'location'			=> $location,
							'channel_name'		=> $channel_entry->row('channel_title'),
							'entry_title'		=> $channel_entry->row('title'),
                            'entry_id'	     	=> $channel_entry->row('entry_id'),
                            'url_title'         => $channel_entry->row('url_title'),
							'comment_id'		=> $comment_id,
							'comment'			=> $comment,
							'comment_url'		=> $FNS->create_url(preg_replace("#S=.+?/#", "", $_POST['URI'])),
							'delete_link'		=> $PREFS->item('cp_url').'?S=0&C=edit'.'&M=delete_comment_confirm'.'&channel_id='.$channel_entry->row('channel_id').'&entry_id='.$_POST['entry_id'].'&comment_id='.$comment_id,
                            'approve_link'		=> $PREFS->item('cp_url').'?S=0&C=edit'.'&M=change_comment_status'.'&channel_id='.$channel_entry->row('channel_id').'&entry_id='.$_POST['entry_id'].'&comment_id='.$comment_id.'&status=o',
                            'close_link'		=> $PREFS->item('cp_url').'?S=0&C=edit'.'&M=change_comment_status'.'&channel_id='.$channel_entry->row('channel_id').'&entry_id='.$_POST['entry_id'].'&comment_id='.$comment_id.'&status=c'
						 );
			
			$template = $FNS->fetch_email_template('admin_notify_comment');
			$email_tit = $FNS->var_swap($template['title'], $swap);
			$email_msg = $FNS->var_swap($template['data'], $swap);

			foreach ($recipients as $recipient)
			{
				if ($recipient!='')
                {
                    $this->EE->email->initialize();
    				$this->EE->email->wordwrap = false;
    				$this->EE->email->from($PREFS->item('webmaster_email'), $PREFS->item('webmaster_name'));	
    				$this->EE->email->to($recipient); 
    				$this->EE->email->reply_to(($email == '') ? $PREFS->item('webmaster_email') : $email);
    				$this->EE->email->subject($email_tit);	
    				$this->EE->email->message(entities_to_ascii($email_msg));		
    				$this->EE->email->Send();
    				
    				$sent[] = $recipient;
                }
			}
		}
        
        //get all parents
        $all_parents = array();
        $current_parent = $comment_id;
        while ($current_parent!=0)
        {
            $this->EE->db->select('parent_id')
                    ->from('comments')
                    ->where('comment_id', $current_parent);
            $all_parents_q = $this->EE->db->get();
            $current_parent = $all_parents_q->row('parent_id');
            if ($current_parent!=0) $all_parents[] = $current_parent;
        } 
        $all_parents_list = implode(",",$all_parents);
        
        if ($moderate == 'n')
        {       

			$recipients = array();
            
            $DB->query("UPDATE exp_channel_titles SET comment_total = '$comment_total', recent_comment_date = '".$LOC->now."' WHERE entry_id = '".$DB->escape_str($_POST['entry_id'])."'");
			
			if ($SESS->userdata('member_id') != 0)
			{
				$q = $DB->query("SELECT total_comments FROM exp_members WHERE member_id = '".$SESS->userdata('member_id')."'");
				$DB->query("UPDATE exp_members SET total_comments = '".($q->row('total_comments') + 1)."', last_comment_date = '".$LOC->now."' WHERE member_id = '".$SESS->userdata('member_id')."'");                
			}
			
			$STAT->update_comment_stats($channel_entry->row('channel_id'), $LOC->now);

			//get ready for notifications!
            $qstr = "SELECT subscription_id, member_id, email, notification_sent, hash FROM exp_comment_subscriptions WHERE entry_id = '".$DB->escape_str($_POST['entry_id'])."' AND (thread_id = '0'";
            if (!empty($all_parents))
            {
                $qstr .= " OR thread_id IN (".$all_parents_list.")";
            }
            $qstr .= ") ORDER BY thread_id ASC";
			$query = $DB->query($qstr);
            
            //entry-level notifications go first
			$recipients = array();		
			if ($query->num_rows > 0)
			{
				foreach ($query->result_array() as $row)
				{
                    //check whether email has changed 
                    if ($row['member_id'] != 0)
					{
						//get comment related
                        $com_q = $DB->query("SELECT comment_id, name, email FROM exp_comments WHERE author_id = '".$DB->escape_str($row['member_id'])."' AND entry_id='".$DB->escape_str($_POST['entry_id'])."' LIMIT 1");
                        $q = $DB->query("SELECT email, screen_name, smart_notifications FROM exp_members WHERE member_id = '".$DB->escape_str($row['member_id'])."' LIMIT 1");
						if ($q->num_rows() > 0 && ($q->row('smart_notifications')=='n' || $row['notification_sent']=='n'))
						{
                            $recipients[] = array($q->row('email'), $com_q->row('comment_id'), $q->row('screen_name'), $row['member_id'], $row['subscription_id'], $row['hash']);
						}
					}
					elseif ($row['email'] != "" && $row['notification_sent']=='n')
					{
						//get comment related
                        $com_q = $DB->query("SELECT comment_id, name, email FROM exp_comments WHERE email = '".$DB->escape_str($row['email'])."' AND entry_id='".$DB->escape_str($_POST['entry_id'])."' LIMIT 1");
                        $recipients[] = array($row['email'], $com_q->row('comment_id'), $com_q->row('name'), $row['member_id'], $row['subscription_id'], $row['hash']);   
					}      
                    //set the 'sent' flag
                    //only for registred members                                        
                    $DB->query("UPDATE exp_comment_subscriptions SET notification_sent='y' WHERE subscription_id='".$row['subscription_id']."' AND member_id!=0");      
				}
			}
            //and to author...
            if ($channel_entry->row('comment_notify_authors') == 'y')
    		{
    			$q = $DB->query("SELECT member_id, screen_name, email FROM exp_members WHERE member_id = '".$DB->escape_str($channel_entry->row('author_id'))."'");
                $recipients[] = array($q->row('email'), $comment_id, $q->row('screen_name'), $channel_entry->row('author_id'), '0', '');  
    		}
            
            //send the notification

			if (count($recipients) > 0)
			{    
	
				$action_id  = $FNS->fetch_action_id('Comment_mcp', 'delete_comment_notification');
            
				$swap = array(
								'name_of_commenter'	=> $name,
								'channel_name'		=> $channel_entry->row('channel_title'),
								'entry_title'		=> $channel_entry->row('title'),
								'url_title'  		=> $channel_entry->row('url_title'), 
								'site_name'			=> stripslashes($PREFS->item('site_name')),
								'site_url'			=> $PREFS->item('site_url'),
								'comment_url'		=> $FNS->create_url(preg_replace("#S=.+?/#", "", $_POST['URI'])),
								'comment_id'		=> $comment_id,
								'comment'			=> $comment
							 );
				
				$template = $FNS->fetch_email_template('comment_notification');
				
				
				$sent = array();

				foreach ($recipients as $recipient)
				{
					if (!empty($recipient))
                    {
                        $cur_email = $recipient[0];
                        $email_tit = $FNS->var_swap($template['title'], $swap);
						$email_msg = $FNS->var_swap($template['data'], $swap);
    					
    					if ($cur_email != $email && ! in_array($cur_email, $sent))
    					{
    						$email_tit	 = str_replace('{name_of_recipient}', $recipient[2], $email_tit);
    						$email_msg = str_replace('{name_of_recipient}', $recipient[2], $email_msg);
    					
    						$email_tit	 = str_replace('{notification_removal_url}', $FNS->fetch_site_index(0, 0).QUERY_MARKER.'ACT='.$action_id.'&id='.$recipient[4].'&hash='.$recipient[5], $email_tit);
    						$email_msg = str_replace('{notification_removal_url}', $FNS->fetch_site_index(0, 0).QUERY_MARKER.'ACT='.$action_id.'&id='.$recipient[4].'&hash='.$recipient[5], $email_msg);
                            
    						$this->EE->email->initialize();
            				$this->EE->email->wordwrap = false;
            				$this->EE->email->from($PREFS->item('webmaster_email'), $PREFS->item('webmaster_name'));	
            				$this->EE->email->to($cur_email); 
            				//$this->EE->email->reply_to(($email == '') ? $PREFS->item('webmaster_email') : $email);
            				$this->EE->email->subject($email_tit);	
            				$this->EE->email->message(entities_to_ascii($email_msg));		
            				$this->EE->email->Send();
            				
            				$sent[] = $recipient[0];
    
    					}
                    }
				}            
			}
			
			// clear the cache
			$FNS->clear_caching('all', $FNS->fetch_site_index().$_POST['URI']);
			
			// clear out the entry_id version if the url_title is in the URI, and vice versa
			if (preg_match("#\/".preg_quote($channel_entry->row('url_title'))."\/#", $_POST['URI'], $matches))
			{
				$FNS->clear_caching('all', $FNS->fetch_site_index().preg_replace("#".preg_quote($matches['0'])."#", "/{$data['entry_id']}/", $_POST['URI']));
			}
			else
			{
				$FNS->clear_caching('all', $FNS->fetch_site_index().preg_replace("#{$data['entry_id']}#", $channel_entry->row('url_title'), $_POST['URI']));
			}
            
        }


        //create subscription record
        //too pity we can't use library here
        if ($notify == 'y')
        {
            $qstr = "SELECT subscription_id, thread_id, email FROM exp_comment_subscriptions WHERE entry_id='".$_POST['entry_id']."' AND thread_id=0 AND (email='".$email."'";
            if ($SESS->userdata('member_id')!=0)
            {
                $qstr .= " OR (member_id='".$SESS->userdata('member_id')."')";
            }
            $qstr .= " ) ";
            $subscr_q = $DB->query($qstr);
            if ($subscr_q->num_rows()==0)
            {
                $DB->query("INSERT INTO exp_comment_subscriptions SET entry_id='".$_POST['entry_id']."', member_id='".$SESS->userdata('member_id')."', email='".$email."', thread_id='0', subscription_date='".$LOC->now."', notification_sent='n', hash='".$SESS->userdata('member_id').$this->EE->functions->random('alnum', 8)."'");
            }
            else
            {
                foreach ($subscr_q->result_array() as $row)
                {
                    $qstr = "UPDATE exp_comment_subscriptions SET notification_sent='n' ";
                    //update email addr if neded
                    if ($email!='' && $email!=$row['email'])
                    {
                        $qstr .=  ", email='".$email."' ";
                    }
                    //clear the notification sent flag
                    $qstr .=  " WHERE subscription_id='".$row['subscription_id']."'";
                    $DB->query($qstr);
                }
            }
        }
        
        if ($notify_thread == 'y')
        {
            $thread_id = ($parent_id!=0)?$root_id:$comment_id;
            $all_parents[] = 0;
            $all_parents_list = implode(",",$all_parents);
            $qstr = "SELECT subscription_id, thread_id, email FROM exp_comment_subscriptions WHERE entry_id='".$_POST['entry_id']."' AND thread_id IN (".$all_parents_list.") AND (email='".$email."' ";
            if ($SESS->userdata('member_id')!=0)
            {
                $qstr .= " OR (member_id='".$SESS->userdata('member_id')."')";
            }
            $qstr .= " ) ";
            
            $subscr_q = $DB->query($qstr);
            if ($subscr_q->num_rows()==0)
            {
                $DB->query("INSERT INTO exp_comment_subscriptions SET entry_id='".$_POST['entry_id']."', member_id='".$SESS->userdata('member_id')."', email='".$email."', thread_id='".$DB->escape_str($thread_id)."', subscription_date='".$LOC->now."', notification_sent='n', hash='".$SESS->userdata('member_id').$this->EE->functions->random('alnum', 8)."'");
            }
            else
            {
                foreach ($subscr_q->result_array() as $row)
                {
                    $qstr = "UPDATE exp_comment_subscriptions SET notification_sent='n' ";
                    //update email addr if neded
                    if ($email!='' && $email!=$row['email'])
                    {
                        $qstr .=  ", email='".$email."' ";
                    }
                    //clear the notification sent flag
                    $qstr .=  " WHERE subscription_id='".$row['subscription_id']."'";
                    $DB->query($qstr);
                }
            }
        }


		//cookies
		if ($notify == 'y')
		{        
			$FNS->set_cookie('notify_me', 'yes', 60*60*24*365);
		}
		else
		{
			$FNS->set_cookie('notify_me', 'no', 60*60*24*365);
		}
        
        if ($notify_thread == 'y')
		{        
            $FNS->set_cookie('notify_thread_'.$root_id, 'yes', 60*60*24*365);
		}
		else
		{
            $FNS->set_cookie('notify_thread_'.$root_id, 'no', 60*60*24*365);
		}

        if ($IN->post('save_info'))
        {        
            $FNS->set_cookie('save_info',   'yes',              60*60*24*365);
            $FNS->set_cookie('my_name',     $name,     60*60*24*365);
            $FNS->set_cookie('my_email',    $email,    60*60*24*365);
            $FNS->set_cookie('my_url',      $url,      60*60*24*365);
            $FNS->set_cookie('my_location', $location, 60*60*24*365);
        }
        else
        {
			$FNS->set_cookie('save_info',   'no', 60*60*24*365);
			$FNS->set_cookie('my_name',     '');
			$FNS->set_cookie('my_email',    '');
			$FNS->set_cookie('my_url',      '');
			$FNS->set_cookie('my_location', '');
        }
        
        // -------------------------------------------
        // 'insert_comment_end' hook.
        //  - More emails, more processing, different redirect
        //  - $comment_id added 1.6.1
		//
        	$edata = $EXT->call('insert_comment_end', $data, $moderate, $comment_id);
        	if ($EXT->end_script === TRUE) return;
        //
        // -------------------------------------------

        //success! do the redirect
        
        if ($moderate == 'y')
        {
			$data = array(	'title' 	=> $LANG->line('cmt_comment_accepted'),
							'heading'	=> $LANG->line('thank_you'),
							'content'	=> $LANG->line('cmt_will_be_reviewed'),
							'redirect'	=> $_POST['RET'],							
							'link'		=> array($_POST['RET'], $LANG->line('cmt_return_to_comments')),
							'rate'		=> 3
						 );
					
			return $OUT->show_message($data);
		}
		else
		{
        	$FNS->redirect($_POST['RET']);
    	}
    }    
    /* END */
    
    
    
    
    
    /** ----------------------------------------
    /**  Build comment form
    /** ----------------------------------------*/
	function form()
	{
        
        $TMPL = $this->EE->TMPL;
        $DB = $this->EE->db;
        $PREFS = $this->EE->config;
        $FNS = $this->EE->functions;
        $LANG = $this->EE->lang;
        $SESS = $this->EE->session;
        $OUT = $this->EE->output;
        $IN = $this->EE->input;
        $EXT = $this->EE->extensions;
        $LOC = $this->EE->localize;
        $STAT = $this->EE->stats;
        
        $sql = "SELECT t.entry_id, t.entry_date, t.comment_expiration_date, t.allow_comments, w.comment_system_enabled, w.comment_use_captcha, w.comment_expiration FROM exp_channel_titles AS t LEFT JOIN exp_channels AS w ON t.channel_id=w.channel_id WHERE t.site_id='".$PREFS->item('site_id')."' AND t.status != 'closed'";
        $gotpreferences = FALSE;
        
		//what is our entry_id?
        if ($TMPL->fetch_param('entry_id')!='')
		{	
			$entry_id = $TMPL->fetch_param('entry_id');
		}
        else if ($TMPL->fetch_param('url_title')!='')
        {
            $sql .= " AND t.url_title='".$DB->escape_str($TMPL->fetch_param('url_title'))."'";
            if ($TMPL->fetch_param('channel')!='')
            {
                $sql .= " AND w.channel_name='".$DB->escape_str($TMPL->fetch_param('channel'))."'";
            }
            $q = $DB->query($sql);
            if ($q->num_rows() == 1)
            {
                $entry_id = $q->row('entry_id');
                $gotpreferences = TRUE;
            }
        }
        //no luck? try to guess it from URL
        if (!isset($entry_id))
        {
            $qstr = $this->EE->uri->query_string;
            if (preg_match("#/P\d+#", $qstr, $match))
    		{			
    			$qstr = reduce_double_slashes(str_replace($match['0'], '', $qstr));
    		}
            $guess = trim($qstr); 		
			$guess = preg_replace("#/.+#", "", $guess);
            if (is_numeric($guess))
            {
                $entry_id = $guess;
            }
            else
            {
                $sql .= " AND t.url_title='".$DB->escape_str($guess)."'";
                if ($TMPL->fetch_param('channel')!='')
                {
                    $sql .= " AND w.channel_name='".$DB->escape_str($TMPL->fetch_param('channel'))."'";
                }
                $q = $DB->query($sql);
                if ($q->num_rows() == 1)
                {
                    $entry_id = $q->row('entry_id');
                    $gotpreferences = TRUE;
                }
            }
        }

		//still no luck? show nothing
        if (!isset($entry_id))
        {
            return $TMPL->no_results();
        }
		
        if ($gotpreferences===FALSE)
        {
            $sql .= " AND t.entry_id='".$DB->escape_str($entry_id)."'";
            $q = $DB->query($sql);
            if ($q->num_rows() == 0)
            {
                return $TMPL->no_results();
            }
            $entry_id = $q->row('entry_id');
        }
        
        $error = '';
        $cond['error'] = FALSE; 
        
        //commenting expired?
        if ($q->row('allow_comments') == 'n' || $q->row('comment_system_enabled') == 'n')
        {
			$error = $LANG->line('cmt_commenting_has_expired');
        }

		if ($PREFS->item('comment_moderation_override') !== 'y')
		{
			if (($q->row('comment_expiration_date')  > 0) && ($LOC->now > $q->row('comment_expiration_date') ))
			{
				$error = $LANG->line('cmt_commenting_has_expired');
			}
		}
        
        //mark comments as read
		if ($this->EE->session->userdata('smart_notifications') == 'y')
		{
			$this->EE->load->library('subscription');
			$this->EE->subscription->init('comment', array('entry_id' => $q->row('entry_id')), TRUE);
			$this->EE->subscription->mark_as_read();
		}
        
        $tagdata = $TMPL->tagdata;
        if ($error!='')
        {
            $tagdata = $TMPL->swap_var_single('error_text', $error, $tagdata);
            $cond['error'] = TRUE; 
            $tagdata = $FNS->prep_conditionals($tagdata, $cond); 
            return $tagdata;
        }
        

		// -------------------------------------------
		// 'comment_form_tagdata' hook.
		//  - Modify, add, etc. something to the comment form
		//
			if ($this->EE->extensions->active_hook('comment_form_tagdata') === TRUE)
			{
				$tagdata = $this->EE->extensions->call('comment_form_tagdata', $tagdata);
				if ($this->EE->extensions->end_script === TRUE) return;
			}
		//
		// -------------------------------------------
		
        //basic conditionals

		if ($q->row('comment_use_captcha')  == 'n')
		{
			$cond['captcha'] = FALSE;
		}
		elseif ($q->row('comment_use_captcha')  == 'y')
		{
			$cond['captcha'] =  ($PREFS->item('captcha_require_members') == 'y' || $SESS->userdata('member_id') == 0) ? TRUE : FALSE;
		}

		$tagdata = $FNS->prep_conditionals($tagdata, $cond);



		$this->EE->load->helper('form');

		foreach ($TMPL->var_single as $key => $val)
		{

			if ($key == 'name')
			{
                $name = ($IN->cookie('my_name')!='')?$IN->cookie('my_name'):(($SESS->userdata['screen_name'] != '') ? $SESS->userdata['screen_name'] : $SESS->userdata['username']);
				$tagdata = $TMPL->swap_var_single($key, form_prep($name), $tagdata);
			}

			if ($key == 'email')
			{
				$email = ($IN->cookie('my_email')!='') ? $IN->cookie('my_email') : $SESS->userdata['email'];
				$tagdata = $TMPL->swap_var_single($key, form_prep($email), $tagdata);
			}

			if ($key == 'url')
			{
				$url = ($IN->cookie('my_url')!='') ? $IN->cookie('my_url') : $SESS->userdata['url'];
				$tagdata = $TMPL->swap_var_single($key, form_prep($url), $tagdata);
			}

			if ($key == 'location')
			{
				$location = ($IN->cookie('my_location')!='') ? $IN->cookie('my_location') : $SESS->userdata['location'];
				$tagdata = $TMPL->swap_var_single($key, form_prep($location), $tagdata);
			}

			if ($key == 'comment')
			{
				$comment = '';
				$tagdata = $TMPL->swap_var_single($key, $comment, $tagdata);
			}

			if ($key == 'save_info')
			{
				$tagdata = $TMPL->swap_var_single($key, ($IN->cookie('save_info') == 'yes') ? " checked=\"checked\"" : '', $tagdata);
			}

			if ($key == 'notify_me')
			{
				$checked = ($IN->cookie('notify_me')!='') ? $IN->cookie('notify_me') : ((isset($SESS->userdata['notify_by_default']) && $SESS->userdata['notify_by_default']=='y')?'yes':'no');
				$tagdata = $this->EE->TMPL->swap_var_single($key, ($checked == 'yes') ? " checked=\"checked\"" : '', $tagdata);
			}
            
            if ($key == 'notify_thread')
			{
                //use checked value for notify_me
				$tagdata = $this->EE->TMPL->swap_var_single($key, ($checked == 'yes') ? " checked=\"checked\"" : '', $tagdata);
			}
		}

		//go on with the form
		
		$RET = ($TMPL->fetch_param('return') != "") ? ((strpos($TMPL->fetch_param('return'), 'http')===0)?$TMPL->fetch_param('return'):$FNS->create_url($TMPL->fetch_param('return'), FALSE)) : $FNS->fetch_current_uri();
        $parent_id = ($TMPL->fetch_param('parent_id') != "") ? $TMPL->fetch_param('parent_id') : (($IN->get_post('parent_id')!='')?$IN->get_post('parent_id'):0);
		$XID = '';

		$hidden_fields = array(
								'ACT'	  	=> $FNS->fetch_action_id('Threaded_comments', 'submit'),
								'RET'	  	=> $RET,
                                'URI'	  	=> $this->EE->uri->uri_string,
								'XID'	  	=> $XID,
								'entry_id' 	=> $entry_id,
                                'parent_id' => $parent_id
							  );

		if ($q->row('comment_use_captcha')  == 'y')
		{
			if (preg_match("/({captcha})/", $tagdata))
			{
				$tagdata = preg_replace("/{captcha}/", $FNS->create_captcha(), $tagdata);
			}
		}

		// -------------------------------------------
		// 'comment_form_hidden_fields' hook.
		//  - Add/Remove Hidden Fields for Comment Form
		//
			if ($this->EE->extensions->active_hook('comment_form_hidden_fields') === TRUE)
			{
				$hidden_fields = $this->EE->extensions->call('comment_form_hidden_fields', $hidden_fields);
				if ($this->EE->extensions->end_script === TRUE) return;
			}
		//
		// -------------------------------------------

		$data = array(
						'hidden_fields'	=> $hidden_fields,
						'id'			=> ($TMPL->fetch_param('id')!='') ? $TMPL->fetch_param('id') : ($TMPL->fetch_param('form_id')!='') ? $TMPL->fetch_param('form_id') : 'comment_form',
						'class'			=> ($TMPL->fetch_param('class')!='') ? $TMPL->fetch_param('class') : ($TMPL->fetch_param('form_class')!='') ? $TMPL->fetch_param('form_class') : NULL
					);


		$out  = $FNS->form_declaration($data).stripslashes($tagdata)."</form>";

		// -------------------------------------------
		// 'comment_form_end' hook.
		//  - Modify, add, etc. something to the comment form at end of processing
		//
			if ($this->EE->extensions->active_hook('comment_form_end') === TRUE)
			{
				$out = $this->EE->extensions->call('comment_form_end', $out);
				if ($this->EE->extensions->end_script === TRUE) return $out;
			}
		//
		// -------------------------------------------


		return $out;
	}
    /* END */
    
    /** ----------------------------------------
    /**  Display comments
    /** ----------------------------------------*/
    function display()
    {
        $TMPL = $this->EE->TMPL;
        $DB = $this->EE->db;
        $PREFS = $this->EE->config;
        $FNS = $this->EE->functions;
        $LANG = $this->EE->lang;
        $SESS = $this->EE->session;
        $OUT = $this->EE->output;
        $IN = $this->EE->input;
        $EXT = $this->EE->extensions;
        $LOC = $this->EE->localize;
        $STAT = $this->EE->stats;

        $cond = array();
        $gotpreferences = FALSE;    
        $current_page = 0;
        $uristr  = $this->EE->uri->uri_string;
        $qstr = $this->EE->uri->query_string;
            	
		//get and strip page number
		if (preg_match("/P(\d+)/", $this->EE->uri->query_string, $match))
		{
            $current_page = $match['1'];	
			
			$uristr  = reduce_double_slashes(str_replace($match['0'], '', $this->EE->uri->uri_string));
			$qstr = reduce_double_slashes(str_replace($match['0'], '', $this->EE->uri->query_string));
		}
		
		//what is our entry_id?
        $base_sql = "SELECT t.entry_id FROM exp_channel_titles AS t";
        
		$status_arr = ($TMPL->fetch_param('entry_status')) ? explode("|", $TMPL->fetch_param('entry_status')) : array();
		$status_check_sql = '';
		if (!empty($status_arr))
		{
			$status_check_sql = " AND (";
			foreach ($status_arr as $status)
			{
				$status_check_sql .= ' t.status="'.$status.'" OR';
			}
			$status_check_sql = rtrim($status_check_sql, 'OR').')';
		}
		        
        if ($TMPL->fetch_param('entry_id')!='')
		{	
			$entry_id = $TMPL->fetch_param('entry_id');
            $gotpreferences = TRUE;
		}
        else if ($TMPL->fetch_param('url_title')!='')
        {
            $sql = '';
            $where = " WHERE t.url_title='".$DB->escape_str($TMPL->fetch_param('url_title'))."'";
            if ($TMPL->fetch_param('channel')!='')
            {
                $sql .= " LEFT JOIN exp_channels AS w ON t.channel_id=w.channel_id ";
                $where .= " AND w.channel_name='".$DB->escape_str($TMPL->fetch_param('channel'))."' AND t.site_id='".$DB->escape_str($PREFS->item('site_id'))."'";
            }
            $q = $DB->query($base_sql.$sql.$where.$status_check_sql);
            if ($q->num_rows() == 1)
            {
                $entry_id = $q->row('entry_id');
                $gotpreferences = TRUE;
            }
        }
        //no luck? try to guess it from URL
        if (!isset($entry_id))
        {
            $qstr = $this->EE->uri->query_string;
            if (preg_match("#/P\d+#", $qstr, $match))
    		{			
    			$qstr = reduce_double_slashes(str_replace($match['0'], '', $qstr));
    		}
            $guess = trim($qstr); 		
			$guess = preg_replace("#/.+#", "", $guess);
            if (is_numeric($guess))
            {
                $entry_id = $guess;
            }
            else
            {
                $sql = '';
                $where = " WHERE t.url_title='".$DB->escape_str($guess)."'";
                if ($TMPL->fetch_param('channel')!='')
                {
                    $sql .= " LEFT JOIN exp_channels AS w ON t.channel_id=w.channel_id";
                    $where .= " AND w.channel_name='".$DB->escape_str($TMPL->fetch_param('channel'))."' AND t.site_id='".$DB->escape_str($PREFS->item('site_id'))."'";
                }
                $q = $DB->query($base_sql.$sql.$where.$status_check_sql);
                if ($q->num_rows() == 1)
                {
                    $entry_id = $q->row('entry_id');
                    $gotpreferences = TRUE;
                }
            }
        }

		//still no luck? show nothing
        if (!isset($entry_id))
        {
            return $TMPL->no_results();
        }
		
        if ($gotpreferences===FALSE)
        {
            $sql = " WHERE t.entry_id='".$DB->escape_str($entry_id)."'";
            $q = $DB->query($base_sql.$sql.$status_check_sql);
            if ($q->num_rows() == 0)
            {
                return $TMPL->no_results();
            }
            $entry_id = $q->row('entry_id');
        }
        
        $limit = ( ! $TMPL->fetch_param('limit')) ? 20 : intval($TMPL->fetch_param('limit'));
        $allowed_sort = array('asc', 'desc');
		$sort  = ( ! $TMPL->fetch_param('sort') OR ! in_array(strtolower($TMPL->fetch_param('sort')), $allowed_sort))  ? 'asc' : $TMPL->fetch_param('sort');
        $allowed_order = array('date', 'email', 'location', 'name', 'url');
        $orderby  = ($TMPL->fetch_param('orderby') == 'date' OR ! in_array($TMPL->fetch_param('orderby'), $allowed_order))  ? 'comment_date' : $TMPL->fetch_param('orderby');

		
        
        //do we need pagination?
        $pq = $DB->query("SELECT COUNT(*) AS count FROM exp_comments WHERE entry_id=".$DB->escape_str($entry_id)." AND status = 'o' AND parent_id=0 ");
        if ($pq->row('count')==0)
        {
            return $TMPL->no_results();
        }

        if ($limit>=$pq->row('count'))
        {
            $cond['pagination'] = FALSE;
        } 
        else
        {
            $cond['pagination'] = TRUE;
        }
        
        $this->EE->load->library('pagination');
    	
        if ($current_page > $pq->row('count'))
		{
			$current_page = 0;
		}

		$t_current_page = floor(($current_page / $limit) + 1);
		$total_pages	= ceil($pq->row('count') / $limit);
        
        $basepath = reduce_double_slashes($FNS->create_url($uristr, FALSE));

		if ($TMPL->fetch_param('paginate_base')!='')
		{
			$this->EE->load->helper('string');
			$pbase = trim_slashes($TMPL->fetch_param('paginate_base'));
			$pbase = str_replace("/index", "/", $pbase);
			$basepath = reduce_double_slashes($FNS->create_url($pbase, FALSE));
		}
        
		$p_config['base_url']	= $basepath;
		$p_config['prefix']		= 'P';
		$p_config['total_rows'] = $pq->row('count');
		$p_config['per_page']	= $limit;
		$p_config['cur_page']	= $current_page;
		$p_config['suffix']		= '';
		$p_config['first_link'] = $LANG->line('pag_first_link');
		$p_config['last_link'] 	= $LANG->line('pag_last_link');
        $p_config['uri_segment'] = 0;
    
    	$this->EE->pagination->initialize($p_config);
    
    	$pagination_links = $this->EE->pagination->create_links();
        $cond['next_page'] = FALSE;
        $page_next = '';
        $cond['previous_page'] = FALSE;
        $page_previous = '';
        if ((($total_pages * $limit) - $limit) > $current_page)
		{
			$page_next = rtrim($basepath, '/').'/'.$p_config['prefix'].($current_page + $limit).'/';
            $cond['next_page'] = TRUE;
		}

		if (($current_page - $limit ) >= 0)
		{
			$page_previous = rtrim($basepath, '/').'/'.$p_config['prefix'].($current_page - $limit).'/';
            $cond['previous_page'] = TRUE;
		}
        
        $tagdata = $TMPL->tagdata;
        
        $tagdata = $FNS->prep_conditionals($tagdata, $cond);
        
        if ( preg_match_all("/".LD."paginate".RD."(.*?)".LD."\/paginate".RD."/s", $tagdata, $tmp)!=0)
        {
            $tagdata = str_replace($tmp[0][0], $tmp[1][0], $tagdata);
        }
        
        $tagdata = $TMPL->swap_var_single('pagination_links', $pagination_links, $tagdata);
        $tagdata = $TMPL->swap_var_single('current_page', $t_current_page, $tagdata);
        $tagdata = $TMPL->swap_var_single('total_pages', $total_pages, $tagdata);
        $tagdata = $TMPL->swap_var_single('prev_link', $page_previous, $tagdata);
        $tagdata = $TMPL->swap_var_single('next_link', $page_next, $tagdata);
        
        $tagdata = $TMPL->swap_var_single('total_threads', $pq->row('count'), $tagdata);
        
        $tot_q = $DB->query("SELECT COUNT(*) AS count FROM exp_comments WHERE entry_id=".$DB->escape_str($entry_id)." AND status = 'o'");
        $tagdata = $TMPL->swap_var_single('total_comments', $tot_q->row('count'), $tagdata);
        
        
        //get current page zero level comment ids
        $commentids_q = $DB->query("SELECT comment_id FROM exp_comments WHERE entry_id=".$DB->escape_str($entry_id)." AND status = 'o' AND parent_id=0 ORDER BY $orderby $sort LIMIT $current_page, $limit");
        if ($commentids_q->num_rows()==0)
        {
            return $TMPL->no_results();
        }
        $commentids_a = array();
        foreach ($commentids_q->result_array() as $row)
        {
            $commentids_a[] = $row['comment_id'];
        }
        $commentids = implode(",", $commentids_a);
        
        //absolute counter
        if ($current_page!=0)
        {
            $prev_q = $DB->query("SELECT comment_id FROM exp_comments WHERE entry_id=".$DB->escape_str($entry_id)." AND status = 'o' AND parent_id=0 ORDER BY $orderby $sort LIMIT 0, $current_page");
            $prev_commentids_a = array();
            foreach ($prev_q->result_array() as $row)
            {
                $prev_commentids_a[] = $row['comment_id'];
            }
            $this->displayed_prev_root = count($prev_commentids_a); 
            if (count($prev_commentids_a)>0)
            {
                $prev_commentids = implode(",", $prev_commentids_a);
                $prev_count_q = $DB->query("SELECT COUNT(*) AS count FROM exp_comments WHERE comment_id IN ($prev_commentids) OR root_id IN ($prev_commentids)");
                $this->displayed_prev = $prev_count_q->row('count');
            }
        }
        
        
        //get actual comments - zero level and all related
        
        $mfields_q = $DB->query("SELECT m_field_id, m_field_name FROM exp_member_fields");
		$mfields = array();				
		if ($mfields_q->num_rows() > 0)
		{
			foreach ($mfields_q->result_array() as $row)
			{        		
				$mfields[$row['m_field_name']] = $row['m_field_id'];
			}
		}
        
        $sql = "SELECT 
				exp_comments.comment_id, exp_comments.entry_id, exp_comments.channel_id, exp_comments.author_id, exp_comments.name, exp_comments.email AS c_email, exp_comments.url AS c_url, exp_comments.location as c_location, exp_comments.ip_address, exp_comments.comment_date, exp_comments.edit_date, exp_comments.comment, exp_comments.site_id AS comment_site_id, exp_comments.root_id, exp_comments.parent_id, exp_comments.level,
				exp_members.username, exp_members.screen_name, exp_members.email, exp_members.url, exp_members.location, exp_members.occupation, exp_members.interests, exp_members.aol_im, exp_members.yahoo_im, exp_members.msn_im, exp_members.icq, exp_members.group_id, exp_members.member_id, exp_members.signature, exp_members.sig_img_filename, exp_members.sig_img_width, exp_members.sig_img_height, exp_members.avatar_filename, exp_members.avatar_width, exp_members.avatar_height, exp_members.photo_filename, exp_members.photo_width, exp_members.photo_height, 
				exp_member_data.*,
				exp_channel_titles.title, exp_channel_titles.url_title, exp_channel_titles.author_id AS entry_author_id,
				exp_channels.comment_text_formatting, exp_channels.comment_html_formatting, exp_channels.comment_allow_img_urls, exp_channels.comment_auto_link_urls, exp_channels.channel_url, exp_channels.comment_url, exp_channels.channel_title 
				FROM exp_comments 
				LEFT JOIN exp_channels ON exp_comments.channel_id = exp_channels.channel_id 
				LEFT JOIN exp_channel_titles ON exp_comments.entry_id = exp_channel_titles.entry_id 
				LEFT JOIN exp_members ON exp_members.member_id = exp_comments.author_id 
				LEFT JOIN exp_member_data ON exp_member_data.member_id = exp_members.member_id
				WHERE (exp_comments.comment_id  IN ($commentids) OR exp_comments.root_id IN ($commentids))
                    AND exp_comments.status='o'
                ORDER BY $orderby $sort";
		
		$query = $DB->query($sql);
        
        $tagdata = $TMPL->swap_var_single('total_results', $query->num_rows(), $tagdata);
        
        $this->EE->load->helper('url');
        
        $first_comment_id = 0;
		
        foreach ($query->result_array() as $row)
		{
            if (!isset ($this->comment[$row['comment_id']]))
			{
				$this->comment[$row['comment_id']] = new stdClass();
			}
			if (!isset ($this->comment[$row['parent_id']]))
			{
				$this->comment[$row['parent_id']] = new stdClass();
			}
			
			if ($first_comment_id==0 && $row['level']==0) $first_comment_id = $row['comment_id'];
            if (!isset($this->comment[$row['comment_id']]->has_children)) $this->comment[$row['comment_id']]->has_children = false;
            $this->comment[$row['parent_id']]->has_children = true;
            if ($this->comment[$row['parent_id']]->has_children == true)
            {
                $this->comment[$row['parent_id']]->children[] = $row['comment_id'];
            }
            
            $this->comment[$row['comment_id']]->level = $row['level'];
            $this->comment[$row['comment_id']]->root_id = $row['root_id'];
            $this->comment[$row['comment_id']]->parent_id = $row['parent_id'];
            
            $this->comment[$row['comment_id']]->comment_id = $row['comment_id'];
            $this->comment[$row['comment_id']]->entry_id = $row['entry_id'];
            $this->comment[$row['comment_id']]->channel_id = $row['channel_id'];
            $this->comment[$row['comment_id']]->author_id = $row['author_id'];
            $this->comment[$row['comment_id']]->username = $row['username'];            
            $this->comment[$row['comment_id']]->name = ($row['name']!='')?$row['name']:($row['screen_name']!=''?$row['screen_name']:$row['username']);
            $this->comment[$row['comment_id']]->email = ($row['c_email']!='')?$row['c_email']:$row['email'];
            $this->comment[$row['comment_id']]->url = ($row['c_url']!='')?$row['c_url']:$row['url'];
            if ($this->comment[$row['comment_id']]->url!='')
            {
                $this->comment[$row['comment_id']]->url = prep_url($this->comment[$row['comment_id']]->url);
            }
            $this->comment[$row['comment_id']]->location = ($row['c_location']!='')?$row['c_location']:$row['location'];
            $this->comment[$row['comment_id']]->ip_address = $row['ip_address'];
            $this->comment[$row['comment_id']]->comment_date = $row['comment_date'];
            $this->comment[$row['comment_id']]->edit_date = $row['edit_date'];
            $this->comment[$row['comment_id']]->comment = $row['comment'];
            //$this->comment[$row['comment_id']]->notify = $row['notify'];
            //$this->comment[$row['comment_id']]->notify_thread = $row['notify_thread'];
            $this->comment[$row['comment_id']]->comment_site_id = $row['comment_site_id'];
            $this->comment[$row['comment_id']]->occupation = $row['occupation'];
            $this->comment[$row['comment_id']]->interests = $row['interests'];
            $this->comment[$row['comment_id']]->aol_im = $row['aol_im'];
            $this->comment[$row['comment_id']]->yahoo_im = $row['yahoo_im'];
            $this->comment[$row['comment_id']]->msn_im = $row['msn_im'];
            $this->comment[$row['comment_id']]->icq = $row['icq'];
            $this->comment[$row['comment_id']]->group_id = $row['group_id'];
            $this->comment[$row['comment_id']]->member_group_id = $row['group_id'];
            $this->comment[$row['comment_id']]->member_id = $row['member_id'];
            $this->comment[$row['comment_id']]->signature = $row['signature'];
            $this->comment[$row['comment_id']]->sig_img_filename = $row['sig_img_filename'];
            $this->comment[$row['comment_id']]->sig_img_width = $row['sig_img_width'];
            $this->comment[$row['comment_id']]->sig_img_height = $row['sig_img_height'];
            $this->comment[$row['comment_id']]->avatar_filename = $row['avatar_filename'];
            $this->comment[$row['comment_id']]->avatar_width = $row['avatar_width'];
            $this->comment[$row['comment_id']]->avatar_height = $row['avatar_height'];
            $this->comment[$row['comment_id']]->photo_filename = $row['photo_filename'];
            $this->comment[$row['comment_id']]->photo_width = $row['photo_width'];
            $this->comment[$row['comment_id']]->photo_height = $row['photo_height'];
            foreach ($mfields as $mfield_name=>$mfield_id)
            {
                $this->comment[$row['comment_id']]->$mfield_name = $row['m_field_id_'.$mfield_id];
            }
            $this->comment[$row['comment_id']]->title = $row['title'];
            $this->comment[$row['comment_id']]->url_title = $row['url_title'];
            $this->comment[$row['comment_id']]->entry_author_id = $row['entry_author_id'];
            $this->comment[$row['comment_id']]->comment_text_formatting = $row['comment_text_formatting'];
            $this->comment[$row['comment_id']]->comment_html_formatting = $row['comment_html_formatting'];
            $this->comment[$row['comment_id']]->comment_allow_img_urls = $row['comment_allow_img_urls'];
            $this->comment[$row['comment_id']]->comment_auto_link_urls = $row['comment_auto_link_urls'];
            $this->comment[$row['comment_id']]->comment_url = ($row['comment_url']!='')?$row['comment_url']:$row['channel_url'];
            $this->comment[$row['comment_id']]->channel_title = $row['channel_title'];
            
            //mark the comments as read
            $qstr = "UPDATE exp_comment_subscriptions SET notification_sent='n' WHERE entry_id='".$row['entry_id']."' AND thread_id='".$row['root_id']."' ";
            if ($SESS->userdata('member_id') != 0)
            {
                $qstr .= " AND member_id=".$SESS->userdata('member_id');
                $DB->query($qstr);
            }
            else if ($IN->cookie('my_email')!='')
            {
                $qstr .= " AND email='".$DB->escape_str($IN->cookie('my_email'))."'";
                $DB->query($qstr);
            }            

		}

        if (preg_match_all("/".LD."comments".RD."(.*?)".LD."\/comments".RD."/s", $tagdata, $tmp))
        {
	        $comments_tagdata = $tmp[1][0];
	        //var_dump($tagdata);
	        
	        //trick with the dates - prepare them here!
	        $dates = array();
	        $date_vars = array('gmt_comment_date', 'comment_date', 'edit_date');
			foreach ($date_vars as $val)
			{					
				if (preg_match_all("/".LD.$val."\s+format=[\"'](.*?)[\"']".RD."/s", $comments_tagdata, $matches))
				{
					for ($j = 0; $j < count($matches['0']); $j++)
					{
						$matches['0'][$j] = str_replace(LD, '', $matches['0'][$j]);
						$matches['0'][$j] = str_replace(RD, '', $matches['0'][$j]);
						
	                    //$dates[$val][$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
	                    $dates[$val][$matches['0'][$j]] = $matches['1'][$j];
					}
				}
			}
	
	        //build the properly ordered tree         
	        $comments_sorted = $this->_build_thread($commentids_a);   
	        //var_dump($commentids_a);
	        
	        //krsort($this->thread_end_ids);
	        //var_dump($this->thread_end_ids);
	        
	        $return = '';
	        $total = count($comments_sorted)-1;
	        $rootcount = -1;
	        foreach ($comments_sorted as $count=>$cmt_id)
	        {
	            //var_dump($cmt_id);
	            /*$rootcount = array_search($cmt_id, $commentids_a);
	            if ($rootcount!==FALSE)
	            {
	                $rootcount = $count;
	            }*/
	            if ($this->comment[$cmt_id]->level == 0)
	            {
	            	$rootcount++;
	            }
	            
	            $lastcall = ($count==$total) ? true : false;
	            $nextid = (isset($comments_sorted[$count+1]))?$comments_sorted[$count+1]:0;            
	            $return .= $this->_commentloop($comments_tagdata, $dates, $cmt_id, $count, $rootcount, $nextid, $lastcall);
	        }      
	        
	        $tagdata = str_replace($tmp[0][0], $return, $tagdata);
	        
	
			/** ----------------------------------------
			/**  Parse path variable
			/** ----------------------------------------*/
	        
	        $tagdata = preg_replace_callback("/".LD."\s*path=(.+?)".RD."/", array(&$FNS, 'create_url'), $tagdata);
		}
		
		$tagdata = $TMPL->parse_globals($tagdata);

        return $tagdata;
    }
    /* END */
    
    /** ----------------------------------------
	/**  Build the properly sorted tree of comment IDs
	/** ----------------------------------------*/
    function _build_thread($arr)
    {
        foreach ($arr as $cmt_id)
        {
            $comments_sorted[] = $this->comment[$cmt_id]->comment_id;
            if ($this->comment[$cmt_id]->has_children == true)
            {
                //thread start
                $this->thread_open_ids[] = $this->comment[$cmt_id]->comment_id;
                $this->thread_start_ids[] = $this->comment[$cmt_id]->children[0];
                $arr_last_el = count($this->comment[$cmt_id]->children)-1;
                
                $child_comments = $this->_build_thread($this->comment[$cmt_id]->children);
                
                //thread end
                $idx = $this->comment[$cmt_id]->children[$arr_last_el];
                if ($this->comment[$idx]->has_children == false)
                {
                    $close_idx = $idx - 1;
                    $this->thread_end_ids[] = $idx;
                    $this->thread_close_ids[] = $close_idx;
                }
                
                foreach ($child_comments as $child_comment) array_push($comments_sorted, $child_comment);
            }
        }
        return $comments_sorted;    
    } 
    /* END */
    
    /* ----------------------------------------
	/*  Deal with {comments} loop 
	/* ----------------------------------------*/
    function _commentloop($tagdata, $dates, $currentid, $count, $rootcount, $nextid, $lastcall=false)
    {      
            
        $TMPL = $this->EE->TMPL;
        $DB = $this->EE->db;
        $PREFS = $this->EE->config;
        $FNS = $this->EE->functions;
        $LANG = $this->EE->lang;
        $SESS = $this->EE->session;
        $OUT = $this->EE->output;
        $IN = $this->EE->input;
        $EXT = $this->EE->extensions;
        $LOC = $this->EE->localize;
        $STAT = $this->EE->stats;
        
        $return = '';  
        //echo $currentid;
        //var_dump($this->comment);
        $row = get_object_vars($this->comment[$currentid]);
        
        // -------------------------------------------
		// 'comment_entries_tagdata' hook.
		//  - Modify and play with the tagdata before everyone else
		//
			if ($EXT->active_hook('comment_entries_tagdata') === TRUE)
			{
				$tagdata = $EXT->call('comment_entries_tagdata', $tagdata, $row);
				if ($EXT->end_script === TRUE) return $tagdata;
			}
		//
		// -------------------------------------------
        
		/*
		$cond['allow_comments'] 	= (isset($row['allow_comments']) AND $row['allow_comments'] == 'n') ? 'FALSE' : 'TRUE';*/
        
		$cond['signature_image']	= ( ! isset($row['sig_img_filename']) || $row['sig_img_filename'] == '' || $PREFS->item('enable_signatures') == 'n' || $SESS->userdata('display_signatures') == 'n') ? FALSE : TRUE;
		$cond['avatar']				= ( ! isset($row['avatar_filename']) || $row['avatar_filename'] == '' || $PREFS->item('enable_avatars') == 'n' || $SESS->userdata('display_avatars') == 'n') ? FALSE : TRUE;
		$cond['photo']				= ( ! isset($row['photo_filename']) || $row['photo_filename'] == '' || $PREFS->item('enable_photos') == 'n' || $SESS->userdata('display_photos') == 'n') ? FALSE : TRUE;
		$cond['is_ignored']			= ( ! isset($row['member_id']) || ! in_array($row['member_id'], $SESS->userdata['ignore_list'])) ? FALSE : TRUE;
        $cond['has_replies']			= ($row['has_children'] != true) ? FALSE : TRUE;

		foreach($row as $key => $value)
		{
			if ($value!='' && $value!='n' && $value!='0'  && $value!=NULL )
            {
                $cond[$key] = TRUE;
            }
            else
			{
			    $cond[$key] = FALSE; 
			}	
		}


		$tagdata = $FNS->prep_conditionals($tagdata, $cond);
 
        $this->EE->load->library('typography');
        $this->EE->typography->initialize();
        $TYPE = $this->EE->typography;
        
                                      
       	$tagdata = $TMPL->swap_var_single('next_comment_id', $nextid, $tagdata);
        $tagdata = $TMPL->swap_var_single('prev_comment_level', $this->prev_level, $tagdata);
        $nextrow = get_object_vars($this->comment[$nextid]);
        if (!isset($nextrow['level'])) $nextrow['level'] = 0;
        $tagdata = $TMPL->swap_var_single('next_comment_level', $nextrow['level'], $tagdata);

        foreach ($TMPL->var_single as $key => $val)
        { 
            //parse comment
            if ($key == 'comment')
			{
				// -------------------------------------------
				// 'comment_entries_comment_format' hook.
				//  - Play with the tagdata contents of the comment entries
				//
				if ($EXT->active_hook('comment_entries_comment_format') === TRUE)
				{
					$comment = $EXT->call('comment_entries_comment_format', $row);
					if ($EXT->end_script === TRUE) return;
				}
				else
				{
					$comment = $TYPE->parse_type( $row['comment'], 
												   array(
															'text_format'   => $row['comment_text_formatting'],
															'html_format'   => $row['comment_html_formatting'],
															'auto_links'    => $row['comment_auto_link_urls'],
															'allow_img_url' => $row['comment_allow_img_urls']
														)
												);
				}
				//
				// -------------------------------------------
				
				$tagdata = $TMPL->swap_var_single($key, $comment, $tagdata);                
			}
            
            // dates         
            if (strpos($key, 'comment_date')===0)
            {
	            if (isset($dates['comment_date']))
	            {                
					foreach ($dates['comment_date'] as $dvar)
					{
						$val = str_replace($dvar, $this->_format_date($dvar, $row['comment_date'], TRUE), $val);		
					}
					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
	            }
            }
            
            if (strpos($key, 'gmt_comment_date')===0)
            {
	            if (isset($dates['gmt_comment_date']))
	            {                
					foreach ($dates['gmt_comment_date'] as $dvar)
					{
						$val = str_replace($dvar, $this->_format_date($dvar, $row['comment_date'], FALSE), $val);		
					}
					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
	            }
     		}
            
            if (strpos($key, 'edit_date')===0)
            {
	            if (isset($dates['edit_date']))
	            {
					foreach ($dates['edit_date'] as $dvar)
	                {
						$val = str_replace($dvar, $this->_format_date($dvar, $LOC->timestamp_to_gmt($row['edit_date']), TRUE), $val);					
					}
	                $tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					              
	            }
     		}
            
            
            
            //{switch}
			
			if (strncmp($key, 'switch', 6) == 0)
			{
				$sparam = $FNS->assign_parameters($key);
				
				$sw = '';

				if (isset($sparam['switch']))
				{
					$sopt = @explode("|", $sparam['switch']);
					
					$sw = $sopt[($rootcount + count($sopt) - 1) % count($sopt)];

				}
				
				$tagdata = $TMPL->swap_var_single($key, $sw, $tagdata);
			}

            // {permalink}
            
            if (strncmp('permalink', $key, 9) == 0)
            {                     
				$tagdata = $TMPL->swap_var_single(
													$key, 
													$FNS->create_url($uristr.'#'.$row['comment_id'], 0, 0), 
													$tagdata
												 );
            }                

            // {comment_path}
            
            if (preg_match("#^(comment_path|entry_id_path)#", $key))
            {                       
				$tagdata = $TMPL->swap_var_single(
													$key, 
													$FNS->create_url($FNS->extract_path($key).'/'.$row['entry_id']), 
													$tagdata
												 );
            }


            // {title_permalink}            
            if (preg_match("#^(title_permalink|url_title_path)#", $key))
            { 
				$path = ($FNS->extract_path($key) != '' && $FNS->extract_path($key) != 'SITE_INDEX') ? $FNS->extract_path($key).'/'.$row['url_title'] : $row['url_title'];

				$tagdata = $TMPL->swap_var_single(
													$key, 
													$FNS->create_url($path, 1, 0), 
													$tagdata
												 );
            }
        

            // {member_search_path}
            
			if (strncmp('member_search_path', $key, 18) == 0)
            {                   
				$tagdata = $TMPL->swap_var_single($key, $search_link.$row['author_id'], $tagdata);
            }
            
            // {username}
            if ($key == "username")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $row['username'], $tagdata);
            }

            // {author}
            if ($key == "author")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $row['name'], $tagdata);
            }

            // {url_or_email}            
            if ($key == "url_or_email")
            {
                $tagdata = $TMPL->swap_var_single($val, ($row['url'] != '') ? $row['url'] : $row['email'], $tagdata);
            }

            // {url_as_author}

            if ($key == "url_as_author")
            {                    
                if ($row['url'] != '')
                {
                    $tagdata = $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$row['name']."</a>", $tagdata);
                }
                else
                {
                    $tagdata = $TMPL->swap_var_single($val, $row['name'], $tagdata);
                }
            }

            // {url_or_email_as_author}
            if ($key == "url_or_email_as_author")
            {                    
                if ($row['url'] != '')
                {
                    $tagdata = $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$row['name']."</a>", $tagdata);
                }
                else
                {
                	if ($row['email'] != '')
                	{
                    	$tagdata = $TMPL->swap_var_single($val, $TYPE->encode_email($row['email'], $row['name']), $tagdata);
                    }
                    else
                    {
                    	$tagdata = $TMPL->swap_var_single($val, $row['name'], $tagdata);
                    }
                }
            }
            
            // {url_or_email_as_link}            
            if ($key == "url_or_email_as_link")
            {                    
                if ($row['url'] != '')
                {
                    $tagdata = $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$row['url']."</a>", $tagdata);
                }
                else
                {  
                	if ($row['email'] != '')
                	{                    
                    	$tagdata = $TMPL->swap_var_single($val, $TYPE->encode_email($row['email']), $tagdata);
                    }
                    else
                    {
                    	$tagdata = $TMPL->swap_var_single($val, $row['name'], $tagdata);
                    }
                }
            }
           
            // {comment_auto_path}            
            if ($key == "comment_auto_path")
            {           
                $tagdata = $TMPL->swap_var_single($key, $row['comment_url'], $tagdata);
            }
            
            // {comment_url_title_auto_path}            
            if ($key == "comment_url_title_auto_path")
            { 
                $tagdata = $TMPL->swap_var_single(
                								$key, 
                								$row['comment_url'].$row['url_title'].'/', 
                								$tagdata
                							 );
            }
            
            // {comment_entry_id_auto_path}            
            if ($key == "comment_entry_id_auto_path" AND $comments_exist == TRUE)
            {           
                $tagdata = $TMPL->swap_var_single(
                								$key, 
                								$row['comment_url'].$row['entry_id'].'/', 
                								$tagdata
                							 );
            }

            // {signature}
            
            if ($key == "signature")
            {                
				if ($SESS->userdata('display_signatures') == 'n' OR  ! isset($row['signature']) OR $row['signature'] == '' OR $SESS->userdata('display_signatures') == 'n')
				{			
					$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($key,
													$TYPE->parse_type($row['signature'], array(
																				'text_format'   => 'xhtml',
																				'html_format'   => 'safe',
																				'auto_links'    => 'y',
																				'allow_img_url' => $PREFS->item('sig_allow_img_hotlink')
																			)
																		), $tagdata);
				}
            }
            
            //images
            if ($key == "signature_image_url")
            {                  
				if ($SESS->userdata('display_signatures') == 'n' OR $row['sig_img_filename'] == ''  OR $SESS->userdata('display_signatures') == 'n')
				{			
					$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
					$tagdata = $TMPL->swap_var_single('signature_image_width', '', $tagdata);
					$tagdata = $TMPL->swap_var_single('signature_image_height', '', $tagdata);
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($key, $PREFS->item('sig_img_url').$row['sig_img_filename'], $tagdata);
					$tagdata = $TMPL->swap_var_single('signature_image_width', $row['sig_img_width'], $tagdata);
					$tagdata = $TMPL->swap_var_single('signature_image_height', $row['sig_img_height'], $tagdata);						
				}
            }

            if ($key == "avatar_url")
            {        
				if ($SESS->userdata('display_avatars') == 'n' || $row['avatar_filename'] == '' || $SESS->userdata('display_avatars') == 'n')
				{			
					$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
					$tagdata = $TMPL->swap_var_single('avatar_image_width', '', $tagdata);
					$tagdata = $TMPL->swap_var_single('avatar_image_height', '', $tagdata);
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($key, $PREFS->item('avatar_url').$row['avatar_filename'], $tagdata);
					$tagdata = $TMPL->swap_var_single('avatar_image_width', $row['avatar_width'], $tagdata);
					$tagdata = $TMPL->swap_var_single('avatar_image_height', $row['avatar_height'], $tagdata);						
				}
            }
            
            if ($key == "photo_url")
            {        
				if ($SESS->userdata('display_photos') == 'n' || $row['photo_filename'] == '' || $SESS->userdata('display_photos') == 'n')
				{			
					$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
					$tagdata = $TMPL->swap_var_single('photo_image_width', '', $tagdata);
					$tagdata = $TMPL->swap_var_single('photo_image_height', '', $tagdata);
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($key, $PREFS->item('photo_url').$row['photo_filename'], $tagdata);
					$tagdata = $TMPL->swap_var_single('photo_image_width', $row['photo_width'], $tagdata);
					$tagdata = $TMPL->swap_var_single('photo_image_height', $row['photo_height'], $tagdata);						
				}
            }
            
            // {count}
            if ($key == "count")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $count+1, $tagdata);
            }
            
            // {count_root}
            if ($key == "count_root")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $rootcount+1, $tagdata);
            }
            
            // {absolute_count}
            if ($key == "absolute_count")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $this->displayed_prev+$count+1, $tagdata);
            }
            
            // {absolute_count_root}
            if ($key == "absolute_count_root")
            {                    
               	$tagdata = $TMPL->swap_var_single($val, $this->displayed_prev_root+$rootcount+1, $tagdata);
            }
            
            //parse all the other variables that are in query results
            
			if (isset($row[$val]))
            {                    
                $tagdata = $TMPL->swap_var_single($val, $row[$val], $tagdata);
            }
            else
            {
                $tagdata = $TMPL->swap_var_single($val, '', $tagdata);
            }
            
		}
        
        //complete missing closing tags
        if ($row['level']<($this->prev_level-1))
        {
            
            if (preg_match_all("/".LD."thread_end".RD."(.*?)".LD."\/thread_end".RD."/s", $tagdata, $tmp)!=0)// && in_array($currentid, $this->thread_end_ids)
            {
                if ( preg_match_all("/".LD."thread_start".RD."(.*?)".LD."\/thread_start".RD."/s", $tagdata, $tmp2)!=0)
                {
                
                    $replace = '';
                    //echo $row['comment_id'];
                    //echo $row['level']."-".$this->prev_level;
                    //var_dump($tmp);
                    
                    for($i=$row['level']; $i<$this->prev_level-1; $i++)
                    {
                        //echo $i;
                        $replace .= $tmp[1][0];
                    }
                    $replace .= $tmp2[0][0];
                    //echo $replace;
                    $tagdata = str_replace($tmp2[0][0], $replace, $tagdata);
                }
            }
        }
        
        //first in thread?
        //echo preg_match_all("/".LD."thread_start".RD."(.*?)".LD."\/thread_start".RD."/s", $tagdata, $tmp);
        //echo in_array($currentid, $this->thread_start_ids);
        //var_dump($tmp);
        if ( preg_match_all("/".LD."thread_start".RD."(.*?)".LD."\/thread_start".RD."/s", $tagdata, $tmp)!=0)
        {
            if (in_array($currentid, $this->thread_start_ids))
            {
                $tagdata = str_replace($tmp[0][0], $tmp[1][0], $tagdata);
            }
            else
            {
                $tagdata = str_replace($tmp[0][0], '', $tagdata);
            }
        } 
        
        if ( preg_match_all("/".LD."thread_open".RD."(.*?)".LD."\/thread_open".RD."/s", $tagdata, $tmp)!=0)
        {
            if (in_array($currentid, $this->thread_open_ids))
            {
                $tagdata = str_replace($tmp[0][0], $tmp[1][0], $tagdata);
            }
            else
            {
                $tagdata = str_replace($tmp[0][0], '', $tagdata);
            }
        } 
        
        
        //last in thread?
        // also make sure no childs present 
        if (preg_match_all("/".LD."thread_end".RD."(.*?)".LD."\/thread_end".RD."/s", $tagdata, $tmp)!=0)
        {
            if (in_array($currentid, $this->thread_end_ids) && $row['has_children']==false)
            {
                //is last call? close all tags
                $replace = $tmp[1][0];
                //$replace = '';
                if ($lastcall==true)
                {
                    //echo 'lastcall';
                    for($i=0; $i<$row['level']-1; $i++)
                    {
                        $replace .= $tmp[1][0];
                    }
                }
                $tagdata = str_replace($tmp[0][0], $replace, $tagdata);
            }
            else
            {
                $tagdata = str_replace($tmp[0][0], '', $tagdata);
            }
        } 

        if (preg_match_all("/".LD."thread_close".RD."(.*?)".LD."\/thread_close".RD."/s", $tagdata, $tmp)!=0)
        {
            if (in_array($currentid, $this->thread_end_ids) && $row['level']!=0)
            {
                //is last call? close all tags
                $replace = $tmp[1][0];
                //$replace = '';
  
                if ($lastcall==true)
                {
                    $repeats_nr = $row['level']-1;
                }
                else
                {
                    $repeats_nr = $row['level']-$nextrow['level']-1;
                    $tagdata = $TMPL->swap_var_single('next_comment_level', $nextrow['level'], $tagdata);
                }
                

                for($i=0; $i<$repeats_nr; $i++)
                {
                    $replace .= $tmp[1][0];
                }

                $tagdata = str_replace($tmp[0][0], $replace, $tagdata);
            }
            else
            {
                $tagdata = str_replace($tmp[0][0], '', $tagdata);
            }
        } 
        
        if (preg_match_all("/".LD."thread_container_close".RD."(.*?)".LD."\/thread_container_close".RD."/s", $tagdata, $tmp)!=0)
        {
            //if (($row['level']==0 && $row['has_children']==false) || (in_array($currentid, $this->thread_end_ids)))
            if (($row['has_children']==false) || (in_array($currentid, $this->thread_end_ids)))
            {
                $tagdata = str_replace($tmp[0][0], $tmp[1][0], $tagdata);
            }
            else
            {
                $tagdata = str_replace($tmp[0][0], '', $tagdata);
            }
        } 
        
        $this->prev_level = $row['level'];
        
        //add the content of the loop
        $return .= $tagdata;

		//$item_count++;    
        return $return;                    
      }
      /* END */
      
      
      function _format_date($one='', $two='', $three=false)
	{
		if ($this->EE->config->item('app_version')>=260)
		{
			return $this->EE->localize->format_date($one, $two, $three);
		}
		else
		{
			return $this->EE->localize->decode_date($one, $two, $three);
		}
	}

}
/* END */
?>