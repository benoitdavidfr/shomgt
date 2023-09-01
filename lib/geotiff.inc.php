<?php
/*PhpDoc:
title: geotiff.inc.php - définition de la classe GeoTiff
name: geotiff.inc.php
classes:
doc: |
  Tous les calculs sont effectués dans le CRS des cartes Shom qui est WGS84 World Mercator, abrévié en WoM.
  test:
    http://localhost:8081/index.php/collections/gt50k/showmap?bbox=1000,5220,1060,5280&width=6000&height=6000
journal: |
  22/5/2022:
    - modif utilisation EnvVar
  3/5/2022:
    - utilisation de la variable d'environnement SHOMGT3_MAPS_DIR_PATH
  1/5/2022:
    - chgt chemin des cartes
  25/4/2022:
    - scission de maps.php
includes:
  - envvar.inc.php
  - gdalinfo.inc.php
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
title: class GeoTiff - un GéoTiff Shom qui sait notamment se décomposer en dalles
name: Layer
methods:
doc: |
  Définit un objet correspondant à un GéoTiff.
  Le nom fait le lien avec le fichier correspondant.
  Le rectangle englobant $spatial est celui de la zone cartographiée et provient de shomgt.yaml
  Si le fichier tif est correctement géoréférencé alors le rectangle de géoréf. est lu dans le fichier .info
  et est utilisé pour connaître les bords du GéoTiff à effacer.
  Sinon, ces bords doivent être fournis à la création de l'objet GéoTiff.
  L'objet GéoTiff prend en compte le dallage qui a été effectué et sait extraire une partie de l'image à partir de ces dalles.
*/
class GeoTiff {
  const ErrorNotGeoRef = 'GeoTiff::ErrorNotGeoRef';
  const ErrorBadGeoRef = 'GeoTiff::ErrorBadGeoRef';
  
  protected string $name; // nom du GéoTiff, c'est le basename du fichier tiff.
  protected \gegeom\EBox $ebox; // rectangle englobant de géoréférencement du GéoTiff en WoM
  /** @var array<string, int> $size */
  protected array $size; // width et height du GéoTiff
  
  /*PhpDoc: methods
  name: deduceGeoRefFromBorders
  title: "deduceGeoRefFromBorders(\gegeom\EBox $spatial, array $borders): EBox - traite les GéoTiff non géo-référencés"
  doc: |
    Dans le cas où le GéoTiff n'est pas géoréférencé, calcule le rectangle de géoréférencement à partir du rectangle
    de la zone cartographiée en WoM et des tailles en pixels des bords à retirer et de la taille de l'image
  */
  /** @param array<string, int> $borders */
  function deduceGeoRefFromBorders(\gegeom\EBox $spatial, array $borders): \gegeom\EBox {
    //echo "GeoTiff::deduceGeoRefFromBorders(spatial=$spatial, borders)\n"; print_r($borders);
    // Calcul de la taille du pixel
    //echo "bottom=",eval("return $borders[bottom];"),"<br>\n";
    //echo "right=",intval($borders['right']),"<br>\n";
    $pixelSizeX = ($spatial->east() - $spatial->west()) / ($this->size['width'] - $borders['left'] - $borders['right']);
    //echo "pixelSizeX=$pixelSizeX\n";
    $pixelSizeY = ($spatial->north() - $spatial->south()) / ($this->size['height'] - $borders['top'] - $borders['bottom']);
    //echo "pixelSizeY=$pixelSizeY\n";
    if (($pixelSizeX/$pixelSizeY > 1.1) || ($pixelSizeX/$pixelSizeY < 0.9))
      throw new SExcept("Erreur de géoréférencement", self::ErrorBadGeoRef);
    $ebox = new \gegeom\EBox([
      $spatial->west() - intval($borders['left'])  * $pixelSizeX, $spatial->south() - $borders['bottom'] * $pixelSizeY,
      $spatial->east() + intval($borders['right']) * $pixelSizeX, $spatial->north() + $borders['top']    * $pixelSizeY,
    ]);
    //echo "ebox=$ebox\n";
    return $ebox;
  }
  
