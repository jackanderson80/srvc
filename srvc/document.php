<?php

require_once __DIR__ . '/doc_functions.php';
require_once __DIR__ . '/blob_api.php';
require_once __DIR__ . '/team_functions.php';
//require_once __DIR__ . '/app_api_fn.php';

require_once __DIR__ . "/../srv/tableservice/tableservice.php";
require_once __DIR__ . "/../srv/templates/tableservice_templates.php";

require_once __DIR__ . "/../srv/config/db_config.php";
require_once __DIR__ . "/../srv/config/app_config.php";


$cmd = $_POST['cmd'];

if (isset($_POST['document_id']))
    $document_id = $_POST['document_id'];
else
    $document_id = -1;

if (isset($_POST['getBlobContent']))
	$getBlobContent = $_POST['getBlobContent'] == "true";
else
	$getBlobContent = false;

if (isset($_POST['tenant_uuid']))
	$tenant_uuid = $_POST['tenant_uuid'] == "true";
else
	$tenant_uuid = null;


session_start();

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $everyone_user_id;
$org_unit_id = -1;

if (!isset($_SESSION['user_account_id']))
{
	$output = array('data' => '', 'status' => false, 'error' => 'Account is not set');
	goto end;
}

if (!isset($_SESSION['user_account_id']))
{
	$output = array('data' => '', 'status' => false, 'error' => 'Account is not set');
	goto end;
}

$account_id = $_SESSION['user_account_id'];
$readonly_access =  $user_id == $everyone_user_id;
    
