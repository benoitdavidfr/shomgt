<?php
/*PhpDoc:
name: index.php
title: index.php - page d'accueil
includes: [ ws/accesscntrl.inc.php ]
doc: |
journal: |
  9/11/2019
    amélioration du controle d'accès
  1/11/2019
    nlle version
  11/6/2017
    chgt de l'URL initiale
  9/6/2017
    création
*/
require_once __DIR__.'/ws/accesscntrl.inc.php';

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>cartes shom</title></head>\n";
//echo "<pre>config[cntrlFor]="; print_r(config('cntrlFor')); echo "</pre>";
if (Access::cntrlFor('homePage') && !Access::cntrl()) {
  $adip = $_SERVER['REMOTE_ADDR'];
  header('HTTP/1.1 403 Forbidden');
  die("<body>Bonjour,</p>
    <b>Ce site est réservé aux agents de l'Etat et de ses Etablissements publics administratifs (EPA).</b><br>
    L'accès peut s'effectuer au travers d'une adresse IP correspondant à un intranet de l'Etat ou d'un de ses EPA (RIE, ...).
    Vous accédez actuellement à ce site au travers de l'adresse IP <b>$adip</b> qui n'est pas enregistrée
    comme une adresse IP d'un tel intranet.<br>
    Si vous souhaitez accéder à ce site et que vous appartenez à un service de l'Etat ou à un de ses EPA,
    vous pouvez transmettre cette adresse IP à Benoit DAVID de la MIG (contact at geoapi.fr)
    qui regardera la possibilité d'autoriser votre accès.<br>
    Une autre possibilité est d'<a href='login.php' target='_parent'>accéder en vous authentifiant ici</a>,
    si vous disposez d'un identifiant et d'un mot de passe.  
  ");
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
];
$start = $starts[floor(rand(0,count($starts)-1))];
//$start = $starts[count($starts)-1];

echo <<<EOT
<frameset cols="50%,50%" >
  <frame src="index2.php" name="main">
  <frame src="mapwcat.php?center=$start[center]&zoom=$start[zoom]" name="map">
  <noframes>
  	<body>
  		<p><a href='index2.php'>Accès sans frame</p>
  	</body>
  </noframes>
</frameset>

EOT;
