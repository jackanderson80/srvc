<?php

require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/../srv/config/app_config.php";

require_once __DIR__ . '/doc_functions.php';
require_once __DIR__ . '/team_functions.php';
require_once __DIR__ . '/blob_api.php';

require_once __DIR__ . '/userapi.php';

$passedCount = 0;
$failedCount = 0;

$db_hostname = "p:localhost";
$db_database = "ws_db";
$db_username = "root";
$db_password = "Parola0302";

$account_id = 101;
$org_unit_id = 202;


function validateIsOk($result)
{
	global $passedCount;
	global $failedCount;
	if ($result['status'] != true || $result['error'] != '')
	{
		$failedCount++;
		echo ' - <span style=\'color: red;\'>FAILED</span><br>';
		var_dump($result);
	}
	else
	{
		$passedCount++;
		echo ' - <span style=\'color: green;\'>PASSED</span><br>';
	}	
}

function validateIsFail($result)
{
	global $passedCount;
	global $failedCount;
	if ($result['status'] == true)
	{
		$failedCount++;
		echo ' - <span style=\'color: red;\'>FAILED (Expected Error but status is \'Ok\')</span><br>';		
	}
	else if ($result['status'] != true && $result['error'] != '')
	{
		$passedCount++;
		echo ' - <span style=\'color: green;\'>PASSED (Expected Error)</span><br>';
	}	
	
	//var_dump($result);
	echo '<br>';
}

echo 'DB Connect<br>';
$mysqli = db_connect($db_hostname, $db_username, $db_password, $db_database);

echo 'DB Connect Users<br>';
$mysqli_users = db_connect($users_db_hostname, $users_db_username, $users_db_password, $users_db_database);


$password = "some password";
$email = "user1@gmail.com";
$email_other = "user2@gmail.com";

$allow_inherit_parent_permissions = 1;

echo '<h3>Creating users required for testing other functions</h3>';
echo '<br>Creating a new user';
$result = upsertUser($mysqli_users, -1, 'some mail', $email, $password);
$innerResult = $result['data'];
$userId = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
validateIsOk($result);


echo '<br>Confirm the 1st user email';
$result = updateUserAccountActivation($mysqli_users, $email, 0, 0);
validateIsOk($result);

echo '<br>Get the 1st user by email';
$result = getUserByEmail($mysqli_users, $email);
validateIsOk($result);

echo '<br>Get the 1st user primary security_group_id';
$result = getUserPrimarySecurityGroup($mysqli_users, $result['data']['user_id']);
validateIsOk($result);


echo '<br>Get the 1st user primary security group';
$result = getUserPrimarySecurityGroup($mysqli_users, $userId);
validateIsOk($result);
$innerResult = $result['data'];
$user1_primariy_group_id = $innerResult['id'];

echo '<br>Add the 1st user primary security group to docs db cache';
$result = setUserSecurityGroupsCache($mysqli, $userId, strval($user1_primariy_group_id), 1);
validateIsOk($result);


echo '<br>Creating a 2nd user';
$result = upsertUser($mysqli_users, -1, 'some mail', $email_other, $password);
$innerResult = $result['data'];
$userId_other = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
validateIsOk($result);

echo '<br>Confirm the 2nd user email';
$result = updateUserAccountActivation($mysqli_users, $email_other, 0, 0);
validateIsOk($result);

echo '<br>Get the 2nd user primary security group';
$result = getUserPrimarySecurityGroup($mysqli_users, $userId_other);
validateIsOk($result);
$innerResult = $result['data'];
$user2_primariy_group_id = $innerResult['id'];

echo '<br>Add the 2nd user primary security group to docs db cache';
$result = setUserSecurityGroupsCache($mysqli, $userId_other, strval($user2_primariy_group_id), 1);
validateIsOk($result);

////////////////////////////

echo '<h3>Document functions</h3>';

echo '<br>saveDoc - creating a new document';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'New doc name', 'test type', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
var_dump($result);
validateIsOk($result);

$docId = $result['data']['document_id'];

echo '<br>getDoc - reading the document saved in the previous step';
$result = getDoc($mysqli, $userId, $account_id, $docId, '');
validateIsOk($result);
var_dump($result);

