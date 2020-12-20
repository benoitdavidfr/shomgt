<?php
/*PhpDoc:
name: mapcat.inc.php
title: cat2 / mapcat.inc.php - Gestion du catalogue des cartes du Shom v2
classes:
doc: |
  La classe MapCat est conforme au schéma http://geoapi.fr/shomgt/cat2/mapcat.schema

  Je gère 2 types de BBox:
    - celles définies précisément par des mesures en degrés et minutes décimales correspondant à l'ext. du cadre interne de la carte
    - celles imprécises qui peuvent correspondre soit au cadre interne soit à l'extension de la carte y compris le cadre externe
  La classe GjBox gère les Bbox imprécises et est super-classe de celle des données précises.

  Un bbox à cheval sur l'anti-méridien n'est pas géré de la même facon que dans la classe GBox
  Ici, il est géré comme spécifié par GeoJSON, cad avec $westlimit > $eastlimit
journal: |
  17/12/2020:
    - remplacement de BBoxDd par GjBox 
  15/12/2020:
    - changement d'architecture, passage en Php8
  14/12/2020:
    - correction bug sur BBoxDM à cheval sur l'anti-méridien
    - réalisation d'une carte de vérification du catalogue
  13/12/2020:
    - passage en V2
includes: [../lib/gegeom.inc.php, ../updt/updtapi.inc.php, gjbox.inc.php, france.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/../updt/updtapi.inc.php';
require_once __DIR__.'/gjbox.inc.php';
require_once __DIR__.'/france.inc.php';

use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

/*PhpDoc: classes
name: BBoxDM
title: class BBoxDM extends GjBox - Gestion des BBox des cartes et des cartouches en degrés et minutes décimales
doc: |
  Il s'agit des BBox définies précisément par des mesures en degrés et minutes décimales correspondant à l'extension du cadre
  interne de la carte
  Je garde la définition en minutes décimales et j'ajoute la possibilité d'initaliser les données en minutes et de les restituer.
  Attention, certains BBox sont à cheval sur l'anti-méridien, ie $westlimit > $eastlimit
*/
class BBoxDM extends GjBox {
  // Attention le tiret central peut être un tiret long interprété comme plusieurs caractères
  const PATTERN = "!^(\\d+)°((\\d+)(,(\\d+))?)?'(N|S) [^ ]+ (\\d+)°((\\d+)(,(\\d+))?)?'(E|W)$!";
  protected string $sw;
  protected string $ne;
  
  function __construct(array $bboxDM) {
    if (!preg_match(self::PATTERN, $bboxDM['SW'], $matches))
      throw new Exception("Erreur d'initialisation de BBoxDM sur SW = '$bboxDM[SW]'");
    //print_r($matches);
    $this->ws[1] = ($matches[6]=='S' ? -1 : +1) * ($matches[1] + "$matches[3].$matches[5]"/60);
    //echo "southlimit=$this->southlimit\n";
    $this->ws[0] = ($matches[12]=='W' ? -1 : +1) * ($matches[7] + "$matches[9].$matches[11]"/60);
    //echo "westlimit=$this->westlimit\n";
    $this->sw = $bboxDM['SW'];
    if (!preg_match(self::PATTERN, $bboxDM['NE'], $matches))
      throw new Exception("Erreur d'initialisation de BBoxDM sur NE = '$bboxDM[NE]'");
    //print_r($matches);
    $this->en[1] = ($matches[6]=='S' ? -1 : +1) * ($matches[1] + "$matches[3].$matches[5]"/60);
    //echo "northlimit=$this->northlimit\n";
    $this->en[0] = ($matches[12]=='W' ? -1 : +1) * ($matches[7] + "$matches[9].$matches[11]"/60);
    //echo "eastlimit=$this->eastlimit\n";
    $this->ne = $bboxDM['NE'];
  }
  
  function __toString(): string { return "{SW: $this->sw, NE: $this->ne}"; }
  
