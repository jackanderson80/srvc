<?php
require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . '/../srv/common/db_common.php';
require_once __DIR__ . "/../srv/config/app_config.php";

require_once __DIR__ . '/account.php';

require_once __DIR__ . '/blob_api.php';

setlocale(LC_ALL, 'en_US.UTF8');

function cleanName($str, $replace=array(), $delimiter='-') {
	try
    { 
		if( !empty($replace) ) {
			$str = str_replace((array)$replace, ' ', $str);
		}

		//$clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str); // //TRANSLIT causes errors with other langauges
		
		$clean = iconv('UTF-8', 'ASCII//IGNORE', utf8_encode($str));
		
		$clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
		$clean = strtolower(trim($clean, '-'));
		$clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);

		return $clean;
	}
    catch(Exception $e)
    {
		return "";
    }
}

function setUserSecurityGroupsCache($conn, $userId, $securityGroupsList, $primaryGroupFlag)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_SetUserSecurityGroupsCache(?, ?, ?)")))
    {
        $status = false;
		
        $error = "Internal error. Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'isi', $userId, $securityGroupsList, $primaryGroupFlag);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {            
            $stmp->bind_result($retcode);
            while ($stmp->fetch())
            {
              $result = array('status' => $retcode);
            }

            if ($result == "")
            {
                $status = false;
            }
            else
            {
                $status = true;
            }
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function clearUserSecurityGroupsCache($conn, $userId)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_ClearUserSecurityGroupsCache(?)")))
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
            $stmp->bind_result($retcode);
            while ($stmp->fetch())
            {
              $result = array('status' => $retcode);
            }

            if ($result == "")
            {
                $status = false;
			}
            else
            {
                $status = true;
            }
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function getDoc($conn, $userId, $accountId, $docId, $docHash)
{
    $status = true;
    $error = "";
    $result = "";
        
    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetDoc(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'iiis', $userId, $accountId, $docId, $docHash);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($document_id, $hash, $type, $name, $description, $keywords, $author_user_id, $account_id, $org_unit_id, $created_date, $modified_date, $content, $allow_inherit_parent_permissions, $permissions_mask, $shared_everyone);
            while ($stmp->fetch())
            {
              $result = array(
                'document_id' => $document_id,
                'hash' => $hash,
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'keywords' => $keywords,
                'created_date' => $created_date,
                'modified_date' => $modified_date,
                'content' => $content,
				'allow_inherit_parent_permissions' => $allow_inherit_parent_permissions,
                'permissions_mask' => $permissions_mask,
                'shared_everyone' => $shared_everyone,
                'author_user_id' => $author_user_id,
                'account_id' => $account_id,
                'org_unit_id' => $org_unit_id
                );
            }

            if ($result == "")
            {
				$status = false;
				$error = "Insufficient permissions";
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

function setDocUserPermissions($mysqli, $mysqli_user, $document_id, $account_id, $permissions_mask, $grantor_user_id, $email)
{
    $status = true;
    $error = "";
    $result = "";

    $current_user_primary_security_group_id = -1;

    // get the target user info
    $inner_result = getUserByEmail($mysqli_user, $email);
    if ($inner_result['status'] == false)
    {
        $status = false;
        $error = "Cannot get user information for " . $email;
        goto end;
    }

    if ($inner_result['data']['user_isactive'] != 1)
    {
        $status = false;
        $error = "The specified user does not exist or is inactive " . $email;
        goto end;
    }

    $target_user_id = $inner_result['data']['user_id'];

    // get the primary security group of the taget user
    $inner_result = getUserPrimarySecurityGroup($mysqli_user, $target_user_id);
    if ($inner_result['status'] == false)
    {
        $status = false;
        $error = "Cannot get primary security group of target user " . $email;
        goto end;
    }

    $target_security_group_id = $inner_result['data']['id'];

    // check if the user is member of the same account
    $inner_result = getDoc($mysqli, $grantor_user_id, $account_id, $document_id, '');
    if ($inner_result['status'] == false || !isset($inner_result['data']['document_id']))
    {
        $status = false;
        $error = "The target document does not exist or cannot be found under the current account";
        goto end;
    }

    $doc_account_id = $inner_result['data']['account_id'];

    if ($doc_account_id != $account_id)
    {
        $status = false;
        $error = "The document sharing cannot be modified while using the current account";
        goto end;
    }

    if ($doc_account_id != -1)
    {
        $inner_result = check_user_account_membership($mysqli_user, $target_user_id, $doc_account_id);
        if ($inner_result['status'] == false || $inner_result['data']['is_member'] == 0)
        {
            $status = false;
            $error = "The specified user is not a member of the current account.";
            goto end;
        }
    }

    // Check that the user is not modifying own permissions
    
    $outputGroup = getUserPrimarySecurityGroup($mysqli_user, $grantor_user_id);
    if ($outputGroup["status"] == false)
    {
        $status = false;
        $error  = "Cannot get primary security group of the current user";
        goto end;
    }
    
    $current_user_primary_security_group_id = $outputGroup['data']['id'];
    if ($current_user_primary_security_group_id == $target_security_group_id)
    {
        $status = false;
        $error  = "The user cannot modify own permissions";
        goto end;
    }

    $inner_result = setDocSecurityGroupPermissions($mysqli, $grantor_user_id, $target_security_group_id, $document_id, $permissions_mask);		
    
    $result = $inner_result['data'];
    $error = $inner_result['error'];
    $status = $inner_result['status'];

    end:

    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    
    return $out;
}

function setDocSecurityGroupPermissions($conn, $grantor_user_id, $securityGroupId, $docId, $permissions_mask)
{
    $status = true;
    $error = "";
    $result = "";
            
    if (!($stmp = mysqli_prepare($conn, "CALL prc_SetDocSecurityGroupPermissions(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        mysqli_stmt_bind_param($stmp, 'iiis', $grantor_user_id, $securityGroupId, $docId, $permissions_mask);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($retcode);
            while ($stmp->fetch())
            {
              $result = array('status' => $retcode);
            }

            if ($result == "")
            {
                $status = false;
            }
			else if ($result["status"] == -1)
			{
				$status = false;
				$error = "Insufficient permissions";
			}
			else if ($result["status"] == -2)
			{
				$status = false;
				$error = "Invalid security group";
			}
            else
            {
                $status = true;
            }
        }
    }
    
    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function deleteDocRecursive($conn, $userId, $accountId, $docId, $deleteBlobFlag)
{
    $status = true;
    $error = "";
    $result = "";

	try
	{
		$inner_result = getChildDocs($conn, $userId, $docId);
		if ($inner_result['status'] != true)
		{
			$result = $inner_result['data'];
			$status = $inner_result['status'];
			$error = $inner_result['error'];
			
			goto end;
		}
		
		$list = $inner_result['data'];
				
		for ($i = 0; $i < count($list); $i++)
		{
			$record = $list[$i];
			$childDocDeleteResult = deleteDocRecursive($conn, $userId, $accountId, $record['document_id'], $deleteBlobFlag);
			if ($childDocDeleteResult['status'] != true)
			{
				$result = $childDocDeleteResult['data'];
				$status = $childDocDeleteResult['status'];
				$error = $childDocDeleteResult['error'];
				goto end;
			}
			else
			{
				//echo "<br>Delete child doc: " . $record['document_id'] . "<br>";
			}
		}
		
		$inner_result = deleteDocSelf($conn, $userId, $accountId, $docId, $deleteBlobFlag);
		
		$result = $inner_result['data'];
		$status = $inner_result['status'];
		$error = $inner_result['error'];
				
	}
	catch(Exception $e)
	{
		$error = "Exception: " + $e;
		$status = false;
		$result = "";
    }
    
    end:

    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function deleteDocSelf($conn, $userId, $accountId, $docId, $deleteBlobFlag)
{
    $status = true;
    $error = "";
    $result = "";

	try
	{
		if (!($stmp = mysqli_prepare($conn, "CALL prc_DeleteDocSelf(?, ?, ?)")))
		{
			$status = false;
			$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
			goto end;
		}
		else
		{   
			mysqli_stmt_bind_param($stmp, 'iii', $userId, $accountId, $docId);
			
			if (!$stmp->execute())
			{
				$status = false;
				$error = $stmp->error;
				goto end;
			}
			else
			{
				$rowCount = 0;
				
				$stmp->bind_result($rowCount);
				while ($stmp->fetch())
				{
				  $result = array('deletedCount' => $rowCount);
				}

				if ($result == "")
				{
                    $error = "Undefined error";
					$status = false;
				}
				else if ($result['deletedCount'] == -1)
				{
					$status = false;
					$error = "Document does not exist";
				}
				else if ($result['deletedCount'] == -2)
				{
					$status = false;
					$error = "Insufficient account permissions";
				}
				else if ($result['deletedCount'] == -3)
				{
					$status = false;
					$error = "Insufficient permissions";
				}
				else                
				{
					$status = true;
				}
				
				if ($status == true && $deleteBlobFlag)
				{
					$blobDeleteResult = deleteBlob(intval($docId));
				}
				
				goto end;
			}
		}
        
		end:

		if ($stmp)
		{
			$stmp->close();
			$stmp = null;
		}
		
	}
	catch(Exception $e)
	{
		$error = "Exception: " + $e;
		$status = false;
		$result = "";
	}

	if ($stmp)
	{
		$stmp->close();
		$stmp = null;
	}
	
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
	
    return $out;
}

function saveDoc($conn, $userId, $account_id, $org_unit_id, $docId, $name, $type, $allow_inherit_parent_permissions, $description, $keywords, $content, $hash)
{
    global $anon_user_id;
    global $everyone_user_id;

    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_SaveDoc(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {   
        if ($docId == -1)
        {
          if ($userId != $anon_user_id && $userId != $everyone_user_id)
          {
             $hash = "";//cleanName($name);
          }
          else
          {
            $hash = "";
          }
        }
        
        mysqli_stmt_bind_param($stmp, 'iiiississss', $userId, $account_id, $org_unit_id, $docId, $name, $type, $allow_inherit_parent_permissions, $description, $keywords, $content, $hash);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($doc_id, $hash);
            while ($stmp->fetch())
            {
              $result = array('document_id' => $doc_id, 'hash' => $hash);
            }

            if ($result == "")
            {
                $status = false;
            }
            else if ($result['document_id'] == -1)
			{
				$status = false;
				$error = "Invalid security group permissions";
			}
            else if ($result['document_id'] == -2)
			{
				$status = false;
				$error = "Insufficient permissions";
            }
            else if ($result['document_id'] == -3)
			{
				$status = false;
				$error = "Insufficient account permissions to modify document";
            }            
			else
            {
                $status = true;
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

function getDocsByType($conn, $userId, $accountId, $docType)
{
    $status = true;
    $error = "";
    $result = array();
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    
    $parts = explode("|", $docType);
    
    for ($i = 0; $i < count($parts); $i++)
    {
      $type = $parts[$i];
      $out_current = getDocsByType_inner($conn, $userId, $accountId, $type);
      if ($i == 0)
      {
        $out = $out_current;
      }
      else if ($out_current['status'] != true)
      {
        $out = $out_current;
        break;
      }
      else
      {
        $out['data'] = array_merge($out['data'], $out_current['data']);
      }      
    }
    
    return $out;
}

function getDocsByType_inner($conn, $userId, $accountId, $docType)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_getDocsList(?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {           
        mysqli_stmt_bind_param($stmp, 'iis', $userId, $accountId, $docType);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($document_id, $hash, $type, $name, $description, $keywords, $author_user_id, $account_id, $org_unit_id, $created_date, $modified_date, $content, $allow_inherit_parent_permissions, $permissions_mask);
            $tmp_result = array();
            while ($stmp->fetch())
            {
              $tmp_result[] = array(
                'document_id' => $document_id,
                'hash' => $hash,
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'keywords' => $keywords,
                'author_user_id' => $author_user_id,
                'account_id' => $account_id,
                'org_unit_id' => $org_unit_id,
                'created_date' => $created_date,
                'modified_date' => $modified_date,
				'content' => $content,
				'allow_inherit_parent_permissions' => $allow_inherit_parent_permissions,
                'permissions_mask' => $permissions_mask
              );
            }
            
            $result = array();
            $last_doc_id = -1;
            for ($i = 0; $i < count($tmp_result); $i++)
            {
                if ($last_doc_id != $tmp_result[$i]['document_id'])
                {
                    array_push($result, $tmp_result[$i]);
                    $last_doc_id = $tmp_result[$i]['document_id'];
                }
                else
                {
                    $cur_mask = $result[count($result)-1]['permissions_mask'];
                    $i_mask = $tmp_result[$i]['permissions_mask'];
                                            
                    $iParts = str_split($i_mask);

                    for ($j = 0; $j < count($iParts); $j++)
                    {
                        if (strpos($cur_mask, $iParts[$j]) === false)
                            $cur_mask = $cur_mask . $iParts[$j];
                    }
                    
                    $result[count($result)-1]['permissions_mask'] = $cur_mask;
                }
            }
                
            $out = array();
            for ($i = 0; $i < count($result); $i++)
            {
                if ($result[$i]['author_user_id'] == $userId)
                    array_push($out, $result[$i]);
            }
            for ($i = 0; $i < count($result); $i++)
            {
                if ($result[$i]['author_user_id'] != $userId)
                    array_push($out, $result[$i]);
            }
            
            $result = $out;
            
            $status = true;
            goto end;
        }
    }    

    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function getDocSecurityInfoAll($conn, $docId, $fetch_inherited)
{
    $status = true;
    $error = "";
    $result = "";

	$result = getDocPath($conn, $docId);
		
	if ($result['status'] != true)
	{
		$status = $result['status'];
		goto end;
	}
	
	$path = $result['data']['path'];
	$path = $path . "/" . $docId;
	
	$pieces = explode("/", $path);

	$result = array();
	for ($i = 0; $i < count($pieces); $i++)
	{
		$piece = $pieces[$i];
		if (strlen($piece) <= 0)
			continue;
		
		$inner_result = getDocSecurityInfoSelf($conn, $piece);
		if ($inner_result['status'] != true)
			continue;
		
		{
			for ($j = 0; $j < count($inner_result['data']); $j++)
			{
				if ($fetch_inherited == false)
				{
					if($inner_result['data'][$j]['permissions_mask'] == "I")
						continue;
				}
				
				if ($i < count($pieces) - 1)
				{
					$inner_result['data'][$j]['is_inherited'] = true;
				}
				else
				{
					$inner_result['data'][$j]['is_inherited'] = false;
				}
				
				array_push($result, $inner_result['data'][$j]);
			}
		}
	}

	
	end:
	
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function getDocSecurityInfoSelf($conn, $docId)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetDocSecurityInfo(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {           
        mysqli_stmt_bind_param($stmp, 'i', $docId);
        
        if (!$stmp->execute())
        {
            $status = false;
            $error = $stmp->error;
            goto end;
        }
        else
        {
            $stmp->bind_result($relationship_id, $document_id, $security_group_id, $permissions_mask, $granted_by_userid, $inherited_by_relationship_id);
            
            $result = array();
            
            while ($stmp->fetch())
            {
              $result[] = array(
                'relationship_id' => $relationship_id,
                'document_id' => $document_id,
                'security_group_id' => $security_group_id,
                'permissions_mask' => $permissions_mask,
                'granted_by_userid' => $granted_by_userid,
                'inherited_by_relationship_id' => $inherited_by_relationship_id
              );
            }

            $status = true;
            goto end;
        }
    }    

    end:

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}


function _setImplicitReadPermissionToParentDocs($conn, $userId, $security_group_id, $document_id, $inherited_by_relationship_id)
{
	
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_SetImplicitReadPermissionToParentDocs(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {          
        mysqli_stmt_bind_param($stmp, 'iiii', $userId, $security_group_id, $document_id, $inherited_by_relationship_id);
        
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
              $result = array('retVal' => $retVal);
            }

            if ($result == "" || $result['retVal'] != 1)
            {
				$status = false;
				$error = "Internal error";
            }
            else
            {
                $status = true;
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


//////////////////////////////////////////////////
// Folder functions
//////////////////////////////////////////////////
function getChildDocs($conn, $userId, $parentDocId)
{
    $status = true;
    $error = "";
    $result = "";

	try
	{
		if (!($stmp = mysqli_prepare($conn, "CALL prc_GetChildDocs(?, ?)")))
		{
			$status = false;
			$error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
		}
		else
		{           
			mysqli_stmt_bind_param($stmp, 'ii', $userId, $parentDocId);
			
			if (!$stmp->execute())
			{
				$status = false;
				$error = $stmp->error;
			}
			else
			{
				$stmp->bind_result($document_id, $hash, $type, $name, $description, $keywords, $created_date, $modified_date, $content, $permissions_mask, $author_id, $account_id, $org_unit_id);
				$tmp_result = array();
				while ($stmp->fetch())
				{
				  $tmp_result[] = array(
					'document_id' => $document_id,
					'hash' => $hash,
					'type' => $type,
					'name' => $name,
					'description' => $description,
					'keywords' => $keywords,
					'created_date' => $created_date,
                    'modified_date' => $modified_date,
                    'content' => $content,
					'permissions_mask' => $permissions_mask,
                    'author_id' => $author_id,
                    'account_id' => $account_id,
                    'org_unit_id' => $org_unit_id
				  );
				}
				
				$result = array();
				$last_doc_id = -1;
				for ($i = 0; $i < count($tmp_result); $i++)
				{
					if ($last_doc_id != $tmp_result[$i]['document_id'])
					{
						array_push($result, $tmp_result[$i]);
						$last_doc_id = $tmp_result[$i]['document_id'];
					}
					else
					{
						$cur_mask = $result[count($result)-1]['permissions_mask'];
						$i_mask = $tmp_result[$i]['permissions_mask'];
												
						$iParts = str_split($i_mask);

						for ($j = 0; $j < count($iParts); $j++)
						{
							if (strpos($cur_mask, $iParts[$j]) === false)
								$cur_mask = $cur_mask . $iParts[$j];
						}
						
						$result[count($result)-1]['permissions_mask'] = $cur_mask;
					}
				}
					
				$out = array();
				for ($i = 0; $i < count($result); $i++)
				{
					if ($result[$i]['author_id'] == $userId)
						array_push($out, $result[$i]);
				}
				for ($i = 0; $i < count($result); $i++)
				{
					if ($result[$i]['author_id'] != $userId)
						array_push($out, $result[$i]);
				}
				
				$result = $out;
				
				$status = true;
			}
		}    		
	}
	catch(Exception $e)
	{
		$error = "Exception: " + $e;
		$status = false;
		$result = "";
	}

    if ($stmp)
        $stmp->close();
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function getDocAndChildDocs($mysqli, $userId, $accountId, $docId)
{
	$list = [];
	$status = true;
	$error = '';

	$result = getDoc($mysqli, $userId, $accountId, $docId, '');
	if ($result['status'] != true)
	{
		$status = $result['status'];
		$error = $result['error'];
		goto end;
	}
	
	$result = $result['data'];
	
	$docContent = $result;
		
	$result = getChildDocs($mysqli, $userId, $docId);
	if ($result['status'] != true)
	{
		$status = $result['status'];
		$error = $result['error'];
		goto end;
	}
	
	$childDocs = $result['data'];
	
	for ($j = 0; $j < count($childDocs); $j++)
	{
		$docResult = getDoc($mysqli, $userId, $accountId, $childDocs[$j]['document_id'], '');
		if ($docResult['status'] != true)
		{
			$status = $result['status'];
			$error = $result['error'];
			goto end;
		}
		
		$childDoc = $docResult['data'];
		array_push($list, $childDoc);
	}
	
	
end:

	$docContent['childDocs'] = $list;
	//$docAndChildDocs = array('document' => $docContent, 'child_documents' => $list);
		
	$out = array('data' => $docContent, 'status' => $status, 'error' => $error);
	
	return $out;
}

function getDocs($mysqli, $userId, $accountId, $docsList)
{
	$list = [];
	$status = true;
	$error = '';
	
	for ($i = 0; $i < count($docsList); $i++)
	{		
		$docId = $docsList[$i];
		
		$result = getDoc($mysqli, $userId, $parentDocId, $accountId, $docId, '');
		if ($result['status'] != true)
		{
			$status = $result['status'];
			$error = $result['error'];
			goto end;
		}
		
        $childDoc = $result['data'];
        			
		array_push($list, $childDoc);
	}
	
end:
		
	$out = array('data' => $list, 'status' => $status, 'error' => $error, 'user_id' => $userId);
	
	return $out;
}

function _propagateSecurityGroupsPermissionsToParentDocs($conn, $userId, $documentId)
{
    $status = true;
    $error = "";
    $result = "";
	
	$docSecInfo = getDocSecurityInfoSelf($conn, $documentId);
	
	if ($docSecInfo['status'] != true)
	{
		$status = false;
		$error = "Error getting document security info: " . $docSecInfo['error'];
		goto end;
	}
	
	$propagated_count = 0;
	
	for ($i = 0; $i < count($docSecInfo['data']); $i++)
	{
		$record = $docSecInfo['data'][$i];
		
		if (false === strpos(strtolower($record['permissions_mask']), 'i') &&
            false === strpos(strtolower($record['permissions_mask']), 'r'))
			continue;
		
		$inherited_by_relationship_id = $record['inherited_by_relationship_id'];
		if ($inherited_by_relationship_id === NULL)
		{
			$inherited_by_relationship_id = $record['relationship_id'];
		}
		
		$inner_result = _setImplicitReadPermissionToParentDocs($conn, $userId, $record['security_group_id'], $record['document_id'], $inherited_by_relationship_id);
		if ($inner_result['status'] == true)
			$propagated_count++;
	}
	
	end:
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error, 'propagatedCount' => $propagated_count);
    return $out;
}


function addDocToParentDoc($conn, $userId, $accountId, $documentId, $parentDocId)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_AddDocToParentDoc(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {          
        mysqli_stmt_bind_param($stmp, 'iiii', $userId, $accountId, $documentId, $parentDocId);
        
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
              $result = array('retVal' => $retVal);
            }

            if ($result == "")
            {
                $status = false;
            }
            else if ($result['retVal']  != 1)
			{
				$status = false;
				$error = "Insufficient permissions.";
			}
            else                
            {
                $status = true;
            }
            
            goto end;
        }
    }    

    end:

    if ($stmp)
        $stmp->close();
	
	
	if ($status == true)
	{
		$out = _propagateSecurityGroupsPermissionsToParentDocs($conn, $userId, $documentId);
		return $out;
		$result = $out['data'];
		$status = $out['status'];
		$error = $out['error'];
	}
    
    $out = array('data' => $result, 'status' => $status, 'error' => $error);
    return $out;
}

function removeDocFromParentDoc($conn, $userId, $accountId, $documentId, $parentDocId)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_RemoveDocFromParentDoc(?, ?, ?, ?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {          
        mysqli_stmt_bind_param($stmp, 'iiii', $userId, $accountId, $documentId, $parentDocId);
        
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
              $result = array('retVal' => $retVal);
            }

            if ($result == "")
            {
                $status = false;
            }
            else if ($result['retVal'] == -1 || $result['retVal'] == -2)
			{
				$status = false;
				$error = "Insufficient permissions.";
			}
            else if ($result['retVal'] == -3)
			{
				$status = false;
				$error = "Invalid document relationship.";
            }
            else if ($result['retVal'] == -4)
			{
				$status = false;
				$error = "Insufficient account permissions.";
			}            
            else                
            {
                $status = true;
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

function getDocPath($conn, $documentId)
{
    $status = true;
    $error = "";
    $result = "";

    if (!($stmp = mysqli_prepare($conn, "CALL prc_GetDocPath(?)")))
    {
        $status = false;
        $error = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        goto end;
    }
    else
    {          
        mysqli_stmt_bind_param($stmp, 'i', $documentId);
        
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
              $result = array('path' => $retVal);
            }

            if ($result == "")
            {
                $result = array('path' => "");
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

//////////////////////////////////////////////
// End of folder functions
//////////////////////////////////////////////
?>