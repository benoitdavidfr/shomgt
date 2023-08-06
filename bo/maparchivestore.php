<?php
/* bo/maparchivestore.php - gère les stockage d'archives de cartes
** Un stockage d'archives de cartes (MapArchiveStore) est un répertoire qui contient
**   - d'une part un sous-répertoire archives
**   - d'autre part un sous-répertoire current
**
** Ce script propose 5 fonctions:
**  1) vérifier la conformité des liens de current
**  2) si un lien de current est absolu, le transformer en lien relatif
**  3) cloner un MapArchiveStorage avec dans archives des liens pour les md.json et les 7z du storage d'origine
**  4) tester si un MapArchiveStorage a des liens dans les archives
**  3) remplacer dans un MapArchiveStorage les liens dans les archives par les fichiers pointés
*/
require_once __DIR__.'/login.inc.php';

if (!($login = Login::login())) {
  die("Accès non autorisé\n");
}

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

class MapArchiveStore {
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
      //echo "<b>Erreur, le lien $linkTarget est un lien absolu</b>, <a href='?action=correct&entry=$entry'>le corriger</a>\n";
      return 'targetIsAbsolute';
    }
    elseif (!preg_match($targetPattern, $linkTarget)) {
      //echo "<b>Erreur, le lien $linkTarget ne respecte pas le motif</b>\n";
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
    $errors = [];
    foreach (new DirectoryIterator("$this->path/current", ) as $entry) {
      if (!in_array($entry, ['.','..','.DS_Store']) && ($error = $this->wrongCurLink($entry)))
        $errors[(string)$entry] = $error;
    }
    return $errors;
  }
  
  function clone(string $clonePath): ?self { // clone un MapArchiveStore avec des liens pour les md.json et les 7z
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
      $bnameBack = basename($this->path).'-back';
      foreach (new DirectoryIterator("$this->path/archives") as $archive) {
        if (in_array($archive, ['.','..','.DS_Store'])) continue;
        if (!mkdir("$clonePath/archives/$archive"))
          echo "Erreur mkdir($clonePath/archives/$archive)<br>\n";
        foreach (new DirectoryIterator("$this->path/archives/$archive") as $filename) {
          if (in_array($filename, ['.','..','.DS_Store'])) continue;
          $link = "$clonePath/archives/$archive/$filename";
          $target = "../../../$bnameBack/archives/$archive/$filename";
          if (!symlink($target, $link))
            echo "Erreur symlink($target, $link)<br>\n";
          else
            echo "symlink($target, $link) ok<br>\n";
        }
      }
    }
    { // clonage de current en créant des liens vers les archives du clone à condition que le lien initial soit valide 
      if (!mkdir("{$this->path}-clone/current")) {
        echo "Erreur mkdir({$this->path}-clone/current)<br>\n";
        return null;
      }
      foreach (new DirectoryIterator("$this->path/current") as $filename) {
        if (in_array($filename, ['.','..','.DS_Store'])) continue;
        $target = readlink("$this->path/current/$filename");
        if (!$this->wrongCurLink($filename)) {
          echo "$filename -> $target<br>\n";
          if (!symlink($target, "{$this->path}-clone/current/$filename"))
            echo "Erreur symlink($target, {$this->path}-clone/current/$filename)\n";
          else
            echo "symlink($target, {$this->path}-clone/current/$filename) ok\n";
        }
      }
    }
    return new MapArchiveStore("{$this->path}-clone");
  }

  function listLinksInArchives(): void { // liste les liens dans les archives
    foreach (new DirectoryIterator("$this->path/archives") as $archive) {
      if (in_array($archive, ['.','..','.DS_Store'])) continue;
      //echo "archive $archive<br>\n";
      foreach (new DirectoryIterator("$this->path/archives/$archive") as $filename) {
        if (in_array($filename, ['.','..','.DS_Store'])) continue;
        // echo "&nbsp;&nbsp;fichier $filename<br>\n";
        if (is_link("$this->path/archives/$archive/$filename")) {
          $link = readlink("$this->path/archives/$archive/$filename");
          echo "archives/$archive/$filename est un lien vers $link<br>\n";
        }
      }
    }
  }

  function materialize(): void { // remplace dans les archives les liens par les fichiers pointés
    foreach (new DirectoryIterator("$this->path/archives") as $archive) {
      if (in_array($archive, ['.','..','.DS_Store'])) continue;
      //echo "archive $archive<br>\n";
      foreach (new DirectoryIterator("$this->path/archives/$archive") as $filename) {
        if (in_array($filename, ['.','..','.DS_Store'])) continue;
        //echo "&nbsp;&nbsp;fichier $filename<br>\n";
        if (is_link("$this->path/archives/$archive/$filename")) {
          $link = readlink("$this->path/archives/$archive/$filename");
          unlink("$this->path/archives/$archive/$filename");
          copy($link, "$this->path/archives/$archive/$filename");
          echo "copy($link, $this->path/archives/$archive/$filename)<br>\n";
        }
      }
    }
  }

  function action(?string $action): void {
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
      case 'delete': { // supprime $_GET['pf']
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
      case 'listLinksInArchives': { // liste les liens dans les archives
        $this->listLinksInArchives();
        break;
      }
      case 'materialize': { // remplace dans les archives de $_GET['pf'] les liens par les fichiers pointés
        $this->materialize();
        break;
      }
      default: echo "Erreur, action $action inconnue<br>\n";
    }
  }
};

