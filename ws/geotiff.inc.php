<?php
/*PhpDoc:
name: geotiff.inc.php
title: geotiff.inc.php - gestion des GéoTiff
classes:
doc: |
  Gère les GéoTiff définis dans un fichier shomgt.yaml
  Fabrique une image correspondant à une des couches des GéoTiff.
  La couche gtpyr est la pyramide des autres couches.
  La correspondance zoom => couche est la suivante:
     0-5 20M
      6  10M
      7   4M
      8   2M
      9   1M
     10  500k
     11  250k
     12  100k
     13   50k
     14   25k
     15   12k
    16-18 5k

journal: |
  26/12/2020:
    - ajout de types dans la définition de la classe GeoTiff
  12/12/2020:
    - ajout sur chaque GeoTiff du champ mdDate, qui est la date de mise à jour des MD ISO,
      que j'utilise comme proxy de la date de dernière révision de la carte
    - génération en GeoJSON du champ mdDate et du champ ganWeek qui donne la semaine GAN déduite de mdDate
  22/11/2019:
    - gestion du cas où shomgt.pser existe alors que shomgt.yaml a été supprimé
  4/11/2019:
    - gestion num des cartes AEM et MancheGrid
  10/4/2019:
    - ajout tuiles des numéros de carte
  30/3/2019:
    - ajout du dessin des silhouettes des GéoTiff d'une couche
  14-15/3/2019:
    - ajout de fonctionnalités pour afficher le cadre de la carte
  8-11/3/2019:
    - création
includes: [../lib/gebox.inc.php, ../lib/coordsys.inc.php, ../lib/gegeom.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: GeoTiff
title: classe des GéoTiff
methods:
doc: |
  La classe définit des méthodes sur les GéoTiff et contient en statique l'ensemble des GéoTiff structuré en couches
  initialisé à partir du fichier shomgt.yaml
*/
class GeoTiff {
  static string $path; // chemin d'accès aux fichiers GéoTiff
  static array $gts=[]; // ensemble des GeoTiff organisé par couche [{lyrname}=> [ {gtname} => GeoTiff ]]
  static int $verbose = 0; // niveau de verbosité, 0==non verbeux, 1==verbeux
  
  private string $name; // nom identifiant le GéoTiff correspondant au chemin du fichier associé
  private ?string $title; // titre
  private ?int $scaleden; // dénominateur de l'échelle (optionnel)
  private ?string $edition; // édition de la carte (optionnel)
  private ?int $lastUpdate; // nombre de corrections de la carte (optionnel)
  private ?string $mdDate; // date de modification des métadonnées ISO 19139
  private GBox $gbox; // GBox en WGS84 du GéoTiff, y.c. les bordures
  private EBox $wombox; // EBox en WorldMercator du GéoTiff, y.c. les bordures
  private EBox $wboxnb; // EBox en WorldMercator du GéoTiff sans les bordures
  private int $width, $height; // largeur et hauteur du GéoTiff y compris les bordures en nbre de pixels
  private int $left, $right, $top, $bottom; // taille des bordures
  private bool $partiallyDeleted=false; // true ssi la carte est partiellement effacée
  
