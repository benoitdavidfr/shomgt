<?php
/*PhpDoc:
name: index.php
title: index.php - page d'accueil
includes: [ lib/accesscntrl.inc.php ]
doc: |
journal: |
  10/8/2023:
    report dans Access du message lors du refus d'accès
  9/11/2019
    amélioration du controle d'accès
  1/11/2019
    nlle version
  11/6/2017
    chgt de l'URL initiale
  9/6/2017
    création
*/
require_once __DIR__.'/lib/accesscntrl.inc.php';

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>cartes shom</title></head>\n";

//echo "Fichier ",__FILE__,", ligne ",__LINE__,"<br>\n";
try {
  if (Access::cntrlFor('homePage') && !Access::cntrl()) {
    header('HTTP/1.1 403 Forbidden');
    die(str_replace('{adip}', $_SERVER['REMOTE_ADDR'], Access::FORBIDDEN_ACCESS_MESSAGE));
  }
}
catch (Exception $e) {
  echo "<pre>e="; print_r($e);
  die("Erreur dans le contrôle d'accès au serveur\n");
}

// liste de points de départ possibles (latitude,longitude, zoom)
$starts = [
  [ 'center'=>'43,6.2', 'zoom'=>11 ],  // Hyères
  [ 'center'=>'43,6', 'zoom'=>8 ], // Cote d'Azur
  [ 'center'=>'48,-4', 'zoom'=>8 ], // Bretagne
  [ 'center'=>'47.8,-4.1', 'zoom'=>12 ], // Bénodet
  [ 'center'=>'48.75,-4.0', 'zoom'=>12 ], // Roscoff
  [ 'center'=>'48.0,-5.0', 'zoom'=>9 ], // Brest
  [ 'center'=>'50.9,1.3', 'zoom'=>10 ], // Pas de Calais
  [ 'center'=>'46.0,-1.5', 'zoom'=>10 ], // Iles de Ré et d'Oléron
  [ 'center'=>'46.0,-1.5', 'zoom'=>5 ], // Europe
  [ 'center'=>'16.21,-61.53', 'zoom'=>12 ], // Guadeloupe
  [ 'center'=>'14.60,-61.05', 'zoom'=>13 ], // Martinique
  [ 'center'=>'4.93,-52.30', 'zoom'=>9 ], // Guyane
  [ 'center'=>'-20.94,55.30', 'zoom'=>11 ], // Réunion
  [ 'center'=>'-12.78,45.25', 'zoom'=>12 ], // Mayotte
  [ 'center'=>'-9.6928,-139.0', 'zoom'=>9 ], // Iles Marquise
  [ 'center'=>'-23.133,-135.0', 'zoom'=>11 ], // Iles Gambier
  [ 'center'=>'-14.2867,-178.1325', 'zoom'=>13 ], // Ile Futuna
  [ 'center'=>'-14.2867,-178.1325', 'zoom'=>13 ], // Ile Futuna (le dernier doit être répété)
];
$start = $starts[floor(rand(0,count($starts)-1))];

echo <<<EOT
<frameset cols="50%,50%" >
  <frame src="index2.php" name="main">
  <frame src="shomgt/mapwcat.php?center=$start[center]&zoom=$start[zoom]" name="map">
  <noframes>
  	<body>
  		<p><a href='index2.php'>Accès sans frame</p>
  	</body>
  </noframes>
</frameset>

EOT;
