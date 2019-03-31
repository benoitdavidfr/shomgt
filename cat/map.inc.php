<?php
/*PhpDoc:
name: map.inc.php
title: map.inc.php - sous-classe de documents pour l'affichage d'une carte Leaflet
functions:
doc: <a href='/yamldoc/?action=version&name=map.inc.php'>doc intégrée en Php</a>
*/
{ //doc 
$phpDocs['map.inc.php']['file'] = <<<'EOT'
name: map.inc.php
title: map.inc.php - sous-classe de documents pour l'affichage d'une carte Leaflet
doc: |  
journal:
  15/2/2019:
    - ajout du code pour le plug-in https://visu.gexplor.fr/lib/control.coordinates.js
  22/1/2019:
    - passage des UGeoJSONLayer en https
  20/8/2018:
    - ajout de symboles, test sur les pai_religieux de la BDTopo
  19/8/2018:
    - modif des spécifications des couches affichées par défaut de addLayer en defaultLayers
    - ajout du view en paramètre optionnel de display
  17/8/2018:
    - modif de l'initialisation pour que les paramètres originaux ne s'affichent pas à la fin
    - les endpoint des couches UGeoJSONLayer peuvent soit être des URI de couche
      soit le chemin {docid}/{lyrname} dans le store courant
  6/8/2018:
    - affichage des propriétés d'un objet GeoJSON
    - stylage des objets GeoJSON par couche et en fonction d'un attribut
  5/8/2018:
    - création
EOT;
}

use Symfony\Component\Yaml\Yaml;

{ // doc
$phpDocs['map.inc.php']['classes']['Map'] = <<<'EOT'
title: affichage d'une carte Leaflet
doc: |
  La carte peut être affichée par appel de son URI suivie de /display  
  Chaque couche définie dans la carte génère un objet d'une sous-classe de LeafletLayer en fonction de son type.  
  Le fichier map-default.yaml est utilisé pour définir une carte par défaut.  
  Cette carte par défaut contient 3 couches de base et 0 calques (overlays).
  
  Voir la carte geodata/testmap.yaml comme exemple et spécification.
  
  La carte peut aussi être généré dynamiquement par un autre document, par un FeatureDataset.
  Voir comme exemple id.php/geodata/route500/map
EOT;
}

class YamlDoc {};
  
class Map extends YamlDoc {
  protected $_c; // contient les champs
  
  // crée un nouveau doc, $yaml est le contenu Yaml externe issu de l'analyseur Yaml
  // $yaml est généralement un array mais peut aussi être du texte
  function __construct($yaml, string $docid) {
    $this->_id = $docid;
    $this->_c = $yaml;
    if (!is_file(__DIR__."/map-default.yaml"))
      throw new Exception("Erreur dans Map::__construct() le fichier map-default.yaml est absent");
    $defaultParams = Yaml::parse(file_get_contents(__DIR__."/map-default.yaml"), Yaml::PARSE_DATETIME);
    foreach ($defaultParams as $prop => $value) {
      if (!isset($this->_c[$prop]))
        $this->_c[$prop] = $value;
    }
    if ($this->bases) {
      foreach ($this->bases as $id => $layer) {
        $class = "Leaflet$layer[type]";
        if (!class_exists($class))
          throw new Exception("Erreur dans Map::__construct() le type de couche $layer[type] n'est pas autorisé");
        $this->_c['bases'][$id] = new $class($layer, $this->attributions);
      }
    }
    if ($this->overlays) {
      foreach ($this->_c['overlays'] as $id => $layer) {
        $class = "Leaflet$layer[type]";
        if (!class_exists($class))
          throw new Exception("Erreur dans Map::__construct() le type de couche $layer[type] n'est pas autorisé");
        $this->_c['overlays'][$id] = new $class($layer, $this->attributions);
      }
    }
  }
  
  // lit les champs
  function __get(string $name) { return isset($this->_c[$name]) ? $this->_c[$name] : null; }

  // affiche le sous-élément de l'élément défini par $ypath
  function show(string $ypath=''): void {
    $docid = $this->_id;
    echo "Map::show($docid, $ypath)<br>\n";
    if (!$ypath || ($ypath=='/'))
      showDoc($docid, $this->_c);
    else
      showDoc($docid, $this->extract($ypath));
    //echo "<pre>"; print_r($this->_c); echo "</pre>\n";
  }
  
