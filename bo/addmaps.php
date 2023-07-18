<?php
// Test upload
require_once __DIR__.'/mapmetadata.inc.php';

define ('SHOMGEOTIFF', '/var/www/html/shomgeotiff');

function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string {
  $form =  "<form action='$action' method='$method'>";
  foreach ($hiddenValues as $name => $value)
    $form .= "  <input type='hidden' name='$name' value='$value' />";
  return $form
    ."  <input type='submit' value='$submitValue'>"
    ."</form>";
}

//echo "upload_max_filesize=",ini_get('upload_max_filesize'),"<br>\n";
//echo "post_max_size=",ini_get('post_max_size'),"<br>\n";
$login = 'benoit.david@free.fr';

echo "<!DOCTYPE html><html><body>\n";
echo "<h2>Intégration de nouvelles versions de cartes au portefeuille</h2>\n";
echo "<pre>_POST="; print_r($_POST); echo "</pre>\n";
echo "<pre>_GET="; print_r($_GET); echo "</pre>\n";

switch ($_POST['action'] ?? null) { // action à réaliser
  case null: break;
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
    if (!is_dir(SHOMGEOTIFF."/users/"))
      mkdir(SHOMGEOTIFF."/users");
    if (!is_dir(SHOMGEOTIFF."/users/$login"))
      mkdir(SHOMGEOTIFF."/users/$login");
    $target_file = SHOMGEOTIFF."/users/$login/" . basename($_FILES["fileToUpload"]["name"]);
    if (!move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $target_file)) {
      echo "<b>Le chargement du fichier a généré une erreur</b><br>\n";
      break;
    }
    echo "Le fichier ". htmlspecialchars( basename( $_FILES["fileToUpload"]["name"])). " a bien été chargé.";
    break;
  }
  case 'delete': { // suppression du fichier d'un e carte 
    unlink(SHOMGEOTIFF."/users/$login/$_POST[map]");
    break;
  }
  default: {
    echo "Erreur, action $_POST[action] inconnue<br>\n";
  }
}

// Affichage de la page hors éxécution d'action

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

// Tableau des acrtes chargées avec actions possibles
$maps = [];
foreach (new DirectoryIterator(SHOMGEOTIFF."/users/$login") as $file) {
  if (substr($file, -3) == '.7z')
    $maps[] = (string)$file;
}
if ($maps) {
  echo "<h3>Cartes en cours d'intégration</h3>\n";
  echo "<table border=1>\n";
  foreach ($maps as $map) {
    $md = MapMetadata::getFrom7z(SHOMGEOTIFF."/users/$login/$map");
    echo "<tr><td>",$md['title'] ?? $map,"</td>",
         "<td>",
           button('vérifier', ['path'=>SHOMGEOTIFF."/users/$login",'map'=>substr($map, 0, -3)], 'viewtiff.php', 'get'),
         "</td>",
         "<td>",button('supprimer', ['action'=>'delete','map'=>$map]),"</td>",
         "</tr>\n";
  }
  echo "</table>\n";
}
