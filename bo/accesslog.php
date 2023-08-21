<?php
/*PhpDoc:
name: accesslog.php
title: accesslog.php - affichage des logs d'accès
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/zoom.inc.php';

use Symfony\Component\Yaml\Yaml;

if ($_SERVER['HTTP_HOST'] == 'localhost')
  $LOG_MYSQL_URI = 'mysql://bdavid:dsbune44@mysql-bdavid.alwaysdata.net/bdavid_shomgt';
else
  $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI') or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
MySql::open($LOG_MYSQL_URI);

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>bo/accesslog@$_SERVER[HTTP_HOST]</title></head><body>\n";

class SqlDef {
  // la structuration de la constante est définie dans son champ description
  const IPADDRESS_SCHEMA = [
    'comment' => "table des adresses IP",
    'columns' => [
      'ip' => [
        'type'=> 'varchar(256)',
        'keyOrNull'=> 'primary key',
        'comment'=> "adresse IP",
      ],
      'label' => [
        'type'=> 'varchar(256)',
        'comment'=> "étiquette associée",
      ],
    ],
  ]; // Définition du schéma de la table ipaddress
  const IPADDRESS_CONTENT = [
    ['86.244.235.216', "La bergerie"],
    ['127.0.0.1', "Accès local"],
    ['172.20.0.8', "Docker"],
    ['185.31.40.12', "Alwaysdata IPv4 (bdavid)"],
    ['199.19.249.196', "RIE"],
    ['185.24.185.194', "RIE"],
    ['185.24.186.194', "RIE"],
    ['185.24.184.194', "RIE"],
    ['185.24.184.209', "RIE"],
    ['185.24.187.196', "RIE"],
    ['185.2.196.196', "RIE"],
    ['195.6.33.18', "SDES-Orléans"],
    ['159.180.226.236', "OFB-AAMP"],
    ['217.108.227.133', "OFB-AAMP"],
    ['195.101.150.124', "DM-SOI"],
    ['41.242.116.32', "DEAL Mayotte"],
    ['213.41.80.110', "DAM"],
    ['185.24.187.194', "RIE"],
    ['185.24.184.208', "RIE"],
    ['185.24.184.212', "RIE"],
    ['185.24.185.212', "RIE"],
    ['185.24.186.212', "RIE"],
    ['185.24.187.212', "RIE"],
    ['194.5.172.137', "Centre serveur SG/DNum"],
    ['194.5.173.137', "Centre serveur SG/DNum"],
    ['185.24.186.192', "RIE"],
    ['185.24.187.124', "RIE"],
    ['185.24.187.191', "RIE"],
    ['83.206.157.137', "RIE"],
    ['86.246.91.34', "RIE"],
    ['192.93.226.1', "Cerema"],
    ['194.57.229.5', "Shom"],
    ['134.246.184.7', "Shom"],
    ['137.129.13.93', "Shom"],
  ];
  // fabrique le code SQL de création de la table à partir d'une des constantes de définition du schéma
  /** @param array<string, mixed> $schema */
  static function sql(string $tableName, array $schema): string {
    $cols = [];
    foreach ($schema['columns'] ?? [] as $cname => $col) {
      $cols[] = "  $cname "
        .match($col['type'] ?? null) {
          'enum' => "enum('".implode("','", array_keys($col['enum']))."')",
          default => "$col[type] ",
          null => die("<b>Erreur, la colonne '$cname' doit comporter un champ 'type'</b>."),
      }
      .($col['keyOrNull'] ?? '')
      .(isset($col['comment']) ? " comment \"$col[comment]\"" : '');
    }
    return "create table $tableName (\n"
      .implode(",\n", $cols)."\n)"
      .(isset($schema['comment']) ? " comment \"$schema[comment]\"\n" : '');
  }
};

