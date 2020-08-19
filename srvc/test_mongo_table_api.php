<?php

require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/../srv/config/config.php";

require_once __DIR__ . '/mongo_table_api.php';


function validateIsOk($result)
{
	if ($result['status'] != true || $result['error'] != '')
	{
		echo ' - <span style=\'color: red;\'>FAILED</span><br>';
		var_dump($result);
	}
	else
	{
		echo ' - <span style=\'color: green;\'>PASSED</span><br>';
	}	
}

function validateIsFail($result)
{
	if ($result['status'] == true)
	{
		echo ' - <span style=\'color: red;\'>FAILED (Expected Error but status is \'Ok\')</span><br>';		
	}
	else if ($result['status'] != true && $result['error'] != '')
	{
		echo ' - <span style=\'color: green;\'>PASSED (Expected Error)</span><br>';
	}	
	
	var_dump($result);
	echo '<br>';
}



echo 'Set record<br>';
$key = mongodb_addRecord("docs", ["data" => "This is test 2"]);

echo '<br>key = ' . $key . "<br>";

$data = mongodb_getRecord("docs", $key);
var_dump($data);

echo '<br>deleting key = ' . $key . "<br>";

mongodb_deleteRecord("docs", $key);

echo '<br>getting record for key = ' . $key . "<br>";
$data = mongodb_getRecord("docs", $key);
var_dump($data);

?>