  // initialise l'ensemble des GéoTiff à partir, s'il existe, du fichier shomgt.pser, ou sinon de shomgt.yaml ou
  // $yamlpath est le chemin du catalogue shomgt.yaml des GéoTiff
  static function init(string $yamlpath, int $verbose=0) {
    //echo "yamlpath=$yamlpath<br>\n"; //die();
    self::$verbose = $verbose;
    $path = dirname($yamlpath).'/'.basename($yamlpath, '.yaml'); // le chemin ss l'extension
    if (!file_exists($path.'.pser') && !file_exists($path.'.yaml'))
      throw new Exception("Erreur dans GeoTiff::init() : les fichiers shomgt.yaml et shomgt.pser n'existent ni l'un ni l'autre");
    if (!file_exists($path.'.pser') || (file_exists($path.'.yaml') && (filemtime($path.'.pser') < filemtime($yamlpath)))) {
      $yaml = Yaml::parseFile($yamlpath);
      //echo "<pre>"; print_r($yaml);
      self::$path = $yaml['path'];
      foreach ($yaml as $lyrname => $gts) {
        if ((strncmp($lyrname, 'gt', 2)==0) && $gts) {
          foreach ($gts as $gtname => $gt) {
            // corrections si nécessaire de right et bottom
            if ($gt['right'] < 0)
              $gt['right'] += $gt['width'];
            if ($gt['bottom'] < 0)
              $gt['bottom'] += $gt['height'];
            self::$gts[$lyrname][$gtname] = new GeoTiff($gtname, $gt);
            if ($gt['east'] > 180.0) { // cas particulier où le GéoTiff est à cheval sur l'anti-méridien
              // création d'un 2nd GéoTiff l'autre côté de l'anti-méridien
              $gt['west'] -= 360.0;
              $gt['east'] -= 360.0;
              self::$gts[$lyrname]["$gtname-East"] = new GeoTiff($gtname, $gt);
            }
          }
        }
      }
      // sauvegarde de $path et $gts dans un fichier sérialisé pour améliorer les performances
      file_put_contents($path.'.pser', serialize([self::$path, self::$gts]));
    }
    else { // le phpser existe et est plus récent que le Yaml alors initialisation à partir du phpser
      list(self::$path, self::$gts) = unserialize(file_get_contents($path.'.pser'));
    }
    //echo "<pre>"; print_r(self::$gts);
  }
    