// classe traduisant un URI correspondant à une requête WMS ou tile dans le polygone GeoJSON de la loc. de la requête
class GJGeom {
  // extrait le bbox d'une requête WMS et le transforme en Polygone GeoJSON
  // retourne [] si le BBOX n'est pas détecté dans la requête
  static function wmsRequest2GJGeom(string $request_uri): array {

    // détermination du bbox
    $bboxPattern = '!BBOX=(-?\d+(\.\d+)?)(%2C|,)(-?\d+(\.\d+)?)(%2C|,)(-?\d+(\.\d+)?)(%2C|,)(-?\d+(\.\d+)?)&!i';
    if (!preg_match($bboxPattern, $request_uri, $matches)) {
      return [];
    }
    //echo "<pre>request_uri=$request_uri</pre>\n";
    $ebox = new EBox([(float)$matches[1], (float)$matches[4], (float)$matches[7], (float)$matches[10]]);

    // détermination du CRS WMS 1.3.0 / 1.1.1
    if (preg_match('!version=1\.3\.0!i', $request_uri)) {
      if (!preg_match('!CRS=([A-Z:\d%]+)&!i', $request_uri, $matches)) {
        echo "<pre>request_uri=$request_uri</pre>\n";
        throw new Exception("Erreur CRS non détecté dans requête WMS version 1.3.0");
      }
      //echo "<pre>crs ->>",Yaml::dump($matches),"</pre>\n";
      $crs = $matches[1];
    }
    elseif (preg_match('!version=1\.1\.1!i', $request_uri)) {
      if (!preg_match('!SRS=([A-Z:\d%]+)&!i', $request_uri, $matches)) {
        echo "<pre>request_uri=$request_uri</pre>\n";
        throw new Exception("Erreur SRS non détecté dans requête WMS version 1.1.0");
      }
      //echo "<pre>crs ->>",Yaml::dump($matches),"</pre>\n";
      $srs = $matches[1];
      $crs = ($srs == 'EPSG:4326') ? 'CRS:84' : $srs;
    }
    else {
      echo "<pre>request_uri=$request_uri</pre>\n";
      throw new Exception("Dans requête WMS, version ni 1.1.0 ni 1.3.0");
    }
    
    // conversion de l'ebox en GBox et génération du GeoJSON
    $proj = match ($crs) {
      'CRS:84' => 'LonLatDd',
      'EPSG:4326' => 'LatLonDd',
      'EPSG:3857','EPSG%3A3857' => 'WebMercator',
      'EPSG:3395','EPSG%3A3395' => 'WorldMercator',
      default => die("CRS $crs non pris en compte dans GJGeom::bbox2GJGeom()"),
    };
    return [
      'type'=> 'Polygon',
      'coordinates'=> $ebox->geo($proj)->polygon(),
    ];
  }
  
  // tarnsforme une requête sur une tuile en géométrie GeoJSON 
  static function tileRequest2GJGeom(string $request_uri): array {
    if (!preg_match('!^/shomgt/tile.php/[^/]+/(\d+)/(\d+)/(\d+).png$!', $request_uri, $matches))
      return [];
    //echo "<pre>request_uri=$request_uri</pre>\n";
    $ebox = Zoom::tileEBox($matches[1], $matches[2], $matches[3]); // $ebox en coord. WebMercator
    return [
      'type'=> 'Polygon',
      'coordinates'=> $ebox->geo('WebMercator')->polygon(),
    ];
  }

  static function test(): void {
    if (0) { // test WMS
      $request_uri = '/shomgt/wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap'
        .'&BBOX=45.59043706550564679,-1.081144577564866704,45.64622396475353838,-0.9709213645309664464'
        .'&CRS=EPSG:4326&WIDTH=1463&HEIGHT=740&LAYERS=gtpyr&STYLES=&FORMAT=image/png'
        .'&DPI=96&MAP_RESOLUTION=96&FORMAT_OPTIONS=dpi:96&TRANSPARENT=TRUE';
      echo "<pre>",Yaml::dump(self::wmsRequest2GJGeom($request_uri), 4);
    }
    elseif (1) { // test tile
      echo "<pre>",Yaml::dump(self::tileRequest2GJGeom('/shomgt/tile.php/gtpyr/18/128733/91496.png'), 4);
    }
    die("Fin ligne ".__LINE__);
  }
};
//GJGeom::test();

function durationInHours(string $duration): int {
  return match (substr($_GET['duration'], -1)) {
    'h' => intval(substr($_GET['duration'], 0, -1)),
    'd' => intval(substr($_GET['duration'], 0, -1))*24,
    'm' => intval(substr($_GET['duration'], 0, -1))*24*30,
    default => throw new Exception("durée $_GET[duration] erronée"),
  };
}

