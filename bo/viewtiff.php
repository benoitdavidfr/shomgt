<?php
/* bo/viewtiff.php - Visualisation pour validation d'une carte Shom 7z
 * Benoit DAVID - 11-27/7/2023
 * Utilisé de 2 manières:
 *  - en autonome propose de visualiser les livraisons et les archives
 *  - appelé par maparchive.php pour visualiser une carte 7z
 *
 * Utilise georaster-layer-for-leaflet pour visualiser des tif dans une carte Leaflet
 * Permet aussi de visualiser les extensions spatiales fournies dans MapCat
 *
 * paramètres GET
 *  - path - chemin du répertoire contenant des 7z de cartes
 *  - map  - nom de base du fichier 7z d'une carte (sans l'extension .7z)
 *
 * Faire des tests de viewtiff avec:
 *  - 2 cartes normales standard sans cartouches
 *  - 2 cartes normales standard avec cartouches
 *  - toutes les cartes spéciales anciennes et nouvelles
 *  - la carte normale mal géoréférencée
 *  - toutes les cartes normales à cheval sur l'antiméridien
*/
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/maparchive.php';

define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>viewtiff</title></head><body>\n");
//define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
define ('TEST_MAPS', [
    "2 cartes normales sans cartouche" => [
      'path=/incoming/20230710&map=6735' =>
        "6735 - Pas de Calais - De Boulogne-sur-Mer à Zeebrugge, estuaire de la Tamise (Thames)",
      'path=/archives/7441&map=7441-2009' => "7441-2009 - Abords et Ports de Monaco",
    ],
    "2 cartes normales avec partie principale et cartouches"=> [
      'path=/archives/7594&map=7594-1903' => "7594-1903 - De la Pointe Ebba au Cap de la Découverte",
      'path=/incoming/20230710&map=7090' => "7090 - De la Pointe de Barfleur à Saint-Vaast-la-Hougue",
    ],
    "2 cartes normales avec cartouches mais sans partie principale" => [
      'path=/archives/7207&map=7207-2303'=> "7207-2303 - Ports de Fécamp et du Tréport",
      'path=/archives/7427&map=7427-1724'=>
        "7427-1724 - La Gironde- De Mortagne-sur-Gironde au Bec d'Ambès"
        ." - La Garonne et La Dordogne jusqu'à Bordeaux et Libourne",
    ],
    "Carte 7620 mal géoréférencée" => [
      'path=/archives/7620&map=7620-1903'=> "7620-1903 - Approches d'Anguilla",
      'path=/archives/7620&map=7620-2242'=> "7620-2242 - Approches d'Anguilla",
    ],
    "Les anciennes cartes spéciales" => [
      'path=/archives/7330&map=7330-1726'=> 
        "7330-1726 - De Cherbourg à Hendaye - Action de l'Etat en Mer en Zone Maritime Atlantique",
      'path=/archives/7344&map=7344-1726'=>
        "7344-1726 - De Brest à la frontière belge - Action de l'Etat en Mer - Zone Manche et Mer du Nord",
      'path=/archives/7360&map=7360-1726'=>
        "7360-1726 - De Cerbère à Menton - Action de l'Etat en Mer - Zone Méditerranée",
      'path=/archives/8101&map=8101-1726'=> "8101-1726 - MANCHEGRID - Carte générale",
      'path=/archives/8502&map=8502-1726'=> "8502-1726 - Action de l'Etat en Mer en ZMSOI",
      'path=/archives/8509&map=8509-1944'=> 
        "8509-1944 - Action de l''Etat en Mer - Nouvelle-Calédonie - Wallis et Futun",
      'path=/archives/8510&map=8510-1944'=> "8510-1944 - Délimitations des zones maritimes",
      'path=/archives/8517&map=8517-1944'=>
        "8517-1944 - Carte simplifiée de l''action de l''Etat en Mer des ZEE Polynésie Française et Clipperton",
      'path=/archives/8523&map=8523-2224'=>
        "8523-2224 - Carte d''Action de l'État en Mer - Océan Atlantique Nord - Zone maritime Antilles-Guyane",
    ],
    "Les nouvelles cartes spéciales" => [
      'path=/incoming/20230628aem&map=7330'=> 
        "7330 - De Cherbourg à Hendaye - Action de l'Etat en Mer en Zone Maritime Atlantique",
      'path=/doublons/20230626&map=7344'=>
        "7344 - Carte d’Action de l’État en Mer Zone Manche et Mer du Nord - \"De Brest à la frontière Belge\"",
      'path=/doublons/20230626&map=7360'=>
        "7360 - Carte d’Action de l’État en Mer Zone Méditerranée - \"De Cerbère à Menton\"",
      'path=/attente/20230628aem&map=8502'=>
        "8502 - Carte d’Action de l'État en Mer en Zone Maritime Sud de l'Océan Indien ZMSOI",
      'path=/attente/20230628aem&map=8509'=>
        "8509 - Carte d’Action de l’État en Mer - Nouvelle-Calédonie - Wallis et Futuna",
      'path=/attente/20230628aem&map=8510'=> "8510 - Délimitation des zones maritimes",
      'path=/attente/20230628aem&map=8517'=> 
        "8517 - Carte simplifiée d’Action de l’État en Mer des ZEE Polynésie française et Clipperton",
      'path=/attente/20230628aem&map=8523'=>
        "8523 - Carte d’Action de l’État en Mer - Océan Atlantique Nord - Zone maritime Antilles-Guyane",
    ],
    "Cartes à cheval sur l'antiméridien" => [
      'path=/archives/6835&map=6835-2311'=> "6835-2311 - Océan Pacifique Nord - Partie Est",
      'path=/archives/6977&map=6977-2304'=> "6977-2304 - Océan Pacifique Nord - Partie Nord-Ouest",
      'path=/archives/7021&map=7021-2308'=> "7021-2308 - Océan Pacifique Nord - Partie Sud-Ouest",
      'path=/archives/7271&map=7271-1726'=> "7271-1726 - Australasie et mers adjacentes",
      /*
      7271
      7166
      6671
      6670
      6817
      7283
      */
    ],
    "Tests d'erreurs"=> [
      'path=/attente/20230628aem&map=xx'=> "Le fichier n'existe pas",
    ],
  ]
); // cartes de tests 
define ('MIN_FOR_DISPLAY_IN_COLS', 100); // nbre min d'objets pour affichage en colonnes
define ('NBCOLS_FOR_DISPLAY', 24); // nbre de colonnes si affichage en colonnes
define ('LGEOJSON_STYLE', ['color'=>'blue', 'weight'=> 2, 'opacity'=> 0.3]); // style passé à l'appel de L.geoJSON()

