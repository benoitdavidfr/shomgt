<?php
/*PhpDoc:
name: updatecolls.php
title: met à jour les collections passées en paramètre à partir du serveur WFS du Shom
*/
$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomft/updatecolls@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo "$HTML_HEAD<h2>Mise à jour des collections $_GET[collections] à partir du serveur WFS du Shom</h2>\n";

foreach(explode(',',$_GET['collections']) as $coll) {
  echo "Copie de la collection $coll depuis le serveur WFS du Shom<br>\n";
  $filepath = __DIR__."/$coll.json";
  //echo "date=",date('c', filemtime($filepath)),"<br>\n";
  unlink($filepath);
  //echo "<pre>"; print_r($_SERVER); echo "</pre>\n";
  $shomGtPath = dirname($_SERVER['SCRIPT_NAME']);
  $url = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$shomGtPath/ft.php/collections/$coll/items";
  //echo "url=$url <a href='$url'>$url</a><br>\n";
  file_get_contents($url);
  if ($coll == 'delmar') {
    echo "Recopie de la collection $coll dans shomgt/geojson/<br>\n";
    copy(__DIR__."/$coll.json", __DIR__."/../shomgt/geojson/$coll.geojson");
  }
}
die("Fin OK");
