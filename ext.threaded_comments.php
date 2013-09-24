<?php

/*
=====================================================
 Threaded Comments
-----------------------------------------------------
 http://www.intoeetive.com/
-----------------------------------------------------
 Copyright (c) 2011 Yuri Salimovskiy
=====================================================
 This software is based upon and derived from
 ExpressionEngine software protected under
 copyright dated 2004 - 2010. Please see
 http://expressionengine.com/docs/license.html
=====================================================
 File: ext.threaded_comments.php
-----------------------------------------------------
 Purpose: Enables nested comments in ExpressionEngine
=====================================================
*/

if ( ! defined('BASEPATH'))
{
	exit('Invalid file request');
}

class Threaded_comments_ext {

	var $name	     	= 'Threaded Comments';
	var $version 		= '2.3.1';
	var $description	= 'Enables nested comments in ExpressionEngine';
	var $settings_exist	= 'n';
	var $docs_url		= 'http://www.intoeetive.com/docs/threaded_comments.html';
    
    var $settings 		= array();
    
	/**
	 * Constructor
	 *
	 * @param 	mixed	Settings array or empty string if none exist.
	 */
	function __construct($settings = '')
	{
		$this->EE =& get_instance();
	}
    
    /**
     * Activate Extension
     */
    function activate_extension()
    {
        
        $hooks = array(
            array(
    			'hook'		=> 'insert_comment_insert_array',
    			'method'	=> 'insert_comment_data',
    			'priority'	=> 10
    		)
    	);
    	
        foreach ($hooks AS $hook)
    	{
    		$data = array(
        		'class'		=> __CLASS__,
        		'method'	=> $hook['method'],
        		'hook'		=> $hook['hook'],
        		'settings'	=> '',
        		'priority'	=> $hook['priority'],
        		'version'	=> $this->version,
        		'enabled'	=> 'y'
        	);
            $this->EE->db->insert('extensions', $data);
    	}	

    }
    
    /**
     * Update Extension
     */
    function update_extension($current = '')
    {
    	if ($current == '' OR $current == $this->version)
    	{
    		return FALSE;
    	}
    	
    	if ($current < '2.0')
    	{
    		// Update to version 1.0
    	}
    	
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->update(
    				'extensions', 
    				array('version' => $this->version)
    	);
    }
    
    
    /**
     * Disable Extension
     */
    function disable_extension()
    {
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->delete('extensions');        
                    
    }
    
    
    function settings()
    {
        return;	
    }  


    function insert_comment_data($data)
    {
        if ($this->EE->input->post('parent_id')==0)
        {
            return;
        }
        
        $this->EE->extensions->end_script = TRUE;
        
        if ($this->EE->security->secure_forms_check($this->EE->input->post('XID')) == FALSE)
		{
			$this->EE->functions->redirect(stripslashes($return_link));
		}
        
        $parent_id = $this->EE->security->xss_clean($_POST['parent_id']);
        $root_id = $parent_id;
        $level = 0;
        do {
            $level++;
            $q = $this->EE->db->query("SELECT parent_id FROM exp_comments WHERE comment_id='".$this->EE->db->escape_str($root_id)."'");
            if ($q->row('parent_id')==0) break;
            $root_id = $q->row('parent_id');
        } while ($root_id!=0);
        
        $data['parent_id'] = $parent_id;
        $data['root_id'] = $root_id;
        $data['level'] = $level;

		$this->EE->db->insert('comments', $data);
		$comment_id = $this->EE->db->insert_id();		
        
        
        if ($this->EE->session->userdata('member_id')==0)
        {
            $name = $this->EE->input->post('name', true);
            $email =  $this->EE->input->post('email', true);
        }
        else
        {
            $name = ($this->EE->session->userdata('screen_name')!='') ? $this->EE->session->userdata('screen_name') : $this->EE->session->userdata('username');
            $email = $this->EE->session->userdata('email');
        }
        
        $notify = $this->EE->input->post('notify_me') ? 'y' : 'n';
        $moderate		= ($SESS->userdata['group_id'] == 1 || $SESS->userdata['exclude_from_moderation'] == 'y') ? 'n' : $channel_entry->row('comment_moderate');
        $notify_thread = $this->EE->input->post('notify_thread') ? 'y' : 'n';
        if ($notify_thread == 'y')
        {
            $subscr_q = $this->EE->db->query("SELECT subscription_id, thread_id, email FROM exp_comment_subscriptions WHERE entry_id='".$_POST['entry_id']."' AND thread_id='".$root_id."' AND (email='".$email."' OR (member_id='".$this->EE->session->userdata('member_id')."' AND member_id!=0))");
            if ($subscr_q->num_rows()==0)
            {
                $this->EE->db->query("INSERT INTO exp_comment_subscriptions SET entry_id='".$_POST['entry_id']."', member_id='".$this->EE->session->userdata('member_id')."', email='".$email."', thread_id='".$root_id."', subscription_date='".$this->EE->localize->now."', notification_sent='n', hash='".$this->EE->session->userdata('member_id').$this->EE->functions->random('alnum', 8)."'");
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
                    $this->EE->db->query($qstr);
                }
            }
        }
        