  /*PhpDoc: methods
  name: maketile
  title: static function maketile(string $lyrname, EBox $wombox, array $options=[]) - retourne l'image correspondant aux paramètres
  doc: |
    Pour les couches autres que gtpyr, retourne l'image correspondant au wombox.
    Pour la couche gtpyr, retourne l'image correspondant au wombox et au zoom défini par l'option zoom
    $wombox est fournie en coord. World Mercator
    $options contient aussi éventuellement la longueur et la hauteur de l'image qui valent chacune par défaut 256
  */
  static function maketile(string $lyrname, EBox $wombox, array $options=[]) {
    if (self::$verbose)
      echo "GeoTiff::maketile(lyrname=$lyrname, wombox=$wombox, options=",json_encode($options),")<br>\n";
    //echo '<pre>$gts='; print_r(self::$gts);
    $layers = []; // liste des couches à utiliser
    if ($lyrname <> 'gtpyr')
      $layers = [ $lyrname ];
    else {
      $zoom = (isset($options['zoom']) && ($options['zoom'] > 0)) ? $options['zoom'] : 0;
      $layers = ['gt20M'];
      if ($zoom >= 6)
        $layers[] = 'gt10M';
      if ($zoom >= 7)
        $layers[] = 'gt4M';
      if ($zoom >= 8)
        $layers[] = 'gt2M';
      if ($zoom >= 9)
        $layers[] = 'gt1M';
      if ($zoom >= 10)
        $layers[] = 'gt500k';
      if ($zoom >= 11)
        $layers[] = 'gt250k';
      if ($zoom >= 12)
        $layers[] = 'gt100k';
      if ($zoom >= 13)
        $layers[] = 'gt50k';
      if ($zoom >= 14)
        $layers[] = 'gt25k';
      if ($zoom >= 15)
        $layers[] = 'gt12k';
      if ($zoom >= 16)
        $layers[] = 'gt5k';
      if ($zoom >= 10) {
        // Si un GéoTiff couvre complètement la boite demandée, il est inutile d'effectuer les échelles plus petites
        $lyrs3 = [];
        foreach (array_reverse($layers) as $lyr) {
          $lyrs3[] = $lyr;
          if (isset(self::$gts[$lyr])) {
            foreach (self::$gts[$lyr] as $gt) {
              if ($gt->wboxnb()->covers($wombox) > 0.99) {
                $layers = array_reverse($lyrs3);
                break 2;
              }
            }
          }
        }
      }
    }
    $width = $options['width'] ?? 256;
    $height = $options['height'] ?? 256;
    // fabrication de l'image
    if (!($image = imagecreatetruecolor($width, $height)))
      throw new Exception("erreur de imagecreatetruecolor() ligne ".__LINE__);
    // remplissage en transparent
    if (!imagealphablending($image, false))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    $transparent = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 0x7F);
    if (!imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent))
      throw new Exception("erreur de imagefilledrectangle() ligne ".__LINE__);
    if (!imagealphablending($image, true))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    if (strncmp($lyrname, 'gt', 2) == 0) {
      // dessin d'un texte indiquant l'absence de carte, sera écrasé s'il en existe une
      $text_color = imagecolorallocate($image, 233, 14, 91);
      if (!imagestring($image, 4, 50, 100,  "Pas de carte SHOM", $text_color))
        throw new Exception("erreur de imagestring() ligne ".__LINE__);
      foreach ($layers as $layer)
        if (isset(self::$gts[$layer]))
          foreach (self::$gts[$layer] as $gt)
            $gt->imagecopytiles($image, $wombox); // plus complexe mais plus économe en mémoire
            //$gt->imagecopy($image, $wombox); // plus simple mais trop gourmand en mémoire
    }
    elseif (strncmp($lyrname, 'num', 3) == 0) { // couche d'étiquettes du numéro de chaque carte
      $lyrname = str_replace('num', 'gt', $lyrname);
      if (isset(self::$gts[$lyrname]))
        foreach (self::$gts[$lyrname] as $gt)
          $gt->drawLabel($image, $wombox, $width, $height);
    }
    else
      throw new Exception("Couche $lyrname inconnue");
    
    if (!imagesavealpha($image, true))
      throw new Exception("erreur de imagesavealpha() ligne ".__LINE__);
    return $image;
  }
  
  /*PhpDoc: methods
  name: drawOutline
  title: static function drawOutline($image, string $layer, EBox $wombox, int $width, int $height) - dessine sur l'image les silhouettes des GéoTiff de la couche $layername dans le rectangle $wombox
  doc: |
    Test Monde entier:
     http://localhost/geoapi/shomgt/ws/wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=-20037508,-15538711,20037508,15538711&CRS=EPSG:3857&WIDTH=948&HEIGHT=735&LAYERS=gt50k&STYLES=&FORMAT=image/png&TRANSPARENT=TRUE
     http://localhost/geoapi/shomgt/ws/wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=-7977949.091245136224,1814613.089056419674,8231889.115369650535,9972436.364056419581&CRS=EPSG:3857&WIDTH=1532&HEIGHT=771&LAYERS=gt50k&STYLES=&FORMAT=image/png&TRANSPARENT=TRUE
  */
  static function drawOutline($image, string $layername, EBox $wombox, int $width, int $height) {
    $color = imagecolorallocate($image, 255, 0, 0);
    if (isset(self::$gts[$layername])) {
      foreach (self::$gts[$layername] as $gt) {
        if (!($int = $gt->wboxnb->intersects($wombox)))
          continue; // si le GeoTiff n'intersecte pas la $wombox alors on le passe
        $x1 = round(($int->west() - $wombox->west()) / $wombox->dx() * $width);
        $y1 = round(($wombox->north()-$int->north()) / $wombox->dy() * $height);
        $x2 = round(($int->east() - $wombox->west()) / $wombox->dx() * $width);
        $y2 = round(($wombox->north()-$int->south()) / $wombox->dy() * $height);
        imagerectangle($image, $x1, $y1, $x2, $y2, $color);
      }
    }
  }
  
  static function geojson(?string $lyrname='', ?EBox $wombox=null): array {
    $features = [];
    if (!$lyrname) {
      foreach (self::$gts as $lyrname => $gts) {
        foreach ($gts as $gtname => $gt) {
          if ($feature = $gt->geojsonf($lyrname, $gtname, $wombox))
            $features[] = $feature;
        }
      }
      return [
        'type'=> 'FeatureCollection',
        'features'=> $features,
      ];
    }
    if (!isset(self::$gts[$lyrname]))
      return ['error'=> "couche $lyrname inexistante"];
    foreach (self::$gts[$lyrname] as $gtname => $gt) {
      if ($feature = $gt->geojsonf($lyrname, $gtname, $wombox))
        $features[] = $feature;
    }
    return [
      'type'=> 'FeatureCollection',
      'features'=> $features,
    ];
  }
  
  // récupère un GéoTiff par son id
  static function get(string $gtname): ?GeoTiff {
    foreach (self::$gts as $lyrname => $gts) {
      if (isset($gts[$gtname]))
        return $gts[$gtname];
    }
    return null;
  }
  
  // initialise un GeoTiff à partir des infos du catalogue shomgt.yaml
  // peut aussi utiliser des paramètres générés par imagecopytiles()
  function __construct(string $name, array $params) {
    //print_r($params);
    $this->name = $name;
    $this->title = $params['title'] ?? null;
    $this->scaleden = $params['scaleden'] ?? null;
    $this->edition = $params['edition'] ?? null;
    $this->lastUpdate = $params['lastUpdate'] ?? null;
    $this->mdDate = $params['mdDate'] ?? null;
    $this->width = $params['width'];
    $this->height = $params['height'];
    $this->left = $params['left'];
    $this->top = $params['top'];
    $this->right = $params['right'];
    $this->bottom = $params['bottom'];
    $this->partiallyDeleted = isset($params['partiallyDeleted']);
    if (isset($params['wombox']))
      $this->wombox = $params['wombox']; // EBox en WorldMercator du GéoTiff avec bordures
    else { // fabrication de wombox à partir de la boite en coord. géo.
      $this->gbox = new GBox([$params['west'], $params['south'], $params['east'], $params['north']]);
      $this->wombox = $this->gbox->proj('WorldMercator'); // bbox avec bordures
    }
    $this->wboxnb = new EBox([
      $this->wombox->west() + ($this->left * $this->wombox->dx() / $this->width),
      $this->wombox->south() + ($this->bottom * $this->wombox->dy() / $this->height),
      $this->wombox->east() - ($this->right * $this->wombox->dx() / $this->width),
      $this->wombox->north() - ($this->top * $this->wombox->dy() / $this->height),
    ]);
  }
  
  // affiche les caractéristiques principales d'un objet GéoTiff
  function __toString(): string {
    return json_encode(['class'=>'GeoTiff', 'name'=> $this->name, 'wboxnb'=> $this->wboxnb->round()->asArray()]);
  }
  
  function gbox(): GBox { return $this->gbox; }
  function wombox(): EBox { return $this->wombox; }
  function wboxnb(): EBox { return $this->wboxnb; }
  
  function width() { return $this->width; }
  function height() { return $this->height; }
  
  function resx() { return $this->wombox->dx() / $this->width; } // resolution en X
  function resy() { return $this->wombox->dy() / $this->height; } // resolution en Y
  
  // numéro de carte à afficher dans le catalogue
  function num() {
    // zone principale
    if (preg_match('!^(\d+)/\d+_pal300$!', $this->name, $matches))
      return $matches[1];
    // cartouche
    if (preg_match('!^(\d+)/\d+_([^_]+)_gtw$!', $this->name, $matches))
      return "$matches[1]/$matches[2]";
    // AEM + MancheGrid
    if (preg_match('!^(\d+)/\d+(_\d+)?$!', $this->name, $matches))
      return $matches[1];
    return $this->name;
  }
  
  /*PhpDoc: methods
  name: imagecopytiles
  title: function imagecopytiles(resource $image, EBox $bbox) - recopie dans l'image GD le morceau de GéoTiff correspondant à la bbox (en coord. WorldMercator)
  doc: |
    Plutot que de lire le GéoTiff pour extraire l'image adhoc comme le fait imagecopy(), il est plus efficace
    de lire des dalles.
    La présente méthode fabrique les dalles correspondant au GéoTiff courant et effectue sur chacune un imagecopy().
    Pour les premières dalles en colonne (resp. en ligne), le paramètre left (resp. top) sont définis.
    Les dernières dalles en colonne (resp. en ligne) ont comme largeur (resp. hauteur) le reste
  */
  function imagecopytiles($image, EBox $bbox): bool {
    //echo "imagecopytiles() sur this="; print_r($this);
    if (!$this->wboxnb->intersects($bbox)) // si la bbox demandée n'intersecte pas la bbox du GéoTiff
      return false; // alors retour sans rien faire
    $imax = floor(($this->width - $this->right)/1024); // nbre de dalles en X
    $jmax = floor(($this->height - $this->bottom)/1024); // nbre de dalles en Y
    for ($i=0; $i<=$imax; $i++) {
      if ($this->left >= ($i+1) * 1024) // premières colonnes entièrement dans la bordure
        continue; // je ne génère pas la dalle correspondante
      for ($j=0; $j<=$jmax; $j++) {
        if ($this->top > ($j+1) * 1024) // premières lignes entièrement dans la bordure
          continue;
        $tilename = sprintf('%s/%X-%X', $this->name, $i, $j);
        // je crée la dalle comme un GéoTiff
        if (self::$verbose)
          echo "tile $tilename<br>\n";
        // cas général
        $wombox = $this->wombox;
        // calcul des paramètres de la dalle $i $j dans le cas général
        $params = [
          'name'=> $tilename,
          'wombox'=> new EBox([
            $wombox->west()  + $i * $this->resx() * 1024,
            $wombox->north() - ($j+1) * $this->resy() * 1024,
            $wombox->west()  + ($i+1) * $this->resx() * 1024,
            $wombox->north() - $j * $this->resy() * 1024,
          ]),
          'width'=> 1024,
          'height'=> 1024,
          'left'=> 0,
          'right'=> 0,
          'top'=> 0,
          'bottom'=> 0,
        ];
        // cas particuliers
        if ($i*1024 < $this->left) // colonne a cheval sur le bord
          $params['left'] = $this->left - $i*1024;
        if ($i == $imax) { // dernière colonne
          // cas où la réelle dernière colonne est entièrement dans la bordure, il s'agit donc de la précédente
          if ($imax <> floor($this->width/1024)) {
            $params['right'] = (($i+1) * 1024) - ($this->width - $this->right);
          }
          else { // cas général
            $params['wombox']->setEast($wombox->east());
            $params['width'] = $this->width - $i * 1024;
            $params['right'] = $this->right;
          }
        }
        if ($j*1024 < $this->top) // ligne a cheval sur le bord
          $params['top'] = $this->top - $j*1024;
        if ($j == $jmax) { // dernière ligne
          // cas où la réelle dernière ligne est entièrement dans la bordure, il s'agit donc de la précédente
          if ($jmax <> floor($this->height/1024)) {
            $params['bottom'] = (($j+1) * 1024) - ($this->height - $this->bottom);
          }
          else {
            $params['wombox']->setSouth($wombox->south());
            $params['height'] = $this->height - $j * 1024;          
            $params['bottom'] = $this->bottom;
          }
        }
        // création d'un pseudo GéoTiff correspodant à une dalle
        $tile = new GeoTiff($tilename, $params);
        // j'effectue la copie d'image à partir de ce dernier GéoTiff
        $tile->imagecopy($image, $bbox);
      }
    }
    return true;
  }
    
  
  /*PhpDoc: methods
  name: imagecopy
  title: function imagecopy($image, EBox $bbox) - recopie dans l'image GD le morceau de GéoTiff défini par la bbox
  doc: |
    L'image est étirée ou réduite pour correspondre à la taille définie de l'image.
    $bbox est en coord. WorldMerc. 
    Cette méthode pourrait fonctionner pour un GéoTiff mais nécessiterait alors de charger en mémoire son image.
    Dans un souci de performance, la copie s'effectue par dalle en utilisant la méthode imagecopytiles()
    qui fabrique un objet GeoTiff pour chaque dalle et appelle dessus imagecopy()
  */
  function imagecopy($image, EBox $bbox): bool {
    if (!($int = $this->wboxnb->intersects($bbox))) {
      return false;
    }
    if (self::$verbose) {
      echo "GeoTiff::imagecopy(image, bbox=$bbox)@$this<br>\n";
      echo "Le GeoTiff $this->name intersecte bbox: $int<br>\n";
    }
    $path = __DIR__.'/'.self::$path.$this->name.'.png'; // le chemin du GéoTiff stocké en png
    if (!($gtimg = @imagecreatefrompng($path)))
      throw new Exception("Erreur ouverture du GeoTiff $path impossible");
    $width = imagesx($image);
    $height = imagesy($image);
    $dst_x = round(($int->west() - $bbox->west()) / $bbox->dx() * $width);
    $dst_y = round(($bbox->north() - $int->north()) / $bbox->dy() * $height);
    $src_x = round(($int->west() - $this->wboxnb->west()) / $this->resx()) + $this->left;
    $src_y = round(($this->wboxnb->north() - $int->north()) / $this->resy()) + $this->top;
    $dst_w = round(($int->east() - $int->west()) / $bbox->dx() * $width);
    $dst_h = round(($int->north() - $int->south()) / $bbox->dy() * $height);
    $src_w = round(($int->east() - $int->west()) / $this->resx());
    $src_h = round(($int->north() - $int->south()) / $this->resy());
    if (self::$verbose)
      echo "dst_x=$dst_x, dst_y=$dst_y, src_x=$src_x, src_y=$src_y, dst_w=$dst_w, dst_h=$dst_h, src_w=$src_w, src_h=$src_h<br>\n";
    if (($dst_w <= 0) || ($dst_h <= 0) || ($src_w <= 0) || ($src_h <= 0))
      return false;
    if (!imagecopyresampled($image, $gtimg, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h)) {
      echo "<pre>int="; print_r($int); echo "</pre>\n";
      echo "dst_x=$dst_x, dst_y=$dst_y, src_x=$src_x, src_y=$src_y, dst_w=$dst_w, dst_h=$dst_h, src_w=$src_w, src_h=$src_h<br>\n";      
      throw new Exception("Erreur imagecopyresampled() ligne ".__LINE__);
    }
    return true;
  }
  
  /* transforme une position dans l'espace utilisateur en WorldMercator en une position dans l'image
  function toImageCS(array $pos): array {
    return [
      round(($pos[0] - $this->wombox->west()) / $this->resx()),
      round(($this->wombox->north() - $pos[1]) / $this->resy()),
    ];
  }*/
  
  /* transforme une position dans l'espace image en une position dans l'espace utilisateur en WorldMercator
  function toUserCS(array $pos): array {
    return [
      $this->wombox->west()  + $pos[0] * $this->resx(),
      $this->wombox->north() - $pos[1] * $this->resy(),
    ];
  }*/
  
  // dessine le cadre de la carte
  function drawFrame($image, int $color, int $alpha=0) {
    $color = imagecolorallocatealpha($image, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, $alpha);
    if ($color === FALSE)
      throw new Exception("Erreur imagecolorallocatealpha() ligne ".__LINE__);
    $width = imagesx($image);
    $height = imagesy($image);
    $x1 = round($this->left * $width / $this->width);
    $y1 = round($this->top * $height / $this->height);
    $x2 = round(($this->width - $this->right) * $width / $this->width);
    $y2 = round(($this->height - $this->bottom) * $height / $this->height);
    // bord West
    if (!imagefilledrectangle($image, 0, 0, $x1, $height-1, $color))
      throw new Exception("Erreur imagerectangle() ligne ".__LINE__);
    // bord East
    if (!imagefilledrectangle($image, $x2, 0, $width-1, $height-1, $color))
      throw new Exception("Erreur imagerectangle() ligne ".__LINE__);
    // bord Nord
    if (!imagefilledrectangle($image, $x1+1, 0, $x2-1, $y1, $color))
      throw new Exception("Erreur imagerectangle() ligne ".__LINE__);
    // bord Sud
    if (!imagefilledrectangle($image, $x1+1, $y2, $x2-1, $height-1, $color))
      throw new Exception("Erreur imagerectangle() ligne ".__LINE__);
  }
  
  /*PhpDoc: methods
  name: drawLabel
  title: "function drawLabel($image, EBox $bbox, int $width, int $height): bool - dessine dans l'image GD le numéro de la carte"
  */
  function drawLabel($image, EBox $bbox, int $width, int $height): bool {
    //echo "title= ",$this->title,"<br>\n";
    if (!($int = $this->wboxnb->intersects($bbox))) {
      return false;
    }
    $x = round(($this->wboxnb->west() - $bbox->west()) / $bbox->dx() * $width);
    $y = round(- ($this->wboxnb->north() - $bbox->north()) / $bbox->dy() * $height);
    //echo "x=$x, y=$y<br>\n"; die();
    $font = 3;
    $bg_color = imagecolorallocate($image, 255, 255, 0);
    $dx = strlen($this->num()) * imagefontwidth($font);
    $dy = imagefontheight($font);
    imagefilledrectangle($image, $x+2, $y, $x+$dx, $y+$dy, $bg_color);
    $text_color = imagecolorallocate($image, 255, 0, 0);
    // bool imagestring ( resource $image , int $font , int $x , int $y , string $string , int $color )
    imagestring($image, $font, $x+2, $y, $this->num(), $text_color);
    //die();
    return true;
  }
  
  function ganWeek(): string {
    $ganWeek = '1715'; // par défaut, c'est a peu près la date des premières livraisons
    if ($this->mdDate) { // Si la date de mise à jour des MD est remplie alors je prends pour ganweek cette semaine
      $time = strtotime($this->mdDate);
      $ganWeek = substr(date('o', $time), 2) . date('W', $time);
    }
    return $ganWeek;
  }
  
  /*PhpDoc: methods
  name: geojsonf
  title: "function geojsonf(string $lyrname, string $gtname, ?EBox $wombox): array - génère un array Php correspondant au GeoJSON du GéoTiff"
  */
  function geojsonf(string $lyrname, string $gtname, ?EBox $wombox): array {
    //print_r($this);
    if ($wombox && !$this->wboxnb->intersects($wombox))
      return [];
    return [
      'type'=> 'Feature',
      'properties'=> [
        'layer'=> $lyrname,
        'gtname'=> $gtname,
        'title'=> $this->title,
        'scaleden'=> $this->scaleden,
        'edition'=> $this->edition,
        'lastUpdate'=> $this->lastUpdate,
        'mdDate'=> $this->mdDate,
        'ganWeek'=> $this->ganWeek(),
        'width'=> $this->width,
        'height'=> $this->height,
        'left'=> $this->left,
        'top'=> $this->top,
        'right'=> $this->right,
        'bottom'=> $this->bottom,
      ],
      'geometry'=> [
        'type'=> 'Polygon',
        'coordinates'=> $this->wboxnb->geo('WorldMercator')->polygon(),
      ],
    ];
  }
  
  // export sous la forme d'un array structuré comme les paramètres de construction d'un GéoTiff
  function asArray(): array {
    return [
      'name'=> $this->name,
      'title'=> $this->title,
      'scaleden'=> $this->scaleden,
      'edition'=> $this->edition,
      'lastUpdate'=> $this->lastUpdate,
      'mdDate'=> $this->mdDate,
      'ganWeek'=> $this->ganWeek(),
      'west'=> $this->gbox->west(),
      'south'=> $this->gbox->south(),
      'east'=> $this->gbox->east(),
      'north'=> $this->gbox->north(),
      'width'=> $this->width,
      'height'=> $this->height,
      'left'=> $this->left,
      'bottom'=> $this->bottom,
      'right'=> $this->right,
      'top'=> $this->top,
    ];
  }
  
  // génère un nouvel objet avec des marges à 0
  function withFrame(): GeoTiff {
    $params = $this->asArray();
    $params['left'] = 0;
    $params['bottom'] = 0;
    $params['right'] = 0;
    $params['top'] = 0;
    return new GeoTiff($params['name'], $params);
  }
};


