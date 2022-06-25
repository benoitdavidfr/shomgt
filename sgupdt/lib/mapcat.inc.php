<?php
/*PhpDoc:
title: mapcat.inc.php - charge le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
name: mapcat.inc.php
classes:
doc: |
journal: |
  17-18/6/2022:
    - création
*/
require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/execdl.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';

class MapCat {
  const MAPCAT_TEMP_PATH = __DIR__.'/../temp/mapcat.json';
  protected string $name; // le nom de la carte
  protected array $map; // les caractéristiques de la carte correspondant au fichier mapcat.yaml
  
  static array $cat; // catalogue [{mapName} => MapCat]
  
  // si $option == 'download' ou si le fichier mapcat.json n'existe pas alors télécharge mapcat.json depuis $SHOMGT3_SERVER_URL
  // puis initialise self::$cat à partir du fichier 
  static function init(string $option=''): void {
    if (($option=='download') || !is_file(self::MAPCAT_TEMP_PATH)) {
      $url = EnvVar::val('SHOMGT3_SERVER_URL').'/cat.json';
      if (!is_dir(__DIR__.'/../temp')) mkdir(__DIR__.'/temp');
      $httpCode = download($url, self::MAPCAT_TEMP_PATH, 1);
      if ($httpCode <> 200)
        throw new Exception("Erreur de download de cat.json");
    }
    $mapcat = json_decode(file_get_contents(self::MAPCAT_TEMP_PATH), true);
    foreach ($mapcat['maps'] as $name => $map) {
      self::$cat[$name] = new self($name, $map);
    }
  }
  
  function __construct(string $name, array $map) {
    $this->name = $name;
    $this->map = $map;
  }
  
  //function title(): string { return $this->map['title']; }
  //function spatial(): array { return $this->map['spatial'] ?? []; }
  function scaleDen(): ?int {
    return isset($this->map['scaleDenominator']) ? str_replace('.', '', $this->map['scaleDenominator']) : null;
  }
  function insetMaps(): array { return $this->map['insetMaps'] ?? []; }
  
  function insetMap(int $no): self {
    return new self("inset $no of $this->name", $this->map['insetMaps'][$no]);
  }
  
  function gtInfo(): array {
    return [
      'title'=> $this->map['title'],
      'spatial'=> $this->map['spatial'] ?? [],
      'scaleDen'=> $this->scaleDen(),
      'layer'=> $this->map['layer'] ?? null,
      'toDelete'=> $this->map['toDelete'] ?? [],
      'z-order'=> $this->map['z-order'] ?? 0,
      'borders'=> $this->map['borders'] ?? [],
    ];
  }
    
  // sélectionne le cartouche qui correspond le mieux au rectangle passé en paramètre et en construit un objet MapCat
  private function insetMapFromRect(GBox $georefrect): self {
    $best = -1;
    foreach ($this->map['insetMaps'] as $no => $insetMap) {
      //echo "insetMaps[$no]="; print_r($insetMap);
      $dist = GBox::fromShomGt($insetMap['spatial'])->distance($georefrect);
      //echo "distance=$dist\n";
      if (($best == -1) || ($dist < $distmin)) {
        $best = $no;
        $distmin = $dist;;
      }
    }
    //  echo "best="; print_r($this->map['insetMaps'][$best]);
    return new self("inset $best of $this->name", $this->map['insetMaps'][$best]);
  }
  
  // retourne la carte ou le cartouche correspondant à $gtname, $temp indique si la carte est dans temp ou dans maps
  static function fromGtname(string $gtname, bool $temp): ?self {
    $mapnum = substr($gtname, 0, 4);
    $map = self::$cat["FR$mapnum"] ?? null;
    if (!$map)
      return null;
    if (preg_match('!^\d+_\d+_gtw!', $gtname)) { // cartouche 
      if (count($map->insetMaps())==1) {
        return $map->insetMap(0);
      }
      else {
        $gtinfopath = GdalInfo::filepath($gtname, $temp);
        $gdalinfo = new GdalInfo($gtinfopath);
        $georefrect = $gdalinfo->ebox()->geo('WorldMercator');
        return $map->insetMapFromRect($georefrect);
      }
    }
    else { // espace principal de carte standard ou carte spéciale 
      return $map;
    }
  }
  
  // extrait de MapCat ceux ayant un champ toDelete
  // et retourne un array [{gtname}=> {toDelete}] / {toDelete} défini par mapcat.schema.yaml
  static function allZonesToDelete(): array {
    $toDelete = [];
    foreach (self::$cat as $mapid => $mapcat) {
      if (isset($mapcat->map['toDelete'])) {
        $toDelete[substr($mapid, 2).'_pal300'] = $mapcat->map['toDelete'];
      }
      foreach ($mapcat->map['insetMaps'] ?? [] as $insetMap) {
        if (isset($insetMap['toDelete'])) {
          $toDelete[$insetMap['toDelete']['geotiffname']] = $insetMap['toDelete'];
        }
      }
    }
    return $toDelete;
  }
  
  // retourne pour la carte $mapid les champs toDelete par gtname ou [] s'il n'y en a pas
  static function toDeleteByGtname(string $mapid): array {
    $toDelete = []; // [{gtname}=> {toDelete}]
    $mapcat = self::$cat[$mapid];
    if (isset($mapcat->map['toDelete'])) {
      $toDelete[substr($mapid, 2).'_pal300'] = $mapcat->map['toDelete'];
    }
    foreach ($mapcat->map['insetMaps'] ?? [] as $insetMap) {
      if (isset($insetMap['toDelete'])) {
        $toDelete[$insetMap['toDelete']['geotiffname']] = $insetMap['toDelete'];
        unset($toDelete[$insetMap['toDelete']['geotiffname']]['geotiffname']);
      }
    }
    ksort($toDelete);
    return $toDelete;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire


//MapCat::init('download');
MapCat::init();
echo "<pre>";
//print_r(MapCat::fromGtname('6969_pal300', false));
//print_r(MapCat::fromGtname('7420_4_gtw', false));
//print_r(MapCat::fromGtname('7420_4_gtw', true));
//print_r(MapCat::fromGtname('8509_pal300', false));

print_r(MapCat::toDelete());

