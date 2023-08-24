<?php
namespace bo;
/*PhpDoc:
name: maparchive.php
title: bo/maparchive.php - Affichage et validation d'une archive 7z de carte - Benoit DAVID - 7-8/2023
doc: |
  La validation des cartes est définie d'une part par sa conformité à sa spécification
  et, d'autre part, par sa cohérence avec MapCat.
 
  Voir les critères de conformité des archives de cartes dans shomgt4.yaml
 
  Le script est utilisé de 2 manières:
   - soit inclus dans addmaps.php et viewmap.php, lui-même appelé par différents autre scripts
   - soit en CLI pour tester un ensemble de cartes.
   - soit en no CLI pour visualiser une archive
*/
require_once __DIR__.'/../vendor/autoload.php';
#require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/mapcat.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/my7zarchive.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;

class GeoRefImage { // Image principale ou cartouche de la carte 
  protected ?string $tif=null; // chemin du tif dans l'archive
  protected ?string $georef; // ('ok'|'KO'|null)
  protected ?GBox $georefBox=null; // gbox de géoréférencement de l'image
  protected ?string $xml=null; // nom de l'xml dans l'archive
  /** @var array<string,mixed> $md */
  protected array $md=[]; // MD synthéttiques
  
  /** @return array<string, mixed> */
  function asArray(): array {
    return [
      'tif'=> $this->tif,
      'georef'=> $this->georef,
      'georefBox'=> $this->georefBox->__toString(),
      'xml'=> $this->xml,
      'md'=> $this->md,
    ];
  }
  
  function setTif(string $tif, My7zArchive $archive): void {
    $this->tif = $tif;
    $tifPath = $archive->extract($tif);
    $gdalinfo = new GdalInfo($tifPath);
    $this->georef = $gdalinfo->georef();
    $this->georefBox = $this->georef ? $gdalinfo->gbox() : null;
    $archive->remove($tifPath);
  }
  
  function setXml(string $xml, string $pathOf7z): void {
    $this->xml = $xml;
    $this->md = MapMetadata::getFrom7z($pathOf7z, $xml);
  }

  function tif(): ?string { return $this->tif; }
  function georef(): ?string { return $this->georef; }
  function georefBox(): ?GBox { return $this->georefBox; }
  
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

class MapArchive { // analyse les fichiers d'une archive d'une carte pour évaluersa validité et afficher le contenu
  //const FORCE_VALIDATION = false; // vrai ssi possibilité de forcer la validation d'une carte invalide
  protected string $type='undefined'; // 'undefined'|'normal'|'special'
  protected string $pathOf7z; // chemin du fichier .7z
  protected string $mapNum; // no sur 4 chiffres
  protected ?string $thumbnail=null; // chemin de la vignette dans l'archive
  protected GeoRefImage $main; // les caractéristiques de l'image principale et les MD de la carte
  /** @var array<string, GeoRefImage> */
  protected array $insets=[]; // les cartouches [{name}=> GeoRefImage]
  /** @var array<string, int> */
  protected array $suppls=[]; // liste de noms de fichiers hors specs sous la forme [{name} => 1]
  protected ?\mapcat\MapCatItem $mapCat; // enregistrement correspondant à la carte dans MapCat