function queryForRecentAccess(string $durationInHours, ?string $param=null): string {
  switch ($param) {
    case 'agg': { // req agrégée sur les IP
      return "select ip, label labelip, referer, login, user, count(*) nbre
        from log left join ipaddress using(ip) 
        where
          (login is null or login <> 'benoit.david@free.fr')
          and (user is null or user <> 'benoit.david@free.fr')
          and access='T'
          and TIMESTAMPDIFF(HOUR,logdt,now()) < $durationInHours
        group by ip, labelip, referer, login, user
      ";
    }
    case null: { // requête non agrégée sans IP
      return "select logdt, ip, label labelip, referer, login, user, request_uri
        from log left join ipaddress using(ip) 
        where
          (login is null or login <> 'benoit.david@free.fr')
          and (user is null or user <> 'benoit.david@free.fr')
          and access='T'
          and TIMESTAMPDIFF(HOUR,logdt,now()) < $durationInHours
      ";
    }
    default: { // $param contient l'IP
      return "select *
        from log left join ipaddress using(ip) 
        where
          ip='$param'
          and TIMESTAMPDIFF(HOUR,logdt,now()) < $durationInHours
      ";
    }
  }
}

switch ($action = $_GET['action'] ?? null) {
  case null: {
    echo "$HTML_HEAD<h3>Access log</h3>\n";
    echo "<ul>\n";
    echo "<li>Nbre d'accès ok depuis";
    echo "<a href='?action=recentAccess&duration=8h'> 8 heures</a>,\n";
    echo "<a href='?action=recentAccess&duration=1h'> 1 heure</a>,\n";
    echo "<a href='?action=recentAccess&duration=1d'> 24 heures</a>,\n";
    echo "<a href='?action=recentAccess&duration=7d'> 1 semaine</a>,\n";
    echo "<a href='?action=recentAccess&duration=1m'> 1 mois</a></li>\n";
    echo "<li><a href='?action=createTableIpaddress'>Créer et peupler la table ipaddress</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'createTableIpaddress': {
    echo "$HTML_HEAD<h3>Access log</h3>\n";
    Mysql::query("drop table if exists ipaddress");
    MySql::query(SqlDef::sql('ipaddress', SqlDef::IPADDRESS_SCHEMA));
    foreach (SqlDef::IPADDRESS_CONTENT as $iptuple)
      MySql::query("insert into ipaddress values('$iptuple[0]','$iptuple[1]')");
    die();
  }
  case 'recentAccess': {
    echo "$HTML_HEAD<h3>recentAccess depuis $_GET[duration]</h3>\n";
    $durationInHours = durationInHours($_GET['duration']);
    echo "<a href='?action=mapOfLogs&amp;duration=$_GET[duration]'>Carte des accès</a><br>\n";
    $sql = queryForRecentAccess($durationInHours, 'agg');
    echo "<table border=1>\n";
    $sum = 0;
    $first = true;
    foreach (MySql::query($sql) as $tuple) {
      //print_r($tuple); echo "<br>\n";
      if ($first) {
        echo "<th>",implode('</th><th>', array_keys($tuple)),"</th>\n";
        $first = false;
      }
      $href = "?action=recentAccessIp&duration=$_GET[duration]&ip=$tuple[ip]";
      echo "<tr><td><a href='$href'>",implode('</td><td>', $tuple),"</a></td></tr>\n";
      $sum += $tuple['nbre'];
    }
    echo "<tr><td colspan=5></td><td>$sum</td></tr>\n";
    echo "</table>\n";
    die();
  }
  case 'recentAccessIp': {
    echo "$HTML_HEAD<h3>recentAccessIp depuis $_GET[duration] et depuis l'adresse IP $_GET[ip]</h3>\n";
    $durationInHours = durationInHours($_GET['duration']);
    $sql = queryForRecentAccess($durationInHours, $_GET['ip']);
    echo "<pre>sql=$sql</pre>\n";
    echo "<a href='?action=mapOfLogs&amp;duration=$_GET[duration]&ip=$_GET[ip]'>Carte des accès</a><br>\n";
    echo "<table border=1>\n";
    $first = true;
    foreach (MySql::query($sql) as $tuple) {
      //print_r($tuple); echo "<br>\n";
      if ($first) {
        echo "<th>",implode('</th><th>', array_keys($tuple)),"</th>\n";
        $first = false;
      }
      echo "<tr><td>",implode('</td><td>', $tuple),"</td></tr>\n";
    }
    echo "</table>\n";
    die();
  }
  case 'mapOfLogs': { // code HTML+JS de la carte Leaflet
    break;
  }
  case 'geojson': { // fournir le GeoJSON des logs
    header('Content-type: application/json; charset="utf-8"');
    echo '{"type":"FeatureCollection","features":[',"\n";
    $durationInHours = durationInHours($_GET['duration']);
    $sql = queryForRecentAccess($durationInHours, $_GET['ip'] ?? null);
    $first = true;
    foreach (MySql::query($sql) as $tuple) {
      //print_r($tuple); echo "<br>\n";
      //echo "<pre>",Yaml::dump($tuple),"</pre>\n";
      if (($geometry = GJGeom::wmsRequest2GJGeom($tuple['request_uri']))
       || ($geometry = GJGeom::tileRequest2GJGeom($tuple['request_uri']))) {
        $feature = [
          'type'=> 'Feature',
          'properties'=> [
            'logdt'=> $tuple['logdt'],
            'ip'=> $tuple['ip'],
            'labelip'=> $tuple['label'] ?? $tuple['labelip'],
            'login'=> $tuple['login'],
            'user'=> $tuple['user'],
          ],
          'geometry'=> $geometry,
        ];
        if (!$first)
          echo ",\n"; // séparateur entre 2 features
        $first = false;
        echo '  ',json_encode($feature);
      }
    }
    die("\n]}\n");
  }
  default: die("Action $action inconnue");
}