if ($result['data']['document_id'] != $docId ||	
	$result['data']['account_id'] != $account_id ||
	$result['data']['org_unit_id'] != $org_unit_id ||
	$result['data']['name'] != 'New doc name' ||
	$result['data']['type'] != 'test type' ||
	$result['data']['description'] != 'test description' ||
	$result['data']['keywords'] != 'test keywords' ||
	$result['data']['content'] != 'test content')
{
	echo 'Document content didn\'t match<br>';
}
else
{
	echo 'Document content matched<br>';
}

echo '<br>saveDoc - updating the document';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, $docId, 'updated doc name', 'updated test type', $allow_inherit_parent_permissions, 'updated test description', 'updated test keywords', 'updated test content', '');
validateIsOk($result);

echo '<br>getDoc - reading the document updated in the previous step';
$result = getDoc($mysqli, $userId, $account_id, $docId, '');
validateIsOk($result);
if ($result['data']['document_id'] != $docId ||
	$result['data']['account_id'] != $account_id ||
	$result['data']['org_unit_id'] != $org_unit_id ||
	$result['data']['name'] != 'updated doc name' ||
	$result['data']['type'] != 'updated test type' ||
	$result['data']['description'] != 'updated test description' ||
	$result['data']['keywords'] != 'updated test keywords' ||
	$result['data']['content'] != 'updated test content')
{
	echo 'Updated document content didn\'t match<br>';
}
else
{
	echo 'Updated document content matched<br>';
}


echo '<br>getDoc - 2nd user trying to read the doc without permissions. Must fail to read.';
$result = getDoc($mysqli, $userId_other, $account_id, $docId, '');
validateIsFail($result);

echo '<br>setDocSecurityGroupPermissions - giving the 2nd user read permissions';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $docId, 'R');
validateIsOk($result);

echo '<br>getDoc - the user we shared with reads the doc';
$result = getDoc($mysqli, $userId_other, $account_id, $docId, '');
validateIsOk($result);
var_dump($result);

if ($result['data']['document_id'] != $docId ||
	$result['data']['account_id'] != $account_id ||
	$result['data']['org_unit_id'] != $org_unit_id ||
	$result['data']['name'] != 'updated doc name' ||
	$result['data']['type'] != 'updated test type' ||
	$result['data']['description'] != 'updated test description' ||
	$result['data']['keywords'] != 'updated test keywords' ||
	$result['data']['content'] != 'updated test content')
{
	echo 'Document content didn\'t match<br>';
}
else
{
	echo 'Document content matched<br>';
}

echo '<br>The 2nd user trying to update the doc. This must fail.';
$result = saveDoc($mysqli, $userId_other, $account_id, $org_unit_id, $docId, 'updated doc name 2', 'updated test type 2', $allow_inherit_parent_permissions, 'updated test description 2', 'updated test keywords 2', 'updated test content 2', '');
validateIsFail($result);

echo '<br>setDocSecurityGroupPermissions - giving the 2nd user write permissions';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $docId, 'RW');
validateIsOk($result);

echo '<br>The 2nd user trying to update the doc. This must succeed.';
$result = saveDoc($mysqli, $userId_other, $account_id, $org_unit_id, $docId, 'updated doc name 2', 'updated test type 2', $allow_inherit_parent_permissions, 'updated test description 2', 'updated test keywords 2', 'updated test content 2', '');
validateIsOk($result);

echo '<br>Reading the doc updated by the 2nd user';
$result = getDoc($mysqli, $userId, $account_id, $docId, '');
validateIsOk($result);

if ($result['data']['document_id'] != $docId ||
	$result['data']['account_id'] != $account_id ||
	$result['data']['org_unit_id'] != $org_unit_id ||
	$result['data']['name'] != 'updated doc name 2' ||
	$result['data']['type'] != 'updated test type 2' ||
	$result['data']['description'] != 'updated test description 2' ||
	$result['data']['keywords'] != 'updated test keywords 2' ||
	$result['data']['content'] != 'updated test content 2')
{
	echo 'Document content didn\'t match<br>';
}
else
{
	echo 'Document content matched<br>';
}

echo '<br>deleteDoc - 2nd user trying to delete the document. It must fail.';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $docId, true);
validateIsFail($result);

echo '<br>setDocSecurityGroupPermissions - giving the 2nd user delete permissions';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $docId, 'RWD');
validateIsOk($result);