  /* $pathOf7z est le chemin du fichier .7z
  ** $mapNum est le numéro de la carte sur 4 chiffres
  */
  function __construct(string $pathOf7z, string $mapNum) {
    //echo "MapArchive::__construct(pathOf7z=$pathOf7z, mapNum=$mapNum)<br>\n";
    if (!is_file($pathOf7z))
      throw new \Exception("pathOf7z=$pathOf7z n'est pas un fichier dans MapArchive::__construct()");
    $this->pathOf7z = $pathOf7z;
    $this->mapNum = $mapNum;
    $this->mapCat = \mapcat\MapCat::get($mapNum);
    $archive = new My7zArchive($pathOf7z);
    $this->main = new GeoRefImage;
    foreach ($archive as $entry) {
      //echo "<pre>"; print_r($entry); echo "</pre>\n";
      if ($entry['Attr'] <> '....A') continue; // pas un fichier
      if ($entry['Name'] == "$mapNum/$mapNum.png")
        $this->thumbnail = $entry['Name'];
      elseif ($entry['Name'] == "$mapNum/{$mapNum}_pal300.tif") {
        $this->type = 'normal';
        $this->main->setTif($entry['Name'], $archive);
      }
      elseif ($entry['Name'] == "$mapNum/CARTO_GEOTIFF_{$mapNum}_pal300.xml")
        $this->main->setXml($entry['Name'], $pathOf7z);
      elseif (preg_match("!^$mapNum/(CARTO_GEOTIFF_)?{$mapNum}_((\d+|[A-Z]+)_gtw)\.(tif|xml)$!", $entry['Name'], $matches)) {
        $name = $matches[2];
        $ext = $matches[4];
        if (!isset($this->insets[$name]))
          $this->insets[$name] = new GeoRefImage;
        if ($ext == 'tif')
          $this->insets[$name]->setTif($entry['Name'], $archive);
        else // $ext == 'xml'
          $this->insets[$name]->setXml($entry['Name'], $pathOf7z);
      }
      elseif (preg_match("!^$mapNum/{$mapNum}_\d{4}\.(tif|pdf)$!", $entry['Name'], $matches)) {
        $this->type = 'special';
        if (($matches[1] == 'tif') || !$this->main->tif()) { // le .tif a priorité sur le .pdf
          $this->main->setTif($entry['Name'], $archive);
        }
      }
      elseif (preg_match("!^$mapNum/[^/]+\.xml$!", $entry['Name']))
        $this->main->setXml($entry['Name'], $pathOf7z);
      elseif (preg_match("!^$mapNum/[^/]+$!", $entry['Name']) && !preg_match('!\.(gt|tfw|prj)$!', $entry['Name']))
        $this->suppls[$entry['Name']] = 1;
    }
  }
  
  /** @return array<int, string> */
  function gtiffs(): array { // retourne la liste des GéoTiffs géoréférencés
    $gtiffs = [];
    if ($this->main->georef() == 'ok')
      $gtiffs[] = $this->main->tif();
    foreach ($this->insets as $inset)
      $gtiffs[] = $inset->tif();
    return $gtiffs;
  }
  
  /* Retourne le GBox de l'image principale SI elle existe et est géoréférencée
   * SINON
   *   S'il n'y a pas d'image principale
   *   ALORS retourne null // cas d'erreur
   *   SINON // il y a une image principale et elle n'est pas géoréférencée
   *     S'il y a des cartouches
   *     ALORS retourne l'union des cartouches
   *     SINON retourne null // cas d'erreur, evt carte spéciale
   */
  function georefBox(): ?GBox { // retourne null ssi aucun tif géoréférencé
    if ($this->main->georefBox())
      return $this->main->georefBox();
    if ($this->insets) {
      $georefBox = new GBox;
      foreach ($this->insets as $inset) {
        $georefBox->union($inset->georefBox());
      }
      return $georefBox;
    }
    return null;
  }
  
