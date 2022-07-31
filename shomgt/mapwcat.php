<?php
/*PhpDoc:
name: mapwcat.php
title: mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE
doc: |
journal: |
  28-31/7/2022:
    - correction suite à analyse PhpStan level 6
  1/6/2022:
    - ajout utilisation de la variable d'environnement SHOMGT3_MAPWCAT_FORCE_HTTPS
      - si elle vaut 'true' les appels générés sont en https même si l'appel de mapwcat.php est en http
      - indispensable pour utiliser shomgt derrière Traefik en https
  22/5/2022:
    - modif affichage des caractéristiques de chaque GeoTiff
    - corr. bug dans catalogues cartesAEM
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
require_once __DIR__.'/lib/accesscntrl.inc.php';

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

//print_r($_GET); die("map.php");
if (Access::cntrlFor('mapwcat') && !Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-type: text/plain; charset="utf-8"');
  die("Accès interdit");
}

$request_scheme = (getenv('SHOMGT3_MAPWCAT_FORCE_HTTPS') == 'true') ? 'https'
  : ($_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http');
$dirname = dirname($_SERVER['SCRIPT_NAME']);
$shomgturl = "$request_scheme://$_SERVER[HTTP_HOST]".($dirname=='/' ? '/' : "$dirname/");
//echo "<pre>"; print_r($_SERVER); die("shomgturl=$shomgturl\n");

/**
 * @param array<string, string> $versions
 * @return array<string, string>
*/
function latestVersion(array $versions): array {
  $latest = null;
  foreach ($versions as $k => $version) {
    if (!$latest)
      $latest = $k;
    elseif (strcmp($version, $versions[$latest]) > 0)
      $latest = $k;
  }
  return [$latest => $versions[$latest]];
}

$options = explode(',', $_GET['options'] ?? 'none');
foreach ($options as $option) {
  switch ($option) {
    case 'help': {
      echo "Options de ce script:<ul>\n";
      echo "<li>help : fournit cette aide</li>\n";
      echo "<li>version : fournit les dates de modification des fichiers sources</li>\n";
      echo "<li>center : fournit le centre de la carte sous la forme {lat},{lon}</li>\n";
      // echo "<li>zoom : fournit le zoom de la carte sous la forme d'un entier</li>\n";
      echo "</ul>\n";
      die();
    }
    case 'version': {
      $VERSION = array_merge(
        $VERSION,
        json_decode(file_get_contents("$shomgturl/tile.php?options=version"), true),
        json_decode(file_get_contents("$shomgturl/maps.php?options=version"), true),
      );
      header('Content-type: application/json');
      echo json_encode(['versions'=> $VERSION, 'latest'=> latestVersion($VERSION)]);
      die();
    }
    case 'none': break;
    default: {
      echo "Attention, option '$option' non gérée<br>\n";
      break;
    }
  }
}

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
  <!-- chgt du curseur -->
  <style>
  .leaflet-grab {
     cursor: auto;
  }
  .leaflet-dragging .leaflet-grab{
     cursor: move;
  }
  </style> 
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
  var popupContent = '<pre>';
  popupContent += '<u><i>couche</i></u>: '+feature.properties.layer+"\n";
  popupContent += '<u><i>titre</i></u>: '+feature.properties.title+"\n";
  popupContent += '<u><i>nom</i></u>: '+feature.properties.name+"\n";
  if (feature.properties.scaleDenominator) {
    popupContent += '<u><i>échelle</i></u>: 1/'+feature.properties.scaleDenominator+"\n";
    popupContent += '<u><i>édition</i></u>: '+feature.properties.edition+"\n";
    popupContent += '<u><i>dernière correction</i></u>: '+feature.properties.lastUpdate+"\n";
    popupContent += '<u><i>mdDate</i></u>: '+feature.properties.mdDate+"\n";
  }
  popupContent += '<u><i>Semaine de maj (estim.)</i></u>: '+feature.properties.ganWeek+"\n";
  if (feature.properties.errorMessage)
    popupContent += '<u><i>errorMessage</i></u>: '+feature.properties.errorMessage+"\n";
  popupContent += "</pre>\n";
  num = feature.properties.name.substring(0,4);
  ganWeek = feature.properties.ganWeek;
  popupContent += "\n<b>Liens:</b><ul>\n";
  popupContent += "<li><a href='"+shomgturl+"dl.php/"+feature.properties.name+"' target='_blank'>téléchargements</a></li>\n";
  popupContent += "<li><a href='https://www.shom.fr/qr/gan/FR"+num+"/"+ganWeek+"' target='GAN'>"
    +"Corrections (GAN) non prises en compte.</a></li>\n";  
  popupContent += '</ul>';
  layer.bindPopup(popupContent, {maxWidth: 600});
  layer.bindTooltip(feature.properties.title);
}

