<?php
/** définition des classes Gan et GanInSet pour gérer et utiliser les GAN
 * @package shomgt\gan
 */
namespace gan;

//require_once __DIR__.'/../dashboard/portfolio.inc.php';

use Symfony\Component\Yaml\Yaml;


/** Teste si à la fois $now - $first >= 1 jour et il existe une $dayOfWeekT$h:$m  entre $first et $now */
function dateBetween(\DateTimeImmutable $first, \DateTimeImmutable $now, int $dayOfWeek=4, int $h=20, int $mn=0): bool {
  //echo 'first = ',$first->format(DateTimeInterface::ISO8601),', now = ',$now->format(DateTimeInterface::ISO8601),"<br>\n";
  
  //$diff = $first->diff($now); print_r($diff); echo "<br>\n";
  /*if ($diff->invert == 1) {
    echo "diff.invert==1 <=> now < first<br>\n";
  }
  else {
    echo "diff.invert<>=1 <=> first < now<br>\n";
  }*/
  if ($first->diff($now)->d == 0) // Il y a moins d'un jour d'écart
    return false;
  if ($first->diff($now)->days >= 7) // Il y a plus de 7 jours d'écart
    return true;
  
  $W = $now->format('W'); // le no de la semaine de $now
  //echo "Le no de la semaine de now est $W<br>\n";
  $o = $now->format('o'); // l'année ISO semaine de $last
  //echo "L'année (ISO semaine) d'aujourd'hui est $o<br>\n";
  // j'appelle $thursday le jour qui doit être le $dayOfWeek
  $thursday = $now->setISODate(intval($o), intval($W), $dayOfWeek)->setTime($h, $mn);
  //echo "Le jeudi 20h UTC de la semaine d'aujourd'hui est ",$thursday->format(DateTimeInterface::ISO8601),"<br>\n";
  
  $diff = $thursday->diff($now); // intervalle de $thursday à $now
  //print_r($diff); echo "<br>\n";
  if ($diff->invert == 1) { // c'est le jeudi d'après now => prendre le jeudi d'avant
    //echo $thursday->format(DateTimeInterface::ISO8601)," est après maintenant<br>\n";
    $oneWeek = new \DateInterval("P7D"); // 7 jours
    $thursday = $thursday->sub($oneWeek);
    //echo $thursday->format(DateTimeInterface::ISO8601)," est le jeudi de la semaine précédente<br>\n";
  }
  else {
    //echo $thursday->format(DateTimeInterface::ISO8601)," est avant maintenant, c'est donc le jeudi précédent<br>\n";
  }
  $thursday_minus_first = $first->diff($thursday);
  //print_r($thursday_minus_first);
  if ($thursday_minus_first->invert == 1) {
    //echo "thursday_minus_first->invert == 1 <=> thursday < first<br>\n";
    return false;
  }
  else {
    //echo "thursday_minus_first->invert <> 1 <=> first < thursday <br>\n";
    return true;
  }
}

/** Test de dateBetween() */
function testDateBetween(): void {
  $first = \DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-15');
  $now = new \DateTimeImmutable;
  echo 'first = ',$first->format(\DateTimeInterface::ISO8601),', now = ',$now->format(\DateTimeInterface::ISO8601),"<br>\n";
  $db = dateBetween($first, $now, 4, 20, 00);
  echo "dateBetween=",$db ? 'true' : 'false',"<br>\n";
  
  $first = \DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-11'); // Ve
  $now = \DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-15'); // Ma
  echo 'first = ',$first->format(\DateTimeInterface::ISO8601),', now = ',$now->format(\DateTimeInterface::ISO8601),"<br>\n";
  $db = dateBetween($first, $now, 4, 20, 00);
  echo "dateBetween=",$db ? 'true' : 'false',"<br>\n";
}
//testDateBetween();


