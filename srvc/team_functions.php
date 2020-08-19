<?php
require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/../srv/config/app_config.php";
require_once __DIR__ . "/../srv/config/db_config.php";

require_once __DIR__ . '/doc_functions.php';
require_once __DIR__ . '/blob_api.php';

setlocale(LC_ALL, 'en_US.UTF8');

function logInvitation($conn, $email_from, $email_to, $msg)
{               
    $status = true;
    $error = "";
    $result = "";
	
	if (!($stmp = mysqli_prepare($conn, "CALL prc_LogInvitation(?, ?, ?)")))
	{
		$status = false;
		$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
	}
    else
    {
		mysqli_stmt_bind_param($stmp, 'sss', $email_from, $email_to, $msg);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
		}
	}
	
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function ensureUserExists($conn, $email)
{
    $status = true;
    $error = "";
    $result = "";
	
	$innerResult = getUserByEmail($conn, $email);
	
	if ($innerResult['status'] == true)
	{
		$result = $innerResult['data'];
	}
	else
	{
		$guid = bin2hex(openssl_random_pseudo_bytes(16));

		$password = $guid;
		
		$innerResult = upsertUser($conn, -1, '', $email, $password);
		$innerResult = getUserByEmail($conn, $email);
		
		$error = $innerResult['error'];
		$status = $innerResult['status'];
		$result = $innerResult['data'];
	}

	
	end:
	
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}


function getUsersInSecurityGroups($conn, $groups_list)
{
    $status = true;
    $error = "";
    $result = "";
	
	$pieces = explode(";", $groups_list);

	$result = array();
	for ($i = 0; $i < count($pieces); $i++)
	{
		$piece = $pieces[$i];
		if (strlen($piece) <= 0)
			continue;
		
		$inner_result = getUsersBySecurityGroup($conn, $piece);
		if ($inner_result['status'] != true)
			continue;
		
		{
			for ($j = 0; $j < count($inner_result['data']); $j++)
				array_push($result, $inner_result['data'][$j]);
		}
	}

	
	end:
	
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function getUsersBySecurityGroup($conn, $security_group_id)
{               
    $status = true;
    $error = "";
    $result = "";
	
	if (!($stmp = mysqli_prepare($conn, "CALL prc_GetUsersBySecurityGroup(?)")))
	{
		$status = false;
		$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
		goto end;
	}
    else
    {
		mysqli_stmt_bind_param($stmp, 'i', $security_group_id);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		else
		{
			$stmp->bind_result($id, $email, $security_group_id, $security_group_name, $primary, $is_admin);
			
			$result = array();
			
			while ($stmp->fetch())
			{
			  $result[] = array(
				'user_id' => $id,
				'user_email' => $email,
				'security_group_id' => $security_group_id,
				'security_group_name' => $security_group_name,
				'primary' => $primary,
				'is_admin' => $is_admin
				);
			}
		}
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);

    return $out;
}


function upsertUser($conn, $user_id, $name, $email, $password)
{               
    $status = true;
    $error = "";
    $result = "";
	
	if (!($stmp = mysqli_prepare($conn, "CALL prc_UpsertUser(?, ?, ?, ?)")))
	{
		$status = false;
		$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
		goto end;
	}
    else
    {
		mysqli_stmt_bind_param($stmp, 'isss', $user_id, $name, $email, $password);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		else
		{
			$stmp->bind_result($id);
			while ($stmp->fetch())
			{
			  $result = array(
				'user_id' => $id,
				'user_email' => $email,
				'user_name' => $name
			  );
			}

			if ($result['user_id'] == -1)
			{
				$status = true;
				$result = array(
					'user_id' => -1,
					'exists' => 1,
					'msg' => 'User already exists'
				);
			}
			
			goto end;
		}
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);

    return $out;
}

function getUser($conn, $email, $password)
{               
    $status = true;
    $error = "";
    $result = "";
	
	if (!($stmp = mysqli_prepare($conn, "CALL prc_GetUser(?, ?)")))
	{
		$status = false;
		$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
		goto end;
	}
	else
	{   
		mysqli_stmt_bind_param($stmp, 'ss', $email, $password);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		else
		{
			$stmp->bind_result($id, $name, $email, $password, $registation_date, $require_password_reset, $is_active, $is_active_provider);
			while ($stmp->fetch())
			{
			  $result = array(
				'user_id' => $id,
				'user_email' => $email,
				'user_name' => $name,
				'user_isactive' => $is_active,
				'is_active_provider' => $is_active_provider
				);
			}
			
			if ($result == "" || $result['user_isactive'] == 0)
			{
			  $status = true;
			  $result = array(
				'user_id' => -1,
				'user_email' => '',
				'user_name' => '',
				'user_isactive' => 0,
				'is_active_provider' => 0
				);
			}
			
			goto end;
		}
	}    
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);

    return $out;
}

