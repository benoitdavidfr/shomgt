<?php
/*PhpDoc:
name: mapcat.inc.php
title: mapcat.inc.php - Gestion du catalogue des cartes du Shom
classes:
doc: |
  Le catalogue est issu du service WFS du Shom et des GAN
journal: |
  29/10/2019:
    ajout des cartes AEM et MancheGrid
  28/10/2019:
    suppression de la gestion de l'historique
  1/4/2019:
    correction pattern MapBBox
  15-17/3/2019:
    refonte importante
    prises en compte de corrections du GAN définies dans gancorrections.yaml
  8/3/2019:
    fork dans ~/html/geoapi/gt/cat
  9-10/12/2018:
    Gestion de l'historique des GANs
  3/12/2018:
    gestion du pser
  21/11/2018:
    modif de l'analyse des gan pour traiter la carte FR7003
includes: [../lib/gebox.inc.php]
*/
require_once __DIR__.'/../lib/gebox.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: MapBBox
title: classe MapBBox - Gestion des BBox des cartes et des cartouches
doc: |
  Les coordonnées en dégrés minutes sont conservées
  Pour la représentation externe, l'encodage DCMI-Box est privilégié
*/
class MapBBox {
  private $sw; // point SW sous la forme [latDM, lonDM, latDD, lonDD]
  private $ne; // point NE sous la forme [latDM, lonDM, latDD, lonDD]
  
  // analyse des coordonnées d'un point en DM Lat Lon et génération en 4 valeurs latDM, lonDM, latDD, lonDD
  function analCoord(string $text): array {
    // ATTENTION: les caractères ° et - peuvent être non ASCII
    if (!preg_match("!^((\d+)[^\\d](\d\d),(\d\d)'(N|S)) . ((\d+)[^\\d]+(\d+),(\d+)'(E|W))$!u", $text, $matches))
      throw new Exception("Erreur match sur MapBBox::analCoord($text)");
    //print_r($matches);
    return [
      'latDM'=> $matches[1],
      'lonDM'=> $matches[6],
      'latDD'=> ($matches[5]=='S' ? -1 : 1) * ($matches[2] + ($matches[3] + $matches[4]/100)/60),
      'lonDD'=> ($matches[10]=='W' ? -1 : 1) * ($matches[7] + ($matches[8] + $matches[9]/100)/60),
    ];
  }
  
  function __construct(string $sw, string $ne) {
    $this->sw = $this->analCoord($sw);
    $this->ne = $this->analCoord($ne);
  }
  
  // représentation externe inspirée de l'encodage DCMI-Box en conservant la forme d'origine en degrés et minutes
  function __toString(): string {
    $sw = $this->sw;
    $ne = $this->ne;
    return "{southlimit: $sw[latDM], westlimit: $sw[lonDM], northlimit: $ne[latDM], eastlimit: $ne[lonDM]}";
  }
  
  // export des coordonnées en degrés, minutes avec documentation de chaque coordonnée
  function asArray(): array {
    return [
      'southlimit'=> $this->sw['latDM'], 'westlimit'=> $this->sw['lonDM'],
      'northlimit'=> $this->ne['latDM'], 'eastlimit'=> $this->ne['lonDM']
    ];
  }
  
  // export synthétique des coordonnées en degrés, minutes
  function asDM(): array {
    return [
      'SW'=> $this->sw['latDM'].' - '.$this->sw['lonDM'],
      'NE'=> $this->ne['latDM'].' - '.$this->ne['lonDM']
    ];
  }
  
  // export des coordonnées en DD à la DCMI-Box
  function spatial(): array {
    return [
      'southlimit'=> $this->sw['latDD'], 'westlimit'=> $this->sw['lonDD'],
      'northlimit'=> $this->ne['latDD'], 'eastlimit'=> $this->ne['lonDD'],
    ];
  }
  
  // export des coordonnées comme GBox pour laquelle w < e
  function gbox(): GBox {
    if ($this->sw['lonDD'] > $this->ne['lonDD']) // cas MapBBox à cheval sur anti-méridien 
      return new GBox([
        [ $this->sw['lonDD'], $this->sw['latDD']],
        [ $this->ne['lonDD']+360, $this->ne['latDD']],
      ]);
    else
      return new GBox([
        [ $this->sw['lonDD'], $this->sw['latDD']],
        [ $this->ne['lonDD'], $this->ne['latDD']],
      ]);
  }
};