  function asArray(): array {
    return [
      'SW'=> $this->sw,
      'NE'=> $this->ne,
    ];
  }
};
if (0) { // tests unitaires 
  function dump(string $str) {
    echo "$str\n";
    for ($i=0; $i<strlen($str); $i++) {
      $char = substr($str, $i, 1);
      echo "  $char -> ",ord($char),"\n";
    }
  }
  if (0) {
    echo "<pre>";
    $bbox = ['SW'=>"27°40,48'S — 144°27,04'W"];
    echo "<table border=1><tr><td valign='top'><pre>",dump($bbox['SW']),"</pre></td>",
      "<td valign='top'><pre>",dump(BBoxDM::PATTERN),"</pre></td></tr></table>\n";
    $bboxDM = new BBoxDM($bbox);
  }
  elseif (1) {
    echo "<pre>";
    $bbox = ['SW'=>"NaN°00,00'S — 158°07,97'E"];
    $bboxDM = new BBoxDM($bbox);
  }
  
  die("Fin ligne ".__LINE__."\n");
}

// Gestion d'un cartouche
class MapPart {
  protected string $title; // titre du cartouche
  protected string $scaleDenominator; // dénominateur de l'échelle du cartouche avec un . comme séparateur des milliers,
  protected BBoxDM $bbox; // boite englobante du cartouche comme BBoxDM
  
  function __construct(array $mapPart) {
    $this->title = $mapPart['title'];
    $this->scaleDenominator = $mapPart['scaleDenominator'] ?? null;
    $this->bbox = new BBoxDM($mapPart['bboxDM']);
  }
  
  function scaleDenominator(): string { return $this->scaleDenominator; }
  
  function asArray(): array {
    return
      ['title'=> $this->title]
      + ['scaleDenominator'=> $this->scaleDenominator]
      + ['bboxDM'=> $this->bbox->asArray()]
      + ['spatial'=> $this->bbox->asDcmiBox()]
      ;
  }
  
  function multiPolygonCoords(): array {
    $gboxes = $this->bbox->asGBoxes();
    if (count($gboxes) == 1) {
      return [ $this->bbox->asGBoxes()[0]->polygon() ];
    }
    else {
      return [
        $this->bbox->asGBoxes()[0]->polygon(),
        $this->bbox->asGBoxes()[1]->polygon(),
      ];
    }
  }

  // EBox en WebMercator du bbox
  function wembox(): EBox {
    $gboxes = $this->bbox->asGBoxes();
    $gbox = $gboxes[0];
    return $gbox->proj('WebMercator');
  }
};

/*PhpDoc: classes
name: MapCat
title: classe MapCat - Gestion du catalogue des cartes du Shom
methods:
doc: |
  Chaque objet de la classe MapCat décrit une carte du Shom.
  La propriété statique $maps est un dictionnaire des cartes sur leur id.

  Les fichiers mapcat.yaml et mapcat.pser contiennent le catalogue des cartes y c. la date et l'heure d'actualisation.
  Le fichier mapcat.pser accélère la lecture.
  
  Après un traitement modifiant le contenu du catalogue, il est nécessaire de réécrire les fichier yaml ainsi que le fichier pser.
  La propriété statique $catUpdated trace les mises à jour, elle vaut toujours false après le chargement des données ;
  si elle vaut true cela signfie que les données doivent être enregistrées.

  Il existe des cartes sans espace principal (exemple 7436 - Approches et Port de Bastia - Ports d'Ajaccio et de Propriano)
  uniquement constituées de cartouches.
*/
class MapCat {
  const PATH = __DIR__.'/mapcat.'; // chemin des fichiers stockant le catalogue en pser ou en yaml, lui ajouter l'extension
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml

  static bool $verbose = false; // flag controlant certains affichages de debuggage, propriété enregistrée ni en pser ni en Yaml
  static bool $catUpdated = false; // true ssi le catalogue a été modifié, propriété enregistrée ni en pser ni en Yaml

  static ?string $catTitle = null; // titre du catalogue
  static array $catDescription = []; // description du catalogue, liste de string
  static ?string $catCreated = null; // date et heure de création du catalogue au format ISO 8601
  static ?string $catModified = null; // date et heure d'actualisation du catalogue au format ISO 8601
  static protected array $maps = []; // dictionnaire des MapCat [FR{num} => MapCat]
  
