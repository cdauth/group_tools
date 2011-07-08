<?php 

	global $GROUP_TOOLS_PAGE_HANDLER_BACKUP;
	$GROUP_TOOLS_PAGE_HANDLER_BACKUP = array();

	function group_tools_extend_page_handler($handler, $function){
		global $GROUP_TOOLS_PAGE_HANDLER_BACKUP;
		global $CONFIG;
		
		$result = false;
		
		if(!empty($handler) && !empty($function) && is_callable($function)){
			if(isset($CONFIG->pagehandler) && array_key_exists($handler, $CONFIG->pagehandler)){
				// backup original page handler
				$GROUP_TOOLS_PAGE_HANDLER_BACKUP[$handler] = $CONFIG->pagehandler[$handler];
				// register new handler
				register_page_handler($handler, $function);
				$result = true;
			} else {
				register_page_handler($handler, $function);
				$result = true;
			}
		}
		
		return $result;
	}
	
	function group_tools_fallback_page_handler($page, $handler){
		global $GROUP_TOOLS_PAGE_HANDLER_BACKUP;
		
		$result = false;
		
		if(!empty($handler)){
			if(array_key_exists($handler, $GROUP_TOOLS_PAGE_HANDLER_BACKUP)){
				if(is_callable($GROUP_TOOLS_PAGE_HANDLER_BACKUP[$handler])){
					$function = $GROUP_TOOLS_PAGE_HANDLER_BACKUP[$handler];
					
					$result = $function($page, $handler);
					
					if($result !== false){
						$result = true;
					}
				}
			}
		}
		
		return $result;
	}

	function group_tools_groups_page_handler($page, $handler){
		
		switch($page[0]){
			case "mail":
				if(!empty($page[1])){
					set_input("group_guid", $page[1]);
					include(dirname(dirname(__FILE__)) . "/pages/mail.php");
					break;
				}
			case "invite":
				if(!empty($page[1])){
					set_input("group_guid", $page[1]);
					include(dirname(dirname(__FILE__)) . "/pages/groups/invite.php");
					break;
				}
			case "membershipreq":
				if(!empty($page[1])){
					set_input("group_guid", $page[1]);
					include(dirname(dirname(__FILE__)) . "/pages/groups/membershipreq.php");
					break;
				}
			case "group_invite_autocomplete":	
				include(dirname(dirname(__FILE__)) . "/procedures/group_invite_autocomplete.php");
				break;
			case "all":
				include(dirname(dirname(__FILE__)) . "/pages/groups/all.php");
				break;
			default:
				return group_tools_fallback_page_handler($page, $handler);
				break;
		}
	}
	
	function group_tools_replace_submenu(){
		global $CONFIG;
		
		$page_owner = page_owner_entity();
		
		$titles = array(
			elgg_echo("groups:invite") => elgg_echo("group_tools:groups:invite")
		);
		
		$urls = array();
		
		$remove = array(
			elgg_echo("groups:membershiprequests") => $CONFIG->wwwroot . "mod/groups/membershipreq.php?group_guid=" . $page_owner->getGUID()
		);
		
		if(!empty($CONFIG->submenu)){
			$submenu = $CONFIG->submenu;
			
			foreach($submenu as $group => $items){
				if(!empty($items)){
					foreach($items as $index => $item){
						// replace menu titles
						if(array_key_exists($item->name, $titles)){
							$submenu[$group][$index]->name = $titles[$item->name];
						}
						
						// replace urls
						if(array_key_exists($item->name, $urls)){
							$submenu[$group][$index]->value = $urls[$item->name];
						}
						
						// remove items
						if(array_key_exists($item->name, $remove) && ($item->value == $remove[$item->name])){
							unset($submenu[$group][$index]);
						}
					}
				} else {
					unset($submenu[$group]);
				}
			}
			
			$CONFIG->submenu = $submenu;
			
		}
	}
	
	function group_tools_check_group_email_invitation($invite_code, $group_guid = 0){
		$result = false;
		
		if(!empty($invite_code)){
			$options = array(
				"type" => "group",
				"limit" => 1,
				"site_guids" => false,
				"annotation_name_value_pairs" => array("email_invitation" => $invite_code)
			);
			
			if(!empty($group_guid)){
				$options["annotation_owner_guids"] = array($group_guid);
			}
			
			if($groups = elgg_get_entities_from_annotations($options)){
				$result = $groups[0];
			}
		}
		
		return $result;
	}
	
	function group_tools_invite_user(ElggGroup $group, ElggUser $user, $text = ""){
		global $CONFIG;
		
		$result = false;
		
		if(!empty($user) && ($user instanceof ElggUser) && !empty($group) && ($group instanceof ElggGroup) && ($loggedin_user = get_loggedin_user())){
			// Create relationship
			add_entity_relationship($group->getGUID(), "invited", $user->getGUID());
			
			// Send email
			$url = $CONFIG->url . "pg/groups/invitations/" . $user->username;
				
			$subject = sprintf(elgg_echo("groups:invite:subject"), $user->name, $group->name);
			$msg = sprintf(elgg_echo("group_tools:groups:invite:body"), $user->name, $loggedin_user->name, $group->name, $text, $url);
			
			if(notify_user($user->getGUID(), $group->getOwner(), $subject, $msg)){
				$result = true;
			}
		}
		
		return $result;
	}
	
	function group_tools_add_user(ElggGroup $group, ElggUser $user, $text = ""){
		$result = false;
		
		if(!empty($user) && ($user instanceof ElggUser) && !empty($group) && ($group instanceof ElggGroup) && ($loggedin_user = get_loggedin_user())){
			if($group->join($user)){
				// Remove any invite or join request flags
				remove_entity_relationship($group->getGUID(), "invited", $user->getGUID());
				remove_entity_relationship($user->getGUID(), "membership_request", $group->getGUID());
					
				// notify user
				$subject = sprintf(elgg_echo("group_tools:groups:invite:add:subject"), $group->name);
				$msg = sprintf(elgg_echo("group_tools:groups:invite:add:body"), $user->name, $loggedin_user->name, $group->name, $text, $group->getURL());
					
				if(notify_user($user->getGUID(), $group->getOwner(), $subject, $msg)){
					$result = true;
				}
			}
		}
		
		return $result;
	}
	
	function group_tools_invite_email(ElggGroup $group, $email, $text = ""){
		global $CONFIG;
		
		$result = false;

		if(!empty($group) && ($group instanceof ElggGroup) && !empty($email) && is_email_address($email) && ($loggedin_user = get_loggedin_user())){
			// get site secret
			$site_secret = get_site_secret();
			
			// generate invite code
			$invite_code = md5($site_secret . $email . $group->getGUID());
			
			if(!group_tools_check_group_email_invitation($invite_code, $group->getGUID())){
				// make site email
				if(!empty($CONFIG->site->email)){
					if(!empty($CONFIG->site->name)){
						$site_from = $CONFIG->site->name . " <" . $CONFIG->site->email . ">";
					} else {
						$site_from = $CONFIG->site->email;
					}
				} else {
					// no site email, so make one up
					if(!empty($CONFIG->site->name)){
						$site_from = $CONFIG->site->name . " <noreply@" . get_site_domain($CONFIG->site_guid) . ">";
					} else {
						$site_from = "noreply@" . get_site_domain($CONFIG->site_guid);
					}
				}
				
				// register invite with group
				$group->annotate("email_invitation", $invite_code, ACCESS_LOGGED_IN, $group->getGUID());
				
				// make subject
				$subject = sprintf(elgg_echo("group_tools:groups:invite:email:subject"), $group->name);
				
				// make body
				$body = sprintf(elgg_echo("group_tools:groups:invite:email:body"),
					$loggedin_user->name,
					$group->name,
					$CONFIG->site->name,
					$text,
					$CONFIG->site->name,
					$CONFIG->wwwroot . "pg/register",
					$CONFIG->wwwroot . "pg/groups/invitations/?invitecode=" . $invite_code,
					$invite_code);
				
				if(is_plugin_enabled("html_email_handler") && (get_plugin_setting("notifications", "html_email_handler") == "yes")){
					// generate HTML mail body
					$html_message = elgg_view("html_email_handler/notification/body", array("title" => $subject, "message" => parse_urls($body)));
					if(defined("XML_DOCUMENT_NODE")){
						if($transform = html_email_handler_css_inliner($html_message)){
							$html_message = $transform;
						}
					}
				
					// set options for sending
					$options = array(
						"to" => $email,
						"from" => $site_from,
						"subject" => $subject,
						"html_message" => $html_message,
						"plaintext_message" => $body
					);
					
					if(html_email_handler_send_email($options)){
						$result = true;
					}
				} else {
					// use plaintext mail
					$headers = "From: " . $site_from . PHP_EOL;
					$headers .= "X-Mailer: PHP/" . phpversion() . PHP_EOL;
					$headers .= "Content-Type: text/plain; charset=\"utf-8\"" . PHP_EOL;
						
					if(mail($email, $subject, $body, $headers)){
						$result = true;
					}
				}
			} else {
				$result = null;
			}
		}
		
		return $result;
	}

?>