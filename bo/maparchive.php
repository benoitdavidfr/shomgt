<?php
/** Affichage et validation d'une archive 7z de carte - Benoit DAVID - 7-8/2023
 *
 * La validation des cartes est définie d'une part par sa conformité à sa spécification
 * et, d'autre part, par sa cohérence avec MapCat.
 *
 * Voir les critères de conformité des archives de cartes dans shomgt4.yaml
 *
 * Le script est utilisé de 3 manières:
 *  - soit inclus dans un script de BO
 *  - soit en CLI pour tester un ensemble de cartes.
 *  - soit en web pour visualiser une archive ou la liste des archives
 * @package shomgt\bo
 */
namespace bo;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/my7zarchive.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/gdalinfobo.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Image principale ou cartouche de la carte */
class GeoRefImage {
  /** chemin du tif dans l'archive */
  protected ?string $tif=null;
  /** ('ok'|'KO'|null) */
  protected ?string $georef;
  /** gbox de géoréférencement de l'image */
  protected ?\gegeom\GBox $georefBox=null;
  /** nom de l'xml dans l'archive décrivant l'image */
  protected ?string $xml=null;
  /** // MD simplifiées
   * @var array<string,mixed> $md */
  protected array $md=[];
  
  /** @return array<string, mixed> */
  function asArray(): array {
    return [
      'tif'=> $this->tif,
      'georef'=> $this->georef,
      'georefBox'=> $this->georefBox->asArray(),
      'xml'=> $this->xml,
      'md'=> $this->md,
    ];
  }
  
  function setTif(string $tif, My7zArchive $archive): void {
    $this->tif = $tif;
    $tifPath = $archive->extract($tif);
    $gdalinfo = new GdalInfoBo($tifPath);
    $this->georef = $gdalinfo->georef();
    $this->georefBox = $this->georef ? $gdalinfo->gbox() : null;
    $archive->remove($tifPath);
  }
  
  // Attention, je suis dans une boucle sur l'archive, ne pas la passer à MapMetadata
  // sinon la 2ème boucle écrase la première
  function setXml(?string $xml, string $pathOf7z): void {
    $this->xml = $xml;
    $this->md = MapMetadata::getFrom7z($pathOf7z, $xml);
  }

  function tif(): ?string { return $this->tif; }
  function georef(): ?string { return $this->georef; }
  function georefBox(): ?\gegeom\GBox { return $this->georefBox; }
  
  function georefLabel(): string { // label associé au georef
    return match ($this->georef()) {
      null => "Image non géoréférencée",
      'ok' => "Image géoréférencée",
      'KO' => "Image mal géoréférencée",
      default => throw new \Exception("valeur ".$this->georef()." interdite"),
    }
    . (($this->georefBox && $this->georefBox->intersectsAntiMeridian()) ? " à cheval sur l'antiméridien" : '');
  }
  
  function xml(): ?string { return $this->xml; }
  /** @return array<string, mixed> */
  function md(): array { return $this->md; }
  function title(): ?string { return $this->md['title'] ?? null; }
  
  /** Recherche pour ce cartouche défini dans l'archive le meilleur cartouche correspondant défini dans le catalogue
   *  retourne le titre de ce meilleur cartouche
   * @param array<int, array<string, mixed>> $insetMapsOfCat
   */
  function bestInsetMapOfCat(array $insetMapsOfCat, bool $show=false): ?string {
    //$show = true;
    //echo "title=",$this->title(),", georefBox=",$this->georefBox,"<br>\n";
    $bests = []; // liste des cartouches de MapCat correspondant au cartouche de l'archive
    foreach ($insetMapsOfCat as $insetMapOfCat) {
      $gbox = new \mapcat\Spatial($insetMapOfCat['spatial']);
      //echo "insetMapOfCat: title=",$insetMapOfCat['title'],", gbox=$gbox<br>\n";
      if ($this->georefBox->includes($gbox, $show))
        $bests[] = ['title'=> $insetMapOfCat['title'], 'gbox'=> $gbox];
    }
    if (count($bests)==0) {
      if ($show)
        echo "<pre>Aucun cartouche du catalogue correspond au cartouche ",$this->title(),"</pre>\n";
      return null;
    }
    if (count($bests)==1) {
      if ($show)
        echo "<pre>bests for ",$this->title(), " = ",$bests[0]['title'],"</pre>\n";
      return $bests[0]['title'];
    }
    $maxArea = 0;
    $best = null;
    if ($show) {
      echo "<pre>bests for ",$this->title(), "="; print_r($bests); echo "</pre>\n";
    }
    foreach ($bests as $i => $insetOfMapCat) {
      $area = $insetOfMapCat['gbox']->area();
      if ($area > $maxArea) {
        $maxArea = $area;
        $best = $insetOfMapCat;
      }
    }
    if ($show)
      echo "<pre>best for ",$this->title(), " = ",$best['title'],"</pre>\n";
    return $best['title'];
  }
};