/*PhpDoc: classes
name: MapCat
title: classe MapCat - Gestion de la description des cartes du Shom issue des GAN
doc: |
  Chaque objet de la classe MapCat décrit une carte du Shom.
  Un objet est initialisé par __construct() à partir du source Html du GAN correspondant.
  La variable statique $all est un dictionnaire sur le no de la carte précédé de FR.

  Le fichier pser contient le catalogue des cartes ainsi que la date d'actualisation.
*/
class MapCat {
  const PSER_PATH = __DIR__.'/mapcat.pser'; // chemin du fichier pser stockant le catalogue
  static $all = []; // dictionnaire des MapCat [FR{num} => MapCat]
  static $modified = null; // date d'actualisation du catalogue comme timestamp Unix
  
  private $num; // no de la carte
  private $edition; // edition de la carte
  private $remplace; // remplacement éventuel
  private $note; // note éventuelle sur l'édition
  private $facsimile; // fac-simile éventuel
  private $corrections; // corrections apportées par rapport au GAN
  private $boxes=[]; /* liste des espaces carto, le premier étant le principal, les autres les cartouches éventuels
  Pour le premier box les champs suivants sont définis:
    - superTitle évent. non défini
    - title toujours défini
    - scaleD défini uniquement si l'espace principal existe
    - bbox: MapBBox défini uniquement si l'espace principal existe
  Pour les autres box les champs suivants sont définis:
    - title
    - scaleD
    - bbox: MapBBox
  */
  
  // analyse du html pour créer l'avis Gan d'une carte
  function __construct(string $num, string $html) {
    $this->num = $num;
    if (preg_match('!<p class="erreur">([^<]*)</p>!', $html, $matches)) {
      if (preg_match('!^La carte FR[^ ]* n&rsquo;est pas en vigueur\.$!', $matches[1])) {
        $this->edition = 'notValid';
        $this->applyCorrection();
        return;
      }
      throw new Exception("Erreur: \"$matches[1]\"");
    }
    $start = strpos($html, '<table', 0);
    $start = strpos($html, '<table', $start+6);
    $end = strpos($html, '</table>', $start);
    $html = substr($html, $start, $end-$start+8);
    $html = preg_replace('!<font [^>]*>!', '', $html);
    $html = preg_replace('!</font>!', '', $html);
    $html = preg_replace('!<table [^>]*>\s*<tbody>\s*!', '<table>', $html);
    $html = preg_replace('!<td [^>]*>!', '<td>', $html);
    //echo $html;
    $doc = [];
    // je transfère chaque ligne du tableau dans un élément de $doc
    // avec la première cellule dans titre et la seconde dans echelle
    $pattern = '!^(<table>)<tr>\s*<td>([^<]*(<br>[^<]*)*)</td>\s*<td>([^<]*(<br>[^<]*)*)</td>\s*</tr>\s*!';
    while (preg_match($pattern, $html, $matches)) {
      //echo "<pre>m="; print_r($matches); echo "</pre>\n";
      $doc[] = ['titre'=> $matches[2], 'echelle'=> $matches[4]];
      $html = preg_replace($pattern, $matches[1], $html);
    }
    //echo "<pre>doc="; print_r($doc); echo "</pre>\n";
    // analyse de la partie edition qui est dans le dernier élément de $doc
    $edition = array_pop($doc)['titre'];
    $edition = trim($edition);
    //echo "edition: \"$edition\"<br>\n";
    $edition = explode('<br>', $edition);
    //echo "edition="; print_r($edition);
    $this->facsimile = '';
    if (preg_match('!^\[!', $edition[0])) {
      $this->facsimile = array_shift($edition);
      $this->facsimile = substr($this->facsimile, 1, strlen($this->facsimile)-2);
    }
    $edition[0] = trim($edition[0]);
    if (!preg_match('!^(Édition|Publication)!', $edition[0]))
      throw new Exception("erreur match sur $edition[0]");
    $this->edition = array_shift($edition);
    $edition[0] = trim($edition[0]);
    $this->remplace = '';
    if (preg_match('!^\(remplace!', $edition[0])) {
      $this->remplace = array_shift($edition);
    }
    $edition[0] = trim($edition[0]);
    $this->note = '';
    if (preg_match('!^Nota&nbsp;:!', $edition[0])) {
      $this->note = array_shift($edition);
    }
    if ((count($edition)<>1) || ($edition[0]<>'&nbsp;')) {
      echo "edition="; print_r($edition);
      throw new Exception("erreur match sur edition");
    }
    
    // analyse de la partie principale de la carte
    $doc0 = array_shift($doc);
    $titre = explode('<br>', $doc0['titre']);
    //echo "titre="; print_r($titre); echo "<br>\n";
    $titre[0] = trim($titre[0]);
    /* Les différents cas de figure de composition du titre
      {TITRE} ::= 'Carte internationale' '<br>' {TITRE2} | {TITRE2}
      {TITRE2} ::= {SuperTitle} '<br>' {title} '<br>' {bboxSW} '<br>' {bboxNE}  // cas général
                 | {SuperTitle} '<br>' {title}                                  // pas de bbox si uniq cartouches
    */
    if ($titre[0] == 'Carte internationale')
      array_shift($titre);
    // modif 21/11/2018 pour traiter FR7003
    if ((count($titre) == 4) || (count($titre) == 2)) {
      $boxes[0]['superTitle'] = array_shift($titre);
    }
    $boxes[0]['title'] = array_shift($titre);
    //echo "count=",count($titre),"<br>\n";
    if (count($titre) == 2) {
      if (!preg_match('!^1&nbsp;:((&nbsp;\d+)+)$!', $doc0['echelle'], $matches))
        throw new Exception("Erreur sur match sur $doc0[echelle]");
      //print_r($matches);
      $boxes[0]['scaleD'] = str_replace('&nbsp;','', $matches[1]);
      $boxes[0]['bbox'] = new MapBBox($titre[0], $titre[1]);
    }
    $this->boxes[0] = $boxes[0];
    
    // analyse les cartouches
    foreach ($doc as $carte) {
      $titre = explode('<br>',trim($carte['titre']));
      $titre2 = str_replace('&nbsp;', ' ', $titre[0]);
      if (preg_match('!^Cartouche : !', $titre[0], $matches))
        throw new Exception("Erreur sur match sur titre cartouche $titre2");
      $titre2 = substr($titre2, 12);
      
      if (!preg_match('!^1&nbsp;:((&nbsp;\d+)+)$!', $carte['echelle'], $matches))
        throw new Exception("Erreur sur match sur echelle cartouche $carte[echelle]");
      $scaleD = str_replace('&nbsp;','', $matches[1]);
      
      $this->boxes[] = [
        'title'=> $titre2,
        'scaleD'=> $scaleD,
        'bbox'=> new MapBBox($titre[1], $titre[2])
      ];
    }
    $this->applyCorrection();
  }
  
