<?php
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/team_functions.php';
require_once __DIR__ . '/encrypt.php';

require_once __DIR__ . "/../srv/config/app_config.php";
require_once __DIR__ . "/../srv/config/db_config.php";

require_once __DIR__ . '/../srv/resourcemanager/resourcemanager.php';

function _free_result($sqlConnection)
{
	while ($sqlConnection && mysqli_more_results($sqlConnection) && mysqli_next_result($sqlConnection)) {

		$dummyResult = mysqli_use_result($sqlConnection);

		if ($dummyResult instanceof mysqli_result) {
			mysqli_free_result($sqlConnection);
		}
	}
}        

function connect_RM_DB()
{
	global $rm_db_hostname, $rm_db_username, $rm_db_password, $rm_db_database, $rm_db_port;
	
	$conn = db_connect($rm_db_hostname, $rm_db_username, $rm_db_password, $rm_db_database, $rm_db_port);
	
	return $conn;
}

function _assign_tenant_for_new_account($conn, $account_primary_security_group_id)
{
	$rmconn = connect_RM_DB();
	if (!$rmconn)
	{
		$out = array('data' => "", 'status' => false, 'error' => "Failed to connect to resource manager");
		return $out;
	}
	
	$resourceManager = new ResourceManager($rmconn);

    $result = $resourceManager->findFreeTenant("ws-community");
	db_disconnect($rmconn);
	
	if ($result["status"] == false)
	{
		return $result;
	}
	
	$tenant_uuid = $result["data"]["uuid"];
		
	$inner_result = add_user_group_to_tenant($conn, $account_primary_security_group_id, $tenant_uuid, 1);
	
	return $inner_result;
}

function assign_tenant_for_new_user($conn, $user_group_id)
{
	$rmconn = connect_RM_DB();
	if (!$rmconn)
	{
		$out = array('data' => "", 'status' => false, 'error' => "Failed to connect to resource manager");
		return $out;
	}
	
	$resourceManager = new ResourceManager($rmconn);

    $result = $resourceManager->findFreeTenant("workspacetenant");
	db_disconnect($rmconn);
	
	if ($result["status"] == false)
	{
		return $result;
	}
	
	$tenant_uuid = $result["data"]["tenant_uuid"];
		
	$inner_result = add_user_group_to_tenant($conn, $user_group_id, $tenant_uuid, 1);
	
	return $inner_result;
}

function add_user_group_to_tenant($conn, $group_id, $tenant_uuid, $is_primary)
{
	$status = true;
	$error = "";
	$result = "";

	$procName = "prc_AddGroupTenant";
	$statement = "CALL " . $procName . "(?, ?, ?)";
	
	try
	{                   
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'isi', $group_id, $tenant_uuid, $is_primary);
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$result = "";
	}

	end:

	$out = array('data' => $result, 'status' => $status, 'error' => $error);

	return $out;
}

function get_user_tenants($conn, $userId)
{
	$status = true;
	$error = "";

	$procName = "prc_GetUserTenants";
	$statement = "CALL " . $procName . "(?)";
	
	$user_tenants = [];

	try
	{                   
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'i', $userId);
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($security_group_id, $tenant_uuid, $is_primary);
		
		while ($stmp->fetch())
		{
			$record = array(
				"security_group_id" => $security_group_id,
				"tenant_uuid" => $tenant_uuid,
				"is_primary" => $is_primary
			);

			array_push($user_tenants, $record);
		}
		
		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$user_tenants = [];
	}
	
	end:

	$out = array('data' => $user_tenants, 'status' => $status, 'error' => $error);

	return $out;
}


