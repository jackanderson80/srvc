<?php
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../srv/config/app_config.php";

setlocale(LC_ALL, 'en_US.UTF8');

function mongodb_getRecord($tableName, $key)
{
	try
	{
		$db = (new MongoDB\Client)->teamblocks_tables;

		$collection = $db->selectCollection($tableName);
		if (null == $collection)
			return null;
		
		$record = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($key)]);
		
		if (null != $record)
			return $record['data'];
		
	}
	catch(Exception $error)
	{
		echo $error;
	}
	
	return null;
}

function mongodb_deleteRecord($tableName, $key)
{
	return mongodb_internal_setRecord($tableName, $key, null);
}

function mongodb_addRecord($tableName, $value)
{
	return mongodb_internal_setRecord($tableName, null, $value);
}

function mongodb_updateRecord($tableName, $key, $value)
{
	return mongodb_internal_setRecord($tableName, $key, $value);
}

function mongodb_internal_setRecord($tableName, $key, $value)
{
	try
	{
		$connection = (new MongoDB\Client);
		$db = $connection->teamblocks_tables->listCollections();
		
		$collection = $connection->teamblocks_tables->selectCollection($tableName);
		if (null == $collection)
			return null;
		
		if ($key != null && $value == null)
		{
			$deleteResult = $collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($key)]);
			if ($deleteResult->getDeletedCount() == 1)
				return $key;
			
			return null;
		}
		
		if ($key == null)
		{
			$insertOneResult = $collection->insertOne($value);
			$key = $insertOneResult->getInsertedId();
			return $key;
		}
				
		$updateResult = $collection->replaceOne(
			[ '_id' => $key ],
			$value,
			array('upsert' => true)
		);
		
		if ($updateResult->getModifiedCount() == 0)
			return null;
		
		return $key;
	}
	catch(Exception $error)
	{
		return null;
	}
	
	return $key;
}

?>

