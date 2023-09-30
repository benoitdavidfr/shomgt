<?php
/** définition de la classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
 *
 * journal:
 * - 31/7/2022:
 *   - correction suite à analyse PhpStan level 6
 * - 10/2/2022:
 *   - transformation Exception en SExcept
 * - 5/2/2022:
 *   - ajout Zoom::gboxToTiles() et Zoom::wemboxToTiles()
 * - 9/3/2019:
 *   - scission depuis gegeom.inc.php
 * - 7/3/2019:
 *   - création
 * @package shomgt\lib
 */
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/sexcept.inc.php';

/** classe regroupant l'intelligence autour du tuilage et des niveaux de zoom */
class Zoom {
  const ErrorTooManyTiles = 'Zoom::ErrorTooManyTiles';
  const MaxZoom = 18; // zoom max utilisé notamment pour les points
  /**
   * Size0 est la circumférence de la Terre en mètres utilisée dans la projection WebMercator
   *
   * correspond à 2 * PI * a où a = 6 378 137.0 est le demi-axe majeur de l'ellipsoide WGS 84
   * Size0 est le côté du carré contenant les points en coordonnées WebMercator */
  const Size0 = 20037508.3427892476320267 * 2;
  
  /** taille du pixel en mètres en fonction du zoom */
  static function pixelSize(int $zoom): float { return self::Size0 / 256 / pow(2, $zoom); }
  
  /** niveau de zoom adapté à la visualisation d'une géométrie définie par la taille de son GBox */
  static function zoomForGBoxSize(float $size): int {
    if ($size) {
      $z = log(360.0 / $size, 2);
      //echo "z=$z<br>\n";
      return min(round($z), self::MaxZoom);
    }
    else
      return self::MaxZoom;
  }
  
  /* taille d'un degré en mètres */
  static function sizeOfADegreeInMeters(): float { return self::Size0 / 360.0; }
  
  /** calcule la EBox en coord. WebMercator. de la tuile (z,x,y) */
  static function tileEBox(int $z, int $ix, int $iy): \gegeom\EBox {
    $base = self::Size0 / 2;
    $x0 = - $base;
    $y0 =   $base;
    $size = self::Size0 / pow(2, $z);
    return new \gegeom\EBox([
      $x0 + $size * $ix, $y0 - $size * ($iy+1),
      $x0 + $size * ($ix+1), $y0 - $size * $iy
    ]);
  }
  
  /** calcule les tuiles couvrant un GBox sous la forme d'une liste [['x'=>x, 'y'=>y, 'z'=>z]]
   *
   * Lève une exception en cas d'erreur
   * @return list<array{x: int, y: int, z: int}> */
  static function gboxToTiles(\gegeom\GBox $gbox, int $width, int $height): array {
    //echo "gbox=$gbox\n";
    return self::wemboxToTiles($gbox->proj('WebMercator'), $width, $height);
  }