function get_workspace_tenants($conn, $userId, $account_id)
{
	$status = true;
	$error = "";
	$result = "";

	$inner_result =	($account_id == -1) ? get_user_tenants($conn, $userId) : get_account_tenants($conn, $account_id);

	if ($inner_result["status"] == false)
	{
		return $inner_result;	
	}

	$user_tenants = $inner_result["data"];
	
	if (count($user_tenants) == 0)
	{		
		// get the user's security groups
		$sg_result = getUserPrimarySecurityGroup($conn, $userId);
		if ($sg_result["status"] == false || $sg_result["data"] == "" || count($sg_result["data"]) == 0)
		{
			$status = false;
			$error = "Cannot get user's primary security group: " . $sg_result["error"];
			goto end;
		}
		
		$user_primariy_group_id = $sg_result['data']['id'];

		$inner_result = assign_tenant_for_new_user($conn, $user_primariy_group_id);
		if ($inner_result["status"] == false)
		{
			$status = false;
			$error = "Cannot assign tenant to new user: " . $inner_result["error"];
			goto end;
		}
		
		$inner_result =	get_user_tenants($conn, $userId);
		if ($inner_result["status"] == false)
		{
			return $inner_result;	
		}
		$user_tenants = $inner_result["data"];
	}
	
	
	$tenants = [];
	try
	{
		$rmconn = connect_RM_DB();
		if (!$rmconn)
		{
			$status = false;
			$error = "Cannot connect to resource manager";
			goto end;
		}
		
		$resourceManager = new ResourceManager($rmconn);
		$inner_result = $resourceManager->getTenants();
		db_disconnect($rmconn);
		
		if ($inner_result["status"] == false)
		{
			$status = false;
			$error = "Cannot get tenants from resource manager";
			goto end;
		}
		
		$tenants = $inner_result["data"];
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$result = "";
	}
	
	$result = [];
	for ($i = 0; $i < count($user_tenants); $i++)
	{
		$tenant_uuid = $user_tenants[$i]["tenant_uuid"];
		if (isset($tenants[$tenant_uuid]))
		{
			array_push($result, $tenants[$tenant_uuid]);
			$result[count($result)- 1]["is_primary"] = $user_tenants[$i]["is_primary"];
		}
	}
	
	end:

	$out = array('data' => $result, 'status' => $status, 'error' => $error);

	return $out;
}

function create_account($conn, $user_id, $name, $customer_name, $email, $address, $city, $state, $zip, $phone)
{
	$status = true;
	$result = "";
	$error = "";

	$procName = "prc_CreateAccount";
	$statement = "CALL " . $procName . "(?, ?, ?, ?, ?, ?, ?, ?, ?)";
	
	try
	{
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'issssssss', $user_id, $name, $customer_name, $email, $address, $city, $state, $zip, $phone);
		if (!$stmp->execute())
		{
			$status = false;
			$error = "Internal error calling create_account: " . $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($id);
		
		while ($stmp->fetch())
		{
			$record = array(
				"id" => $id
			);

			if ($record['id'] <= 0)
			{
				$status = false;
				$error = "Failed to create account";
				goto end;
			}
		}

		$result = $record;
		
		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$user_accounts = [];
	}
	
	end:

	$out = array('data' => $result, 'status' => $status, 'error' => $error);

	return $out;
}

function get_account($conn, $user_id, $accountId)
{
	$status = true;
	$result = array();
	$error = "";

	$procName = "prc_GetAccount";
	$statement = "CALL " . $procName . "(?, ?)";
	
	try
	{
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'ii', $user_id, $accountId);
		if (!$stmp->execute())
		{
			$status = false;
			$error = "Internal error calling get_user_accounts: " . $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($id, $primary_security_group, $name, $customer_name, $email, $address, $city, $state, $zip, $phone, $is_admin, $is_active);
		
		$i = 0;
		while ($stmp->fetch())
		{
			$record = array(
				"id" => $id,
				"primary_security_group" => $primary_security_group,
				"name" => $name,
				"customer_name" => $customer_name,
				"email" => $email,
				"address" => $address,
				"city" => $city,
				"state" => $state,
				"zip" => $zip,
				"phone" => $phone,
				"user_is_admin" => $is_admin,
				"is_active" => $is_active
			);

			$i++;
			$result = $record;
		}
		
		if ($i != 1)
		{
			$error = $i > 1 ? "Internal error. Duplicate accounts." : "Account does not exist";
			$result = array();
			$status = false;
		}

		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$result = array();
	}
	
	end:

	$out = array('data' => $result, 'status' => $status, 'error' => $error);

	return $out;
}

