<?php
/** Ajout du GAN moissonné aux corrections */
namespace gan;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gan.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Corrections par carte */
class GanHisto {
  const FILE_PATH = __DIR__.'/ganhisto.yaml';
  
  protected ?Gan $gan=null;
  /** corr par édition [{édition} => [c{num} => ['semaineAvis' => {SemaineAvis}, 'valid'=> {valid}]]]
   * @var array<string,array<string,array{semaineAvis: string, valid: string}>> $corrs */
  protected array $corrs=[];
  
  static string $start=''; // plus ancienne date de moissonnage
  static string $end=''; // plus récente date de moissonnage
  /** dictionnaire [{numDeCarte} => MapCorr]
   * @var array<string,GanHisto> $all */
  static array $all=[];
  
  function setGan(Gan $gan): void { $this->gan = $gan; }
  
  /** Ajoute une correction à une édition */
  function addHisto(string $edition, string $num, string $semaineAvis, string $valid): void {
    $this->corrs[$edition][$num] = ['semaineAvis'=> $semaineAvis, 'valid'=> $valid];
    //echo "strcmp(",self::$start,",$valid)=",strcmp(self::$start, $valid),"\n";
    if (!self::$start || (strcmp(self::$start, $valid)==1))
      self::$start = $valid;
    if (!self::$end || (strcmp(self::$end, $valid)==-1))
      self::$end = $valid;
  }
  
  /** Chargement d'un fichier de corrections existant */
  static function load(): void {
    if (!is_file(self::FILE_PATH))
      return;
    $fileContents = Yaml::parseFile(self::FILE_PATH);
    self::$start = $fileContents['valid']['start'] ?? '';
    //echo "start=",self::$start,"\n";
    self::$end = $fileContents['valid']['end'] ?? '';
    foreach ($fileContents['maps'] ?? [] as $mapNum => $map) {
      self::$all[$mapNum] = new self;
      foreach ($map['corrections'] ?? [] as $edition => $corrections) {
        foreach ($corrections as $num => $c)
          self::$all[$mapNum]->addHisto($edition, $num, $c['semaineAvis'], $c['valid']);
      }
    }
    $backupName = __DIR__.'/ganhisto'.self::$end.'.yaml';
    rename(self::FILE_PATH, $backupName);
  }
  
  /** @return array<mixed> */
  function asArray(): array {
    $gan = $this->gan->asArray();
    $gan['corrections'] = $this->corrs;
    return $gan;
  }
  
  /** @return array<mixed> */
  static function allAsArray(): array {
    return [
      'title'=> "Synthèse du résultat du moissonnage des GAN des cartes du catalogue avec historique des corrections",
      '$schema'=> 'ganhisto',
      'valid'=> ['start'=> self::$start, 'end'=> self::$end],
      'maps'=> array_map(function(self $ganHisto): array { return $ganHisto->asArray(); }, self::$all),
    ];
  }
  
  /** Ecriture d'une nouvelle version du fichier */
  static function dump(): void {
    //echo Yaml::dump(self::allAsArray(), 5, 2); die();
    file_put_contents(self::FILE_PATH, Yaml::dump(self::allAsArray(), 5, 2));
  }
  
  /** Ajout des corrections d'un GAN stocké dans la classe Gan */
  static function addGan(): void {
    foreach (Gan::$all as $mapNum => $gan) {
      if ($gan->edition) {
        //echo Yaml::dump([$mapNum => $gan->asArray()]);
        if (!isset(self::$all[$mapNum]))
          self::$all[$mapNum] = new self();
        self::$all[$mapNum]->setGan($gan);
        self::$all[$mapNum]->corrs[$gan->edition] = [];
        foreach ($gan->corrections ?? [] as $c) {
          self::$all[$mapNum]->addHisto($gan->edition, "c$c[num]", $c['semaineAvis'], $gan->valid);
        }
        //print_r(self::$all[$mapNum]);
      }
    }
  }
};

if ($argv[0] <> basename(__FILE__)) return; // cas où le fichier est inclus dans un autre fichier

Gan::loadFromPser();
GanHisto::load();
GanHisto::addGan();
GanHisto::dump();
echo "Fichier ",GanHisto::FILE_PATH," créé\n";
