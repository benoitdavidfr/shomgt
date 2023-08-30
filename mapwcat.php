<?php
/*PhpDoc:
title: mapwcat.php - renvoi vers view/mapwcat.php pour utilisation sur geoapi
name: mapwcat.php
*/

//echo "<pre>"; print_r($_SERVER);
$request_scheme = ($_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http');
$dirname = dirname($_SERVER['SCRIPT_NAME']);
$location = "$request_scheme://$_SERVER[HTTP_HOST]"
  .($dirname=='/' ? '/' : "$dirname/")
  .'view/mapwcat.php'
  .(isset($_SERVER['QUERY_STRING']) ? "?$_SERVER[QUERY_STRING]" : '');
//die("location=$location");

header('HTTP/1.1 302 Found');
header("Location: $location");
