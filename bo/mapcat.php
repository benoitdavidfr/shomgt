<?php
/*PhpDoc:
title: bo/mapcat.php - gestion du catalogue MapCat et confrontation des données de localisation de MapCat avec celles du GAN
classes:
doc: |
  L'objectif est d'identifier les écarts entre mapcat et le GAN pour
    - s'assurer que mapcat est correct
    - marquer dans mapcat dans le champ badGan l'écart

  Le traitement dans le GAN des excroissances de cartes est hétérogène.
  Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.
journal: |
  13/8/2023:
    - restructuration dans le cadre du BO v4
  24/4/2023:
    - prise en compte dans CmpMapCat::scale() de la possibilité que scaleDenominator ne soit pas défini
    - prise en compte dans CmpMapCat::cmpGans() que la carte soit définie dans MapCat et absente du GAN
  3/8/2022:
    - corrections listée par PhpStan level 6
  2/7/2022:
    - reprise après correction des GAN par le Shom à la suite de mon message
    - ajout comparaison des échelles
  24/6/2022:
    - migration 
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../dashboard/gan.inc.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE html>\n<html><head><title>bo/mapcat@$_SERVER[HTTP_HOST]</title></head><body>\n";


/* Vérifie les contraintes et les exceptions du champ spatial
* contraintes:
*  - les latitudes sont comprises entre -90° et 90°
*  - la latitude North est supérieure à la latitude South
*  - les longitudes West et East sont comprises entre -180° et 180° sauf dans l'exception circumnavigateTheEarth
*  - La longitude East est supérieure à la longitude West sauf dans l'exception astrideTheAntimeridian
*  - l'exception astrideTheAntimeridian correspond à une boite à cheval sur l'anti-méridien
*    - elle est indiquée par le champ exception prenant la valeur 'astrideTheAntimeridian'
*    - sauf pour l'exception circumnavigateTheEarth
*  - l'exception circumnavigateTheEarth correspond à une boite couvrant la totalité de la Terre en longitude
*    - elle est indiquée par le champ exception prenant la valeur 'circumnavigateTheEarth'
*    - dans ce cas -180° <= West < 180° < East < 540° (360+180).
*/
class SpatialCheck extends Spatial {
  protected ?string $exception; // nom de l'exception ou null
  
  function __construct(array $spatial) {
    $this->exception = $spatial['exception'] ?? null;
    parent::__construct($spatial);
  }
  
  function badLats(): ?string {
    if (($this->sw()[1] < -90) || ($this->ne()[1] > 90))
      return "lat < -90 || > 90";
    if ($this->sw()[1] >= $this->ne()[1])
      return "south > north";
    return null;
  }
  
  function badLons(): ?string {
    if ($this->sw()[0] >= $this->ne()[0])
      return "west >= est";
    if ($this->sw()[0] < -180)
      return "west < -180";
    return null;
  }
  
  function exceptionLons(): ?string {
    if (($this->ne()[0] - $this->sw()[0]) >= 360)
      return 'circumnavigateTheEarth';
    if ($this->ne()[0] > 180)
      return 'astrideTheAntimeridian';
    return null;
  }
  
  function isBad(): ?string {
    $bad = false;
    if (($error = $this->badLats()) || ($error = $this->badLons())) {
      return $error;
    }
    if (($exception = $this->exceptionLons()) <> $this->exception) {
      return $exception;
    }
    return null;
  }

  // retourne la liste des images géoréférencées de la carte sous la forme [{id} => $info]
  static function spatials(string $mapNum, array $map): array { 
    $spatials = (isset($map['spatial'])) ? [$mapNum => $map] : [];
    //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
    foreach($map['insetMaps'] ?? [] as $i => $insetMap) {
      $spatials["$mapNum/inset$i"] = $insetMap;
    }
    return $spatials;
  }
};

// Classe stockant le contenu du fichier mapcat.yaml tel quel et définissant de la méthode cmpGans
class CmpMapCat {
  protected string $mapid;
  /** @var array<string, mixed> $map */
  protected array $map;
  /** @var array<string, CmpMapCat> $maps */
  static array $maps=[]; // [$mapid => CmpMapCat]

  function __get(string $field) { return $this->map[$field] ?? null; }
  
  /** @param array<string, mixed> $mapcat */
  static function init(array $mapcat): void {
    foreach ($mapcat['maps'] as $mapid => $map) {
      //echo "<pre>$mapid -> "; print_r($map);
      //if ($mapid=='FR7133')
      //if ($mapid=='FR7052')
      //if ($mapid=='FR6835')
      self::$maps[$mapid] = new self($mapid, $map);
    }
  }

  /** @param array<string, mixed> $map */
  function __construct(string $mapid, array $map) {
    $this->mapid = $mapid;
    $this->map = $map;
  }

