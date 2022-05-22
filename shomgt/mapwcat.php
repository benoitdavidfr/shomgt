<?php
/*PhpDoc:
name: mapwcat.php
title: mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE
doc: |
journal: |
  1-3/5/2022:
    - clonage dans shomgt3 et adaptation
  26/4/2022:
    - ajout d'une couche des points des rectangles des GéoTiffs
  12/12/2020:
    - correction du lien du GAN dans les cartes
    - ajout du champ mdDate qui est la date des métadonnées ISO 19139
  22/11/2019:
    - modification du code afin que la carte Leaflet fonctionne sur un poste non connecté à internet
      (demande Dominique Bon du CROSS Corsen)
    - téléchargement dans le répertoire leaflet du code nécessaire à la carte Leaflet et intégration dans le Github
  21/11/2019:
    ajout OSM!
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
includes: [ lib/accesscntrl.inc.php ]
*/
//require_once __DIR__.'/lib/accesscntrl.inc.php';

//print_r($_GET); die("map.php");
/*if (Access::cntrlFor('mapwcat') && !Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-type: text/plain; charset="utf-8"');
  die("Accès interdit");
}*/

$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$dirname = dirname($_SERVER['SCRIPT_NAME']);
$shomgturl = "$request_scheme://$_SERVER[HTTP_HOST]".($dirname=='/' ? '/' : "$dirname/");
//echo "<pre>"; print_r($_SERVER); die("shomgturl=$shomgturl\n");

$center = (isset($_GET['center']) ? explode(',',$_GET['center']) : [46.5, 3]);
$center[0] = floatval($center[0]);
$center[1] = floatval($center[1]);
$zoom = $_GET['zoom'] ?? 6;

?>
<!DOCTYPE HTML><html><head>
  <title>carte avec catalogue</title>
  <meta charset="UTF-8">
  <!-- meta nécessaire pour le mobile -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <!-- styles nécessaires pour le mobile -->
  <link rel='stylesheet' href='leaflet/llmap.css'>
  <!-- styles et src de Leaflet -->
  <link rel="stylesheet" href='leaflet/leaflet.css'/>
  <script src='leaflet/leaflet.js'></script>
  <!-- Include the edgebuffer plugin -->
  <script src="leaflet/leaflet.edgebuffer.js"></script>
  <!-- Include the Control.Coordinates plugin -->
  <link rel='stylesheet' href='leaflet/Control.Coordinates.css'>
  <script src='leaflet/Control.Coordinates.js'></script>
  <!-- Include the uGeoJSON plugin -->
  <script src="leaflet/leaflet.uGeoJSON.js"></script>
  <!-- plug-in d'appel des GeoJSON en AJAX -->
  <script src='leaflet/leaflet-ajax.js'></script>
</head>
<body>
  <div id="map" style="height: 100%; width: 100%"></div>
  <script>
var shomgturl = <?php echo "'$shomgturl';\n"; ?>
    var attrshom = "&copy; <a href='http://data.shom.fr' target='_blank'>Shom</a>";

// affichage des caractéristiques de chaque GeoTiff
var onEachFeature = function (feature, layer) {
  /*
  popupContent += '<b>Liens:</b><ul>';
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".png' target='_blank'>image PNG du géotiff</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+num+".png' target='_blank'>mini image PNG de la carte</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+num+".7z' target='_blank'>archive Shom de la carte</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".crop.tif' target='_blank'>"
    +"image GéoTIFF rognée</a></li>";
  popupContent += "<li><a href='"+shomgturl+"ws/dl.php/"+gtname+".json' target='_blank'>"
    +"propriétés du géotiff en JSON</a></li>";
  popupContent += '</ul>';*/
  var popupContent = '<pre><u><i>couche</i></u>: '+feature.properties.layer+"\n";
  popupContent += '<u><i>titre</i></u>: '+feature.properties.title+"\n";
  popupContent += '<u><i>nom</i></u>: '+feature.properties.name+"\n";
  popupContent += '<u><i>échelle</i></u>: 1/'+feature.properties.scaleDenominator+"\n";
  popupContent += '<u><i>édition</i></u>: '+feature.properties.edition+"\n";
  popupContent += '<u><i>dernière correction</i></u>: '+feature.properties.lastUpdate+"\n";
  popupContent += '<u><i>mdDate</i></u>: '+feature.properties.mdDate+"\n";
  popupContent += '<u><i>Semaine de maj (estim.)</i></u>: '+feature.properties.ganWeek+"\n";
  popupContent += "</pre>\n";
  num = feature.properties.name.substring(0,4);
  ganWeek = feature.properties.ganWeek;
  popupContent += "\n<b>Liens:</b><ul>\n";
  popupContent += "<li><a href='"+shomgturl+"dl.php/"+feature.properties.name+"' target='_blank'>téléchargements</a></li>\n";
  popupContent += "<li><a href='https://www.shom.fr/qr/gan/FR"+num+"/"+ganWeek+"' target='GAN'>"
    +"Corrections (GAN) non prises en compte.</a></li>\n";  
  popupContent += '</ul>';
  layer.bindPopup(popupContent);
  layer.bindTooltip(feature.properties.title);
}

