<?php
/* clonedatamaps.php - crée dans sgpp/data/maps un clone de shomgt/data/maps - 8/8/2023
** Clone shomgt/data/maps dans sgpp/data/maps en créant des répertoires et des liens durs pour les fichiers
** Sur localhost utilise des répertoires différents.
*/
//require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';

//use Symfony\Component\Yaml\Yaml;

if (!($login = Login::login())) {
  die("Accès non autorisé\n");
}

echo "<!DOCTYPE html><html><head><title>clonedatamaps@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo "<h2>Clonage de shomgt/data/maps dans sgpp/data/maps</h2>\n";

switch($_SERVER['HTTP_HOST'] ?? null) {
  case 'localhost': {
    echo "mode dev<br>\n";
    $srcPath = __DIR__.'/../data/maps';
    $clonePath = __DIR__.'/../data/maps-clone';
    break;
  }
  case 'sgpp.geoapi.fr': {
    $clonePath = __DIR__.'/../data/maps'; // le data/maps de sgpp
    $srcPath = __DIR__.'/../../shomgt/data/maps'; // le data/maps de shomgt
    break;
  }
  default: {
    die("HTTP_HOST = $_SERVER[HTTP_HOST]");
  }
}

if (is_dir($clonePath)) {
  die("Erreur le répertoire $clonePath existe\n");
}
if (!mkdir($clonePath)) {
  die("Erreur de création du répertoire $clonePath\n");
}
if (!is_dir($srcPath)) {
  die("Erreur le répertoire $srcPath n'existe pas\n");
}

// Retourne null ssi OK, un message d'erreur en cas d'erreur
function cloneDir(string $srcPath, string $destPath, string $tab): ?string {
  if ($tab)
    echo "{$tab}cloneDir($srcPath, $destPath)<br>\n";
  foreach (new DirectoryIterator($srcPath) as $entry) {
    if (in_array($entry, ['.','..','.DS_Store'])) continue;
    $link = "$destPath/$entry";
    $target = "$srcPath/$entry";
    if (is_file($target)) {
      if (!link($target, $link)) {
        return "Erreur sur link($target, $link)";
      }
      elseif ($tab)
        echo "{$tab}link($target, $link) ok <br>\n";
    }
    elseif (is_dir($target)) {
      if (!mkdir($link))
        return "Erreur sur mkdir($link)<br>\n";
      elseif ($tab)
        echo "{$tab}mkdir($link) ok <br>\n";
      if ($error = cloneDir($target, $link, $tab ? $tab.'&nbsp;' : ''))
        return $error;
    }
  }
  return null; // OK
}

if ($error = cloneDir($srcPath, $clonePath, ''))
  echo "$error<br>\n";
else
  echo "Clonage OK<br>\n";
echo "<a href='index.php'>Retour au menu du BO</a><br>\n";
