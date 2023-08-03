<?php
// gdalinfo.inc.php - fourniture d'un gdalinfo d'un fichier .tif ou .pdf - 3/8/2023

require_once __DIR__.'/../lib/coordsys.inc.php';

{/*PhpDoc: classes
name: GBox
title: class GBox - Gestion d'une BBox en coord. géo., chaque position codée comme [lon, lat]
doc: |
  Par convention, on cherche à respecter:
    (-180 <= lon <= 180) && (-90 <= lat <= 90)
  sauf pour les boites à cheval sur l'antiméridien où:
    (-180 <= lonmin <= 180 < lonmax <= 180+360 )
  Cette convention est différente de celle utilisée par GeoJSON.
  Toutefois, uGeoJSON génère des bbox avec des coord. qqc, y compris lonmin < -180
*/}
class GBox { 
  protected array $min=[]; // position SW en LonLat
  protected array $max=[]; // position NE en LonLat
  
  function __construct(array $polygon=[]) {
    if (!$polygon) return;
    /* Je fais l'hypothèse que l'ordre des positions dans le polygone est le même dans gdalinfo que dans cornerCoordinates
    ** à savoir: upperLeft, lowerLeft, lowerRight, upperRight, upperLeft
    ** Si ((lowerLeft[0] > 0) && (lowerRight[0] < 0)) cela indique que la boite est à cheval sur l'antiméridien
    */
    $lpos = $polygon['coordinates'][0]; // la liste des positions du polygone
    $this->min = $lpos[1];
    if (($lpos[1][0] > 0) && ($lpos[2][0] < 0)) { // boite est à cheval sur l'antéméridien
      $this->max = [$lpos[3][0]+360, $lpos[3][1]];
    }
    else { // la boite n'est PAS à cheval sur l'antiméridien
      $this->max = $lpos[3];
    }
  }
  
  static function createFromDcmiBox(array $dcmiBox): self {
    $gbox = new GBox;
    $gbox->min = [$dcmiBox['westlimit'], $dcmiBox['southlimit']];
    $gbox->max = [$dcmiBox['eastlimit'], $dcmiBox['northlimit']];
    return $gbox;
  }
  
  function min(): array { return $this->min; }
  function max(): array { return $this->max; }
  
  function __toString(): string {
    if (!$this->min)
      return '[]';
    else
      return sprintf('[west: %f, south: %f, east: %f, north: %f]', $this->min[0], $this->min[1], $this->max[0], $this->max[1]);
  }
  
  function astrideTheAntimeridian(): bool { return ($this->max[0] > 180); }
    
  function area(): float { return ($this->max[0]-$this->min[0]) * ($this->max[1]-$this->min[1]); }
  
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
  
  function includes(GBox $small, bool $show=false): bool { // teste si $small est inclus dans $this
    $result = ($this->min[0] < $small->min[0]) && ($this->min[1] < $small->min[1])
           && ($this->max[0] > $small->max[0]) && ($this->max[1] > $small->max[1]);
    if ($show)
      echo $this,($result ? " includes " : " NOT includes "),$small,"<br>\n";
    return $result;
  }
  
  function latLngBounds(): array { // retourne un array de 2 positions en LatLng pour LeafLet
    return [[$this->min[1], $this->min[0]], [$this->max[1], $this->max[0]]];
  }
};

// BBox en coord. projétées
class Ebox {
  protected array $min=[]; // position SW en proj
  protected array $max=[]; // position NE en proj
  protected array $center=[]; // position centre

  function __construct(array $cc=[]) {
    if (!$cc) return;
    // construction à partir d'un array cornerCoordinates
    $this->min = [
      ($cc['upperLeft'][0] + $cc['lowerLeft'][0])/2, // left
      ($cc['lowerRight'][1] + $cc['lowerLeft'][1])/2, // lower
    ];
    $this->max = [
      ($cc['upperRight'][0] + $cc['lowerRight'][0])/2, // right
      ($cc['upperRight'][1] + $cc['upperLeft'][1])/2, // upper
    ];
    $this->center = $cc['center'];
  }
  
  static function fromListOfPos(array $lpos): EBox {
    $ebox = new EBox;
    foreach ($lpos as $pos)
      $ebox->bound($pos);
    return $ebox;
  }
  
