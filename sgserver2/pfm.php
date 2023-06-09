<?php
/*PhpDoc:
title: pfm.php - gestionnaire de portefeuille
doc: |
  abstract:
    J'appelle portefeuille l'ensemble des cartes gérées avec leurs versions sur le principe d'avoir:
     1) les cartes courantes dans le répertoire current
     2) les livraisons chacune dans un répertoire delivery/{YYYYMMDD}
  pourquoi:
    - complexité de la structure pour le serveur avec les fichiers mapversions.pser
    - quasi inutilité index.yaml
    - nécessité de purge sur geoapi
  objectif:
    - avoir la même structure en local et sur geoapi
    - possibilité d'annuler le dépôt d'une livraison
    - possibilité de purger sur geoapi
    - tant que l'on ne purge pas on conserve toutes les versions que l'on  peut retrouver
    - être compatible avec le client actuel
    - être efficace pour sgserver
  nouvelleStructure:
    ~/shomgeotiff/current:
      - contient les cartes courantes cad ni remplacées ni annulées
      - un lien symbolique par carte vers la carte dans archives, nommé par le no suivi de .7z
    ~/shomgeotiff/deliveries/{YYYYMMDD}:
      - un répertoire par livraison à effectuer, nommé par la date de livraison
        - ou au moins qu'un ls donne le bon ordre (tri alphabétique)
      - dans chaque répertoire de livraison les cartes de la livraison, chacune comme fichier 7z
    ~/shomgeotiff/archives/{YYYYMMDD}:
      - quand une livraison déposée, son répertoire est déplacé dans archives
      - dans chaque répertoire d'archive les cartes de la livraison, nommées par le no suivi de .7z
    ~/shomgeotiff/canceled/{YYYYMMDD}:
      - si une livraison est annulée alors son répertoire est déplacé dans canceled
      - dans chaque répertoire les cartes de la livraison
    avantages:
      - proche de la version actuelle
      - pas de redondance
      - plus performante que la version actuelle, 1 seul répertoire à ouvrir en Php (à vérifier)
    inconvénients:
      - nécessité de scripts de gestion du portefeuille
  opérations:
    add:
      - ajout des répertoires de livraison nommé deliveries/{YYYYMMDD}
        - renommer le répertoire deliveries/{YYYYMMDD} en archives/{YYYYMMDD}
        - pour chaque carte de la livraison
          - si la carte existe déjà dans le portefeuille courant
            - alors suppression du lien symbolique
        - création d'un lien symbolique de current/{no}.7z vers archives/{YYYYMMDD}/{no}.7z
    del:
      - supprime une carte de current en l'archivant sans qu'il y en ait une nouvelle version
        - suppression du lien symbolique dans current
    cancel:
      - annule l'ajout de la dernière livraison
        - déplace la dernière livraison de archives vers canceled
        - déplace les autres livraisons vers deliveries
        - supprime les liens de current
        - ajoute chaque livraison de deliveries dans l'ordre chronologique
    purge:
      - supprime les archives avant strictement une certaine livraison
        - pour chaque archive avant strictement une certaine livraison
          - pour chaque carte de l'archive
          - si la carte ne correspond pas à une carte de current
            - alors suppression de la carte
*/
$metadataFile = 'index.yaml'; // nom du fichier de MD d'une livraison

// entrées systématiques dans les répertoires à sauter lors du parcours d'un répertoire 
function stdEntry(string $entry): bool { return in_array($entry, ['.','..','.DS_Store']); }

if (!($PORTFOLIO_PATH = getenv('PORTFOLIO_PATH')))
  die("Erreur PORTFOLIO_PATH non défini, utiliser 'export PORTFOLIO_PATH=xxx'\n");
if (!is_dir($PORTFOLIO_PATH))
  die("Erreur PORTFOLIO_PATH n'est pas un répertoire\n");

