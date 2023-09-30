<?php
/** Tableau de bord de mise à jour des cartes.
 *
 * Affiche:
 *  1) les cartes manquantes ou en excès dans le portefeuille par rapport au flux WFS du Shom
 *  2) le degré de péremption des différentes cartes du portefeuille
 *
 * Journal:
 * - 30/9/2023:
 *   - transfert de la classe MapFromWfs dans le module shomft ainsi que la fonction wfsAge()
 * - 2/7/2023:
 *   - correction lecture AvailAtTheShop pour que la lecture du fichier disponible.tsv fonctionne avec des lignes vides à la fin
 * - 28/6/2023:
 *   - **BUG** Attention, l'action perempt plante lorsqu'une nouvelle carte est ajoutée dans le patrimoine
 *     sans que le GAN soit moissonné sur cette carte
 * - 25/6/2023:
 *   - ajout de l'affichage de la disponibilité de la carte dans la boutique
 * - 12/6/2023:
 *   - réécriture de l'interface avec le portefeuille à la suite de sa réorganisation
 * - 23/4/2023:
 *   - ajout aux cartes manquantes la ZEE intersectée pour facilier leur localisation
 * - 21/4/2023:
 *   - prise en compte évol de ../shomft sur la définition du périmètre de gt.json
 *   - modif fonction spatialCoeff() pour mettre à niveau les COM à la suite mail D. Bon
 * - 6/1/2023: modif fonction spatialCoeff()
 * - 22/8/2022: correction bug
 * @package shomgt\dashboard
 */
namespace dashboard;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
//require_once __DIR__.'/../shomft/frzee.inc.php';
require_once __DIR__.'/../shomft/mapfromwfs.inc.php';
require_once __DIR__.'/../gan/gan.inc.php';
require_once __DIR__.'/portfolio.inc.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><title>dashboard@$_SERVER[HTTP_HOST]</title></head><body>\n";

/** chaque objet est une ligne du TdB croisant pour une carte les infos du portefeuille et celles du GAN
 * en vue d'afficher le tableau de péremption des cartes */
class DashboardRow {
  /** num de la carte */
  readonly public string $mapNum;
  /** infos de MapCat */
  readonly public ?\mapcat\MapCatItem $mapCat;
  /** version provenant du portefeuille */
  readonly public string $pfVersion;
  /** date provenant du portefeuille */
  readonly public ?string $pfDate;
  /** version fournie par le GAN */
  protected string $ganVersion='';
  /** liste des corrections apportées àa la carte
   * @var array<int, array<string, string>> $ganCorrections */
  protected array $ganCorrections=[]; // info du GAN
  /**  degré de péremption déduit de la confrontation entre portefeuille et GAN */
  protected ?float $degree=null;
  
  /** tableau de tous les objets de la classse [mapNum => self]
   * @var array<string, self> $all */
  static array $all;

  /** construction à partir du portefeuille */
  static function init(): void {
    foreach (\bo\Portfolio::$all as $mapnum => $mapMd) {
      self::$all[$mapnum] = new self($mapnum, $mapMd);
    }
  }
  
  /** @param TMapMdNormal|TMapMdLimited $mapMd */
  function __construct(string $mapNum, array $mapMd) {
    //echo "<pre>"; print_r($map);
    $this->mapNum = $mapNum;
    $this->mapCat = \mapcat\MapCat::get($mapNum);
    $this->pfVersion = $mapMd['version'];
    $this->pfDate = $mapMd['dateMD']['value'] ?? $mapMd['dateArchive'];
  }
  
  /** Mise à jour de perempt à partir du GAN */
  function setGan(\gan\Gan $gan): void {
    $this->ganVersion = $gan->version();
    $this->ganCorrections = $gan->corrections();
    $this->degree = $this->degree();
  }

  function title(): string { return $this->mapCat->title ?? "<i>titre inconnu</i>"; }
  /** @return array<int, string> */
  function mapsFrance(): array { return $this->mapCat->mapsFrance ?? ['unkown']; }
  
