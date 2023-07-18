<?php
/* bo/conform.php - Validation d'une carte 
 * Benoit DAVID - 11-13/7/2023
 * La validation des cartes est définie d'une part par sa conformité à sa spécification
 * et, d'autre part, par sa cohérence avec MapCat.
 *
 * Les cartes normales sont spécifiées par le Shom.
 * J'y ajoute la cohérence suivante avec MapCat:
 *  - chaque carte 7z correspond à une entrée dans les cartes non obsolètes de MapCat
 *  - ssi la zone principale pal300 est géoréférencée alors
 *    - la carte comporte les champs spatial et scaleDenominator et
 *    - son géoréférencement contient l'extension spatiale définie dans MapCat
 *  - il y a bijection entre les GéoTiffs de cartouche de l'archive et les cartouches de MapCat
 *  - le géoréférencement du GéoTiff contient l'extension spatiale correspondante dans MapCat
 *  - si un géoréférencement est absent ou incorrect alors il est remplacé par la définition du champ borders dans Mapcat
 *
 * Pour les cartes spéciales j'utilise la spécification suivante:
 *  - la carte est livrée comme une archive 7z nommée par le numéro de la carte et l'extension .7z
 *  - cette archive contient au moins un fichier contenant la carte, soit au format .tif, géoréférencé ou non,
 *    soit au format .pdf
 *  - si c'est un fichier .tif alors son nom sans son extension doit être défini dans MapCat dans le champ geotiffname
 *  - si c'est un fichier .pdf alors son nom doit être défini dans le même champ mais avec son extension .pdf
 *  - si le fichier n'est pas géoréféréncé alors l'enregistrement MapCat doit comporter un champ borders
 *  - l'enregistrement MapCat doit comporter un champ layer
 *   
 * Pour les 2 types de carte:
 *  - un .tif est considéré comme géoréférencé ssi son gdalinfo contient un champ coordinateSystem
 *  - le géoréférencement est incorrect si le wkt du coordinateSystem de son gdalinfo n'est pas conforme
 *    au motif défini dans COORDINATESYSTEM_WKT_PATTERN
 *
 * AMELIORER le test de conformité Map::conforms()
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../sgserver/SevenZipArchive.php';

use Symfony\Component\Yaml\Yaml;

define ('SHOMGEOTIFF', '/var/www/html/shomgeotiff/');

class MapCat { // chargement de MapCat 
  static array $cat;
  
  static function init() {
    self::$cat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    //echo "<pre>"; print_r(self::$cat); echo "</pre>\n";
  }
  
  static function get(string $mapNum): array {
    return self::$cat['maps']["FR$mapNum"] ?? [];
  }
};

class GBox { // GBox en LonLat 
  protected array $min=[]; // position SW en LonLat
  protected array $max=[]; // position NE en LonLat
  
  function bound(array $pos): void { // agrandit le bbox avec la position $pos en LonLat
    if (!$this->min) {
      $this->min = $pos;
      $this->max = $pos;
    }
    else {
      if ($pos[0] < $this->min[0]) $this->min[0] = $pos[0];
      if ($pos[1] < $this->min[1]) $this->min[1] = $pos[1];
      if ($pos[0] > $this->max[0]) $this->max[0] = $pos[0];
      if ($pos[1] > $this->max[1]) $this->max[1] = $pos[1];
    }
  }
  
  function union(GBox $gbox): GBox { // retourne l'union des 2 bbox
    if (!$gbox->min) {
      return $this;
    }
    $union = $this;
    $union->bound($gbox->min);
    $union->bound($gbox->max);
    return $union;
  }
  
  function latLngBounds(): array { // retourne un array de 2 positions en LatLng pour LeafLet
    return [[$this->min[1], $this->min[0]], [$this->max[1], $this->max[0]]];
  }
};

class Gdalinfo {
  const CRS_WKT_PATTERN =
    '!^PROJCRS{"WGS 84 / World Mercator",\s*'
      .'BASEGEOGCRS{"WGS 84",\s*'
        .'DATUM{"World Geodetic System 1984",\s*ELLIPSOID{"WGS 84",6378137,298.257223563,\s*LENGTHUNIT{"metre",1}}},\s*'
        .'PRIMEM{"Greenwich",0,\s*ANGLEUNIT{"degree",0.0174532925199433}},\s*'
        .'ID{"EPSG",4326}},\s*'
      .'CONVERSION{"Mercator \(variant A\)",\s*'
        .'METHOD{"Mercator \(variant A\)",\s*ID{"EPSG",9804}},\s*'
        .'PARAMETER{"Latitude of natural origin",0,\s*ANGLEUNIT{"degree",0.0174532925199433},\s*ID{"EPSG",8801}},\s*'
        .'PARAMETER{"Longitude of natural origin",0,\s*ANGLEUNIT{"degree",0.0174532925199433},\s*ID{"EPSG",8802}},\s*'
        .'PARAMETER{"Scale factor at natural origin",1,\s*SCALEUNIT{"unity",1},\s*ID{"EPSG",8805}},\s*'
        .'PARAMETER{"False easting",0,\s*LENGTHUNIT{"metre",1},\s*ID{"EPSG",8806}},\s*'
        .'PARAMETER{"False northing",0,\s*LENGTHUNIT{"metre",1],\s*ID{"EPSG",8807}}},\s*'
      .'CS{Cartesian,2},\s*'
      .'AXIS{"easting",east,\s*ORDER{1},\s*LENGTHUNIT{"metre",1}},\s*'
      .'AXIS{"northing",north,\s*ORDER{2},\s*LENGTHUNIT{"metre",1}},\s*'
      .'ID{"EPSG",3395}}\s*'
    .'$!';
  protected array $info; // contenu du gdalinfo
  
  function __construct(string $path) {
    $cmde = "gdalinfo -json $path";
    //echo "$cmde<br>\n";
    exec($cmde, $output, $retval);
    //echo "<pre>"; print_r($output); echo "retval=$retval</pre>\n";
    $this->info = json_decode(implode("\n", $output), true);
  }
  
  function asArray(): array { return $this->info; }
  
  // indique si le géoréférencement est absent, correct ou incorrect, retourne
  //  - null si non géoréférencé cad champ 'coordinateSystem' non défini
  //  - 'ok' si géoréférencé correctement cad champ 'coordinateSystem/wkt' correspond au motif
  //  - 'KO' si géoréférencé incorrectement cad champ 'coordinateSystem/wkt' ne correspond pas au motif
  function georef(): ?string {
    if (!isset($this->info['coordinateSystem']))
      return null;
    $pattern = str_replace(['{','}'], ['\[','\]'], self::CRS_WKT_PATTERN);
    $georef = preg_match($pattern, $this->info['coordinateSystem']['wkt']) ? 'ok' : 'KO';
    //echo "georef=$georef\n";
    return $georef;
  }
  
  function gbox(): ?GBox { // retourne le GBox ssi il est défini dans le gdalinfo
    if (!isset($this->info['wgs84Extent']))
      return null;
    $gbox = new GBox; 
    foreach($this->info['wgs84Extent']['coordinates'][0] as $pos) {
      $gbox->bound($pos);
    }
    return $gbox;
  }
};

class Map { // analyse des fichiers et restructuration
  protected string $incomingPath;
  protected string $mapNum;
  protected ?string $thumbnail=null;
  protected array $main=[]; // ['tif'=> {fileName}, 'xml'=>{filename}, 'georef'=>('ok'|'KO'|null) 'gbox'=>?GBox]
  protected array $insets=[]; // [{name}=> ['tif'=> {fileName}, 'xml'=>{filename}]]
  protected array $suppls=[]; // liste de noms de fichiers hors specs
  
  function __construct(string $incomingPath, string $mapNum) {
    $this->incomingPath = $incomingPath;
    $this->mapNum = $mapNum;
    if (!is_dir("$incomingPath/$mapNum")) {
      $cmde = "7z x -o$incomingPath $incomingPath/$mapNum.7z";
      //echo "$cmde<br>\n";
      exec($cmde, $output, $retval);
      //echo "<pre>"; print_r($output); echo "retval=$retval</pre>\n";
    }
    foreach (new DirectoryIterator("$incomingPath/$mapNum") as $fileName) {
      if (in_array($fileName, ['.','..','.DS_Store'])) continue;
      if ($fileName == "$mapNum.png")
        $this->thumbnail = $fileName;
      elseif ($fileName == $mapNum."_pal300.tif")
        $this->main['tif'] = (string)$fileName;
      elseif ($fileName == 'CARTO_GEOTIFF_'.$mapNum.'_pal300.xml')
        $this->main['xml'] = (string)$fileName;
      elseif (preg_match('!^'.$mapNum.'_(\d+_gtw)\.tif$!', $fileName, $matches))
        $this->insets[$matches[1]]['tif'] = (string)$fileName;
      elseif (preg_match('!^CARTO_GEOTIFF_'.$mapNum.'_(\d+_gtw)\.xml$!', $fileName, $matches))
        $this->insets[$matches[1]]['xml'] = (string)$fileName;
      elseif (!preg_match('!\.gt$!', $fileName))
        $this->suppls[] = (string)$fileName;
    }
    if (isset($this->main['tif'])) {
      $gdalinfo = new GdalInfo("$incomingPath/$mapNum/".$this->main['tif']);
      $georef = $gdalinfo->georef();
      $this->main['georef'] =  $georef;
      $this->main['gbox'] = $georef ? $gdalinfo->gbox() : null;
    }
  }
  
  function clean() { // supprime le répertoire correspondant au dézippage de la carte
    foreach (new DirectoryIterator("$this->incomingPath/$this->mapNum") as $filename) {
      if (in_array($filename, ['.','..'])) continue;
      if (substr($filename, -3) == '.7z') { // je n'efface pas les archives 7z 
        die("Erreur de Map::clean sur $this->incomingPath/$this->mapNum/$filename\n");
      }
      else {
        //echo "unlink $filename\n";
        unlink("$this->incomingPath/$this->mapNum/$filename");
      }
    }
    rmdir("$this->incomingPath/$this->mapNum");
  }
  
  function gtiffs(): array { // retourne la liste des GéoTiffs géoréférencés
    $gtiffs = [];
    if ($this->main && $this->main['gbox'])
      $gtiffs[] = $this->main['tif'];
    foreach ($this->insets as $inset)
      $gtiffs[] = $inset['tif'];
    return $gtiffs;
  }
  
  function gbox(): ?GBox { // retourne null ssi aucun tif géoréférencé
    if (!$this->main)
      return null;
    if ($this->main['gbox'])
      return $this->main['gbox'];
    if ($this->insets) {
      $gbox = new GBox;
      foreach ($this->insets as $inset) {
        $gdalinfo = new GdalInfo("$_GET[path]/$_GET[map]/$inset[tif]");
        $gbox->union($gdalinfo->gbox());
      }
      return $gbox;
    }
    return null;
  }
  
/* Pour les cartes spéciales j'utilise la spécification suivante:
  *  - la carte est livrée comme une archive 7z nommée par le numéro de la carte et l'extension .7z
  *  - cette archive contient au moins un fichier contenant la carte, soit au format .tif, géoréférencé ou non,
  *    soit au format .pdf
  *  - si c'est un fichier .tif alors son nom sans son extension doit être défini dans MapCat dans le champ geotiffname
  *  - si c'est un fichier .pdf alors son nom doit être défini dans le même champ mais avec son extension .pdf
  *  - si le fichier n'est pas géoréféréncé alors l'enregistrement MapCat doit comporter un champ borders
  *  - l'enregistrement MapCat doit comporter un champ layer
  *   
  * Pour les 2 types de carte:
  *  - un .tif est considéré comme géoréférencé ssi son gdalinfo contient un champ coordinateSystem
  *  - le géoréférencement est incorrect si le wkt du coordinateSystem de son gdalinfo n'est pas conforme
  *    au motif défini dans COORDINATESYSTEM_WKT_PATTERN
*/
  /* Teste la conformité à la spec et au catalogue
   * retourne [] si la carte livrée est valide et conforme à sa description dans le catalogue
   * sinon un array comportant un au moins des 2 champs:
   *  - errors listant les erreurs
   *  - warnings listant les alertes
  */
  function invalid(array $mapCat): array {
    if (!$mapCat)
      return ['errors'=> ["La carte n'existe pas dans le catalogue MapCat"]];
    $errors = [];
    $warnings = [];
    if ($this->main) {
      //echo "Carte normale<br>\n";
      if (!$this->thumbnail)
        $warnings[] = "L'archive ne comporte pas de miniature";
      if (!$this->main['tif'])
        $errors[] = "L'archive ne comporte pas de GéoTiff de la partie principale";
      if (!$this->main['xml'])
        $errors[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
      switch($this->main['georef']) {
        case 'ok': {
          if (!isset($mapCat['scaleDenominator']) || !isset($mapCat['spatial']))
            $errors[] = "Le fichier GéoTiff principal est géoréférencé alors que le catalogue indique qu'il ne l'est pas";
          break;
        }
        case 'KO': {
          if (!isset($mapCat['scaleDenominator']) || !isset($mapCat['spatial']))
            $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
              ." alors que le catalogue indique qu'il n'est pas géoréférencé";
          elseif (!isset($mapCat['borders']))
            $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
              ." et le catalogue ne fournit pas le champ borders";
          else
            $warnings[] = "Le fichier GéoTiff principal est mal géoréférencé mais cela est compensé par le champ borders"
              ." fourni par le catalogue";
          break;
        }
        case null: {
          if (isset($mapCat['scaleDenominator']) || isset($mapCat['spatial']))
            $errors[] = "Le fichier GéoTiff principal n'est pas géoréférencé alors que le catalogue indique qu'il l'est";
          break;
        }
      }
      if (count($this->insets) <> count($mapCat['insetMaps'] ?? []))
        $errors[] = "L'archive contient ".count($this->insets)." cartouches"
          ." alors que le catalogue en mentionne ".count($mapCat['insetMaps'] ?? []);
      foreach ($this->insets as $name => $inset) {
        if (!$inset['tif'])
          $errors[] = "Le fichier GéoTiff du cartouche $name est absent";
        if (!$inset['xml'])
          $warnings[] = "Le fichier de métadonnées XML du cartouche $name est absent";
      }
      if ($this->suppls) {
        foreach($this->suppls as $suppl)
          $warnings[] = "Le fichier $suppl n'est pas prévu par la spécification";
      }
    }
    else {
      //echo "Carte spéciale<br>\n";
      if (!isset($mapCat['layer']) || (!isset($mapCat['geotiffname'])))
        $errors[] = "L'archive ne correspond pas à une carte normale et le catalogue ne correspond pas à une carte spéciale";
      $geotiffname = $mapCat['geotiffname'];
      if (substr($geotiffname, -4) <> '.pdf')
        $geotiffname .= '.tif';
      if (!in_array($geotiffname, $this->suppls)) {
        $errors[] = "Le fichier \"$geotiffname\" est absent de l'archive qui contient les fichiers "
          .'"'.implode('","', $this->suppls).'"';
      }
    }
    return array_merge($errors ? ['errors'=> $errors] : [], $warnings ? ['warnings'=> $warnings] : []);
  }
  
  function showAsHtml(string $incomingPath, string $mapNum, array $mapCat): void {
    echo "<h2>Carte $_GET[map] de la livraison ",substr($_GET['path'], strlen(SHOMGEOTIFF)),"</h2>\n";
    //echo "incomingPath=$incomingPath<br>\n";
    $incoming = substr($incomingPath, strlen('/var/www/html/'));
    //echo "incoming=$incoming<br>\n";
    echo "<table border=1>";
    echo "<tr><td>cat</td><td><pre>",Yaml::dump($mapCat, 6),"</td></tr>\n";
    if ($this->thumbnail) {
      $thumbnailUrl = "http://localhost/$incoming/$mapNum/".$this->thumbnail;
      echo "<tr><td>miniature</td><td><a href='$thumbnailUrl'><img src='$thumbnailUrl'></a></td></tr>\n";
    }
    else {
      echo "<tr><td>miniature</td><td>absente</td></tr>\n";
    }
    $md = isset($this->main['xml']) ? MapMetadata::extractFromIso19139("$incomingPath/$mapNum/".$this->main['xml'])
        : 'No Metadata';
    if (isset($this->main['tif'])) {
      $georef = "?path=$_GET[path]&map=$_GET[map]&tif=".$this->main['tif']."&action=gdalinfo";
      echo "<tr><td><a href='$georef'>principal</a></td>";
    }
    else {
      echo "<tr><td>principal</td>";
    }
    echo "<td><pre>"; print_r($md); echo "</pre></td></tr>\n";
    foreach ($this->insets as $name => $inset) {
      $md = MapMetadata::extractFromIso19139("$incomingPath/$mapNum/".$inset['xml']);
      $georef = "?path=$_GET[path]&map=$_GET[map]&tif=$inset[tif]&action=gdalinfo";
      echo "<tr><td><a href='$georef'>$name</a></td><td>$md[title]</td></tr>\n";
    }
    if ($this->suppls) {
      echo "<tr><td>fichiers hors spec</td><td><ul>\n";
      foreach ($this->suppls as $suppl)
        echo "<li>$suppl</li>\n";
      echo "</ul></td></tr>\n";
    }
    echo "<tr><td>erreurs</td><td><pre>",
         Yaml::dump(($invalid = $this->invalid($mapCat)) ? $invalid : 'aucun'),
         "</pre></td></tr>\n";
    echo "</table>\n";
    echo "<a href='?path=$_GET[path]&map=$_GET[map]&action=viewtiff'>Affichage des TIFF avec Leaflet</a><br>\n";
    echo "<pre>"; print_r($this); echo "</pre>";
  }
  
  function showAsYaml(string $incomingPath, string $mapNum, array $mapCat): void {
    $record = ['mapNum'=> $mapNum];
    if ($invalid = $this->invalid($mapCat)) {
      $record['MapCat'] = $mapCat;
      $record['invalid'] = $invalid;
    }
    else {
      $record['title'] = $mapCat['title'];
      $record['invalid'] = 'aucun';
    }
    echo Yaml::dump([$record], 9, 2);
    if ($invalid)
      print_r($this);
  }
};

function checkIncoming(string $group, string $incoming): void {
  echo "**Livraison $incoming**\n";
  foreach (new DirectoryIterator(SHOMGEOTIFF."$group/$incoming") as $map) {
    if (substr($map, -3) <> '.7z') continue;
    $mapNum = substr($map, 0, -3);
    $map = new Map(SHOMGEOTIFF."$group/$incoming", $mapNum);
    $map->showAsYaml(SHOMGEOTIFF."$group/$incoming", $mapNum, MapCat::get($mapNum));
    $map->clean();
    //die("Fin ok\n");
  }
}

if ((php_sapi_name() == 'cli') && ($argv[0]=='conform.php')) {
  if (!isset($argv[1]))
    die("usage: $argv[0] ('archives'|'incoming') [{incoming}]\n");
  $group = $argv[1];
  MapCat::init();
  if (isset($argv[2])) {
    checkIncoming($group, $argv[2]);
  }
  else {
    foreach(new DirectoryIterator(SHOMGEOTIFF.$group) as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      checkIncoming($group, $incoming);
    }
  }
}