echo '<br>deleteDoc - 2nd user trying to delete the document. It must succeed.';
echo 'Deleting id: ' . $docId;
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $docId, true);
validateIsOk($result);

////////////////////////////////////////////////////////////////////////////////////	
// Folder functions
////////////////////////////////////////////////////////////////////////////////////	

echo '<h3>Folder functions</h3>';

echo '<br>Create a parent document - type "parentDocType"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'parentDocType', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$parentDocId = $result['data']['document_id'];

// save a child doc and add it to a parentDoc
echo '<br>Creating a sample doc';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'childDocType', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$docId = $result['data']['document_id'];

echo '<br>ParentDoc: ' . $parentDocId;
echo '<br>ChildDocId: ' . $docId;

echo '<br>Adding the newly created document to the parent document';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $docId, $parentDocId);
validateIsOk($result);
var_dump($result);

echo '<br>Getting the child documents of the parent doc. Should return 1 document.';
$result = getChildDocs($mysqli, $userId, $parentDocId);
if (count($result['data']) != 1)
	$result["status"] = "Error. Wrong docs count returned";
validateIsOk($result);

echo '<br>Getting the child documents count as 2nd user. It should return 0 documents.';
$result = getChildDocs($mysqli, $userId_other, $parentDocId);
if (count($result['data']) != 0)
	$result["status"] = "Error. Wrong docs count returned";
validateIsOk($result);

echo '<br>Getting the parent doc as 2nd user. It should fail';
$result = getDoc($mysqli, $userId_other, $account_id, $parentDocId, '');
validateIsFail($result);

echo '<br>Giving the 2nd user read permissions to the child doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $docId, 'R');

validateIsOk($result);

echo '<br>Getting the child docs count as 2nd user. It should fail because the 2nd user cannot read the parent doc.';
$result = getChildDocs($mysqli, $userId_other, $parentDocId);
if (count($result['data']) != 0)
	$result["status"] = "Error. Wrong docs count returned";
validateIsOk($result);

echo '<br>Giving the 2nd user read permissions to the parent doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $parentDocId, 'R');
validateIsOk($result);

echo '<br>Getting the parent doc as 2nd user. It should pass';
$result = getDoc($mysqli, $userId_other, $account_id, $parentDocId, '');
validateIsOk($result);

echo '<br>Getting the child docs count as 2nd user. It should pass because the 2nd user now has inherited read permissions from the parent doc.';
$result = getChildDocs($mysqli, $userId_other, $parentDocId);
if (count($result['data']) != 1)
	$result["status"] = "Error. Wrong docs count returned";
validateIsOk($result);

echo '<br>Delete parent doc without permissions - 2nd user trying to delete the new parent doc. It will fail.';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $parentDocId, true);
validateIsFail($result);

echo '<br>deleteDoc - the 2nd user deleting the child doc. It will fail.';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $parentDocId, true);
validateIsFail($result);

echo '<br>Giving the 2nd user delete permissions to the parent doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $parentDocId, 'D');
validateIsOk($result);

echo '<br>delete parent doc - the 2nd user deleting the parent doc. It will pass because the user had delete permissions on the parent doc.';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $parentDocId, true);
validateIsOk($result);

echo '<br>Getting the parentDoc, it must fail because the doc was deleted';
$result = getDoc($mysqli, $userId_other, $account_id, $parentDocId, '');
validateIsFail($result);

echo '<br>Getting the doc, it must fail because the doc was deleted with the deletion of the parent doc';
$result = getDoc($mysqli, $userId_other, $account_id, $docId, '');
validateIsFail($result);

echo '<br>3 level tests - create a workspace document - type "workspace"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'workspace', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$workspaceDocId = $result['data']['document_id'];

echo '<br>3 level tests - create a book document - type "book"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'book', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$bookDocId = $result['data']['document_id'];

echo '<br>Adding the book doc to the workspace doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $bookDocId, $workspaceDocId);
validateIsOk($result);

echo '<br>3 level tests - create a document - type "table"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'table', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$tableDocId = $result['data']['document_id'];

echo '<br>Adding the table doc to the book doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $tableDocId, $bookDocId);
validateIsOk($result);

