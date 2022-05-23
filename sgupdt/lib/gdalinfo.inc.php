<?php
/*PhpDoc:
title: gdalinfo.inc.php - Analyse une chaine GDAL INFO et en extrait les infos essentielles
name: gdalinfo.inc.php
classes:
doc: |
  Traitement créés pour les cartes Shom sur la base de l'exemple ci-dessous.
  A adapter pour répondre à d'autres cas.
journal: |
  22/5/2022:
    - modif. utilisation EnVar
  3/5/2022:
    - utilisation de la variable d'environnement SHOMGT3_MAPS_DIR_PATH
    - chagt du paramètre de GdalInfo::__construct()
  28/4/2022:
    - correction d'un bug
    - traitement du cas où le Tiff n'est pas géoréférencé alors seul size est renseigné
  24/4/2022:
    - définition de la classe GdalInfo
  18-20/4/2022:
    - création pour répondre aux besoins de décodage des infos CRS liées aux cartes Shom
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/sexcept.inc.php';
require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/gebox.inc.php';

/*PhpDoc: classes
title: class GdalInfoCrs - gère l'extraction des infos sur le système de coordonnées
name: GdalInfoCrs
doc: |
  La méthode init() effectue une analyse du texte et remplit les variables statiques $patams, $ids et $exprs
  La méthode toArray() génère à partir de ces variables une structure Php qui est stockée dans l'objet
  Les autres méthodes exploitent cette structure Php pour en extraire les infos intéressantes
*/
class GdalInfoCrs {
  static array $params=[]; // paramètres chaine ou nombre
  static array $ids=[]; // les identifiants 
  static array $exprs=[]; // les expressions 
  
  protected $array; // stockage comme structure Php
  
  static function init(string $info): string {
    self::$params = [];
    self::$ids = [];
    self::$exprs = [];
    
    $no = 1;
    $pattern = '!(("[^"]*")|Cartesian|north|east)!';
    while (preg_match($pattern, $info, $matches)) {
      //echo '$matches='; print_r($matches);
      self::$params["{p$no}"] = $matches[1];
      $info = preg_replace($pattern, "{p$no}", $info, 1);
      //echo "info=$info\n";
      $no++;
    }
    
    $pattern = '!([\d.]+)([,\]])!';
    while (preg_match($pattern, $info, $matches)) {
      //echo '$matches='; print_r($matches);
      self::$params["{p$no}"] = $matches[1];
      $info = preg_replace($pattern, "{p$no}$matches[2]", $info, 1);
      //echo "info=$info\n";
      $no++;
    }
    
    $no = 1;
    $pattern = '!\s*([A-Z]+)!';
    while (preg_match($pattern, $info, $matches)) {
      //echo '$matches='; print_r($matches);
      self::$ids["{id$no}"] = $matches[1];
      $replacement = "{id$no}";
      $info = preg_replace($pattern, $replacement, $info, 1);
      //echo "info=$info\n";
      $no++;
    }
    
    $no = 1;
    $pattern = '!({id\d+}\[({(p|expr)\d+},?)+\])!';
    while (preg_match($pattern, $info, $matches)) {
      //echo '$matches='; print_r($matches);
      self::$exprs["{expr$no}"] = $matches[1];
      $replacement = "{expr$no}";
      $info = preg_replace($pattern, $replacement, $info, 1);
      //echo "info=$info\n";
      $no++;
    }
    return $info;
  }
  
  static function exprId(string $expr): string { // extraction de l'id d'une expression
    if (preg_match('!^({id\d+})!', $expr, $matches)) {
      return $matches[1];
    }
    else
      die("No match ligne ".__LINE__);
  }

  static function exprParams(string $expr): array { // extraction des paramètres d'une expression 
    if (preg_match('!^{id\d+}\[(.*)\]$!', $expr, $matches)) {
      return explode(',', $matches[1]);
    }
    else
      die("No match ligne ".__LINE__);
  }

  static function toArray($info): array { // restructuration comme structure Php
    $expr = self::$exprs[$info];
    $paramsOfResult = [];
    foreach (self::exprParams($expr) as $no => $param) {
      if (isset(self::$params[$param])) {
        $p = self::$params[$param];
        if ((substr($p, 0, 1)=='"') && (substr($p, -1)=='"'))
          $p = substr($p, 1, strlen($p)-2);
        elseif (is_numeric($p))
          $p = $p + 0;
        //$paramsOfResult["p$no"] = $p;
        $paramsOfResult[] = $p;
      }
      else {
        //$paramsOfResult = array_merge($paramsOfResult, self::toArray($param));
        $paramsOfResult[] = self::toArray($param);
      }
    }
    $id = self::exprId($expr);
    $id = self::$ids[$id];
    return [ $id => $paramsOfResult];
  }
  
