<?php
/*PhpDoc:
name: errortile.inc.php
title: errortile.inc.php - Génération d'une image d'erreur contenant le message d'erreur et l'identifiant de la tuile
classes:
doc: |
journal: |
  28/7/2022:
    - correction suite à analyse PhpStan level 4
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

// en cas d'erreur dans la génération
function error(string $message) { die($message); }

// Création et affichage d'une image d'erreur
function sendErrorTile(string $tileid, string $message, string $symbol='undef', string $color='FF0000', int $width=128, int $height=128): never {
  if (isset($_GET['output']) && ($_GET['output']=='txt')) {
    header('Content-type: text/plain; charset="utf-8"');
    die($message);
  }
  $im = imagecreatetruecolor (2*$width, 2*$height)
    or error("Erreur imagecreatetruecolor");
  // passage en blending FALSE pour copier un fond transparent
  imagealphablending($im, FALSE)
    or error("Erreur sur imagealphablending(FALSE)");
  // création de la couleur transparente
  $transparent = imagecolorallocatealpha($im, 0xFF, 0xFF, 0xFF, 0x7F)
    or error("Erreur sur imagecolorallocatealpha");
  // remplissage de l'image par la couleur transparente
  imagefilledrectangle ($im, 0, 0, 2*$width-1, 2*$height-1, $transparent)
    or error("Erreur sur imagefilledrectangle");
  // passage en blending TRUE pour copier normalement
  imagealphablending($im, TRUE)
    or error("Erreur sur imagealphablending(TRUE)");

  $grey = imagecolorallocatealpha($im, 0x40, 0x40, 0x40, 0x40)
    or error("Erreur sur imagecolorallocatealpha");

  $chex = hexdec($color);
  $color = imagecolorallocatealpha($im, ($chex >> 16) & 0xFF, ($chex >> 8) & 0xFF, $chex&0xFF, 0)
    or error("Erreur sur imagecolorallocatealpha(".(($chex>>16)&0xFF).",".(($chex>>8)&0xFF).",".($chex&0xFF).")");

  if ($symbol=='circle') {
    // ombre grise transparente décalée
    imagefilledellipse($im, $width*1.2, $height*1.3, $width, $height, $grey)
      or error("Erreur imagefilledellipse");
    imagefilledellipse($im, $width, $height, $width, $height, $color)
      or error("Erreur imagefilledellipse");
  }
  elseif ($symbol=='square') {
    // ombre grise transparente décalée
    imagefilledrectangle($im, $width/2+$width*0.2, $height/2+$height*0.3, 3*$width/2+$width*0.3, 3*$height/2+$height*0.3, $grey)
      or error("Erreur imagefilledrectangle");
    imagefilledrectangle($im, $width/2, $height/2, 3*$width/2, 3*$height/2, $color)
      or error("Erreur imagefilledrectangle");
  }
  elseif ($symbol=='diam') {
    $points = [
      $width/2, $height,
      $width, $height/2,
      $width*1.5, $height,
      $width, $height*1.5,
    ];
    $offset = []; // Pts de décalés de 3 pixels en X et Y
    for ($i=0; $i<count($points)/2; $i++) {
      $offset[2*$i] = $points[2*$i] + $width*0.15;
      $offset[2*$i+1] = $points[2*$i+1] + $height*0.3;
    }
    // ombre grise transparente décalée
    imagefilledpolygon($im, $offset, count($offset)/2, $grey)
      or error("Erreur imagefilledpolygon");
    imagefilledpolygon($im, $points, count($points)/2, $color)
      or error("Erreur imagefilledpolygon");
  }
  else { // symbole inconnu => rond coloré avec rectangle blanc
    $frame_color = imagecolorallocate($im, 0, 0, 0);
    imagerectangle($im, 0, 0, 2*$width-1, 2*$height-1, $frame_color)
      or error("Erreur imagerectangle");
    // ombre grise transparente décalée
    imagefilledellipse($im, round($width*1.3), round($height*1.3), $width, $height, $grey)
      or error("Erreur imagefilledellipse");
    // grand rond opaque
    imagefilledellipse($im, $width, $height, $width, $height, $color)
      or error("Erreur imagefilledellipse");
    // petit rectangle blanc
    $white = imagecolorallocatealpha($im, 0xFF, 0xFF, 0xFF, 0)
      or error("Erreur sur imagecolorallocatealpha");
    imagefilledrectangle($im, round(0.7*$width), round(0.9*$height), round(1.3*$width), round(1.1*$height), $white)
      or error("Erreur imagefilledrectangle");
  }
  $text_color = imagecolorallocate($im, 0, 0, 0);
  imagestring($im, 4, 5, 5, $tileid, $text_color);
  $i = 0;
  while (strlen($message) > $i*30) {
    imagestring($im, 4, 5, 25 + $i*16, substr($message, $i*30, 30), $text_color);
    $i++;
  }
  // Affichage de l'image
  imagealphablending($im, FALSE)
    or error("erreur sur imagealphablending(FALSE)");
  imagesavealpha($im, TRUE)
    or error("erreur sur imagesavealpha(TRUE)");
  header('Content-type: image/png');
  imagepng($im);
  imagedestroy($im);
  die();
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la fonction sendErrorTile()
  sendErrorTile("id", "message d'erreur");
}