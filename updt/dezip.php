<?php
/*PhpDoc:
name: dezip.php
title: dezip.php - dezipage des fichiers de carte d'une livraison Shom
doc: |
  script à appeler en ligne de commande
  doit être appelé avec le nom du répertoire de livraison en paramètre
  sans paramètre liste les répertoires de livraison
  produit des commandes shell. Doit être pipé avec un shell.
  lance à la fin genpng.php
journal: |
  10/3/2019:
    ajout suppression des gros fichiers non indispensables
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
  echo "Quelle livraison ?\n";
  while (($filename = readdir($dir)) !== false) {
    if (!in_array($filename, ['.','..','.DS_Store'])) {
      echo " - $filename\n";
    }
  }
  closedir($dir);
  die("\n");
}

echo "# effacement du contenu de tmp ou création du répertoire s'il n'existe pas\n";
$tmppath = "$shomgeotiff/tmp";
if (is_dir($tmppath)) {
  echo "echo rm -r $tmppath/*\n"; echo "rm -r $tmppath/*\n";
}
else {
  echo "echo mkdir $tmppath\n"; echo "mkdir $tmppath\n"; 
}
  
// extraction des 7z et déplacement dans tmp
$dirpath = "$shomgeotiff/incoming/$argv[1]";
$dir = opendir($dirpath)
  or die("Erreur d'ouverture du répertoire $dirpath\n");
echo "echo cd $dirpath\n"; echo "cd $dirpath\n";
while (($filename = readdir($dir)) !== false) {
  if (!preg_match('!^\d+\.7z$!', $filename))
    continue;
  echo "echo 7z x $filename\n";
  echo "7z x $filename\n";
  $filename = substr($filename, 0, strlen($filename)-3);
  echo "echo mv $filename ../../tmp/\n";
  echo "mv $filename ../../tmp/\n";
}
closedir($dir);

echo "echo cd ",__DIR__,"\n"; echo "cd ",__DIR__,"\n";
echo "echo php genpng.php\n"; echo "php genpng.php | sh\n";
die("\n");