        if ($notify_thread == 'y')
		{        
            $this->EE->functions->set_cookie('notify_thread_'.$root_id, 'yes', 60*60*24*365);
		}
		else
		{
            $this->EE->functions->set_cookie('notify_thread_'.$root_id, 'no', 60*60*24*365);
		}
        
        if ($comment_moderate == 'n')
        {       

			$recipients = array();

			//get ready for notifications!
			$query = $this->EE->db->query("SELECT subscription_id, member_id, email, notification_sent, hash FROM exp_comment_subscriptions WHERE entry_id = '".$this->EE->db->escape_str($_POST['entry_id'])."' AND (thread_id = '0' OR thread_id='".$root_id."') ORDER BY thread_id ASC");
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
                        $com_q = $this->EE->db->query("SELECT comment_id, name, email FROM exp_comments WHERE author_id = '".$this->EE->db->escape_str($row['member_id'])."' AND entry_id='".$this->EE->db->escape_str($_POST['entry_id'])."' LIMIT 1");
                        $q = $this->EE->db->query("SELECT email, screen_name, smart_notifications FROM exp_members WHERE member_id = '".$this->EE->db->escape_str($row['member_id'])."' LIMIT 1");
						if ($q->num_rows() > 0 && ($q->row('smart_notifications')=='n' || $row['notification_sent']=='n'))
						{
                            $recipients[] = array($q->row('email'), $com_q->row('comment_id'), $q->row('screen_name'), $row['member_id'], $row['subscription_id'], $row['hash']);
						}
					}
					elseif ($row['email'] != "" && $row['notification_sent']=='n')
					{
						//get comment related
                        $com_q = $this->EE->db->query("SELECT comment_id, name, email FROM exp_comments WHERE email = '".$this->EE->db->escape_str($row['email'])."' AND entry_id='".$this->EE->db->escape_str($_POST['entry_id'])."' LIMIT 1");
                        $recipients[] = array($row['email'], $com_q->row('comment_id'), $com_q->row('name'), $row['member_id'], $row['subscription_id'], $row['hash']);   
					}      
                    //set the 'sent' flag
                    $this->EE->db->query("UPDATE exp_comment_subscriptions SET notification_sent='y' WHERE subscription_id='".$row['subscription_id']."'");      
				}
			}
            //and to author...
            if ($channel_entry->row('comment_notify_authors') == 'y')
    		{
    			$q = $this->EE->db->query("SELECT member_id, screen_name, email FROM exp_members WHERE member_id = '".$this->EE->db->escape_str($channel_entry->row('author_id'))."'");
                $recipients[] = array($q->row('email'), $comment_id, $q->row('screen_name'), $channel_entry->row('author_id'), '0', '');  
    		}
            
            //send the notification

			if (count($recipients) > 0)
			{    
	
				$action_id  = $this->EE->functions->fetch_action_id('Comment_mcp', 'delete_comment_notification');
            
				$swap = array(
								'name_of_commenter'	=> $name,
								'channel_name'		=> $channel_entry->row('channel_title'),
								'entry_title'		=> $channel_entry->row('title'),
								'site_name'			=> stripslashes($this->EE->config->item('site_name')),
								'site_url'			=> $this->EE->config->item('site_url'),
								'comment_url'		=> preg_replace("#S=.+?/#", "", $_POST['RET']),
								'comment_id'		=> $comment_id,
								'comment'			=> $comment
							 );
				
				$template = $this->EE->functions->fetch_email_template('comment_notification');
				$email_tit = $this->EE->functions->var_swap($template['title'], $swap);
				$email_msg = $this->EE->functions->var_swap($template['data'], $swap);
				
				$sent = array();

				foreach ($recipients as $recipient)
				{
					if (!empty($recipient))
                    {
                        $cur_email = $recipient[0];
    					
    					if ($cur_email != $email && ! in_array($cur_email, $sent))
    					{
    						$email_tit	 = str_replace('{name_of_recipient}', $recipient[2], $email_tit);
    						$email_msg = str_replace('{name_of_recipient}', $recipient[2], $email_msg);
    					
    						$email_tit	 = str_replace('{notification_removal_url}', $this->EE->functions->fetch_site_index(0, 0).QUERY_MARKER.'ACT='.$action_id.'&id='.$recipient[4].'&hash='.$recipient[5], $email_tit);
    						$email_msg = str_replace('{notification_removal_url}', $this->EE->functions->fetch_site_index(0, 0).QUERY_MARKER.'ACT='.$action_id.'&id='.$recipient[4].'&hash='.$recipient[5], $email_msg);
                            
    						$this->EE->email->initialize();
            				$this->EE->email->wordwrap = false;
            				$this->EE->email->from($this->EE->config->item('webmaster_email'), $this->EE->config->item('webmaster_name'));	
            				$this->EE->email->to($cur_email); 
            				$this->EE->email->reply_to(($email == '') ? $this->EE->config->item('webmaster_email') : $email);
            				$this->EE->email->subject($email_tit);	
            				$this->EE->email->message(entities_to_ascii($email_msg));		
            				$this->EE->email->Send();
            				
            				$sent[] = $recipient[0];
    
    					}
                    }
				}            
			}

        }
        
        
        if ($comment_moderate == 'y')
        {
			$data = array(	'title' 	=> $this->EE->lang->line('cmt_comment_accepted'),
							'heading'	=> $this->EE->lang->line('thank_you'),
							'content'	=> $this->EE->lang->line('cmt_will_be_reviewed'),
							'redirect'	=> $_POST['RET'],							
							'link'		=> array($_POST['RET'], $this->EE->lang->line('cmt_return_to_comments')),
							'rate'		=> 3
						 );
					
			return $this->EE->output->show_message($data);
		}
		else
		{
        	$this->EE->functions->redirect($_POST['RET']);
    	}

    } 




    function forum_submit($obj, $data)
    {

        if (!isset($data['status']))
        {
            return false;
        }
        
        if ($obj->forum_metadata[$data['forum_id']]['forum_status']!='o')
        {
            return false;
        }
        
        @session_start();
        
        $site_id = $this->EE->config->item('site_id');
        
        //get the keys
        $this->EE->db->select('social_login_keys, social_login_permissions')
            ->from('members')
            ->where('member_id', $this->EE->session->userdata('member_id'));
        $q = $this->EE->db->get();
        if ($q->num_rows()==0 || $q->row('social_login_keys')=='')
        {
            return false;
        }
        $keys = unserialize($q->row('social_login_keys'));
        if ($q->row('social_login_permissions')!='')
        {
            $permissions = unserialize($q->row('social_login_permissions'));
            if (isset($permissions[$site_id]['forum_submit']) && $permissions[$site_id]['forum_submit']=='n')
            {
                return false;
            }
        }
        
        //template enabled?
        $this->EE->db->select('enable_template, template_data')
                        ->from('social_login_templates')
                        ->where('site_id', $site_id)
                        ->where('template_name', 'insert_comment_end')
                        ->limit(1);
        $q = $this->EE->db->get();
        if ($q->num_rows > 0)
        {
            if ($q->row('enable_template')=='n')
            {
                return false;
            }
            $tmpl = $q->row('template_data');
        }
        else
        {
            $tmpl = $this->EE->lang->line('insert_comment_end_tmpl');
        }

        //prepare the message
        $msg = str_replace(LD.'site_name'.RD, $this->EE->config->item('site_name'), trim($tmpl));
        $msg = str_replace(LD.'title'.RD, $data['title'], $msg);

        $basepath = $obj->preferences['board_forum_url'];
        $basepath = rtrim($basepath, '/').'/';
        
        $msg = str_replace(LD.'forum_name'.RD, $obj->forum_metadata[$data['forum_id']]['forum_name'], $msg);
        $msg = str_replace(LD.'forum_id'.RD, $data['forum_id'], $msg);
        $msg = str_replace(LD.'board_name'.RD, $obj->preferences['board_name'], $msg);
        $msg = str_replace(LD.'board_id'.RD, $data['board_id'], $msg);
        $msg = str_replace(LD.'permalink'.RD, $basepath.'viewthread/'.$data['topic_id'], $msg);
        
        $this->_post($msg, $keys);
        
    } 




    
    
    //trims the string to be exactly of less of the given length
    //the integrity of words is kept 
    function _char_limit($str, $length, $minword = 3)
    {
        $sub = '';
        $len = 0;
       
        foreach (explode(' ', $str) as $word)
        {
            $part = (($sub != '') ? ' ' : '') . $word;
            $sub .= $part;
            $len += strlen($part);
           
            if (strlen($word) > $minword && strlen($sub) >= $length)
            {
                break;
            }
        }
       
        return $sub . (($len < strlen($str)) ? '...' : '');

    }
    
    
    
    function _post($msg_orig, $keys)
    {
        $site_id = $this->EE->config->item('site_id');

        foreach ($this->providers as $provider)
        {
            if (!isset($keys["$provider"]['oauth_token']) || $keys["$provider"]['oauth_token']=='')
            {
                continue;
            }
            if ($this->settings[$site_id][$provider]['app_id']=='' || $this->settings[$site_id][$provider]['app_secret']=='' || $this->settings[$site_id][$provider]['custom_field']=='')
            {
                continue;
            }

            if (!isset($this->settings[$site_id][$provider]['enable_posts']) || $this->settings[$site_id][$provider]['enable_posts']=='y')
            {
                $msg = $msg_orig;
                if (strlen($msg)>$this->maxlen[$provider])
                {
                    if ( ! class_exists('Shorteen'))
                	{
                		require_once PATH_THIRD.'shorteen/mod.shorteen.php';
                	}
                	
                	$SHORTEEN = new Shorteen();
                    
                    preg_match_all('/https?:\/\/[^:\/\s]{3,}(:\d{1,5})?(\/[^\?\s]*)?([\?#][^\s]*)?/i', $msg, $matches);

                    foreach ($matches as $match)
                    {
                        if (!empty($match) && strpos($match[0], 'http')===0)
                        {
                            //truncate urls
                            $longurl = $match[0];
                            if (strlen($longurl)>$this->max_link_length)
                            {
                                $shorturl = $SHORTEEN->process($this->settings[$site_id]['url_shortening_service'], $longurl, true);
                                if ($shorturl!='')
                                {
                                    $msg = str_replace($longurl, $shorturl, $msg);
                                }
                            }
                        }
                    }
                }
                //still too long? truncate the message
                //at least one URL should always be included
                if (strlen($msg)>$this->maxlen[$provider])
                {
                    if ($shorturl!='')
                    {
                        $len = $this->maxlen[$provider] - strlen($shorturl) - 1;
                        $msg = $this->_char_limit($msg, $len);
                        $msg .= ' '.$shorturl;
                    }
                    else
                    {
                        $msg = $this->_char_limit($msg, $this->maxlen[$provider]);
                    }
                }
                
                //all is ready! post the message
                $lib = $provider.'_oauth';
                $params = array('key'=>$this->settings[$site_id]["$provider"]['app_id'], 'secret'=>$this->settings[$site_id]["$provider"]['app_secret']);
                $this->EE->load->library($lib, $params);
                if ($provider=='yahoo')
                {
                    $this->EE->$lib->post($msg, $keys["$provider"]['oauth_token'], $keys["$provider"]['oauth_token_secret'], array('guid'=>$keys["$provider"]['guid']));
                }
                else
                {
                    $this->EE->$lib->post($msg, $keys["$provider"]['oauth_token'], $keys["$provider"]['oauth_token_secret']);    
                }
            }
        }
    }


  

}
// END CLASS