if ($argc == 1) {
  echo "usage: php $argv[0] {action} [{params}]\n";
  echo " où {action} vaut:\n";
  echo "  - ls - liste les cartes courantes du PF (portefeuille)\n";
  echo "  - lsa - liste les cartes archivées du PF par date d'archive\n";
  echo "  - lsd - liste les cartes à livrer par date d'archive\n";
  echo "  - add [{répertoire de livraison}] - ajoute au PF les cartes de la livraison\n";
  echo "  - del {carte} [{date}] - supprime une carte du PF courant et l'archive, {date} est la date d'archive, par défaut la date du jour\n";
  echo "  - cancel - annule le dernier ajout et place les cartes retirées dans un répertoire new{date} où {date} est la date de l'ajout retiré\n";
  echo "  - purge [{date}] - supprime définitivement les versions archivées antérieures à la date indiquée\n";
  die();
}

switch ($argv[1]) {
  case 'lecture': {
    if (!($currentDir = @dir("$PORTFOLIO_PATH/current")))
      die("Erreur d'ouverture de currentDir $PORTFOLIO_PATH/current\n");
    while (false !== ($entry = $currentDir->read())) {
      if (stdEntry($entry, ['.','..'])) continue;
      echo "$entry:\n";
      echo file_get_contents("$PORTFOLIO_PATH/current/$entry");
    }
    break;
  }
  case 'stat': {
    if (!($currentDir = @dir("$PORTFOLIO_PATH/current")))
      die("Erreur d'ouverture de currentDir $PORTFOLIO_PATH/current\n");
    while (false !== ($entry = $currentDir->read())) {
      if (stdEntry($entry, ['.','..'])) continue;
      echo "$entry -> ",readlink("$PORTFOLIO_PATH/current/$entry"),"\n";
    }
    break;
  }
  case 'ls': {
    if (!($currentDir = @dir("$PORTFOLIO_PATH/current")))
      die("Erreur d'ouverture de currentDir $PORTFOLIO_PATH/current\n");
    while (false !== ($entry = $currentDir->read())) {
      if (stdEntry($entry, ['.','..'])) continue;
      echo "$entry\n";
    }
    break;
  }
  case 'lsa': {
    if (!($archivesDir = @dir("$PORTFOLIO_PATH/archives")))
      die("Erreur d'ouverture de archivesDir $PORTFOLIO_PATH/archives\n");
    while (false !== ($archive = $archivesDir->read())) {
      if (stdEntry($archive)) continue;
      echo "$archive:\n";
      $archiveDir = @dir("$PORTFOLIO_PATH/archives/$archive");
      while (false !== ($entry = $archiveDir->read())) {
        if (stdEntry($entry)) continue;
        echo "  $entry\n";
      }
    }
    break;
  }
  case 'add': {
    {/* ajoute dans le portefeuille courant les cartes de de la livraison
       si le nom du répertoire se termine par une date (YYYYMMDD) alors cette date est prise comme date de livraison
      - création dans archives d'un répertoire d'archive nommé avec la date du jour de l'opération
      - pour chaque carte dans le répertoire de livraison
        - si la carte existe déjà dans current alors
          - déplacement de la carte depuis le répertoire current vers ce répertoire avec la date
        - déplacement de la nouvelle carte dans current
      - s'il existe un fichier index.yaml le déplacer dans l'archive
    */}
      $deliveryPath = $argv[2] ?? "$PORTFOLIO_PATH/new";
      $deliveryDate = preg_match('!(\d{8}$)!', $deliveryPath, $matches) ? $matches[1] : date('Ymd');
      if (!is_dir("$PORTFOLIO_PATH/archives/$deliveryDate")) mkdir("$PORTFOLIO_PATH/archives/$deliveryDate");
      if (!($deliveryDir = @dir($deliveryPath)))
        die("Erreur d'ouverture de $deliveryPath\n");
      while (false !== ($entry = $deliveryDir->read())) {
        if (stdEntry($entry)) continue;
        echo "fichier $entry\n";
        if ($entry == $metadataFile) {
          if (is_file("$PORTFOLIO_PATH/archives/$deliveryDate/$entry"))
            echo "Erreur de déplacement de $entry car $PORTFOLIO_PATH/archives/$deliveryDate/$entry existe\n";
          else {
            echo "Déplacement de $deliveryPath/$entry vers PORTFOLIO/archives/$deliveryDate/$entry\n";
            rename("$deliveryPath/$entry", "$PORTFOLIO_PATH/archives/$deliveryDate/$entry");
          }
        }
        else { // l'élément ne se nomme pas index.yaml
          if (is_file("$PORTFOLIO_PATH/current/$entry")) { // la carte existe déjà
            rename("$PORTFOLIO_PATH/current/$entry", "$PORTFOLIO_PATH/archives/$deliveryDate/$entry");
            echo "Déplacement de PORTFOLIO/current/$entry vers PORTFOLIO/archives/$deliveryDate/$entry\n";
          }
          rename("$deliveryPath/$entry", "$PORTFOLIO_PATH/current/$entry");
          echo "Déplacement de $deliveryPath/$entry vers PORTFOLIO/current/$entry\n";
        }
      }
    break;
  }
  case 'del': {
    {/* supprime une carte de current en l'archivant sans qu'il y en ait une nouvelle version
        - création s'il n'existe pas d'un répertoire dans archives avec la date du jour de l'opération
        - déplacement de la carte depuis le répertoire current vers ce répertoire avec la date
    */}
    if (!($eltName = $argv[2] ?? null))
      die("Erreur, cette commande nécessite le nom de la carte en paramètre\n");
    if (!is_file("$PORTFOLIO_PATH/current/$eltName"))
      die("Erreur, la carte $eltName n'existe pas dans current\n");
    $deleteDate = (isset($argv[3]) && preg_match('!^\d{8}$!', $argv[3])) ? $argv[3] : date('Ymd');
    if (!is_dir("$PORTFOLIO_PATH/archives/$deleteDate"))
      mkdir("$PORTFOLIO_PATH/archives/$deleteDate");
    rename("$PORTFOLIO_PATH/current/$eltName", "$PORTFOLIO_PATH/archives/$deleteDate/$eltName");
    break;
  }
  case 'cancel': {
    {/* annule l'ajout de la dernière livraison
      - créer un répertoire delivery dans le portefeuille avec la date de la dernière archive
      - pour chaque carte de la dernière archive
        - déplacer la carte courante ayant le même nom dans le répertoire new
        - déplacer la carte de la dernière archive dans current
      - si existant déplacement du fichier index.yaml de l'archive vers le répertoire new
      - supprimer le répertoire de la dernière archive
    */}
    if (!($archivesDir = @dir("$PORTFOLIO_PATH/archives")))
      die("Erreur d'ouverture de archivesDir $PORTFOLIO_PATH/archives\n");
    $lastArchive = null;
    while (false !== ($archive = $archivesDir->read())) {
      if (!stdEntry($archive))
        $lastArchive = $archive;
    }
    if (!$lastArchive)
      die("Erreur aucune archive");
    mkdir ("$PORTFOLIO_PATH/delivery$lastArchive");
    echo "création $PORTFOLIO_PATH/delivery$lastArchive\n";
    if (!($lastArchiveDir = @dir("$PORTFOLIO_PATH/archives/$lastArchive")))
      die("Erreur d'ouverture de lastArchiveDir=$PORTFOLIO_PATH/archives/$lastArchive\n");
    while (false !== ($entry = $lastArchiveDir->read())) {
      if (stdEntry($entry)) continue;
      if ($entry == $metadataFile)
        rename("$PORTFOLIO_PATH/archives/$lastArchive/$entry", "$PORTFOLIO_PATH/delivery$lastArchive/$entry");
      else {
        if (is_file("$PORTFOLIO_PATH/current/$entry"))
          rename("$PORTFOLIO_PATH/current/$entry", "$PORTFOLIO_PATH/delivery$lastArchive/$entry");
        rename("$PORTFOLIO_PATH/archives/$lastArchive/$entry", "$PORTFOLIO_PATH/current/$entry");
      }
    }
    @unlink("$PORTFOLIO_PATH/archives/$lastArchive/.DS_Store"); // au cas où 
    rmdir("$PORTFOLIO_PATH/archives/$lastArchive");
    break;
  }
  case 'purge': {
    break;
  }
  default: {
    die("Ereur, $argv[1] ne correspond à aucune action\n"); 
  }
}