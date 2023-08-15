<?php
/*PhpDoc:
name: gdalinfo.inc.php
title: gdalinfo.inc.php - fourniture d'un gdalinfo d'un fichier .tif ou .pdf - 3/8/2023
*/
require_once __DIR__.'/../lib/coordsys.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';

{/*PhpDoc: classes
name: GdalGBox
title: class GdalGBox extends GBox - Ajout à GBox de fonctionnalités
doc: |
  Par convention, on cherche à respecter:
    (-180 <= lon <= 180) && (-90 <= lat <= 90)
  sauf pour les boites à cheval sur l'antiméridien où:
    (-180 <= lonmin <= 180 < lonmax <= 180+360 )
  et sauf pour les boites qui couvrent la totalité de la Terre en longitude.
  Cette convention est différente de celle utilisée par GeoJSON.
  Toutefois, uGeoJSON génère des bbox avec des coord. qqc, y compris lonmin < -180
  
  J'ajoute à GBox la possibilité de création à partir du polygone GeoJSON dans wgs84extent
  Je fais l'hypothèse que l'ordre des positions dans le polygone est le même dans gdalinfo que dans cornerCoordinates
  à savoir: upperLeft, lowerLeft, lowerRight, upperRight, upperLeft
  Si ((lowerLeft[0] > 0) && (lowerRight[0] < 0)) cela indique que la boite est à cheval sur l'antiméridien
  
  Je considère au final qu'un GBox standardisé respecte les 2 contraintes ci-dessus.

  On essaie ici de réutiliser GBox et EBox en en créant des sous-classes GdalGBox et GdalEBox
  pour leur ajouter des fonctionalités.
  Pour cela les règles à respecter sont le suivantes:
   - ne pas redéfinir __construct() avec une signature incompatible avec celle du parent car certaines méthodes
     comme BBox::round() par exemple utilisent le __construct() de ses enfants.
     - la signature peut par contre être étendue à de nouvelles possibilités
   - redéfinir les méthodes comme EBox::geo() car j'ai besoin qu'elle renvoie un GdalGBox
*/}
class GdalGBox extends GBox { 
  //protected array $min=[]; // position SW en LonLat
  //protected array $max=[]; // position NE en LonLat
  
  function __construct(array|string $param=[]) {
    if (isset($param['coordinates'])) { // cas où le paramètre est un polygone GeoJSON
      $lpos = $param['coordinates'][0]; // la liste des positions du polygone
      $min = $lpos[1];
      $max = $lpos[3];
      if (($lpos[1][0] > 0) && ($lpos[2][0] < 0)) { // boite est à cheval sur l'antiméridien
        $max[0] += 360;
      }
      parent::__construct([$min, $max]);
    }
    else {
      parent::__construct($param);
    }
  }
  
  function std(): self { // standardisation, cad respectant les conventions
    $min = $this->min;
    if ($min[0] <= -180) $min[0] += 360;
    if ($min[0]  >  180) $min[0] -= 360;
    $max = $this->max;
    if ($max[0] <= -180) $max[0] += 360;
    if ($max[0]  >  180) $max[0] -= 360;
    if ($max[0] < $min[0]) $max[0] += 360; // à cheval sur l'antiméridien
    return new self([$min, $max]);
  }
};

// extension de EBox avec possibilité de création à partir du champ cornerCoordinates de gdalinfo
// et création d'un GdalGBox par déprojection du GdalEBox
class GdalEBox extends EBox {
  //protected array $min=[]; // position SW en proj
  //protected array $max=[]; // position NE en proj
  protected array $center=[]; // position centre