$geojsonParams = "&duration=$_GET[duration]".(isset($_GET['ip']) ? "&ip=$_GET[ip]" : '');

$request_scheme = (getenv('SHOMGT3_MAPWCAT_FORCE_HTTPS') == 'true') ? 'https'
  : ($_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http');
$dirname = dirname(dirname($_SERVER['SCRIPT_NAME']));
$shomgturl = "$request_scheme://$_SERVER[HTTP_HOST]".($dirname=='/' ? '/' : "$dirname/");
?>
<html>
  <head>
    <title>logs</title>
    <meta charset="UTF-8">
    <!-- meta nécessaire pour le mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9/dist/leaflet.css"/>
    <!-- styles nécessaires pour le mobile -->
    <link rel='stylesheet' href='../shomgt/leaflet/llmap.css'>
    <script src="https://unpkg.com/leaflet@1.9/dist/leaflet.js"></script>
    <!-- plug-in d'appel des GeoJSON en AJAX -->
    <script src='../shomgt/leaflet/leaflet-ajax.js'></script>
    <!-- chgt du curseur -->
    <style>
    .leaflet-grab {
       cursor: auto;
    }
    .leaflet-dragging .leaflet-grab{
       cursor: move;
    }
    #map {
      bottom: 0;
      left: 0;
      position: absolute;
      right: 0;
      top: 0;
    }
    </style> 
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>
  <body>
    <div id="map" style="height: 100%; width: 100%"></div>
    <script>
      var shomgturl = '<?php echo $shomgturl;?>';
      var geojsonParams = '<?php echo $geojsonParams;?>';
      var attrshom = "&copy; <a href='http://data.shom.fr' target='_blank'>Shom</a>";
      // initalize leaflet map
      var map = L.map('map').setView([46.5,3], 6);
      var baseLayers = {
        // PYR
        "Pyramide GéoTIFF" : new L.TileLayer(
          shomgturl+'shomgt/tile.php/gtpyr/{z}/{x}/{y}.png',
          { format:"png", minZoom:0, maxZoom:18, detectRetina:false, attribution:attrshom }
        ),
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
      map.addLayer(baseLayers["OSM"]);
      
      var overlays = {
        "Logs" : new L.GeoJSON.AJAX(shomgturl+'bo/accesslog.php?action=geojson'+geojsonParams, {
          style: { fillColor: 'LightBlue', color: 'DarkBlue', weight: 1, fillOpacity: 0.1}, minZoom: 0, maxZoom: 18
        }),
        "Délim. maritimes (Shom)" : new L.GeoJSON.AJAX(shomgturl+'shomgt/geojson/delmar.geojson', {
          style: { color: 'SteelBlue'}, minZoom: 0, maxZoom: 18
        }),
        "ZEE simplifiée" : new L.GeoJSON.AJAX(shomgturl+'shomgt/geojson/frzee.geojson', {
          style: { color: 'blue'}, minZoom: 0, maxZoom: 18
        })
      };
      map.addLayer(overlays["Logs"]);
      
      L.control.layers(baseLayers, overlays).addTo(map);
    </script>
  </body>
</html>
