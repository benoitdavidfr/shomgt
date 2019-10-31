<?php
/*PhpDoc:
name: lib.inc.php
title: lib.inc.php - fonctions utilisées par index.php, gan.php ou geojson.php
doc: |
  diverses commandes
journal: |
  15/9/2019
    modif de wfsdl() pour que l'id de la carte soit correct
  8/3/2019
    fork dans gt
  11/12/2018
    scission de index.php et récupération de bboxgeom.inc.php
includes: [wfsserver.inc.php, wfsjson.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/wfsserver.inc.php';
require_once __DIR__.'/wfsjson.inc.php';

use Symfony\Component\Yaml\Yaml;

// Génère un pseudo enregistrement du WFS pour effectuer les corrections nécessaires
function buildMapForWfs(string $num, string $title, int $scaleD, array $bbox): array {
  return [
    'type'=> 'Feature',
    'id'=> "FR$num",
    'geometry'=> [
      'type'=> 'Polygon',
      'coordinates'=> [[
        [$bbox[0], $bbox[1]], // SW
        [$bbox[2], $bbox[1]], // SE
        [$bbox[2], $bbox[3]], // NE
        [$bbox[0], $bbox[3]], // NW
        [$bbox[0], $bbox[1]], // SW
      ]],
    ],
    'geometry_name'=> 'the_geom',
    'properties'=> [
      'fid'=> $title,
      'name'=> $title,
      'carte_id'=> $num,
      'scale'=> $scaleD,
    ],
  ];
}

// lecture du wfs Shom des fantomes des cartes GeoTiff
// retour d'un ensemble de features chacun identifié par un id de la forme "FR{num}"
function wfsdl(): array {
  //printf("time-filemtime=%.2f heures<br>\n",(time()-filemtime(__DIR__.'/wfsdl.pser'))/60/60);
  // Le fichier wfsdl.pser est automatiquement mis à jour toutes les 12 heures
  if (is_file(__DIR__.'/wfsdl.pser') && (time() - filemtime(__DIR__.'/wfsdl.pser') < 12*60*60))
    return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
  
  //try {
    $typenames = [
      'CARTES_MARINES_GRILLE:grille_geotiff_30', // cartes echelle > 1/30K
      'CARTES_MARINES_GRILLE:grille_geotiff_30_300', // cartes aux échelles entre 1/30K et 1/300K
      'CARTES_MARINES_GRILLE:grille_geotiff_300_800', // cartes aux échelles entre 1/300K et 1/800K
      'CARTES_MARINES_GRILLE:grille_geotiff_800', // carte échelle < 1/800K
    ];

    $yaml = Yaml::parseFile(__DIR__.'/shomwfs.yaml');
    $shomwfs = new WfsServerJson($yaml, 'shomwfs');

    $wfs = [];
    foreach ($typenames as $typename) {
      $numberMatched = $shomwfs->getNumberMatched($typename);
      $count = 100;
      for ($startindex = 0; $startindex < $numberMatched; $startindex += $count) {
        $fc = $shomwfs->getFeatureAsArray($typename, [], -1, '', $count, $startindex);
        foreach ($fc['features'] as $feature) {
          if (!preg_match('!^([^ ]*)!', $feature['properties']['name'], $matches))
            throw new Exception("Ereur: no match on ".$feature['properties']['name']);
          $id = 'FR'.$matches[1];
          $wfs[$id] = $feature;
          //echo "id=$id\n";
        }
      }
    }
    //echo '<pre>',json_encode($maps, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    echo count($wfs)," cartes téléchargées du WFS du Shom<br>\n";
  
    // La carte 6497 est absente du WFS mais présente dans le GAN, cela semble une erreur
    // Le 4/12/2018 le Shom indique que la carte 6497 n'est pas dispo en version numérique,
    // mais existe bien en version papier
    $wfs['FR6497'] = buildMapForWfs(
                       6497, "6497 - Ile de la Possession, île de l'Est", 75000, 
                       [51.346828, -46.673142, 52.488178, -46.106097]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 6497 - Ile de la Possession, île de l'Est<br>\n";
    $wfs['FR7330'] = buildMapForWfs(
                       7330,
                       "7330 - De Cherbourg à Hendaye - Action de l'Etat en Mer en zone maritime Atlantique",
                       1070000, 
                       [-11.025828, 41.060522, 0.524947, 52.278119]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 7330 - Action de l'Etat en Mer en zone maritime Atlantique<br>\n";
    $wfs['FR7344'] = buildMapForWfs(
                       7344,
                       "7344 - De Brest à la frontière belge - Action de l'Etat en Mer en zone Manche et Mer du Nord",
                       655000, 
                       [-7.311689, 47.229586, 3.324964, 52.175769]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 7344 - Action de l'Etat en Mer en zone Manche et Mer du Nord<br>\n";
    $wfs['FR7360'] = buildMapForWfs(
                       7360, "7360 - De Cerbère à Menton - Action de l'Etat en Mer en zone Méditerranée", 700000, 
                       [1.204069, 39.651736, 11.137350, 44.940681]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 7360 - Action de l'Etat en Mer en zone Méditerranée<br>\n";
    $wfs['FR8502'] = buildMapForWfs(
                       8502, "8502 - Action de l'Etat en Mer en ZMSOI", 7904971, 
                       [26.015222, -64.087619, 94.000544, 12.155178]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 8502 - Action de l'Etat en Mer en ZMSOI<br>\n";
    $wfs['FR8101'] = buildMapForWfs(
                       8101, "8101 - MANCHEGRID - Carte générale", 880000, 
                       [-6.641753, 48.144300, 2.853589, 52.449447]); // LonLatDd issu de shomgt.yaml West Sud Est North
    echo "Ajout de la carte 8101 - MANCHEGRID - Carte générale<br>\n";
    
    ksort($wfs);
    //echo "<pre>wfs="; print_r($wfs);
    file_put_contents(__DIR__.'/wfsdl.pser', serialize($wfs));
    return $wfs;
    /*}
  catch (Exception $e) {
    if (is_file(__DIR__.'/wfsdl.pser'))
      return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
    else
      throw new Exception("Ereur: impossible de créer wfsdl.pser");
  }*/
}

