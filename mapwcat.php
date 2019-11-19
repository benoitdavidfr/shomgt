<?php
/*PhpDoc:
name: mapwcat.php
title: mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE
doc: |
journal: |
  19/11/2019:
    correction de URL générique du GAN suite à erreur constatée, a priori cette nouvelle URL n'est plus celle du QR Code !
  15/11/2019
    passage igngp.geoapi.fr en https pour résoudre bug
  9/11/2019
    amélioration du controle d'accès
  4/11/2019:
    - ajout bindTooltip() dans onEachFeature()
  1-2/11/2019:
    - adaptation à la nouvelle version
  10/4/2019:
    - ajout des numéros de carte Shom
includes: [ ws/accesscntrl.inc.php ]
*/
require_once __DIR__.'/ws/accesscntrl.inc.php';

//print_r($_GET); die("map.php");
if (Access::cntrlFor('mapwcat') && !Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-type: text/plain; charset="utf-8"');
  die("Accès interdit");
}

$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$shomgturl = "$request_scheme://$_SERVER[HTTP_HOST]".dirname($_SERVER['SCRIPT_NAME']).'/';
//die("shomgturl=$shomgturl\n");

$center = (isset($_GET['center']) ? explode(',',$_GET['center']) : [46.5, 3]);
$center[0] = $center[0]+0;
$center[1] = $center[1]+0;
$zoom = (isset($_GET['zoom']) ? $_GET['zoom'] : 6);

?>
<!DOCTYPE HTML><html><head>
  <title>carte avec catalogue</title>
  <meta charset="UTF-8">
  <!-- meta nécessaire pour le mobile -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <!-- styles nécessaires pour le mobile -->
  <link rel='stylesheet' href='https://benoitdavidfr.github.io/leaflet/llmap.css'>
  <!-- styles et src de Leaflet -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.4.0/dist/leaflet.css"
    integrity="sha512-puBpdR0798OZvTTbP4A8Ix/l+A4dHDD0DGqYW6RQ+9jxkRFclaxxQb/SJAWZfWAkuyeQUytO7+7N4QKrDh+drA=="
    crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.4.0/dist/leaflet.js"
    integrity="sha512-QVftwZFqvtRNi0ZyCtsznlKSWOStnDORoefr1enyq5mVL4tmKB3S/EnC3rRJcxCPavG10IcrVGSmPh6Qw5lwrg=="
    crossorigin=""></script>
  <!-- Include the edgebuffer plugin -->
  <script src="https://benoitdavidfr.github.io/leaflet/leaflet.edgebuffer.js"></script>
  <!-- Include the Control.Coordinates plugin -->
  <link rel='stylesheet' href='https://benoitdavidfr.github.io/leaflet/Control.Coordinates.css'>
  <script src='https://benoitdavidfr.github.io/leaflet/Control.Coordinates.js'></script>
  <!-- Include the uGeoJSON plugin -->
  <script src="https://benoitdavidfr.github.io/leaflet/leaflet.uGeoJSON.js"></script>
  <!-- plug-in d'appel des GeoJSON en AJAX -->
  <script src='https://benoitdavidfr.github.io/leaflet/leaflet-ajax.js'></script>
</head>
<body>
  <div id="map" style="height: 100%; width: 100%"></div>
  <script>
var shomgturl = <?php echo "'$shomgturl';\n"; ?>
var attrshom = "&copy; <a href='http://data.shom.fr' target='_blank'>Shom</a>";

// affichage des caractéristiques de chaque GeoTiff
var onEachFeature = function (feature, layer) {
  var popupContent = '<pre><u><i>couche</i></u>: '+feature.properties.layer+"\n";
  popupContent += '<u><i>'+'titre</i></u>: '+feature.properties.title+"\n";
  popupContent += '<u><i>'+'nom</i></u>: '+feature.properties.gtname+"\n";
  popupContent += '<u><i>'+'échelle</i></u>: 1/'+feature.properties.scaleden+"\n";
  popupContent += '<u><i>'+'édition</i></u>: '+feature.properties.edition+"\n";
  popupContent += '<u><i>'+'dernière correction</i></u>: '+feature.properties.lastUpdate+"</pre>\n";
  gtname = feature.properties.gtname;
  num = gtname.substring(0,4);
  popupContent += '<b>Liens:</b><ul>';
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".png' target='_blank'>image PNG du géotiff</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+num+".png' target='_blank'>mini image PNG de la carte</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+num+".7z' target='_blank'>archive Shom de la carte</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".crop.tif' target='_blank'>"
    +"image GéoTIFF rognée</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".json' target='_blank'>"
    +"propriétés du géotiff en JSON</a></li>";
  popupContent += "<li><a href='https://gan.shom.fr/qr/gan/FR"+num+"' target='GAN'>"
    +"Groupe d’Avis aux Navigateurs (GAN) de la carte.</a></li>";  
  popupContent += '</ul>';
  layer.bindPopup(popupContent);
  layer.bindTooltip(feature.properties.title);
}