  /** calcul du degré de péremption */
  function degree(): float {
    if (($this->pfVersion == 'undefined') && ($this->ganVersion == 'undefined'))
      return -1;
    if (!$this->mapCat) {
      return -1;
    }
    if (preg_match('!^(\d+)c(\d+)$!', $this->pfVersion, $matches)) {
      $pfYear = $matches[1];
      $pfNCor = $matches[2];
    }
    else
      return 100;
    if (preg_match('!^(\d+)c(\d+)$!', $this->ganVersion, $matches)) {
      $ganYear = $matches[1];
      $ganNCor = $matches[2];
    }
    else
      return 100;
    if ($pfYear == $ganYear) {
      $d = intval($ganNCor) - intval($pfNCor);
      if ($d < 0) $d = 0;
      return $d;
    }
    else
      return 100;
  }

  /** Affichage du tableau des degrés de péremption */
  static function showAll(): void { 
    usort(self::$all,
      function(self $a, self $b) {
        if ($a->degree() < $b->degree()) return 1;
        elseif ($a->degree() == $b->degree()) return 0;
        else return -1;
      });
    //echo "<pre>Perempt="; print_r(Perempt::$all);
    echo "<h2>Degrés de péremption des cartes du portefeuille</h2>\n";
    echo "<p>On appelle <b>portefeuille</b>, l'ensemble des dernières versions des cartes non obsolètes",
      " exposées par le serveur de cartes 7z.<br>",
      "L'objectif du grand tableau ci-dessous est de fournir le degré de péremption des cartes du portefeuille",
      " mesuré par rapport aux <a href='https://gan.shom.fr/' target='_blank'>GAN (Groupes d'Avis aux Navigateurs)</a>",
      " qui indiquent chaque semaine notamment les corrections apportées aux cartes.</p>\n";
    echo "<table border=1><tr><td><b>Validité du GAN</b></td><td>",\gan\Gan::$hvalid,"</td></tr></table>\n";
    echo "<h3>Signification des colonnes du tableau ci-dessous</h3>\n";
    echo "<table border=1>\n";
    $headers = [
      '#'=> "numéro de la carte",
      'titre'=> "titre de la carte",
      'zone ZEE'=> "zone de la ZEE intersectant la carte",
      'date Pf'=> "date de création ou de révision de la carte du portefeuille, issue des MD ISO associée à la carte",
      'version Pf'=> "version de la carte dans le portefeuille, identifiée par l'année d'édition de la carte"
          ." suivie du caractère 'c' et du numéro de la dernière correction apportée à la carte",
      'version GAN'=> "version de la carte trouvée dans les GANs à la date de validité ci-dessus ;"
          ." le lien permet de consulter les GANs du Shom pour cette carte",
      'degré'=> "degré de péremption de la carte exprimant l'écart entre les 2 versions ci-dessus ;"
          ." la table ci-dessous est triée par degré décroissant ; "
          ." l'objectif de gestion est d'éviter les degrés supérieurs ou égaux à 5",
      'corrections'=> "liste des corrections reconnues dans les GANs, avec en première colonne "
          ." le numéro de la correction et, dans la seconde colonne, avant le tiret, le no de semaine de la correction"
          ." (année sur 2 caractères et no de semmaine sur 2 caractères)"
          ." et après le tiret le numéro d'avis dans le GAN de cette semaine.",
    ];
    foreach ($headers as $name => $label)
        echo "<tr><td><b>$name</b></td><td>$label</td></tr>\n";
    if (AvailAtTheShop::exists())
      echo "<tr><td><b>boutique</b></td><td>disponibilité sur la boutique du Shom avec info de mise à jour</td></tr>\n";
    echo "</table></p>\n";
    echo "<p>Attention, certains écarts de version sont dus à des informations incomplètes ou incorrectes",
      " sur les sites du Shom</p>\n";
    echo "<table border=1><th>",implode('</th><th>', array_keys($headers)),"</th>\n";
    if (AvailAtTheShop::exists())
      echo "<th>boutique</th>\n";
    foreach (self::$all as $row) {
      $row->showAsRow();
    }
    echo "</table>\n";
  }
  