  protected string $num; // no de la carte
  protected ?string $obsolete; // si non null signifie que la carte est obsolète
  protected ?string $groupTitle; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title; // titre de la carte
  protected ?string $edition; // edition de la carte
  protected array $mapsFrance; // identifie les cartes dites d'intérêt et les zones couvertes
  protected ?string $modified; // date de la dernière correction apportée à la carte ou null s'il n'y en n'a pas eu ou si non connue
  protected ?int $lastUpdate; // no de la dernière correction apportée à la carte, 0 s'il n'y en n'a pas eu, ou null si inconnu
  protected ?string $scaleDenominator; // dénominateur de l'échelle de l'espace principal avec un . comme séparateur des milliers,
                                // null ssi la carte ne comporte pas d'espace principal
  protected ?GjBox $bbox; // bbox de l'espace principal de la carte comme BBoxDM|GjBox, null ssi pas d'espace principal
  protected ?string $replaces; // indication éventuelle de la carte remplacée
  protected ?string $references; // ssi la carte est un fac-similé alors référence de la carte généralement étrangère reproduite
  protected ?string $noteShom; // commentaire associé par le Shom à la carte
  protected ?string $noteCatalog; // commentaire associé à la carte dans la gestion du catalogue
  protected array $hasPart=[]; // liste des éventuels cartouches, chacun comme MapPart
  
  function mapsFrance(): ?bool { return $this->mapsFrance; }
  // s'il n'y a pas d'espace principal, je prends arbitrairement l'échelle du premier cartouche
  function scaleDenominator(): string { return $this->scaleDenominator ?? $this->hasPart[0]->scaleDenominator(); }
  // Génération du dénom. d'échelle comme entier
  function scaleDenAsInt(): int { return (int)str_replace('.', '', $this->scaleDenominator()); }
  
  function __construct(string $mapid, array $map) { // $map peut être une structure V2 ou V1
    $this->num = substr($mapid, 2);
    $this->obsolete = null;
    $this->groupTitle = $map['groupTitle'] ?? null;
    $this->title = $map['title'];
    $this->edition = $map['edition'] ?? $map['issued'] ?? null;
    $this->modified = $map['modified'] ?? null;
    $this->lastUpdate = isset($map['lastUpdate']) ? intval($map['lastUpdate']) : null;
    $this->scaleDenominator = $map['scaleDenominator'] ?? null;
    $this->bbox = isset($map['bboxDM']) ?
        new BBoxDM($map['bboxDM']) :
        (isset($map['bboxLonLatFromWfs']) ? new GjBox($map['bboxLonLatFromWfs']) : null);
    $this->replaces = $map['replaces'] ?? null;
    $this->references = $map['references'] ?? null;
    $this->noteShom = $map['noteShom'] ?? $map['note'] ?? null;
    $this->noteCatalog = $map['noteCatalog'] ?? null;
    foreach ($map['hasPart'] ?? $map['boxes'] ?? [] as $mapPart) {
      $this->hasPart[] = new MapPart($mapPart);
    }
   
    if (isset($map['mapsFrance']))
      $this->mapsFrenchAreas = $map['mapsFrance'];
    else { // si non défini alors il est calculé et le catalogue est marqué pour mise à jour
      $this->mapsFrance = France::interet($mapid, $this->scaleDenominator(), $this->geometry());
      self::$catUpdated = true;
    }
  }

  // retourne la géométrie de l'espace principal comme Polygone s'il existe, sinon des cartouches comme Multi-Polygone
  function geometry(): Geometry {
    if ($this->bbox) {
      return $this->bbox->asGeometry();
    }
    else {
      $multiPolygonCoords = [];
      foreach ($this->hasPart as $part)
        $multiPolygonCoords = array_merge($multiPolygonCoords, $part->multiPolygonCoords());
      return Geometry::fromGeoJSON(['type'=> 'MultiPolygon', 'coordinates'=> $multiPolygonCoords]);
    }
  }
  
  // EBox en WebMercator du bbox, génère une erreur s'il n'y a pas d'espace principal
  function wembox(): EBox {
    if ($this->bbox) {
      $gboxes = $this->bbox->asGBoxes();
      $gbox = $gboxes[0];
      return $gbox->proj('WebMercator');
    }
  }
  
