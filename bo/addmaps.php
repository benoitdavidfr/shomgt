<?php
/** ajout et vérification de nouvelles cartes dans le BO
 * @package shomgt\bo
 */
namespace bo;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/user.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/maparchive.php';

use Symfony\Component\Yaml\Yaml;


/** vrai ssi possibilité de forcer la validation d'une carte invalide */
const FORCE_VALIDATION = false;
  
//echo "upload_max_filesize=",ini_get('upload_max_filesize'),"<br>\n";
//echo "post_max_size=",ini_get('post_max_size'),"<br>\n";
if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

if (!in_array(userRole($login), ['normal','admin'])) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
//echo "PF_PATH=$PF_PATH<br>\n";

echo "<!DOCTYPE html><html><head><title>addmaps</title></head><body>\n";
echo "<h2>Dépôt de nouvelles versions de cartes dans le portefeuille</h2>\n";
//echo "<pre>_POST="; print_r($_POST); echo "</pre>\n";
//echo "<pre>_GET="; print_r($_GET); echo "</pre>\n";

function existingVersion(string $PF_PATH, string $mapNum): string {
  if (!is_file("$PF_PATH/current/$mapNum.md.json"))
    return '0000c0';
  $existingMd = json_decode(file_get_contents("$PF_PATH/current/$mapNum.md.json"), true);
  //echo "<pre>"; print_r($existingMd); echo "</pre>\n";
  return $existingMd['version'];
}

// compare 2 versions, renvoie 1 si v1 > V2, 0 si si v1 == v2, -1 si v1 < v2
// renvoie 2 (indéfini) si l'une des 2 versions ne respecte pas le format YYYYcN
function cmpVersion(string $v1, string $v2): int {
  if (!preg_match('!^\d{4}c\d+$!', $v1) || !preg_match('!^\d{4}c\d+$!', $v2))
    return 2;
  $y1 = substr($v1, 0, 4);
  $y2 = substr($v2, 0, 4);
  if ($y1 > $y2)
    return 1;
  elseif ($y1 < $y2)
    return -1;
  $c1 = substr($v1, 5);
  $c2 = substr($v2, 5);
  if ($c1 > $c2)
    return 1;
  elseif ($c1 < $c2)
    return -1;
  else
    return 0;
}

