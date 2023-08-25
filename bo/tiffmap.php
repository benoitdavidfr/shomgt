<?php
namespace bo;
/*PhpDoc:
name: tiffmap.php
title: bo/tiffmap.php - génère une carte Leaflet pour visualiser les tiff contenues dans une archive de carte
doc: |
  paramètres GET
   - path - chemin du répertoire contenant le fichier 7z de la carte
   - map  - nom de base du fichier 7z d'une carte (sans l'extension .7z)
*/
define ('LGEOJSON_STYLE', ['color'=>'blue', 'weight'=> 2, 'opacity'=> 0.3]); // style passé à l'appel de L.geoJSON()

require_once __DIR__.'/maparchive.php';

/** transforme un GBox en une structure latLngBounds@Leaflet
 * @return TLPos */
function latLngBounds(\gegeom\GBox $gbox): array {
  return [[$gbox->south(), $gbox->west()], [$gbox->north(), $gbox->east()]];
}

$rpath = $_GET['rpath'];
// fabrication  de la liste des tiffs à afficher dans la carte
$tifs = []; // liste des URL des GéoTiffs utilisant shomgeotiff.php [name => url]
$map = new MapArchive($rpath);
// prefix d'URL vers le répertoire courant
$serverUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF']);
foreach ($map->gtiffs() as $fileName) {
  echo "$fileName<br>\n";
  $tifs[substr($fileName, 5, -4)] = "$serverUrl/shomgeotiff.php$rpath/$fileName";
  $spatials[substr($fileName, 5, -4)] = "$serverUrl/shomgeotiff.php$rpath/$fileName";
}
echo "<pre>tifs = "; print_r($tifs); echo "</pre>\n";

// fabrication de la liste des extensions spatiales à afficher dans la carte
$spatials = []; // liste des couches Leaflet représentant les ext. spat. des GéoTiffs [title => code JS créant un L.geoJSON]
foreach ($map->mapCat->spatials() as $title => $spatial) {
  $title = str_replace('"', '\"', $title);
  //echo "<pre>spatial[$name] = "; print_r($spatial); echo "</pre>\n";
  $spatials[$title] = $spatial->lgeoJSON(LGEOJSON_STYLE, $title);
}
//echo "<pre>spatials = "; print_r($spatials); echo "</pre>\n"; //die("Ok ligne ".__LINE__);
$bounds = ($georefBox = $map->georefBox()) ? latLngBounds($georefBox) : [];
echo "<pre>bounds = "; print_r($bounds); echo "</pre>\n";
if (!$tifs)
  die("Affichage impossible car aucun GéoTiff à afficher\n");
if (!$bounds)
  die("Affichage impossible car impossible de déterminer l'extension à afficher\n");
//die("Ok ligne ".__LINE__);

?>
<html>
  <head>
    <title><?php echo "tiffmap $_GET[map]@_SERVER[HTTPS_HOST]"; ?></title>
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