  // applique les corrections définies dans le fichier gancorrections.yaml qui corrige les ereurs du GAN
  function applyCorrection() {
    static $gancorrections = null;
    static $ganajouts = null;
    if (!$gancorrections) {
      $yaml = Yaml::parseFile(__DIR__.'/gancorrections.yaml');
      $gancorrections = $yaml['corrections'];
      $ganajouts = $yaml['ajouts'];
    }
    $frnum = 'FR'.$this->num;
    if (isset($gancorrections[$frnum])) {
      $corr = $gancorrections[$frnum];
      //print_r($corr);
      foreach ($this->boxes as $ibox => $box) {
        if (($ibox == 0) && isset($corr['bboxDM'])) {
          $bboxDM = $corr['bboxDM'];
          //echo "correction $frnum.boxes[$ibox]: \"$bboxDM[SW]\", \"$bboxDM[NE]\"<br>\n";
          $this->boxes[0]['bbox'] = new MapBBox($bboxDM['SW'], $bboxDM['NE']);
        }
        elseif (isset($corr['boxes'][$ibox-1]['bboxDM'])) {
          $bboxDM = $corr['boxes'][$ibox-1]['bboxDM'];
          //echo "correction $frnum.boxes[$ibox]: \"$bboxDM[SW]\", \"$bboxDM[NE]\"<br>\n";
          $this->boxes[$ibox]['bbox'] = new MapBBox($bboxDM['SW'], $bboxDM['NE']);
        } 
      }
      $this->corrections = $corr['lineage'];
    }
    elseif (isset($ganajouts[$frnum])) {
      $ajout = $ganajouts[$frnum];
      $this->edition = $ajout['issued'];
      $this->corrections = $ajout['lineage'];
      $this->boxes[0]['title'] = $ajout['title'];
      $this->boxes[0]['scaleD'] = str_replace('.', '', $ajout['scaleDenominator']);
      $this->boxes[0]['bbox'] = new MapBBox($ajout['bboxDM']['SW'], $ajout['bboxDM']['NE']);
      /*
      FR8502:
        title: 8502 - Action de l'Etat en Mer en ZMSOI
        lineage: Ajout de la carte 8502 (AEM ZMSOI) absente du GAN
        scaleDenominator: '7.904.971'
        bboxDM:
          SW: "60°00,00'S - 030°00,00'E"
          NE: "05°00,00'N - 090°00,00'E"
        issued: Publication 2010
      FR8101:
        title: 8101 - MANCHEGRID - Carte générale
        lineage: Ajout de la carte 8101 (MANCHEGRID - Carte générale) absente du GAN
        scaleDenominator: '880.000'
        bboxDM:
          SW: "48°30,00'N - 006°30,00'W"
          NE: "51°35,00'N - 002°30,00'E"
        issued: Publication 2010
      */
    }
  }
  