function getUserPrimarySecurityGroup($conn, $userId)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetUserPrimarySecurityGroup(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'i', $userId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($id, $name, $isPrimary);
						
            while ($stmp->fetch())
            {
              $result = array(
                'id' => $id,
				'name' => $name,
				'primary' => $isPrimary,
                );
            }
			            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function hardDeleteUser($conn, $user_id, $password)
{
    return deleteUser($conn, $user_id, $password, true);
}

function softDeleteUser($conn, $user_id, $password)
{
    return deleteUser($conn, $user_id, $password, false);
}

function deleteUser($conn, $user_id, $password, $isHardDelete)
{
    $status = true;
    $error = "";
    $result = "";
    
	if (!($stmp = mysqli_prepare($conn, $isHardDelete ? "CALL prc_HardDeleteUser(?,?)" : "CALL prc_SoftDeleteUser(?,?)")))
	{
		$status = false;
		$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
		goto end;
	}
	else
	{   
		mysqli_stmt_bind_param($stmp, 'is', $user_id, $password);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array('status' => $retVal);
            }
			
			goto end;
		}
	}    
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);

    return $out;
}

function updateUserAccountActivation($conn, $email, $is_provider_activation, $cancel)
{
    $status = true;
    $error = "";
    $result = "";
	
    if (!($stmp = mysqli_prepare($conn, "CALL prc_UpdateAccountActivation(?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {          
        mysqli_stmt_bind_param($stmp, 'sii', $email, $is_provider_activation, $cancel);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($id, $email, $name);
            while ($stmp->fetch())
            {
              $result = array(
                'user_id' => $id,
                'user_email' => $email,
                'user_name' => $name
              );
              
            }

            if ($result == "")
            {
                $status = true;
                $result = array(
                    'user_id' => -1,
					'user_email' => '',
					'user_name' => ''
                );
            }
            
            goto end;
        }
    }    
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);

    return $out;
}


