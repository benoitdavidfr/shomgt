<?php
/* viewtiff - Visualisation et validation d'une livraison utilisant georaster-layer-for-leaflet
 * Benoit DAVID - 11-12/7/2023
*/
require_once __DIR__.'/conform.php';

// var url_to_geotiff_file = "http://localhost/shomgeotiff/incoming/20230710/6735/6735_pal300.tif";
define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>viewtiff</title></head><body>\n");
define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

function ganWeek2iso(string $ganWeek): string { // traduit une semaine GAN en date ISO 
  $date = new DateTimeImmutable();
  // public DateTimeImmutable::setISODate(int $year, int $week, int $dayOfWeek = 1): DateTimeImmutable
  $newDate = $date->setISODate((int)('20'.substr($ganWeek,0,2)), (int)substr($ganWeek, 2), 3);
  return $newDate->format('Y-m-d');
}

class MapMetadata { // construit les MD d'une carte à partir des MD ISO dans le 7z 
  // La date de revision n'est pas celle de la correction mais ed l'édition de la carte
  /** @return array<string, string> */
  static function extractFromIso19139(string $mdpath): array { // lit les MD dans le fichier ISO 19138
    if (!($xmlmd = @file_get_contents($mdpath)))
      throw new Exception("Fichier de MD non trouvé pour mdpath=$mdpath");
    $xmlmd = str_replace(['gmd:','gco:'], ['gmd_','gco_'], $xmlmd);
    $mdSxe = new SimpleXMLElement($xmlmd);
    
    $citation = $mdSxe->gmd_identificationInfo->gmd_MD_DataIdentification->gmd_citation->gmd_CI_Citation;
    //print_r($citation);
    //var_dump($citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['@attributes']['codeListValue']);
    //var_dump((string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue']);
    $md = ['title'=>
      str_replace("Image numérique géoréférencée de la carte marine ", '',
        (string)$citation->gmd_title->gco_CharacterString)];
    $md['alternate'] = (string)$citation->gmd_alternateTitle->gco_CharacterString;
    
    $edition = (string)$citation->gmd_edition->gco_CharacterString;
    // ex: Edition n° 4 - 2015 - Dernière correction : 12
    // ou: Edition n° 4 - 2022 - Dernière correction : 0 - GAN : 2241
    if (!preg_match('!^[^-]*- (\d+) - [^\d]*(\d+)( - GAN : (\d+))?$!', $edition, $matches)
    // ex: Publication 1984 - Dernière correction : 101
    // ou: Publication 1989 - Dernière correction : 149 - GAN : 2250
     && !preg_match('!^[^\d]*(\d+) - [^\d]*(\d+)( - GAN : (\d+))?$!', $edition, $matches))
       throw new Exception("Format de l'édition inconnu pour \"$edition\"");
    $anneeEdition = $matches[1];
    $lastUpdate = $matches[2];
    $gan = $matches[4] ?? '';
 
    $md['version'] = $anneeEdition.'c'.$lastUpdate;
    $md['edition'] = $edition;
    $md['ganWeek'] = $gan;
    $md['ganDate'] = $gan ? ganWeek2iso($gan) : '';
    
    $date = (string)$citation->gmd_date->gmd_CI_Date->gmd_date->gco_Date;
    $dateType = (string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue'];
    $md[$dateType] = $date;
    
    return $md;
  }

  /** @return array<string, string> */
  static function getFrom7z(string $pathOf7z): array { // retourne les MD ISO à partir du chemin de la carte en 7z 
    //echo "MapVersion::getFrom7z($pathOf7z)<br>\n";
    $creation = [];
    $archive = new SevenZipArchive($pathOf7z);
    foreach ($archive as $entry) {
      if (preg_match('!^\d+/CARTO_GEOTIFF_\d{4}_pal300\.xml$!', $entry['Name'])) { // CARTO_GEOTIFF_7107_pal300.xml
        //print_r($entry);
        if (!is_dir(__DIR__.'/temp'))
          if (!mkdir(__DIR__.'/temp'))
            throw new Exception("Erreur de création du répertoire __DIR__/temp");
        $archive->extractTo(__DIR__.'/temp', $entry['Name']);
        $mdPath = __DIR__."/temp/$entry[Name]";
        $md = self::extractFromIso19139($mdPath);
        unlink($mdPath);
        rmdir(dirname($mdPath));
        //echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
        return $md;
      }
      elseif (preg_match('!\.(tif|pdf)$!', $entry['Name'], $matches)) {
        $creation[$matches[1]] = $entry['DateTime'];
      }
    }
    // s'il n'existe pas de fichier de MD ISO alors retourne la version 'undefined'
    // et comme date de création la date de modification du fichier tif ou sinon pdf
    $creation = $creation['tif'] ?? $creation['pdf'] ?? null;
    //echo "getMapVersionFrom7z()-> undefined<br>\n";
    return [
      'version'=> 'undefined',
      'creation'=> $creation ? substr($creation, 0, 10) : null,
    ];
  }
  
  static function test(string $PF_PATH): void { // Test de la classe 
    if (0) { // @phpstan-ignore-line // Test sur une carte 
      $md = self::getFrom7z("$PF_PATH/current/7107.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (1) { // @phpstan-ignore-line // Test d'une carte spéciale 
      $md = self::getFrom7z("$PF_PATH/current/7330.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // test de toutes les cartes de current 
      foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        $md = self::getFrom7z("$PF_PATH/current/$entry");
        echo "getMapVersionFrom7z($entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
      }
    }
    elseif (0) { // @phpstan-ignore-line // Test de ttes les cartes de archives
      foreach (new DirectoryIterator("$PF_PATH/archives") as $archive) {
        if (stdEntry($archive)) continue;
        foreach (new DirectoryIterator("$PF_PATH/archives/$archive") as $entry) {
          if (substr($entry, -3) <> '.7z') continue;
          $md = self::getFrom7z("$PF_PATH/archives/$archive/$entry");
          echo "getMapVersionFrom7z($archive/$entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
        }
      }
    }
  }
};

if (!isset($_GET['path'])) { // affichage de la liste des livraisons 
  echo HTML_HEAD;
  $groups = [
    'incoming'=> "Liste des livraisons",
    'attente'=> "Liste des livraisons en attente",
    'archives'=> "Liste des archives",
  ];
  if (($_GET['action'] ?? null) == 'clean') {
    echo "<h2>Nettoyage</h2>\n";
    foreach ($groups as $gname => $title) {
      echo "$title:<ul>\n";
      foreach (new DirectoryIterator(SHOMGEOTIFF.$gname) as $incoming) {
        if (in_array($incoming, ['.','..','.DS_Store'])) continue;
        foreach (new DirectoryIterator(SHOMGEOTIFF."$gname/$incoming") as $dirname) {
          if (in_array($dirname, ['.','..','.DS_Store'])) continue;
          if (is_dir(SHOMGEOTIFF."$gname/$incoming/$dirname")) {
            echo "<li>Effacement du répertoire $gname/$incoming/$dirname<ul>\n";
            foreach (new DirectoryIterator(SHOMGEOTIFF."$gname/$incoming/$dirname") as $filename) {
              if (in_array($filename, ['.','..'])) continue;
              if (substr($filename, -3) == '.7z') { // je n'efface pas les archives 7z 
                echo "<li><b>Erreur sur $filename</b></li>\n";
              }
              else {
                echo "<li>$filename</li>\n";
                if (isset($_GET['confirm']))
                  unlink(SHOMGEOTIFF."$gname/$incoming/$dirname/$filename");
              }
            }
            if (isset($_GET['confirm']))
              rmdir(SHOMGEOTIFF."$gname/$incoming/$dirname");
            echo "</ul></li>\n";
          }
        }
      }
      echo "</ul>\n";
    }
    if (!isset($_GET['confirm']))
      echo "<a href='?action=clean&confirm=true'>confirmer ?</a><br>\n";
    die();
  }
  foreach ($groups as $gname => $title) {
    echo "$title:<ul>\n";
    foreach (new DirectoryIterator(SHOMGEOTIFF.$gname) as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      echo "<li><a href='?path=",SHOMGEOTIFF,"$gname/$incoming'>$incoming</a></li>\n";
    }
    echo "</ul>\n";
  }
  echo "<a href='?action=clean'>Nettoyage</a><br>\n";
  die();
}

if (!isset($_GET['map'])) { // affichage du contenu de la livraison 
  echo HTML_HEAD;
  echo "<h2>Livraison $_GET[path]</h2><ul>\n";
  foreach (new DirectoryIterator($_GET['path']) as $map) {
    if (substr($map, -3) == '.7z') {
      $md = MapMetadata::getFrom7z("$_GET[path]/$map");
      //echo "<li>"; print_r($md); echo "</li>";
      $mapnum = substr($map, 0, -3);
      echo "<li><a href='?path=$_GET[path]&map=$mapnum'>",$md['title'] ?? $mapnum,"</a></li>\n";
    }
  }
  die();
}

switch ($_GET['action'] ?? null) {
  case null: { // affichage des caractéristiques de la carte
    echo HTML_HEAD;
    MapCat::init();
    $map = new Map($_GET['path'], $_GET['map']);
    $map->showAsHtml($_GET['path'], $_GET['map'], MapCat::get($_GET['map']));
    die();
  }
  case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
    $gdalinfo = new GdalInfo("$_GET[path]/$_GET[map]/$_GET[tif]");
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode($gdalinfo->asArray(), JSON_OPTIONS);
    die();
  }
  case 'viewtiff': { // affichage des tiff de la carte dans Leaflet
    $incoming = substr($_GET['path'], strlen('/var/www/html/'));
    $tifs = [];
    $map = new Map($_GET['path'], $_GET['map']);
    foreach ($map->gtiffs() as $fileName) {
      echo "$fileName<br>\n";
      $tifs[substr($fileName, 5, -4)] = "http://localhost/$incoming/$_GET[map]/$fileName";
    }
    echo "<pre>tifs = "; print_r($tifs); echo "</pre>\n";
    $bounds = ($gbox = $map->gbox()) ? $gbox->latLngBounds() : [];
    echo "<pre>bounds = "; print_r($bounds); echo "</pre>\n";
    if (!$tifs || !$gbox)
      die("Affichage impossible\n");
    //die("Ok");
    break;
  }
}
?>
<html>
  <head>
    <title>viewtiff <?php echo $_GET['map']; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9/dist/leaflet.css"/>
    <style>
      #map {
        bottom: 0;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
      }
    </style>
  </head>
  <body>
    <div id="map"></div>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://unpkg.com/georaster"></script>
    <script src="https://unpkg.com/proj4"></script>
    <script src="https://unpkg.com/georaster-layer-for-leaflet"></script>
    <script>
      // initalize leaflet map
      var map = L.map('map').fitBounds(<?php echo json_encode($bounds); ?>);
      var baseLayers = {
        // OSM
        "OSM" : new L.TileLayer(
          'https://{s}.tile.osm.org/{z}/{x}/{y}.png',
          {"attribution":"&copy; les contributeurs d’<a href='http://osm.org/copyright'>OpenStreetMap</a>"}
        ),
        // Fond blanc
        "Fond blanc" : new L.TileLayer(
          'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
          { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
        )
      };
      var overlays = {};
      
<?php foreach ($tifs as $name => $path) { ?>
      fetch(<?php echo "'$path'"; ?>)
        .then(response => response.arrayBuffer())
        .then(arrayBuffer => {
          parseGeoraster(arrayBuffer).then(georaster => {
            console.log("georaster:", georaster);
            overlays[<?php echo "'$name'"; ?>] = new GeoRasterLayer({georaster: georaster, opacity: 1.0, resolution: 256});
<?php if ($name == 'pal300') { ?>
            map.fitBounds(overlays['pal300'].getBounds());
<?php } ?>
            map.addLayer(overlays[<?php echo "'$name'"; ?>]);
            
<?php } ?>
                  map.addLayer(baseLayers['OSM']);
                  L.control.layers(baseLayers, overlays).addTo(map);
<?php foreach ($tifs as $name => $path) { ?>
              });
            });
<?php } ?>
      
    </script>
  </body>
</html>