  // formattage de l'échelle en rajoutant un point comme séparateur de milliers
  function fmtScaleD(int $scaleD): string {
    if ($scaleD < 1e6)
      return floor($scaleD / 1000) .'.'. sprintf('%03d', $scaleD % 1000);
    else
      return floor($scaleD / 1e6)
        .'.'. sprintf('%03d', floor($scaleD/1000) % 1000)
        .'.'. sprintf('%03d', $scaleD % 1000);
  }
  
  // export d'un objet comme array Php prêt à être encodé en JSON
  function asArray(): array {
    if ($this->edition == 'notValid')
      return ['num'=> $this->num, 'issued'=> 'notValid'];
      
    $export = ['num'=> $this->num];
    if (isset($this->boxes[0]['superTitle']))
      $export['groupTitle'] = $this->boxes[0]['superTitle'];
    $export['title'] = $this->boxes[0]['title'];
    if (isset($this->boxes[0]['scaleD']))
      $export['scaleDenominator'] = $this->fmtScaleD($this->boxes[0]['scaleD']);
    if (isset($this->boxes[0]['bbox'])) {
      $export['bboxDM'] = $this->boxes[0]['bbox']->asDM();
      $export['spatial'] = $this->boxes[0]['bbox']->spatial();
    }
    $export['issued'] = str_replace('&nbsp;',' ',$this->edition);
    if ($this->remplace)
      $export['replaces'] = $this->remplace;
    if ($this->facsimile)
      $export['references'] = $this->facsimile;
    if ($this->note)
      $export['note'] = $this->note;
    if ($this->corrections)
      $export['corrections'] = $this->corrections;
    if (count($this->boxes) > 1) {
      $export['boxes'] = [];
      foreach ($this->boxes as $i => $box) {
        if ($i > 0)
          $export['boxes'][] = [
            'title'=> $box['title'],
            'scaleDenominator'=> $this->fmtScaleD($box['scaleD']),
            'bboxDM'=> $box['bbox']->asDM(),
            'spatial'=> $box['bbox']->spatial(),
          ];
      }
    }
    return $export;
  }
  
  function bbox(): ?MapBBox { return isset($this->boxes[0]['bbox']) ? $this->boxes[0]['bbox'] : null; }
  
  // renvoie la liste des cartouches avec notamment le GBox de chacun
  function boxes(): array {
    $boxes = [];
    foreach ($this->boxes as $i => $box) {
      if (!$i) continue;
      $boxes[] = [
        'title'=> $box['title'],
        'scaleDenominator'=> $box['scaleD'],
        'bboxDM'=> $box['bbox']->asDM(),
        'gbox'=> $box['bbox']->gbox(),
      ];
    }
    return $boxes;
  }
  
  // retourne 0 ssi $this et $other sont identiques
  function cmp(MapCat $other): bool {
    $json0 = json_encode($this->asArray());
    $json1 = json_encode($other->asArray());
    return strcmp($json0, $json1);
  }
  
