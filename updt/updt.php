<?php
/*PhpDoc:
name: updt.php
title: updt.php - installe une ou plusieurs livraisons dans le portefeuille de shomgt
doc: |
  script à appeler en ligne de commande
  doit être appelé avec en paramètre les noms des répertoires de livraison
  sans paramètre liste les répertoires de livraison
  produit des commandes shell et doit donc être pipé avec un shell.
  dezip une livraison dans tmp puis lance genpng.php
  supprime les cartes obsolètes
  à la fin génère shomgt.yaml et efface le cache des tuiles

journal: |
  2/1/2021:
    - transfert de la suppression de cartes de genpng.php dans updt.php
  2-3/4/2019:
    traitement de plusieurs livraisons
    chgt de nom
  10/3/2019:
    suppression des gros fichiers non indispensables
  9/3/2019:
    création
includes: [../lib/store.inc.php]
*/
require_once __DIR__.'/../lib/store.inc.php';

header('Content-type: text/plain; charset="utf8"');

$shomgeotiff = realpath(__DIR__.'/../../../shomgeotiff');
  
//echo "argc=$argc\n";
if ($argc <= 1) {
  $deliveries = SevenZipMap::listOfDeliveries();
  sort($deliveries);
  echo "Quelle livraison ?\n";
  echo ' - ',implode("\n - ", $deliveries);
  die("\n");
}

array_shift($argv); // supprime le nom du script php pour garder la liste des noms des répertoires de livraison
foreach ($argv as $incoming) {
  echo "# suppression de tmp s'il existe et recréation du répertoire\n";
  $tmppath = "$shomgeotiff/tmp";
  if (is_dir($tmppath)) {
    echo "echo rm -r $tmppath\n"; echo "rm -r $tmppath\n";
  }
  echo "echo mkdir $tmppath\n"; echo "mkdir $tmppath\n"; 
  
  // extrait les 7z et déplace le répertoire dans tmp
  $dirpath = "$shomgeotiff/incoming/$incoming";
  $dir = opendir($dirpath)
    or die("Erreur d'ouverture du répertoire $dirpath\n");
  echo "echo cd $dirpath\n"; echo "cd $dirpath\n";
  while (($filename = readdir($dir)) !== false) {
    if (!preg_match('!^\d+\.7z$!', $filename))
      continue;
    echo "echo 7z -y x $filename\n"; echo "7z -y x $filename\n";
    $filename = substr($filename, 0, strlen($filename)-3);
    echo "echo mv $filename ../../tmp/\n"; echo "mv $filename ../../tmp/\n";
  }
  closedir($dir);

  // appel genpng.php pour générer dans tmp les png puis les transférer dans current
  echo "echo cd ",__DIR__,"\n"; echo "cd ",__DIR__,"\n";
  echo "echo 'php genpng.php | sh'\n"; echo "php genpng.php | sh\n";
  
  // supprime dans current les cartes à supprimer
  foreach (array_keys(SevenZipMap::obsoleteMaps($incoming)) as $obsoleteMapId) {
    if (substr($toDelete, 0, 2))
      $obsoleteMapNum = substr($obsoleteMapId, 2);
    echo "echo \"Suppresion de la carte $obsoleteMapNum\"\n";
    if (is_dir("$shomgeotiff/current/$obsoleteMapNum")) {
      echo "echo rm -r $shomgeotiff/current/$obsoleteMapNum\n"; echo "rm -r $shomgeotiff/current/$obsoleteMapNum\n";
    }
    else
      echo "echo \"La carte $obsoleteMapNum n'existe pas dans current\"\n";
  }
}

// génère le nouveau shomgt.yaml et le met dans ws
echo "echo 'php shomgt.php > shomgt.yaml'\n"; echo "php shomgt.php > shomgt.yaml\n";
echo "echo 'cp shomgt.yaml ../ws'\n"; echo "cp shomgt.yaml ../ws\n";

// efface le cache des tuiles
if (is_dir(__DIR__.'/../tilecache')) {
  echo "echo rm -r ",__DIR__,"/../tilecache\n"; echo "rm -r ",__DIR__,"/../tilecache\n";
}
die("\n");
