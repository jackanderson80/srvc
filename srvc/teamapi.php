<?php

include('../srv/config/app_config.php');
include('../srv/config/db_config.php');
include('team_functions.php');

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/mail.php';


$cmd = $_POST['cmd'];

session_start();

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $everyone_user_id;
$readonly_access =  $user_id == $everyone_user_id;
    
try
{
    $output = array('data' => '', 'status' => true, 'error' => '');
    
    $mysqli = db_connect($users_db_hostname, $users_db_username, $users_db_password, $users_db_database);
        
	///////////////////////////////////////////////////////////////////////
	// Team APIs
	///////////////////////////////////////////////////////////////////////
	if ($cmd == 'sendInvitationToUser')
	{
		// allow only logged in users ro can call this
		if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_name']))
		{		
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$invite_user_email = $_POST['invite_user_email'];
			$description = $_POST['description'];
			
			$inner_result = ensureUserExists($mysqli, $invite_user_email);
			if ($inner_result['status'] != true)
			{
				$output = array('data' => $inner_result['data'], 'status' => false, 'error' => "Internal error. User does not exist.");
				goto end;
			}
				
			$inner_result = getUserByEmail($mysqli, $invite_user_email);
			if ($inner_result['status'] != true)
			{
				$output = array('data' => $inner_result['data'], 'status' => false, 'error' => "Internal error. Error retrieving user data.");
				goto end;
			}

			$reqestorUserName = $_SESSION['user_name'];
			$reqestorEmail = $_SESSION['user_email'];
			$invite_user_name = $inner_result['data']['user_name'];
			
			$to = $invite_user_email;
			$subject = "Your " . $productName . " collaboration request";
			
			
			$message = "Hello";
			if (strlen($invite_user_name) != 0)
				$message = "Hi " . $invite_user_name;
			
			$message = $message . ",\r\n\r\n" . $reqestorUserName . " invited you to collaborate on " . $description . ".\r\n\r\n" . "Get started by logging into your workspace at " . $baseUrl . "";
			$message = $message . "\r\n\r\nThank you,\r\n" . $teamSignature . "\r\n" . $teamSignatureUrl;

			try
			{
				sendEmail($to, $subject, $message);
				logInvitation($mysqli, $reqestorEmail, $invite_user_email, $message);
			}
			catch(Exception $e)
			{
				$output = array('data' => "", 'status' => false, 'error' => $e);
				goto end;
			}
			
			$output = array('data' => $message, 'status' => true, 'error' => "");
		}
	}
	
	else if ($cmd == 'ensureUserExists')
	{
		// allow only logged in users ro can call this
		if (!isset($_SESSION['user_id']))
		{		
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$email = $_POST['email'];
			
			$inner_result = ensureUserExists($mysqli, $email);
			
			$status = $inner_result["status"];
			$result = $inner_result["data"];
			$error = $inner_result["error"];
			
			$output = array('data' => $result, 'status' => $status, 'error' => $error);
		}
	}
	
    else if ($cmd == "getUsersInSecurityGroups")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$groups_list = $_POST['groups_list'];
			$output = getUsersInSecurityGroups($mysqli, $groups_list);
		}
    }

    else if ($cmd == "createGroup")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$groupName = $_POST['name'];
			$output = createGroup($mysqli, $user_id, $groupName);
		}
    }
    else if ($cmd == "deleteGroup")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$groupName = $_POST['name'];
			$output = deleteGroup($mysqli, $user_id, $groupName);
		}
    }
    else if ($cmd == "renameGroup")
    {
		$groupName = $_POST['name'];
		$newGroupName = $_POST['newname'];
        $output = renameGroup($mysqli, $user_id, $groupName, $newGroupName);
    }    
    else if ($cmd == "addUserToGroup")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$output = getUserByEmail($mysqli, $_POST['email']);
			if ($output['status'] == true)
			{
				$userid_to_add = $output['data']['user_id'];
				if ($userid_to_add != -1)
				{			
					$groupName = $_POST['name'];
					$is_admin = $_POST['isadmin'];
					$output = addUserToGroup($mysqli, $userid_to_add, $groupName, $is_admin, $user_id);
				}
			}
		}
    }    
    else if ($cmd == "deleteUserFromGroup")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$output = getUserByEmail($mysqli, $_POST['email']);
			if ($output['status'] == true)
			{
				$userid_to_remove = $output['data']['user_id'];
				if ($userid_to_remove != -1)
				{			
					$groupName = $_POST['name'];
					$output = deleteUserFromGroup($mysqli, $userid_to_remove, $groupName, $user_id);
				}
			}
		}
    }    
    else if ($cmd == "setUserGroupAdminFlag")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => 'Error', 'error' => 'Permission denied');
		}
		else
		{
			$output = getUserByEmail($mysqli, $_POST['email']);
			if ($output['status'] == true)
			{
				$userid_to_modify = $output['data']['user_id'];
				if ($userid_to_modify == $user_id)
				{
					$output = array('data' => '', 'status' => false, 'error' => 'Members cannot modify their own access');
				}
				else if ($userid_to_modify != -1)
				{			
					$groupName = $_POST['name'];
					$is_admin = $_POST['isadmin'];
					$output = setUserGroupAdminFlag($mysqli, $userid_to_modify, $groupName, $is_admin, $user_id);
				}
			}
		}
    }    
	else if ($cmd == "getGroupMembers")
    {
		$groupName = $_POST['name'];
        $output = getGroupMembers($mysqli, $user_id, $groupName);
    }
	else if ($cmd == "getUserGroups")
    {
        $output = getUserGroups($mysqli, $user_id);
    }
    
	///////////////////////////////////////////////////////////////////////
end:

    db_disconnect($mysqli);

}
catch(Exception $e)
{
    $error = 'exception: ' + $e;
    $output = array('status' => false, 'error' => $error, 'data' => "");
}

echo json_encode($output);

?>