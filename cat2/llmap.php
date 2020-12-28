<?php
/*PhpDoc:
name: llmap.php
title: cat2/llmap.php - affichage de la carte LL du catalogue des cartes par tranches d'échelles ou d'une carte
doc: |
journal: |
  21/12/2020:
    ajout possibilité d'afficher qu'une carte
  14/12/2020:
    passage en catv2
  9/3/2019:
    fork dans gt/cat
  12/12/2018:
    modif de l'URL de geoapi pour que le script fonctionne sur geoapi.fr
  11/12/2018:
    nouvelle version repartant de shomgtcatmap.php
*/
$lat0 = $_GET['lat'] ?? 46.5;
$lon0 = $_GET['lon'] ?? 3;
$zoom0 = $_GET['zoom'] ?? 6;
//echo "<pre>_SERVER="; print_r($_SERVER); die();
$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$shomgtcaturl = "$request_scheme://$_SERVER[HTTP_HOST]".dirname($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE HTML><html><head><title>ShomgtCatMap</title><meta charset='UTF-8'>
<!-- meta nécessaire pour le mobile -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <link rel='stylesheet' href='https://benoitdavidfr.github.io/leaflet/llmap.css'>
  <!-- styles et src de Leaflet -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css"
    integrity="sha512-xodZBNTC5n17Xt2atTPuE1HxjVMSvLVW9ocqUKLsCC5CXdbqCmblAshOMAS6/keqq/sMZMZ19scR4PsZChSR7A=="
    crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"
    integrity="sha512-XQoYMqMTK8LvdxXYG3nZ448hOEQiglfqkJs1NOQV44cWnUrBc8PkAOcXy20w0vlaXaVUearIOBhiXZ5V3ynxwA=="
    crossorigin=""></script>
  <!-- Include the uGeoJSON plugin -->
  <!-- <script src="https://benoitdavidfr.github.io/leaflet/leaflet.uGeoJSON.js"></script> -->
  <!-- plug-in d'appel des GeoJSON en AJAX -->
  <script src='https://benoitdavidfr.github.io/leaflet/leaflet-ajax.js'></script>
  <!-- Include the edgebuffer plugin -->
  <script src="https://benoitdavidfr.github.io/leaflet/leaflet.edgebuffer.js"></script>
</head>
<body>
  <div id='map' style='height: 100%; width: 100%'></div>
  <script>
  var shomgtcaturl = <?php echo "'$shomgtcaturl'";?>;
  var map = L.map('map').setView(<?php echo"[$lat0, $lon0], $zoom0";?>); // view pour la zone
  L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);
  var attrshom = "&copy; <a href='http://data.shom.fr' target='_blank'>Shom</a>";
  var baseLayers = {
    "Pyramide GéoTIFF" : new L.TileLayer(
      'https://geoapi.fr/shomgt/tile.php/gtpyr/{z}/{x}/{y}.png',
      {"format":"image/png","minZoom":0,"maxZoom":18,"detectRetina":false,"attribution":attrshom}
    ),
    "Plan IGN" : new L.TileLayer(
      'http://igngp.geoapi.fr/tile.php/plan-ignv2/{z}/{x}/{y}.png',
      {"format":"image/png","minZoom":0,"maxZoom":18,"attribution":"&copy; <a href='http://www.ign.fr'>IGN</a>"}
    ),
    "Ortho-images" : new L.TileLayer(
      'http://igngp.geoapi.fr/tile.php/orthos/{z}/{x}/{y}.jpg',
      {"format":"image/jpeg","minZoom":0,"maxZoom":18,"attribution":"&copy; <a href='http://www.ign.fr'>IGN</a>"}
    ),
    "Fond blanc" : new L.TileLayer(
      'http://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
      {"format":"image/jpeg","minZoom":0,"maxZoom":21}
    ),
  };
  map.addLayer(baseLayers["Pyramide GéoTIFF"]);
  
  var onEachFeature = function (feature, layer) {
    id = feature.id;
    layer.bindPopup(
      "<a href='"+shomgtcaturl+"/mapcat.php/"+id+"' target='_blank'>"+id+"</a>\n"
      +'<pre>'+JSON.stringify(feature.properties,null,' ')+'</pre>'
    );
    layer.bindTooltip(feature.id + ' - ' + feature.properties.title);
  }
  var styleOfMap = function (feature) {
    if (feature.properties.mapsFrance.length != 0) {
      return {color: 'green'};
    } else {
      return {color: 'red'};
    }
  }
  var overlays = {
    "ZEE simplifiée" : new L.GeoJSON.AJAX(shomgtcaturl+'/france.geojson', {
      style: {color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature
    }),
    'limitesMartimes+ZEE' : L.layerGroup([
      new L.GeoJSON.AJAX( // agreedmaritimeboundary
        shomgtcaturl+'/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_agreedmaritimeboundary/items?f=json',
        {style: {color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature}
      ),
      new L.GeoJSON.AJAX( // nonagreedmaritimeboundary
        shomgtcaturl+'/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary/items?f=json',
        {style: {color: 'blue', dashArray: '3,6'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature}
      ),
      new L.GeoJSON.AJAX(
        shomgtcaturl+'/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_economicexclusivezone/items?f=json',
        {style: {color: 'SteelBlue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature}
      )
    ]),
    "PF continentale" : new L.GeoJSON.AJAX(
      shomgtcaturl+'/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_continentalshelf/items?f=json',
      {style: {color: 'RoyalBlue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature}
    ),
    
<?php
  // affiche une carte particulière en orange
  if ($mapid = ($_GET['mapid'] ?? null)) {
    echo "    '$mapid' : L.layerGroup([\n",
         "        new L.GeoJSON.AJAX(shomgtcaturl+'/mapcat.php/$mapid?f=geojson', {\n",
         "          style: {color:'orange'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature\n",
         "        }),\n",
         "        new L.TileLayer(\n",
         "          shomgtcaturl+'/tilenum.php/mapid$mapid/{z}/{x}/{y}.png',\n",
         "          {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "        )\n",
         "    ]),\n";
    $defaultOverlays = [$mapid];
  }
  // liste des scaleDenomnator, sous la forme [label => valeur], définissant n intervalles
  $sds = [
    '10M'=>1e7, '6.5M'=>6.5e6, '2.5M'=>2.5e6, '1.1M'=>1.1e6, '700k'=>7e5, '500k'=>5e5, '375k'=>3.75e5, '200k'=>2e5,
    '100k'=>1e5, '50k'=>5e4, '25k'=>2.5e4, '12.5k'=>1.25e4, 0=>0];
  $sdmax = '';
  foreach (array_keys($sds) as $no => $sdmin) {
    $title = $sdmax ? "$sdmin < sd <= $sdmax" : "sd > $sdmin";
    $titleWfs = $sdmax ? "$sdmin < wfs <= $sdmax" : "wfs > $sdmin";
    $sdvmax = $sdmax ? $sds[$sdmax] : '';
    $sdvmin = $sds[$sdmin];
    echo "    \"$title\" : L.layerGroup([\n",
         "        new L.GeoJSON.AJAX(shomgtcaturl+'/mapcat.php?f=geojson&sdmax=$sdvmax&sdmin=$sdvmin', {\n",
         "          style: styleOfMap, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature\n",
         "        }),\n",
         "        new L.TileLayer(\n",
         "          shomgtcaturl+'/tilenum.php/cat$sdvmin-$sdvmax/{z}/{x}/{y}.png',\n",
         "          {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "        )\n",
         "    ]),\n";
    echo "    \"$titleWfs\" : L.layerGroup([\n",
         "        new L.GeoJSON.AJAX(shomgtcaturl+'/shomgtwfs.php?sdmax=$sdvmax&sdmin=$sdvmin', {\n",
         "          style: styleOfMap, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature\n",
         "        }),\n",
         "        new L.TileLayer(\n",
         "          shomgtcaturl+'/tilenum.php/wfs$sdvmin-$sdvmax/{z}/{x}/{y}.png',\n",
         "          {'format':'png','minZoom':0,'maxZoom':18,'detectRetina':false}\n",
         "        )\n",
         "    ]),\n";
    $sdmax = $sdmin;
  }
  if (!isset($defaultOverlays))
    $defaultOverlays = ['700k < sd <= 1.1M'];
?>
// affichage de l'antimeridien
    "antimeridien" : L.geoJSON(
      { "type": "MultiPolygon",
        "coordinates": [
           [[[ 180.0,-90.0 ],[ 180.1,-90.0 ],[ 180.1,90.0],[ 180.0,90.0 ],[ 180.0,-90.0 ] ] ],
           [[[-180.0,-90.0 ],[-180.1,-90.0 ],[-180.1,90.0],[-180.0,90.0 ],[-180.0,-90.0 ] ] ]
        ]
      },
      { style: { "color": "red", "weight": 2, "opacity": 0.65 } }),
  };
<?php
  foreach ($defaultOverlays as $lyrId)
    echo "  map.addLayer(overlays['$lyrId']);\n";
?>
  map.addLayer(overlays["antimeridien"]);

  L.control.layers(baseLayers, overlays).addTo(map);
  </script>
</body></html>
