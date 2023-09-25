<?php
/** Définition de la classe GeoRefImage pour gérer une image géoréférencée
 *
 * Ajout à une ressource [GD](https://www.php.net/manual/fr/book.image.php)
 *  - d'un espace en coordonnées utilisateurs, typiquement un système de coordonnées projeté comme WorldMercator
 *  - d'une notion de style inspiré de Leaflet pour dessiner des polylignes et des polygones
 * Il serait logique d'étendre cette notion de style aux écritures et aux rectangles.
 *
 * journal: |
 * - 28/7/2022:
 *   - correction suite à analyse PhpStan level 4
 * - 8-9/7/2022:
 *   - ajout classe Style
 *   - suppression méthode GeoRefImage::filledpolygon()
 *   - modification des paramètres des méthodes GeoRefImage::polygon() et GeoRefImage::polyline()
 * - 10/6/2022:
 *   - revue de code
 * - 27/4/2022:
 *   - ajout GeoRefImage::imagefilledpolygon()
 * - 26/4/2022:
 *   - ajout GeoRefImage::imagefilledrectangle()
 * - 22/4/2022:
 *   - création
 * @package shomgt\lib
 */
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/sexcept.inc.php';
require_once __DIR__.'/gebox.inc.php';

/** classe portant le méthode getHttpError() */
class Http {
  /** retourne le code Http et le message d'erreur retourné
   * @return array<string, string> */
  static function getHttpError(string $url): array {
    $context = stream_context_create(['http'=> ['ignore_errors'=> true]]); 
    $message = file_get_contents($url, false, $context);
    $httpCode = substr($http_response_header[0], 9, 3);
    return [
      'httpCode'=> $httpCode,
      'message'=> $message,
    ];
  }
};

/** Définition d'un Style de représentation graphique des objets vecteurs inspiré de celui utilisé par Leaflet */
class Style {
  /** titre du style */
  protected string $title;
  /** couleur de trait comme array RVB, si absent pas de couleur de trait */
  protected ?int $color;
  /** épaissur du trait, 1 par défaut */
  protected int $weight;
  /** // couleur de remplissage comme array RVB, si absent pas de remplissage */
  protected ?int $fillColor;

  /** @param array<string, int|float|array<int, int>> $style */
  function __construct(array $style, GeoRefImage $grImage) {
    $this->title = $style['title'] ?? "No title";
    $this->color = isset($style['color']) ? $grImage->colorallocate($style['color']) : null;
    $this->weight = $style['weight'] ?? 1;
    if (!isset($style['fillColor'])) {
      $this->fillColor = null;
    }
    else {
      $alpha = round((1 - ($style['fillOpacity'] ?? 0.2)) * 127);
      $style['fillColor'][3] = $alpha;
      $this->fillColor = $grImage->colorallocatealpha($style['fillColor']);
    }
  }
  
  function weight(): int { return $this->weight; }
  function color(): ?int { return $this->color; }
  function fillColor(): ?int { return $this->fillColor; }
};

/** représente une image géo-référencée
 *
 * Une image géoréférencée est l'association d'une image GD et d'un EBox.
 * Les opérations GD peuvent ainsi être effectuées en coordonnées utilisateur plutôt qu'en coordonnées image
 * Les coordonnées utilisateur (X,Y) sont définies avec X de gauche à droite et Y du bas vers le haut.
*/
class GeoRefImage {
  const ErrorCreate = 'GeoRefImage::ErrorCreate';
  const ErrorCreateFromPng = 'GeoRefImage::ErrorCreateFromPng';
  const ErrorCopy = 'GeoRefImage::ErrorCopy';
  const ErrorColorAllocate = 'GeoRefImage::ErrorColorAllocate';
  const ErrorFilledRectangle = 'GeoRefImage::ErrorFilledRectangle';
  const ErrorRectangle = 'GeoRefImage::ErrorRectangle';
  const ErrorPolyline = 'GeoRefImage::ErrorPolyline';
  const ErrorPolygon = 'GeoRefImage::ErrorPolygon';
  const ErrorFilledPolygon = 'GeoRefImage::ErrorFilledPolygon';
  const ErrorDrawString = 'GeoRefImage::ErrorDrawString';
  const ErrorSaveAlpha = 'GeoRefImage::ErrorSaveAlpha';

  protected \gegeom\EBox $ebox; // EBox en coordonnées dans un CRS comme World Mercator
  protected ?GdImage $image=null; // la ressource image