/** analyse les fichiers d'une archive d'une carte pour évaluer sa validité et afficher le contenu */
class MapArchive {
  /** 'undefined'|'normal'|'special' */
  protected string $type='undefined';
  /** chemin du fichier .7z relativement à $PF_PATH et commencant par '/' */
  public readonly string $rpathOf7z;
  /** no sur 4 chiffres */
  public readonly string $mapNum;
  /** chemin de la vignette dans l'archive */
  protected ?string $thumbnail=null;
  /** les caractéristiques de l'image principale et les MD de la carte */
  protected GeoRefImage $main;
  /** les cartouches [{name}=> GeoRefImage]
   * @var array<string, GeoRefImage> */
  protected array $insets=[];
  /** liste de noms de fichiers hors specs sous la forme [{name} => 1]
   * @var array<string, int> */
  protected array $suppls=[];
  /** enregistrement dans MapCat correspondant à la carte ou null */
  public readonly ?\mapcat\MapCatItem $mapCat;

  function main(): GeoRefImage { return $this->main; }
  
  /** fabrique un MapArchive
   * @param string $rpathOf7z le chemin relatif du fichier .7z */
  function __construct(string $rpathOf7z) {
    //echo "MapArchive::__construct(rpathOf7z=$rpathOf7z)<br>\n";
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    if (!is_file($PF_PATH.$rpathOf7z))
      throw new \Exception("rpathOf7z=$rpathOf7z n'est pas un fichier dans MapArchive::__construct()");
    $this->rpathOf7z = $rpathOf7z;
    $mapNum = substr(basename($rpathOf7z), 0, 4);
    $this->mapNum = $mapNum;
    $this->mapCat = \mapcat\MapCat::get($mapNum);
    $archive = new My7zArchive($PF_PATH.$rpathOf7z);
    $this->main = new GeoRefImage;
    foreach ($archive as $entry) {
      //echo "<pre>entry="; print_r($entry); echo "</pre>\n";
      if ($entry['Attr'] <> '....A') continue; // pas un fichier
      if ($entry['Name'] == "$mapNum/$mapNum.png")
        $this->thumbnail = $entry['Name'];
      elseif ($entry['Name'] == "$mapNum/{$mapNum}_pal300.tif") {
        $this->type = 'normal';
        $this->main->setTif($entry['Name'], $archive);
      }
      elseif ($entry['Name'] == "$mapNum/CARTO_GEOTIFF_{$mapNum}_pal300.xml")
        $this->main->setXml($entry['Name'], $PF_PATH.$rpathOf7z);
      elseif (preg_match("!^$mapNum/(CARTO_GEOTIFF_)?{$mapNum}_((\d+|[A-Z]+)_gtw)\.(tif|xml)$!", $entry['Name'], $matches)) {
        $name = $matches[2];
        $ext = $matches[4];
        if (!isset($this->insets[$name]))
          $this->insets[$name] = new GeoRefImage;
        if ($ext == 'tif')
          $this->insets[$name]->setTif($entry['Name'], $archive);
        else // $ext == 'xml'
          $this->insets[$name]->setXml($entry['Name'], $PF_PATH.$rpathOf7z);
      }
      elseif (preg_match("!^$mapNum/{$mapNum}_\d{4}\.(tif|pdf)$!", $entry['Name'], $matches)) {
        $this->type = 'special';
        if (($matches[1] == 'tif') || !$this->main->tif()) { // le .tif a priorité sur le .pdf
          $this->main->setTif($entry['Name'], $archive);
        }
      }
      elseif (preg_match("!^$mapNum/[^/]+\.xml$!", $entry['Name']))
        $this->main->setXml($entry['Name'], $PF_PATH.$rpathOf7z);
      elseif (preg_match("!^$mapNum/[^/]+$!", $entry['Name']) && !preg_match('!\.(gt|tfw|prj)$!', $entry['Name']))
        $this->suppls[$entry['Name']] = 1;
    }
    if (!$this->main->md()) { // md n'a pas été initialisé si aucun fichier .xml n'a été rencontré
      echo "MapArchive::__construct(rpathOf7z=$rpathOf7z) -> MD limitées<br>\n";
      $this->main->setXml(null, $PF_PATH.$rpathOf7z); // affectation des MD limitées
    }
  }
  