//echo '<pre>',Yaml::dump(['$_POST'=> $_POST, '$_GET'=> $_GET]),"</pre>\n";

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) { // action à réaliser
  case null: break;
  case 'updateMapCat':
  case 'insertMapCat':
  case 'verifyMap': {
    $mapNum = $_GET['mapNum'];
    $abort = "<a href='?action=verifyMap&mapNum=$mapNum'>Retour</a>";
    switch ($action) {
      case 'updateMapCat': {
        echo "updateMapCat<br>\n";
        \mapcat\MapCatItem::updateMapCat($mapNum, $login, $abort);
        break;
      }
      case 'insertMapCat': {
        echo "insertMapCat<br>\n";
        \mapcat\MapCatItem::insertMapCat($mapNum, $login, $abort);
        break;
      }
      default: break;
    }
    //echo "verifyMap@addmaps<br>\n";
    $map = new MapArchive("/users/$login/$mapNum.7z");
    echo "<table border=1>\n";
    $map->showAsHtml(true);
    
    $validateButton = new \html\Form(
        submit: "Valider la carte et la déposer",
        hiddens: [
          'action'=> 'validateMap',
          'mapNum' => $_GET['mapNum'],
        ],
        method: 'get'
    );
    $invalid = $map->invalid();
    if (!isset($invalid['errors'])) { // cas normal, pas d'erreur => bouton de validation
      echo "<tr><td colspan=2><center>$validateButton</center></td></tr>\n";
    }
    elseif (FORCE_VALIDATION) { // @phpstan-ignore-line // cas où il y a une erreur mais la validation peut être forcée
      echo "<tr><td colspan=2><center>",
             "<b>La carte n'est pas valide mais sa validation peut être forcée</b>",
             "$validateButton</center></td></tr>\n";
    }
    else { // cas d'erreur normale, la validation n'est pas possible
      echo "<tr><td colspan=2><center>",
           "<b>La carte ne peut pas être validée en raison des erreurs</b>",
           "</center></td></tr>\n";
    }
    echo "</table>\n";
    echo "<a href='?'>Retour</a><br>\n";
    die();
  }
  case 'showMapCatScheme': { // Affiche le schema JSON de l'entité map
    echo '<pre>',Yaml::dump(\mapcat\MapCatItem::getDefSchema('map'), 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
    break;
  }
  case 'upload': { // chargement du fichier d'une carte 
    //echo "<pre>_POST="; print_r($_POST);
    //echo "_FILES="; print_r($_FILES);
    if ($_FILES['fileToUpload']['error']) {
      echo "<b>Erreur ".$_FILES['fileToUpload']['error']." de lecture du fichier chargé</b><br>\n";
      break;
    }
    if ($_FILES['fileToUpload']['type'] <> 'application/x-7z-compressed') {
      echo "<b>Erreur, le fichier chargé a pour type ".$_FILES['fileToUpload']['type']
          ." et n'est donc pas l'archive 7z d'une carte</b><br>\n";
      break;
    }
    if (!preg_match('!^\d{4}\.7z$!', $_FILES["fileToUpload"]["name"])) {
      echo "<b>Erreur le nom du fichier d'archive ('",$_FILES['fileToUpload']['name'],
           "') doit être constitué du numéro de la carte et de l'extension .7z</b><br>\n";
      break;
    }
    if (!is_dir($PF_PATH."/users/"))
      mkdir($PF_PATH."/users");
    if (!is_dir($PF_PATH."/users/$login"))
      mkdir($PF_PATH."/users/$login");
    $target_file = $PF_PATH."/users/$login/" . basename($_FILES["fileToUpload"]["name"]);
    if (!move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $target_file)) {
      echo "<b>Le chargement du fichier a généré une erreur</b><br>\n";
      break;
    }
    echo "Le fichier ". htmlspecialchars( basename( $_FILES["fileToUpload"]["name"])). " a bien été chargé.";
    break;
  }
  case 'delete': { // suppression du fichier de la carte 
    unlink($PF_PATH."/users/$login/$_POST[mapNum].7z");
    echo "Suppresion de $_POST[mapNum] confirmée<br>\n";
    break;
  }
  case 'validateMap': { // dépôt de la carte, paramètre GET rpath=chemin relatif de la carte à déposer
    $mapNum = $_GET['mapNum'];
    $newMap = new MapArchive("/users/$login/$mapNum.7z");
    
    { // Première condition: la version de la nouvelle version doit être postérieure à celle du portefeuille
      $newMd = $newMap->main()->md();
      //echo "<pre>newMd = "; print_r($newMd); echo "</pre>\n";
      $newVersion = $newMd['version'];
      $existingVersion = existingVersion($PF_PATH, $mapNum);
      $cmp = cmpVersion($newVersion, $existingVersion);
      //echo "newVersion=$newVersion, existingVersion=$existingVersion, cmp=$cmp<br>\n";
      if ($cmp <= 0) {
        echo "<b>Dépôt impossible de la version $newVersion de la carte $mapNum qui est antérieure ou identique",
             " à la version courante ($existingVersion) du portefeuille.</b><br>\n";
        break;
      }
    }
    
    // 2ème condition: cette version de carte ne doit pas exister
    // Cela peut arriver exceptionnellement lorsque la version courante n'est pas la dernière
    if (is_file("$PF_PATH/archives/$mapNum/$mapNum-$newVersion.7z")) {
      echo "<b>Dépôt impossible de la version $newVersion de la carte $mapNum",
           " car cette version est déjà présente dans le portefeuille sans être la version courante.</b><br>\n";
      break;
    }
    
    // 3ème condition: la carte doit être valide, sauf si la validation peut être forcée
    if (!FORCE_VALIDATION) { // @phpstan-ignore-line 
      $invalid = $newMap->invalid();
      //echo "<pre>invalid = ",Yaml::dump($invalid),"</pre>\n";
      if (isset($invalid['errors'])) {
        echo "<b>Dépôt impossible de la carte $mapNum, car l'archive contient les erreurs suivantes:</b><br>\n",
             "<pre>invalid = ",Yaml::dump($invalid),"</pre>\n";
        break;
      }
    }
    
    // Les conditions sont vérifiées, on y va
    // Je déplace l'archive 7z dans archives
    if (!is_dir("$PF_PATH/archives/$mapNum"))
      mkdir("$PF_PATH/archives/$mapNum");
    rename("$PF_PATH/users/$login/$mapNum.7z", "$PF_PATH/archives/$mapNum/$mapNum-$newVersion.7z");
    // Je génère le fichier md.json associé
    $newMd = array_merge( // j'ajoute le nom de l'utilisateur déposant cette carte et la date de dépôt
      $newMd,
      ['user'=> $login, 'dateUpload'=> date('Y-m-d')]
    );
    file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-$newVersion.md.json", json_encode($newMd, JSON_OPTIONS));
    
    // Je supprime la version courante éventuelle
    if (is_file("$PF_PATH/current/$mapNum.md.json"))
      unlink("$PF_PATH/current/$mapNum.md.json");
    if (is_file("$PF_PATH/current/$mapNum.7z"))
      unlink("$PF_PATH/current/$mapNum.7z");
    
    // Je définis cette nouvelle version comme version courante
    symlink("../archives/$mapNum/$mapNum-$newVersion.7z", "$PF_PATH/current/$mapNum.7z");
    symlink("../archives/$mapNum/$mapNum-$newVersion.md.json", "$PF_PATH/current/$mapNum.md.json");
    echo "<b>Dépôt de la carte $mapNum réalisé.</b><br>\n";
    break;
  }
  default: {
    echo "Erreur, action '$action' inconnue<br>\n";
    break;
  }
}