  function ebox(): \gegeom\EBox { return $this->ebox; }
  function image(): ?GdImage { return $this->image; }

  function __construct(\gegeom\EBox $ebox, ?GdImage $image=null) { $this->ebox = $ebox; $this->image = $image; }

  /** création d'une image vide avec un fond soit transparent soit blanc */
  function create(int $width, int $height, bool $transparent): void {
    $this->image = @imagecreatetruecolor($width, $height)
      or throw new SExcept("Erreur dans imagecreatetruecolor() pour GeoRefImage::create($width, $height)", self::ErrorCreate);
    if ($transparent) {
      @imagealphablending($this->image, false)
        or throw new SExcept("Erreur dans imagealphablending(false) pour GeoRefImage::imagecreate()", self::ErrorCreate);
      $transparent = @imagecolorallocatealpha($this->image, 0xFF, 0xFF, 0xFF, 0x7F);
      if ($transparent === false)
        throw new SExcept("Erreur dans imagecolorallocatealpha(image, 0xFF, 0xFF, 0xFF, 0x7F)", self::ErrorColorAllocate);
      @imagefilledrectangle($this->image, 0, 0, $width, $height, $transparent)
        or throw new SExcept("erreur de imagefilledrectangle()", self::ErrorFilledRectangle);
      @imagealphablending($this->image, true)
        or throw new SExcept("Erreur dans imagealphablending(true) pour GeoRefImage::imagecreate()", self::ErrorCreate);
    }
    else {
      $white = @imagecolorallocate($this->image, 0xFF, 0xFF, 0xFF);
      if ($white === false)
        throw new SExcept("Erreur dans imagecolorallocatealpha(image, 0xFF, 0xFF, 0xFF)", self::ErrorColorAllocate);
      @imagefilledrectangle($this->image, 0, 0, $width, $height, $white)
        or throw new SExcept("erreur de imagefilledrectangle()", self::ErrorFilledRectangle);
    }
  }

  /** chargement de l'image à partir d'un fichier ou d'une URL */
  function createfrompng(string $filename): void {
    if (!($image = @imagecreatefrompng($filename))) {
      if (preg_match('!^https?://!', $filename)) {
        $httpError = Http::getHttpError($filename);
        $message = sprintf('Erreur sur "%s" : httpCode=%d, message="%s"',
            $filename, $httpError['httpCode'], $httpError['message']);
        throw new SExcept($message, self::ErrorCreateFromPng);
      }
      else {
        throw new SExcept("Erreur d'ouverture du fichier $filename", self::ErrorCreateFromPng);
      }
    }
    $this->image = $image;
  }

  /** transforme en coordonnées image une position en coordonnées utilisateur
  * @param TPos $uPos
  * @return array<int, int>
  */
  function toImgPos(array $uPos, string $debug): array {
    if ($debug) echo "GeoRefImage::toImgPos($uPos[0], $uPos[1])@$debug<br>\n";
    return [
      intval(round(($uPos[0] - $this->ebox->west()) / ($this->ebox->east() - $this->ebox->west()) * imagesx($this->image))),
      intval(round(($this->ebox->north() - $uPos[1]) / ($this->ebox->north() - $this->ebox->south()) * imagesy($this->image)))
    ];
  }

  /** transforme en coordonnées utilisateur une position en coordonnées image
  * @param array<int, int> $imgPos
  * @return TPos
  */
  function toUserPos(array $imgPos): array {
    return [
      $this->ebox->west() + $imgPos[0] / imagesx($this->image) * ($this->ebox->east() - $this->ebox->west()),
      $this->ebox->north() - $imgPos[1] / imagesy($this->image) * ($this->ebox->north() - $this->ebox->south())
    ];
  }