use Symfony\Component\Yaml\Yaml;

if (!($login = Login::login())) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

if (!isset($_GET['path'])) { // affichage de la liste des livraisons 
  echo HTML_HEAD,"<h2>Livraisons et archives</h2>\n";
  $groups = [
    '/incoming'=> "Livraisons",
    '/attente'=> "Livraisons en attente",
    '/archives'=> "Archives de cartes",
  ];
  foreach ($groups as $gname => $title) {
    echo "<h3>$title</h3>\n";
    $incomings = []; // liste des livraisonw ou archives
    foreach (new DirectoryIterator($PF_PATH.$gname) as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      $incomings[] = (string)$incoming;
    }
    $nbincomings = count($incomings);
    if ($nbincomings < MIN_FOR_DISPLAY_IN_COLS) { // affichage sans colonne
      echo "<ul>\n";
      foreach ($incomings as $incoming) {
        echo "<li><a href='?path=$gname/$incoming'>$incoming</a></li>\n";
      }
      echo "</ul>\n";
    }
    else { // affichage en colonnes
      //echo "nbincomings=$nbincomings<br>\n";
      echo "<table border=1><tr>\n";
      $i = 0;
      for ($nocol=0; $nocol < NBCOLS_FOR_DISPLAY; $nocol++) {
        echo "<td valign='top'>\n";
        //echo "max=",$nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1),"<br>\n";
        //echo "floor(max)=",floor($nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1)),"<br>\n";
        while ($i < round($nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1))) {
          //echo "i=$i\n";
          $incoming = $incomings[$i];
          //echo "<li><a href='?path=$gname/$incoming'>$incoming</a></li>\n";
          echo "&nbsp;<a href='?path=$gname/$incoming'>$incoming</a>&nbsp;<br>\n";
          $i++;
        }
        echo "</td>\n";
      }
      echo "</tr></table>\n";
    }
  }
  echo "</p><a href='?path=tests&action=tests'>Cartes de tests</a></p>\n";
  die();
}

