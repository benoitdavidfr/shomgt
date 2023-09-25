<?php
/** clone un répertoire src dans un répertoire dest - 18/8/2023.
 *
 * Clone le répertoire src qui doit exister dans le répertoire dest qui est créé et ne doit pas prééxister
 * Le clonage s'effectue en créant:
 *  - pour chaque répertoire de src un nouveau répertoire dans dest
 *  - pour chaque fichier de src un lien dur dans dest
 *  - pour chaque lien symbolique des src un lien symbolique dans dest ayant même cible
 * Peut s'exécuter soit en CLI soit en web
 * @package shomgt\bo
 */
namespace bo;
//require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib.inc.php';

//use Symfony\Component\Yaml\Yaml;

/** fonction récursive de clonage.
 * @param string $src; le chemin vers un répertoire existant à cloner
 * @param string $dest; le chemin vers le répertoire à créer par clonage qui ne doit pas pré-exister
 * @param string $tab; la chaine à afficher avant les affichages de debug, si vide pas de d'affichage
 * Retourne un message en cas d'erreur et null ssi OK
 */
function cloneDir(string $src, string $dest, string $tab=''): ?string {
  if ($tab)
    echo "{$tab}cloneDir($src, $dest)\n";
  if (!mkdir($dest))
    return "Erreur sur mkdir($dest)<br>\n";
  elseif ($tab)
    echo "{$tab}mkdir($dest) ok <br>\n";
  foreach (new \DirectoryIterator($src) as $entry) {
    if (in_array($entry, ['.','..','.DS_Store'])) continue;
    $dest2 = "$dest/$entry";
    $src2 = "$src/$entry";
    if (is_link($src2)) {
      //echo "$src2 est un link\n";
      $target = readlink($src2);
      if (!symlink($target, $dest2))
        return "Erreur sur symlink($target, $dest2)";
      elseif ($tab)
        echo "{$tab}symlink($target, $dest2) ok\n";
    }
    elseif (is_file($src2)) {
      //echo "$src2 est un file\n";
      if (!link($src2, $dest2)) {
        return "Erreur sur link($src2, $dest2)";
      }
      elseif ($tab)
        echo "{$tab}link($src2, $dest2) ok\n";
    }
    elseif (is_dir($src2)) {
      //echo "$src2 est un dir\n";
      if ($error = cloneDir($src2, $dest2, $tab ? $tab.'  ' : ''))
        return $error;
    }
    else {
      return "$src ni fichier, ni lien symbolique, ni répertoire";
    }
  }
  return null; // OK
}

switch (callingThisFile(__FILE__)) {
  case null: return; // fichier inclus
  case 'cli': { // appel comme cmde CLI
    if ($argc < 3)
      die("usage: php $argv[0] [-v] {src} {dest}\n");
    if (($argv[1] == '-v') && ($argc==4)) {
      $src = $argv[2];
      $dest = $argv[3];
      $verbose = true;
    }
    elseif (($argv[1] <> '-v') && ($argc == 3)) {
      $src = $argv[1];
      $dest = $argv[2];
      $verbose = false;
    }
    else
      die("usage: php $argv[0] [-v] {src} {dest}\n");
    break;
  }
  case 'web': {
    echo "<!DOCTYPE html><html><head><title>clone@$_SERVER[HTTP_HOST]</title></head><body><pre>\n";
    if (!($src = $_GET['src'] ?? null)) {
      die("Erreur, le paramètre src doit indiquer le répertoire source à cloner\n");
    }
    if (!($dest = $_GET['dest'] ?? null)) {
      die("Erreur, le paramètre dest doit indiquer le répertoire cloné de destination\n");
    }
    $verbose = $_GET['verbose'] ?? false;
    echo "</pre><h2>Clonage de $_GET[src] dans $_GET[dest]</h2><pre>\n";
    break;
  }
}

if (!is_dir($src)) {
  die("Erreur le répertoire $src n'existe pas\n");
}
if (is_dir($dest)) {
  die("Erreur le répertoire $dest existe\n");
}

if ($error = cloneDir($src, $dest, $verbose ? ' ':''))
  echo "$error\n";
else
  echo "Clonage de $dest OK\n";