  function __construct(array|string $param=[]) {
    if (is_array($param) && isset($param['center'])) {
      $min = [
        ($param['upperLeft'][0] + $param['lowerLeft'][0])/2, // left
        ($param['lowerRight'][1] + $param['lowerLeft'][1])/2, // lower
      ];
      $max = [
        ($param['upperRight'][0] + $param['lowerRight'][0])/2, // right
        ($param['upperRight'][1] + $param['upperLeft'][1])/2, // upper
      ];
      parent::__construct([$min, $max]);
      $this->center = $param['center'];
    }
    else {
      parent::__construct($param);
    }
  }
  static function fromCornerCoordinates(array $cc) : self {
    //echo "GdalEbox::fromCornerCoordinates(cc) "; print_r($cc);
    if (!isset($cc['center']))
      throw new Exception("Erreur, le paramètre n'est pas un cc");
    // construction à partir d'un array cornerCoordinates
    $min = [
      ($cc['upperLeft'][0] + $cc['lowerLeft'][0])/2, // left
      ($cc['lowerRight'][1] + $cc['lowerLeft'][1])/2, // lower
    ];
    $max = [
      ($cc['upperRight'][0] + $cc['lowerRight'][0])/2, // right
      ($cc['upperRight'][1] + $cc['upperLeft'][1])/2, // upper
    ];
    $gdalEBox = new self([$min, $max]);
    $gdalEBox->center = $cc['center'];
    return $gdalEBox;
  }
  
  /*static function fromListOfPos(array $lpos): EBox {
    $ebox = new EBox;
    foreach ($lpos as $pos)
      $ebox->bound($pos);
    return $ebox;
  }*/
  
  /*function bound(array $pos): void { // agrandit le bbox avec la position $pos
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
  }*/
  
  /*function __toString(): string {
    $center = $this->center ? sprintf("[x: %.0f, y: %.0f]", $this->center[0], $this->center[1]) : '[]';
    return sprintf("[west: %.0f, south: %.0f, east: %.0f, north: %.0f, center: %s]",
            $this->min[0], $this->min[1], $this->max[0], $this->max[1], $center);
  }*/
  
  // distance entre 2 boites, nulle ssi les 2 boites sont identiques
  /*function distance(EBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur de GBox::distance() avec une des EBox vide");
    return max(
      abs($b2->min[0] - $this->min[0]),
      abs($b2->min[1] - $this->min[1]),
      abs($b2->max[0] - $this->max[0]),
      abs($b2->max[1] - $this->max[1])
    );
  }*/
  
