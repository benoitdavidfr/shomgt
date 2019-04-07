<?php
/*PhpDoc:
name: gdalinfo.inc.php
title: gdalinfo.inc.php - la fonction gdalinfo() extrait d'un fichier gdalinfo les infos pertinentes et les structure
classes:
doc: |
*/

// traduit en degré décimal la coordonnée géographique fournie par gdalinfo
function todecdeg($val): float {
//  echo "val=$val<br>\n";
  if (is_numeric($val))
    return $val;
  if (preg_match('!^\s*([^d]+)d([^\']+)\'([^"]+)"(E|W|N|S)!', $val, $matches)) {
//    echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + ($matches[2] + $matches[3]/60)/60;
    return (in_array($matches[4], ['E','N']) ? $decdeg : -$decdeg); 
  } elseif (preg_match('!^([^d]+)d([^\']+)\'(E|W|N|S)!', $val, $matches)) {
//    echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + $matches[2]/60;
    return (in_array($matches[3], ['E','N']) ? $decdeg : -$decdeg); 
  } else
    throw new Exception("nomatch in todecdeg for !$val!");
}

// extrait du fichier gdalinfo ['gbox'=> {gbox}, 'width'=> {width}, 'height'=> {height} ]
// Si ces coordonnées sont absentes alors retourne [], le GéoTiff n'est pas géolocalisé
function gdalinfo(string $filepath): array {
  if (!($info = @file_get_contents($filepath)))
    throw new Exception("Erreur d'ouverture de $filepath");
  //die($info);

  $pattern = '!Size is (\d+), (\d+)!';
  if (!preg_match($pattern, $info, $matches))
    throw new Exception("No match for $filepath Size is\n$info");
  $width = $matches[1];
  $height = $matches[2];
  
  $pattern = '!Upper Right \(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]),\s*([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches)) {
    if (preg_match('!\(\s*(-?[\d.]+),\s*(-?[\d.]+)\)!', $info))
      return []; // le GéoTiff n'est pas géolocalisé
    else
      throw new Exception("No match for $filepath Upper Right\n$info");
  }
  $xmax = $matches[1];
  $ymax = $matches[2];
  $east = todecdeg($matches[3]);
  $north = todecdeg($matches[4]);
  $pattern = '!Lower Left  \(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]), ([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches))
    die("No match for $filepath Lower Left\n$info");
  $xmin = $matches[1];
  $ymin = $matches[2];
  $west = todecdeg($matches[3]);
  $south = todecdeg($matches[4]);
  if ($east < $west)
    $east += 360.0;
  if ($north < $south)
    throw new Exception("Erreur dans gdalinfo, north=$north < south=$south");
  return [
    'gbox'=> new GBox([$west, $south, $east, $north]),
    //'ebox'=> new EBox([$xmin, $ymin, $xmax, $ymax]),
    'width'=> $width,
    'height'=> $height,
  ];
}