  /** construit la correspondance des cartouches de l'archive avec ceux de MapCat
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
  
  /** Teste la conformité à la spec et au catalogue
   * retourne [] si la carte est valide et conforme à sa description dans le catalogue
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
        if (!$this->mapCat->scaleDenominator || !$this->mapCat->spatial)
          $errors[] = "Le fichier GéoTiff principal est géoréférencé alors que le catalogue indique qu'il ne l'est pas";
        elseif (!$this->georefBox()->includes($this->mapCat->spatial()))
          $errors[] = "L'extension spatiale définie dans MapCat n'est pas inclues dans le géoréférencement de l'archive";
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
  
  function showAsHtml(?string $return=null): void { // affiche le contenu de l'archive en Html 
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    $shomgeotiffUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF'])."/shomgeotiff.php";
    
    echo "<h2>Carte $_GET[map] de la livraison $_GET[path]</h2>\n";
    echo "<table border=1>";
    
    { // affichage de l'entrée du catalogue
      echo "<tr><td >catalogue</td><td>";
      if ($this->mapCat) {
        echo '<pre>',YamlDump($this->mapCat->asArray(), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
        if ($return == 'addmaps')
          echo "<a href='mapcat.php?action=updateMapCatId&mapnum=$_GET[map]&return=addmaps'>",
                "Mettre à jour cette description</a>\n";
      }
      else {
        echo "Carte absente du catalogue</p>\n";
        echo "<a href='mapcat.php?action=insertMapCat&mapnum=$_GET[map]&return=addmaps'>",
              "Créer une description</a>\n";
      }
      echo "</td></tr>\n";
    }
    
    { // miniature
      echo "<tr><td>vignette</td>";
      if ($this->thumbnail) {
        $pathOf7zFromPfPath = substr($this->pathOf7z, strlen($PF_PATH));
        //echo "<tr><td colspan=2>pathOf7zFromPfPath=$pathOf7zFromPfPath</td></tr>\n";
        $thumbnailUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/$this->thumbnail";
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
      $pathOf7zFromPfPath = substr($this->pathOf7z, strlen($PF_PATH));
      if ($this->main->tif()) {
        $path = "?path=$_GET[path]&map=$_GET[map]&tif=".$this->main->tif()."&action=gdalinfo";
        $label = $this->main->georefLabel();
        echo "<a href='$path'>$label</a> / ";
        //echo "<tr><td colspan=2>pathOf7zFromPfPath=$pathOf7zFromPfPath</td></tr>\n";
        $imageUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/".substr($this->main->tif(),0, -4).'.png';
        echo "<a href='$imageUrl'>Afficher l'image</a>";
      }
      echo "</td></tr>\n";
    }
    
    foreach ($this->insets as $name => $inset) { // caractéristiques de chaque cartouche
      $title = $inset->title() ?? 'NO metadata';
      $georefLabel = $inset->georefLabel();
      $gdalinfo = "?path=$_GET[path]&map=$_GET[map]&tif=".$inset->tif()."&action=gdalinfo";
      $imageUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/".substr($inset->tif(),0, -4).'.png';
      echo "<tr><td>Cart. $name</a></td>",
           "<td>$title (<a href='$gdalinfo'>$georefLabel</a> / <a href='$imageUrl'>Afficher l'image</a>)</td></tr>\n";
    }
    
    if (count($this->insets) > 1) { // Correspondance des cartouches
      $mappingInsetsWithMapCat = $this->mappingInsetsWithMapCat();
      $action = "?path=$_GET[path]&map=$_GET[map]&action=insetMapping";
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
         Yaml::dump(($invalid = $this->invalid()) ? $invalid : 'aucun'),
         "</pre></td></tr>\n";
    
    // Affichage de la carte Leaflet, du contenu de l'archive et de l'appel du dump
    echo "<tr><td colspan=2><a href='tiffmap.php?path=$_GET[path]&map=$_GET[map]'>",
      "Afficher une carte Leaflet avec les images géoréférencées</a></td></tr>\n";
    echo "<tr><td colspan=2><a href='?path=$_GET[path]&map=$_GET[map]&action=show7zContents'>",
      "Afficher la liste des fichiers contenus dans l'archive 7z</a></td></tr>\n";
    /*echo "<tr><td colspan=2><a href='?path=$_GET[path]&map=$_GET[map]&action=dumpPhp'>",
      "Dump de l'objet Php</a></td></tr>\n";*/
    