  // décapsule l'objet et retourne son contenu sous la forme d'un array
  // ce décapsulage ne s'effectue qu'à un seul niveau
  // Permet de maitriser l'ordre des champs
  function asArray() {
    $ret = $this->_c;
    foreach ($ret['bases'] as $lyrid => $layer) {
      $ret['bases'][$lyrid] = $layer->asArray();
    }
    foreach ($ret['overlays'] as $lyrid => $layer) {
      $ret['overlays'][$lyrid] = $layer->asArray();
    }
    return $ret;
  }

  // extrait le fragment du document défini par $ypath
  // Renvoie un array ou un objet qui sera ensuite transformé par YamlDoc::replaceYDEltByArray()
  // Utilisé par YamlDoc::yaml() et YamlDoc::json()
  // Evite de construire une structure intermédiaire volumineuse avec asArray()
  function extract(string $ypath) {
    return YamlDoc::sextract($this->_c, $ypath);
  }
  
  static function api(): array {
    return [
      'class'=> get_class(), 
      'title'=> "description de l'API de la classe ".get_class(),
      'abstract'=> "documents pour l'affichage d'une carte Leaflet",
      'api'=> [
        '/'=> "retourne le contenu du document ".get_class(),
        '/api'=> "retourne les points d'accès de ".get_class(),
        '/display?latlon={latlon}&zoom={zoom}'=> "génère le code HTML d'affichage de la carte utilisant Leaflet",
      ]
    ];
  }

  // extrait le fragment défini par $ypath, utilisé pour générer un retour à partir d'un URI
  function extractByUri(string $ypath) {
    $docuri = $this->_id;
    if (!$ypath || ($ypath=='/')) {
      return array_merge(['_id'=> $this->_id], $this->_c);
    }
    elseif ($ypath == '/api') {
      return self::api();
    }
    if ($ypath=='/display') {
      $latlon = isset($_GET['latlon']) ? explode(',',$_GET['latlon']) : [];
      $zoom = isset($_GET['zoom']) ? $_GET['zoom']+0 : -1;
      $this->display($docuri, $latlon, $zoom);
    }
    else {
      $fragment = $this->extract($ypath);
      $fragment = self::replaceYDEltByArray($fragment);
      return $fragment;
    }
  }
  
  // affiche la carte
  function display(string $docid, array $latlon=[], int $zoom=-1): void {
    //echo "Map::display($docid)<br>\n";
    //echo "<pre>_SERVER="; print_r($_SERVER); die();
    echo "<!DOCTYPE HTML><html><head>";
    echo "<title>",$this->title,"</title><meta charset='UTF-8'>\n";
    echo "<!-- meta nécessaire pour le mobile -->\n",
         '  <meta name="viewport" content="width=device-width, initial-scale=1.0,',
           ' maximum-scale=1.0, user-scalable=no" />',"\n";
    foreach ($this->stylesheets as $stylesheet)
      echo "  <link rel='stylesheet' href='$stylesheet'>\n";
    echo "  <script src='https://unpkg.com/leaflet@1.3/dist/leaflet.js'></script>\n";
    foreach ($this->plugins as $plugin)
      echo "  <script src='$plugin'></script>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "  <div id='map' style='height: ",$this->mapStyle['height'],
         "; width: ",$this->mapStyle['width'],"'></div>\n";
    echo "  <script>\n";
    if (!$latlon)
      $latlon = $this->view['latlon'];
    if ($zoom == -1)
      $zoom = $this->view['zoom'];
    echo "var map = L.map('map').setView([",implode(',',$latlon),"], ",$zoom,"); // view pour la zone\n";
    if ($this->locate)
      echo "map.locate({setView: ",$this->locate['setView']?'true':'false',
           ", maxZoom: ",$this->locate['maxZoom'],"});\n";
    echo "L.control.scale({position:'",$this->scaleControl['position'],"', ",
         "metric:",$this->scaleControl['metric']?'true':'false',", ",
         "imperial:",$this->scaleControl['imperial']?'true':'false',"}).addTo(map);\n";
         
    echo "var bases = {\n";
    if ($this->bases) {
      foreach ($this->bases as $lyrid => $layer) {
        $layer->showAsCode("$docid/$lyrid");
      }
    }
    echo "};\n";
         
    echo "var overlays = {\n";
    if ($this->overlays) {
      foreach ($this->overlays as $lyrid => $layer) {
        $layer->showAsCode("$docid/$lyrid");
      }
    }
    echo "};\n";
    foreach ($this->defaultLayers as $lyrid)
      if (isset($this->bases[$lyrid]))
        echo "map.addLayer(bases[\"",$this->bases[$lyrid]->title,"\"]);\n";
      elseif (isset($this->overlays[$lyrid]))
        echo "map.addLayer(overlays[\"",$this->overlays[$lyrid]->title,"\"]);\n";
    // ajout de l'outil de sélection de couche
    echo "L.control.layers(bases, overlays).addTo(map);\n";
    if (in_array('https://visu.gexplor.fr/lib/control.coordinates.js', $this->plugins)) {
      echo "var c = new L.Control.Coordinates();\n";
      echo "c.addTo(map);\n";
      echo "map.on('click', function(e) { c.setCoordinates(e); });\n";
    }
    echo "  </script>\n</body></html>\n";
    die();
  }
};

