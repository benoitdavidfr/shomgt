<?php
/** fourniture d'un gdalinfo d'un fichier .tif ou .pdf - 3/8/2023 */
namespace bo;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
#require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/my7zarchive.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Analyse un GBox fourni par gdalinfo comme polygone GeoJSON pour déterminer s'il intersecte ou non l'antiméridien
 *
 * On fait l'hypothèse que le polygone respecte la règle GeoJSON d'orientation inverse des aiguilles d'une montre
 * Par contre, on fait aussi l'hypothèse que le polygone ne respecte pas la règle GeoJSON de dédoublage des objets
 * intersectant l'antiméridien.
 * Ces hypothèses ont été vérifiées sur de nombreuses cartes.
 * Détecte les # des segments NS et WE
 * Si le segment WE suit le segment NS alors le GBox n'intersecte pas l'AM
 * A l'inverse si ce n'est pas le cas alors GBox intersecte l'AM
 * NE TRAITE PAS TOUS LES CAS DE FIGURE POSSIBLE
 */
readonly class GBoxAsPolygon {
  /** en degrés soit environ 1 km */
  const EPSILON = 1e-2;
  /** @var TLLPos $coords; */
  public array $coords;
  
  /** @param TGJPolygon $param; */
  function __construct(array $param) {
    if (($param['type'] ?? null) <> 'Polygon')
      throw new \Exception("type <> 'Polygon'");
    if (!\gegeom\LLPos::is($param['coordinates']))
      throw new \Exception("coordinates n'est pas un LLPos");
    if (count($param['coordinates'][0]) <> 5)
      throw new \Exception("coordinates n'a pas 5 positions, il y en a ".count($param['coordinates'][0] ?? []));
    $this->coords = $param['coordinates'];
  }
  
  /** numéro du segment Nord->Sud */
  function NSs(): int {
    for($i=0; $i <= 3; $i++) {
      // même X et $i.Y > $i+1.Y
      if ((abs($this->coords[0][$i][0] - $this->coords[0][$i+1][0]) < self::EPSILON)
         && ($this->coords[0][$i][1] > $this->coords[0][$i+1][1]))
        return $i;
    }
    throw new \Exception("numéro du segment Nord->Sud non trouvé");
  }
  
  /** numéro du segment West->Est */
  function WEs(): int {
    for($i=0; $i <= 3; $i++) {
      // même Y et $i.X < $i+1.X
      if ((abs($this->coords[0][$i][1] - $this->coords[0][$i+1][1]) < self::EPSILON)
         && ($this->coords[0][$i][0] < $this->coords[0][$i+1][0]))
        return $i;
    }
    throw new \Exception("numéro du segment West->Est non trouvé");
  }
  
  /** indique si le polygone intersecte ou non l'anti-méridien */
  function crossesTheAM(bool $verbose=false): bool {
    if ($verbose) {
      echo "#NSs=",$this->NSs(),"<br>\n";
      echo "#WEs=",$this->WEs(),"<br>\n";
    }
    if ($this->WEs() - $this->NSs() == 1) {
      if ($verbose)
        echo "DONT crosses the AM\n";
      return false;
    }
    else {
      if ($verbose)
        echo "crosses the AM\n";
      return true;
    }
  }
  
  /** position du coin SW, c'est la fin du segment NS
   * @return TPos */
  function SWc(): array {
    $ins = $this->NSs();
    return $this->coords[0][$ins+1];
  }
  
  /** position du coin NE, c'est l'indice du point NS + 3 modulo 4
   * @return TPos */
  function NEc(): array {
    $ins = $this->NSs();
    $ine = $ins + 3;
    if ($ine >= 4)
      $ine -= 4;
    return $this->coords[0][$ine];
  }
};

/** Ajout de fonctionnalités à \gegeom\GBox
 *
 * Par convention, on cherche à respecter:
 *   (-180 <= lon <= 180) && (-90 <= lat <= 90)
 * sauf pour les boites à cheval sur l'antiméridien où:
 *   (-180 <= lonmin <= 180 < lonmax <= 180+360 )
 * et sauf pour les boites qui couvrent la totalité de la Terre en longitude.
 * Cette convention est différente de celle utilisée par GeoJSON.
 * Toutefois, uGeoJSON génère des bbox avec des coord. qqc, y compris lonmin < -180
 * 
 * J'ajoute à GBox la possibilité de création à partir du polygone GeoJSON dans wgs84extent
 * 
 * Je considère au final qu'un GBox standardisé respecte les 2 contraintes ci-dessus.
 *
 * On essaie ici de réutiliser \gegeom\GBox et \gegeom\EBox en en créant des sous-classes GBox et EBox
 * pour leur ajouter des fonctionalités.
 * Pour cela les règles à respecter sont le suivantes:
 *  - ne pas redéfinir __construct() avec une signature incompatible avec celle du parent car certaines méthodes
 *    comme BBox::round() par exemple utilisent le __construct() de ses enfants.
 *    - la signature peut par contre être étendue à de nouvelles possibilités
 *  - redéfinir les méthodes comme EBox::geo() car j'ai besoin qu'elle renvoie un GBox
 */