    if ($return == 'addmaps') { // ajout éventuel d'un bouton de validation
      $validateButton = Html::button(
          submitValue: "Valider la carte et la déposer",
          hiddenValues: [
            'action'=> 'validateMap',
            'path' => $_GET['path'],
            'map'=> $_GET['map'].'.7z',
          ],
          action: 'addmaps.php',
          method: 'get'
      );
      if (!isset($invalid['errors'])) { // cas normal, pas d'erreur => bouton de validation
        echo "<tr><td colspan=2><center>$validateButton</center></td></tr>\n";
      }
      elseif (self::FORCE_VALIDATION) { // @phpstan-ignore-line // cas où il y a une erreur mais la validation peut être forcée
        echo "<tr><td colspan=2><center>",
               "<b>La carte n'est pas valide mais sa validation peut être forcée</b>",
               "$validateButton</center></td></tr>\n";
      }
      else { // cas d'erreur normale, la validation n'est pas possible
        echo "<tr><td colspan=2><center>",
             "<b>La carte ne peut pas être validée en raison des erreurs</b>",
             "</center></td></tr>\n";
      }
    }
    
    echo "</table>\n";
    
    if ($return == 'addmaps') { // Retour éventuel vers addmaps sans validation
      echo "<a href='addmaps.php'>Retour au menu de dépôt de cartes</p>\n";
    }
  }
  
