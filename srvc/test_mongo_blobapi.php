<?php

require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/../srv/config/app_config.php";
require_once __DIR__ . "/../srv/config/db_config.php";

include "blob_api.php";


$collection = (new MongoDB\Client)->teamblocks->docs;
/*
$insertOneResult = $collection->insertOne([
    'username' => 'admin',
    'email' => 'admin@example.com',
    'name' => 'Admin User',
]);

printf("Inserted %d document(s)\n", $insertOneResult->getInsertedCount());

var_dump($insertOneResult->getInsertedId());*/

//$deleteResult = $collection->remove({'_id' => 'ObjectId("5b9560078e31a56650001462")'});

$id = '5b9567588e31a55b28002a02';

$cursor = $collection->find(['documentId' => 2662]);
//var_dump($cursor);

foreach($cursor as $obj) {
   //var_dump($obj->_id);
   echo $obj->_id . '<br>';
};

$result = deleteBlob(2791);
var_dump($result);

//$mongoId = new MongoDB\BSON\ObjectId ($id);

//echo var_dump($id);
echo "<br>";
//echo var_dump($mongoId);
//$deleteResult = $collection->deleteOne(['_id' => $mongoId]);
//$deleteResult = $collection->deleteOne(['_id' => $id]);

//printf("Deleted %d document(s)\n", $deleteResult->getDeletedCount());

?>