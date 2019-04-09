<?php
/*PhpDoc:
name: gdalinfo.inc.php
title: gdalinfo.inc.php - la fonction gdalinfo() extrait d'un fichier gdalinfo les infos pertinentes et les structure
functions:
doc: |
journal: |
  9/4/2019:
    traitement du cas particulier de FR0101
*/

// traduit en degré décimal la coordonnée géographique fournie par gdalinfo
function todecdeg($val): float {
//  echo "val=$val<br>\n";
  if (is_numeric($val))
    return $val;
  if (preg_match('!^\s*([^d]+)d([^\']+)\'([^"]+)"(E|W|N|S)!', $val, $matches)) {
    //echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + ($matches[2] + $matches[3]/60)/60;
    return (in_array($matches[4], ['E','N']) ? $decdeg : -$decdeg); 
  } elseif (preg_match('!^([^d]+)d([^\']+)\'(E|W|N|S)!', $val, $matches)) {
    //echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + $matches[2]/60;
    return (in_array($matches[3], ['E','N']) ? $decdeg : -$decdeg); 
  } else
    throw new Exception("nomatch in todecdeg for !$val!");
}

/*PhpDoc: functions
name: gdalinfo
title: "function gdalinfo(string $filepath): array - extrait du fichier gdalinfo ['gbox'=> {gbox}, 'width'=> {width}, 'height'=> {height} ]"
doc: |
  Si ces coordonnées sont absentes alors retourne [], le GéoTiff n'est pas géolocalisé
  De manière générale -180 <= west < east <= 180
  Traite 2 cas particuliers:
    - si le rectangle est à cheval sur l'anti-méridien alors rajout de 360° à east pour que -180 <= west < east <= 540
    - cas du planisphère qui est à cheval sur l'anti-méridien mais pour lequel west < east
      dans ce dernier cas aussi ajout de 360° à east
      ce dernier cas est détecté en regardant si la longitude du centre est bien comprise entre les extrêmes 
*/
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
  
  //Center      (33205505.112,  666284.369) ( 61d42'35.54"W,  6d 0'52.01"N)
  $pattern = '!Center +\(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]), ([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches))
    die("No match for $filepath Center\n$info");
  $xc = $matches[1];
  $yc = $matches[2];
  $lonc = todecdeg($matches[3]);
  $latc = todecdeg($matches[4]);
  
  if (($lonc < $west) || ($lonc > $east)) // test permettant de détecter le cas du planisphère
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
