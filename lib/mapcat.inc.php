<?php
/*PhpDoc:
title: mapcat.inc.php - charge le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
name: mapcat.inc.php
classes:
doc: |
journal: |
  2/7/2023:
    - correction d'un bug en lien avec la carte 7620
    - correction d'un bug sur la création du répertoire temp
  1/8/2022:
    - correction suite à analyse PhpStan level 6
  17-18/6/2022:
    - création
*/
require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/execdl.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';

class TempMapCat {
  const MAPCAT_TEMP_PATH = __DIR__.'/../sgupdt/temp/mapcat.json';
  protected string $name; // le nom de la carte
  /** @var array<string, mixed> $map */
  protected array $map; // les caractéristiques de la carte correspondant au fichier mapcat.yaml
  
  /** @var array<string, TempMapCat> $cat */
  static array $cat; // catalogue [{mapName} => TempMapCat]
  
  // si $option == 'download' ou si le fichier mapcat.json n'existe pas alors télécharge mapcat.json depuis $SHOMGT3_SERVER_URL
  // puis initialise self::$cat à partir du fichier 
  static function init(string $option=''): void {
    if (($option=='download') || !is_file(self::MAPCAT_TEMP_PATH)) {
      $url = EnvVar::val('SHOMGT3_SERVER_URL').'/cat.json';
      if (!is_dir(__DIR__.'/temp')) mkdir(__DIR__.'/temp');
      $httpCode = download($url, self::MAPCAT_TEMP_PATH, 1);
      if ($httpCode <> 200)
        throw new Exception("Erreur de download de cat.json");
    }
    $mapcat = json_decode(file_get_contents(self::MAPCAT_TEMP_PATH), true);
    foreach ($mapcat['maps'] as $name => $map) {
      self::$cat[$name] = new self($name, $map);
    }
  }
  
  /** @param array<string, mixed> $map */
  function __construct(string $name, array $map) {
    $this->name = $name;
    $this->map = $map;
  }
  
  //function title(): string { return $this->map['title']; }
  //function spatial(): array { return $this->map['spatial'] ?? []; }
  function scaleDen(): ?int {
    return isset($this->map['scaleDenominator']) ? intval(str_replace('.', '', $this->map['scaleDenominator'])) : null;
  }
  
  /** @return array<int, array<string, mixed>> */
  function insetMaps(): array { return $this->map['insetMaps'] ?? []; }
  
  function insetMap(int $no): self {
    return new self("inset $no of $this->name", $this->map['insetMaps'][$no]);
  }
  
  /** @return array<string, mixed> */
  function gtInfo(): array {
    return [
      'title'=> $this->map['title'],
      'spatial'=> $this->map['spatial'] ?? [],
      'outgrowth'=> $this->map['outgrowth'] ?? [],
      'scaleDen'=> $this->scaleDen(),
      'layer'=> $this->map['layer'] ?? null,
      'toDelete'=> $this->map['toDelete'] ?? [],
      'z-order'=> $this->map['z-order'] ?? 0,
      'borders'=> $this->map['borders'] ?? [],
    ];
  }
    
  // sélectionne le cartouche qui correspond le mieux au rectangle passé en paramètre et en construit un objet TempMapCat
  private function insetMapFromRect(GBox $georefrect): ?self {
    $distmin = INF;
    $best = -1;
    foreach ($this->map['insetMaps'] as $no => $insetMap) {
      //echo "insetMaps[$no]="; print_r($insetMap);
      $dist = GBox::fromGeoDMd($insetMap['spatial'])->distance($georefrect);
      //echo "distance=$dist\n";
      if ($dist < $distmin) {
        $best = $no;
        $distmin = $dist;;
      }
    }
    if ($best == -1)
      return null;
    //  echo "best="; print_r($this->map['insetMaps'][$best]);
    return new self("inset $best of $this->name", $this->map['insetMaps'][$best]);
  }
  
  // retourne la carte ou le cartouche correspondant à $gtname, $temp indique si la carte est dans temp ou dans maps
  static function fromGtname(string $gtname, bool $temp): ?self {
    $mapnum = substr($gtname, 0, 4);
    $map = self::$cat["FR$mapnum"] ?? null;
    if (!$map)
      return null;
    //elseif (!preg_match('!^\d+_\d+_gtw!', $gtname)) { // espace principal de carte standard ou carte spéciale 
    // correction bug 2/7/2023
    elseif (!preg_match('!^\d+_[^_]+_gtw$!', $gtname)) { // espace principal de carte standard ou carte spéciale 
      return $map;
    }
    // si non cartouche 
    elseif (count($map->insetMaps())==1) { // cartouche unique
      return $map->insetMap(0);
    }
    else {
      $gtinfopath = GdalInfo::filepath($gtname, $temp);
      $gdalinfo = new GdalInfo($gtinfopath);
      $georefrect = $gdalinfo->ebox()->geo('WorldMercator');
      return $map->insetMapFromRect($georefrect);
    }
  }
  
  // extrait de TempMapCat ceux ayant un champ toDelete
  // et retourne un array [{gtname}=> {toDelete}] / {toDelete} défini par mapcat.schema.yaml
  /** @return array<string, array<string, array<int, mixed>>> */
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
  /** @return array<string, array<string, array<int, mixed>>> */
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


//TempMapCat::init('download');
TempMapCat::init();
echo "<pre>";
//print_r(TempMapCat::fromGtname('6969_pal300', false));
//print_r(TempMapCat::fromGtname('7420_4_gtw', false));
//print_r(TempMapCat::fromGtname('7420_4_gtw', true));
//print_r(TempMapCat::fromGtname('8509_pal300', false));

print_r(TempMapCat::allZonesToDelete());