// affichage des caractéristiques de chaque polygone de ZEE
var onEachFeatureZee = function (feature, layer) {
  layer.bindPopup(
    '<b>ZEE</b><br>'
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
  layer.bindTooltip(feature.properties.label);
}

var map = L.map('map').setView(<?php echo json_encode($center),",$zoom";?>);  // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

// activation du plug-in Control.Coordinates
var c = new L.Control.Coordinates();
c.addTo(map);
map.on('click', function(e) { c.setCoordinates(e); });

var baseLayers = {
// PYR
  "Pyramide GéoTIFF" : new L.TileLayer(
    shomgturl+'tile.php/gtpyr/{z}/{x}/{y}.png',
    { format:"png", minZoom:0, maxZoom:18, detectRetina:false, attribution:attrshom }
  ),
// 1/20M
  "Planisphère Shom 1/20M" : new L.TileLayer(
    shomgturl+'tile.php/gt20M/{z}/{x}/{y}.png',
    { format:"png", minZoom:0, maxZoom:17, detectRetina:false, attribution:attrshom }
  ),
  "Cartes IGN Express" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/scan-express/{z}/{x}/{y}.jpg',
    {"format":"image/jpeg","minZoom":6,"maxZoom":18,"detectRetina":false,"attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"}
  ),
  "Cartes IGN classiques" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/cartes-classiques/{z}/{x}/{y}.jpg',
    {"format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"}
  ),
  "Plan IGN" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/plan-ign/{z}/{x}/{y}.jpg',
    {"format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"}
  ),
  "Fond blanc" : new L.TileLayer(
    'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
    { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
  )
};
map.addLayer(baseLayers["Pyramide GéoTIFF"]);

var overlays = {
  "ZEE" : new L.GeoJSON.AJAX(shomgturl+'cat/france.geojson', {
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee
  }),
  
<?php
  // liste des scaleDenomnator
  $sds = ['10M', '4M', '2M', '1M', '500k', '250k', '100k', '50k', '25k', '12k', '5k'];
  foreach ($sds as $sd) {
    echo "// 1/$sd\n";
    echo "    \"Catalogue 1/$sd\" : L.layerGroup([\n",
         "       new L.UGeoJSONLayer({\n",
         "         endpoint: shomgturl+'ws/geojson.php?lyr=gt$sd',\n",
         "         minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature\n",
         "       }),\n",
         "       new L.TileLayer(\n",
         "         shomgturl+'tile.php/num$sd/{z}/{x}/{y}.png',\n",
         "         {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "       ),\n",
         "    ]),\n";
    echo "    \"GéoTIFF 1/$sd\" : new L.TileLayer(\n",
         "       shomgturl+'tile.php/gt$sd/{z}/{x}/{y}.png',\n",
         "       {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false,'attribution':attrshom}\n",
         "    ),\n";
  }
?>
 
// gtaem
  "Catalogue AEM" :  L.layerGroup([
    new L.UGeoJSONLayer({
      endpoint: shomgturl+'ws/geojson.php?lyr=gtaem',
      once: true, minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
    }),
    new L.TileLayer(
      shomgturl+'tile.php/numaem/{z}/{x}/{y}.png',
      {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}
    )
  ]),
  "Cartes AEM" : new L.TileLayer(
    shomgturl+'tile.php/gtaem/{z}/{x}/{y}.png',
    {"format":"png","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":attrshom}
  ),
  
// gtMancheGrid
  "Catalogue MancheGrid" :  L.layerGroup([
    new L.UGeoJSONLayer({
      endpoint: shomgturl+'ws/geojson.php?lyr=gtMancheGrid',
      once: true, minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
    }),
    new L.TileLayer(
      shomgturl+'tile.php/numMancheGrid/{z}/{x}/{y}.png',
      {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}
    )
  ]),
  "Cartes MancheGrid" : new L.TileLayer(
    shomgturl+'tile.php/gtMancheGrid/{z}/{x}/{y}.png',
    {"format":"png","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":attrshom}
  ),

// affichage de l'antimeridien
  "antimeridien" : L.geoJSON(
    { "type": "MultiPolygon",
      "coordinates": [
         [[[ 180.0,-90.0 ],[ 180.1,-90.0 ],[ 180.1,90.0],[ 180.0,90.0 ],[ 180.0,-90.0 ] ] ],
         [[[-180.0,-90.0 ],[-180.1,-90.0 ],[-180.1,90.0],[-180.0,90.0 ],[-180.0,-90.0 ] ] ]
      ]
    },
   { style: { "color": "red", "weight": 2, "opacity": 0.65 } }),
    
// affichage d'une couche debug
  "debug" : new L.TileLayer(
    'http://visu.gexplor.fr/utilityserver.php/debug/{z}/{x}/{y}.png',
    {"format":"image/png","minZoom":0,"maxZoom":21,"detectRetina":false}
  )
};
map.addLayer(overlays["antimeridien"]);

L.control.layers(baseLayers, overlays).addTo(map);
    </script>
  </body>
</html>