  /** retourne la liste des GéoTiffs géoréférencés
   * @return array<int, string> */
  function gtiffs(): array {
    $gtiffs = [];
    if ($this->main->georef() == 'ok')
      $gtiffs[] = $this->main->tif();
    foreach ($this->insets as $inset)
      $gtiffs[] = $inset->tif();
    return $gtiffs;
  }
  
  /** Retourne le GBox de géoréférencement de la carte.
   * retourne le géoréférencement de l'image principale SI elle existe et est géoréférencée
   * SINON
   *   S'il n'y a pas d'image principale
   *   ALORS retourne null // cas d'erreur
   *   SINON // il y a une image principale et elle n'est pas géoréférencée
   *     S'il y a des cartouches
   *     ALORS retourne l'union des cartouches
   *     SINON retourne null // cas d'erreur, evt carte spéciale
   */
  function georefBox(): ?\gegeom\GBox { // retourne null ssi aucun tif géoréférencé
    if ($this->main->georefBox())
      return $this->main->georefBox();
    elseif ($this->insets) {
      $georefBox = new \gegeom\GBox;
      foreach ($this->insets as $inset) {
        $georefBox = $georefBox->union($inset->georefBox());
      }
      return $georefBox;
    }
    else
      return null;
  }
  
  /** construit la correspondance des cartouches de l'archive avec ceux de MapCat.
   * Le résultat est un array avec en clés les noms des cartouches dans l'archive
   * et en valeurs les titres des cartouches dans MapCat
   * @return array<string, string>
   */
  function mappingInsetsWithMapCat(bool $show=false): array {
    $mappingGeoTiffWithMapCat = [];
    foreach ($this->insets as $name => $inset) {
      if ($inset->tif() && $this->mapCat->insetMaps) {
        $mappingGeoTiffWithMapCat[$name] = $inset->bestInsetMapOfCat($this->mapCat->insetMaps, $show);
      }
    }
    return $mappingGeoTiffWithMapCat;
  }
  