// formattage de l'échelle en rajoutant un point comme séparateur de milliers
function fmtScaleD(int $scaleD): string {
  if ($scaleD < 1e6)
    return floor($scaleD / 1000) .'.'. sprintf('%03d', $scaleD % 1000);
  else
    return floor($scaleD / 1e6)
      .'.'. sprintf('%03d', floor($scaleD/1000) % 1000)
      .'.'. sprintf('%03d', $scaleD % 1000);
}

// transforme un bbox en geometry GeoJSON en tenant compte du cas du bbox à cheval sur l'anti-méridien
function bboxToGeoJsonGeometry(array $bbox, bool $withHole=false): array {
  if ($bbox[2] < $bbox[0]) {
    $geom1 = bboxToGeoJsonGeometry([$bbox[0], $bbox[1], $bbox[2]+360, $bbox[3]]);
    $geom2 = bboxToGeoJsonGeometry([$bbox[0]-360, $bbox[1], $bbox[2], $bbox[3]]);
    return [
      'type'=> 'MultiPolygon',
      'coordinates'=> [
        $geom1['coordinates'],
        $geom2['coordinates'],
      ]
    ];
  }
  else {
    $polygon = [
      'type'=> 'Polygon',
      'coordinates'=> [
        [
          [$bbox[0], $bbox[1]],
          [$bbox[0], $bbox[3]],
          [$bbox[2], $bbox[3]],
          [$bbox[2], $bbox[1]],
          [$bbox[0], $bbox[1]],
        ],
      ],
    ];
    if ($withHole) {
      $polygon['coordinates'][] = [
        [(9*$bbox[0]+$bbox[2])/10, (9*$bbox[1]+$bbox[3])/10],
        [(9*$bbox[0]+$bbox[2])/10, ($bbox[1]+9*$bbox[3])/10],
        [($bbox[0]+9*$bbox[2])/10, ($bbox[1]+9*$bbox[3])/10],
        [($bbox[0]+9*$bbox[2])/10, (9*$bbox[1]+$bbox[3])/10],
        [(9*$bbox[0]+$bbox[2])/10, (9*$bbox[1]+$bbox[3])/10],
      ];
    }
    return $polygon;
  }
}