  function bound(array $pos): void { // agrandit le bbox avec la position $pos
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
  
  function __toString(): string {
    $center = $this->center ? sprintf("[x: %.0f, y: %.0f]", $this->center[0], $this->center[1]) : '[]';
    return sprintf("[west: %.0f, south: %.0f, east: %.0f, north: %.0f, center: %s]",
            $this->min[0], $this->min[1], $this->max[0], $this->max[1], $center);
  }
  
  // distance entre 2 boites, nulle ssi les 2 boites sont identiques
  function distance(EBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur de GBox::distance() avec une des EBox vide");
    return max(
      abs($b2->min[0] - $this->min[0]),
      abs($b2->min[1] - $this->min[1]),
      abs($b2->max[0] - $this->max[0]),
      abs($b2->max[1] - $this->max[1])
    );
  }
};

/* Gdalinfo - contenu du gdalinfo d'un fichier .tif ou .pdf
 * Un fichier peut être géoréférencé, non géoréférencé ou mal géoréférencé.
 * Il est géoréférencé ssi les champs coordinateSystem, cornerCoordinates et wgs84Extent sont définis.
 * Il est mal géoréférencé ssi son géoréférencement est erroné.
 * Cela peut se traduire par un coordinateSystem/wkt invalide
 * ou des coordonnées cornerCoordinates différentes de la projection de wgs84Extent.
 * Dans cette version, je considère que coordinateSystem/wkt et cornerCoordinates ne sont pas utilisés
 * et que donc un fichier n'est jamais mal géoréférencé
*/
require_once __DIR__.'/my7zarchive.inc.php';

class Gdalinfo { // 
  protected array $info; // contenu du gdalinfo
  
  function __construct(string $path) {
    $cmde = "gdalinfo -json $path";
    //echo "$cmde<br>\n";
    exec($cmde, $output, $retval);
    //echo "<pre>"; print_r($output); echo "retval=$retval</pre>\n";
    $this->info = json_decode(implode("\n", $output), true);
  }
  
  function asArray(): array { return $this->info; }
  
  /* indique si le géoréférencement est absent, correct ou incorrect, retourne
   *  - null si non géoréférencé cad champ 'coordinateSystem/wkt' non défini ou vide
   *  - 'ok' si géoréférencé correctement cad champ 'coordinateSystem/wkt' défini et non vide
   *  - 'KO' si géoréférencé incorrectement
   */
  function georef(): ?string {
    // Si coordinateSystem.wkt est non défini ou vide alors non géoréférencé
    if (!($this->info['coordinateSystem']['wkt'] ?? null))
      return null;
    else // sinon test de goodGeoref()
      return $this->goodGeoref() ? 'ok' : 'KO';
  }
  
  // Test de georef correct fondé sur la comparaison entre cornerCoordinates et la projection en WorldMercator de wgs84Extent
  function goodGeoref(bool $debug=false): bool { 
    $gbox = $this->gbox();
    if ($debug)
      echo "  gbox=$gbox\n";
    $proj = EBox::fromListOfPos([
      WorldMercator::proj($gbox->min()),
      WorldMercator::proj($gbox->max()),
    ]);
    if ($debug)
      echo "  proj=$proj\n";
    
    $ebox = new EBox($this->info['cornerCoordinates']);
    if ($debug)
      echo "  ebox=$ebox\n";
    $distance = $ebox->distance($proj);
    if ($debug)
      printf("  distance=%.0f\n", $distance);
    
    return ($distance < 1000);
  }
  
  function gbox(): ?GBox { // retourne le GBox ssi il est défini dans le gdalinfo
    if (!isset($this->info['wgs84Extent']))
      return null;
    else
      return new GBox($this->info['wgs84Extent']);
  }

  static function test(string $path7z, string $entry): void {
    $archive = new My7zArchive($path7z);
    $gdalInfo = new GdalInfo($path = $archive->extract($entry));
    $archive->remove($path);
    //print_r($gdalInfo);
    echo 'georef = ',$gdalInfo->georef(),"\n";
  }
};

if ((php_sapi_name() == 'cli') && ($argv[0]=='gdalinfo.inc.php')) {
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  if (0) {
    GdalInfo::test("$PF_PATH/incoming/20230628aem/7330.7z", '7330/7330_2019.pdf');
  }
  elseif (0) {
    GdalInfo::test("$PF_PATH/attente/20230628aem/8502.7z", '8502/8502CXC_Ed2_2023.tif'); 
  }
  elseif (1) { // Test de goodGeoref
    foreach ([
        "7620-2018c5 - Approches d'Anguilla"=>
          ['path7z'=> 'incoming/7620-2018c5/7620.7z', 'entry'=> '7620/7620_pal300.tif'],
        "7471-2021c3 - D'Anguilla à Saint-Barthélemy"=>
          ['path7z'=> 'archives/7471/7471-2021c3.7z', 'entry'=> '7471/7471_pal300.tif'],
        "6977 - Océan Pacifique Nord - Partie Nord-Ouest (à cheval sur AM)"=>
          ['path7z'=> 'archives/6977/6977-1982c169.7z', 'entry'=> '6977/6977_pal300.tif'],
      ] as $title => $tif) {
        echo "$title:\n";
        $archive = new My7zArchive("$PF_PATH/$tif[path7z]");
        $gdalInfo = new GdalInfo($path = $archive->extract($tif['entry']));
        $archive->remove($path);
        //print_r($gdalInfo);
        $good = $gdalInfo->goodGeoref();
        echo "  ",$good ? 'good' : 'bad',"\n";
    }
    $am = WorldMercator::proj([180, 0]);
    printf("AM=[x: %.0f, y: %.0f]\n", $am[0],$am[1]);
  }
}