/** description d'un cartouche dans la synthèse d'une carte */
class GanInSet {
  protected string $title;
  protected ?string $scale=null; // échelle
  /** @var array<string, string> $spatial */
  protected array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]
  
  function __construct(?string $html) {
    if (!$html) return;
    //echo "html=$html\n";
    if (!preg_match('!^\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new \Exception("Erreur de construction de GanInSet sur '$html'");
    $this->title = trim($matches[1]);
    $this->spatial = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }
  
  function scale(): ?string { return $this->scale; }
  function setScale(string $scale): void { $this->scale = $scale; }
  /** @return array<string, string> */
  function spatial(): array { return $this->spatial; }
  
  /** Exporte un cartouche comme un array.
   * @return array<string, mixed> */
  function asArray(): array {
    return [
      'title'=> $this->title,
      'scale'=> $this->scale,
      'spatial'=> $this->spatial,
    ];
  }
  
  /** construit un cartouche à partir d'un array structuré par asArray().
   @param array<string,mixed> $array */
  static function buildFromArray(array $array): self {
    $inset = new self(null);
    $inset->title = $array['title'];
    $inset->scale = $array['scale'];
    $inset->spatial = $array['spatial'];
    return $inset;
  }
};

/** synthèse du GAN par carte à la date de moisson des GAN / catalogue ou indication d'erreur d'interrogation des GAN.
 *
 * Une fois moissonné le GAN est stocké dans Gan::$all avec un objet Gan par carte référencé par le numéro de la carte.
 * L'ensemble des objets Gan ainsi que la variable statique $hvalid qui définit l'intervalle des dates de validation
 * sont enregistrés dans un fichier pser et dans un fichier Yaml.
 * L'utilisation du fichier pser présente l'avantage de la rapidité de lecture et l'inconvénient de dépendre de la structure Php,
 * De son côté, le fichier Yaml est indépendant de la structure Php et est facile à lire par un humain mais sa lecture
 * est moins rapide.
 */
class Gan {
  /** chemin des fichiers stockant la synthèse en pser ou en yaml, sans l'extension */
  const PATH = __DIR__.'/gans.';
  /** chemin du fichier stockant le catalogue en pser */
  const PATH_PSER = self::PATH.'pser';
  /** chemin du fichier stockant le catalogue en  Yaml */
  const PATH_YAML = self::PATH.'yaml';
  
  /** Corrections du champ édition du GAN pour certaines cartes afin de ne pas perturber le TdB.
   * Le GAN contient quelques erreurs sur l'année d'édition des cartes.
   * La référence est pour ShomGT la date indiquée sur la acrte.
   * Cette constante est un tableau avec pour entrée le numéro de carte
   * et pour valeur un array [{edACorriger}=> {edCorrigée}]
   * Liste d'écarts transmise le 15/6/2022 au Shom
   * Les écarts ci-dessous sont ceux restants après corrections du Shom
   * Revisite le 18/8/2023, vérification par rapport à la carte elle même
   */
  const CORRECTIONS = [
    '6942'=> ["Edition n°3 - 2015"=> "Edition n°3 - 2016"], // correction vérifiée le 18/8/2023
    //'7143'=> ["Edition n°2 - 2002"=> "Edition n°2 - 2003"], Suppression le 18/8/2023
    //'7268'=> ["Publication 1992"=> "Publication 1993"],  Suppression le 18/8/2023
    //'7411'=> ["Edition n°2 - 2002"=> "Edition n°2 - 2003"],  Suppression le 18/8/2023
    '7414'=> ["Edition n°3 - 2013"=> "Edition n°3 - 2014"], // correction vérifiée le 18/8/2023
    //'7507'=> ["Publication 1995"=> "Publication 1996"], Suppression le 18/8/2023
    //'7593'=> ["Publication 2002"=> "Publication 2003"],  Suppression le 18/8/2023
    '7755'=> ["Publication 2015"=> "Publication 2016"], // correction vérifiée le 18/8/2023
  ];

  /** interval des dates de la moisson des GAN */
  static string $hvalid='';
  /** dictionnaire [$mapnum => Gan]
   * @var array<string, Gan> $all */
  static array $all=[];
  
  /** numéro de la carte */
  readonly public string $mapnum;
  /** sur-titre optionnel identifiant un ensemble de cartes */
  protected ?string $groupTitle=null;
  /** titre de la carte */
  protected string $title='';
  /** échelle de la carte */
  protected ?string $scale=null;
  /** édition de la carte */
  readonly public ?string $edition;
  /** liste des corrections
   * @var array<int, array<string, string>> $corrections */
  readonly public array $corrections;
  /** extension spatiale sour la forme ['SW'=> sw, 'NE'=> ne]
   * @var array<string, string> $spatial */
  protected array $spatial=[];
  /** liste des cartouches
   * @var array<int, GanInSet> $inSets */
  protected array $inSets=[];
  /** erreurs éventuelles d'analyse du résultat du moissonnage
   * @var array<int, string> $analyzeErrors */
  readonly public array $analyzeErrors;
  /** date de moissonnage du GAN en format ISO */
  readonly public string $valid;
  /** erreur éventuelle du moissonnage */
  readonly public string $harvestError;

  /** indique si la dernière moisson du GAN est ancienne et le GAN doit donc être remoissonné
   * Si remoisson alors retourne l'age de la moisson en jours
   * Si la moisson n'existe pas alors retourne -1
   * Si la moisson n'a pas à être moissonnée retourne 0 */
  static function age(): int {
    //return -1;
    if (!self::$hvalid) { // la classe n'a pas déjà été chargée
      if (!self::loadFromPser()) // aucune moisson disponible
        return -1;
    }
    $now = new \DateTimeImmutable;
    $valid = explode('/', self::$hvalid);
    //echo "valid="; print_r($valid); echo "<br>\n";
    $valid = \DateTimeImmutable::createFromFormat('Y-m-d', $valid[1]);
    if (dateBetween($valid, $now, 4, 20, 00)) {
      //print_r($valid->diff($now));
      return $valid->diff($now)->days;
    }
    else
      return 0;
  }
  
  /** Construit le fichier pser à partir du fichie Yaml */
  static function buildPserFromYaml(): void {
    if (!is_file(self::PATH_YAML))
      throw new \Exception("Erreur, fichier gans.yaml absent");
    $array = Yaml::parseFile(Gan::PATH_YAML);
    self::buildFromArrayOfAll($array);
    //echo Yaml::dump(Gan::allAsArray(), 4, 2);
    self::storeAsPser();
  }
  
  /** enregistre le catalogue comme pser */
  static function storeAsPser(): void {
    file_put_contents(self::PATH_PSER, serialize([
      'valid'=> Gan::$hvalid,
      'gans'=> Gan::$all,
    ]));
  }
  
  /** initialise les données de la classe depuis le fichier pser, ou s'il n'existe pas du fichier Yaml */
  static function loadFromPser(): bool {
    if (!is_file(self::PATH_PSER)) {
      if (!is_file(self::PATH_YAML))
        return false;
      self::buildPserFromYaml();
    }
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    Gan::$hvalid = $contents['valid'];
    Gan::$all = $contents['gans'];
    return true;
  }
  
  static function item(string $mapnum): ?Gan { return Gan::$all[$mapnum] ?? null; }
  
  /** @param array<string, mixed> $record; résultat de l'analyse du fichier Html*/
  function __construct(string $mapnum, array $record, ?int $mtime) {
    //echo "mapnum=$mapnum\n";
    //echo '$record='; print_r($record);
    //echo '$mapa='; print_r($mapa);
    $this->mapnum = $mapnum;
    // cas où soit le GAN ne renvoie aucune info signifiant qu'il n'y a pas de corrections, soit il renvoie une erreur
    if (!$record || isset($record['harvestError'])) {
      $this->edition = null;
    }
    else { // cas où il existe des corrections
      $this->postProcessTitle($record['title']);
      $this->edition = $record['edition'];
    }
    
    // transfert des infos sur l'échelle des différents espaces
    if (isset($record['scale'][0])) {
      $this->scale = $record['scale'][0];
      array_shift($record['scale']);
    }
    foreach ($this->inSets ?? [] as $i => $inSet) {
      $inSet->setScale($record['scale'][$i]);
    }
    
    $this->corrections = $record['corrections'] ?? [];
    $this->analyzeErrors = $record['analyzeErrors'] ?? [];
    $this->harvestError = $record['harvestError'] ?? '';
    $this->valid = $mtime ? date('Y-m-d', $mtime) : '';
  }
  
  /** @param array<string, mixed> $record */
  private function postProcessTitle(array $record): void { // complète __construct()
    if (!$record) return;
    $title = array_shift($record);
    foreach ($record as $inSet)
      $this->inSets[] = new GanInSet($inSet);
    if (!preg_match('!^([^{]*){div}([^{]*){/div}\s*({div}([^{]*){/div}\s*)?({div}([^{]*){/div})?\s*$!', $title, $matches))
      throw new \Exception("Erreur d'analyse du titre '$title'");
    //echo '$matches='; print_r($matches);
    switch (count($matches)) {
      case 3: { // sur-titre + titre sans bbox
        $this->groupTitle = trim($matches[1]);
        $this->title = trim($matches[2]);
        break;
      }
      case 7: { // sur-titre + titre + bbox
        $this->groupTitle = trim($matches[1]);
        $this->title = trim($matches[2]);
        $this->spatial = ['SW'=> trim($matches[4]), 'NE'=> trim($matches[6])];
        break;
      }
      default: throw new \Exception("Erreur d'analyse du titre '$title', count=".count($matches));
    }
    //echo '$this='; print_r($this);
  }
  
  function scale(): ?string { return $this->scale; }
  /** @return array<string, string> */
  function spatial(): array { return $this->spatial; }
  
  /** @return array<int, array<string, string>> $corrections */
  function corrections(): array { return $this->corrections; }
  
  /** retourne l'ensemble des objets de la classe comme array
   * @return array<string, mixed> */
  static function allAsArray(): array {
    if (!self::$all)
      Gan::loadFromPser();
    return [
      'title'=> "Synthèse du résultat du moissonnage des GAN des cartes du catalogue",
      'description'=> "Seules sont présentes les cartes non obsolètes présentes sur sgserver",
      '$id'=> 'https://geoapi.fr/shomgt4/cat2/gans',
      '$schema'=> __DIR__.'/gans',
      'valid'=> self::$hvalid,
      'gans'=> array_map(function(Gan $gan) { return $gan->asArray(); }, self::$all),
      'eof'=> null,
    ];
  }
  
  /** Reconstruit la classe Gan à partir de l'array produit par allAsArray()
   * @param array<string, mixed> $all */
  static function buildFromArrayOfAll(array $all): void {
    self::$hvalid = $all['valid'];
    foreach ($all['gans'] as $mapnum => $ganAsArray) {
      self::$all[$mapnum] = self::buildFromArray($mapnum, $ganAsArray);
    }
  }
  
  /** retourne un objet comme array 
   * @return array<string, mixed> */
  function asArray(): array {
    return
      ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
    + ($this->title ? ['title'=> $this->title] : [])
    + ($this->scale ? ['scale'=> $this->scale] : [])
    + ($this->spatial ? ['spatial'=> $this->spatial] : [])
    + ($this->inSets ?
        ['inSets'=> array_map(function (GanInSet $inset): array { return $inset->asArray(); }, $this->inSets)] : [])
    + ($this->edition ? ['edition'=> $this->edition] : [])
    + ($this->corrections ? ['corrections'=> $this->corrections] : [])
    + ($this->analyzeErrors ? ['analyzeErrors'=> $this->analyzeErrors] : [])
    + ($this->valid ? ['valid'=> $this->valid] : [])
    + ($this->harvestError ? ['harvestError'=> $this->harvestError] : [])
    ;
  }
  
  /** reconstruit un objet Gan à partir de l'arry produit par asArray()
   * @param array<string, mixed> $array */
  static function buildFromArray(string $mapnum, array $array): self {
    $gan = new self($mapnum, [], null);
    //print_r($array);
    $gan->groupTitle = $array['groupTitle'] ?? null;
    $gan->title = $array['title'] ?? '';
    $gan->scale = $array['scale'] ?? null;
    $gan->spatial = $array['spatial'] ?? [];
    foreach ($array['inSets'] ?? [] as $inSet)
      $gan->inSets[] = GanInSet::buildFromArray($inSet);
    $gan->edition = $array['edition'] ?? null;
    $gan->corrections = $array['corrections'] ?? [];
    $gan->analyzeErrors = $array['analyzeErrors'] ?? [];
    $gan->valid = $array['valid'] ?? null;
    $gan->harvestError = $array['harvestError'] ?? '';
    return $gan;
  } 

  /** calcule la version sous la forme {anneeEdition}c{noCorrection} */
  function version(): string {
    // COORECTIONS DU GAN
    if (isset(self::CORRECTIONS[$this->mapnum][$this->edition]))
      $this->edition = self::CORRECTIONS[$this->mapnum][$this->edition];

    if (!$this->edition && !$this->corrections)
      return 'undefined';
    if (preg_match('!^Edition n°\d+ - (\d+)$!', $this->edition, $matches)) {
      $anneeEdition = $matches[1];
      // "anneeEdition=$anneeEdition<br>\n";
    }
    elseif (preg_match('!^Publication (\d+)$!', $this->edition, $matches)) {
      $anneeEdition = $matches[1];
      //echo "anneeEdition=$anneeEdition<br>\n";
    }
    else {
      throw new \Exception("No match pour version edition='$this->edition'");
    }
    if (!$this->corrections) {
      return $anneeEdition.'c0';
    }
    else {
      $lastCorrection = $this->corrections[count($this->corrections)-1];
      $num = $lastCorrection['num'];
      return $anneeEdition.'c'.$num;
    }
    /*echo "mapnum=$this->mapnum, edition=$this->edition<br>\n";
    echo "<pre>corrections = "; print_r($this->corrections); echo "</pre>";*/
  }

  /** retourne le cartouche correspondant au qgbox */
  function inSet(\gegeom\GBox $qgbox): GanInSet {
    //echo "<pre>"; print_r($this);
    $dmin = INF;
    $pmin = -1;
    foreach ($this->inSets as $pnum => $part) {
      //try {
        $pgbox = new \gegeom\GBox([
          'SW'=> str_replace('—','-', $part->spatial()['SW']),
          'NE'=> str_replace('—','-', $part->spatial()['NE']),
        ]);
      /*}
      catch (SExcept $e) {
        echo "<pre>SExcept::message: ",$e->getMessage(),"\n";
        //print_r($this);
        return null;
      }*/
      $d = $qgbox->distance($pgbox);
      //echo "pgbox=$pgbox, dist=$d\n";
      if ($d < $dmin) {
        $dmin = $d;
        $pmin = $pnum;
      }
    }
    if ($pmin == -1)
      throw new \SExcept("Aucun Part");
    return $this->inSets[$pmin];
  }
};