  /** Teste la conformité à la spec et au catalogue.
   * retourne [] si la carte est valide et conforme à sa description dans le catalogue et sans alertes
   * sinon un array comportant un au moins des 2 champs:
   *  - errors listant les erreurs
   *  - warnings listant les alertes
   * @return array<string, array<int, string>>
   */
  function invalid(): array {
    if (!$this->mapCat)
      return ['errors'=> ["La carte n'existe pas dans le catalogue MapCat"]];
    $errors = [];
    $warnings = [];
    if (!$this->thumbnail && ($this->type == 'normal'))
      $warnings[] = "L'archive ne comporte pas de miniature";
    if (!$this->main->tif())
      return ['errors' => ["Aucun GéoTiff défini pour la partie principale"]];
    if (!$this->main->xml()) {
      if ($this->type == 'normal')
        $errors[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
      else
        $warnings[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
    }
    // Partie Géoréférencement de la partie principale 
    switch ($this->main->georef()) {
      case 'ok': {
        if (!$this->mapCat->scaleDenominator || !$this->mapCat->spatial) {
          $errors[] = "Le fichier GéoTiff principal est géoréférencé alors que le catalogue indique qu'il ne l'est pas";
        }
        elseif (!($inc = $this->georefBox()->includes($this->mapCat->spatial()))) {
          //echo "georefBox=",$this->georefBox(),"<br>\n";
          //echo "mapcat->spatial()=",$this->mapCat->spatial(),"<br>\n";
          //echo "georefBox->includes(mapcat->spatial())=",($inc ? 'T' : 'F'),"<br>\n";
          $errors[] = "L'extension spatiale définie dans MapCat n'est pas inclues dans le géoréférencement de l'archive";
        }
        break;
      }
      case null: { // Fichier principal non géoréférencé, 2 possibilités
        // carte normale composée uniquement de cartouches => ok ssi c'est indiqué comme telle dans le catalogue
        if ($this->type == 'normal') {
          if ($this->mapCat->scaleDenominator || $this->mapCat->spatial)
            $errors[] = "Le fichier GéoTiff principal n'est pas géoréférencé alors que le catalogue indique qu'il l'est";
          else
            $warnings[] = "Le fichier GéoTiff principal n'est pas géoréférencé ce qui est conforme au catalogue";
        }
        else { // carte spéciale géoréférencée par l'ajout de borders dans le catalogue => ok ssi borders présentes
          if (!$this->mapCat->borders)
            $errors[] = "Le fichier GéoTiff principal de la carte spéciale n'est pas géoréférencé"
              ." et le catalogue ne fournit pas l'information nécessaire à son géoréférencement";
          else
            $warnings[] = "Le fichier GéoTiff principal de la carte spéciale n'est pas géoréférencé"
              ." mais le catalogue fournit l'information nécessaire à son géoréférencement";
        }
        break;
      }
      case 'KO': { // Fichier principal mal géoréférencé, carte normale
        if (!$this->mapCat->scaleDenominator || !$this->mapCat->spatial)
          $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." alors que le catalogue indique qu'il n'est pas géoréférencé";
        elseif (!$this->mapCat->borders)
          $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." et le catalogue ne fournit pas l'information nécessaire à son géoréférencement";
        else
          $warnings[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." mais cela est compensé par l'information fournie par le catalogue";
        break;
      }
    }
    // Partie cartouches
    if (count($this->insets) <> count($this->mapCat->insetMaps ?? []))
      $errors[] = "L'archive contient ".count($this->insets)." cartouches"
        ." alors que le catalogue en mentionne ".count($this->mapCat->insetMaps ?? []);
    if ($this->insets) {
      foreach ($this->insets as $name => $inset) {
        if (!$inset->tif())
          $errors[] = "Le fichier GéoTiff du cartouche $name est absent";
        if (!$inset->xml())
          $warnings[] = "Le fichier de métadonnées XML du cartouche $name est absent";
        switch($inset->georef()) {
          case 'ok': break;
          case 'KO': {
            $errors[] = "Le fichier GéoTiff du cartouche '$name' est mal géoréférencé";
            break;
          }
          case null: {
            $errors[] = "Le fichier GéoTiff du cartouche '$name' n'est pas géoréférencé";
            break;
          }
        }
      }
      $mappingInsetsWithMapCat = $this->mappingInsetsWithMapCat();
      //echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
      sort($mappingInsetsWithMapCat);
      //echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
      //echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
      if ($mappingInsetsWithMapCat <> $this->mapCat->insetTitlesSorted())
        $errors[] = "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
    }
    if ($this->suppls) {
      foreach(array_keys($this->suppls) as $suppl) {
        if (substr($suppl, -4)=='.tif')
          $errors[] = "Le fichier $suppl est interdit par la spécification";
        else
          $warnings[] = "Le fichier $suppl n'est pas prévu par la spécification";
      }
    }
    return array_merge($errors ? ['errors'=> $errors] : [], $warnings ? ['warnings'=> $warnings] : []);
  }
  
  /** affiche le contenu de l'archive en Html comme table Html sans les balises <table> et </table>
   * Si $mapCatUpdateOrCreate alors affiche la possibilité de modifier/créer l'enregistrement MapCat
   * Cela est fait en rappellant le script avec l'action updateMapCat ou insertMapCat et le num de la carte */
  function showAsHtml(bool $mapCatUpdateOrCreate=false): void {
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    $shomgeotiffUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF'])."/shomgeotiff.php";
    
    { // affichage de l'entrée du catalogue
      echo "<tr><td >catalogue</td><td>";
      if ($this->mapCat) {
        echo '<pre>',YamlDump($this->mapCat->asArray(), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
        if ($mapCatUpdateOrCreate) {
          echo "<a href='?action=updateMapCat&mapNum=$this->mapNum'>Mettre à jour cette description</a>\n";
        }
      }
      else {
        echo "Carte absente du catalogue</p>\n";
        if ($mapCatUpdateOrCreate)
          echo "<a href='?action=insertMapCat&mapNum=$this->mapNum'>Créer une description</a>\n";
      }
      echo "</td></tr>\n";
    }
    
    { // vignette
      echo "<tr><td>vignette</td>";
      if ($this->thumbnail) {
        $thumbnailUrl = $shomgeotiffUrl.$this->rpathOf7z.'/'.$this->thumbnail;
        echo "<td><a href='$thumbnailUrl'><img src='$thumbnailUrl'></a></td></tr>\n";
      }
      else {
        echo "<td>absente</td></tr>\n";
      }
    }
    
    { // caractéristiques de l'image principale
      echo "<tr><td>image<br>principale</td>";
      if (!($md = $this->main->md()))
        $md = 'No Metadata';
      echo "<td><pre>",Yaml::dump($md, 1, 2),"</pre>";
      if ($this->main->tif()) {
        $path = "maparchive.php?rpath=$this->rpathOf7z&amp;action=gdalinfo&amp;name=".$this->main->tif();
        $label = $this->main->georefLabel();
        echo "<a href='$path'>$label</a> / ";
        $imageUrl = $shomgeotiffUrl.$this->rpathOf7z.'/'.substr($this->main->tif(),0, -4).'.png'; // en PNG
        echo "<a href='$imageUrl'>Afficher l'image</a>";
      }
      echo "</td></tr>\n";
    }
    
    foreach ($this->insets as $name => $inset) { // caractéristiques de chaque cartouche
      $title = $inset->title() ?? 'NO metadata';
      $georefLabel = $inset->georefLabel();
      $gdalinfo = "maparchive.php?rpath=$this->rpathOf7z&amp;action=gdalinfo&amp;name=".$inset->tif();
      $imageUrl = $shomgeotiffUrl.$this->rpathOf7z.'/'.substr($inset->tif(),0, -4).'.png'; // en PNG
      echo "<tr><td>Cart. $name</a></td>",
           "<td>$title (<a href='$gdalinfo'>$georefLabel</a> / <a href='$imageUrl'>Afficher l'image</a>)</td></tr>\n";
    }
    
    if (count($this->insets) > 1) { // Correspondance des cartouches
      $mappingInsetsWithMapCat = $this->mappingInsetsWithMapCat();
      $action = "maparchive.php?rpath=$this->rpathOf7z&action=insetMapping";
      echo "<tr><td>Corresp.<br>cartouches<br>(archive<br>-> MapCat)</td>";
      echo "<td><pre><a href='$action'>",Yaml::dump($mappingInsetsWithMapCat),"</a></pre></td></tr>\n";
    }
    
    if ($this->suppls) { // fichiers hors spec
      echo "<tr><td>fichiers hors spec</td><td><ul>\n";
      foreach (array_keys($this->suppls) as $suppl)
        echo "<li>$suppl</li>\n";
      echo "</ul></td></tr>\n";
    }
    
    // Affichage des erreurs et alertes
    echo "<tr><td>erreurs &<br>&nbsp; alertes</td><td><pre>",
         Yaml::dump(($invalid = $this->invalid()) ? $invalid : 'aucune'),
         "</pre></td></tr>\n";
    
    { // Affichage de la carte Leaflet, du contenu de l'archive et de l'appel du dump
      echo "<tr><td colspan=2><a href='tiffmap.php?rpath=$this->rpathOf7z'>",
        "Afficher une carte Leaflet avec les images géoréférencées</a></td></tr>\n";
      echo "<tr><td colspan=2><a href='maparchive.php?rpath=$this->rpathOf7z&action=show7zContents'>",
        "Afficher la liste des fichiers contenus dans l'archive 7z</a></td></tr>\n";
      echo "<tr><td colspan=2><a href='maparchive.php?rpath=$this->rpathOf7z&action=dumpPhp'>",
        "Dump de l'objet Php</a></td></tr>\n";
    }
  }
  
  /** Affichage limité utilisé par la version CLI */
  function showAsYaml(): void {
    $mapNum = $this->mapNum;
    $record = ['mapNum'=> $mapNum];
    $mapCat = \mapcat\MapCat::get($mapNum);
    $invalid = $this->invalid();
    if (isset($invalid['errors'])) {
      $record['MapCat'] = $mapCat->asArray();
      $record['invalid'] = $invalid;
    }
    else {
      $record['title'] = $mapCat->title;
      $record['invalid'] = $invalid;
    }
    echo Yaml::dump([$record], 9, 2);
    //if (isset($invalid['errors']))
    //  print_r($this);
  }
  
  /** Affichage avec options utilisé par la version CLI 
   * @param array<string, mixed> $options */
  function showWithOptions(array $options): void {
    if ($options['yaml'] ?? null) {
      $mapCat = $this->mapCat;
      foreach ($this->insets as $name => $inset)
        $insets[$name] = $inset->asArray();
      echo Yaml::dump([$this->rpathOf7z => [
          'mapNum'=> $this->mapNum,
          'MapCat'=> $mapCat->asArray(),
          'type'=> $this->type,
          'rpathOf7z'=> $this->rpathOf7z,
          'thumbnail'=> $this->thumbnail,
          'main'=> $this->main->asArray(),
          'insets'=> $insets ?? [],
          'suppls'=> array_keys($this->suppls),
          'invalid'=> $this->invalid(),
      ]], 9, 2);
    }
    if ($options['invalid'] ?? null) {
      echo Yaml::dump([$this->rpathOf7z => $this->invalid()], 9, 2);
    }
    if ($options['errors'] ?? null) {
      echo Yaml::dump([$this->rpathOf7z => ($this->invalid()['errors'] ?? [])], 9, 2);
    }
    if ($options['php'] ?? null) {
      print_r([$this->rpathOf7z => $this]);
    }
  }
  
  // Utilisé en CLI
  // Si $path est un fichier .7z appelle showAsYaml(), Si c'est un répertoire alors effectue un appel récursif sur chaque élément
  /** @param array<string, mixed> $options */
  static function check(string $path, array $options): void {
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    if (is_file($path)) {
      if (substr($path, -3) == '.7z') {
        $rpath = substr($path, strlen($PF_PATH));
        $map = new self($rpath);
        if ($options)
          $map->showWithOptions($options);
        else
          $map->showAsYaml();
      }
      elseif (substr($path, -8) <> '.md.json') {
        echo "Alerte: Le fichier $path ne correspond pas à une carte car il ne possède pas l'extension .7z\n";
      }
    }
    elseif (is_dir($path)) {
      echo "**Répertoire $path**\n";
      foreach (new \DirectoryIterator($path) as $name) {
        if (!in_array($name, ['.','..','.DS_Store']))
          self::check("$path/$name", $options);
      }
    }
    else {
      die("Erreur: $path ni fichier ni répertoire\n");
    }
  }
};


switch (callingThisFile(__FILE__)) {
  case null: return; // Si le fichier est inclus retour
  case 'cli': { // appel en mode CLI
    if (!isset($argv[1])) {
      echo "usage: $argv[0] [{options}] {chemin_d'un_répertoire_ou_d'un_fichier.7z}\n";
      echo "Si le chemin correspond à un répertoire alors le parcours récursivement pour trouver les archives 7z\n"
        ."et vérifier la validité de chaque archive 7z comme carte ShomGT.\n";
      echo "Options:\n";
      echo "  -yaml affiche l'objet MapArchive complètement en Yaml.\n";
      echo "  -invalid affiche le résultat du test de validité de la carte.\n";
      echo "  -errors affiche les erreurs retournées par le test de validité de la carte.\n";
      echo "  -php affiche l'objet MapArchive avec print_r() de Php.\n";
      die();
    }
    $options = [];
    //print_r($argv);
    for($i=1; $i < $argc; $i++) {
      switch ($argv[$i]) {
        case '-yaml': { $options['yaml'] = true; break; }
        case '-invalid': { $options['invalid'] = true; break; }
        case '-errors': { $options['errors'] = true; break; }
        case '-php': { $options['php'] = true; break; }
        default: {
          //echo "i=$i, argv[i]=",$argv[$i],"\n";
          MapArchive::check(realpath($argv[$i]), $options);
          break;
        }
      }
    }
    die();
  }
  case 'web': { // appel en mode web
    // la plupart des appels prévoient un paramètre GET rpath qui est un chemin relatif par rapport à $PF_PATH,
    // soit celui de l'archive soit d'un répertoire. Il commence par '/'
    define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>maparchive@$_SERVER[HTTP_HOST]</title></head><body>\n");
    /** Cartes de test */
    define ('TEST_MAPS', [
        "2 cartes normales sans cartouche" => [
          '/archives/6735/6735-2012c153.7z' =>
            "6735 - Pas de Calais - De Boulogne-sur-Mer à Zeebrugge, estuaire de la Tamise (Thames)",
          '/archives/7441/7441-2022c0.7z' => "7441 - Abords et Ports de Monaco",
        ],
        "2 cartes normales avec partie principale et cartouches"=> [
          '/archives/7594/7594-2003c0.7z' => "7594 - De la Pointe Ebba au Cap de la Découverte",
          '/archives/7090/7090-2018c15.7z' => "7090 - De la Pointe de Barfleur à Saint-Vaast-la-Hougue",
        ],
        "2 cartes normales avec cartouches mais sans partie principale" => [
          '/archives/7207/7207-2020c4.7z'=> "7207 - Ports de Fécamp et du Tréport",
          '/archives/7427/7427-2016c13.7z'=> "7427-1724 - La Gironde - ...",
        ],
        "Carte 7620 mal géoréférencée" => [
          '/archives/7620/7620-2018c1.7z'=> "7620-2018c1 - Approches d'Anguilla bien géoréférencée",
          '/archives/7620/7620-2018c5.7z'=> "7620-2018c5 - Approches d'Anguilla mal géoréférencée",
        ],
        "Les cartes spéciales" => [
          '/archives/7330'=> "7330 - Action de l'Etat en Mer en Zone Maritime Atlantique",
          '/archives/7344'=> "7344 - Action de l'Etat en Mer - Zone Manche et Mer du Nord",
          '/archives/7360'=> "7360 - De Cerbère à Menton - Action de l'Etat en Mer - Zone Méditerranée",
          '/archives/8101'=> "8101 - MANCHEGRID - Carte générale",
          '/archives/8502'=> "8502 - Action de l'Etat en Mer en ZMSOI",
          '/archives/8509'=> "8509 - Action de l'Etat en Mer - Nouvelle-Calédonie - Wallis et Futun",
          '/archives/8510'=> "8510 - Délimitations des zones maritimes",
          '/archives/8517'=> "8517 - Carte simplifiée AEM des ZEE Polynésie Française et Clipperton",
          '/archives/8523'=> "8523 - Carte d'Action de l'État en Mer - Océan Atlantique Nord - Zone maritime Antilles-Guyane",
        ],
        "Cartes à cheval sur l'antiméridien" => [
          '/archives/6835'=> "6835 - Océan Pacifique Nord - Partie Est",
          '/archives/6977'=> "6977 - Océan Pacifique Nord - Partie Nord-Ouest",
          '/archives/7021'=> "7021 - Océan Pacifique Nord - Partie Sud-Ouest",
          '/archives/7271'=> "7271 - Australasie et mers adjacentes",
          '/archives/7166'=> "7166 - Océan Pacifique Sud - Partie Ouest",
          '/archives/6671'=> "6671 - Mers du Corail et des Salomon - et mers adjacentes",
          '/archives/6670'=> "6670 - Mers de Tasman et du Corail - De l'Australie à la Nouvelle-Zélande et aux Îles Fidji",
          '/archives/6817'=> "6817 - De la Nouvelle-Zélande aux îles Fidji et Samoa",
          '/archives/7283'=> "7283 - Des îles Fidji (Fiji) aux îles Tonga - Iles Wallis et Futuna",
        ],
        "Tests d'erreurs"=> [
          'path=/attente/20230628aem&map=xx'=> "Le fichier n'existe pas",
        ],
      ]
    ); // cartes de tests 
    /** nbre min d'objets pour affichage en colonnes */
    define ('MIN_FOR_DISPLAY_IN_COLS', 100);
    /** nbre de colonnes si affichage en colonnes */
    define ('NBCOLS_FOR_DISPLAY', 24);

    $login = Login::loggedIn() or die("Accès non autorisé\n");
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH') or die("Erreur variable d'environnement SHOMGT3_PORTFOLIO_PATH non définie\n");
    
    $rpath = $_GET['rpath'] ?? '';
    if ($rpath == '/TEST_MAPS') {
      echo "<h2>Cartes de test</h2>\n";
      foreach (TEST_MAPS as $sectionTitle => $testMaps) {
        echo "<h3>$sectionTitle</h3><ul>\n";
        foreach ($testMaps as $rpath => $title)
          echo "<li><a href='?rpath=$rpath'>$title</a></li>\n";
        echo "</ul>\n";
      }
    }
    elseif (is_dir($PF_PATH.$rpath)) { // la carte n'est pas définie -> sélection dans le répertoire
      echo HTML_HEAD,"<h2>Répertoires de cartes ($rpath)</h2>\n";
      $names = [];
      foreach (new \DirectoryIterator($PF_PATH.$rpath) as $name) {
        if (in_array($name, ['.','..','.DS_Store'])) continue;
        if (is_dir("$PF_PATH$rpath/$name") || (is_file("$PF_PATH$rpath/$name") && (substr($name, -3)=='.7z')))
          $names[] = (string)$name;
      }
      if (!$rpath)
        $names[] = 'TEST_MAPS';
      if (count($names) < MIN_FOR_DISPLAY_IN_COLS) { // affichage sans colonne
        echo "<ul>\n";
        foreach ($names as $name)
          echo "<li><a href='?rpath=$rpath/$name'>$name</a></li>\n";
        echo "</ul>\n";
      }
      else { // affichage en colonnes
        echo "<table border=1><tr>\n";
        $i = 0;
        for ($nocol=0; $nocol < NBCOLS_FOR_DISPLAY; $nocol++) {
          echo "<td valign='top'>\n";
          while ($i < round(count($names) / NBCOLS_FOR_DISPLAY * ($nocol+1))) {
            $name = $names[$i];
            echo "&nbsp;<a href='?rpath=$rpath/$name'>$name</a>&nbsp;<br>\n";
            $i++;
          }
          echo "</td>\n";
        }
        echo "</tr></table>\n";
      }
    }
    else { // la carte définie -> affichage ou action particulière définie par $_GET['action']
      switch ($_GET['action'] ?? null) {
        case null: {
          $mapArchive = new MapArchive($rpath);
          echo HTML_HEAD;
          echo "<h2>Carte $rpath</h2>\n";
          echo "<table border=1>";
          $mapArchive->showAsHtml();
          echo "</table>\n";
          break;
        }
        case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
          $archive = new My7zArchive($PF_PATH.$rpath);
          $tiffpath = $archive->extract($_GET['name']);
          $gdalinfo = new GdalInfoBo($tiffpath);
          $archive->remove($tiffpath);
          header('Content-type: application/json; charset="utf-8"');
          die(json_encode($gdalinfo->asArray(), JSON_OPTIONS));
        }
        case 'insetMapping': { // affiche le détail de la correspondance entre cartouches 
          $map = new MapArchive($rpath);
          $mappingInsetsWithMapCat = $map->mappingInsetsWithMapCat(true);
          echo "<pre>mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
          sort($mappingInsetsWithMapCat);
          echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
          $mapCat = $map->mapCat;
          echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
          if ($mappingInsetsWithMapCat <> $mapCat->insetTitlesSorted())
            echo "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
          die();
        }
        case 'show7zContents': { // affiche le contenu de l'archive
          $archive = new My7zArchive($PF_PATH.$rpath);
          echo HTML_HEAD,
               "<b>Contenu de l'archive $rpath:</b><br>\n",
               "<pre><table border=1><th>DateTime</th><th>Attr</th><th>Size</th><th>Compressed</th><th>Name</th>\n";
          foreach ($archive as $entry) {
            //echo Yaml::dump([$entry]);
            if ($entry['Attr'] == '....A') {
              $href = "shomgeotiff.php/$rpath/$entry[Name]";
              echo "<tr><td>$entry[DateTime]</td><td>$entry[Attr]</td><td align='right'>$entry[Size]</td>",
                   "<td align='right'>$entry[Compressed]</td><td><a href='$href'>$entry[Name]</a></td></tr>\n";
            }
            else {
              echo "<tr><td>$entry[DateTime]</td><td>$entry[Attr]</td><td align='right'>$entry[Size]</td>",
                   "<td align='right'>$entry[Compressed]</td><td>$entry[Name]</td></tr>\n";
            }
          }
          echo "</table></pre>";
          die();
        }
        case 'dumpPhp': { // affiche le print_r() Php
          $map = new MapArchive($rpath);
          echo HTML_HEAD,"<pre>"; print_r($map); echo "</pre>";
          die();
        }
        default: die("Action $_GET[action] inconnue\n");
      }
    }
    die();
  }
}