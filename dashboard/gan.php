<?php
/*PhpDoc:
name: gan.php
title: dashboard/gan.php - gestion des gan
classes:
functions:
doc: |
  L'objectif est de moissonner les GAN des cartes définies dans $INCOMING_PATH/../maps.json
  et de fabriquer un fichier gans.yaml/pser de synthèse

  $INCOMING_PATH est:
    - soit $SHOMGT3_DASHBOARD_INCOMING_PATH s'il est défini
    - soit $SHOMGT3_INCOMING_PATH s'il est défini
    - sinon erreur

  Le script propose en CLI:
    - de moissonner les GANs de manière incrémentale, cad uniq. les GAN absents
    - de moissonner les GANs en effacant les précédentes moissons
    - de fabriquer la synthèse en yaml/pser
    - d'afficher cette synthèse

  Certains traitements se font en cli (moissonnage), d'autres en non-CLI (affichage).

  Erreur 500 semble signfier que la carte n'est pas gérée dans le GAN, il s'agit visiblement surtout de cartes outre-mer
  Ex: https://gan.shom.fr/diffusion/qr/gan/6280/1931 - Partie Nord de Raiatea - Port d'Uturoa (1/12000)
  Le qrcode donne:
    Error Page
    status code: 404
    Exception Message: N/A

  Si un proxy est nécessaire pour interroger les GANs, il doit être défini dans ../secrets/secretconfig.inc.php (non effectif)

  Tests de analyzeHtml() sur qqs cartes types (incomplet):
    - 6616 - carte avec 2 cartouches et une correction
    - 7330 - carte sans GAN

journal: |
  2/8/2022:
    - corrections suite à PhpStan level 6
  2/7/2022:
    - ajout de la var;env. SHOMGT3_DASHBOARD_INCOMING_PATH qui permet de référencer des cartes différentes de sgserver
    - ajout dans le GAN du champ scale
  12/6/2022:
    - fork dans ShomGt3
    - restriction fonctionnelle au moissonnage du GAN et à la construction des fichiers gans.yaml/pser
      - le calcul du degré de péremption est transféré dans dashboard/index.php
    - le lien avec la liste des cartes du portefuille est effectué par la fonction maps() qui lit la liste des cartes
      exposées par sgserver
  31/5/2022:
    - désactivation de la vérification SSL
includes: [../lib/config.inc.php, mapcat.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../main/lib/mapversion.inc.php';
require_once __DIR__.'/gan.inc.php';

use Symfony\Component\Yaml\Yaml;

/** @param array<int, string> $http_response_header */
function http_error_code(?array $http_response_header): ?string { // extrait le code d'erreur Http 
  if (!isset($http_response_header))
    return 'indéfini';
  $http_error_code = null;
  foreach ($http_response_header as $line) {
    if (preg_match('!^HTTP/.\.. (\d+) !', $line, $matches))
      $http_error_code = $matches[1];
  }
  return $http_error_code;
}

function httpContext(): mixed { // fabrique un context Http
  //if (1 || !($proxy = config('proxy'))) {
    //return null;
    return stream_context_create([
      'http'=> [
        'method'=> 'GET',
      ],
      'ssl'=> [
        'verify_peer'=> false,
        'verify_peer_name'=> false,
      ],
    ]);
  //}
  
  /*return stream_context_create([
    'http'=> [
      'method'=> 'GET',
      'proxy'=> str_replace('http://', 'tcp://', $proxy),
    ]
  ]);*/
}

/** @return array<string, array<string, mixed>> */
function maps(): array { // liste les cartes actives du portefeuille 
  ($INCOMING_PATH = getenv('SHOMGT3_DASHBOARD_INCOMING_PATH'))
    or ($INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH'))
      or throw new Exception("Variables d'env. SHOMGT3_DASHBOARD_INCOMING_PATH et SHOMGT3_INCOMING_PATH non définies");
  $maps = MapVersion::allAsArray($INCOMING_PATH);
  foreach ($maps as $num => $map) {
    //var_dump($num);
    if (($map['status'] <> 'ok') || !(is_int($num) || ctype_digit($num)))
      unset($maps[$num]);
  }
  return $maps;
}


// Utilisation de la classe Gan
if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__)))
  return;


if (php_sapi_name() == 'cli') {
  //echo "argc=$argc\n"; print_r($argv);
  if ($argc == 1) {
    echo "usage: gan.php {action}\n";
    echo "{action}\n";
    echo "  - harvest - Moissonne les Gan de manière incrémentale\n";
    echo "  - newHarvest - Moissonne les Gan en réinitialisant au péalable\n";
    echo "  - showHarvest - Affiche la moisson en Yaml\n";
    echo "  - storeHarvest - Enregistre la moisson en Yaml/pser\n";
    echo "  - analyzeHtml {mapNum} - Analyse le GAN de la carte {mapNum}\n";
    die();
  }
  else
    $a = $argv[1];
}
else { // non CLI
  $a = $_GET['a'] ?? 'showHarvest'; // si $a vaut null alors action d'afficher dans le format $f
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gan</title></head><body><pre>\n";
}

if ($a == 'menu') { // menu non CLI
  echo "gan.php - Menu:<ul>\n";
  echo "<li>La moisson des GAN doit être effectuée en CLI</li>\n";
  echo "<li><a href='?a=showHarvest'>Affiche la moisson en Yaml</a></li>\n";
  echo "<li><a href='?a=storeHarvest'>Enregistre la moisson en Yaml/pser</a></li>\n";
  //echo "<li><a href='?a=listMaps'>Affiche en Html les cartes avec synthèse moisson et lien vers Gan</a></li>\n";
  //echo "<li><a href='?f=html'>Affiche en Html les cartes à mettre à jour les plus périmées d'abord</a></li>\n";
  echo "</ul>\n";
  die();
}

elseif ($a == 'harvest') { // moisson des GAN depuis le Shom en repartant de 0 
  //echo "Harvest ligne ",__LINE__,"\n";
  GanStatic::harvest();
  die();
}

elseif ($a == 'newHarvest') { // moisson des GAN depuis le Shom réinitialisant au préalable 
  //echo "fullHarvest ligne ",__LINE__,"\n";
  GanStatic::harvest(['reinit'=> true]);
  die();
}

elseif ($a == 'showHarvest') { // Affiche la moisson en Yaml 
  GanStatic::build();
  echo Yaml::dump(Gan::allAsArray(), 4, 2);
  die();
}

elseif ($a == 'storeHarvest') { // Enregistre la moisson en Yaml/pser 
  GanStatic::build();
  file_put_contents(GanStatic::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  GanStatic::storeAsPser();
  die("Enregistrement des fichiers Yaml et pser ok\n");
}

elseif ($a == 'harvestAndStore') { // moisson des GAN depuis le Shom puis enregistrement en Yaml/pser
  GanStatic::harvest();
  GanStatic::build();
  file_put_contents(GanStatic::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  GanStatic::storeAsPser();
  die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
}

elseif ($a == 'fullHarvestAndStore') { // moisson des GAN depuis le Shom puis enregistrement en Yaml/pser
  GanStatic::harvest(['reinit'=> true]);
  GanStatic::build();
  file_put_contents(GanStatic::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  GanStatic::storeAsPser();
  die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
}

elseif ($a == 'analyzeHtml') { // analyse l'Html du GAN d'une carte particulière 
  ($mapNum = $argv[2] ?? null)
    or throw new Exception("argument mapNum absent");
  GanStatic::analyzeHtmlOfMap($mapNum);
}