if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe GeoTiff
  //ini_set('memory_limit', '1280M');
  require __DIR__.'/../lib/gegeom.inc.php';
  $verbose = !isset($_GET['noverbose']);
  //$tileid = 'gt20M/7/63/44';
  //$tileid = 'gt20M/6/37/23';
  //$tileid = 'gtpyr/6/37/23';
  //$tileid = 'gt10M/6/37/23';
  $tileid = 'gt10M/5/28/13';
  $tileids = explode('/', $tileid);
  $wembox = Zoom::tileEBox($tileids[1], $tileids[2], $tileids[3]);
  $geobox = $wembox->geo('WebMercator');
  if ($verbose)
    echo "geobox=$geobox<br>\n";
  $wombox = $geobox->proj('WorldMercator');
  if ($verbose)
    echo "wombox=$wombox<br>\n";
  GeoTiff::init(__DIR__.'/shomgt.yaml', $verbose);
  $image = GeoTiff::maketile($tileids[0], $wombox, ['zoom'=>$tileids[1]]);
  if ($verbose) {
    echo "<table border=1><th>shomgt</th><th>gt</th><tr>\n";
    $href = "http://geoapi.fr/shomgt/tile.php/$tileid.png";
    echo "<td><a href='$href'><img src='$href'></a></td>\n";
    $href = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]?noverbose=1";
    echo "<td><a href='$href'><img src='$href'></a></td>\n";
    echo "</tr></table>\n";
    die();
  }
  else {
    header('Content-type: image/png');
    imagepng($image);
  }
}