  function scale(): ?string { // formatte l'échelle comme dans le GAN
    if (!isset($this->map['scaleDenominator']))
      return 'undef';
    else
      return '1 : '.str_replace('.',' ',$this->map['scaleDenominator']);
  }

  function insetScale(int $i): ?string { // formatte l'échelle comme dans le GAN
    return '1 : '.str_replace('.',' ',$this->map['insetMaps'][$i]['scaleDenominator']);
  }

  static function cmpGans(): void {
    echo "<table border=1><th>mapid</th><th>badGan</th><th>inset</th>",
      "<th>cat'scale</th><th>gan'scale</th><th>ok?</th>",
      "<th>cat'SW</th><th>gan'SW</th><th>ok?</th>",
      "<th>x</th><th>cat'NE</th><th>gan'NE</th><th>ok?</th>\n";
    foreach (self::$maps as $mapid => $map) {
      //echo "<pre>"; print_r($map); echo "</pre>";
      if (!($gan = Gan::$gans[substr($mapid, 2)] ?? null)) { // carte définie dans MapCat et absente du GAN
        echo "<tr><td>$mapid</td><td>",$map->map['badGan'] ?? '',"</td><td></td>";
        echo "<td>",$map->scale(),"</td><td colspan=9>Absente du GAN</td></tr>\n";
        continue;
      }
      //echo "<pre>gan="; print_r($gan); echo "</pre>";
      //echo "<pre>map="; print_r($map); echo "</pre>";
      if ($map->spatial && $gan->spatial()) {
        $ganspatial = [
          'SW' => str_replace('—', '-', $gan->spatial()['SW']),
          'NE' => str_replace('—', '-', $gan->spatial()['NE']),
        ];
        $mapspatial = $map->map['spatial'];
        //echo "<pre>"; print_r($map); echo "</pre>";
        if ($map->badGan || ($map->scale() <> $gan->scale())
            || ($mapspatial['SW'] <> $ganspatial['SW']) || ($mapspatial['NE'] <> $ganspatial['NE'])) {
          echo "<tr><td>$mapid</td><td>",$map->map['badGan'] ?? '',"</td><td></td>";
          echo "<td>",$map->scale(),"</td><td>",$gan->scale(),"</td>",
            "<td>",($map->scale() == $gan->scale()) ? 'ok' : '<b>KO</b>',"</td>\n";
          echo "<td>$mapspatial[SW]</td><td>$ganspatial[SW]</td>",
            "<td>",($mapspatial['SW'] == $ganspatial['SW']) ? 'ok' : '<b>KO</b',"</td>";
          echo "<td></td><td>$mapspatial[NE]</td><td>$ganspatial[NE]</td>",
            "<td>",($mapspatial['NE'] == $ganspatial['NE']) ? 'ok' : '<b>KO</b',"</td>";
          echo "</tr>\n";
        }
      }
      foreach ($map->insetMaps  ?? [] as $i => $insetMap) {
        try {
          $ganpart = Gan::$gans[substr($mapid, 2)]->inSet(GBox::fromGeoDMd($insetMap['spatial']));
          $ganpartspatial = [
            'SW' => str_replace('—', '-', $ganpart->spatial()['SW']),
            'NE' => str_replace('—', '-', $ganpart->spatial()['NE']),
          ];
          if (($ganpart->scale() <> $map->insetScale($i))
             || ($ganpartspatial['SW'] <> $insetMap['spatial']['SW'])
             || ($ganpartspatial['NE'] <> $insetMap['spatial']['NE'])) {
            echo "<tr><td>$mapid/$i</td><td>",$map->map['badGan'] ?? '',"</td><td>$insetMap[title]</td>";
            //echo "<td><pre>"; print_r($insetMap); echo "</pre></td>";
            echo "<td>",$map->insetScale($i),"</td><td>",$ganpart->scale(),"</td>",
              "<td>",($ganpart->scale() == $map->insetScale($i)) ? 'ok' : '<b>KO</b>',"</td>";
            echo "<td>",$insetMap['spatial']['SW'],"\n";
            //echo "<td><pre>"; print_r($ganpart); echo "</pre></td>";
            echo "<td>$ganpartspatial[SW]</td>",
              "<td>",$ganpartspatial['SW'] == $insetMap['spatial']['SW'] ? 'ok' : '<b>KO</b>',"</td>";
            echo "<td></td><td>",$insetMap['spatial']['NE'],"\n";
            echo "<td>$ganpartspatial[NE]</td>",
              "<td>",$ganpartspatial['NE'] == $insetMap['spatial']['NE'] ? 'ok' : '<b>KO</b>',"</td>";
            echo "</tr>\n";
          }
        }
        catch (SExcept $e) {
        }
      }
    }
    echo "</table>\n";
  }
};
//echo '<pre>maps='; print_r(MapCat::$maps);

