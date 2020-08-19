<?php
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/doc_functions.php';
require_once __DIR__ . '/team_functions.php';
require_once __DIR__ . '/encrypt.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/account.php';

require_once __DIR__ . '/../srv/common/common.php';

require_once __DIR__ . "/../srv/config/app_config.php";
require_once __DIR__ . "/../srv/config/db_config.php";

require_once __DIR__ . '/../srv/resourcemanager/resourcemanager.php';


use Firebase\Auth\Token\Verifier;


if (isset($_POST['name']))
    $name = $_POST['name'];
else
    $name = '';

if (isset($_POST['email']))
    $email = strtolower($_POST['email']);
else
    $email = '';
    
if (isset($_POST['password']))
    $password = $_POST['password'];
else
    $password = '';

function validate_firebase_token()
{
	$projectId = "terinno";

	$verifier = new Verifier($projectId);

	$idToken = isset($_POST['idToken']) ? $_POST['idToken'] : null;
	$uid = isset($_POST['uid']) ? $_POST['uid'] : null;

	$verifiedIdToken = "";
	$status = false;

	$claim = "";

	try 
	{
		$verifiedIdToken = $verifier->verifyIdToken($idToken);
		
		$claim = $verifiedIdToken->getClaim('sub'); // "a-uid"
		
		if ($claim == "" || $claim != $uid)
		{
			// "Validation failed";
			$status = false;
		}
		else
		{
			$status = true;
		}
	} 
	catch (\Firebase\Auth\Token\Exception\ExpiredToken $e) 
	{
		$status = false;
		$error = $e->getMessage();
	}
	catch (\Firebase\Auth\Token\Exception\IssuedInTheFuture $e)
	{
		$status = false;
		$error = $e->getMessage();
	}
	catch (\Firebase\Auth\Token\Exception\InvalidToken $e)
	{
		$status = false;
		$error = $e->getMessage();
	}
	
	return $status;
}


if (!isset($_POST['cmd']))
	return;

$cmd = $_POST['cmd'];

session_start();

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $everyone_user_id;
$readonly_access =  $user_id == $everyone_user_id;