  function __construct(string $info) {
    $info = self::init($info); // decompose info en paramètres, ids et expressions
    $this->array = self::toArray($info); // restructure comme dictionnaire
  }
  
  function asArray(): array { return $this->array; }
  
  function crsId(): string { // Identifiant du CRS sous la forme 'EPSG:xxxx'
    foreach ($this->array['PROJCRS'] as $n => $elt) {
      //print_r($elt);
      if (isset($elt['ID'])) {
        //print_r($elt);
        return $elt['ID'][0].':'.$elt['ID'][1];
      }
    }
  }
  
  function crsUri(): string { // Uri du CRS sous la forme 'http://www.opengis.net/def/crs/EPSG/0/xxxx' 
    foreach ($this->array['PROJCRS'] as $n => $elt) {
      //print_r($elt);
      if (isset($elt['ID'])) {
        //print_r($elt);
        return 'http://www.opengis.net/def/crs/'.$elt['ID'][0].'/0/'.$elt['ID'][1];
      }
    }
  }
};

/*PhpDoc: classes
title: class GdalInfo - gère l'extraction des infos d'un fichier généré par gdalinfo
name: GdalInfo
doc: |
  La méthode __construct() lit le fichier, en extrait les infos intéressantes et les stocke dans l'objet ainsi créé
  Les autres méthodes extraient des infos de l'objet.
*/
class GdalInfo {
  const ErrorFileNotFound = 'GdalInfo::ErrorFileNotFound';
  const ErrorNoMatch = 'GdalInfo::ErrorNoMatch';

  protected array $size; // ['width'=>{width}, 'height'=> {height}]
  protected ?GBox $gbox=null; // le GBox issu du gdalinfo ou null si aucun gbox n'est défini
  protected array $crs=[]; // l'id et l'URI du CRS ou [] si aucun CRS n'est défini
  protected ?EBox $ebox=null; // le EBox issu du gdalinfo
  
  static function dms2Dec(string $val): float { // transforme "9d20'26.32\"E" ou "42d38'39.72\"N" en degrés décimaux
    if (!preg_match('!^(\d+)d([\d ]+)\'([\d .]+)"(E|W|N|S)$!', $val, $matches))
      throw new Exception("No match for \"$val\" in ".__FILE__." ligne ".__LINE__);
    return (in_array($matches[4],['E','N']) ? +1 : -1) * ($matches[1] + ($matches[2] + $matches[3]/60)/60);
  }
  
  static function filepath(string $gtname): string {
    return sprintf('%s/%s/%s.info', EnvVar::val('SHOMGT3_MAPS_DIR_PATH'), substr($gtname, 0, 4), $gtname);
  }
  function size(): array { return $this->size; }
  function ebox(): ?EBox { return $this->ebox; }

  function __construct(string $filename) { // extraction des infos générées par gdalinfo
    if (!is_file($filename))
      throw new SExcept("file '$filename' not found in GdalInfo", self::ErrorFileNotFound);
    $info = file_get_contents($filename);
    //echo $info;
  
    // Size is 9922, 13819
    if (!preg_match('!Size is ([\d]+), ([\d]+)!', $info, $matches)) {
      throw new SExcept("No match for Size", self::ErrorNoMatch);
    }
    $this->size = ['width'=> $matches[1], 'height'=> $matches[2]];
  
    // Si la section PROJCRS n'est pas remplie, cela signfie que le fichier n'est pas géoréférencé
    if (!preg_match('!\n(PROJCRS[^\n]+\n( [^\n]+\n)+)!', $info, $matches))
      return;

    $gdalInfoCrs = new GdalInfoCrs(rtrim($matches[1]));
    $this->crs = [
      'id' => $gdalInfoCrs->crsId(),
      'uri' => $gdalInfoCrs->crsUri(),
    ];
  
    //                Lower Left  ( 1039795.858, 5229041.643) (  9d20'26.32"E, 42d38'39.72"N)
    //                Lower Left  ( 1039797.845, 5164874.826) (  9d20'26.39"E, 42d13' 2.48"N)
    //                Lower Left  (  857014.600, 4943722.892) (  7d41'55.30"E, 40d43'23.68"N)
    //                Lower Left  (  -42220.495, 5569472.663) (  0d22'45.38"W, 44d51'38.67"N)
    //                Lower Left  (  -84761.231, 5617914.803) (  0d45'41.12"W, 45d10' 9.84"N)
    $pattern = '!Lower Left  \(([-\d\. ]+),([-\d\. ]+)\) \(\s*([\dd\.\'" ]+(E|W)),\s*([\dd\.\'" ]+(N|S))\)\n!';
    if (preg_match($pattern, $info, $matches)) {
      //print_r($matches);
      $ebox = [(float)$matches[1], (float)$matches[2]];
      $gbox = [self::dms2Dec($matches[3]), self::dms2Dec($matches[5])];
    }
    //Lower Left  (    0.0, 9922.0)
    /*elseif (preg_match('!Lower Left  \(([-\d\. ]+),([-\d\. ]+)\)!', $info, $matches)) {
      $ebox = [(float)$matches[1], (float)$matches[2]];
      $gbox = [];
    }*/
    else
      throw new SExcept("No match for Lower Left", self::ErrorNoMatch);
    
    
    //Upper Right ( 1097372.110, 5309231.749) (  9d51'28.30"E, 43d10'25.99"N)
    //Upper Right ( 1097374.097, 5245064.932) (  9d51'28.37"E, 42d45' 1.95"N)
    $pattern = '!Upper Right \(([-\d\. ]+),([-\d\. ]+)\) \(\s*([\dd\.\'" ]+(E|W)),\s*([\dd\.\'" ]+(N|S))\)\n!';
    if (preg_match($pattern, $info, $matches)) {
      //print_r($matches);
      $ebox[2] = (float)$matches[1];
      $ebox[3] = (float)$matches[2];
      $gbox[2] = self::dms2Dec($matches[3]);
      $gbox[3] = self::dms2Dec($matches[5]);
    }
    /*elseif (preg_match('!Upper Right \(([-\d\. ]+),([-\d\. ]+)\)!', $info, $matches)) {
      $ebox[2] = (float)$matches[1];
      $ebox[3] = (float)$matches[2];
    }*/
    else
      throw new SExcept("No match for Upper Right", self::ErrorNoMatch);
  
    //print_r($gbox);
    if ($gbox && (count($gbox)==4))
      $this->gbox = GBox::fromShomGt(['SW'=> [$gbox[0], $gbox[1]], 'NE'=> [$gbox[2], $gbox[3]]]);
    //echo "gbox=$this->gbox\n";
    //print_r($ebox);
    $this->ebox = new EBox([$ebox[0], $ebox[1], $ebox[2], $ebox[3]]);
    //echo "ebox=$this->ebox\n";
  }