  /** Affichage d'une ligne du tableau des degrés de péremption */
  function showAsRow(): void {
    echo "<tr><td>$this->mapNum</td>";
    echo "<td>",$this->title(),"</td>";
    echo "<td>",implode(', ', $this->mapsFrance()),"</td>";
    echo "<td>$this->pfDate</td>";
    echo "<td>$this->pfVersion</td>";
    $href = "https://gan.shom.fr/diffusion/qr/gan/$this->mapNum";
    echo "<td><a href='$href' target='_blank'>$this->ganVersion</a></td>";
    if ($this->degree !== null)
      printf('<td>%.2f</td>', $this->degree);
    else
      echo "<td>non défini</td>\n";
    echo "<td><table border=1>";
    foreach ($this->ganCorrections as $c) {
      echo "<tr><td>$c[num]</td><td>$c[semaineAvis]</td></tr>";
    }
    echo "</table></td>\n";
    if (AvailAtTheShop::exists())
      echo "<td>",AvailAtTheShop::maj($this->mapNum),"</td>\n";
    //echo "<td><pre>"; print_r($this); echo "</pre></td>";
    echo "</tr>\n";
  }
};

/** lit le fichier disponible.tsv s'il existe et stoke les cartes dispo. dans la boutique */
class AvailAtTheShop {
  /** chemin du fichier tsv */
  const FILE_NAME = __DIR__.'/available.tsv';
  /** durée en secondes pendant laquelle le fichier FILE_NAME reste valide */
  const MAX_DURATION = 7*24*60*60;
  //const MAX_DURATION = 60; // Pour test
  
  /** affiche comme table Html un tableau dont chaque ligne est une chaine avec \t comme séparateur
   * $header est soit une ligne avec séparateurs \t, soit nul
   * @param list<string> $data
   */
  private static function showTsvAsTable(array $data, ?string $header=null): void {
    echo "<table border=1>\n";
    if ($header) {
      $header = explode("\t", $header);
      echo '<th>#</th><th>',implode('</th><th>', $header),"</th>\n";
    }
    foreach ($data as $i => $line) {
      $line = explode("\t", $line);
      echo "<tr><td>$i</td><td>",implode('</td><td>', $line),"</td></tr>\n";
    }
    echo "</table>\n";
  }
  
  /** chargement du fichier available.tsv */
  static function load(): void {
    //echo "<pre>POST="; print_r($_POST); echo "</pre>\n";
    if (!isset($_POST['text'])) {// appel intial
      $page = 0;
    }
    elseif ($_POST['text']) { // chargement de la page $_POST[page]
      $page = $_POST['page'];
      $data = explode("\n", $_POST['text']);
      //echo "<pre>data="; print_r($data); echo "</pre>\n";
      $header = null;
      if (substr($data[0], 0, 8) == 'Commande') {
        $header = array_shift($data);
      }
      //echo "<pre>data ="; print_r($data); echo "</pre>\n";
      self::showTsvAsTable($data, $header);
      file_put_contents(__DIR__."/available-page$page.tsv", implode("\n", $data));
      $page++;
    }
    else {
      echo "Fin du chargement<br>\n";
      $data = [];
      $nbPages = $_POST['page'];
      for ($page=0; $page < $nbPages; $page++) {
        $text = file_get_contents(__DIR__."/available-page$page.tsv");
        unlink(__DIR__."/available-page$page.tsv");
        $data = array_merge($data, explode("\n", $text));
        //echo "<pre>data="; print_r($data); echo "</pre>\n";
      }
      file_put_contents(self::FILE_NAME, implode("\n", $data));
      self::showTsvAsTable($data);
      return;
    }
    echo "Copier dans le cadre ci-dessous une copie de la liste des produits téléchageables",
         " sur le <a href='https://diffusion.shom.fr/' target='_blank'>site de diffusion du Shom</a> (page $page)<br>\n";
    echo "<table border=1><form method='post'>\n";
    echo "  <input type='hidden' name='page' value='$page'/>\n";
    echo "  <tr><td><textarea name='text' rows='30' cols='120'></textarea></td></tr>\n";
    echo "  <tr><td><input type='submit' value='charger'></td></tr>\n";
    echo "</form></table>\n";
  }
  
