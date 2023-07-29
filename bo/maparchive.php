<?php
/* bo/maparchive.php - Affichage et validation d'une carte - Benoit DAVID - 11-29/7/2023
 * La validation des cartes est définie d'une part par sa conformité à sa spécification
 * et, d'autre part, par sa cohérence avec MapCat.
 *
 * Les cartes normales sont spécifiées par le Shom.
 * J'y ajoute la cohérence suivante avec MapCat:
 *  - chaque carte 7z correspond à une entrée dans les cartes obsolètes ou non de MapCat
 *  - ssi la zone principale pal300 est géoréférencée alors
 *    - la carte comporte les champs spatial et scaleDenominator et
 *    - son géoréférencement contient géographiquement l'extension spatiale définie dans MapCat
 *  - il y a bijection entre les GéoTiffs de cartouche de l'archive et les cartouches de MapCat
 *  - le géoréférencement du GéoTiff contient l'extension spatiale correspondante dans MapCat
 *  - si un géoréférencement est absent ou incorrect alors il est remplacé par la définition du champ borders dans Mapcat
 *
 * Pour les cartes spéciales j'utilise la spécification suivante:
 *  - comme une carte normale, elle est livrée comme une archive 7z nommée par le numéro de la carte et l'extension .7z
 *  - dans cette archive le fichier {mapNum}/{mapNum}_pal300.tif n'existe pas
 *  - SI l'archive contient un seul .tif ou pas de .tif et un seul .pdf
 *    ALORS ce fichier .tif ou .pdf contient l'image géoréférencée ou non
 *    SINON le nom du fichier .tif ou .pdf de la carte doit être défini dans MapCat dans le champ geotiffNames
 *  - si le fichier .tif ou .pdf n'est pas géoréféréncé alors l'enregistrement MapCat doit comporter un champ borders
 *  - dans MapCat, les cartes spéciales sont identifiées par l'existence du champ layer
 * 
 * Pour les 2 types de carte:
 *  - un .tif est considéré comme géoréférencé ssi son gdalinfo contient un champ coordinateSystem
 *
 * Le script peut être soit utilisé appelé par addmaps.php soit en CLI pour tester un ensemble de cartes.
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/my7zarchive.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;

// supprime les - suivis d'un retour à la ligne dans Yaml::dump()
function YamlDump($data, int $level=3, int $indentation=2, int $options=0): string {
  $dump = Yaml::dump($data, $level, $indentation, $options);
  return preg_replace('!-\n *!', '- ', $dump);
}

// affiche un boutton HTML
function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string {
  $form =  "<form action='$action' method='$method'>";
  foreach ($hiddenValues as $name => $value)
    $form .= "  <input type='hidden' name='$name' value='$value' />";
  return $form
    ."  <input type='submit' value='$submitValue'>"
    ."</form>";
}

class Image { // Image principale ou cartouche de la carte 
  //protected string $name=''; // nom du cartouche de la forme (\d+|[A-Z]+)_gtw, '' pour l'image principale
  protected ?string $tif=null; // nom du tif dans l'archive
  protected ?string $georef; // ('ok'|'KO'|null)
  protected ?GBox $georefBox=null; // gbox de géoréférencement de l'image
  protected ?string $xml=null; // nom de l'xml dans l'archive
  protected array $md=[]; // MD synthéttiques
  
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
  
  function georefLabel() { // label associé au georef
    return match ($this->georef()) {
      null => "Image non géoréférencée",
      'ok' => "Image géoréférencée",
      'KO' => "Image mal géoréférencée",
    }
    . (($this->georefBox && $this->georefBox->astrideTheAntimeridian()) ? " à cheval sur l'antiméridien" : '');
  }
  
  function xml(): ?string { return $this->xml; }
  function md(): array { return $this->md; }
  function title(): ?string { return $this->md['title'] ?? null; }
  
  // Recherche pour ce cartouche défini dans l'archive le meilleur cartouche correspondant défini dans le catalogue
  // retourne le titre de ce meilleur cartouche
  function bestInsetMapOfCat(array $insetMapsOfCat, bool $show=false): ?string {
    $bests = []; // liste des cartouches de MapCat correspondant au cartouche de l'archive
    foreach ($insetMapsOfCat as $insetMapOfCat) {
      $spatial = new Spatial($insetMapOfCat['spatial']);
      $gbox = GBox::createFromDcmiBox($spatial->dcmiBox());
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

class MapArchive { // analyse des fichiers d'une archive d'une carte
  protected string $type; // 'normal'|'special'
  protected string $pathOf7z; // chemin chemin du fichier .7z
  protected string $mapNum; // no sur 4 chiffres
  protected ?string $thumbnail=null; // nom de la miniature dans l'archive
  protected Image $main; // les caractéristiques de l'image principale et les MD de la carte
  protected array $insets=[]; // les cartouches [{name}=> Image]
  protected array $suppls=[]; // liste de noms de fichiers hors specs sous la forme [{name} => 1]
  
  /* $pathOf7z est le chemin du fichier .7z
  ** $mapNum est le numéro de la carte sur 4 chiffres
  ** // $mapCat est l'entrée correspondant à la carte dans le catalogue
  */
  function __construct(string $pathOf7z, string $mapNum) {
    //echo "MapArchive::__construct(pathOf7z=$pathOf7z, mapNum=$mapNum)<br>\n";
    $this->pathOf7z = $pathOf7z;
    $this->mapNum = $mapNum;
    $mapCat = new MapCat($mapNum);
    if (!is_file($pathOf7z))
      throw new Exception("pathOf7z=$pathOf7z n'est pas un fichier dans MapArchive::__construct()");
    $archive = new My7zArchive($pathOf7z);
    $this->main = new Image;
    foreach ($archive as $entry) {
      //echo "<pre>"; print_r($entry); echo "</pre>\n";
      if ($entry['Attr'] <> '....A') continue; // pas un fichier
      if ($entry['Name'] == "$mapNum/$mapNum.png")
        $this->thumbnail = $entry['Name'];
      elseif ($entry['Name'] == "$mapNum/{$mapNum}_pal300.tif")
        $this->main->setTif($entry['Name'], $archive);
      elseif ($entry['Name'] == "$mapNum/CARTO_GEOTIFF_{$mapNum}_pal300.xml")
        $this->main->setXml($entry['Name'], $pathOf7z);
      elseif (preg_match("!^$mapNum/(CARTO_GEOTIFF_)?{$mapNum}_((\d+|[A-Z]+)_gtw)\.(tif|xml)$!", $entry['Name'], $matches)) {
        $name = $matches[2];
        $ext = $matches[4];
        if (!isset($this->insets[$name]))
          $this->insets[$name] = new Image;
        if ($ext == 'tif')
          $this->insets[$name]->setTif($entry['Name'], $archive);
        else // $ext == 'xml'
          $this->insets[$name]->setXml($entry['Name'], $pathOf7z);
      }
      elseif (!preg_match('!\.(gt|tfw|prj)$!', $entry['Name']))
        $this->suppls[$entry['Name']] = 1;
    }
    //echo "<pre>"; print_r($this); echo "</pre>\n";
    if ($this->main->tif()) { // cas de carte normale
      $this->type = 'normal';
    }
    else { // détection des images et MD pour une carte spéciale 
      $this->type = 'special';
      $entriesPerExt = ['tif'=>[], 'pdf'=>[], 'xml'=>[]];
      foreach (array_keys($this->suppls) as $name) { // recherche des .tif, des .pdf et des .xml
        if (preg_match('!\.(tif|pdf|xml)$!', $name, $matches)) {
          $entriesPerExt[$matches[1]][] = $name;
        }
      }
      //echo "<pre>"; print_r($entriesPerExt);
      if (count($entriesPerExt['tif']) == 1) {
        $this->main->setTif($entriesPerExt['tif'][0], $archive);
        unset($this->suppls[$entriesPerExt['tif'][0]]);
      }
      elseif ((count($entriesPerExt['tif']) == 0) && (count($entriesPerExt['pdf'])) == 1) {
        $this->main->setTif($entriesPerExt['pdf'][0], $archive);
        unset($this->suppls[$entriesPerExt['pdf'][0]]);
      }
      if (count($entriesPerExt['xml']) == 1) {
        $this->main->setXml($entriesPerExt['xml'][0], $pathOf7z);
        unset($this->suppls[$entriesPerExt['xml'][0]]);
      }
      if (!$this->main->tif() && $mapCat->geotiffNames) {
        foreach ($mapCat->geotiffNames as $geotiffName) {
          foreach (array_keys($this->suppls) as $name)  {
            if ($name == "$mapNum/$geotiffName") {
              $this->main->setTif("$mapNum/$geotiffName", $archive);
              unset($this->suppls[$name]);
              break 2;
            }
          }
        }
      }
    }
  }
  
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
  
  // construit la correspondance des cartouches de l'archive avec ceux de MapCat
  // Le résultat est un array avec en clés les noms des cartouches dans l'archive
  // et en valeurs les titres des cartouches dans MapCat
  function mappingInsetsWithMapCat(bool $show=false): array {
    $mapCat = new MapCat($this->mapNum);
    $mappingGeoTiffWithMapCat = [];
    foreach ($this->insets as $name => $inset) {
      if ($inset->tif() && $mapCat->insetMaps) {
        $mappingGeoTiffWithMapCat[$name] = $inset->bestInsetMapOfCat($mapCat->insetMaps, $show);
      }
    }
    return $mappingGeoTiffWithMapCat;
  }
  
  /* Teste la conformité à la spec et au catalogue
   * retourne [] si la carte est valide et conforme à sa description dans le catalogue
   * sinon un array comportant un au moins des 2 champs:
   *  - errors listant les erreurs
   *  - warnings listant les alertes
  */
  function invalid(): array {
    $mapCat = new MapCat($this->mapNum);
    if ($mapCat->empty())
      return ['errors'=> ["La carte n'existe pas dans le catalogue MapCat"]];
    $errors = [];
    $warnings = [];
    if (!$this->thumbnail && ($this->type == 'normal'))
      $warnings[] = "L'archive ne comporte pas de miniature";
    if (!$this->main->tif())
      return ['errors' => "Aucun GéoTiff défini pour la partie principale"];
    if (!$this->main->xml()) {
      if ($this->type == 'normal')
        $errors[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
      else
        $warnings[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
    }
    // Partie Géoréférencement de la partie principale 
    switch ($this->main->georef()) {
      case 'ok': {
        if (!$mapCat->scaleDenominator || !$mapCat->spatial)
          $errors[] = "Le fichier GéoTiff principal est géoréférencé alors que le catalogue indique qu'il ne l'est pas";
        break;
      }
      case null: { // Fichier principal non géoréférencé, 2 possibilités
        // carte normale composée uniquement de cartouches => ok ssi c'est indiqué comme telle dans le catalogue
        if ($this->type == 'normal') {
          if ($mapCat->scaleDenominator || $mapCat->spatial)
            $errors[] = "Le fichier GéoTiff principal n'est pas géoréférencé alors que le catalogue indique qu'il l'est";
          else
            $warnings[] = "Le fichier GéoTiff principal n'est pas géoréférencé ce qui est conforme au catalogue";
        }
        else { // carte spéciale géoréférencée par l'ajout de borders dans le catalogue => ok ssi borders présentes
          if (!$mapCat->borders)
            $errors[] = "Le fichier GéoTiff principal de la carte spéciale n'est pas géoréférencé"
              ." et le catalogue ne fournit pas l'information nécessaire à son géoréférencement";
          else
            $warnings[] = "Le fichier GéoTiff principal de la carte spéciale n'est pas géoréférencé"
              ." mais le catalogue fournit l'information nécessaire à son géoréférencement";
        }
        break;
      }
      case 'KO': { // Fichier principal mal géoréférencé, carte normale
        if (!$mapCat->scaleDenominator || !$mapCat->spatial)
          $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." alors que le catalogue indique qu'il n'est pas géoréférencé";
        elseif (!$mapCat->borders)
          $errors[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." et le catalogue ne fournit pas l'information nécessaire à son géoréférencement";
        else
          $warnings[] = "Le fichier GéoTiff principal est mal géoréférencé"
            ." mais cela est compensé par l'information fournie par le catalogue";
        break;
      }
    }
    // Partie cartouches
    if (count($this->insets) <> count($mapCat->insetMaps ?? []))
      $errors[] = "L'archive contient ".count($this->insets)." cartouches"
        ." alors que le catalogue en mentionne ".count($mapCat->insetMaps ?? []);
    if ($this->insets) {
      foreach ($this->insets as $name => $inset) {
        if (!$inset->tif())
          $errors[] = "Le fichier GéoTiff du cartouche $name est absent";
        if (!$inset->xml())
          $warnings[] = "Le fichier de métadonnées XML du cartouche $name est absent";
      }
      $mappingInsetsWithMapCat = $this->mappingInsetsWithMapCat();
      //echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
      sort($mappingInsetsWithMapCat);
      //echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
      //echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
      if ($mappingInsetsWithMapCat <> $mapCat->insetTitlesSorted())
        $errors[] = "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
    }
    if ($this->suppls) {
      foreach(array_keys($this->suppls) as $suppl)
        $warnings[] = "Le fichier $suppl n'est pas prévu par la spécification";
    }
    return array_merge($errors ? ['errors'=> $errors] : [], $warnings ? ['warnings'=> $warnings] : []);
  }
  
  function showAsHtml(): void {
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    $shomgeotiffUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF'])."/shomgeotiff.php";
    
    echo "<h2>Carte $_GET[map] de la livraison $_GET[path]</h2>\n";
    $mapCat = new MapCat($this->mapNum);
    echo "<table border=1>";
    
    // affichage de l'entrée du catalogue
    echo "<tr><td>catalogue</td><td><pre>",
        YamlDump($mapCat->asArray(), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
        "</td></tr>\n";
    
    // miniature
    echo "<tr><td>miniature</td>";
    if ($this->thumbnail) {
      $pathOf7zFromPfPath = substr($this->pathOf7z, strlen($PF_PATH));
      //echo "<tr><td colspan=2>pathOf7zFromPfPath=$pathOf7zFromPfPath</td></tr>\n";
      $thumbnailUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/$this->thumbnail";
      echo "<td><a href='$thumbnailUrl'><img src='$thumbnailUrl'></a></td></tr>\n";
    }
    else {
      echo "<td>absente</td></tr>\n";
    }
    
    { // caractéristiques de l'image principale
      echo "<tr><td>image<br>principale</td>";
      if (!($md = $this->main->md()))
        $md = 'No Metadata';
      echo "<td><pre>",Yaml::dump($md, 1, 2),"</pre>";
      if ($this->main->tif()) {
        $path = "?path=$_GET[path]&map=$_GET[map]&tif=".$this->main->tif()."&action=gdalinfo";
        $label = $this->main->georefLabel();
        echo "<a href='$path'>$label</a> / ";
        $pathOf7zFromPfPath = substr($this->pathOf7z, strlen($PF_PATH));
        //echo "<tr><td colspan=2>pathOf7zFromPfPath=$pathOf7zFromPfPath</td></tr>\n";
        $imageUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/".substr($this->main->tif(),0, -4).'.png';
        echo "<a href='$imageUrl'>Afficher l'image</a>";
      }
      echo "</td></tr>\n";
    }
    
    // caractéristiques de chaque cartouche
    foreach ($this->insets as $name => $inset) {
      $title = $inset->title() ?? 'NO metadata';
      $georefLabel = $inset->georefLabel();
      $gdalinfo = "?path=$_GET[path]&map=$_GET[map]&tif=".$inset->tif()."&action=gdalinfo";
      $imageUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/".substr($inset->tif(),0, -4).'.png';
      echo "<tr><td>Cart. $name</a></td>",
           "<td>$title (<a href='$gdalinfo'>$georefLabel</a> / <a href='$imageUrl'>Afficher l'image</a>)</td></tr>\n";
    }
    
    // Correspcartouches
    if (count($this->insets) > 1) {
      $mappingInsetsWithMapCat = $this->mappingInsetsWithMapCat();
      $action = "?path=$_GET[path]&map=$_GET[map]&action=insetMapping";
      echo "<tr><td>Corresp.<br>cartouches<br>(archive<br>-> MapCat)</td>";
      echo "<td><pre><a href='$action'>",Yaml::dump($mappingInsetsWithMapCat),"</a></pre></td></tr>\n";
    }
    
    // fichiers hors spec
    if ($this->suppls) {
      echo "<tr><td>fichiers hors spec</td><td><ul>\n";
      foreach (array_keys($this->suppls) as $suppl)
        echo "<li>$suppl</li>\n";
      echo "</ul></td></tr>\n";
    }
    
    // erreurs et alertes
    echo "<tr><td>erreurs &<br>&nbsp; alertes</td><td><pre>",
         Yaml::dump(($invalid = $this->invalid()) ? $invalid : 'aucun'),
         "</pre></td></tr>\n";
    
    // Affichage de la carte Leaflet, de l'appel du dump et du boutton de validation
    echo "<tr><td colspan=2><a href='?path=$_GET[path]&map=$_GET[map]&action=viewtiff'>",
      "Affichage d'une carte Leaflet avec les images géoréférencées</a></td></tr>\n";
    echo "<tr><td colspan=2><a href='?path=$_GET[path]&map=$_GET[map]&action=dumpPhp'>",
      "Dump de l'objet Php</a></td></tr>\n";
    echo "<tr><td colspan=2><center>",
         button(
          "Valider la carte et la déposer",
          [ 'action'=> 'validateMap',
            'path' => $_GET['path'],
            'map'=> $_GET['map'].'.7z',
          ],
          'addmaps.php', 'get'),
         "</center></td></tr>\n";
    
    echo "</table>\n";
  }
  
  function showAsYaml(): void {
    $mapNum = $this->mapNum;
    $record = ['mapNum'=> $mapNum];
    $mapCat = new MapCat($mapNum);
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
};

function checkIncoming(string $path): void {
  echo "**Livraison $path**\n";
  foreach (new DirectoryIterator($path) as $map) {
    if (substr($map, -3) <> '.7z') continue;
    $mapNum = substr($map, 0, 4);
    $map = new MapArchive("$path/$map", $mapNum);
    $map->showAsYaml();
    //die("Fin ok\n");
  }
}

if ((php_sapi_name() == 'cli') && ($argv[0]=='maparchive.php')) {
  if (!isset($argv[1]))
    die("usage: $argv[0] ('archives'|'incoming') [{incoming}]\n");
  $group = $argv[1];
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  if (isset($argv[2])) {
    checkIncoming("$PF_PATH/$group/$argv[2]");
  }
  else {
    foreach(new DirectoryIterator("$PF_PATH/$group") as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      checkIncoming("$PF_PATH/$group/$incoming");
    }
  }
}