echo '<br>Remove the table doc from the book doc';
$result = removeDocFromParentDoc($mysqli, $userId, $account_id, $tableDocId, $bookDocId);
validateIsOk($result);

echo '<br>Delete workspace doc as 2nd user. It must fail.';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $workspaceDocId, true);
validateIsFail($result);

echo '<br>Delete workspace doc. as 1st user. It must succeed.';
$result = deleteDocRecursive($mysqli, $userId, $account_id, $workspaceDocId, true);
validateIsOk($result);

echo '<br>Try getting the book, it must fail because the doc was deleted along with the workspace';
$result = getDoc($mysqli, $userId, $account_id, $bookDocId, '');
validateIsFail($result);

echo '<br>Try getting the table, it must pass because the book doc was deleted along with the workspace but the table was detatched from the book';
$result = getDoc($mysqli, $userId, $account_id, $tableDocId, '');
validateIsOk($result);
var_dump($result);
echo "<br>";

echo '<br>Delete book doc';
$result = deleteDocRecursive($mysqli, $userId, $account_id, $bookDocId, true);
validateIsFail($result);

echo '<br>Delete table doc.';
$result = deleteDocRecursive($mysqli, $userId, $account_id, $tableDocId, true);
var_dump($result);
validateIsOk($result);

////////////////////////////////////////////////////////////////////////////////////	
// Account document access functions
////////////////////////////////////////////////////////////////////////////////////	
echo '<h3>Account functions</h3>';

echo '<br>Create account';

$result = create_account($mysqli_users, $userId, "account 1", "customer 1", "admin@customer1.com", "address", "city", "state", "zip", "000-000-0000");
validateIsOk($result);

$new_account_id = $result['data']['id'];

echo '<br>Get account';
$result = get_account($mysqli_users, $userId, $new_account_id);
if ($result['status'] == false || 
	!isset($result['data']) ||
	$result['data']['id'] != $new_account_id ||
	$result['data']['name'] != 'account 1' ||
	$result['data']['customer_name'] != 'customer 1' ||
	$result['data']['email'] != 'admin@customer1.com')
	{
		$result['status'] = false;
	}

$account_primary_security_group = $result['data']['primary_security_group'];
validateIsOk($result);