// affichage des caractéristiques de chaque GeoTiff / partie effacée
var onEachFeatureDeleted = function (feature, layer) {
  var popupContent = "<pre><b>Partie effacée</b>\n";
  popupContent += '<u><i>couche</i></u>: '+feature.properties.layer+"\n";
  popupContent += '<u><i>titre</i></u>: '+feature.properties.title+"\n";
  popupContent += '<u><i>nom</i></u>: '+feature.properties.name+"\n";
  popupContent += "</pre>\n";
  layer.bindPopup(popupContent, {maxWidth: 600});
  layer.bindTooltip(feature.properties.title);
}

// options d'affichage des coins des sihouettes des GeoTiffs
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

// affichage des caractéristiques des limites maritimes
var onEachFeatureDelmar = function (feature, layer) {
  var popupContent = '<b>Délimitations maritimes, source Shom, 2022 (extrait WFS)</b><br><table>';
  popupContent += '<tr><td>type</td><td>'+feature.properties.type+"</td></tr>\n";
  popupContent += '<tr><td>nature</td><td>'+feature.properties.nature+"</td></tr>\n";
  popupContent += '<tr><td>description</td><td>'+feature.properties.description+"</td></tr>\n";
  popupContent += '<tr><td>reference</td><td>'+feature.properties.reference+"</td></tr>\n";
  popupContent += '<tr><td>layerName</td><td>'+feature.properties.layerName+"</td></tr>\n";
  popupContent += '<tr><td>inspireId</td><td>'+feature.properties.inspireId+"</td></tr>\n";
  popupContent += "</table>\n";
  layer.bindPopup(popupContent, {maxWidth: 600});
  layer.bindTooltip(feature.properties.type);
}

// affichage des caractéristiques de chaque polygone de ZEE
var onEachFeatureZee = function (feature, layer) {
  layer.bindPopup(
    '<b>ZEE simplifiées</b><br>'
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
  layer.bindTooltip(feature.properties.title);
}

// affichage des caractéristiques de chaque polygone de SAR
var onEachFeatureSar = function (feature, layer) {
  layer.bindPopup(
    '<b>Zones SRR (SAR), source Shom 2019</b><br>'
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
  layer.bindTooltip(feature.properties.nom_fr);
}

// affichage des caractéristiques de chaque polygone de GT
var onEachFeatureGt = function (feature, layer) {
  layer.bindPopup(
    '<b>GT</b><br>'
    +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
  );
  layer.bindTooltip(feature.properties.name);
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
    {"format":"png","minZoom":0,"maxZoom":8,"detectRetina":false,"attribution":attrshom}
  ),
  // IGN
  "Ortho IGN" : new L.TileLayer(
    'https://wxs.ign.fr/essentiels/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0'
      +'&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&TILEMATRIXSET=PM&TILEMATRIX={z}&TILECOL={x}&TILEROW={y}'
      +'&STYLE=normal&FORMAT=image/jpeg',
    { "format":"image/jpeg","minZoom":0,"maxZoom":21,"detectRetina":false,
      "attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"
    }
  ),
  "Plan IGN" : new L.TileLayer(
    'https://wxs.ign.fr/essentiels/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0'
      +'&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&TILEMATRIXSET=PM&TILEMATRIX={z}&TILECOL={x}&TILEROW={y}'
      +'&STYLE=normal&FORMAT=image/png',
    { "format":"image/png","minZoom":0,"maxZoom":18,"detectRetina":false,
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
  "Délim. maritimes (Shom)" : new L.GeoJSON.AJAX(shomgturl+'geojson/delmar.geojson', {
    style: { color: 'SteelBlue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureDelmar
  }),
  "ZEE simplifiée" : new L.GeoJSON.AJAX(shomgturl+'geojson/frzee.geojson', {
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureZee
  }),
  "SAR-SRR" : new L.GeoJSON.AJAX(shomgturl+'geojson/sar_2019.geojson', {
    style: { color: 'green'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeatureSar
  }),

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
         "       new L.UGeoJSONLayer({\n", // la couche des zones effacées
         "         endpoint: shomgturl+'maps.php/collections/gt$sd/deletedZones',\n",
         "         style: { color: 'red'}, minZoom: 0, maxZoom: 18, usebbox: true,\n",
         "         onEachFeature: onEachFeatureDeleted\n",
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
      minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
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
      minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
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