  /*function geo(string $proj): GdalGBox {
    $min = WorldMercator::geo($this->min);
    $max = WorldMercator::geo($this->max);
    return GBox::createFromDcmiBox([
      'westlimit'=> $min[0], 'southlimit'=> $min[1],
      'eastlimit'=> $max[0], 'northlimit'=> $max[1],
    ]);
  }*/
  function geo(string $proj): GdalGBox {
    $gbox = parent::geo($proj);
    return new GdalGBox([$gbox->west(),$gbox->south(),$gbox->east(),$gbox->north()]);
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

class GdalInfo { // info de géoréférencement d'une image fournie par gdalinfo
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
  
  function gbox(): ?GdalGBox { // retourne le GdalGBox ssi il est défini dans le gdalinfo
    if (!isset($this->info['wgs84Extent']))
      return null;
    else
      return new GdalGBox($this->info['wgs84Extent']);
  }

  // Test de georef correct fondé sur la comparaison entre cornerCoordinates et la projection en WorldMercator de wgs84Extent
  /*function goodGeoref0(bool $debug=false): bool { 
    $ebox = new EBox($this->info['cornerCoordinates']);
    if ($debug)
      echo "  ebox=$ebox\n";
    if ($debug)
      echo "  gbox=",$this->gbox(),"\n";

    foreach(['std','AM+ccInTheWestHemisphere','bboxCoversMoreThan360°'] as $case) {
      switch ($case) {
        case 'std': { // cas standard
          $gbox = $this->gbox();
          break;
        }
        // Si non cela peut venir de la convention pour les images à cheval sur l'antimeridien
        case 'AM+ccInTheWestHemisphere': { // cas particulier 1 où les cornerCoordinates sont définis dans l'hémisphère Ouest
          //if (!$this->gbox()->astrideTheAntimeridian())
          //  return false;

          if ($debug)
            echo "  boite à cheval sur AM\n";
          $gbox = $this->gbox()->translate360West();
          if ($debug)
            echo "  translate360West=$gbox\n";
          break;
        }
        case 'bboxCoversMoreThan360°': { // cas particulier 2 où la boite couvre une largeur de plus de 360° (cas 0101)
          if ($debug)
            echo "  Test boite couvrant une largeur de plus de 360°\n";
          $gbox = $this->gbox()->translateEastBound360East();
          if ($debug)
            echo "  translateEastBound360East=$gbox\n";
          break;
        }
      }
      
      $proj = EBox::fromListOfPos([
        WorldMercator::proj($gbox->min()),
        WorldMercator::proj($gbox->max()),
      ]);
      if ($debug)
        echo "  proj=$proj\n";
      
      $distance = $ebox->distance($proj);
      if ($debug)
        printf("  distance=%.0f\n", $distance);
    
      if ($distance < 1000) // Si la distance est inférieure à 1 km alors géoréférencement ok
        return true;
    }
    return false;
  }*/
  
  // nlle version de goodGeoref() fondée sur la conversion en geo de cornerCoordinates
  // et le calcul de la distance de cette GBox standardisée avec le wgs84Extent standardisé 
  function goodGeoref(bool $debug=false): bool {
    //$debug = true;
    $cornerCoordinates = new GdalEBox($this->info['cornerCoordinates']);
    if ($debug) {
      echo "  cornerCoordinates=$cornerCoordinates\n";
      echo "  cornerCoordinates->geo=",$cornerCoordinates->geo('WorldMercator'),"\n";
      echo "  gbox=",$this->gbox(),"\n";
    }
    $dist = $this->gbox()->std()->distance($cornerCoordinates->geo('WorldMercator')->std());
    if ($debug)
      echo "  dist=$dist degrés\n";
    return ($dist < 1e-2); // équivalent à 1 km
  }
  
  static function testGoodGeoref(string $PF_PATH): void {
    foreach ([
        //"7620-2018c5 - Approches d'Anguilla"=> ['path7z'=> 'archives/7620/7620-2018c5.7z', 'entry'=> '7620/7620_pal300.tif'],
        //"7471-2021c3 - D'Anguilla à St-Barth"=> ['path7z'=> 'archives/7471/7471-2021c3.7z', 'entry'=> '7471/7471_pal300.tif'],
        //"6977 - O. Pacif. N - P. NW /AM"=> ['path7z'=> 'archives/6977/6977-1982c169.7z', 'entry'=> '6977/6977_pal300.tif'],
        //"6835 - Océan Pacifique N. - P. E. /AM"=> ['path7z'=> 'current/6835.7z', 'entry'=> '6835/6835_pal300.tif'],
        //"0101 - Planisphère"=> ['path7z'=> 'current/0101.7z', 'entry'=> '0101/0101_pal300.tif'],
        "7427/1 - Port de Pauillac"=> ['path7z'=> 'archives/7427/7427-2016c13.7z', 'entry'=> '7427/7427_1_gtw.tif'],
      ] as $title => $tif) {
        echo "$title:\n";
        $archive = new My7zArchive("$PF_PATH/$tif[path7z]");
        $gdalInfo = new GdalInfo($path = $archive->extract($tif['entry']));
        $archive->remove($path);
        //print_r($gdalInfo);
        $good = $gdalInfo->goodGeoref(false);
        echo "  ",$good ? 'good' : 'bad',"\n";
        if (!$good) {
          $gdalInfo->goodGeoref(true);
        }
    }
    $am = WorldMercator::proj([180, 0]);
    printf("AM=[x: %.0f, y: %.0f]\n", $am[0],$am[1]);
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
    GdalInfo::testGoodGeoref($PF_PATH);
  }
}