echo '<br>User 1 creates a document';
$result = saveDoc($mysqli, $userId, $new_account_id, $org_unit_id, -1, 'doc name', 'workbook', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$docId = $result['data']['document_id'];

echo '<br>User 1 reads the document document';
$result = getDoc($mysqli, $userId, $new_account_id, $docId, '');
var_dump($result);
validateIsOk($result);

echo '<br>User 1 reads the document under personal account. Must fail';
$result = getDoc($mysqli, $userId, -1, $docId, '');
var_dump($result);
validateIsFail($result);

echo '<br>User 1 deletes the document under personal account. Must fail';
$result = deleteDocRecursive($mysqli, $userId, -1, $docId, false);
var_dump($result);
validateIsFail($result);

echo '<br>User 1 deletes the document under new account. Must pass';
$result = deleteDocRecursive($mysqli, $userId, $new_account_id, $docId, false);
var_dump($result);
validateIsOk($result);


echo '<br>User 1 creates a document';

echo '<br>User 1 shared the document with user2 - must fail';

echo '<br>Add user 2 to the account';

echo '<br>User 1 shared the document with user2 - must pass';

echo '<br>User 2 reads the document - must fail';

echo '<br>User 2 overwrites the document - must fail';

echo '<br>User 2 deletes the document - must fail';

echo '<br>User 2 reads the document as a member of the new account - must pass';

echo '<br>User 2 overwrites the document as a member of the new account - must pass';

echo '<br>User 2 deletes the document as a member of the new account - must pass';

////////////////////////////////////////////////////////////////////////////////////
// Security group inheritance functions
////////////////////////////////////////////////////////////////////////////////////

function validatePermission($result, $group, $permissions_flag)
{
	$permission_pass = false;
	
	$finalResult = array('data' => "", 'status' => false, 'error' => "");
	
	if ($result['status'] == true)
	{
		echo "<br>Testing for " . $permissions_flag . " permissions";
		
		$inner_result = $result['data'];
		for ($i = 0; $i < count($inner_result); $i++)
		{
			$record = $inner_result[$i];
			if ($record['security_group_id'] == $group &&
				strpos($record['permissions_mask'], $permissions_flag) !== false)
				{
					$permission_pass = true;
					echo "<br>";
					var_dump($record);
					echo "<br>";
					break;
				}
		}
		
		if ($permission_pass == true)
		{	
			$finalResult['status'] = true;
		}
		else {
			$finalResult['status'] = false;
			$finalResult['error'] = "Permission flag " . $permissions_flag . " is not present";
		}
	}

	return $finalResult;
}

echo '<h3>Performance tests</h3>';

$timestart = time();

echo '<br>Create a workspace document - type "workspace"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'workspace', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$workspaceDocId = $result['data']['document_id'];

echo '<br>Create 1000 book documents and add them to the workspace';
for ($i = 0; $i < 100; $i++)
{
	$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'book', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
	validateIsOk($result);
	$bookDocId = $result['data']['document_id'];

	$result = addDocToParentDoc($mysqli, $userId, $account_id, $bookDocId, $workspaceDocId);
	validateIsOk($result);
	
	$result = getDoc($mysqli, $userId, $account_id, $bookDocId, '');
	validateIsOk($result);
}

echo "<br>Time diff: " . (time() - $timestart) . " seconds<br>";

$timestart = time();
echo '<br>Delete workspace doc to cleanup all';
$result = deleteDocRecursive($mysqli, $userId, $account_id, $workspaceDocId, true);
validateIsOk($result);
echo "<br>Time diff: " . (time() - $timestart) . " seconds<br>";

$workspaceDocId = null;


echo '<h3>Security group inheritance tests</h3>';

echo '<br>Create a workspace document - type "workspace"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'workspace', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$workspaceDocId = $result['data']['document_id'];

echo '<br>Create a book document - type "book"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'book', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$bookDocId = $result['data']['document_id'];

echo '<br>Adding the book doc to the workspace doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $bookDocId, $workspaceDocId);
validateIsOk($result);

echo '<br>Create a document - type "table"';
$result = saveDoc($mysqli, $userId, $account_id, $org_unit_id, -1, 'doc name', 'table', $allow_inherit_parent_permissions, 'test description', 'test keywords', 'test content', '');
validateIsOk($result);
$tableDocId = $result['data']['document_id'];

echo '<br>Adding the table doc to the book doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $tableDocId, $bookDocId);
validateIsOk($result);

echo '<br>Giving the 2nd user read permissions to the workspace doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $workspaceDocId, 'R');
validateIsOk($result);

echo '<br>Validate that the 2nd user gets implicit read permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
var_dump($result);
validateIsOk($result);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "R"));


echo '<br>Getting the workspace child docs as 2nd user.';
$result = getChildDocs($mysqli, $userId_other, $workspaceDocId);
validateIsOk($result);
var_dump($result);

echo '<br>Giving the 2nd user write and delete permissions to the book doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $bookDocId, 'WD');
validateIsOk($result);

echo '<br>Validate that the 2nd user gets implicit write and delete permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsOk($result);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "W"));
validateIsOk(validatePermission($result, $user2_primariy_group_id, "D"));

echo '<br>2nd user updating the table - must pass due to implicit write permissions`"';
$result = saveDoc($mysqli, $userId_other, $account_id, $org_unit_id, $tableDocId, 'doc name', 'table', $allow_inherit_parent_permissions, 'test description 2', 'test keywords 2', 'test content 2', '');
validateIsOk($result);

echo '<br>Revoking the 2nd user permissions to the workspace doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $workspaceDocId, '');
validateIsOk($result);

echo '<br>Revoking the 2nd user permissions to the book doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $bookDocId, '');
validateIsOk($result);

echo '<br>Validate that the 2nd user doesn not have any implicit permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsOk($result);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "R"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "W"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "D"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "S"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>2nd user trying to delete table doc';
$result = deleteDocSelf($mysqli, $userId_other, $account_id, $tableDocId, true);
validateIsFail($result);

echo '<br>2nd user trying to read the workspace doc - should fail';
$result = getDoc($mysqli, $userId_other, $account_id, $workspaceDocId, '');
validateIsFail($result);

echo '<br>Giving the 2nd user read to the table doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $tableDocId, 'R');
validateIsOk($result);