  /** @var array<string, string> $all */
  static array $all=[]; // [{mapNum} => {maj}]
  
  /** indique s'il existe au moins une carte disponible */
  static function exists(): bool { return (self::$all <> []); }
  
  /** charge le fichier dans self::$all */
  static function init(): void {
    // si le fichier n'existe pas ou s'il est trop  vieux alors abandon 
    if (!is_file(self::FILE_NAME) || (time() - filemtime(self::FILE_NAME) > self::MAX_DURATION))
      return;
    $ftsv = fopen(self::FILE_NAME, 'r');
    while ($record = fgetcsv($ftsv, 256, "\t")) {
      //echo "<pre>"; print_r($record); echo "</pre>\n";
      //echo "<pre>"; var_dump($record); echo "</pre>\n";
      if ($record[0] == 'Commande ') continue;
      if ($record[0] == null) break; // ligne vide à la fin du fichier
      if (!preg_match('! - (\d{4}) !', $record[1], $matches))
        die("No match on $record[1]\n");
      $mapnum = $matches[1];
      self::$all[$mapnum] = $record[2];
    }
    fclose($ftsv);
  }
  
  // retourne le champ 'Informations de mise à jour ' pour la carte de numéro $mapNum
  static function maj(string $mapNum): string { return self::$all[$mapNum] ?? ''; }
};
AvailAtTheShop::init();