  /*PhpDoc: methods
  name: __construct
  title: "__construct(string $name, \gegeom\EBox $spatial, array $borders, bool $debug) - initialise un objet GéoTiff"
  doc: |
    Initialise un objet GéoTiff. $name est le basename du fichier tiff.
    Si le fichier GéoTiff correspondant est géoréférencé alors ce géoréférencement est lu dans le fichier .info
    Sinon le rectangle de géoréférencement est calculé à partir du rectangle englobant la zone cartographiée en WoM
    et des tailles en pixels des bords à retirer et de la taille de l'image. Dans ce second cas ces bords sont passés
    dans le tableau $borders qui est de la forme ['left'=> number, 'bottom'=> number, 'right'=> number, 'top'=> number]
  */
  /** @param array<string, int>|null $borders */
  function __construct(string $name, \gegeom\EBox $spatial, ?array $borders, bool $debug) {
    if ($debug)
      echo "GeoTiff::__construct($name, $spatial,",json_encode($borders),")<br>\n";
    $this->name = $name;
    $mapNum = substr($name, 0, 4);
    $gdalInfo = new GdalInfo(GdalInfo::filepath($name, false));
    $this->size = $gdalInfo->size();
    if ($borders) { // Le géoréférencement est déduit du rectangle de la zone cartographiée et des bordures
      $this->ebox = $this->deduceGeoRefFromBorders($spatial, $borders);
    }
    else {
      if (!$gdalInfo->ebox())
        throw new SExcept("le GeoTiff $name n'est pas géoréférencé et les bordures ne sont pas définies", self::ErrorNotGeoRef);
      $this->ebox = $gdalInfo->ebox();
    }
    if ($debug) {
      echo "<pre>"; print_r($this); echo "</pre>\n";
    }
  }
  
  // clone le GeoTiff pour en fabriquer une copie translatée de 360° vers l'Ouest.
  // Utilisé pour dupliquer les GéoTiffs à cheval sur l'anti-méridien.
  function translate360West(): self {
    $translate = clone $this;
    $translate->ebox = $this->ebox->geo('WorldMercator')->translate360West()->proj('WorldMercator');
    return $translate;
  }
  
  // calcul du rectangle englobant en WoM de la dalle ($i,$j)
  function tileEbox(int $i, int $j): \gegeom\EBox {
    $resx = ($this->ebox->east()  - $this->ebox->west())  / $this->size['width'];
    $resy = ($this->ebox->north() - $this->ebox->south()) / $this->size['height'];
    $xmin = $this->ebox->west() +  $i * 1024 * $resx;
    $ymax = $this->ebox->north() - $j * 1024 * $resy;
    $xmax = min($this->ebox->west() +  ($i+1) * 1024 * $resx, $this->ebox->east());
    $ymin = max($this->ebox->north() - ($j+1) * 1024 * $resy, $this->ebox->south());
    return new \gegeom\EBox([$xmin, $ymin, $xmax, $ymax]);
  }
  
  // recopie dans $dest la partie du GeoTiff correspondant à $qebox
  function copyImage(GeoRefImage $dest, \gegeom\EBox $qebox, bool $debug): void {
    if ($debug)
      echo "copyImage(qebox=$qebox)@$this->name<br>\n";
    for($i=0; $i < $this->size['width']/1024; $i++) {
      for($j=0; $j < $this->size['height']/1024; $j++) {
        //if ($debug) echo "Dalle $i x $j: ";
        $tileBbox = $this->tileEbox($i, $j);
        //if ($debug) echo "$tileBbox<br>\n";
        if ($intBbox = $qebox->intersects($tileBbox)) {
          if ($debug) {
            echo "Dalle $i x $j intersecte<br>\n";
            echo "tileBbox=$tileBbox<br>\n";
            echo "intBbox=$intBbox<br>\n";
          }
          $tile = new GeoRefImage($tileBbox);
          $filename = sprintf("%s/%s/%s/%1X-%1X.png",
            EnvVar::val('SHOMGT3_MAPS_DIR_PATH'), substr($this->name, 0, 4), $this->name, $i, $j);
          $tile->createfrompng($filename);
          $dest->copyresampled($tile, $intBbox, $debug);
        }
      }
    }
  }
};