echo '<br>Validate that the 2nd user gets implicit read permissions to the book doc';
$result = getDocSecurityInfoAll($mysqli, $bookDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Validate that the 2nd user gets implicit read permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Removing the table doc from the book doc';
$result = removeDocFromParentDoc($mysqli, $userId, $account_id, $tableDocId, $bookDocId);
validateIsOk($result);

echo '<br>Validate that the 2nd user no longer has any permissions to the book doc';
$result = getDocSecurityInfoAll($mysqli, $bookDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "R"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "W"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "D"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "S"));


echo '<br>Validate that the 2nd user no longer has any permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "R"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "W"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "D"));
validateIsFail(validatePermission($result, $user2_primariy_group_id, "S"));

echo '<br>Adding the table doc back to the book doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $tableDocId, $bookDocId);
validateIsOk($result);

echo '<br>Validate that the 2nd user no longer has any permissions to the book doc';
$result = getDocSecurityInfoAll($mysqli, $bookDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Validate that the 2nd user no longer has any permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Revoking the 2nd user permissions to the table doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $tableDocId, '');
validateIsOk($result);

echo '<br>Validate that the 2nd user doesn not have read permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "R"));

echo '<br>Validate that the 2nd user does not have I permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));


echo '<br>Giving the 2nd user read to the book doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $bookDocId, 'R');
validateIsOk($result);

echo '<br>Validate that the 2nd user gets read permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "R"));

echo '<br>Validate that the 2nd user gets I permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Removing the book doc from the workspace doc';
$result = removeDocFromParentDoc($mysqli, $userId, $account_id, $bookDocId, $workspaceDocId);
validateIsOk($result);

echo '<br>Validate that the 2nd user still has read permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "R"));

echo '<br>Validate that the 2nd user no longer has I permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Adding the book back to the workspace doc';
$result = addDocToParentDoc($mysqli, $userId, $account_id, $bookDocId, $workspaceDocId);
validateIsOk($result);

echo '<br>Validate that the 2nd user still has read permissions to the table doc';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "R"));

echo '<br>Validate that the 2nd user gets again I permissions to the workspace doc';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsOk(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Delete book doc as 2nd user - must fail';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $bookDocId, true);
validateIsFail($result);

echo '<br>Giving the 2nd user delete permissions to the book doc';
$result = setDocSecurityGroupPermissions($mysqli, $userId, $user2_primariy_group_id, $bookDocId, 'D');
validateIsOk($result);

echo '<br>Delete book doc as 2nd user - must pass';
$result = deleteDocRecursive($mysqli, $userId_other, $account_id, $bookDocId, true);
validateIsOk($result);

echo '<br>Validate that the 2nd user does not have has read permissions to the table doc. Actually the table is deleted.';
$result = getDocSecurityInfoAll($mysqli, $tableDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "R"));

echo '<br>Validate that the 2nd user no longer has I permissions to the workspace doc because the book is deleted';
$result = getDocSecurityInfoAll($mysqli, $workspaceDocId, true);
validateIsFail(validatePermission($result, $user2_primariy_group_id, "I"));

echo '<br>Delete workspace doc to cleanup all';
$result = deleteDocRecursive($mysqli, $userId, $account_id, $workspaceDocId, true);
validateIsOk($result);

cleanup:

echo '<h3>Cleaning up users required for testing.</h3>';

echo '<br>Hard delete the user1.';
$result = hardDeleteUser($mysqli_users, $userId);
validateIsOk($result);

echo '<br>Hard delete the user2.';
$result = hardDeleteUser($mysqli_users, $userId_other);
validateIsOk($result);


echo 'CloudDocs DB Disconnect<br>';
db_disconnect($mysqli);

echo 'Users DB Disconnect<br>';
db_disconnect($mysqli_users);


////////////////////////////////////////////////////////////////////////////////////	
// Users functions
////////////////////////////////////////////////////////////////////////////////////	
echo 'Users DB Connect<br>';
$mysqli_users = db_connect($users_db_hostname, $users_db_username, $users_db_password, $users_db_database);

$email = 'user1@gmail.com';
$email_other = 'user2@gmail.com';
$password = 'some password';

echo '<h3>Users functions</h3>';

echo '<br>Creating a new user';
$result = upsertUser($mysqli_users, -1, 'some mail', $email, $password);
validateIsOk($result);

