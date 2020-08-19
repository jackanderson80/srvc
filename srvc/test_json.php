<?php

$string = file_get_contents("tseditor_exports_3.json");
$all_content = json_decode($string, true);

$cnt = count($all_content);

echo $cnt . ' documents found<br>';

for ($i = 0; $i < $cnt; $i++)
{
		echo '<br>Parsing doc: ' . $i . ': ';

        $type = 'tseditor';
        $name = $all_content[$i]['name'];
        $description = $all_content[$i]['description'];
        $keywords = $all_content[$i]['keywords'];
        $content = $all_content[$i]['content'];
		
		$content = json_encode($content);
		$content = json_decode($content, true);

		//echo $content['editors']['systemjs.config.js'];

		echo $content['subtype'];
		
		break;
		
		if ($content == NULL)
		{
			echo 'Parse Error!';
			break;
		}
		else
		{
			echo 'Ok!';
		}
}


?>