interface YamlDocElement {};
  
// création d'une classe par type de couche pour modulariser l'affichage du code
abstract class LeafletLayer implements YamlDocElement {
  protected $_c; // contient les champs
  
  function __construct(&$yaml, $attributions) {
    $this->_c = [];
    foreach ($yaml as $prop => $value) {
      $this->_c[$prop] = $value;
    }
    if (isset($this->options['attribution'])) {
      $attr = $this->options['attribution'];
      if (isset($attributions[$attr]))
        $this->_c['options']['attribution'] = $attributions[$attr];
    }
  }
  
  // lit les champs
  function __get(string $name) { return isset($this->_c[$name]) ? $this->_c[$name] : null; }
  
  function extract(string $ypath) { return YamlDoc::sextract($this->_c, $ypath); }
  
  function asArray() { return $this->_c; }
  
  function show(string $docid, string $prefix='') { showDoc($docid, $this->_c); }
};

// classe pour couche L.TileLayer
class LeafletTileLayer extends LeafletLayer {
  function showAsCode(string $name): void {
    echo "  \"$this->title\" : new L.TileLayer(\n";
    echo "    '$this->url',\n";
    echo '    ',json_encode($this->options, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    echo "  ),\n";
  }
};

// classe pour couche L.TileLayer.WMS
class LeafletTileLayerWMS extends LeafletLayer {
  function showAsCode(string $name): void {
    echo "  \"$this->title\" : new L.TileLayer.WMS(\n";
    echo "    '$this->url',\n";
    echo '    ',json_encode($this->options, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    echo "  ),\n";
  }
};

// classe pour couche L.UGeoJSONLayer
class LeafletUGeoJSONLayer extends LeafletLayer {
  function showAsCode(string $lyrid): void {
    //print_r($this);
    $request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
      : ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS']=='on')) ? 'https' : 'http');
    if ((strncmp($this->endpoint, 'http://', 7)<>0) && (strncmp($this->endpoint, 'https://', 8)<>0))
      $this->_c['endpoint'] = "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/".$this->endpoint;
    echo "  \"$this->title\" : new L.UGeoJSONLayer({\n";
    echo "    lyrid: '$lyrid',\n";
    //echo "    title: '$this->title',\n";
    echo "    endpoint: '$this->endpoint',\n";
    // affichage des propriétés du feature
    //$popup = "'<pre>'+JSON.stringify(feature.properties,null,' ').replace(/[\{\}\"]/g,'')+'</pre>'";
    // affichage de la layer (debuggage)
    //$popup = "'<pre>'+JSON.stringify(layer,null,' ').replace(/[\{\}\"]/g,'')+'</pre>'";
    // test d'affichage du lyrid
    //$popup = "'<b>'+layer.options.lyrid+'</b>'";
    // affichage lyrid + propriétés
    $lyrurl = "$this->endpoint?zoom='+map.getZoom()+'";
    $popup = "'<b><a href=\"$lyrurl\">'+layer.options.lyrid+', zoom='+map.getZoom()+'</a></b><br>'"
      ."+'<pre>'+JSON.stringify(feature.properties,null,' ').replace(/[\{\}\"]/g,'')+'</pre>'";
    echo "    onEachFeature: function (feature, layer) {\n",
         //"      console.log(layer);\n",
         "      layer.bindPopup($popup);\n",
         "    },\n";
    if ($this->pointToLayer && is_string($this->pointToLayer))
      echo "    pointToLayer: ",$this->pointToLayer,",\n";
    if ($this->style && is_array($this->style))
      echo "    style: ",json_encode($this->style),",\n";
    elseif ($this->style && is_string($this->style))
      echo "    style: ",$this->style,",\n";
    if ($this->minZoom !== null)
      echo "    minZoom: ",$this->minZoom,",\n";
    if ($this->maxZoom !== null)
      echo "    maxZoom: ",$this->maxZoom,",\n";
    
    echo "    usebbox: true\n";
    echo "  }),\n";
  }
};