if (($_GET['action'] ?? null) == 'tests') {
  echo HTML_HEAD,"<h3>Cartes de test</h3>\n";
  foreach (TEST_MAPS as $gtitle => $group) {
    echo "<h4>$gtitle</h4><ul>\n";
    foreach ($group as $path => $title)
      echo "<li><a href='?$path'>$title</a></li>\n";
    echo "</ul>\n";
  }
  die();
}

if (!isset($_GET['map'])) { // affichage du contenu de la livraison ou du répertoire d'archives 
  echo HTML_HEAD;
  if (!is_dir($PF_PATH.$_GET['path']))
    die("<b>Erreur, le répertoire $_GET[path] n'existe pas<br>\n");
  echo "<h2>Répertoire $_GET[path]</h2>\n";
  if (substr($_GET['path'], 0, 9) <> '/archives')
    echo "<ul>\n";
  $first = true;
  foreach (new DirectoryIterator($PF_PATH.$_GET['path']) as $map) {
    if (substr($map, -3) <> '.7z') continue;
    //echo "<li>"; print_r($md); echo "</li>";
    if (substr($_GET['path'], 0, 9) == '/archives') { // cas d'une archive de carte
      $md = MapMetadata::getFrom7z("$PF_PATH$_GET[path]/$map");
      if ($first) {
        echo $md['title'] ?? $map,"<ul>\n";
        $first = false;
      }
      $mapid = substr($map, 0, -3);
      echo "<li><a href='?path=$_GET[path]&map=$mapid'>",$md['edition'] ?? $mapid,"</li>\n";
    }
    else { // cas d'une livraison
      $md = MapMetadata::getFrom7z("$PF_PATH$_GET[path]/$map");
      $mapnum = substr($map, 0, -3);
      echo "<li><a href='?path=$_GET[path]&map=$mapnum'>",$md['title'] ?? $mapnum,"</a></li>\n";
    }
  }
  echo "</ul>\n";
  die();
}

if (!is_file("$PF_PATH$_GET[path]/$_GET[map].7z"))
  die("Erreur le fichier $PF_PATH$_GET[path]/$_GET[map].7z n'existe pas\n");