// affichage des coins des GeoTiffs
var geojsonMarkerOptions = {
    radius: 3,
    fillColor: "#0000ff", // blue
    color: "#000",
    weight: 1,
    opacity: 1,
    fillOpacity: 0.8,
    pane: 'markerPane' // le symbole ponctuel est dessiné dans la couche markerPane pour être au dessus des polygones
};
// Dessin d'un cercle défini par geojsonMarkerOptions
var pointToLayer = function (feature, latlng) { return L.circleMarker(latlng, geojsonMarkerOptions); }

// affichage des caractéristiques de points des GeoTiff
var onEachPointFeature = function (feature, layer) {
  var popupContent = '<pre><u><i>layer</i></u>: '+feature.properties.layer+"\n";
  popupContent += '<u><i>gtname</i></u>: '+feature.properties.gtname+"\n";
  popupContent += '<u><i>corner</i></u>: '+feature.properties.corner+"\n";
  popupContent += '<u><i>latLonDM</i></u>: '+feature.properties.latLonDM+"\n";
  popupContent += '</pre>';
  layer.bindPopup(popupContent);
  layer.bindTooltip(feature.properties.latLonDM);
}

// affichage des caractéristiques de chaque polygone de ZEE
var onEachFeatureZee = function (feature, layer) {
  layer.bindPopup(
    '<b>ZEE et frontières</b><br>'
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
  layer.bindTooltip(feature.properties.title);
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
  // Planisphère 1/40M
  "Planisphère 1/40M" : new L.TileLayer(
    shomgturl+'tile.php/gt40M/{z}/{x}/{y}.png',
    { format:"png", minZoom:0, maxZoom:17, detectRetina:false, attribution:attrshom }
  ),
  // gtDelMar
  "Zones maritimes" : new L.TileLayer(
    shomgturl+'tile.php/gtZonMar/{z}/{x}/{y}.png',
    {"format":"png","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":attrshom}
  ),
  // IGN
  "Plan IGN" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/plan-ignv2/{z}/{x}/{y}.png',
    { "format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,
      "attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"
    }
  ),
  // OSM
  "OSM" : new L.TileLayer(
    'http://{s}.tile.osm.org/{z}/{x}/{y}.png',
    {"attribution":"&copy; <a href='https://www.openstreetmap.org/copyright' target='_blank'>les contributeurs d’OpenStreetMap</a>"}
  ),
  // Fond blanc
  "Fond blanc" : new L.TileLayer(
    'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
    { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
  )
};
map.addLayer(baseLayers["Pyramide GéoTIFF"]);

var overlays = {
/*  'frontières+ZEE' : L.layerGroup([
    new L.GeoJSON.AJAX( // agreedmaritimeboundary
      shomgturl+'cat2/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_agreedmaritimeboundary/items?f=json',
      {style: {color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee}
    ),
    new L.GeoJSON.AJAX( // nonagreedmaritimeboundary
      shomgturl+'cat2/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary/items?f=json',
      {style: {color: 'blue', dashArray: '3,6'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee}
    ),
    new L.GeoJSON.AJAX( // economicexclusivezone
      shomgturl+'cat2/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_economicexclusivezone/items?f=json',
      {style: {color: 'SteelBlue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee}
    )
  ]),
  "ZEE simplifiée" : new L.GeoJSON.AJAX(shomgturl+'cat2/france.php', {
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee
  }),
*/
<?php
  // liste des scaleDenomnator
  $sds = ['10M', '4M', '2M', '1M', '500k', '250k', '100k', '50k', '25k', '12k', '5k'];
  foreach ($sds as $sd) {
    echo "// 1/$sd\n";
    echo "    \"Catalogue 1/$sd\" : L.layerGroup([\n",
         "       new L.UGeoJSONLayer({\n", // la couche des rectangles
         "         endpoint: shomgturl+'maps.php/collections/gt$sd/items',\n",
         "         minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature\n",
         "       }),\n",
         "       new L.UGeoJSONLayer({\n", // la couche des coins des rectangles pour afficher leurs coordonnées
         "         endpoint: shomgturl+'maps.php/collections/gt$sd/corners',\n",
         "         minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachPointFeature,\n",
         "         pointToLayer: pointToLayer\n",
         "       }),\n",
         "       new L.TileLayer(\n", // la couche des étiquettes des GéoTiffs
         "         shomgturl+'tile.php/num$sd/{z}/{x}/{y}.png',\n",
         "         {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "       ),\n",
         "    ]),\n";
    echo "    \"GéoTIFF 1/$sd\" : new L.TileLayer(\n", // le contenu des GéoTiffs
         "       shomgturl+'tile.php/gt$sd/{z}/{x}/{y}.png',\n",
         "       {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false,'attribution':attrshom}\n",
         "    ),\n";
  }
?>
 
// gtaem
  "Catalogue AEM" :  L.layerGroup([
    new L.UGeoJSONLayer({
      endpoint: shomgturl+'maps.php/collections/gtaem/items',
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
      endpoint: shomgturl+'maps.php/collections/gtMancheGrid/items',
      once: true, minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
    }),
    new L.TileLayer(
      shomgturl+'tile.php/numMancheGrid/{z}/{x}/{y}.png',
      {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}
    )
  ]),
  "Carte MancheGrid" : new L.TileLayer(
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