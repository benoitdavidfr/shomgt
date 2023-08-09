<?php
/* bo/maparchivestore.php - gère les stockage d'archives de cartes - 7/8/2023
** Un stockage d'archives de cartes (MapArchiveStore) est un répertoire qui contient
**   - d'une part un sous-répertoire archives
**   - d'autre part un sous-répertoire current avec des liens symboliques vers les archives
**
** La classe MapArchiveStore permet surtout de supporter des méthodes qui lisent et modifient les fichiers.
**
** Chgt de logique le 7/8/2023, lors d'un clonage utilisation de liens hards.
** Cela permet d'éviter les boucles infinies ou les liens invalides lorsque l'on déplace le stockage.
**
** Ce script propose 4 fonctions:
**  1) vérifier la conformité des liens de current
**  2) si un lien de current est absolu alors le transformer en lien relatif
**  3) cloner un MapArchiveStorage avec dans archives des liens durs pour les md.json et les 7z du storage d'origine
**  4) comparer 2 MapArchiveStorage
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;

if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

// supprime un répertoire
function rmdirRecursive(string $path): void {
  foreach (new DirectoryIterator($path) as $filename) {
    if (in_array($filename, ['.','..'])) continue;
    if (is_dir("$path/$filename"))
      rmdirRecursive("$path/$filename");
    else
      unlink("$path/$filename");
    @rmdir($path);
  }
}

// Répertoire contenant 2 sous-répertoires archives et current
class MapArchiveStore {
  const TEST = 0; // 1 <=> exécution en mode test sur un objet test
  const DEBUG = 0; // 1 <=> affichage de messages de debug 
  protected ?string $path=null; // le chemin du répertoire
  
  function __construct(?string $path) { $this->path = $path; }
  
  // retourne les motifs des liens dans current soit relatifs soit absolus
  private function targetPatterns(string $mapNum, string $ext): array {
    $versionCStd = '\d{4}c\d+[a-z]?'; // pattern std carte std
    $versionCSpeciale = '(\d{4}|\d{4}_\d{4})'; // pattern carte spéciale
    $versionPattern = match ($ext) {
        '7z' => "($versionCStd|$versionCSpeciale)",
        'md.json'=> "($versionCStd|\d{4}-\d{2}-\d{2}|$versionCSpeciale)",
    };
    $targetRelPattern = "!^\.\./archives/$mapNum/$mapNum-$versionPattern\.$ext$!";
    $targetAbsPattern = "!^$this->path/archives/$mapNum/$mapNum-$versionPattern\.$ext$!";
    return [$targetRelPattern, $targetAbsPattern];
  }
  
  // Vérifie qu'une entrée de current est un lien relatif vers archives
  // Renvoie null si ok, sinon le code d'erreur correspondant à l'entrée
  private function wrongCurLink(string $entry): ?string {
    if (!is_link("$this->path/current/$entry")) {
      return 'notALink';
    }
    $linkTarget = readlink("$this->path/current/$entry");
    //echo "$entry -> $linkTarget\n";
    if (!preg_match('!^(\d{4})\.(7z|md.json)$!', $entry, $matches)) {
      return 'entryDontMatchPattern';
    }
    $mapNum = $matches[1];
    $ext = $matches[2];
    list($targetPattern, $targetAbsPattern) = $this->targetPatterns($mapNum, $ext);
    if (preg_match($targetAbsPattern, $linkTarget)) {
      return 'targetIsAbsolute';
    }
    //echo "linkTarget=$linkTarget dont match $targetAbsPattern<br>\n";
    if (!preg_match($targetPattern, $linkTarget)) {
      return 'targetDontMatchPattern';
    }
    //echo "linkTarget=$linkTarget\n";
    $absPath = "$this->path/current/$linkTarget";
    //echo "absPath=$absPath ",is_file($absPath) ? 'is_file' : 'isNotAFile',"\n";
    if (!is_file($absPath)) {
      return 'targetIsNotAFile';
    }
    return null;
  }
  
  // Vérifie que les entrées de current sont des liens relatifs vers archives
  // Renvoie la liste des entrées qui ne le sont pas sous la forme [entrée => codeDErreur]
  function wrongCurLinks(): array {
    if (!is_dir("$this->path/current"))
      throw new Exception("Erreur, le répertoire $this->path/current n'existe pas");
    $errors = [];
    foreach (new DirectoryIterator("$this->path/current") as $entry) {
      if (!in_array($entry, ['.','..','.DS_Store']) && ($error = $this->wrongCurLink($entry)))
        $errors[(string)$entry] = $error;
    }
    return $errors;
  }
  
  // clone $this dans le chemin $clonePath avec des liens durs pour les md.json et les 7z des archives
  function clone(string $clonePath): ?self {
    if (is_dir($clonePath)) {
      echo "Erreur $clonePath existe déjà<br>\n";
      return null;
    }
    if (!mkdir($clonePath)) {
      echo "Erreur mkdir($clonePath)<br>\n";
      return null;
    }
    { // clonage des archives 
      if (!mkdir("$clonePath/archives")) {
        echo "Erreur mkdir($clonePath/archives)<br>\n";
        return null;
      }
      foreach (new DirectoryIterator("$this->path/archives") as $archive) {
        if (in_array($archive, ['.','..','.DS_Store'])) continue;
        if (!mkdir("$clonePath/archives/$archive"))
          echo "Erreur mkdir($clonePath/archives/$archive)<br>\n";
        foreach (new DirectoryIterator("$this->path/archives/$archive") as $filename) {
          if (in_array($filename, ['.','..','.DS_Store'])) continue;
          $link = "$clonePath/archives/$archive/$filename";
          $target = "$this->path/archives/$archive/$filename";
          if (!link($target, $link))
            echo "Erreur link($target, $link)<br>\n";
          elseif (self::DEBUG)
            echo "link($target, $link) ok<br>\n";
        }
      }
    }
    { // clonage de current en créant des liens vers les archives du clone à condition que le lien initial soit valide 
      if (!mkdir("$clonePath/current")) {
        echo "Erreur mkdir($clonePath/current)<br>\n";
        return null;
      }
      foreach (new DirectoryIterator("$this->path/current") as $filename) {
        if (in_array($filename, ['.','..','.DS_Store'])) continue;
        $target = readlink("$this->path/current/$filename");
        if ($this->wrongCurLink($filename)) {
          echo "Alerte, $filename -> $target incorrect<br>\n";
        }
        else {
          if (self::DEBUG)
            echo "$filename -> $target<br>\n";
          if (!symlink($target, "$clonePath/current/$filename"))
            echo "Erreur symlink($target, $clonePath/current/$filename)<br>\n";
          elseif (self::DEBUG)
            echo "symlink($target, $clonePath/current/$filename) ok<br>\n";
        }
      }
    }
    return new MapArchiveStore($clonePath);
  }

  private static function extension(string $filename): string {
    if (substr($filename, -3)=='.7z')
      return '.7z';
    if (substr($filename, -8)=='.md.json')
      return '.md.json';
    throw new Exception("Pas d'extension pour $filename");
  }
  
  private function listArchives(): array {
    $list = []; // [bname => [ext => inode]]
    foreach (new DirectoryIterator("$this->path/archives") as $archive) {
      if (in_array($archive, ['.','..','.DS_Store'])) continue;
      foreach (new DirectoryIterator("$this->path/archives/$archive") as $filename) {
        if (in_array($filename, ['.','..','.DS_Store'])) continue;
        if (($inode = fileinode("$this->path/archives/$archive/$filename")) === false)
          echo "Erreur sur fileinode($this->path/archives/$archive/$filename)<br>\n";
        else {
          $ext = self::extension($filename);
          $list[substr($filename, 0, -strlen($ext))][$ext] = $inode;
        }
      }
    }
    ksort($list);
    return $list;
  }
  
  private function listCurrents(): array {
    $list = []; // [bname => [ext => target]]
    foreach (new DirectoryIterator("$this->path/current") as $filename) {
      if (in_array($filename, ['.','..','.DS_Store'])) continue;
      $ext = self::extension($filename);
      if (($target = readlink("$this->path/current/$filename")) === false)
        echo "Erreur sur readlink($this->path/current/$filename)<br>\n";
      else
        $list[substr($filename, 0, -strlen($ext))][$ext] = substr($target, 0, -strlen($ext));
    }
    // fusion des 2 extensions lorsque c'est ok
    foreach ($list as $bname => &$targetForExt) {
      //echo '<pre>',json_encode([$bname => $targetForExt]),"</pre>\n";
      if (!isset($targetForExt['.7z'])) {
        $targetForExt = $targetForExt['.md.json'].'.md.json';
      }
      elseif ($targetForExt['.7z'] == $targetForExt['.md.json'])
        $targetForExt = $targetForExt['.7z'];
      else
        throw new Exception("Erreur sur current: ".json_encode([$bname => $targetForExt]));
    }
    ksort($list);
    //echo "<pre>listCurrents="; print_r($list); die();
    return $list;
  }
  
  function diff(self $pfb): array {
    //echo "{$this->path}->diff($pfb->path)<br>\n";
    $labelA = basename($this->path);
    $labelB = basename($pfb->path);
    $diffs = [
      'params'=> ['a'=> $labelA, 'b'=> $labelB],
      'archives'=> [],
      'current'=>[]
    ];
    
    { // comparaison archives
      $diffsa = []; // [banme => [ ext => code]]
      $inodes['a'] = $this->listArchives();
      $inodes['b'] = $pfb->listArchives();
      foreach ($inodes['a'] as $bname => $fext) {
        foreach ($fext as $ext => $inodeA) {
          $inodeB = $inodes['b'][$bname][$ext] ?? null;
          if ($inodeB == $inodeA) {
            //$diffs['archives'][$filename] = 'ok';
            unset($inodes['b'][$bname][$ext]);
          }
          elseif ($inodeB === null) {
            $diffsa[$bname][$ext] = "a && !b";
            //unset($inodes['b'][$filename]);
          }
          else {
            $diffsa[$bname][$ext] = "a <> b";
            unset($inodes['b'][$bname][$ext]);
          }
        }
      }
      foreach ($inodes['b'] as $bname => $fext) {
        foreach ($fext as $ext => $inodeB) {
          $diffsa[$bname][$ext] = "!a && b";
        }
      }
      foreach ($diffsa as $bname => &$codes) {
        if ($codes['.7z'] == $codes['.md.json'])
          $codes =  $codes['.7z'];
      }
      ksort($diffsa);
      $diffs['archives'] = $diffsa;
    }
    
    { // comparaison current 
      $targets['a'] = $this->listCurrents();
      $targets['b'] = $pfb->listCurrents();
      foreach ($targets['a'] as $filename => $targetA) {
        $targetB = $targets['b'][$filename] ?? null;
        if ($targetB == $targetA) {
          unset($targets['b'][$filename]);
        }
        elseif ($targetB === null) {
          $diffs['current'][$filename] = ['a' => $targetA, 'b' => null];
        }
        else {
          $diffs['current'][$filename] = ['a' => $targetA, 'b' => $targetB];
          unset($targets['b'][$filename]);
        }
      }
      foreach ($targets['b'] as $filename => $targetB) {
        $diffs['current'][$filename] = ['a' => null, 'b' => $targetB];
      }
      ksort($diffs['current']);
    }
    echo "<pre>",Yaml::dump($diffs, 4, 2),"</pre>\n";
    return $diffs;
  }
  
  function action(?string $action, array $params): void {
    switch ($action) {
      case null: return;
      case 'correct': {
        $entry = $_GET['entry'];
        if (!preg_match('!^(\d{4})\.(7z|md.json)$!', $entry, $matches)) {
          echo "Erreur, $entry ne respecte pas le motif<br>\n";
          return;
        }
        $mapNum = $matches[1];
        $ext = $matches[2];
        echo "path=$this->path/current/$entry<br>\n";
        $linkTarget = readlink("$this->path/current/$entry");
        list($targetPattern, $targetAbsPattern) = $this->targetPatterns($mapNum, $ext);
        if (!preg_match($targetAbsPattern, $linkTarget)) {
          echo "Erreur, $linkTarget ne respecte pas le motif absolu<br>\n";
          return;
        }
        $relativeLinkTarget = '..'.substr($linkTarget, strlen($this->path));
        echo "relativeLinkTarget=$relativeLinkTarget<br>\n";
        unlink("$this->path/current/$entry");
        if (!symlink($relativeLinkTarget, "$this->path/current/$entry"))
          echo "Erreur symlink($relativeLinkTarget, $this->path/current/$entry)<br>\n";
        else
          echo "symlink($relativeLinkTarget, $this->path/current/$entry) ok<br>\n";
        //return; // Explicitement quand correct s'est exécuté il enchaine sur wrongCurLinks
      }  
      case 'wrongCurLinks': {
        $wrongLinks = $this->wrongCurLinks();
        echo "<pre>";
        //echo '$wrongLinks = '; print_r($wrongLinks);
        foreach ($wrongLinks as $entry => $errorCode) {
          switch($errorCode) {
            case 'notALink': {
              echo "Erreur, $entry n'est pas un lien\n";
              break;
            }
            case 'entryDontMatchPattern': {
              echo "Erreur, $entry ne respecte pas le motif\n";
              break;
            }
            case 'targetIsAbsolute': {
              $linkTarget = readlink("$this->path/current/$entry");
              //echo "$entry -> $linkTarget\n";
              echo "<b>Erreur, le lien $entry -> $linkTarget est un lien absolu</b>,",
                   " <a href='?action=correct&pf=$this->path&entry=$entry'>le corriger</a>\n";
              break;
            }
            case 'targetDontMatchPattern': {
              $linkTarget = readlink("$this->path/current/$entry");
              //echo "\n$entry -> $linkTarget\n";
              //echo "targetPattern=$targetPattern\n";
              echo "<b>Erreur, le lien $entry -> $linkTarget ne respecte pas le motif</b>\n";
              break;
            }
            case 'targetIsNotAFile': {
              $linkTarget = readlink("$this->path/current/$entry");
              //echo "\n$entry -> $linkTarget\n";
              echo "<b>Erreur, le lien $entry -> $linkTarget ne correspond pas à un fichier</b>\n";
              break;
            }
            default: echo "ErrorCode $errorCode non traité\n";
          }
        }
        if (!$wrongLinks)
          echo "Tous les liens de current sont corrects\n";
        echo "</pre>\n";
        return;
      }
      case 'delete': { // supprime $this
        if (!$this->path || !is_dir($this->path)) {
          echo "Erreur, '$this->path' n'existe pas<br>\n";
          return;
        }
        if (!is_dir("$this->path/archives") || !is_dir("$this->path/current")) {
          echo "Erreur, '$this->path' n'est pas un MapArchiveStore<br>\n";
          return;
        }
        rmdirRecursive($this->path);
        echo "$this->path supprimé<br>\n";
        return;
      }
      case 'clone': { // clone un MapArchiveStore avec des liens pour les md.json et les 7z
        $this->clone("{$this->path}-clone");
        break;
      }
      case 'diff': {
        $this->diff($params['pfb']);
        break;
      }
      default: echo "Erreur, action $action inconnue<br>\n";
    }
  }
};

if (!callingThisFile(__FILE__)) return; // n'exécute pas la suite si le fichier est inclus

if (MapArchiveStore::TEST) { // TEST 
  $PF_PATH = __DIR__.'/maparchivestore-test';
}
elseif (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))) {
  die("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie\n");
}
$bname = basename($PF_PATH);

if (substr($PF_PATH, -2)=='pp')
  $PF_PATH2 = substr($PF_PATH, 0, -2);
else
  $PF_PATH2 = $PF_PATH.'pp';
$bname2 = basename($PF_PATH2);

echo "<!DOCTYPE html><html><head><title>mapArchiveStorage $bname@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo "<h2>Gestion des liens de $PF_PATH</h2>\n";

//echo '<pre>'; print_r($_SERVER); die();

if (isset($_GET['pf'])) {
  $store = new MapArchiveStore($_GET['pf']);
  $store->action($_GET['action'] ?? null, isset($_GET['pfb']) ? ['pfb'=> new MapArchiveStore($_GET['pfb'])] : []);
}

echo "<h3>Menu</h3><ul>\n";
echo "<li><a href='?action=wrongCurLinks&pf=$PF_PATH'>vérifie les liens dans current dans '$bname'</a></li>\n";
echo "<li><a href='?action=delete&pf=$PF_PATH-clone'>supprime '$bname-clone'</a></li>\n";
echo "<li><a href='?action=clone&pf=$PF_PATH'>clone '$bname' avec des liens pour les md.json et les 7z</a></li>\n";
echo "<li><a href='?action=diff&pf=$PF_PATH&pfb=$PF_PATH2'>compare '$bname' avec '$bname2'</a></li>\n";
echo "<li><a href='?action=none'>aucune action</a></li>\n";
echo "<li><a href='index.php'>retourne au menu du BO</a></li>\n";
echo "</ul>\n";
