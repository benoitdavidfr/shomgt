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
name: externalGboxForNonGeoLoc
title: "externalGboxForNonGeoRef(int $width, int $height, array $borders, GBox $internalGBox): GBox - traite les pdf non géo-référencés"
doc: |
  Plusieurs cartes livrées par le Shom sont en PDF non géo-référencés.
  Ces cartes peuvent être géo-référencées en renseignant dans le catalogue mapcat les largeurs des bords.
  Le principe du traitement est de calculer par projection la boite intérieure en WorldMercator,
  d'en déduire la boite extérieure en WorldMercator en rajoutant les largeurs des marges
  et enfin de repasser en coordonnées géo. pour obtenir le géo-référencement du fichier.
*/
function externalGboxForNonGeoRef(int $width, int $height, Borders $borders, GBox $inGBox): GBox {
  $wombox = $inGBox->proj('WorldMercator'); // le cadre intérieur en coord. WorldMercator
  //print_r($wombox);
  
  // Calcul de la taille du pixel en projection WorldMercator
  $pixelSizeLat = ($wombox->north() - $wombox->south()) / ($height - $borders->top() - $borders->bottom());
  //echo "pixelSizeLat=$pixelSizeLat\n";
  $pixelSizeLon = ($wombox->east() - $wombox->west()) / ($width - $borders->left() - $borders->right());
  //echo "pixelSizeLon=$pixelSizeLon\n";
  
  // Ajout des marges pour déterminer la boite extérieure
  $wombox->setWest($wombox->west() - $borders->left() * $pixelSizeLon);
  $wombox->setEast($wombox->east() + $borders->right() * $pixelSizeLon);
  $wombox->setSouth($wombox->south() - $borders->bottom() * $pixelSizeLat);
  $wombox->setNorth($wombox->north() + $borders->top() * $pixelSizeLat);
  
  // calcul de la boite extérieure en coordonnées géographiques
  $extgbox = $wombox->geo('WorldMercator');
  //print_r($gbox); die();
  return $extgbox;
}

/*PhpDoc: functions
name: gdalinfo
title: "function gdalinfo(string $currentpath, string $gtname): array - extrait le contenu du fichier gdalinfo"
doc: |
  retourne normalement ['width'=> {width}, 'height'=> {height}, 'gbox'=> {gbox}?]
  4 cas de figure
    - si le fichier est absent ou non conforme alors levée d'une exception
    - si le fichier ne contient pas le géoréférencement alors ne retourne que width et height
    - si le fichier contient le géoréférencement alors retourne les 3 paramètres avec -180 <= west < east <= 180
    - sauf si le rectangle de géoréférencement est à cheval sur l'anti-méridien alors rajoute 360° à east
      pour que -180 <= west < 180 < east <= 540

  Traite aussi le cas particulier du planisphère qui est à cheval sur l'anti-méridien mais pour lequel west < east
  dans ce dernier cas aussi ajout de 360° à east
  Ce dernier cas est détecté en regardant si la longitude du centre est bien comprise entre les extrêmes 
*/
function gdalinfo(string $currentpath, string $gtname): array {
  if (!($info = @file_get_contents("$currentpath/$gtname.info")))
    throw new Exception("Erreur d'ouverture de $currentpath/$gtname.info");
  //die($info);

  $pattern = '!Size is (\d+), (\d+)!';
  if (!preg_match($pattern, $info, $matches))
    throw new Exception("No match for $filepath Size is\n$info");
  $width = $matches[1];
  $height = $matches[2];
  
  $pattern = '!Upper Right \(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]),\s*([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches)) {
    if (!preg_match('!\(\s*(-?[\d.]+),\s*(-?[\d.]+)\)!', $info))
      throw new Exception("No match for $filepath Upper Right\n$info");
    // tiff non géoréférencé
    return [
      'width'=> $width,
      'height'=> $height,
    ];
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
    'width'=> $width,
    'height'=> $height,
    'gbox'=> new GBox([$west, $south, $east, $north]),
  ];
}
