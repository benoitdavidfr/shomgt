<?php
{/*PhpDoc:
title: pfm.php - gestionnaire de portefeuille
doc: |
  abstract:
    J'appelle portefeuille l'ensemble des cartes gérées avec leurs versions sur le principe d'avoir:
      - cartes courantes exposées dans le répertoire current
      - livraisons stockées chacune dans un répertoire archives/{YYYYMMDD} (ou au moins triables alphanumériquement)
      - en local
        - conservation des livraisons précédentes dans archives
        - création dans current de liens symboliques vers les cartes adhoc des archives
        - possibilité de reconstruire current si nécessaire en utilisant les MD index.yaml
      - sur geoapi.fr, stockage des versions en cours dans current, et pas de stockage des archives
      - le code de sgserver est le même dans les 2 environnements
      - simplifier sgserver en excluant l'utilisation des archives
  pourquoi:
    - complexité de la structure pour le serveur avec les fichiers mapversions.pser
    - complexité du code de gestion des versions de carte
    - nécessité de purge régulière sur geoapi
    - inutilité de stocker les archives sur geoapi
  objectif:
    - avoir la même code Php de shomgt en local et sur geoapi
    - possibilité d'annuler le dépôt d'une livraison en local
    - être compatible avec le client actuel
    - être efficace pour sgserver
  
  nouvelleStructure:
    ~/shomgeotiff/current:
      - contient les cartes courantes cad ni remplacées ni annulées
      - soit
        - en local un lien symbolique par carte vers la carte dans archives, nommé par le no suivi de .7z
        - sur geoapi stockage de la carte nommée par le no suivi de .7z
    ~/shomgeotiff/deliveries/{YYYYMMDD}:
      - un répertoire par livraison à effectuer, nommé par la date de livraison
        - ou au moins qu'un ls donne le bon ordre (tri alphabétique)
      - dans chaque répertoire de livraison les cartes de la livraison, chacune comme fichier 7z
    ~/shomgeotiff/archives/{YYYYMMDD}:
      - quand une livraison est déposée, son répertoire est déplacé dans archives
      - dans chaque répertoire d'archive les cartes de la livraison, nommées par le no suivi de .7z
    avantages:
      - proche de la version actuelle
      - pas de redondance
      - plus performante que la version actuelle, 1 seul répertoire à ouvrir en Php (à vérifier)
      - possibilité de code Php identique en local et sur geoapi
    inconvénients:
      - nécessité de scripts de gestion du portefeuille en local uniquement
      - vérifier comment se passe le téléchargement sur geoapi.fr
        - soit copier l'archive et détruire si nécessaire les cartes annulées (peu fréquent)
        - soit copier current en fonction des dates de création des liens
  opérations:
    add:
    cancel:
      - annule l'ajout de la dernière livraison uniquement en local
        - déplace la dernière livraison de archives vers deliveries
        - supprime les liens de current
        - ajoute chaque livraison de deliveries dans l'ordre chronologique
    purge:
      - supprime les archives avant strictement une certaine livraison
        - pour chaque archive avant strictement une certaine livraison
          - pour chaque carte de l'archive
          - si la carte ne correspond pas à une carte de current
            - alors suppression de la carte
*/}
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../vendor/autoload.php';

$metadataFile = 'index.yaml'; // nom du fichier de MD d'une livraison

// entrées systématiques dans les répertoires à sauter lors du parcours d'un répertoire 
function stdEntry(string $entry): bool { return in_array($entry, ['.','..','.DS_Store']); }

if (!($PF_PATH = getenv('PORTFOLIO_PATH')))
  die("Erreur PORTFOLIO_PATH non défini, utiliser 'export PORTFOLIO_PATH=xxx'\n");
if (!is_dir($PF_PATH))
  die("Erreur PORTFOLIO_PATH n'est pas un répertoire\n");

if ($argc == 1) {
  echo "usage: php $argv[0] {action} [{params}]\n";
  echo " où {action} vaut:\n";
  echo "  - ls - liste les cartes courantes du PF (portefeuille)\n";
  echo "  - lsa - liste les archives et leurs cartes\n";
  echo "  - lsd - liste les cartes des livraisons\n";
  echo "  - add [{répertoire de livraison}] - ajoute au PF les cartes de la livraison indiquée\n";
  echo "  - cancel - annule le dernier ajout et place les cartes retirées dans un répertoire new{date} où {date} est la date de l'ajout retiré\n";
  echo "  - purge [{date}] - supprime définitivement les versions archivées antérieures à la date indiquée\n";
  die();
}

