<?php
/** affiche les tuiles d'un GeoTiff décomposé en tuiles
 *
 * Prend en paramètre le répertoire contenant les tuiles
 * @package shomgt\view
 */
$path = $_GET['path'] ?? '.';
if (!is_file("$path/0-0.png")) {
  echo "$path n'est pas un répertoire de tuiles<br>\n";
  foreach (new DirectoryIterator($path) as $filename) {
    echo " - <a href='?path=$path/$filename'>$filename</a><br>\n";
  }
  die();
}

echo "<table><tr>\n";
$i=0;
$j=0;
while(true) {
  $tilename = sprintf('%X-%X.png', $i, $j);
  if (is_file("$path/$tilename")) {
    echo "<td><img src='$path/$tilename'></td>";
    $i++;
  }
  else {
    if ($i == 0) {
      echo "</tr></table>\n";
      break;
    }
    else {
      echo "</tr>\n<tr>";
      $i=0;
      $j++;
    }
  }
}