  function showAsYaml(): void { // Affichage limité utilisé par la version CLI 
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
  
  /** @param array<string, mixed> $options */
  function showWithOptions(array $options): void { // Affichage avec options utilisé par la version CLI 
    if ($options['yaml'] ?? null) {
      $mapCat = \mapcat\MapCat::get($this->mapNum);
      foreach ($this->insets as $name => $inset)
        $insets[$name] = $inset->asArray();
      echo Yaml::dump([$this->pathOf7z => [
          'mapNum'=> $this->mapNum,
          'MapCat'=> $mapCat->asArray(),
          'type'=> $this->type,
          'pathOf7z'=> $this->pathOf7z,
          'thumbnail'=> $this->thumbnail,
          'main'=> $this->main->asArray(),
          'insets'=> $insets ?? [],
          'suppls'=> array_keys($this->suppls),
          'invalid'=> $this->invalid(),
      ]], 9, 2);
    }
    if ($options['invalid'] ?? null) {
      echo Yaml::dump([$this->pathOf7z => $this->invalid()], 9, 2);
    }
    if ($options['errors'] ?? null) {
      echo Yaml::dump([$this->pathOf7z => ($this->invalid()['errors'] ?? [])], 9, 2);
    }
    if ($options['php'] ?? null) {
      print_r([$this->pathOf7z => $this]);
    }
  }
  
  // Utilisé en CLI
  // Si $path est un fichier .7z appelle showAsYaml(), Si c'est un répertoire alors effectue un appel récursif sur chaque élément
  /** @param array<string, mixed> $options */
  static function check(string $path, array $options): void {
    if (is_file($path)) {
      if (substr($path, -3) == '.7z') {
        $mapNum = substr(basename($path), 0, 4);
        $map = new self($path, $mapNum);
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


switch (\bo\callingThisFile(__FILE__)) {
  case null: return;
  case 'cli': {
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
    for($i=1; $i < $argc; $i++) {
      switch ($argv[$i]) {
        case '-yaml': { $options['yaml'] = true; break; }
        case '-invalid': { $options['invalid'] = true; break; }
        case '-errors': { $options['errors'] = true; break; }
        case '-php': { $options['php'] = true; break; }
        default: {
          //echo "i=$i, argv[i]=",$argv[$i],"\n";
          MapArchive::check($argv[$i], $options);
          break;
        }
      }
    }
    die();
  }
  case 'web': {
    define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>maparchive@$_SERVER[HTTP_HOST]</title></head><body>\n");
    define ('MIN_FOR_DISPLAY_IN_COLS', 100); // nbre min d'objets pour affichage en colonnes
    define ('NBCOLS_FOR_DISPLAY', 24); // nbre de colonnes si affichage en colonnes

    $login = Login::loggedIn() or die("Accès non autorisé\n");
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH') or die("Erreur variable d'environnement SHOMGT3_PORTFOLIO_PATH non définie\n");
    
    if (!isset($_GET['map'])) { // la carte n'est pas définie -> sélection
      $rpath = $_GET['path'] ?? '';
      echo HTML_HEAD,"<h2>Répertoires de cartes ($rpath)</h2>\n";
      $names = [];
      foreach (new \DirectoryIterator("$PF_PATH/$rpath") as $name) {
        if (in_array($name, ['.','..','.DS_Store'])) continue;
        if (is_dir("$PF_PATH/$rpath/$name") || (is_file("$PF_PATH/$rpath/$name") && (substr("$PF_PATH/$rpath/$name", -3)=='.7z')))
          $names[] = (string)$name;
      }
      if (count($names) < MIN_FOR_DISPLAY_IN_COLS) { // affichage sans colonne
        echo "<ul>\n";
        foreach ($names as $name) {
          if (is_dir("$PF_PATH/$rpath/$name"))
            echo "<li><a href='?path=$rpath/$name'>$name</a></li>\n";
          else
            echo "<li><a href='?path=$rpath&amp;map=",substr($name, 0, -3),"'>$name</a></li>\n";
        }
        echo "</ul>\n";
      }
      else { // affichage en colonnes
        echo "<table border=1><tr>\n";
        $i = 0;
        for ($nocol=0; $nocol < NBCOLS_FOR_DISPLAY; $nocol++) {
          echo "<td valign='top'>\n";
          while ($i < round(count($names) / NBCOLS_FOR_DISPLAY * ($nocol+1))) {
            $name = $names[$i];
            if (is_dir("$PF_PATH/$rpath/$name"))
              echo "&nbsp;<a href='?path=$rpath/$name'>$name</a>&nbsp;<br>\n";
            else
              echo "&nbsp;<a href='?path=$rpath&amp;map=",substr($name, 0, -3),"'>$name</a></li>\n";
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
          $mapNum = substr($_GET['map'], 0, 4);
          $mapArchive = new MapArchive("$PF_PATH/$_GET[path]/$_GET[map].7z", $mapNum);
          echo HTML_HEAD;
          $mapArchive->showAsHtml();
          break;
        }
        case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
          $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
          $path = $archive->extract($_GET['tif']);
          $gdalinfo = new GdalInfo($path);
          header('Content-type: application/json; charset="utf-8"');
          echo json_encode($gdalinfo->asArray(), JSON_OPTIONS);
          $archive->remove($path);
          die();
        }
        case 'insetMapping': { // affiche le détail de la correspondance entre cartouches 
          $mapNum = substr($_GET['map'], 0, 4);
          $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
          $mappingInsetsWithMapCat = $map->mappingInsetsWithMapCat(true);
          echo "<pre>mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
          sort($mappingInsetsWithMapCat);
          echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
          $mapCat = \mapcat\MapCat::get($mapNum);
          echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
          if ($mappingInsetsWithMapCat <> $mapCat->insetTitlesSorted())
            echo "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
          die();
        }
        case 'show7zContents': { // affiche le contenu de l'archive
          $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
          echo HTML_HEAD,
               "<b>Contenu de l'archive $_GET[map].7z:</b><br>\n",
               "<pre><table border=1><th>DateTime</th><th>Attr</th><th>Size</th><th>Compressed</th><th>Name</th>\n";
          foreach ($archive as $entry) {
            //echo Yaml::dump([$entry]);
            if ($entry['Attr'] == '....A') {
              $href = "shomgeotiff.php/$_GET[path]/$_GET[map].7z/$entry[Name]";
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
          $mapNum = substr($_GET['map'], 0, 4);
          $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
          echo HTML_HEAD,"<pre>"; print_r($map); echo "</pre>";
          die();
        }
        default: {
          die("Action $_GET[action] inconnue\n");
        }
      }
    }
    die();
  }
}