  /** calcule les tuiles couvrant un EBox en coord. WebMercator sous la forme d'une liste [['x'=>x, 'y'=>y, 'z'=>z]]
   *
   * Lève une exception en cas d'erreur
   * @return list<array{x: int, y: int, z: int}> */
  static function wemboxToTiles(\gegeom\EBox $ebox, int $width, int $height): array {
    //echo "ebox=$ebox, width=$width, height=$height\n";
    $pxSze = ($ebox->dx()/$width + $ebox->dy()/$height) / 2;
    $zoom = log(Zoom::Size0 / $pxSze / 256, 2);
    //echo "zoom=$zoom\n";
    $zoom = min(18, round($zoom));
    if ($zoom <= 0) {
      return [['x'=>0, 'y'=>0, 'z'=>0]];
    }
    //echo "zoom=$zoom\n";
    $nbtuiles  = pow(2, $zoom);
    $tSize = self::Size0 / $nbtuiles;
    //echo "w->",$ebox->west()/$tSize,", e->",$ebox->east()/$tSize,"\n";
    //echo "s->",$ebox->south()/$tSize,", n->",$ebox->north()/$tSize,"\n";
    $xmin = floor($ebox->west()/$tSize) + $nbtuiles/2;
    $xmax = floor($ebox->east()/$tSize) + $nbtuiles/2;
    $ymin = $nbtuiles/2 - ceil($ebox->north()/$tSize);
    $ymax = $nbtuiles/2 - ceil($ebox->south()/$tSize);
    if ((($xmax - $xmin) > 100) || (($ymax - $ymin) > 100)) {
      throw new SExcept (sprintf("trop de tuiles, %d en X et %d en Y", $xmax-$xmin, $ymax-$ymin), self::ErrorTooManyTiles);
    }
    $tiles = [];
    for ($x = intval($xmin); $x <= intval($xmax); $x++) {
      $x2 = $x % $nbtuiles;
      for ($y = intval($ymin); $y <= intval($ymax); $y++) {
        //echo "x=$x, y=$y\n";
        $tiles[] = ['x'=>$x2, 'y'=>$y, 'z'=>$zoom];
      }
    }
    return $tiles;
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Zoom
  require_once __DIR__.'/gebox.inc.php';
  
  if (!isset($_GET['test']))
    echo "<a href='?test=Zoom'>Test unitaire de la classe Zoom</a><br>\n";
  elseif ($_GET['test']=='Zoom') {
    for($zoom=0; $zoom <= 21; $zoom++)
      printf("zoom=%d pixelSize=%.2f m<br>\n", $zoom, Zoom::pixelSize($zoom));
    printf("sizeOfADegree=%.3f km<br>\n", Zoom::sizeOfADegreeInMeters()/1000);
    echo "Zoom::tileEBox(0,0,0)=", Zoom::tileEBox(0,0,0),
         "<br>\n ->geo('WebMercator') -> ", Zoom::tileEBox(0,0,0)->geo('WebMercator'),"<br>\n";
    echo "Zoom::tileEBox(9, 253, 176)=",Zoom::tileEBox(9, 253, 176),
         "<br>\n ->geo('WebMercator') -> ",Zoom::tileEBox(9, 253, 176)->geo('WebMercator'),"<br>\n";
   echo "Zoom::tileEBox(14, 8063, 5731)=",Zoom::tileEBox(14, 8063, 5731),
        "<br>\n ->geo('WebMercator') -> ",Zoom::tileEBox(14, 8063, 5731)->geo('WebMercator'),"<br>\n";
    echo "<h2>Test de Zoom::gboxToTiles()</h2>\n";
    foreach ([
        ['gbox'=> new \gegeom\GBox([[4.5,43.0], [5.1,43.5]]), 'width'=> 1200, 'height'=> 800],
        ['gbox'=> new \gegeom\GBox([[45,-90], [90,0]]), 'width'=> 1200, 'height'=> 800],
        ['gbox'=> new \gegeom\GBox([[0,0], [45,45]]), 'width'=> 1, 'height'=> 1],
        ['gbox'=> new \gegeom\GBox([[0,0], [45,45]]), 'width'=> 100000, 'height'=> 1000000],
      ] as $params) {
        echo "gbox=$params[gbox]<br>\n";
        try {
          $tiles = Zoom::gboxToTiles($params['gbox'], $params['width'], $params['height']);
          foreach ($tiles as $tile) {
            $gbox = Zoom::tileEBox($tile['z'], $tile['x'], $tile['y'])->geo('WebMercator');
            printf("x=%4d,y=%4d,z=%2d, gbox=%s<br>\n", $tile['x'], $tile['y'], $tile['z'], $gbox);
          }
        }
        catch (SExcept $e) {
          echo "Exception ",$e->getMessage(),"<br>\n";
        }
    }
    die("Fin\n");
  }
}