// Affichage de la page après éxécution éventuelle d'action préalable

{ // Menu de chargement d'une nouvelle archive 7z 
  echo "<h3>Chargement de l'archive 7z d'une carte</h3>\n";
  echo <<<EOT
  <form method="post" enctype="multipart/form-data">
    <input type='hidden' name='action' value='upload' />
    Sélectionner l'archive 7z à charger:
    <input type='file' name='fileToUpload'>
    <input type='submit' value='Charger'>
  </form>
EOT;
}

// Tableau des cartes chargées avec actions possibles
$mapNums = [];
if (!is_dir("$PF_PATH/users") && !mkdir("$PF_PATH/users")) {
  die("Erreur de création de \"$PF_PATH/users\"\n");
}
if (!is_dir("$PF_PATH/users/$login") && !mkdir("$PF_PATH/users/$login")) {
  die("Erreur de création de \"$PF_PATH/users/$login\"\n");
}
  
foreach (new \DirectoryIterator("$PF_PATH/users/$login") as $file) {
  if (substr($file, -3) == '.7z')
    $mapNums[] = substr($file, 0, -3);
}

if ($mapNums) {
  echo "<h3>Cartes en cours de dépôt</h3>\n";
  echo "<table border=1><th>titre</th><th>v. exist.</th><th>v. dépôt</th>\n";
  foreach ($mapNums as $mapNum) {
    $md = MapMetadata::getFrom7z("$PF_PATH/users/$login/$mapNum.7z");
    if (!isset($md['version']))
      throw new \Exception("Erreur md ne contient pas de champ version pour $PF_PATH/users/$login/$mapNum.7z");
    $newVersion = $md['version'];
    $existingVersion = existingVersion($PF_PATH, $mapNum);
    if ($existingVersion == '0000c0')
      $existingVersion = '';
    echo "<tr><td>",$md['title'] ?? $mapNum,"</td>",
         "<td>$existingVersion</td>",
         "<td>$newVersion</td>",
         "<td><a href='?action=verifyMap&mapNum=$mapNum'>vérifier</a></td>",
         "<td>",new \html\Form(submit: 'supprimer', hiddens: ['action'=>'delete','mapNum'=>$mapNum], method: 'post'),"</td>",
         "</tr>\n";
  }
  echo "</table>\n";
}
echo "<a href='index.php'>Retour au menu du BO</a><br>\n";