  /** recopie la partie de $srcImg correspondant à $qebox dans la zone de $this correspondant à $qebox
   * @param \gegeom\EBox  $qebox  en coordonnées utilisateur */
  function copyresampled(GeoRefImage $srcImg, \gegeom\EBox $qebox, bool $debug): void {
    $sw = [$qebox->west(), $qebox->south()]; // position SW de $qebox en coord. utilisateur
    $ne = [$qebox->east(), $qebox->north()]; // position NE de $qebox en coord. utilisateur
    $sw_dst = $this->toImgPos($sw, $debug ? 'sw_dst' : ''); if ($debug) echo "sw_dst=$sw_dst[0],$sw_dst[1]<br>\n";
    $ne_dst = $this->toImgPos($ne, $debug ? 'ne_dst' : ''); if ($debug) echo "ne_dst=$ne_dst[0],$ne_dst[1]<br>\n";
    $sw_src = $srcImg->toImgPos($sw, $debug ? 'sw_src' : ''); if ($debug) echo "sw_src=$sw_src[0],$sw_src[1]<br>\n";
    $ne_src = $srcImg->toImgPos($ne, $debug ? 'ne_src' : ''); if ($debug) echo "ne_src=$ne_src[0],$ne_src[1]<br>\n";
    // si une des largeurs ou hauteurs est nulle ou négative alors pas de copie
    if (($ne_dst[0] <= $sw_dst[0]) || ($sw_dst[1] <= $ne_dst[1]) || ($ne_src[0] <= $sw_src[0]) || ($sw_src[1] <= $ne_src[1]))
      return;
    @imagecopyresampled(
      $this->image, // GdImage $dst_image,
      $srcImg->image(), // GdImage $src_image,
      $sw_dst[0], // int $dst_x,
      $ne_dst[1], // int $dst_y,
      $sw_src[0], // int $src_x,
      $ne_src[1], // int $src_y,
      $ne_dst[0] - $sw_dst[0], // int $dst_width,
      $sw_dst[1] - $ne_dst[1], // int $dst_height,
      $ne_src[0] - $sw_src[0], // int $src_width,
      $sw_src[1] - $ne_src[1]) // int $src_height)
        or throw new SExcept("Erreur dans imagecopyresampled()", self::ErrorCopy);
  }

  // Utilisé pour debug
  /*private function copyresampledDebug(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $dst_width, int $dst_height, int $src_width, int $src_height, bool $debug): bool {
    if ($debug)
      printf("<pre>imagecopyresampled(dst_image, src_image, dst_x=%d, dst_y=%d, src_x=%d, src_y=%d, "
        ."dst_width=%d, dst_height=%d, src_width=%d, src_height=%d)</pre>\n",
        $dst_x, $dst_y, $src_x, $src_y, $dst_width, $dst_height, $src_width, $src_height);
    return imagecopyresampled($dst_image, $src_image,
        $dst_x, $dst_y, $src_x, $src_y, $dst_width, $dst_height, $src_width, $src_height);
  }*/
  
  /** Alloue une couleur pour l'image
   * @param list<int> $rvb  RVB comme liste de 3 entiers */
  function colorallocate(array $rvb): int {
    $color = @imagecolorallocate($this->image, $rvb[0], $rvb[1], $rvb[2]);
    if ($color === false)
      throw new SExcept("Erreur dans imagecolorallocate(image, $rvb[0], $rvb[1], $rvb[2])", self::ErrorColorAllocate);
    return $color;
  }

  /** Alloue une couleur avec alpha pour l'image
   * @param list<int> $rvba  RVBA comme liste de 4 entiers */
  function colorallocatealpha(array $rvba): int {
    $color = @imagecolorallocatealpha($this->image, $rvba[0], $rvba[1], $rvba[2], $rvba[3]);
    if ($color === false)
      throw new SExcept("Erreur dans imagecolorallocate(i, $rvba[0], $rvba[1], $rvba[2], $rvba[3])", self::ErrorColorAllocate);
    return $color;
  }
  
  /** nécessaire pour conserver le canal alpha avant de générer l'image PNG avec le paramètre true ; 
   * Le alphablending doit être désactivé (imagealphablending($im, false)) pour conserver le canal alpha en premier lieu. */
  function savealpha(bool $enable): void {
    @imagesavealpha($this->image, $enable)
      or throw new SExcept("Erreur imageSaveAlpha", self::ErrorSaveAlpha);
  }
  
  /** Dessine le rectangle en le remplissant avec la couleur */
  function filledrectangle(\gegeom\EBox $rect, int $color): void {
    $nw = $this->toImgPos([$rect->west(), $rect->north()], '');
    $se = $this->toImgPos([$rect->east(), $rect->south()], '');
    @imagefilledrectangle($this->image, $nw[0], $nw[1], $se[0], $se[1], $color)
      or throw new SExcept("erreur de imagefilledrectangle()", self::ErrorFilledRectangle);
  }
  
  /** Dessine le rectangle dans la couleur */
  function rectangle(\gegeom\EBox $rect, int $color): void {
    $nw = $this->toImgPos([$rect->west(), $rect->north()], '');
    $se = $this->toImgPos([$rect->east(), $rect->south()], '');
    @imagerectangle($this->image, $nw[0], $nw[1], $se[0], $se[1], $color)
      or throw new SExcept("erreur de imagerectangle()", self::ErrorRectangle);
  }
  