/*PhpDoc: classes
name: GanInSet
title: class GanInSet - description d'un cartouche dans la synthèse d'une carte
*/
/*class GanInSet {
  protected string $title;
  public string $scale;
  public array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]

  function __construct(string $html) {
    //echo "html=$html\n";
    if (!preg_match('!^\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new Exception("Erreur de construction de GanInSet sur '$html'");
    $this->title = trim($matches[1]);
    $this->spatial = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }

  function asArray(): array {
    return [
      'title'=> $this->title,
      'spatial'=> $this->spatial,
    ];
  }
};*/

/*PhpDoc: classes
name: Gan
title: class Gan - synthèse des GAN par carte à la date de moisson des GAN ou indication d'erreur d'interrogation des GAN
doc: |
*/
/*class Gan {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/../dashboard/gans.'; // chemin sans extension des fichiers stockant la synthèse en pser ou en yaml,
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  static string $hvalid=''; // intervalles des dates de la moisson des GAN
  static array $gans=[]; // dictionnaire [$mapnum => Gan]

  protected string $mapnum;
  protected ?string $groupTitle=null; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title=''; // titre
  public ?string $scale=null; // échelle
  protected ?string $edition=null; // edition
  protected array $corrections=[]; // liste des corrections
  public array $spatial=[]; // sous la forme ['SW'=> sw, 'NE'=> ne]
  protected array $inSets=[]; // cartouches
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $valid; // date de moissonnage du GAN en format ISO
  protected string $harvestError=''; // erreur éventuelle du moissonnage

  static function week(string $modified): string { // transforme une date en semaine sur 4 caractères comme utilisé par le GAN 
    $time = strtotime($modified);
    return substr(date('o', $time), 2) . date('W', $time);
  }

  
};*/

switch($_GET['action'] ?? null) {
  case null: {
    echo "<h2>Gestion du catalogue MapCat</h2><h3>Menu</h3><ul>\n";
    echo "<li><a href='?action=check'>Vérifie les contraintes sur MapCat</a></li>\n";
    echo "<li><a href='?action=cmpGan'>confrontation des données de localisation de MapCat avec celles du GAN</a></li>\n";
    die();
  }
  case 'check': {
    $mapCat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    
    { // Vérifie qu'aucun no de carte apparait dans plusieurs sections
      $maps = [];
      foreach (['maps', 'obsoleteMaps', 'uninterestingMaps', 'deletedMaps'] as $section) {
        foreach ($mapCat[$section] as $mapNum => $map) {
          $maps[$mapNum][$section] = $map;
        }
      }
      $found = false;
      foreach ($maps as $mapNum => $map) {
        if (count($map) > 1) {
          echo '<pre>',Yaml::dump([$mapNum => $map]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Aucun no de carte apparait dans plusieurs sections<br>\n";
    }

    { // vérifie que toute carte de maps dont l'image principale n'est pas géoréférencée a des cartouches
      // cad que (scaleDenominator && spatial) || insetMaps toujours vrai
      $found = false;
      foreach ($mapCat['maps'] as $mapNum => $map) {
        if (!isset($map['insetMaps']) && (!isset($map['scaleDenominator']) || !isset($map['scaleDenominator']))) {
          echo '<pre>',Yaml::dump([$mapNum => $map]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Toute carte de maps dont l'image principale n'est pas géoréférencée a des cartouches<br>\n";
    }

    { // Vérifie que Le mapsFrance de toute carte de maps est <> unknown
      $found = false;
      foreach ($mapCat['maps'] as $mapNum => $map) {
        if ($map['mapsFrance'] == 'unknown') {
          echo '<pre>',Yaml::dump([$mapNum => $map]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Le mapsFrance de toute carte de maps est <> unknown<br>\n";
    }
    
    { // Vérifie les contraintes sur le champ spatial et que les exceptions sont bien indiquées
      $bad = false;
      foreach ($mapCat['maps'] as $mapNum => $map) {
        foreach(SpatialCheck::spatials($mapNum, $map) as $id => $info) {
          $spatial = new SpatialCheck($info['spatial']);
          if ($error = $spatial->isBad()) {
            echo '<pre>',Yaml::dump([$error => [$mapNum => $map]], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
            $bad = true;
          }
        }
      }
      if (!$bad) {
        echo "Tous les champs spatial respectent les contraintes<br>\n";
      }
    }
  }
  case 'cmpGan': {
    CmpMapCat::init(Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml'));
    GanStatic::loadFromPser(); // charge les GANs sepuis le fichier gans.pser du dashboard
    //echo '<pre>gans='; print_r(Gan::$gans);

    CmpMapCat::cmpGans();
  }
}