  static function importFromV1() {
    echo "import du catalogue V1\n";
    $catv1 = json_decode(file_get_contents(__DIR__.'/../cat/mapcat.json'), true);
    foreach ($catv1['maps'] as $mapid => $map) {
      //echo Yaml::dump([$mapid => $map]);
      $map = new self($mapid, $map);
      if ($map->mapsFrance)
        self::$maps[$mapid] = $map;
      //print_r(self::$all[$mapid]);
    }
    ksort(self::$maps);
    self::$catTitle = $catv1['title'];
    $created = date(DATE_ATOM);
    self::$catDescription = ["Import du fichier V1 le $created"];
    self::$catCreated = $created;
    self::$catModified = $created;

    MapCat::addModified();
    MapCat::storeAsYaml();
    MapCat::storeAsPser();
    echo "enregistrement du catalogue en pser et en Yaml\n";
  }
  
  // ajout du champ modified aux cartes d'intérêt après importation V1
  // par lecture des MD ISO d'un des GéoTiff correspondant à chaque carte
  static function addModified() {
    foreach (self::$maps as $mapid => $map) {
      if (!$map->mapsFrance)
        continue;
      if (in_array($mapid, ['FR7330','FR7344','FR7360','FR8101','FR8502'])) // pas de MDISO pour ces cartes
        continue;
      $num = $map->num;
      if ($map->bbox) {
        $gtname = "$num/CARTO_GEOTIFF_${num}_pal300";
        $mdfilename = realpath(__DIR__."/../../../shomgeotiff/current/$gtname.xml");
        if (!is_file($mdfilename)) {
          echo "Erreur fichier $mdfilename absent pour $num\n";
          echo Yaml::dump([$mapid => MapCat::$maps[$mapid]->asArray()], 5, 2);
          //die();
        }
      }
      else {
        //echo "carte $mapid\n";
        $gtname = "$num/CARTO_GEOTIFF_${num}_1_gtw";
        $mdfilename = realpath(__DIR__."/../../../shomgeotiff/current/$gtname.xml");
        if (!is_file($mdfilename)) {
          echo "Erreur fichier $mdfilename absent pour $num\n";
          echo Yaml::dump([$mapid => MapCat::$maps[$mapid]], 5, 2);
          //die();
        }
      }
      $gtname = str_replace('CARTO_GEOTIFF_', '', $gtname);
      if (!($mdiso19139 = UpdtApi::mdiso19139($gtname))) {
        echo Yaml::dump([$mapid => $mdiso19139], 5, 2);
      }
      else {
        $map->modified = $mdiso19139['mdDate'];
        $map->lastUpdate = intval($mdiso19139['dernièreCorrection']);
      }
    }
  }
  
  static function storeAsPser() { // enregistre le catalogue comme pser 
    file_put_contents(self::PATH_PSER, serialize([
      'title'=> self::$catTitle,
      'description'=> self::$catDescription,
      'created'=> self::$catCreated,
      'modified'=> self::$catModified,
      'maps'=> self::$maps,
    ]));
  }
  
  static function maps(?string $mapid=null): array {
    if (!self::$maps)
      self::init();
    if (!$mapid)
      return self::$maps;
    elseif (isset(self::$maps[$mapid]))
      return self::$maps[$mapid]->asArray();
    else
      return [];
  }
  
  static function allAsArray(): array { // génère le catalogue comme array Php
    return [
      'title'=> self::$catTitle,
      'description'=> self::$catDescription,
      '$id'=> 'http://geoapi.fr/shomgt/cat2/mapcat',
      '$schema'=> __DIR__.'/mapcat',
      'created'=> self::$catCreated,
      'modified'=> self::$catModified,
      'maps'=> array_map(function(MapCat $map) { return $map->asArray(); }, self::$maps),
    ];
  }
  
  static function storeAsYaml() { // enregistre le catalogue en Yaml
    file_put_contents(self::PATH_YAML, Yaml::dump(self::allAsArray(), 5, 2));
  }
  
