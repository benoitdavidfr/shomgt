<?php
/** amp.php - Ajout des AMP à ShomGT à la demande de jean-philippe.carlier (31/10/2023).
 * Analyse la couche AMP de l'OFB
 * Construction de la liste des désignations que j'utilise comme type d'AMP
 * Eclatement du fichier issu du WFS de l'OFB en fichier GeoJSON par désignation
 * Construction de la configuration recopiée dans wmsvlayers.yaml
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

ini_set('memory_limit', '10G');

class Amp {
  const DESIGNATIONS = [
    'Aire de gestion des habitats ou des espèces (Polynésie française)' => 'pf-aghe',
    'Aire de gestion durable des ressources (Nouvelle-Calédonie, Province Nord)' => 'pfn-agdr',
    'Aire de gestion durable des ressources (Nouvelle-Calédonie, Province Sud)' => 'pfs-agdr',
    'Aire de protection de biotope'=> 'apb',
    "Aire de repos des cétacés de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-arcpgem',
    "Aire marine protégée de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-amppgem',
    'Aire protégée de ressources naturelles gérées (Polynésie française)'=> 'pf-aprng',
    "Aire spécialement protégée d'importance méditerranéenne"=> 'aspim',
    'Bien inscrit sur la liste du patrimoine mondial'=> 'bipm',
    'Bien inscrit sur la liste du patrimoine mondial (zone coeur)'=> 'bipm-c',
    'Bien inscrit sur la liste du patrimoine mondial (zone tampon)'=> 'bipm-t',
    'Domaine public maritime (Conservatoire du littoral)'=> 'cl-dpm',
    'Domaine public maritime attribué (Conservatoire du littoral)'=> 'cl-dpma',
    'Monument naturel (Polynésie française)'=> 'pf-mn',
    'Parc national'=> 'pn',
    "Parc national (aire d'adhésion)"=> 'pn-aa',
    'Parc national (aire maritime adjacente)'=> 'pn-ama',
    'Parc national (coeur)'=> 'pn-c',
    'Parc naturel (Nouvelle-Calédonie)'=> 'nc-pn',
    'Parc naturel marin'=> 'pnm',
    'Parc provincial (Nouvelle-Calédonie, Province Nord)'=> 'ncn-pp',
    'Parc provincial (Nouvelle-Calédonie, Province Sud)'=> 'ncs-pp',
    'Paysage naturel protégé (Polynésie française)'=> 'pf-pnp',
    "Plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-pgem',
    'Périmètre de protection de réserve naturelle intégrale (Polynésie française)'=> 'pf-pprni',
    'Réserve de biosphère'=> 'rb',
    'Réserve de biosphère (zone centrale)'=> 'rb-c',
    'Réserve de biosphère (zone de transition)'=> 'rb-tr',
    'Réserve de biosphère (zone tampon)'=> 'rb-ta',
    'Réserve de nature sauvage (Nouvelle-Calédonie, Province Nord)'=> 'ncn-rns',
    'Réserve intégrale (Nouvelle-Calédonie)'=> 'nc-ri',
    'Réserve nationale de chasse et de faune sauvage'=> 'rncfs',
    'Réserve naturelle (Nouvelle-Calédonie)'=> 'nc-rn',
    'Réserve naturelle (Nouvelle-Calédonie, Province Sud)'=> 'ncs-rn',
    'Réserve naturelle de la collectivité territoriale de Corse'=> 'rnctc',
    'Réserve naturelle intégrale (Nouvelle-Calédonie, Province Nord)'=> 'ncn-rni',
    'Réserve naturelle intégrale (Nouvelle-Calédonie, Province Sud)'=> 'ncs-rni',
    'Réserve naturelle intégrale (Polynésie française)'=> 'pf-rni',
    'Réserve naturelle intégrale saisonnière (Nouvelle-Calédonie, Province Sud)'=> 'ncs-rnis',
    'Réserve naturelle nationale'=> 'rnn',
    'Réserve naturelle nationale (périmètre de protection)'=> 'rnn-pp',
    'Réserve naturelle nationale (zone de protection renforcée)'=> 'rnn-zpr',
    'Réserve naturelle régionale'=> 'rnr',
    'Réserve naturelle saisonnière (Nouvelle-Calédonie, Province Sud)'=> 'ncs-rns',
    "Site d'importance communautaire (N2000, DHFF)"=> 'sic',
    "Zone de mouillage de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-zmpgem',
    'Zone de nature sauvage (Polynésie française)'=> 'pf-zns',
    'Zone de protection spéciale (N2000, DO)'=> 'zps',
    "Zone de pêche aux Ature de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-zpapgem',
    'Zone de pêche réglementée (Polynésie française)'=> 'pf-zpr',
    "Zone de recherche scientifique de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-zrspgem',
    "Zone de stationnement des paquebots de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-zsppgem',
    "Zone humide d'importance internationale (Ramsar)"=> 'ramsar',
    'Zone marine protégée de la convention OSPAR'=> 'ospar',
    "Zone naturelle protégée de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-znppgem',
    "Zone protégée de la convention d'Apia"=> 'apia',
    "Zone réglementée de pêche de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-zrppgem',
    'Zone spéciale de conservation (N2000, DHFF)'=> 'zsc',
    "Zone spécialement protégée de l'Antarctique"=> 'antarctique',
    'Zone spécialement protégée de la convention de Carthagène'=> 'carthagene',
    "Zone touristique de plan de gestion de l'espace maritime (Polynésie française)"=> 'pf-ztpgem',
  ];
  readonly public array $features;
  
  static function create() {}

  function __construct(string $code) {
    $coll = json_decode(file_get_contents("amp/amp-$code.geojson"), true);
    $this->features = $coll['features'];
  }
  
  function gbox(): \gegeom\GBox {
    $gbox = null;
    foreach ($this->features as $feature) {
      $geom = \gegeom\Geometry::fromGeoArray($feature['geometry']);
      //echo "geom=$geom<br>\n";
      //echo "gbox=",$geom->gbox(),"<br>\n";
      if (!$gbox)
        $gbox = $geom->gbox();
      else
        $gbox = $gbox->union($geom->gbox());
      //print_r($gbox);
    }
    return $gbox;
  }
  
  function types(): array {
    $types = [];
    foreach ($this->features as $feature) {
      $type = $feature['geometry']['type'];
      $types[$type] = 1 + ($types[$type] ?? 0);
    }
    return $types;
  }
  
  // Retourne les features erronnés
  function errors(): array {
    $errors = [];
    foreach ($this->features as $feature) {
      $geom = \gegeom\Geometry::fromGeoArray($feature['geometry']);
      $gbox = json_decode($geom->gbox()->__toString());
      //echo "gbox=",json_encode($gbox),"<br>\n";
      if (($gbox[0] < -180) || ($gbox[1] < -90) || ($gbox[2] > 180) || ($gbox[3] > 90)) {
        $errors[] = array_merge(['error'=> "Erreur sur la BBOX", 'bbox'=> $gbox], $feature);
      }
    }
    return ['bbox'=> json_decode($this->gbox()->__toString()), 'features'=> $errors];
  }
}


switch ($_GET['action'] ?? null) {
  case null: {
    echo "<a href='?action=list'>list</a><br>\n";
    echo "<a href='?action=designation'>designation</a><br>\n";
    echo "<a href='?action=split'>éclatement en fichiers</a><br>\n";
    echo "<a href='?action=layers'>génération de la config à ajouter dans wmsvlayers.yaml</a><br>\n";
    echo "<a href='?action=bbox'>calcul des BBOX par type</a><br>\n";
    echo "<a href='?action=wms'>wms</a><br>\n";
    echo "<a href='?action=errors'>errors</a><br>\n";
    die();
  }
  case 'list': {
    echo '<pre>';
    $collection = json_decode(file_get_contents('amp.json'), true);
    $n = 0;
    foreach ($collection['features'] as $feature) {
      $feature['geometry']['coordinates'] = 'deleted';
      echo Yaml::dump([$feature], 9, 2);
      if ($n++ > 100) break;
    }
    die();
  }
  case 'designation': {
    echo '<pre>';
    $designations = [];
    $collection = json_decode(file_get_contents('amp.json'), true);
    foreach ($collection['features'] as $feature) {
      $des = $feature['properties']['designation'];
      $designations[$des] = 1 + ($designations[$des] ?? 0);
    }
    ksort($designations);
    echo Yaml::dump($designations, 9, 2);
    die();
  }
  case 'split': {
    echo '<pre>';
    $splits = [];
    $collection = json_decode(file_get_contents('amp.json'), true);
    foreach ($collection['features'] as $feature) {
      $des = $feature['properties']['designation'];
      if (isset(DESIGNATIONS[$des])) {
        $splits[DESIGNATIONS[$des]]['features'][] = $feature;
      }
      else {
        echo "Erreur, $des absent\n";
      }
    }
    //echo Yaml::dump($splits, 5, 2);
    is_dir('amp') || mkdir('amp');
    foreach ($splits as $code => $collection) {
      $collection = [
        'type'=> 'FeatureCollection',
        'features'=> $collection['features'],
      ];
      file_put_contents("amp/amp-$code.geojson", json_encode($collection));
    }
    die("Done\n");
  }
  case 'layers': {
    foreach (DESIGNATIONS as $title => $code) {
      $config["amp-$code"] = [
        'title'=> "Aires marines protégées - $title",
        'description'=> "Sélection dans la collection des AMP du service WMS de l'OFB en focntion du champ designation",
        'path'=> "geojson/amp/amp-{$code}.geojson",
        'style'=> '*sgreen',
      ];
    }
    $dump = Yaml::dump($config, 3, 2);
    echo '<pre>',str_replace("'*sgreen'", "*sgreen", $dump);
    die();
  }
  case 'bbox': {
    echo '<pre>';
    foreach (Amp::DESIGNATIONS as $title => $code) {
      echo "$title ($code):\n";
      $coll = new Amp($code);
      echo Yaml::dump(["$title ($code)"=> ['types'=> $coll->types(), 'gbox'=> (string)$coll->gbox()]]);
    }
    die();
  }
  case 'wms': {
    foreach (Amp::DESIGNATIONS as $title => $code) {
      //if ($code <> 'ncn-pp') continue;
      $coll = new Amp($code);
      $gbox = json_decode($coll->gbox()->__toString());
      /*$gbox = [
        $gbox[0] - ($gbox[2]-$gbox[0])/2, $gbox[1] - ($gbox[3]-$gbox[1])/2,
        $gbox[2] + ($gbox[2]-$gbox[0])/2, $gbox[3] + ($gbox[3]-$gbox[1])/2];*/
      //echo "gbox=",json_encode($gbox),"<br>\n";
      $href = '../view/wmsv.php'
        .'?service=WMS'
        .'&version=1.3.0'
        .'&request=GetMap'
        ."&layers=frzee,amp-$code"
        ."&styles="
        .'&bbox='.implode(',',$gbox).'&crs=CRS:84'
        .'&width=500&height=500'
        .'&format=image/png';
      echo "<a href='$href'>$title ($code)</a><br>\n";
      
    }
    die();
  }
  case 'errors': {
    foreach (Amp::DESIGNATIONS as $title => $code) {
      $coll = new Amp($code);
      echo '<pre>',Yaml::dump(["$title ($code)"=> $coll->errors()]);
    }
    die();
  }
  default: die("Erreur, action '$_GET[action]' inconnue\n");
}