class GBoxBo extends \gegeom\GBox { 
  /* @var TPos $min */
  //public readonly array $min; // [number, number] ou []
  /* @var TPos $max */
  //public readonly array $max; // [number, number] ou [], [] ssi $min == []
  
  /** @param string|TPos|TLPos|TLLPos|TGJPolygon $param */
  function __construct(array|string $param=[]) {
    if (isset($param['coordinates'])) { // cas où le paramètre est un polygone GeoJSON
      $gbAsPol = new GBoxAsPolygon($param);
      $min = $gbAsPol->SWc();
      $max = $gbAsPol->NEc();
      if ($gbAsPol->crossesTheAM())
        $max[0] += 360;
      parent::__construct([$min, $max]);
    }
    else {
      parent::__construct($param);
    }
  }
  
  /** standardisation, cad respectant les conventions */
  function std(): self {
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

/** extension de EBox avec possibilité de création à partir du champ cornerCoordinates de gdalinfo
 *
 * et création d'un GBox par déprojection du EBox */
class EBoxBo extends \gegeom\EBox {
  //protected array $min=[]; // position SW en proj
  //protected array $max=[]; // position NE en proj
  
  /** @var TPos $center */
  protected array $center=[]; // position centre
  
  /** @param string|TPos|TLPos|TLLPos|array<string, array<int, number>> $param */
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
  
  function geo(string $proj): GBoxBo {
    $gbox = parent::geo($proj);
    return new GBoxBo([$gbox->west(),$gbox->south(),$gbox->east(),$gbox->north()]);
  }
};

/**  contenu du gdalinfo d'un fichier .tif ou .pdf.
 * Un fichier peut être géoréférencé, non géoréférencé ou mal géoréférencé.
 * Il est géoréférencé ssi les champs coordinateSystem, cornerCoordinates et wgs84Extent sont définis.
 * Il est mal géoréférencé ssi son géoréférencement est erroné.
 * Voir la définition de mal géoréférencé dans goodGeoref()
*/
readonly class GdalInfoBo { // info de géoréférencement d'une image fournie par gdalinfo
  /** @var array<string, mixed> $info */
  public array $info; // contenu du gdalinfo
  
  function __construct(string $path) {
    $cmde = "gdalinfo -json $path";
    //echo "$cmde<br>\n";
    exec($cmde, $output, $retval);
    //echo "<pre>"; print_r($output); echo "retval=$retval</pre>\n";
    $this->info = json_decode(implode("\n", $output), true);
  }
  
  /** @return array<string, mixed> */
  function asArray(): array { return $this->info; }
  
  /** indique si le géoréférencement est absent, correct ou incorrect.
   * retourne
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
  
  /** retourne le GBox ssi il est défini dans le gdalinfo */
  function gbox(): ?GBoxBo {
    if (!isset($this->info['wgs84Extent']))
      return null;
    else {
      return new GBoxBo($this->info['wgs84Extent']);
    }
  }

  /** Teste si le géoréférencement fourni par gdalinfo est correct.
   * Le principe est fondé sur la compraison entre wgs84Extent et la conversion en geo de cornerCoordinates */
  function goodGeoref(bool $debug=false): bool {
    //$debug = true;
    $cornerCoordinates = new EBoxBo($this->info['cornerCoordinates']);
    if ($debug) {
      echo "  cornerCoordinates=$cornerCoordinates\n";
      echo "  cornerCoordinates->geo=",$cornerCoordinates->geo('WorldMercator'),"\n";
      echo "  gbox=",$this->gbox(),"\n";
    }
    if (!$this->gbox()) {
      return false;
    }
    $dist = $this->gbox()->std()->distance($cornerCoordinates->geo('WorldMercator')->std());
    if ($debug) {
      echo "  dist=$dist degrés ";
      if ($dist < 1e-2)
        echo "< 1e-2 => good\n";
      else
        echo "> 1e-2 => bad\n";
    }
    return ($dist < 1e-2); // équivalent à 1 km
  }
  
  /** Test de goodGeoref() */
  static function testGoodGeoref(string $PF_PATH): void {
    foreach ([
        "7620-2018c5 - Approches d'Anguilla"=> ['path7z'=> 'archives/7620/7620-2018c5.7z', 'entry'=> '7620/7620_pal300.tif'],
        "7471-2021c3 - D'Anguilla à St-Barth"=> ['path7z'=> 'archives/7471/7471-2021c3.7z', 'entry'=> '7471/7471_pal300.tif'],
        "6977 - O. Pacif. N - P. NW /AM"=> ['path7z'=> 'archives/6977/6977-1982c169.7z', 'entry'=> '6977/6977_pal300.tif'],
        "6835 - Océan Pacifique N. - P. E. /AM"=> ['path7z'=> 'current/6835.7z', 'entry'=> '6835/6835_pal300.tif'],
        "0101 - Planisphère"=> ['path7z'=> 'current/0101.7z', 'entry'=> '0101/0101_pal300.tif'],
        "7427/1 - Port de Pauillac"=> ['path7z'=> 'archives/7427/7427-2016c13.7z', 'entry'=> '7427/7427_1_gtw.tif'],
      ] as $title => $tif) {
        echo "$title:\n";
        $archive = new My7zArchive("$PF_PATH/$tif[path7z]");
        $gdalInfo = new GdalInfoBo($path = $archive->extract($tif['entry']));
        $archive->remove($path);
        //print_r($gdalInfo);
        $good = $gdalInfo->goodGeoref(false);
        echo "  ",$good ? 'good' : 'bad',"\n";
        if (!$good) {
          $gdalInfo->goodGeoref(true);
        }
    }
    $am = \coordsys\WorldMercator::proj([180, 0]);
    printf("AM=[x: %.0f, y: %.0f]\n", $am[0],$am[1]);
  }
  
  static function test(string $path7z, string $entry): void {
    $archive = new My7zArchive($path7z);
    $gdalInfo = new GdalInfoBo($path = $archive->extract($entry));
    $archive->remove($path);
    //print_r($gdalInfo);
    echo 'georef = ',$gdalInfo->georef(),"\n";
  }
};


// Tests

switch ($mode = callingThisFile(__FILE__)) {
  case null: return;
  case 'web': { // sélection d'un fichier tif dans une archive 7z
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    $rpath = $_GET['rpath'] ?? '';
    if ($entry = $_GET['entry'] ?? null) {
      $archive = new My7zArchive($PF_PATH.$rpath);
      $gdalInfo = new GdalInfoBo($path = $archive->extract($entry));
      $archive->remove($path);
      //echo "<pre>"; print_r($gdalInfo);
      echo "<pre>",Yaml::dump(['wgs84Extent'=> $gdalInfo->info['wgs84Extent']], 6),"</pre>";
      try {
        $gbasp = new GBoxAsPolygon($gdalInfo->info['wgs84Extent']);
        echo $gbasp->crossesTheAM(true) ? "Crosses the AM<br>\n" : '';
        echo 'gbox=',$gdalInfo->gbox(),"<br>\n";
        echo "<pre>goodGeoref:\n";
        $gdalInfo->goodGeoref(true);
      }
      catch (\Exception $e) {
        echo "<b>Exception \"".$e->getMessage()."\" dans GBoxAsPolygon</b><br>\n";
      }
    }
    elseif (!is_file($PF_PATH.$rpath) && is_dir($PF_PATH.$rpath)) {
      foreach (new \DirectoryIterator($PF_PATH.$rpath) as $entry) {
        if (in_array($entry, ['.','..','.DS_Store'])) continue;
        echo "<a href='?rpath=$rpath/$entry'>$entry</a><br>\n";
      }
    }
    elseif (is_file($PF_PATH.$rpath) && substr($rpath, -3)=='.7z') {
      //echo "$rpath is .7z file<br>\n";
      $archive = new My7zArchive($PF_PATH.$rpath);
      foreach ($archive as $entry) {
        if (substr($entry['Name'], -4) == '.tif')
          echo "<a href='?rpath=$rpath&entry=$entry[Name]'>$entry[Name]<br>\n";
      }
    }
    elseif (is_file($PF_PATH.$rpath) && substr($rpath, -5)=='.json') {
      header('Content-type: application/json; charset="utf-8"');
      die (file_get_contents($PF_PATH.$rpath));
    }
    else {
      echo "$rpath ni dir, ni .7z, ni .json<br>\n";
    }
    break;
  }
  case 'cli': {
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    GdalInfoBo::testGoodGeoref($PF_PATH);
    break;
  }
  default: die("mode '$mode' inconnu");
}
