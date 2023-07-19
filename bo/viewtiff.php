<?php
/* bo/viewtiff.php - Visualisation et validation d'une livraison utilisant georaster-layer-for-leaflet
 * Benoit DAVID - 11-19/7/2023
*/
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/conform.php';

// var url_to_geotiff_file = "http://localhost/shomgeotiff/incoming/20230710/6735/6735_pal300.tif";
define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>viewtiff</title></head><body>\n");
define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

if (!isset($_GET['path'])) { // affichage de la liste des livraisons 
  echo HTML_HEAD;
  $groups = [
    'incoming'=> "Liste des livraisons",
    'attente'=> "Liste des livraisons en attente",
    'archives'=> "Liste des archives",
  ];
  if (($_GET['action'] ?? null) == 'clean') {
    echo "<h2>Nettoyage</h2>\n";
    foreach ($groups as $gname => $title) {
      echo "$title:<ul>\n";
      foreach (new DirectoryIterator(SHOMGEOTIFF.$gname) as $incoming) {
        if (in_array($incoming, ['.','..','.DS_Store'])) continue;
        foreach (new DirectoryIterator(SHOMGEOTIFF."$gname/$incoming") as $dirname) {
          if (in_array($dirname, ['.','..','.DS_Store'])) continue;
          if (is_dir(SHOMGEOTIFF."$gname/$incoming/$dirname")) {
            echo "<li>Effacement du répertoire $gname/$incoming/$dirname<ul>\n";
            foreach (new DirectoryIterator(SHOMGEOTIFF."$gname/$incoming/$dirname") as $filename) {
              if (in_array($filename, ['.','..'])) continue;
              if (substr($filename, -3) == '.7z') { // je n'efface pas les archives 7z 
                echo "<li><b>Erreur sur $filename</b></li>\n";
              }
              else {
                echo "<li>$filename</li>\n";
                if (isset($_GET['confirm']))
                  unlink(SHOMGEOTIFF."$gname/$incoming/$dirname/$filename");
              }
            }
            if (isset($_GET['confirm']))
              rmdir(SHOMGEOTIFF."$gname/$incoming/$dirname");
            echo "</ul></li>\n";
          }
        }
      }
      echo "</ul>\n";
    }
    if (!isset($_GET['confirm']))
      echo "<a href='?action=clean&confirm=true'>confirmer ?</a><br>\n";
    die();
  }
  foreach ($groups as $gname => $title) {
    echo "$title:<ul>\n";
    foreach (new DirectoryIterator(SHOMGEOTIFF.$gname) as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      echo "<li><a href='?path=$gname/$incoming'>$incoming</a></li>\n";
    }
    echo "</ul>\n";
  }
  echo "<a href='?action=clean'>Nettoyage</a><br>\n";
  die();
}

if (!isset($_GET['map'])) { // affichage du contenu de la livraison 
  echo HTML_HEAD;
  echo "<h2>Répertoire $_GET[path]</h2>\n";
  if (substr($_GET['path'], 0, 8) <> 'archives')
    echo "<ul>\n";
  $first = true;
  foreach (new DirectoryIterator(SHOMGEOTIFF.$_GET['path']) as $map) {
    if (substr($map, -3) <> '.7z') continue;
    //echo "<li>"; print_r($md); echo "</li>";
    if (substr($_GET['path'], 0, 8) == 'archives') { // cas d'une archive de carte
      $md = MapMetadata::getFrom7z(SHOMGEOTIFF."$_GET[path]/$map");
      if ($first) {
        echo $md['title'] ?? $map,"<ul>\n";
        $first = false;
      }
      $mapid = substr($map, 0, -3);
      echo "<li><a href='?path=$_GET[path]&map=$mapid'>",$md['edition'] ?? $mapid,"</li>\n";
    }
    else { // cas d'une livraison
      $md = MapMetadata::getFrom7z(SHOMGEOTIFF."$_GET[path]/$map");
      $mapnum = substr($map, 0, -3);
      echo "<li><a href='?path=$_GET[path]&map=$mapnum'>",$md['title'] ?? $mapnum,"</a></li>\n";
    }
  }
  echo "</ul>\n";
  die();
}

switch ($_GET['action'] ?? null) {
  case null: { // affichage des caractéristiques de la carte
    echo HTML_HEAD;
    MapCat::init();
    $map = new Map(SHOMGEOTIFF.$_GET['path'], $_GET['map']);
    $map->showAsHtml(SHOMGEOTIFF.$_GET['path'], $_GET['map'], MapCat::get(substr($_GET['map'], 0, 4)));
    die();
  }
  case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
    $gdalinfo = new GdalInfo(SHOMGEOTIFF."$_GET[path]/$_GET[map]/$_GET[tif]");
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode($gdalinfo->asArray(), JSON_OPTIONS);
    die();
  }
  case 'viewtiff': { // affichage des tiff de la carte dans Leaflet
    $tifs = [];
    $map = new Map(SHOMGEOTIFF.$_GET['path'], $_GET['map']);
    foreach ($map->gtiffs() as $fileName) {
      echo "$fileName<br>\n";
      $tifs[substr($fileName, 5, -4)] = "http://localhost/shomgeotiff/$_GET[path]/$_GET[map]/$fileName";
    }
    echo "<pre>tifs = "; print_r($tifs); echo "</pre>\n";
    $bounds = ($gbox = $map->gbox()) ? $gbox->latLngBounds() : [];
    echo "<pre>bounds = "; print_r($bounds); echo "</pre>\n";
    if (!$tifs || !$gbox)
      die("Affichage impossible\n");
    //die("Ok");
    break;
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
    <div id="map"></div>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://unpkg.com/georaster"></script>
    <script src="https://unpkg.com/proj4"></script>
    <script src="https://unpkg.com/georaster-layer-for-leaflet"></script>
    <script>
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
      
<?php foreach ($tifs as $name => $path) { ?>
      fetch(<?php echo "'$path'"; ?>)
        .then(response => response.arrayBuffer())
        .then(arrayBuffer => {
          parseGeoraster(arrayBuffer).then(georaster => {
            console.log("georaster:", georaster);
            overlays[<?php echo "'$name'"; ?>] = new GeoRasterLayer({georaster: georaster, opacity: 1.0, resolution: 256});
<?php if ($name == 'pal300') { ?>
            map.fitBounds(overlays['pal300'].getBounds());
<?php } ?>
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