  // Fabrique le Feature GeoJSON correspondant à la carte
  function geojson() {
    // S'il existe un espace principal alors le Feature correspond à cet espace
    if (isset($this->boxes[0]['bbox'])) {
      $properties = [
        'num'=> $this->num,
        'title'=> $this->boxes[0]['title'],
        'issued'=> $this->edition,
        'scaleDenominator'=> $this->boxes[0]['scaleD'],
        'bboxDM'=> $this->boxes[0]['bbox']->asDM(),
        //'boxes'=> [],
      ];
      if ($this->remplace)
        $properties['replaces'] = $this->remplace;
      if ($this->note)
        $properties['note'] = $this->note;
      if ($this->facsimile)
        $properties['references'] = $this->facsimile;
      foreach ($this->boxes() as $box)
        $properties['boxes'][] = $box['title'];
      return [
        'type'=> 'Feature',
        'properties'=> $properties,
        'geometry'=> [
          'type'=> 'Polygon',
          'coordinates'=> $this->boxes[0]['bbox']->gbox()->polygon(),
        ],
      ];
    }
    else { // Sinon MultiPolygon et liste des cartouches en propriétés
      $properties = [
        'num'=> $this->num,
        'title'=> $this->boxes[0]['title'],
        'issued'=> $this->edition,
      ];
      if ($this->remplace)
        $properties['replaces'] = $this->remplace;
      if ($this->note)
        $properties['note'] = $this->note;
      if ($this->facsimile)
        $properties['references'] = $this->facsimile;
      $boxes = [];
      $coords = [];
      foreach ($this->boxes() as $box) {
        $coords[] = $box['gbox']->polygon();
        unset($box['gbox']);
        $boxes[] = $box;
      }
      $properties['boxes'] = $boxes;
      return [
        'type'=> 'Feature',
        'properties'=> $properties,
        'geometry'=> [
          'type'=> 'MultiPolygon',
          'coordinates'=> $coords,
        ],
      ];
    }
  }
  
  // ajoute une carte au catalogue
  static function add(string $num, string $html, int $modified): void {
    self::$all["FR$num"] = new self($num, $html);
    if (!self::$modified)
      self::$modified = $modified;
  }
  
  // nbre de cartes dans le catalogue
  static function count(): int { return count(self::$all); }
  
  // enregistre l'ensemble du catalogue dans le fichier pser, génère un fichier mapcat.json
  static function store(): void {
    file_put_contents(self::PSER_PATH, serialize([
      'modified'=> self::$modified,
      'all'=> self::$all,
    ]));
    file_put_contents(__DIR__.'/mapcat.json',
      json_encode(
        self::allAsArray(),
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
      )
    );
  }
  
  // initialise les champs statiques de la classe à partir du fichier pser
  static function load(): void {
    if (!is_file(self::PSER_PATH)) {
      self::$all = [];
      return;
    }
    $content = unserialize(file_get_contents(self::PSER_PATH));
    self::$all = $content['all'];
    self::$modified = $content['modified'];
  }
  
  static function modified(): int {
    if (!self::$all)
      self::load();
    return self::$modified;
  }
  
  // renvoie le contenu du catalogue comme [ {frnum} => Mapcat ]
  static function all(): array {
    if (!self::$all)
      self::load();
    return self::$all;
  }
  
  // renvoi le dict des cartes indexé sur leur id sous la forme d'un array pur
  static function allAsArray(): array {
    if (!self::$all)
      self::load();
    $all = [
      'title'=> "Catalogue historisé des cartes Shom",
      'identifier'=> 'https://benoitdavidfr.github.io/shomgt/mapcat',
      'source'=> [
        'http://services.data.shom.fr/INSPIRE/wfs?SERVICE=WFS&TYPENAMES=CARTES_MARINES_GRILLE:grille_geotiff_30', // cartes echelle > 1/30K
        'http://services.data.shom.fr/INSPIRE/wfs?SERVICE=WFS&TYPENAMES=CARTES_MARINES_GRILLE:grille_geotiff_30_300', // cartes aux échelles entre 1/30K et 1/300K
        'http://services.data.shom.fr/INSPIRE/wfs?SERVICE=WFS&TYPENAMES=CARTES_MARINES_GRILLE:grille_geotiff_300_800', // cartes aux échelles entre 1/300K et 1/800K
        'http://services.data.shom.fr/INSPIRE/wfs?SERVICE=WFSTYPENAMES=CARTES_MARINES_GRILLE:grille_geotiff_800', // carte échelle < 1/800K
        'http://www.shom.fr/qr/gan/{frnum}'
      ],
      //'$schema'=> 'mapinfo.schema.yaml',
      '$schema'=> '/var/www/html/geoapi/shomgt/cat/mapcat',
      'modified'=> date(DATE_ATOM, self::$modified),
      'maps'=> [],
    ];
    foreach (self::$all as $id => $map)
      $all['maps'][$id] = $map->asArray();
    return $all;
  }
  
  // renvoie une carte du catalogue comme [ {frnum} => Mapcat ]
  static function getMap(string $frnum): ?MapCat {
    if (!self::$all)
      self::load();
    return self::$all[$frnum] ?? null;
  }