echo '<br>Validating the new user id';
$innerResult = $result['data'];
$userId = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
if ($userId == -1)
	$result["status"] = "Error";
validateIsOk($result);

echo '<br>Creating the same user again. It should return the id of the original user.';
$result = upsertUser($mysqli_users, -1, 'some mail', $email, $password);
validateIsOk($result);

echo '<br>Validating the match of the original user id';
$innerResult = $result['data'];
$userId_again = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
if ($userId != $userId_again)
	$result["status"] = "Error";
validateIsOk($result);



echo '<br>Soft delete the new user.';
$result = softDeleteUser($mysqli_users, $userId);
validateIsOk($result);

echo '<br>Hard delete the new user.';
$result = hardDeleteUser($mysqli_users, $userId);
validateIsOk($result);

echo '<br>Users DB Disconnect<br>';
db_disconnect($mysqli_users);

////////////////////////////////////////////////////////////////////////////////////	
// Group functions
////////////////////////////////////////////////////////////////////////////////////	
echo '<h3>Group functions</h3>';

echo 'Users DB Connect<br>';
$mysqli_users = db_connect($users_db_hostname, $users_db_username, $users_db_password, $users_db_database);


echo '<br>Creating a new user';
$result = upsertUser($mysqli_users, -1, 'some mail', $email, $password);
$innerResult = $result['data'];
$userId = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
validateIsOk($result);

echo '<br>Confirm the 1st user email';
$result = updateUserAccountActivation($mysqli_users, $email, 0, 0);
validateIsOk($result);


echo '<br>Creating a 2nd user';
$result = upsertUser($mysqli_users, -1, 'some mail', $email_other, $password);
$innerResult = $result['data'];
$userId_other = isset($innerResult['user_id']) ? $innerResult['user_id'] : -1;
validateIsOk($result);

echo '<br>Confirm the 2nd user email';
$result = updateUserAccountActivation($mysqli_users, $email_other, 0, 0);
validateIsOk($result);

echo '<br>Creating group';
$result = createGroup($mysqli_users, $userId, 'My group');
validateIsOk($result);

echo '<br>Renaming group';
$result = renameGroup($mysqli_users, $userId, 'My group', 'My new group');
validateIsOk($result);

echo '<br>Adding a user to the group';
$result = addUserToGroup($mysqli_users, $userId_other, 'My new group', 1, $userId);
validateIsOk($result);

echo '<br>Getting a list of the group members';
$result = getGroupMembers($mysqli_users, $userId, 'My new group');
validateIsOk($result);


echo '<br>Removing user from the group';
$result = deleteUserFromGroup($mysqli_users, $userId_other, 'My new group', $userId);
validateIsOk($result);

echo '<br>Removing user from the group. The requestor doesn\'t have permissions';
$result = deleteUserFromGroup($mysqli_users, $userId, 'My new group', $userId);
validateIsFail($result);

echo '<br>Adding a user to the group';
$result = addUserToGroup($mysqli_users, $userId_other, 'My new group', 0, $userId);
validateIsOk($result);

echo '<br>Making the new user administrator';
$result = setUserGroupAdminFlag($mysqli_users, $userId_other, 'My new group', 1, $userId);
validateIsOk($result);

echo '<br>Removing user from the group';
$result = deleteUserFromGroup($mysqli_users, $userId, 'My new group', $userId_other);
validateIsOk($result);

echo '<br>Revoking user administrator permissions. The user doesn\'t exist.';
$result = setUserGroupAdminFlag($mysqli_users, $userId_other, 'My new group', 0, $userId_other);
validateIsFail($result);

echo '<br>Deleting group';
$result = deleteGroup($mysqli_users, $userId_other, 'My new group');
validateIsOk($result);

echo '<br>Hard delete the user1.';
$result = hardDeleteUser($mysqli_users, $userId);
validateIsOk($result);

echo '<br>Hard delete the user2.';
$result = hardDeleteUser($mysqli_users, $userId_other);
validateIsOk($result);


echo '<br>Users DB Disconnect<br>';
db_disconnect($mysqli_users);

echo '<br><br><b>Final results:<br>';
echo '<br>Passed: ' . $passedCount;
echo '<br>Failed: ' . $failedCount;
echo '</b>';

?>