function createGroup($conn, $userId, $groupName)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_CreateGroup(?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'is', $userId, $groupName);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else 
        {
            $stmp->bind_result($id);
            while ($stmp->fetch())
            {
              $result = array(
                'groupId' => $id
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['groupId'] == -1)
			{
				$status = false;
				$error = "The user creating the group does not exists.";
			}
            else if ($result['groupId'] == -2)
			{
				$status = false;
				$error = "A security group with this name already exists.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function deleteGroup($conn, $userId, $groupName)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_DeleteGroup(?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'si', $groupName, $userId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array(
                'retVal' => $retVal
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['retVal'] == -1)
			{
				$status = false;
				$error = "You don't have permissions to delete the group.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function renameGroup($conn, $userId, $groupName, $newGroupName)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_RenameGroup(?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'ssi', $groupName, $newGroupName, $userId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array(
                'retVal' => $retVal
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['retVal'] == -1)
			{
				$status = false;
				$error = "You don't have permissions to rename the group.";
			}
            else if ($result['retVal'] == -2)
			{
				$status = false;
				$error = "A group with this name already exists.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function addUserToGroup($conn, $userId, $groupName, $adminFlag, $requestorUserId)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_AddUserToGroup(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'isii', $userId, $groupName, $adminFlag, $requestorUserId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array(
                'retVal' => $retVal
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['retVal'] == -1)
			{
				$status = false;
				$error = "You don't have permissions to add users the group.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function deleteUserFromGroup($conn, $userId, $groupName, $requestorUserId)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_DeleteUserFromGroup(?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'isi', $userId, $groupName, $requestorUserId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array(
                'retVal' => $retVal
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['retVal'] == -1)
			{
				$status = false;
				$error = "You don't have permissions to remove users from the group.";
			}
            else if ($result['retVal'] == -2)
			{
				$status = false;
				$error = "The user cannot be removed because the group will have no administrators.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function setUserGroupAdminFlag($conn, $userId, $groupName, $adminFlag, $requestorUserId)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_setUserGroupAdminFlag(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'isii', $userId, $groupName, $adminFlag, $requestorUserId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retVal);
            while ($stmp->fetch())
            {
              $result = array(
                'retVal' => $retVal
                );
            }
			
			if ($result == "")
            {
                $status = false;
				$error = "Internal error";
            }
            else if ($result['retVal'] == -1)
			{
				$status = false;
				$error = "You don't have permissions to change admin permissions for the group.";
			}
            else if ($result['retVal'] == -2)
			{
				$status = false;
				$error = "The user permissions cannot be changed because the group will have no administrators.";
			}
            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function getGroupMembers($conn, $userId, $groupName)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetGroupMembers(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 's', $groupName);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($id, $name, $email, $isActive, $isAdmin);
			
			$result = array();
			
            while ($stmp->fetch())
            {
              $result[] = array(
                'id' => $id,
				'name' => $name,
				'email' => $email,
				'isActive' => $isActive,
				'isAdmin' => $isAdmin,
                );
            }
			            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function getUserGroups($conn, $userId)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetUserGroups(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'i', $userId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($id, $name, $isAdmin);
			
			$result = array();
			
            while ($stmp->fetch())
            {
              $result[] = array(
                'id' => $id,
				'name' => $name,
				'isAdmin' => $isAdmin,
                );
            }
			            
            goto end;
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function getUserByEmail($conn, $email)
{               
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetUserByEmail(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
		mysqli_stmt_bind_param($stmp, 's', $email);
		
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		else
		{
			$result = "";
			$stmp->bind_result($id, $name, $email, $password, $registation_date, $require_password_reset, $is_active, $is_active_provider);
			while ($stmp->fetch())
			{
				$result = array(
				'user_id' => $id,
				'user_email' => $email,
				'user_name' => $name,
				'user_isactive' => $is_active,
				'is_active_provider' => $is_active_provider
				);
			}
			
			if ($result == "")
			{
				$status = false;
				$error = "User does not exist.";
			}
		}
            
        goto end;
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function login_user_to_workspaces($conn, $userId, $account_id)
{
	$status = true;
	$error = '';

	try
	{
		unset($_SESSION['user_account_id']);
		unset($_SESSION['workspace_tenants']);
		
		// get the user's security groups
		$sg_result = getUserPrimarySecurityGroup($conn, $userId);
		$sg_innerResult = $sg_result['data'];
		$user1_primariy_group_id = $sg_innerResult['id'];
			
		// authenticate with each of the workspace servers
		$inner_result = get_workspace_tenants($conn, $userId, $account_id);
		if ($inner_result["status"] == false)
		{
			$status = false;
			$error = $inner_result['error'];
			goto end;
		}
		
		$workspace_tenants = $inner_result["data"];
		
		$_SESSION['user_account_id'] = $account_id;
		$_SESSION['workspace_tenants'] = $workspace_tenants;
		
		for ($i = 0; $i < count($workspace_tenants); $i++)
		{
			$ws_tenant = $workspace_tenants[$i];
			
			$node_credentials = json_decode($ws_tenant["node_credentials"], true);
			
			$db_name = $ws_tenant["type"] . "-" . $ws_tenant["tenant_uuid"];
			
			$ws_mysqli = db_connect($node_credentials["hostname"], $node_credentials["username"], $node_credentials["password"], $db_name, $node_credentials["port"]);
			
			clearUserSecurityGroupsCache($ws_mysqli, $userId);
			
			setUserSecurityGroupsCache($ws_mysqli, $userId, strval($user1_primariy_group_id), 1);
			
			db_disconnect($ws_mysqli);
		}
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$result = "";
	}

	end:

	$out = array('data' => '', 'status' => $status, 'error' => $error);

	return $out;
}

function set_user_account($mysqli, $user_id, $selected_account_id)
{
	$status = true;
	$error = '';
	
	$account_id = -1;

	$inner_result = get_user_accounts($mysqli, $user_id);
	if ($selected_account_id != -1 && $inner_result['status'] != false && count($inner_result['data']) > 0)
	{
		for ($i = 0; $i < count($inner_result['data']); $i++)
		{
			if ($inner_result['data'][$i]['id'] == $selected_account_id)
				break;
		}

		if ($i == count($inner_result['data']))
		{
			$status = false;
			$error = "Invaid selected account id " . $selected_account_id;
			goto end;
		}

		$account_data = $inner_result['data'][$i];
		$account_id = $account_data['id'];		
	}

	$inner_result = login_user_to_workspaces($mysqli, $user_id, $account_id);

	if ($inner_result['status'] == false)
	{
		$status = false;
		$error = $inner_result['error'];//"Failed to login user into the workspaces of the selected account";
		goto end;
	}

	end:

	$out = array('data' => "", 'status' => $status, 'error' => $error);

	return $out;

}

function login_user($mysqli, $user_data)
{
	$status = true;
	$error = "";

	$account_id = -1;

	$inner_result = get_user_accounts($mysqli, $user_data['user_id']);
	if ($inner_result['status'] != false && count($inner_result['data']) > 0)
	{
		// user must choose account in the UI
	}
	else
	{
		$inner_result = set_user_account($mysqli, $user_data['user_id'], $account_id /* -1 */);
		
		$status = $inner_result['status'];
		$error = $inner_result['error'];
		if ($status == false)
			goto end;
	}

	// set the session data
	$_SESSION['user_id'] = $user_data['user_id'];
	$_SESSION['user_email'] = $user_data['user_email'];
    $_SESSION['user_name'] = $user_data['user_name'];
    $_SESSION['user_account_id'] = $user_data['user_account_id'] = $account_id;

	// set the cookie and return
	global $cryptKey, $salt;

	setcookie('current_user', encryptString(json_encode($user_data), $cryptKey, $salt), time() + (86400), "/"); // 86400 = 1 day

	end:

	$tenants = get_workspace_tenants($mysqli, 254, -1);

	$out = array('data' => "", 'status' => $status, 'error' => $error, 'tenants' => $tenants);

	return $out;
}

?>