  // renvoie un FeatureCollection GeoJSON correspondant au catalogue
  static function geojsonFColl(): array {
    if (!self::$all)
      self::load();
    $features = [];
    foreach (self::$all as $id => $map) {
      $features[] = $map->geojson();
    }
    return [
      'type'=> 'FeatureCollection',
      'features'=> $features,
    ];
  }
  
  /* retourne le cartouche correspondant au GBox dans le catalogue sous la forme:
    'num': numéro de la carte
    'title': titre
    'issued': edition
    'scaleDenominator': dénominateur de l'échelle
    'gbox': GBox
    'bboxDM': bboxDM
  */
  private function getGTFromGBox(GBox $bbox): array {
    if (count($this->boxes) == 1)
      throw new Eception("Erreur, aucun cartouche dans la carte ".$this->num);
      
    if (count($this->boxes) == 2) {
      $box = $this->boxes[1];
      return [
        'num'=> $this->num,
        'title'=> $box['title'],
        'issued'=> $this->edition,
        'scaleDenominator'=> $box['scaleD'],
        'gbox'=> $box['bbox']->gbox(),
        'bboxDM'=> $box['bbox']->asDM(),
      ];
    }
  
    // je cherche le cartouche qui correspond le mieux au GeoTiff
    $boxes = [];
    // je prend le cartouche le plus proche en utilisant distbbox()
    $dmin = 9e999;
    foreach ($this->boxes as $i => $box) {
      if (!$i) continue;
      //echo "<pre>box="; print_r($box); echo "</pre>\n";
      $boxgbox = $box['bbox']->gbox();
      $dist = $bbox->distance($boxgbox);
      if ($dist < $dmin) {
        //echo "le box $box[title] correspond dist=$dist < $dmin<br>\n";
        $dmin = $dist;
        $nearestBox = [
          'num'=> $this->num,
          'title'=> $box['title'],
          'issued'=> $this->edition,
          'scaleDenominator'=> $box['scaleD'],
          'gbox'=> $boxgbox,
          'bboxDM'=> $box['bbox']->asDM(),
        ];
      }
    }
    if ($dmin == 9e999)
      throw new Eception("Aucun cartouche ne correspond");
    return $nearestBox;
  }

  /* retourne les infos du catalogue correspondant au GeoTiff $name sous la forme:
    'title': titre
    'issued': edition
    'scaleDenominator': dénominateur de l'échelle
    'gbox': GBox
    'bboxDM': bboxDM
  */
  static function getCatInfoFromGtName(string $name, GBox $bbox): array {
    // Exceptions
    if ($name == '5825/5825_1_gtw') {
      // carte "Ilot Clipperton" avec un cartouche et sans espace principal, traitée différemment entre GAN et GéoTiff
      $num = '5825'; // no de la carte
      $sid = '';
    }
    elseif (preg_match('!^(\d\d\d\d)/\d\d\d\d_pal300$!', $name, $matches)) {
      $num = $matches[1]; // no de la carte
      $sid = '';
    }
    elseif (preg_match('!^(\d\d\d\d)/\d\d\d\d_(\d+|[A-Z])_gtw$!', $name, $matches)) {
      $num = $matches[1]; // no de la carte
      $sid = $matches[2]; // id de l'espace secondaire dans GéoTiff
    }
    else
      throw new Exception("No match on $name in ".__FILE__." line ".__LINE__);
    $map = self::all()["FR$num"] ?? null;
    if (!$map)
      throw new Exception("Erreur: carte $num absente du catalogue");
    //echo "map="; print_r($map);
    if ($sid === '') {
      return [
        'num'=> $num,
        'title'=> $map->boxes[0]['title'],
        'issued'=> $map->edition,
        'scaleDenominator'=> $map->boxes[0]['scaleD'],
        'gbox'=> $map->boxes[0]['bbox']->gbox(),
        'bboxDM'=> $map->boxes[0]['bbox']->asDM(),
      ];
    }
    else
      return $map->getGTFromGBox($bbox);
  }
}


// test unitaire de la classe
if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;

if (1) { // Test getCatInfoFromGtName
  echo '<pre> ';
  print_r(MapCat::getCatInfoFromGtName('7622/7622_A_gtw', new GBox([-61.014333,14.005166, -60.984333,14.030166])));
  die("FIN TEST ligne ".__LINE__);
}