  function asArray(): array {
    return [
      'size'=> $this->size,
      'gbox'=> $this->gbox ? $this->gbox->asArray() : null,
      'crs'=> $this->crs,
      'ebox'=> $this->ebox ? $this->ebox->asArray() : null,
    ];
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire


require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/geotiffs.inc.php';

use Symfony\Component\Yaml\Yaml;

{ // données de test
$info = <<<EOT
PROJCRS["WGS 84 / World Mercator",
    BASEGEOGCRS["WGS 84",
        DATUM["World Geodetic System 1984",
            ELLIPSOID["WGS 84",6378137,298.257223563,
                LENGTHUNIT["metre",1]]],
        PRIMEM["Greenwich",0,
            ANGLEUNIT["degree",0.0174532925199433]],
        ID["EPSG",4326]],
    CONVERSION["Mercator (variant A)",
        METHOD["Mercator (variant A)",
            ID["EPSG",9804]],
        PARAMETER["Latitude of natural origin",0,
            ANGLEUNIT["degree",0.0174532925199433],
            ID["EPSG",8801]],
        PARAMETER["Longitude of natural origin",0,
            ANGLEUNIT["degree",0.0174532925199433],
            ID["EPSG",8802]],
        PARAMETER["Scale factor at natural origin",1,
            SCALEUNIT["unity",1],
            ID["EPSG",8805]],
        PARAMETER["False easting",0,
            LENGTHUNIT["metre",1],
            ID["EPSG",8806]],
        PARAMETER["False northing",0,
            LENGTHUNIT["metre",1],
            ID["EPSG",8807]]],
    CS[Cartesian,2],
        AXIS["easting",east,
            ORDER[1],
            LENGTHUNIT["metre",1]],
        AXIS["northing",north,
            ORDER[2],
            LENGTHUNIT["metre",1]],
    ID["EPSG",3395]]
EOT;
}

//echo "<pre>$info\n";
echo "<pre>\n";

$info = new GdalInfoCrs($info);
if (0) {
  $yaml = Yaml::dump($info->asArray(), 99, 2);
  //echo $yaml;
  echo preg_replace('!-\n *!', '- ', $yaml);
}
elseif (0) {
  echo "crsId=",$info->crsId(),"\n";
  echo "crsUri=",$info->crsUri(),"\n";
}
elseif (1) { // Test sur tous les GéoTiffs
  print_r(geotiffs());
  foreach (geotiffs() as $gtname) {
    $gdalInfo = new GdalInfo(GdalInfo::filepath($gtname));
    echo Yaml::dump([$gtname => $gdalInfo->asArray()]);
  }
}
else {
  $gtname = '6670_pal300';
  $gdalInfo = new GdalInfo(GdalInfo::filepath($gtname));
  echo Yaml::dump([$gtname => $gdalInfo->asArray()]);
}