  private static function init() { // initialise en mémoire le catalogue, génère une erreur si le yaml est plus récent que le pser !
    if (!file_exists(self::PATH_PSER) && !file_exists(self::PATH_YAML))
      throw new Exception("Erreur dans MapCat::init() : les fichiers mapcat.yaml et mapcat.pser n'existent ni l'un ni l'autre");
    elseif (!file_exists(self::PATH_PSER)
     || (file_exists(self::PATH_YAML) && (filemtime(self::PATH_PSER) < filemtime(self::PATH_YAML)))) {
      echo "<b>Erreur: le fichier mapcat.yaml est plus récent que le pser ! ".
         "Soit effaces le yaml, soit charges le pour écraser le pser !</b>\n";
      die("Erreur dans MapCat::init()");
    }
    else { // le phpser existe et est plus récent que le Yaml alors initialisation à partir du phpser
      $pser = unserialize(file_get_contents(self::PATH_PSER));
      self::$catTitle = $pser['title'];
      self::$catDescription = $pser['description'];
      self::$catCreated = $pser['created'];
      self::$catModified = $pser['modified'];
      self::$maps = $pser['maps'];
    }
  }
  
  static function loadYaml() { // chargement du Yaml pour écraser le pser, génère une erreur si le pser est plus récent que le Yaml
    if (!file_exists(self::PATH_YAML))
      throw new Exception("Erreur dans MapCat::loadYaml() : le fichier mapcat.yaml n'existe pas");
    if (file_exists(self::PATH_PSER) && (filemtime(self::PATH_PSER) > filemtime(self::PATH_PSER)))
      throw new Exception("Erreur dans MapCat::loadYaml() : le fichier mapcat.pser est plus récent que le fichier Yaml ! "
        ."Soit effacer le pser pour charger le Yaml soit effacer le Yaml !");
    $yaml = Yaml::parseFile(self::PATH_YAML);
    //echo "<pre>"; print_r($yaml);
    self::$catTitle = $yaml['title'];
    self::$catDescription = $yaml['description'];
    self::$catCreated = $yaml['created'];
    self::$catModified = $yaml['modified'];
    self::$maps = [];
    foreach ($yaml['maps'] as $mapid => $map) {
      self::$maps[$mapid] = new self($mapid, $map);
    }
    self::storeAsPser();
  }
  
  static function close() { // s'il y a eu des modifications, réenregistre le document en yaml puis en pser
    if (self::$catUpdated) {
      ksort(self::$maps);
      self::$catModified = date(DATE_ATOM);
      MapCat::storeAsYaml();
      MapCat::storeAsPser();
    }
  }
  
  function asArray(): array { // génère la carte comme array
    return
        ($this->obsolete ? ['obsolete'=> $this->obsolete] : [])
      + ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
      + ($this->title ? ['title'=> $this->title] : [])
      + ($this->edition ? ['edition'=> $this->edition] : [])
      + ['mapsFrance'=> $this->mapsFrance]
      + ($this->modified ? ['modified'=> $this->modified] : [])
      + ($this->lastUpdate !== null ? ['lastUpdate'=> $this->lastUpdate] : [])
      + ($this->scaleDenominator ? ['scaleDenominator'=> $this->scaleDenominator] : [])
      + ($this->bbox && (get_class($this->bbox)=='BBoxDM') ?
          [ 'bboxDM'=> $this->bbox->asArray(), 'spatial'=> $this->bbox->asDcmiBox() ] : [])
      + ($this->bbox && (get_class($this->bbox)=='GjBox') ? [ 'bboxLonLatFromWfs'=> $this->bbox->asArray() ] : [])
      + ($this->replaces ? ['replaces'=> $this->replaces] : [])
      + ($this->references ? ['references'=> $this->references] : [])
      + ($this->noteShom ? ['noteShom'=> $this->noteShom] : [])
      + ($this->noteCatalog ? ['noteCatalog'=> $this->noteCatalog] : [])
      + ($this->hasPart ? ['hasPart'=> array_map(function(MapPart $mapPart) { return $mapPart->asArray(); }, $this->hasPart)] : [])
      ;
  }

  function geojson(): array { // génère une carte comme Feature GeoJSON
    return [
      'type'=> 'Feature',
      'id'=> 'FR'.$this->num,
      'properties'=> $this->asArray(),
      'geometry'=> $this->geometry()->asArray(),
    ];
  }
  
  function obsolete(): ?string { return $this->obsolete; } // consulte la propriété
  
  function setObsolete(string $comment) { // modifie la propriété
    $this->obsolete = $comment;
    self::$catUpdated = true;
  }
  