try
{
	$output = array('data' => '', 'status' => true, 'error' => '');
	
	if (!isset($_SESSION['workspace_tenants']))
		goto end;
	
	$workspace_tenants = $_SESSION['workspace_tenants'];
	if (count($workspace_tenants) == 0)
		goto end;
	
	// get the primary tenant uuid
	$primary_tenant_uuid = null;
	if (isset($_SESSION['primary_tenant_uuid']))
	{
		$primary_tenant_uuid = $_SESSION['primary_tenant_uuid'];
	}
	else
	{
		for ($i = 0; $i < count($workspace_tenants); $i++)
		{
			$ws_tenant = $workspace_tenants[$i];
			if ($ws_tenant["is_primary"] == 1)
			{
				$primary_tenant_uuid = $ws_tenant["tenant_uuid"];
				$_SESSION['primary_tenant_uuid'] = $primary_tenant_uuid;
 				break;
			}
		}		
	}

	if ($tenant_uuid == null)
		$tenant_uuid = $primary_tenant_uuid;

	if ($cmd == 'getTenants')
	{
		$workspace_tenants = $_SESSION['workspace_tenants'];
		$list = [];

		for ($i = 0; $i < count($workspace_tenants); $i++)
			array_push($list, Array("tenant_uuid" => $workspace_tenants[$i]["tenant_uuid"], "is_primary" => $workspace_tenants[$i]["is_primary"]));

		$output = array('status' => true, 'error' => '', 'data' => $list);

		goto end;
	}

	if ($cmd == 'getPrimaryTenantUUID')
	{				
		$output = array('status' => true, 'error' => '', 'data' => Array("primary_tenant_uuid" => $primary_tenant_uuid));
		goto end;
	}

	// establish connection to the requested tenant
	$mysqli = null;

	if ($tenant_uuid != null)
	{
		for ($i = 0; $i < count($workspace_tenants); $i++)
		{
			$ws_tenant = $workspace_tenants[$i];

			if ($ws_tenant['tenant_uuid'] != $tenant_uuid)
				continue;

			$node_credentials = json_decode($ws_tenant["node_credentials"], true);
		
			$db_name = $ws_tenant["type"] . "-" . $ws_tenant["tenant_uuid"];

			$mysqli = db_connect($node_credentials["hostname"], $node_credentials["username"], $node_credentials["password"], $db_name, $node_credentials["port"]);
		}
	}

	if ($mysqli == null)
	{
		$output = array('status' => false, 'error' => 'Invalid tenant', 'data' => "");
		goto end;
	}
		
	if ($cmd == "getDocsByTypeAndChildDocs")
	{
		$type = $_POST['type'];
		$output = getDocsByType($mysqli, $user_id, $account_id, $type);
		if ($output["status"] == true)
		{
			$docs = $output['data'];

			for ($i = 0; $i < count($docs); $i++)
			{
				$docs[$i]["tenant_uuid"] = $tenant_uuid;

				// get the child docs
				$output_childDocs = getChildDocs($mysqli, $user_id, $docs[$i]['document_id']);
				if ($output_childDocs['status'] != true)
				{
					$output['data'] = [];
					$output['error'] = $output_childDocs['error'];
					$output['status'] = false;
					goto finish;
				}

				for ($j = 0; $j < count($output_childDocs['data']); $j++)
					$output_childDocs['data'][$j]["tenant_uuid"] = $tenant_uuid;

				$docs[$i]['childDocs'] = $output_childDocs['data'];
			}

			$output['data'] = $docs;
		}
	}

	///////////////////////////////////////////////////////////////////////
	// DOCUMENT APIs
	///////////////////////////////////////////////////////////////////////
	if ($cmd == "getDocsByType")
	{
		$type = $_POST['type'];
		$output = getDocsByType($mysqli, $user_id, $account_id, $type);
		if ($output["status"] == true)
		{
			for ($i = 0; $i < count($output["data"]); $i++)
			{
				$output["data"][$i]["tenant_uuid"] = $tenant_uuid;
			}
		}
	}

    if ($cmd == "getDocAndChildDocs")
    {
		$document_id = $_POST['document_id'];
        $output = getDocAndChildDocs($mysqli, $user_id, $account_id, $document_id);
	}	

    if ($cmd == "getDocSecurityInfoNoInheritance")
    {
        $document_id = $_POST['document_id'];
        $output = getDocSecurityInfoAll($mysqli, $document_id, false);
    }
	
    if ($cmd == "setDocUserPermissions")
    {
		if ($user_id == -1)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'User is not logged in');
		}
		else
		{
			$mysqli_user = db_connect($users_db_hostname, $users_db_username, $users_db_password, $users_db_database, $users_db_port);
			
			$grantor_user_id = $user_id;
			$email = $_POST['email'];
			$document_id = $_POST['document_id'];
			$permissions_mask = $_POST['permissions_mask'];

			$output = setDocUserPermissions($mysqli, $mysqli_user, $document_id, $account_id, $permissions_mask, $grantor_user_id, $email);
		
			db_disconnect($mysqli_user);
		}
    }
        
    if ($cmd == "deleteDoc")
    {
		if (false == $readonly_access)
		{
			$document_id = $_POST['document_id'];
			
			$deleteBlobContent = $_POST['deleteBlobContent'] == "true";

			$output = deleteDocRecursive($mysqli, $user_id, $account_id, $document_id, $deleteBlobContent);
		}
		else
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}		
    }
    
    if ($cmd == "loadDoc")
    {
        if (isset($_POST['document_hash']))
            $document_hash = $_POST['document_hash'];
        else
            $document_hash = -1;
                        
        if ($document_id == -1 && $document_hash == -1)
		{
            $output = array('data' => getDefaultDocument(), 'status' => true, 'error' => '');
		}
        else
		{
            $output = getDoc($mysqli, $user_id, $account_id, $document_id, $document_hash);
			if ($output['status'] == true && $getBlobContent == true)
			{
				$fetchedDocId = $output['data']['document_id'];
				
				$isAutoSave = isset($_POST['isAutoSave']) ? $_POST['isAutoSave'] : false;
				$versionId = isset($_POST['versionId']) ? $_POST['versionId'] : -1;
				
				if ($versionId == -1)
				{
					if (!$isAutoSave)
					{
						$versionInfo = getBlobVersionInfo($fetchedDocId);
						$versionId = $versionInfo['max']; // get the latest version
					}
				}
				
				$blobContent = getBlob($fetchedDocId, $versionId);
				if ($blobContent == null)
				{
					$output = array("status" => false, "error" => "Internal error calling getBlob", "data" => "", "versionInfo" => $versionInfo);
				}
				else
				{
					$output['data']['blobContent'] = $blobContent;
				}
			}
		}
    }    
    
    if ($cmd == "saveDoc")
    {         
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        $keywords = isset($_POST['keywords']) ? $_POST['keywords'] : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        $hash = isset($_POST['hash']) ? $_POST['hash'] : '';
		
		$blobContent = isset($_POST['blobContent']) ? $_POST['blobContent'] : null;
		$isAutoSave = isset($_POST['isAutoSave']) ? $_POST['isAutoSave'] : false;
		
		$allow_inherit_parent_permissions = 1;
        
        $output = saveDoc($mysqli, $user_id, $account_id, $org_unit_id, $document_id, $name, $type, $allow_inherit_parent_permissions, $description, $keywords, $content, $hash);
		
		if ($output['status'] == true && $blobContent != null)
		{
			$savedDocId = $output['data']['document_id'];
			
			$isNewDoc = $document_id != $savedDocId;
			
			$putBlobOutput = putBlob($savedDocId, $blobContent, $isAutoSave, $isNewDoc);
			if ($putBlobOutput == null || $putBlobOutput['status'] != true)
			{
				if ($isNewDoc)
					deleteDocSelf($mysqli, $user_id, $account_id, $savedDocId, false);
				
				$output = array("status" => false, "error" => "Internal error calling putBlob", "data" => "");
			}
		}
    }

	///////////////////////////////////////////////////////////////////////
	// Folder APIs
	///////////////////////////////////////////////////////////////////////	
    if ($cmd == "addDocToParentDoc")
    {
        $document_id = isset($_POST['document_id']) ? $_POST['document_id'] : '';
        $parent_document_id = isset($_POST['parent_document_id']) ? $_POST['parent_document_id'] : '';
        
        $output = addDocToParentDoc($mysqli, $user_id, $account_id, $document_id, $parent_document_id);
    }
	
	///////////////////////////////////////////////////////////////////////
	// Table APIs
	///////////////////////////////////////////////////////////////////////	
	if ($cmd == "tableCmd")
    {
		if (!isset($_POST['opcode']))
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid table operation');
			goto finish;
		}	

		// prepare the table manager
		if (!isset($_POST['document_id']))
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid table document id');
			goto finish;
		}

		$document_id = $_POST['document_id'];
		$output = getDoc($mysqli, $user_id, $account_id, $document_id, '');
		if ($output['status'] == false)
		{
			goto finish;
		}

		$permissions_mask = $output['data']['permissions_mask'];		
		$opCode = $_POST['opcode'];
		if ($opCode == "addColumn" || 
			$opCode == "deleteColumn" ||
			$opCode == "updateColumn" ||
			$opCode == "changeColumnType" ||
			$opCode == "setColumnPosition" ||
			$opCode == "addRecord" ||
			$opCode == "updateRecord" ||
			$opCode == "deleteRecord" ||
			$opCode == "createTable" || 
			$opCode == "deleteTable"
			)
		{
			if (strstr($permissions_mask, "W") == false)
			{
				$output = array('data' => '', 'status' => false, 'error' => 'Insufficient permissions');
				goto finish;
			}
		}
		else if ($opCode != "getRecord" && $opCode != "getRecords" && $opCode != "getTableSchema")
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid API request');
			goto finish;
		}
			

		$content = json_decode($output['data']['content'], true);

		$tableManager = new TableManager($mysqli);

		$output = $tableManager->loadTable($content['table_uuid']);
		if ($output['status'] == false)
		{
			goto finish;
		}

		// execute the command
		$opParams = json_decode($_POST['opparams'], true);
		$output = call_user_func_array(array($tableManager, $opCode), $opParams);
	}

    if ($cmd == "createTable")
    {
		if (!isset($_POST['parent_document_id']))
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid workbook id');
			goto finish;
		}
		if (!isset($_POST['name']))
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid name');
			goto finish;
		}
		
		$parent_document_id = $_POST['parent_document_id'];

		// get the parent doc to check permissions
		$output = getDoc($mysqli, $user_id, $account_id, $parent_document_id, '');
		if ($output['status'] == false)
		{
			goto finish;
		}

		$permissions_mask = $output['data']['permissions_mask'];
		if (strstr($permissions_mask, "W") == false)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Insufficient permissions');
			goto finish;
		}


		$tableName = $_POST['name'];

		$tableManager = new TableManager($mysqli);
		
		$table_template = 'default_table_template';
		if (isset($_POST['table_template']))
		{
			$table_template = $_POST['table_template'];
		}
		

		$output = create_table_from_template($tableManager, $tableName, $table_template);
		
		if ($output['status'] == false)
			goto finish;

		$table_uuid = $output['data']['uuid'];
		$document_id = -1;
		$allow_inherit_parent_permissions = 1;
		$content = json_encode(array('table_uuid' => $table_uuid));
	
		$output = saveDoc($mysqli, $user_id, $account_id, $org_unit_id, $document_id, $tableName, 'table', $allow_inherit_parent_permissions, '', '', $content, '');
		if ($output['status'] == false)
		{
			// cleanup 
			$tableManager->deleteTable();

			goto finish;
		}

		$document_id = $output["data"]["document_id"];
		
		$output = addDocToParentDoc($mysqli, $user_id, $account_id, $document_id, $_POST['parent_document_id']);
		goto finish;
	}
    if ($cmd == "deleteTable")
    {
		if (!isset($_POST['document_id']))
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Invalid table document id');
			goto finish;
		}

		$document_id = $_POST['document_id'];
		$output = getDoc($mysqli, $user_id, $account_id, $document_id, '');
		if ($output['status'] == false)
		{
			goto finish;
		}

		// check permissions
		$permissions_mask = $output['data']['permissions_mask'];
		if (strstr($permissions_mask, "D") == false)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Insufficient permissions');
			goto finish;
		}

		$content = json_decode($output['data']['content'], true);

		$tableManager = new TableManager($mysqli);

		$output = $tableManager->loadTable($content['table_uuid']);
		if ($output['status'] == false)
		{
			goto finish;
		}

		$output = $tableManager->deleteTable($content['table_uuid']);
		if ($output['status'] == false)
		{
			goto finish;
		}

		$output = deleteDocRecursive($mysqli, $user_id, $account_id, $document_id, true);
	}
	
	finish:

	db_disconnect($mysqli);

}
catch(Exception $e)
{
    $error = 'exception: ' + $e;
    $output = array('status' => false, 'error' => $error, 'data' => "");
}

end:

echo json_encode($output);

?>