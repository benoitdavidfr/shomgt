<?php
/*PhpDoc:
name: map.php
title: map.php - carte de test des web-services
doc: |
journal: |
  10/4/2019:
    - ajout des numéros de carte Shom
*/
$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$shomgturl = "$request_scheme://$_SERVER[HTTP_HOST]".dirname($_SERVER['SCRIPT_NAME']).'/';
//die("shomgturl=$shomgturl\n");
?>
<!DOCTYPE HTML><html><head>
  <title>test de gt/ws</title>
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
  layer.bindPopup(
    '<b>'+feature.properties.layer+'</b><br>'
    +"<a href='"+shomgturl+"updt/frame.php?gtname="+feature.properties.gtname+"' target='_blank'>frame</a>"
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
}

var map = L.map('map').setView([46.5,3],6);  // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

// activation du plug-in Control.Coordinates
var c = new L.Control.Coordinates();
c.addTo(map);
map.on('click', function(e) { c.setCoordinates(e); });

var baseLayers = {
// PYR
  "Pyramide GéoTIFF" : new L.TileLayer(
    shomgturl+'ws/tile.php/gtpyr/{z}/{x}/{y}.png',
    { format:"png", minZoom:0, maxZoom:18, detectRetina:false, attribution:attrshom }
  ),
// 1/20M
  "Planisphère Shom 1/20M" : new L.TileLayer(
    shomgturl+'ws/tile.php/gt20M/{z}/{x}/{y}.png',
    { format:"png", minZoom:0, maxZoom:17, detectRetina:false, attribution:attrshom }
  ),
  "Cartes IGN Express" : new L.TileLayer(
    'https://wxs.ign.fr/2hl0xk4s8pz482s81o4nrilt/geoportail/wmts?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256&tilematrix={z}&tilecol={x}&tilerow={y}&layer=GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD&format=image/jpeg&style=normal',
    {"format":"image/jpeg","minZoom":6,"maxZoom":18,"detectRetina":false,"attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"}
  ),
  "Cartes IGN classiques" : new L.TileLayer(
    'https://wxs.ign.fr/2hl0xk4s8pz482s81o4nrilt/geoportail/wmts?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256&tilematrix={z}&tilecol={x}&tilerow={y}&layer=GEOGRAPHICALGRIDSYSTEMS.MAPS&format=image/jpeg&style=normal',
    {"format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"}
  ),
  "Plan IGN" : new L.TileLayer(
    'https://wxs.ign.fr/2hl0xk4s8pz482s81o4nrilt/geoportail/wmts?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256&tilematrix={z}&tilecol={x}&tilerow={y}&layer=GEOGRAPHICALGRIDSYSTEMS.PLANIGN&format=image/jpeg&style=normal',
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
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature
  }),
  
<?php
  // liste des scaleDenomnator
  $sds = ['10M', '4M', '2M', '1M', '500k', '250k', '100k', '50k', '25k', '12k'];
  foreach ($sds as $sd) {
    echo "// 1/$sd\n";
    echo "    \"Catalogue 1/$sd\" : L.layerGroup([\n",
         "       new L.UGeoJSONLayer({\n",
         "         endpoint: shomgturl+'ws/geojson.php?lyr=gt$sd',\n",
         "         minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature\n",
         "       }),\n",
         "       new L.TileLayer(\n",
         "         shomgturl+'ws/tile.php/num$sd/{z}/{x}/{y}.png',\n",
         "         {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "       ),\n",
         "    ]),\n";
    echo "    \"GéoTIFF 1/$sd\" : new L.TileLayer(\n",
         "       shomgturl+'ws/tile.php/gt$sd/{z}/{x}/{y}.png',\n",
         "       {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false,'attribution':attrshom}\n",
         "    ),\n";
  }
?>
 
// gtaem
  "Cartes AEM" : new L.TileLayer(
    shomgturl+'ws/tile.php/gtaem/{z}/{x}/{y}.png',
    {"format":"png","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":attrshom}
  ),
  "Catalogue AEM" : new L.UGeoJSONLayer({
    endpoint: shomgturl+'ws/geojson.php?sd=$sd',
    once: true, minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
  }),

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