switch ($_GET['action'] ?? null) {
  case null: { // affichage des caractéristiques de la carte
    echo HTML_HEAD;
    $mapNum = substr($_GET['map'], 0, 4);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    $map->showAsHtml($_GET['button'] ?? null);
    die();
  }
  case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
    $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
    $path = $archive->extract($_GET['tif']);
    $gdalinfo = new GdalInfo($path);
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode($gdalinfo->asArray(), JSON_OPTIONS);
    $archive->remove($path);
    die();
  }
  case 'insetMapping': { // affiche le détail de la correspondance entre cartouches 
    $mapNum = substr($_GET['map'], 0, 4);
    //$mapCat = new MapCat($mapNum);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    $mappingInsetsWithMapCat = $map->mappingInsetsWithMapCat(true);
    echo "<pre>mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
    sort($mappingInsetsWithMapCat);
    echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
    echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
    if ($mappingInsetsWithMapCat <> $mapCat->insetTitlesSorted())
      echo "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
    die();
  }
  case 'show7zContents': { // affiche le contenu de l'archive
    $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
    echo HTML_HEAD,"<b>Contenu de l'archive $_GET[map].7z:</b><br>\n<pre>";
    foreach ($archive as $entry) {
      echo Yaml::dump([$entry]);
    }
    die();
  }
  case 'dumpPhp': { // affiche le print_r() Php
    $mapNum = substr($_GET['map'], 0, 4);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    echo HTML_HEAD,"<pre>"; print_r($map); echo "</pre>";
    die();
  }
  case 'viewtiff': { // affiche une carte dans Leaflet avec les images
    $mapNum = substr($_GET['map'], 0, 4);
    $tifs = []; // liste des URL des GéoTiffs utilisant shomgeotiff.php [name => url]
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    // prefix d'URL vers le répertoire courant
    $serverUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF']);
    foreach ($map->gtiffs() as $fileName) {
      echo "$fileName<br>\n";
      $tifs[substr($fileName, 5, -4)] = "$serverUrl/shomgeotiff.php$_GET[path]/$_GET[map].7z/$fileName";
      $spatials[substr($fileName, 5, -4)] = "$serverUrl/shomgeotiff.php$_GET[path]/$_GET[map].7z/$fileName";
    }
    echo "<pre>tifs = "; print_r($tifs); echo "</pre>\n";
    $spatials = []; // liste des couches Leaflet représentant les ext. spat. des GéoTiffs [title => code JS créant un L.geoJSON]
    $mapCat = new MapCat($mapNum);
    foreach ($mapCat->spatials() as $title => $spatial) {
      $title = str_replace('"', '\"', $title);
      //echo "<pre>spatial[$name] = "; print_r($spatial); echo "</pre>\n";
      $spatials[$title] = $spatial->lgeoJSON(LGEOJSON_STYLE, $title);
    }
    //echo "<pre>spatials = "; print_r($spatials); echo "</pre>\n"; //die("Ok ligne ".__LINE__);
    $bounds = ($georefBox = $map->georefBox()) ? $georefBox->latLngBounds() : [];
    echo "<pre>bounds = "; print_r($bounds); echo "</pre>\n";
    if (!$tifs)
      die("Affichage impossible car aucun GéoTiff à afficher\n");
    if (!$bounds)
      die("Affichage impossible car impossible de déterminer l'extension à afficher\n");
   //die("Ok ligne ".__LINE__);
    break;
  }
  default: {
    die("Action $_GET[action] inconnue\n");
  }
}
?>
<html>
  <head>
    <title>viewtiff <?php echo $_GET['map']; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9/dist/leaflet.css"/>
    <style>
      #map {
        bottom: 0;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
      }
    </style>
  </head>
  <body>
    <div id="map" style="height: 100%; width: 100%"></div>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://unpkg.com/georaster"></script>
    <script src="https://unpkg.com/proj4"></script>
    <script src="https://unpkg.com/georaster-layer-for-leaflet"></script>
    <script>
      // si le Feature contient une propriété popupContent alors le popUp est affiché lorsque le Feature est clické
      function onEachFeature(feature, layer) {
          // does this feature have a property named popupContent?
          if (feature.properties && feature.properties.popupContent) {
              layer.bindPopup(feature.properties.popupContent);
          }
      }
      
      // initalize leaflet map
      var map = L.map('map').fitBounds(<?php echo json_encode($bounds); ?>);
      var baseLayers = {
        // OSM
        "OSM" : new L.TileLayer(
          'https://{s}.tile.osm.org/{z}/{x}/{y}.png',
          {"attribution":"&copy; les contributeurs d’<a href='http://osm.org/copyright'>OpenStreetMap</a>"}
        ),
        // Fond blanc
        "Fond blanc" : new L.TileLayer(
          'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
          { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
        )
      };
      var overlays = {};
      
      // affichage des extensions spatiales
<?php foreach ($spatials as $title => $spatial) { ?>
      overlays[<?php echo "\"$title\""; ?>] = <?php echo $spatial; ?>
      map.addLayer(overlays[<?php echo "\"$title\""; ?>]);
<?php } ?>
      
<?php foreach ($tifs as $name => $path) { ?>
      fetch(<?php echo "'$path'"; ?>)
        .then(response => response.arrayBuffer())
        .then(arrayBuffer => {
          parseGeoraster(arrayBuffer).then(georaster => {
            console.log("georaster:", georaster);
            overlays[<?php echo "'$name'"; ?>] = new GeoRasterLayer({georaster: georaster, opacity: 1.0, resolution: 256});
            map.addLayer(overlays[<?php echo "'$name'"; ?>]);
<?php } ?>
                  map.addLayer(baseLayers['OSM']);
                  L.control.layers(baseLayers, overlays).addTo(map);
<?php foreach ($tifs as $name => $path) { ?>
              });
            });
<?php } ?>
      
    </script>
  </body>
</html>