function addDelivery(string $PF_PATH, string $deliveryName): void { // ajoute la livraison en paramètre 
  rename("$PF_PATH/deliveries/$deliveryName", "$PF_PATH/archives/$deliveryName");
  echo "renommage de $deliveryName dans archives\n";
  if (!($deliveryDir = @dir("$PF_PATH/archives/$deliveryName")))
    die("Erreur d'ouverture de PORTFOLIO_PATH/archives/$deliveryName\n");
  while (false !== ($mapname = $deliveryDir->read())) { // traitement de cahque carte de la livraison
    if (stdEntry($mapname)) continue;
    if (in_array($mapname, ['index.yaml'])) continue;
    echo "fichier $mapname\n";
    if (is_file("$PF_PATH/current/$mapname"))
      unlink("$PF_PATH/current/$mapname");
    symlink("../archives/$deliveryName/$mapname", "$PF_PATH/current/$mapname");
  }
  // supprimer les cartes retirées
  if (is_file("$PF_PATH/archives/$deliveryName/index.yaml")) {
    $params = Yaml::parse(file_get_contents("$PF_PATH/archives/$deliveryName/index.yaml"));
    $toDelete = array_keys($params['toDelete'] ?? []);
    //echo 'toDelete='; print_r($toDelete);
    foreach ($toDelete as $mapName) {
      $mapName = substr($mapName, 2); // suppression du 'FR'
      echo "Suppression de $mapName\n";
      unlink("$PF_PATH/current/$mapName");
    }
  }
}

function lsad(string $path): void { // liste les archives ou les livraisons
  if (!($dir = @dir($path)))
    die("Erreur d'ouverture de $path\n");
  while (false !== ($ad = $dir->read())) {
    if (stdEntry($ad)) continue;
    echo "$ad:\n";
    $adDir = @dir("$path/$ad");
    while (false !== ($entry = $adDir->read())) {
      if (stdEntry($entry)) continue;
      echo "  $entry\n";
    }
  }
}

array_shift($argv); // $argv[0] devient l'action, les autres sont les paramètres

switch ($argv[0]) {
  case 'ls': { // liste les cartes courantes du PF (portefeuille)
    if (!($currentDir = @dir("$PF_PATH/current")))
      die("Erreur d'ouverture de currentDir $PF_PATH/current\n");
    while (false !== ($entry = $currentDir->read())) {
      if (stdEntry($entry, ['.','..'])) continue;
      echo "$entry -> ",readlink("$PF_PATH/current/$entry"),"\n";
    }
    break;
  }
  case 'lsa': { // liste les archives et leurs cartes
    lsad("$PF_PATH/archives");
    break;
  }
  case 'lsd': { // liste les archives et leurs cartes
    lsad("$PF_PATH/deliveries");
    break;
  }
  case 'add': {
    {/* ajout d'un ou plusieurs répertoires de livraison nommé deliveries/{YYYYMMDD}
        - renommer le répertoire deliveries/{YYYYMMDD} en archives/{YYYYMMDD}
        - pour chaque carte de la livraison
          - si la carte existe déjà dans current
            - alors suppression du lien symbolique dans current
          - création d'un lien symbolique de current/{no}.7z vers archives/{YYYYMMDD}/{no}.7z
        - pour chaque carte retirée du catalogue suppression du lien symbolique dans current
    */}
    array_shift($argv); // $argv devient la liste des paramètres
    foreach ($argv as $deliveryName) {
      addDelivery($PF_PATH, $deliveryName);
    }
    break;
  }
  case 'cancel': {
    {/* annule l'ajout de la dernière livraison uniquement en local
        - déplace la dernière livraison de archives vers deliveries
        - supprime tous les liens de current
        - ajoute chaque livraison de deliveries dans l'ordre chronologique
    */}
    if (!($archivesDir = @dir("$PF_PATH/archives")))
      die("Erreur d'ouverture de archivesDir $PF_PATH/archives\n");
    $lastArchive = null;
    while (false !== ($archive = $archivesDir->read())) {
      if (!stdEntry($archive))
        $lastArchive = $archive;
    }
    if (!$lastArchive)
      die("Erreur aucune archive");
    echo "Transfert de $lastArchive des archives vers les livraisons\n";
    rename("$PF_PATH/archives/$lastArchive", "$PF_PATH/deliveries/$lastArchive");
    
    // effacement du contenu de current
    if (!($currentDir = @dir("$PF_PATH/current")))
      die("Erreur d'ouverture de PF_PATH/current\n");
    while (false !== ($mapname = $currentDir->read())) { // traitement de chaque carte de current
      if (stdEntry($mapname)) continue;
      @unlink("$PF_PATH/current/$mapname");
    }
    
    // ajoute chaque livraison de deliveries dans l'ordre chronologique
    $archivesDir->rewind();
    while (false !== ($archive = $archivesDir->read())) {
      if (stdEntry($archive)) continue;
      rename("$PF_PATH/archives/$archive", "$PF_PATH/deliveries/$archive");
      addDelivery($PF_PATH, $archive);
    }
    break;
  }
  default: {
    die("Ereur, $argv[0] ne correspond à aucune action\n"); 
  }
}