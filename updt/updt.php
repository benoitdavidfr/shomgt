<?php
/*PhpDoc:
name: updt.php
title: updt.php - installe une ou plusieurs livraisons dans le portefeuille de shomgt
doc: |
  script à appeler en ligne de commande
  doit être appelé avec en paramètre les noms des répertoires de livraison
  sans paramètre liste les répertoires de livraison
  produit des commandes shell. Doit être pipé avec un shell.
  dezip une livraison dans tmp puis lance genpng.php
  à la fin génère shomgt.yaml et efface le cache des tuiles

  appel avec ttes les livraisons:
  php updt.php 20170531 20170613 20170614 20170619 20170626 20170717 20181123 20181130 20181203 20190114 | sh
  
journal: |
  2/1/2021:
    - transfert de la suprresion de cartes de genpng.php dans updt.php
  2-3/4/2019:
    traitement de plusieurs livraisons
    chgt de nom
  10/3/2019:
    suppression des gros fichiers non indispensables
  9/3/2019:
    création
*/
header('Content-type: text/plain; charset="utf8"');

$shomgeotiff = realpath(__DIR__.'/../../../shomgeotiff');
  
//echo "argc=$argc\n";
if ($argc <= 1) {
  $dirpath = "$shomgeotiff/incoming";
  $dir = opendir($dirpath)
    or die("Erreur d'ouverture du répertoire $dirpath");
  $filenames = [];
  while (($filename = readdir($dir)) !== false) {
    if (!in_array($filename, ['.','..','.DS_Store'])) {
      $filenames[] = $filename;
    }
  }
  closedir($dir);
  sort($filenames);
  echo "Quelle livraison ?\n";
  echo ' - ',implode("\n - ", $filenames);
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
  if (($argc > 1) && is_file("$shomgeotiff/incoming/$incoming/index.yaml")) {
    echo "echo $shomgeotiff/incoming/$incoming/index.yaml existe\n";
    $index = Yaml::parseFile("$shomgeotiff/incoming/$incoming/index.yaml");
    if (isset($index['toDelete'])) {
      foreach (array_keys($index['toDelete']) as $toDelete) {
        if (substr($toDelete, 0, 2))
          $toDelete = substr($toDelete, 2);
        echo "echo \"Suppresion de la carte $toDelete\"\n";
        if (is_dir("$shomgeotiff/current/$toDelete")) {
          echo "echo rm -r $shomgeotiff/current/$toDelete\n"; echo "rm -r $shomgeotiff/current/$toDelete\n";
        }
        else
          echo "echo \"La carte $toDelete n'existe pas dans current\"\n";
      }
    }
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
