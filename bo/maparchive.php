<?php
/* bo/maparchive.php - Validation d'une carte - Benoit DAVID - 11-27/7/2023
 * La validation des cartes est définie d'une part par sa conformité à sa spécification
 * et, d'autre part, par sa cohérence avec MapCat.
 *
 * Les cartes normales sont spécifiées par le Shom.
 * J'y ajoute la cohérence suivante avec MapCat:
 *  - chaque carte 7z correspond à une entrée dans les cartes non obsolètes de MapCat
 *  - ssi la zone principale pal300 est géoréférencée alors
 *    - la carte comporte les champs spatial et scaleDenominator et
 *    - son géoréférencement contient l'extension spatiale définie dans MapCat
 *  - il y a bijection entre les GéoTiffs de cartouche de l'archive et les cartouches de MapCat
 *  - le géoréférencement du GéoTiff contient l'extension spatiale correspondante dans MapCat
 *  - si un géoréférencement est absent ou incorrect alors il est remplacé par la définition du champ borders dans Mapcat
 *
 * Pour les cartes spéciales j'utilise la spécification suivante:
 *  - comme une carte normale, elle est livrée comme une archive 7z nommée par le numéro de la carte et l'extension .7z
 *  - dans cette archive le fichier {mapNum}/{mapNum}_pal300.tif n'existe pas
 *  - SI l'archive contient un seul .tif ou pas de .tif et un seul .pdf
 *    ALORS ce fichier .tif ou .pdf contient la carte géoréférencée ou non
 *    SINON le nom du fichier .tif ou .pdf de la carte doit être défini dans MapCat dans le champ geotiffNames
 *  - si le fichier .tif ou .pdf n'est pas géoréféréncé alors l'enregistrement MapCat doit comporter un champ borders
 *  - dans MapCat, les cartes spéciales sont identifiées par l'existence du champ layer
 * 
 * Pour les 2 types de carte:
 *  - un .tif est considéré comme géoréférencé ssi son gdalinfo contient un champ coordinateSystem
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/SevenZipArchive.php';
require_once __DIR__.'/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;

class MapCat { // Un objet MapCat correspond à l'entrée du catalogue correspondant à une carte
  protected array $cat; // contenu de l'entrée du catalogue correspondant à une carte
  static array $maps=[]; // contenu du champ maps de MapCat
  
  // retourne l'entrée du catalogue correspondant à $mapNum sous la forme d'un objet MapCat
  function __construct(string $mapNum) {
    if (!self::$maps)
      self::$maps = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml')['maps'];
    $this->cat = self::$maps["FR$mapNum"] ?? [];
  }
  
  function __get(string $property) { return $this->cat[$property] ?? null; }
  
  function asArray(): array { return $this->cat; }
  
  function spatials(): array { // retourne la liste des extensions spatiales sous la forme [nom => spatial]
    $spatials = $this->spatial ? ['image principale de la carte'=> $this->spatial] : [];
    //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
    foreach ($this->insetMaps ?? [] as $i => $insetMap) {
      $spatials[$insetMap['title']] = $insetMap['spatial'];
    }
    return $spatials;
  }
};

class MapArchive { // analyse des fichiers d'une archive d'une carte
  protected string $type; // 'normal'|'special'
  protected string $pathOf7z; // chemin chemin du fichier .7z
  protected string $mapNum; // no sur 4 chiffres
  protected ?string $thumbnail=null; // nom de la miniature dans l'archive
  protected array $main=[]; // ['tif'=> {fileName}, 'xml'=>{filename}, 'georef'=>('ok'|'KO'|null) 'gbox'=>?GBox]
  protected array $insets=[]; // [{name}=> ['tif'=> {fileName}, 'xml'=>{filename}]]
  protected array $suppls=[]; // liste de noms de fichiers hors specs sous la forme [{name} => 1]
  
  /* $pathOf7z est le chemin du fichier .7z
  ** $mapNum est le numéro de la carte sur 4 chiffres
  ** $mapCat est l'entrée correspondant à la carte dans le catalogue
  */
  function __construct(string $pathOf7z, string $mapNum, MapCat $mapCat) {
    //echo "pathOf7z=$pathOf7z, mapNum=$mapNum<br>\n";
    $this->pathOf7z = $pathOf7z;
    $this->mapNum = $mapNum;
    $archive = new SevenZipArchive($pathOf7z);
    foreach ($archive as $entry) {
      //echo "<pre>"; print_r($entry); echo "</pre>\n";
      if ($entry['Attr'] <> '....A') continue; // pas un fichier
      if ($entry['Name'] == "$mapNum/$mapNum.png")
        $this->thumbnail = $entry['Name'];
      elseif ($entry['Name'] == "$mapNum/{$mapNum}_pal300.tif")
        $this->main['tif'] = $entry['Name'];
      elseif ($entry['Name'] == "$mapNum/CARTO_GEOTIFF_{$mapNum}_pal300.xml")
        $this->main['xml'] = $entry['Name'];
      elseif (preg_match("!^$mapNum/{$mapNum}_((\d+|[A-Z]+)_gtw)\.tif$!", $entry['Name'], $matches))
        $this->insets[$matches[1]]['tif'] = $entry['Name'];
      elseif (preg_match("!^$mapNum/CARTO_GEOTIFF_{$mapNum}_((\d+|[A-Z]+)_gtw)\.xml$!", $entry['Name'], $matches))
        $this->insets[$matches[1]]['xml'] = $entry['Name'];
      elseif (!preg_match('!\.(gt|tfw|prj)$!', $entry['Name']))
        $this->suppls[$entry['Name']] = 1;
    }
    //echo "<pre>"; print_r($this); echo "</pre>\n";
    if (isset($this->main['tif'])) { // cas de carte normale
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
        $this->main['tif'] = $entriesPerExt['tif'][0];
        unset($this->suppls[$entriesPerExt['tif'][0]]);
      }
      elseif ((count($entriesPerExt['tif']) == 0) && (count($entriesPerExt['pdf'])) == 1) {
        $this->main['tif'] = $entriesPerExt['pdf'][0];
        unset($this->suppls[$entriesPerExt['pdf'][0]]);
      }
      if (count($entriesPerExt['xml']) == 1) {
        $this->main['xml'] = $entriesPerExt['xml'][0];
        unset($this->suppls[$entriesPerExt['xml'][0]]);
      }
      if (!isset($this->main['tif']) && isset($mapCat->geotiffNames)) {
        foreach ($mapCat->geotiffNames as $geotiffName) {
          foreach (array_keys($this->suppls) as $name)  {
            if ($name == "$mapNum/$geotiffName") {
              $this->main['tif'] = "$mapNum/$geotiffName";
              unset($this->suppls[$name]);
              break 2;
            }
          }
        }
      }
    }
    if (isset($this->main['tif'])) {
      $archive->extractTo(__DIR__.'/temp', $this->main['tif']);
      $gdalinfo = new GdalInfo(__DIR__.'/temp/'.$this->main['tif']);
      $georef = $gdalinfo->georef();
      $this->main['georef'] = $georef;
      $this->main['gbox'] = $georef ? $gdalinfo->gbox() : null;
      unlink(__DIR__.'/temp/'.$this->main['tif']);
      rmdir(__DIR__.'/temp/'.dirname($this->main['tif']));
    }
  }
  
  function gtiffs(): array { // retourne la liste des GéoTiffs géoréférencés
    $gtiffs = [];
    if ($this->main && ($this->main['georef']=='ok'))
      $gtiffs[] = $this->main['tif'];
    foreach ($this->insets as $inset)
      $gtiffs[] = $inset['tif'];
    return $gtiffs;
  }
  
  // Retourne le GBox de l'image principale SI elle existe et est géoréférencée
  // SINON
  //   S'il y a une image principale non géoréférencée
  //   ALORS
  //     S'il y a des cartouches
  //     ALORS retourne l'union des cartouches
  //     SINON retourne null
  //   SINON retourne null // il n'y a pas d'image principale <=> carte spéciale
  function gbox(): ?GBox { // retourne null ssi aucun tif géoréférencé
    if (!$this->main)
      return null;
    if ($this->main['gbox'])
      return $this->main['gbox'];
    if ($this->insets) {
      if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
        throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
      $archive = new SevenZipArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
      $gbox = new GBox;
      foreach ($this->insets as $inset) {
        $archive->extractTo(__DIR__.'/temp', $inset['tif']);
        $gdalinfo = new GdalInfo(__DIR__."/temp/$inset[tif]");
        $gbox->union($gdalinfo->gbox());
        unlink(__DIR__."/temp/$inset[tif]");
        rmdir(__DIR__.'/temp/'.dirname($inset['tif']));
      }
      return $gbox;
    }
    return null;
  }
  
  /* Teste la conformité à la spec et au catalogue
   * retourne [] si la carte livrée est valide et conforme à sa description dans le catalogue
   * sinon un array comportant un au moins des 2 champs:
   *  - errors listant les erreurs
   *  - warnings listant les alertes
  */
  function invalid(MapCat $mapCat): array {
    if (!$mapCat)
      return ['errors'=> ["La carte n'existe pas dans le catalogue MapCat"]];
    $errors = [];
    $warnings = [];
    if (!$this->thumbnail && ($this->type == 'normal'))
      $warnings[] = "L'archive ne comporte pas de miniature";
    if (!isset($this->main['tif']))
      return ['errors' => "Aucun GéoTiff défini pour la partie principale"];
    if (!isset($this->main['xml'])) {
      if ($this->type == 'normal')
        $errors[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
      else
        $warnings[] = "L'archive ne comporte pas de fichier de métadonnées XML pour la partie principale";
    }
    // Partie Géoréférencement
    switch ($this->main['georef']) {
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
    foreach ($this->insets as $name => $inset) {
      if (!isset($inset['tif']))
        $errors[] = "Le fichier GéoTiff du cartouche $name est absent";
      if (!isset($inset['xml']))
        $warnings[] = "Le fichier de métadonnées XML du cartouche $name est absent";
    }
    if ($this->suppls) {
      foreach(array_keys($this->suppls) as $suppl)
        $warnings[] = "Le fichier $suppl n'est pas prévu par la spécification";
    }
    return array_merge($errors ? ['errors'=> $errors] : [], $warnings ? ['warnings'=> $warnings] : []);
  }
  
  function showAsHtml(MapCat $mapCat): void {
    echo "<h2>Carte $_GET[map] de la livraison $_GET[path]</h2>\n";
    echo "<table border=1>";
    echo "<tr><td>cat</td><td><pre>",Yaml::dump($mapCat->asArray(), 6),"</td></tr>\n";
    if ($this->thumbnail) {
      $shomgeotiffUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]".dirname($_SERVER['PHP_SELF'])."/shomgeotiff.php";
      if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
        throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
      $pathOf7zFromPfPath = substr($this->pathOf7z, strlen($PF_PATH));
      //echo "<tr><td colspan=2>pathOf7zFromPfPath=$pathOf7zFromPfPath</td></tr>\n";
      $thumbnailUrl = "$shomgeotiffUrl$pathOf7zFromPfPath/$this->thumbnail";
      echo "<tr><td>miniature</td><td><a href='$thumbnailUrl'><img src='$thumbnailUrl'></a></td></tr>\n";
    }
    else {
      echo "<tr><td>miniature</td><td>absente</td></tr>\n";
    }
    $md = MapMetadata::getFrom7z($this->pathOf7z);
    if (!$md)
      $md = 'No Metadata';
    if (isset($this->main['tif'])) {
      $georef = "?path=$_GET[path]&map=$_GET[map]&tif=".$this->main['tif']."&action=gdalinfo";
      echo "<tr><td><a href='$georef'>principal</a></td>";
    }
    else {
      echo "<tr><td>principal</td>";
    }
    echo "<td><pre>"; print_r($md); echo "</pre></td></tr>\n";
    foreach ($this->insets as $name => $inset) {
      $md = isset($inset['xml']) ? MapMetadata::getFrom7z($this->pathOf7z, $inset['xml']) : ['title'=>'NO metadata'];
      $georef = "?path=$_GET[path]&map=$_GET[map]&tif=$inset[tif]&action=gdalinfo";
      echo "<tr><td><a href='$georef'>$name</a></td><td>$md[title]</td></tr>\n";
    }
    if ($this->suppls) {
      echo "<tr><td>fichiers hors spec</td><td><ul>\n";
      foreach (array_keys($this->suppls) as $suppl)
        echo "<li>$suppl</li>\n";
      echo "</ul></td></tr>\n";
    }
    echo "<tr><td>erreurs</td><td><pre>",
         Yaml::dump(($invalid = $this->invalid($mapCat)) ? $invalid : 'aucun'),
         "</pre></td></tr>\n";
    echo "</table>\n";
    echo "<a href='?path=$_GET[path]&map=$_GET[map]&action=viewtiff'>Affichage des TIFF avec Leaflet</a><br>\n";
    echo "<pre>"; print_r($this); echo "</pre>";
  }
  
  function showAsYaml(string $mapNum, array $mapCat): void {
    $record = ['mapNum'=> $mapNum];
    if ($invalid = $this->invalid($mapCat)) {
      $record['MapCat'] = $mapCat;
      $record['invalid'] = $invalid;
    }
    else {
      $record['title'] = $mapCat['title'];
      $record['invalid'] = 'aucun';
    }
    echo Yaml::dump([$record], 9, 2);
    if ($invalid)
      print_r($this);
  }
};

function checkIncoming(string $path): void {
  echo "**Livraison $path**\n";
  foreach (new DirectoryIterator($path) as $map) {
    if (substr($map, -3) <> '.7z') continue;
    $mapNum = substr($map, 0, 4);
    $map = new MapArchive("$path/$mapNum.7z", $mapNum);
    $map->showAsYaml($mapNum, MapCat::get($mapNum));
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