  /** Dessine dans le style une polyligne définie par une liste de positiions
   * @param TLPos $lpos */
  function polyline(array $lpos, Style $style): void {
    if (($color = $style->color()) === null)
      return;
    imagesetthickness($this->image, $style->weight());
    $precpos = null;
    foreach ($lpos as $i => $pos) {
      $pos = $this->toImgPos($pos, '');
      if ($precpos) {
        @imageline($this->image, $precpos[0], $precpos[1], $pos[0], $pos[1], $color)
          or throw new SExcept("erreur de imageline()", self::ErrorPolyline);
      }
      $precpos = $pos;
    }
  }
  
  /** Dessine dans le style le polygone défini par une liste de positions
   * @param TLPos $lpos */
  function polygon(array $lpos, Style $style): void {
    $points = [];
    foreach ($lpos as $i => $pos) {
      $pos = $this->toImgPos($pos, '');
      $points[2*$i] = $pos[0];
      $points[2*$i+1] = $pos[1];
    }
    if (($fcolor = $style->fillColor()) !== null) {
      @imagefilledpolygon($this->image, $points, $fcolor)
        or throw new SExcept("erreur de imagefilledpolygon()", self::ErrorFilledPolygon);
    }
    if (($color = $style->color()) !== null) {
      imagesetthickness($this->image, $style->weight());
      @imagepolygon($this->image, $points, $color)
        or throw new SExcept("erreur de imagepolygon()", self::ErrorPolygon);
    }
  }
  
  /** Dessine une chaine de caractère à une position en coord. utilisateur dans la fonte $font, la couleur $text_color
   * avec un cadre de fond de plan dans la couleur $bg_color
   * @param TPos $pos */
  function string(GdFont|int $font, array $pos, string $string, int $text_color, int $bg_color, bool $debug): void {
    $pos = $this->toImgPos($pos, '');
    
    $dx = strlen($string) * imagefontwidth($font) + 2;
    $dy = imagefontheight($font);
    if ($debug)
      printf("imagefilledrectangle(image, x1=%d, y1=%d, x2=%d, y2=%d, color=%d)<br>\n",
        $pos[0], $pos[1], $pos[0]+$dx, $pos[1]+$dy, $bg_color);
    //imagefilledrectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    @imagefilledrectangle($this->image, $pos[0], $pos[1], $pos[0]+$dx, $pos[1]+$dy, $bg_color)
      or throw new SExcept("erreur de imagestring()", self::ErrorDrawString);
    
    @imagestring($this->image, $font, $pos[0]+2, $pos[1], $string, $text_color)
      or throw new SExcept("erreur de imagestring()", self::ErrorDrawString);
  }
};


// Code de test unitaire de cette classe
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


$gtImg = new GeoRefImage(new \gegeom\EBox([[0,0],[1000,1000]]));
$gtImg->create(1200, 600, false);
$gtImg->polygon(
  [[50,50],[50,500],[500,500]],
  new Style(['color'=> [255, 64, 64], 'weight'=> 2, 'fillColor'=> [255, 0, 0], 'fillOpacity'=> 0.3], $gtImg)
);
$gtImg->polygon(
  [[100,100],[50,500],[800,200]],
  new Style(['color'=> [0, 0, 255], 'weight'=> 2, 'fillColor'=> [0, 0, 255], 'fillOpacity'=> 0.3], $gtImg)
);
$black = $gtImg->colorallocate([0,0,0]);
$white = $gtImg->colorallocate([255,255,255]);
$gtImg->string(font: 3, pos: [50,50], string: "position [50,50]", text_color: $black, bg_color: $white, debug: false);
$gtImg->string(font: 3, pos: [500,500], string: "position [500,500]", text_color: $black, bg_color: $white, debug: false);
$gtImg->string(font: 3, pos: [800,200], string: "position [800,200]", text_color: $black, bg_color: $white, debug: false);
/*$gtImg->polygon(
  [[100,100],[50,500],[800,200]],
  new Style(['fillColor'=> [0, 0, 255], 'fillOpacity'=> 0.1], $gtImg)
);
$gtImg->polygon(
  [[100,100],[50,500],[800,200]],
  new Style(['color'=> [0, 0, 255], 'weight'=> 2], $gtImg)
);*/

// génération de l'image
$gtImg->savealpha(true);
header('Content-type: image/png');
imagepng($gtImg->image());