switch ($action = ($_GET['action'] ?? null)) {
  case null:
  case 'deleteTempOutputBatch': { // menu avec éventuelle action préalable
    if ($action == 'deleteTempOutputBatch') { // revient de runbatch.php et efface, s'il existe, le fichier temporaire créé 
      $filename = basename($_GET['filename']);
      //echo "filename=$filename<br>\n";
      if (is_file(__DIR__."/../bo/temp/$filename"))
        unlink(__DIR__."/../bo/temp/$filename");
    }
    //echo "<pre>"; print_r($_GET); echo "</pre>\n";
    echo "<h2>Tableau de bord de l'actualité des cartes</h2><ul>\n";
    $ganHarvestAge = \gan\Gan::age();
    //echo "ganHarvestAge=$ganHarvestAge<br>\n";
    if (($ganHarvestAge == -1) || ($ganHarvestAge > 0))
      echo "<li><a href='../bo/runbatch.php?batch=harvestGan&returnTo=dashboard'>Moissonner le GAN",
           ($ganHarvestAge <> -1) ? " précédemment moissonné il y a $ganHarvestAge jours" : '',"</a></li>\n";
    $wfsAge = \shomft\MapFromWfs::age();
    if (($wfsAge >= 7) || ($wfsAge == -1))
      echo "<li><a href='../shomft/updatecolls.php?collections=gt,aem,delmar'>",
           "Mettre à jour de la liste des cartes à partir du serveur WFS du Shom ",
           ($wfsAge <> -1) ? "précédemment mise à jour il y a $wfsAge jours" : '',
           "</a></li>\n";
    echo "<li><a href='?action=newObsoleteMaps'>",
         "Voir les nouvelles cartes et cartes obsolètes dans le portefeuille par rapport au WFS</li>\n";
    echo "<li><a href='?action=loadAvailableAtTheShop'>",
         "Charger les versions disponibles dans le site de diffusion du Shom</li>\n";
    echo "<li><a href='?action=perempt'>Afficher le degré de péremption des cartes du portefeuille</li>\n";
    //echo "<li><a href='?action=listOfInterest'>liste des cartes d'intérêt issue du serveur WFS du Shom</li>\n";
    echo "<li><a href='?action=listWfs'>Afficher la liste des cartes du serveur WFS du Shom avec leur intérêt</li>\n";
    echo "</ul>\n";
    die();
  }
  case 'newObsoleteMaps': { // détecte de nouvelles cartes à ajouter au portefeuille et les cartes obsolètes
    //echo "<pre>";
    $listOfInterest = \shomft\MapFromWfs::interest();
    $newMaps = []; // liste des nouvelles cartes
    foreach ($listOfInterest as $mapid => $zeeIds) {
      if (!\bo\Portfolio::exists($mapid)) {
        $newMaps[$mapid] = $zeeIds;
        //echo "$mapid dans WFS et pas dans sgserver<br>\n";
      }
    }
    if (!$newMaps)
      echo "<h2>Toutes les cartes d'intérêt du flux WFS sont dans le portefeuille</h2>>\n";
    else {
      echo "<h2>Cartes d'intérêt présentes dans le flux WFS et absentes du portefeuille</h2>\n";
      foreach ($newMaps as $mapid => $zeeIds) {
        $map = \shomft\MapFromWfs::$all[$mapid]->prop;
        $scale = '1/'.addUndescoreForThousand(isset($map['scale']) ? (int)$map['scale'] : null);
        echo "- $map[name] ($scale) intersecte ",implode(',', $zeeIds),"<br>\n";
      }
    }
  
    $obsoletes = []; // liste des cartes obsoletes
    foreach (array_keys(\bo\Portfolio::$all) as $mapid) {
      if (!isset($listOfInterest[$mapid]))
        $obsoletes[] = $mapid;
    }
    if (!$obsoletes)
      echo "<h2>Toutes les cartes du portefeuille sont présentes dans le flux WFS</h2>\n";
    else {
      echo "<h2>Cartes du portefeuille absentes du flux WFS</h2>\n";
      //echo "<pre>"; print_r($listOfInterest); echo "</pre>\n";
      foreach ($obsoletes as $mapid) {
        echo "- $mapid<br>\n";
      }
    }
    die();
  }
  case 'loadAvailableAtTheShop': {
    //echo "<pre>"; print_r($_POST); echo "</pre>\n";
    AvailAtTheShop::load();
    echo "<a href='index.php'>Retour au menu</a><br>\n";
    die();
  }
  case 'perempt': { // construction puis affichage des degrés de péremption 
    \gan\Gan::loadFromPser(); // chargement de la synthèse des GANs
    DashboardRow::init(); // construction à partir du portefeuille
    // Mise à jour de perempt à partir du GAN
    foreach (DashboardRow::$all as $mapNum => $row) {
      if (!($gan = \gan\Gan::item($mapNum)))
        echo "Erreur, Gan absent pour carte $mapNum\n";
      else
        $row->setGan($gan);
    }
    DashboardRow::showAll(); // Affichage du tableau des degrés de péremption
    die();
  }
  case 'listWfs': { // liste des cartes du serveur WFS du Shom avec intérêt pour ShomGT
    echo "<h2>Liste des cartes du serveur WFS du Shom avec intérêt pour ShomGT</h2><pre>\n";
    foreach (\shomft\MapFromWfs::$all as $gmap) {
      $gmap->showOne();
    }
    die();
  }
  case 'listOfInterest': { // Affichage simple des cartes d'intérêt du serveur WFS
    $listOfInterest = \shomft\MapFromWfs::interest();
    ksort($listOfInterest);
    //echo "<pre>listOfInterest="; print_r($listOfInterest); echo "</pre>\n";
    echo "<h2>Liste des cartes d'inérêt</h2><pre>\n",Yaml::dump($listOfInterest, 1),"</pre>\n";
    die();
  }
  default: { die("Action $action non définie\n"); }
}