  // fabrique une tuile de la couche des étiquettes pour la carte Leaflet
  static function maketile(int $sdmin, ?int $sdmax, EBox $wembox, array $options=[]) {
    if (self::$verbose)
      echo "MapCat::maketile(lyrname=$sdmin-$sdmax, wembox=$wembox, options=",json_encode($options),")<br>\n";
    $width = $options['width'] ?? 256;
    $height = $options['height'] ?? 256;
    // fabrication de l'image
    if (!($image = imagecreatetruecolor($width, $height)))
      throw new Exception("erreur de imagecreatetruecolor() ligne ".__LINE__);
    // remplissage en transparent
    if (!imagealphablending($image, false))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    $transparent = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 0x7F);
    if (!imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent))
      throw new Exception("erreur de imagefilledrectangle() ligne ".__LINE__);
    if (!imagealphablending($image, true))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    
    self::init();
    foreach (self::$maps as $map) {
      $sd = $map->scaleDenAsInt();
      if (($sd > $sdmin) && (!$sdmax || ($sd <= $sdmax))) {
        $map->drawLabel($image, $wembox, $width, $height);
      }
    }
    
    if (!imagesavealpha($image, true))
      throw new Exception("erreur de imagesavealpha() ligne ".__LINE__);
    return $image;
  }
  
  /*PhpDoc: methods
  name: drawLabel
  title: "function drawLabel($image, EBox $bbox, int $width, int $height): bool - dessine dans l'image GD le numéro de la carte"
  doc: |
    $bbox est un EBox en WebMercator
  */
  function drawLabel($image, EBox $tileBBox, int $width, int $height): bool {
    //echo "title= ",$this->title,"<br>\n";
    $wemboxes = [];
    if ($this->bbox) {
      $wembox = $this->wembox();
      if ($wembox->intersects($tileBBox))
        $wemboxes[$this->num] = $wembox;
    }
    else {
      foreach ($this->hasPart as $nopart => $part) {
        $wembox = $part->wembox();
        if ($wembox->intersects($tileBBox)) {
          $wemboxes[$this->num."/$nopart"] = $wembox;
        }
      }
    }
    if (!$wemboxes)
      return false;
    foreach ($wemboxes as $label => $wembox) {
      $x = round(($wembox->west() - $tileBBox->west()) / $tileBBox->dx() * $width);
      $y = round(- ($wembox->north() - $tileBBox->north()) / $tileBBox->dy() * $height);
      //echo "x=$x, y=$y<br>\n"; die();
      $font = 3;
      $bg_color = imagecolorallocate($image, 255, 255, 0);
      $dx = strlen($label) * imagefontwidth($font);
      $dy = imagefontheight($font);
      imagefilledrectangle($image, $x+2, $y, $x+$dx, $y+$dy, $bg_color);
      $text_color = imagecolorallocate($image, 255, 0, 0);
      // bool imagestring ( resource $image , int $font , int $x , int $y , string $string , int $color )
      imagestring($image, $font, $x+2, $y, $label, $text_color);
    }
    //die();
    return true;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire de la classe MapCat


if (php_sapi_name() <> 'cli') {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>mapcat</title></head><body><pre>\n";
  if (!isset($_GET['action'])) {
    echo "</pre>mapcat.inc.php - Actions proposées:<ul>\n";
    echo "<li><a href='?action=importFromV1'>Importe le catalogue V1 et l'enregistre en pser et en Yaml</a>\n";
    echo "<li><a href='?action=yaml'>Affiche le catalogue en Yaml</a>\n";
    echo "<li><a href='?action=FR7249'>Affiche FR7249</a>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}

if ($action == 'importFromV1') {
  MapCat::importFromV1();
  die();
}

if ($action == 'yaml') {
  MapCat::init();
  echo Yaml::dump(MapCat::allAsArray(), 5, 2);
  die();
}

if ($action == 'FR7249') {
  MapCat::init();
  $map = MapCat::$maps['FR7249'];
  echo Yaml::dump($map->asArray(), 5, 2);
  echo Yaml::dump(['geometry'=> $map->geometry()->asArray()], 5, 2);
  die();
}

die("Action $action non prévue\n");
