<?php
/** mapfromwfs - liste des cartes définies dans le WFS
 * @package shomgt\shomft
 */
namespace shomft;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/addusforthousand.inc.php';
require_once __DIR__.'/ft.php';

use Symfony\Component\Yaml\Yaml;

/**
 * MapFromWfs - liste des cartes définies dans le WFS 
 *
 * Chaque carte est définie par ses propriétés et sa géométrie.
 * La liste des cartes est fournie dans la variable $all indexée sur la propriété carte_id.
 * Cette liste est actualisée tous les MAX_AGE jours à partir du serveur WFS du Shom.
 */
class MapFromWfs {
  /** Age en jours à partir duquel les collections sont actualisées depuis le serveur WFS du Shom */
  const MAX_AGE = 7;
  /** properties
   * @var array<string, string> $prop */
  public readonly array $prop;
  /** géométrie comme MultiPolygone */
  public readonly \gegeom\MultiPolygon $mpol;
  
  /** liste des MapFromWfs indexés sur carte_id
   * @var array<string, MapFromWfs> $all */
  static array $all;
  
  /** retourne l'ancienneté du moissonnage du WFS en nombre de jours ou -1 si le fichier n'existe pas */
  static function age(): int {
    if (!is_file(__DIR__."/gt.json"))
      return -1;
    $now = new \DateTimeImmutable;
    $filemtime = $now->setTimestamp(filemtime(__DIR__."/gt.json"));
    return $filemtime->diff($now)->days;
  }
  
  /** Met à jour les 3 collections gt, aem et delmar depuis le serveur WFS du Shom */
  static function updateFromShom(): void {
    $shom = new FtServer;
    foreach(['gt','aem','delmar'] as $coll) {
      echo "Copie de la collection $coll depuis le serveur WFS du Shom<br>\n";
      $shom->get($coll);
    }
    echo "Recopie de la collection delmar dans view/geojson/<br>\n";
    copy(__DIR__."/delmar.json", __DIR__."/../view/geojson/delmar.geojson");
  }
  
  /** initialise la liste des cartes depuis les fichiers jsoncs'ils ne sont pas trop agés,
   * s'ils le sont les actualisent avant depuis le WFS du Shom.
   * Cette initialisation est effectuée à la lecture du fichier Php. */
  static function init(): void {
    $age = self::age();
    //echo "MapFromWfs::age()=$age<br>\n";
    if (($age == -1) || ($age >= self::MAX_AGE))
      self::updateFromShom();
    $gt = json_decode(file_get_contents(__DIR__.'/gt.json'), true);
    $aem = json_decode(file_get_contents(__DIR__.'/aem.json'), true);
    foreach (array_merge($gt['features'], $aem['features']) as $gmap) {
      //if ($gmap['properties']['carte_id'] == '0101') continue; // Pour test du code
      self::$all[$gmap['properties']['carte_id']] = new self($gmap);
    }
    ksort(self::$all);
  }
  
  /** @param TGeoJsonFeature $gmap; Feature GeoJSON correspondant à la carte */
  function __construct(array $gmap) {
    $this->prop = $gmap['properties'];
    $this->mpol = \gegeom\MultiPolygon::fromGeoArray($gmap['geometry']);
  }
  
  /** construit l'URL vers mapwcat.php bien centré sur la carte et avec le bon niveau de zoom */
  function mapwcatUrl(): string {
    $center = $this->mpol->center();
    $center = "center=$center[1],$center[0]";
    
    if (isset($this->prop['scale'])) {
      $zoom = round(log(1e7 / (int)$this->prop['scale'], 2));
      if ($zoom < 3) $zoom = 3;
    }
    else {
      $zoom = 6;
    }
    return "../mapwcat.php?options=wfs&zoom=$zoom&$center";
  }
  
  /** affiche une carte  */
  function showOne(): void {
    //echo '<pre>gmap = '; print_r($gmap); echo "</pre>\n";
    $array = [
      'title'=> '{a}'.$this->prop['name'].'{/a}',
      'scale'=> isset($this->prop['scale']) ? '1:'.addUndescoreForThousand((int)$this->prop['scale']) : 'undef',
    ];
    
    if (!isset($this->prop['scale']))
      $array['status'] = 'sans échelle';
    elseif ($this->prop['scale'] > 6e6)
      $array['status'] = 'à petite échelle (< 1/6M)';
    elseif ($mapsFr = \shomft\Zee::inters($this->mpol))
      $array['status'] = 'intersecte '.implode(',',$mapsFr);
    else
      $array['status'] = "Hors ZEE française";
    
    $url = $this->mapwcatUrl();
    //echo "<a href='$url'>lien zoom=$zoom</a>\n";
    echo str_replace(["-\n ",'{a}','{/a}'], ['-',"<a href='$url'>","</a>"], Yaml::dump([$array]));
  }
  
  /** liste des cartes d'intérêt construite à partir du flux WFS du Shom
   *
   * Une carte est d'intérêt ssi
   *  - soit elle intersecte la ZEE
   *  - soit elle est à petite échelle (< 1/6M)
   *
   * Retour sous la forme [carte_id => [ZeeId]|['SmallScale']] 
   *
   * @return array<string, list<string>> */
  static function interest(): array { 
    $list = [];
    foreach (self::$all as $id => $gmap) {
      if ($zeeIds = Zee::inters($gmap->mpol))
        $list[$gmap->prop['carte_id']] = $zeeIds;
      elseif (isset($gmap->prop['scale']) && ($gmap->prop['scale'] > 6e6))
        $list[$gmap->prop['carte_id']] = ['SmallScale'];
    }
    return $list;
  }
};
//MapFromWfs::init();