function get_user_accounts($conn, $userId)
{
	$status = true;
	$error = "";

	$procName = "prc_GetUserAccounts";
	$statement = "CALL " . $procName . "(?)";
	
	$user_accounts = [];

	try
	{
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'i', $userId);
		if (!$stmp->execute())
		{
			$status = false;
			$error = "Internal error calling get_user_accounts: " . $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($id, $primary_security_group, $name, $customer_name, $email, $address, $city, $state, $zip, $phone, $is_admin, $is_active);
		
		while ($stmp->fetch())
		{
			$record = array(
				"id" => $id,
				"primary_security_group" => $primary_security_group,
				"name" => $name,
				"customer_name" => $customer_name,
				"email" => $email,
				"address" => $address,
				"city" => $city,
				"state" => $state,
				"zip" => $zip,
				"phone" => $phone,
				"is_admin" => $is_admin,
				"is_active" => $is_active
			);

			if ($record['is_active'] == 1)
				array_push($user_accounts, $record);
		}
		
		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$user_accounts = [];
	}
	
	end:

	$out = array('data' => $user_accounts, 'status' => $status, 'error' => $error);

	return $out;
}

function check_user_account_membership($conn, $userId, $accountId)
{
	$status = true;
	$result = "";
	$error = "";

	$procName = "prc_CheckUserAccountMembership";
	$statement = "CALL " . $procName . "(?, ?)";
	
	try
	{
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'ii', $userId, $accountId);
		if (!$stmp->execute())
		{
			$status = false;
			$error = "Internal error calling get_user_accounts: " . $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($is_member);
		
		while ($stmp->fetch())
		{
			$record = array(
				"is_member" => $is_member
			);

			$result = $record;
		}
		
		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$result = "";
	}
	
	end:

	$out = array('data' => $result, 'status' => $status, 'error' => $error);

	return $out;
}

function get_account_tenants($conn, $account_id)
{
	$status = true;
	$error = "";
	$account_tenants = [];

	$inner_result = _internal_get_account_tenants($conn, $account_id);
	if ($inner_result['status'] == false)
	{
		$status = false;
		$error = $inner_result['error'];
		goto end;
	}

	// if the account doesn't have any allocated tenants then find and assign a tenant
	if (count($inner_result['data']) == 0)
	{
		// assign tenant to the account
		$inner_result = _assign_tenant_for_new_account($mysqli, $account_data['primary_security_group']);
		if ($inner_result['status'] == false)
		{
			$status = false;
			$error = "Failed allocate tenant for account " . $account_data['id'];
			goto end;
		}

		$inner_result = _internal_get_account_tenants($conn, $account_id);
		if ($inner_result['status'] == false)
		{
			$status = false;
			$error = $inner_result['error'];
			goto end;
		}

		if (count($inner_result['data']) == 0)
		{
			$status = false;
			$error = "Failed pull account tenant after assignment for account " . $account_data['id'];
			goto end;
		}

		$account_tenants = $inner_result['data'];
	}

	end:

	$out = array('data' => $account_tenants, 'status' => $status, 'error' => $error);

	return $out;
}

function _internal_get_account_tenants($conn, $account_id)
{
	$status = true;
	$error = "";

	$procName = "prc_GetAccountTenants";
	$statement = "CALL " . $procName . "(?)";
	
	$account_tenants = [];

	try
	{                   
		if (!($stmp = mysqli_prepare($conn, $statement)))
		{
			$status = false;
			
			$error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}

		mysqli_stmt_bind_param($stmp, 'i', $account_id);
		if (!$stmp->execute())
		{
			$status = false;
			$error = $stmp->error;
			goto end;
		}
		
		$stmp->bind_result($security_group_id, $tenant_uuid, $is_primary);
		
		while ($stmp->fetch())
		{
			$record = array(
				"security_group_id" => $security_group_id,
				"tenant_uuid" => $tenant_uuid,
				"is_primary" => $is_primary
			);

			array_push($account_tenants, $record);
		}
		
		_free_result($conn);
	}
	catch(Exception $e)
	{
		$error = "Exception: " . $e;
		$status = false;
		$account_tenants = [];
	}
	
	end:

	$out = array('data' => $account_tenants, 'status' => $status, 'error' => $error);

	return $out;
}




?>