// retourne '' si ce n'est pas ce fichier qui est appelé (cad qu'il est inclus),
// 'inWebMode' s'il est appelé en mode web, 'inCliMode' s'il est appelé en mode CLI
function thisFileIsCalled(): string {
  $documentRoot = $_SERVER['DOCUMENT_ROOT'];
  if (substr($documentRoot, -1)=='/')
    $documentRoot = substr($documentRoot, 0, -1);
  $thisFileIsCalledInWebMode = (__FILE__ == $documentRoot.$_SERVER['SCRIPT_NAME']);
  $thisFileIsCalledInCliMode = (($argv[0] ?? '') == basename(__FILE__));
  //echo "thisFileIsCalledInWebMode=",$thisFileIsCalledInWebMode?'true':'false',"<br>\n";
  //echo "thisFileIsCalledInCliMode=",$thisFileIsCalledInCliMode?'true':'false',"<br>\n";
  return $thisFileIsCalledInWebMode ? 'inWebMode' : ($thisFileIsCalledInCliMode ? 'inCliMode' : '');
}
if (!thisFileIsCalled()) return; // n'exécute pas la suite si le fichier est inclus

if (0) { // TEST 
  $PF_PATH = __DIR__.'/maparchivestore-test';
}
elseif (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))) {
  die("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie\n");
}

echo "<!DOCTYPE html><html><head><title>mapArchiveStorage</title></head><body>\n";
echo "<h2>Gestion des liens de $PF_PATH</h2>\n";

if (isset($_GET['pf'])) {
  $store = new MapArchiveStore($_GET['pf']);
  $store->action($_GET['action'] ?? null);
}
$bname = basename($PF_PATH);

echo "<h3>Menu</h3><ul>\n";
echo "<li><a href='?action=wrongCurLinks&pf=$PF_PATH'>vérifie les liens dans current dans '$bname'</a></li>\n";
echo "<li><a href='?action=delete&pf=$PF_PATH-clone'>supprime '$bname-clone'</a></li>\n";
echo "<li><a href='?action=clone&pf=$PF_PATH'>clone '$bname' avec des liens pour les md.json et les 7z</a></li>\n";
echo "<li><a href='?action=listLinksInArchives&pf=$PF_PATH-clone'>",
      "liste dans '$bname-clone' les liens dans les archives</a></li>\n";
echo "<li><a href='?action=materialize&pf=$PF_PATH-clone'>",
      "remplace dans les archives de '$bname-clone' les liens par les fichiers pointés</a></li>\n";
echo "<li><a href='?action=none'>aucune action</a></li>\n";
echo "<li><a href='index.php'>retourne au menu du BO</a></li>\n";
echo "</ul>\n";