try
{
	
    $status = false;
    $error = "";
    $result = "";
    
    if (!isset($_SESSION['requestPwdReset']))
		$_SESSION['requestPwdReset'] = false;
		
	if ($cmd != "logout" || $cmd != "getCurrentUser")
	{
		$mysqli = new mysqli($users_db_hostname, $users_db_username, $users_db_password, $users_db_database, $users_db_port);
	
		if ($mysqli->connect_errno) {
			$status = false;
			$error = "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
			
			$output = array('status' => $status, 'error' => $error, 'data' => $result);
	
			goto end;
		}	
	}
    
    
    if ($cmd == "logout")
    {		
        session_destroy();
		
		setcookie("current_user", "", time() - 3600, "/");; // expire the login cookie

		$status = true;

		$output = array('status' => $status, 'error' => $error, 'data' => $result);
		
        goto end;
    }
    else if ($cmd == "getCurrentUser")
    {
        if (isset($_SESSION['user_email']) && isset($_SESSION['user_id']))
        {
            $result = array(
                'user_id' => $_SESSION['user_id'],
                'user_email' => $_SESSION['user_email'], 
				'user_name' => $_SESSION['user_name'],
				'user_account_id' => $_SESSION['user_account_id']
                );
				
			setcookie('current_user', encryptString(json_encode($result), $cryptKey, $salt), time() + (86400), "/"); // 86400 = 1 day
        }
		else if (isset($_COOKIE['current_user']))
		{
			$cookieJSON = null;
			try
			{
				$cookieJSON = decryptString($_COOKIE['current_user'], $cryptKey, $salt);
				$cookieJSON = stripslashes($cookieJSON);
				$result = json_decode($cookieJSON);
			}
			catch(Exception $e)
			{
				echo 'Invalid cookie';
				$result == null;
			}
			
			if ($result == null) 
			{
				setcookie("current_user", "", time() - 3600); // bad cookie. expire it.
				
				$result = array(
					'user_id' => -1,
					'user_email' => '', 
					'user_name' => '',
					'user_account_id' => -1
					);
					
				$status = true;				
			}
			else
			{
				$result = (array)$result;
				
				$_SESSION['user_id'] = $result['user_id'];
				$_SESSION['user_email'] = $result['user_email'];
				$_SESSION['user_name'] = $result['user_name'];
				$_SESSION['user_account_id'] = $result['user_account_id'];
			}

			if (!isset($_SESSION['workspace_tenants']) && isset($_SESSION['user_email']) && isset($_SESSION['user_id']))
			{
				// setting the user account will also perform tenant login and set the tenant session variable
				$inner_result = set_user_account($mysqli, $_SESSION['user_id'], $_SESSION['user_account_id']);
				if ($inner_result['status'] == false)
				{
					$result = array(
						'user_id' => -1,
						'user_email' => '', 
						'user_name' => '',
						'user_account_id' => -1
						);

					unset($_SESSION['workspace_tenants']);
					unset($_SESSION['user_id']);
					unset($_SESSION['user_email']);
					unset($_SESSION['user_name']);
					usnet($_SESSION['user_account_id']);
				}
			}

			$output = array('status' => $status, 'error' => $error, 'data' => $result);

		}
        else
        {
            $result = array(
                'user_id' => -1,
                'user_email' => '', 
				'user_name' => '',
				'user_account_id' => -1
                );
        }
        
		$status = true;

		$output = array('status' => $status, 'error' => $error, 'data' => $result);

        goto end;
    }
    
	else if ($cmd == 'harddeleteuser' || $cmd == 'softdeleteuser')
	{
		if (is_local_host())
		{
			$inner_result = deleteUser($mysqli, $_POST['user_id'], $password, $cmd == 'harddeleteuser');

			$status = $inner_result['status'];
			$error = $inner_result['error'];
			$result = $inner_result['data'];
		}
		else
		{
			$status = false;
			$error = "Insufficient permissions";
			$result = "";
		}

		$output = array('status' => $status, 'error' => $error, 'data' => $result);
	}

	else if ($cmd == "activateuseraccount")
	{
		if (is_local_host())
		{
			$output = updateUserAccountActivation($mysqli, $_POST['email'], 0, 0);
		}
		else
		{
			$status = false;
			$error = "Insufficient permissions";
			$result = "";
		
			$output = array('status' => $status, 'error' => $error, 'data' => $result);
		}
	}
	
    if ($cmd == "create" || $cmd == 'changepassword' || $cmd == 'changeusername' || $cmd == 'updateUser')
    {
		$user_id = -1;
		if ($cmd != "create")
			$user_id = $_SESSION['user_id'];
		
		$inner_result = upsertUser($mysqli, $user_id, $name, $email, $password);
		$status = $inner_result["status"];
		$result = $inner_result["data"];

		if ($status == true && $result['user_id'] != -1)
		{
			if ($cmd != 'create')
			{
				$_SESSION = array();
				$_SESSION['user_id'] = $result['user_id'];
				$_SESSION['user_email'] = $result['user_email'];
				$_SESSION['user_name'] = $result['user_name'];
			}
			else
			{
				$result = array(
					'user_id' => -1,
					'msg' => 'Check your email to confirm your registration'
				);

				if (!is_local_host())
				{
					try
					{
						sendMailConfirmation($name, $email, 'create');
					}
					catch(Exception $e)
					{
						hardDeleteUser($mysqli, $result['user_id'], $password);

						$status = false;
						$error = 'exception: ' + $e;
						
						$result = array(
							'user_id' => -1,
							'msg' => 'Internal error. Please try later'
						);
					}
				}
				else
				{
				//	$result = $inner_result["data"];
				}
			}
			
			if ($cmd == 'changepassword')
				$_SESSION['requestPwdReset'] = false;
		}
		
		$output = array('status' => $status, 'error' => $error, 'data' => $result);
    }
    else if ($cmd == "forgot")
    {
        // call the proc to ensure that the user exists in the database
        // if the user exist then send a reset email
        if (!($stmp = mysqli_prepare($mysqli, "CALL prc_GetUserByEmail(?)")))
        {
            $status = false;
            $error = "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error;
        }
        else
        {   
            mysqli_stmt_bind_param($stmp, 's', $email);
            
            if (!$stmp->execute())
            {
                $status = false;
                $error = $stmp->error;
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
                    'user_isactive' => $is_active
                    );
                }
                
                if ($result != "" && $result['user_isactive'] != 0)
                {
                    if (strlen($email) > 0)
                    {
                        sendPwdReset($email);
                        $status = true;
                    }
                }

                $status = true;
                $result = array(
                    'user_id' => -1,
                    'msg' => 'Check your email for password reset instructions'
                );                        
            }
        }    
            
		$output = array('status' => $status, 'error' => $error, 'data' => $result);
    }
	
	else if ($cmd == "login_provider")
	{
		// validate provider token
		if (!validate_firebase_token())
		{
			$status = false;
			$result = "";
			$error = "validation failed";
			$output = array('status' => $status, 'error' => $error, 'data' => $result);

			goto end;
		}

		// validation completed. proceed with login
		
		$inner_result = ensureUserExists($mysqli, $email);
		$status = $inner_result["status"];
		$result = $inner_result["data"];
		$error = $inner_result["error"];
		
		if ($status == false)
		{
			$output = array('status' => $status, 'error' => $error, 'data' => $result);

			goto end;
		}
		
		if ($result['is_active_provider'] == 0)
		{
			$inner_result = updateUserAccountActivation($mysqli, $email, 1, 0);
			
			if ($inner_result['status'] == false)
			{
				$output = array('status' => $status, 'error' => $error, 'data' => "");

				goto end;
			}
		}
		
		$result = getUserByEmail($mysqli, $email);
		$status = $inner_result["status"];
		$user_data = $inner_result["data"];
		$error = $inner_result["error"];
		
		$dbg = null;

		if ($status == true && $user_data["user_isactive"] == 1)
		{
			$inner_result = login_user($mysqli, $user_data);
			$dbg = $inner_result;
			if ($inner_result['status'] == false)
			{
				$status = false;
				$error = $inner_result["error"];
				$output = array('status' => $status, 'error' => $error, 'data' => $result);

				goto end;
			}
		}
		else
		{
			$status = false;
			$error = "Invalid user name or password";
		}

		$output = array('status' => $status, 'error' => $error, 'data' => $result);

	}
    else if ($cmd == "login")
    {
		$inner_result = getUser($mysqli, $email, $password);
		$status = $inner_result["status"];
		$user_data = $inner_result["data"];

		$dbg = null;
		$dbg2 = null;

		if ($status == true && $user_data["user_isactive"] == 1)
		{
			$inner_result = login_user($mysqli, $user_data);
			$dbg = get_user_tenants($mysqli, 256);

			$rm_db_conn = connect_RM_DB();
			$resourceManager = new ResourceManager($rm_db_conn);
			$dbg2 = $resourceManager->getTenants();
			db_disconnect($rm_db_conn);

			if ($inner_result['status'] == false)
			{
				$status = false;
				$error = $inner_result["error"];
			}
			else
			{			
				$result = Array("user_email" => $user_data['user_email'], "user_id" => $user_data['user_id'], "user_name" => $user_data['user_name']);
			}
		}
		else
		{
			$status = false;
			$error = "Invalid user name or password";
		}
				
		$output = array('status' => $status, 'error' => $error, 'data' => $result);
	}
	else if ($cmd == 'setUserAccount')
	{
		$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : -1;
		if ($user_id == -1)
		{
			$status = false;
			$error = "User is not logged in";
		}
		else
		{
			$inner_result = set_user_account($mysqli, $user_id, $_POST['account_id']);
						
			$status = $inner_result['status'];
			$error = $inner_result['error'];

			if ($status == true)
			{
				$_SESSION['user_account_id'] = $user_data['user_account_id'] = $account_id;
				$result = array(
					'user_id' => $_SESSION['user_id'],
					'user_email' => $_SESSION['user_email'], 
					'user_name' => $_SESSION['user_name'],
					'user_account_id' => $_SESSION['user_account_id']
				);
	
				// set the cookie and return
				global $cryptKey, $salt;

				setcookie('current_user', encryptString(json_encode($user_data), $cryptKey, $salt), time() + (86400), "/"); // 86400 = 1 day
			}
		}

		$output = array('status' => $status, 'error' => $error, 'data' => $result);
	}
	else if ($cmd == 'getUserAccounts')
	{
		$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : -1;
		if ($user_id == -1)
		{
			$result = "";
			$status = false;
			$error = "User is not logged in";
		}
		else
		{
			$inner_result = get_user_accounts($mysqli, $user_id);
			if ($inner_result['status'] == false)
			{
				$status = false;
				$error = $inner_result['error'];
			}
			else
			{
				$status = true;
				$error = '';
				$data = $inner_result['data'];
				$result = [];
				for ($i = 0; $i < count($data); $i++)
				{
					$record = array("id" => $data[$i]["id"], "name" => $data[$i]["name"]);
					array_push($result, $record);
				}
			}
		}

		$output = array('status' => $status, 'error' => $error, 'data' => $result);
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

    else if ($cmd == "getUserInfoByEmail")
    {
		if ($readonly_access)
		{
			$output = array('data' => '', 'status' => false, 'error' => 'Permission denied');
		}
		else
		{
			$output = getUserByEmail($mysqli, $_POST['email']);
			if ($output["status"] == true)
			{
				$user_id_to_get = $output['data']['user_id'];
				if ($user_id_to_get != -1)
				{
					$outputGroup = getUserPrimarySecurityGroup($mysqli, $user_id_to_get);
					if ($outputGroup["status"] == true)
					{
						$result = array(
							'user_id' => $user_id_to_get,
							'user_name' => $output['data']['user_name'],
							'user_email' => $output['data']['user_email'],
							'user_isactive' => $output['data']['user_isactive'],
							'primary_security_group_id' => $outputGroup['data']['id']
						);
						
						$output = array('data' => $result, 'status' => true, 'error' => '');
					}
				}
			}
		}
    }


    else
    {
        $status = false;
        $error = "Invalid command";
    }
           
    if (isset($mysqli))
        $mysqli->close();
        
}
catch(Exception $e)
{
    $error = 'exception: ' + $e;
    $output = array('status' => false, 'error' => $error, 'data' => "");
}

if (isset($_SESSION['requestPwdReset']))
{
    if ($_SESSION['requestPwdReset'] == true)
    {
        $result['requestPwdReset'] = true;
    }
}

end:

echo json_encode($output);

?>