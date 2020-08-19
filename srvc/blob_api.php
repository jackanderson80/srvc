<?php
require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/../srv/config/app_config.php";


setlocale(LC_ALL, 'en_US.UTF8');

function deleteBlob($docId)
{
	global $useMongoDB;
	
	if ($useMongoDB == true)
		return mongodb_deleteBlob($docId);
}


function putBlob($docId, $blobContent, $isAutoSave, $isNewDoc)
{
	global $useMongoDB;
	
	if ($useMongoDB == true)
		return mongodb_putBlob($docId, $blobContent, $isAutoSave, $isNewDoc);
	
	return null;
}

function getBlob($docId, $versionId)
{
	global $useMongoDB;
	
	if ($useMongoDB == true)
		return mongodb_getBlob($docId, $versionId);
	
	return null;
}

function getBlobVersionInfo($docId)
{
	global $useMongoDB;
	
	if ($useMongoDB == true)
		return mongodb_getBlobVersionInfo($docId);
	
	return null;
}

function setBlobVersionInfo($docId, $versionInfo)
{
	global $useMongoDB;
	
	if ($useMongoDB == true)
		return mongodb_setBlobVersionInfo($docId, $versionInfo);
	
	return null;
}

function mongodb_getBlobVersionInfo($docId)
{
	$collection = (new MongoDB\Client)->teamblocks->docversioninfo;
	$versionInfo = $collection->findOne(['documentId' => $docId]);
	if ($versionInfo == null)
	{
		$versionInfo = array('documentId' => $docId, 'min' => -1, 'max' => -1);
	}
	
	return $versionInfo;
}

function mongodb_getBlob($docId, $versionId)
{
	try
	{
		$collection = (new MongoDB\Client)->teamblocks->docs;
		$doc = $collection->findOne(['documentId' => $docId, 'version' => $versionId]);
		
		if (null != $doc)
			return $doc['blobContent'];
		
	}
	catch(Exception $error)
	{
	}
	
	return null;
}

function mongodb_setBlobVersionInfo($docId, $versionInfo)
{
	$collection = (new MongoDB\Client)->teamblocks->docversioninfo;
	if ($versionInfo['min'] == 0 && $versionInfo['max'] == 0)
	{
		$insertOneResult = $collection->insertOne($versionInfo);
	}
	else
	{
		$updateResult = $collection->replaceOne(['documentId' => $docId], $versionInfo);
	}	
}

function mongodb_putBlob($docId, $blobContent, $isAutoSave, $isNewDoc)
{
	try
	{
		$collection = (new MongoDB\Client)->teamblocks->docs;
		
		$isAutoSave = (($isAutoSave == "true") ? true : false);
		
		$versionNum = $isAutoSave ? -1 : 0;
		if (!$isNewDoc)
		{
			$versionInfo = mongodb_getBlobVersionInfo($docId);
			$versionNum = $versionInfo['max'] + 1;
		}
		
		if ($isAutoSave == false)
		{
			$insertOneResult = $collection->insertOne([
				'documentId' => $docId,
				'version' => $versionNum,
				'autoSaved' => $isAutoSave,
				'blobContent' => $blobContent
			]);
			
			$versionInfo = mongodb_getBlobVersionInfo($docId);
			
			if ($versionInfo['min'] == -1)
				$versionInfo['min'] = 0;
			if ($versionInfo['max'] == -1)
				$versionInfo['max'] = 0;
			else
				$versionInfo['max'] = $versionNum;
			
			mongodb_setBlobVersionInfo($docId, $versionInfo);
		}
		else // handle auto-save (set version to -1
		{
			if ($isNewDoc) // new doc
			{
				$insertOneResult = $collection->insertOne([
					'documentId' => $docId,
					'version' => -1,
					'autoSaved' => true,
					'blobContent' => $blobContent
				]);
			}
			else // existing doc
			{
				$updateResult = $collection->replaceOne(
					[
						'documentId' => $docId
					], 
					[
						'documentId' => $docId,
						'version' => -1,
						'autoSaved' => true,
						'blobContent' => $blobContent
					]
				);
			}
		}
		
	}
	catch(Exception $error)
	{
		$output = array('data' => '', 'status' => false, 'error' => $error);
		return $output;
	}

	$output = array('data' => '', 'status' => true, 'error' => '');
	return $output;
}

function mongodb_deleteBlob($docId)
{
	$collection = (new MongoDB\Client)->teamblocks->docs;	
	$versionscollection = (new MongoDB\Client)->teamblocks->docversioninfo;
	
	try
	{
		$deleteResult = $collection->deleteMany(['documentId' => $docId]);
		$deleteResult = $versionscollection->deleteMany(['documentId' => $docId]);
	}
	catch(Exception $error)
	{
		$output = array('data' => '', 'status' => false, 'error' => $error);
		return $output;
	}

	$output = array('data' => '', 'status' => true, 'error' => '');
	
	return $output;	
}

?>

