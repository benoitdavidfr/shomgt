<?php
/**
 * construit le graphe des inclusions entre fichiers Php pour afficher différentes informations
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/token.inc.php';
require_once __DIR__.'/defining.inc.php';
require_once __DIR__.'/using.inc.php';

use Symfony\Component\Yaml\Yaml;

//ini_set('memory_limit', '1024M');

/** Fichier Php analysé avec son chemin relatif et organisation des fichiers en un arbre */
class PhpFile {
  /** liste des sous-répertoires exclus du parcours lors de la construction de l'arbre */
  const EXCLUDED = ['.','..','.git','.phpdoc','vendor','shomgeotiff','gan','data','temp'];
  /** @var string $rpath chemin relatf par rapport à $root */
  readonly public string $rpath;
  readonly public string $namespace;
  readonly public string $title;
  /** @var list<string> $includes; liste des fichiers inclus */ 
  readonly public array $includes;
  
  /** @var string $root; chemin de la racine de l'arbre */
  static string $root;

  /** Construit et retourne l'arbre des répertoires et fichiers
   *
   * Structure récursive de l'arbre:
   * TREE ::= PhpFile # pour un fichier
   *        | {{rpath} => TREE} # pour un répertoire
   *
   * Peut être appelé avec un nom de sous-classe de PhpFile pour construire un arbre d'objet de cette sous-classe.
   * @return array<string, mixed>|PhpFile */
  static function buildTree(string $class, string $rpath='', bool $verbose=false): array|PhpFile {
    if ($verbose)
      echo "buildTree(class=$class, rpath=$rpath)<br>\n";
    if (is_file(self::$root.$rpath) && (substr($rpath, -4)=='.php')) { // Fichier Php
      //echo "new $class(rpath=$rpath)<br>\n";
      return new $class($rpath, new TokenArray(self::$root.$rpath));
    }
    elseif (is_dir(self::$root.$rpath)) { // Répertoire
      $result = [];
      foreach (new DirectoryIterator(self::$root.$rpath) as $entry) {
        if (in_array($entry, self::EXCLUDED)) continue;
        if ($tree = self::buildTree($class, "$rpath/$entry", $verbose))
          $result["$entry"] = $tree;
      }
      return $result;
    }
    else {
      //echo "$rpath ni phpFile ni dir\n";
      return [];
    }
  }
  
  /** transforme l'arbre des fichiers en un arbre composé d'Array.
   * @param array<mixed>|PhpFile $tree
   * @return array<mixed>
   */
  static function treeAsArray(array|PhpFile $tree): array {
    if (is_object($tree))
      return $tree->asArray();
    else {
      $result = [];
      foreach ($tree as $rpath => $subTree) {
        $result[$rpath] = self::treeAsArray($subTree);
      }
      return $result;
    }
  }
  
  /** affiche tous les noms de fichiers avec un lien vers chacun */
  static function chooseFile(string $action, string $rpath=''): void {
    foreach (new DirectoryIterator(PhpFile::$root.$rpath) as $entry) {
      if (in_array($entry, self::EXCLUDED)) continue;
      if (is_file(PhpFile::$root.$rpath."/$entry") && (substr($entry, -4)=='.php')) {
        echo "<a href='?action=$action&rpath=$rpath/$entry'>$rpath/$entry</a><br>\n";
      }
      elseif (is_dir(PhpFile::$root.$rpath."/$entry")) {
        self::chooseFile($action, "$rpath/$entry");
      }
    }
  }
  
  function __construct(string $rpath, TokenArray $tokens) {
    $verbose = $_GET['verbose'] ?? null;
    if ($verbose)
      echo "<b>PhpFile::__construct(rpath=$rpath)</b><br>\n";
    $this->rpath = $rpath;
    $this->namespace = $this->namespace($tokens);
    $this->title = $this->title($tokens);
    $this->includes = $this->includes($tokens);
  }
  
  /** Retourne l'objet comme array.
   * @return array<mixed> */
  function asArray(): array {
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title."</a>",
      'includes'=> $this->includes,
    ];
  }
  
  /** Retourne le titre du fichier extrait du commentaire PhpDoc */
  function title(TokenArray $tokens): string {
    if (isset($tokens[1]) && ($tokens[1]->id == T_DOC_COMMENT)) {
      $comment = $tokens[1]->src;
      //echo "comment=",htmlentities($comment),"\n";
      if (preg_match('!^/\*\*\n \* ([^\n]*)!', $comment, $matches)) {
        return $matches[1];
      }
      if (preg_match('!^/\*\* ([^\n]*)!', $comment, $matches)) {
        return $matches[1];
      }
    }
    return "{!phpDoc}";
  }
  
  /** Retourne la liste des fichiers inclus dans le fichier Php.
   * 
   * Si le fichier inclus est dans root alors fournit un path relatif par rapport à root avec {root} au début du chemin.
   * Si le fichier inclus n'existe pas alors:
   *  - signale une erreur
   *  - retourne le chemin absolu suivi de " (BAD)"
   *
   * @return list<string>
   */
  function includes(TokenArray $tokens): array {
    $includes = [];
    foreach ($tokens as $i => $token) {
      if ($token->id == T_REQUIRE_ONCE) {
        //echo $tokens->symbStr($i, 5),"<br>\n";
        if ($tokens->symbStr($i, 5) == 'T_REQUIRE_ONCE,T_WHITESPACE,T_DIR,.,T_CONSTANT_ENCAPSED_STRING') {
          $inc = dirname(self::$root.$this->rpath).substr($tokens[$i+4]->src, 1, -1);
          if (($rp = realpath($inc)) === false) {
            echo "<b>Erreur d'inclusion de $inc dans $this->rpath</b><br>\n";
            $includes[] = "$inc (BAD)";
          }
          elseif (substr($rp, 0, strlen(self::$root)) == self::$root)
            $includes[] = '{root}'.substr($rp, strlen(self::$root));
          else
            $includes[] = $rp;
        }
        else {
          echo "<b>Erreur, T_REQUIRE_ONCE détecté mais non interprété</b><br>\n";
        }
      }
    }
    return $includes;
  }
  
  /** retourne le namespace du fichier ou '' si aucun n'est défini */
  function namespace(TokenArray $tokens): string {
    // Cas où le namspace est la première instruction après T_OPEN_TAG
    if ($tokens->symbStr(1, 3) == 'T_NAMESPACE,T_WHITESPACE,T_STRING') {
      $namespace = $tokens[3]->src;
      //echo "namespace=$namespace<br>\n";
      return "$namespace\\";
    }
    // Cas où le namespace est après T_OPEN_TAG et T_DOC_COMMENT et T_WHITESPACE
    if ($tokens->symbStr(1, 5) == 'T_DOC_COMMENT,T_WHITESPACE,T_NAMESPACE,T_WHITESPACE,T_STRING') {
      $namespace = $tokens[5]->src;
      //echo "namespace=$namespace<br>\n";
      return "$namespace\\";
    }
    return '';
  }
};

/** graphe d'inclusions entre fichiers et de fichier dans lequel une classe est définie */
class FileIncGraph {
  /** @var array<string, string> dictionnaire des titres des fichiers [{rpath => {title}] */
  static array $titles=[];
  /** @var array<string, array<string, 1>> $incIn matrice [{rpathInclus} => [{rpath_incluants} => 1]] */
  static array $incIn=[];
  /** @var array<string, array<string, PhpClass>> $classesInFile matrice [{className} => [{rpath_incluants} => PhpClass]] */
  static array $classesInFile=[];
  
  /** parcours l'arbre d'inclusion et construit les propriétés $titles et $incIn.
   * @param array<mixed> $tree l'arbre d'inclusion
   */
  static function build(array|DefiningFile $tree, string $rpath=''): void {
    //echo "build(rpath=$rpath)<br>\n";
    if (is_object($tree)) { // c'est un fichier Php
      self::$titles[$rpath] = $tree->title;
      foreach ($tree->includes as $inc) {
        if (substr($inc, 0, 6)=='{root}') {
          $inc = substr($inc, 6);
          //echo "inc=$inc<br>\n";
          //echo "addFileIncGraph($rpath, $inc)<br>\n";
          self::$incIn[$inc][$rpath] = 1;
        }
      }
      foreach ($tree->classes() as $className => $class) {
        self::$classesInFile[$className][$rpath] = $class;
      }
    }
    else { // c'est un répertoire
      foreach ($tree as $name => $sinc) {
        self::build($sinc, "$rpath/$name");
      }
    }
  }
  
  /** export du graphe sous la forme d'un arbre d'inclusion inversé
   * @return array<mixed>
   */
  static function exportInvIncludes(): array {
    $export = [];
    ksort(self::$incIn);
    foreach (self::$incIn as $included => $includedIns) {
      ksort($includedIns);
      $export[$included] = [
        // le titre n'est pas défini si le fichier n'était pas un fichier inclus
        'title'=> self::$titles[$included] ?? 'NO TITLE',
        'includedIn' => array_keys($includedIns),
      ];
    }
    return $export;
  }

  /** export du graphe sous la forme d'un arbre [{classe} -> {chemin du fichier}]
   * @return array<mixed>
   */
  static function exportClasses(): array {
    $export = [];
    ksort(self::$classesInFile);
    foreach (self::$classesInFile as $className => $files) { // $files ::= [{rpath_incluants} => PhpClass]
      foreach ($files as $rpath => $class) {
        $lineNr = $class->lineNr;
        // Url vers le fichier à la bonne ligne
        $url = "<a href='viewtoken.php?rpath=$rpath&lineNr=$lineNr#line$lineNr'>$rpath</a>";
        $export[$className]['files'][$url] = $lineNr;
      }
    }
    return $export;
  }
};

/** Graphe d'utilisation des classes et fonctions
 *
 * Un objet correspond à la définition d'une classe ou d'une fonction et à ses utilisations
 */
class UseGraph {
  const SAME_DIR = false; // si vrai alors affiche les utilisations dans le même répertoire que les définitions
  readonly public PhpBlock $def; // l'objet de définition
  /** @var array<string,list<PhpUse>> $uses liste des objets d'utilisation par fichier */
  protected array $uses=[];
  
  /** @var array<string,array<string,UseGraph>> $functions; les fonctions idexées sur leur nom et le chemin du fichier de déf. */
  static array $functions=[];
  /** @var array<string,array<string,UseGraph>> $classes; les classes idexées sur leur nom et le chemin du fichier de déf. */
  static array $classes=[];
  
  /** construction récursive des définitions d'un répertoire */
  static function buildDefs(string $rpath=''): void {
    //echo "UseGraph::buildDefs(rpath=$rpath)<br>\n";
    foreach (new DirectoryIterator(PhpFile::$root.$rpath) as $entry) {
      if (in_array($entry, PhpFile::EXCLUDED)) continue;
      if (is_dir(PhpFile::$root."$rpath/$entry"))
        self::buildDefs("$rpath/$entry");
      elseif (substr($entry, -4) == '.php') {
        $defrpath = "$rpath/$entry";
        $defFile = new DefiningFile($defrpath);
        foreach ($defFile->functions() as $name => $def) {
          self::$functions[$name][$defrpath] = new self($def);
        }
        foreach ($defFile->classes() as $name => $def) {
          self::$classes[$name][$defrpath] = new self($def);
        }
      }
    }
  }

  // construction des utilisations
  static function buildUses(string $rpath=''): void {
    $sameDir = $_GET['sameDir'] ?? null; // si vrai alors affichage des utilisations et définitions dans le même module
    foreach (new DirectoryIterator(PhpFile::$root.$rpath) as $entry) {
      if (in_array($entry, PhpFile::EXCLUDED)) continue;
      if (is_dir(PhpFile::$root."$rpath/$entry"))
        self::buildUses("$rpath/$entry");
      elseif (substr($entry, -4) == '.php') {
        $userpath = "$rpath/$entry";
        $useFile = new UsingFile($userpath);
        foreach ($useFile->uses as $use) {
          //echo "$use<br>\n";
          if ($uFunName = $use->usedFunctionName($useFile->namespace)) {
            if (substr($uFunName, 0, 1) == '\\')
              $uFunName = substr($uFunName, 1);
            // Je n'enregistre que les utilisations effectuées dans un fichier différent de la définition
            if (isset(self::$functions[$uFunName]) && !isset(self::$functions[$uFunName][$userpath])) {
              if (count(array_keys(self::$functions[$uFunName])) > 1) { // fun définie dans plusieurs fichiers
                echo "Dans $userpath utilisation de la fonction $uFunName définie dans \n";
                echo "<ul><li>",implode("</li>\n<li>", array_keys(self::$functions[$uFunName])),"</li></ul>\n";
              }
              else {
                $defrpath = array_keys(self::$functions[$uFunName])[0];
                if ($sameDir || (dirname($defrpath) <> dirname($userpath))) {
                  //echo "Fonction $uFunName utilisée dans $userpath définie dans $defrpath<br>\n";
                  self::$functions[$uFunName][$defrpath]->addUse($userpath, $use);
                }
              }
            }
          }
          if ($uClassName = $use->usedClassName($useFile->namespace)) {
            if (substr($uClassName, 0, 1) == '\\')
              $uClassName = substr($uClassName, 1);
            //echo "uClassName=$uClassName<br>\n";
            // Je n'enregistre que les utilisations effectuées dans un fichier différent de la définition
            if (isset(self::$classes[$uClassName]) && !isset(self::$classes[$uClassName][$userpath])) {
              if (count(array_keys(self::$classes[$uClassName])) > 1) { // classe définie dans plusieurs fichiers
                echo "Dans $userpath utilisation de la classe $uClassName définie dans \n";
                echo "<ul><li>",implode("</li>\n<li>", array_keys(self::$classes[$uClassName])),"</li></ul>\n";
              }
              else {
                $defrpath = array_keys(self::$classes[$uClassName])[0];
                if ($sameDir || (dirname($defrpath) <> dirname($userpath))) {
                  //echo "Classe $uClassName utilisée dans $userpath définie dans $defrpath<br>\n";
                  self::$classes[$uClassName][$defrpath]->addUse($userpath, $use);
                }
              }
            }
          }
        }
      }
    }
  }
  
  function __construct(PhpBlock $def) { $this->def = $def; }
  
  function addUse(string $userpath, PhpUse $use): void { $this->uses[$userpath][] = $use; }
  
  static function show(): void {
    ksort(self::$functions);
    ksort(self::$classes);
    echo "<pre>";
    foreach (self::$functions as $name => $pathUses) {
      foreach ($pathUses as $defrpath => $graphElt) {
        $key = "Fun $name@$defrpath#".$graphElt->def->lineNr;
        if ($graphElt->uses)
          echo Yaml::dump([$key => $graphElt->asArray()], 99);
      }
    }
    foreach (self::$classes as $name => $pathUses) {
      foreach ($pathUses as $defrpath => $graphElt) {
        $key = "Class $name@$defrpath#".$graphElt->def->lineNr;
        if ($graphElt->uses)
          echo Yaml::dump([$key => $graphElt->asArray()], 99);
      }
    }
  }
  
  /** Retourne un élément du graphe comme array pur
   * @return array<mixed> */
  function asArray(): array {
    $array = [];
    foreach ($this->uses as $userpath => $uses) {
      foreach ($uses as $use)
        $array[$userpath][] = $use->__toString();
    }
    return $array;
  }
};

//PhpFile::$root = __DIR__.'/testcode';
//PhpFile::$root = __DIR__;
PhpFile::$root = realpath(__DIR__.'/..');

echo "<!DOCTYPE html>\n<html><head><title>phpanalyzer</title></head><body>\n";
switch ($_GET['action'] ?? null) {
  case null: {
    echo "<a href='?action=fileIncludes'>Arbre des répertoires et fichiers avec inclusions de fichiers</a><br>\n";
    echo "<a href='?action=fileIncGraph'>Affichage du graphe d'inclusions entre fichiers</a><br>\n";
    echo "<a href='?action=fileIncludedIn'>Inclusions inversées entre fichiers</a><br>\n";
    echo "<a href='?action=classInFile'>Liste des classes et fichiers la définissant</a><br>\n";
    echo "<a href='?action=buildBlocks'>Test de construction des blocks</a><br>\n";
    echo "<a href='?action=usesInAPhpFile'>Liste des utilisations d'un fichier</a><br>\n";
    echo "<a href='?action=useClassOrFunction'>Affichage des utilisations d'une classe ou d'une fonction</a><br>\n";
    echo "<a href='?action=useGraph'>Affichage des utilisations des classes et fonctions entre modules</a><br>\n";
    echo "<a href='?action=useGraph&sameDir=true'>Affichage des utilisations des classes et fonctions y c. dans le même module</a><br>\n";
    break;
  }
  case 'fileIncludes': {
    echo "<h2>Arbre des répertoires et fichiers avec inclusions de fichiers</h2>\n";
    $tree = PhpFile::buildTree('PhpFile'); // construction de l'arbre
    echo '<pre>',str_replace("''", "'", Yaml::dump([PhpFile::$root => PhpFile::treeAsArray($tree)], 99, 2));
    break;
  }
  case 'fileIncGraph': {
    echo "<h2>Affichage du graphe d'inclusions entre fichiers</h2>\n";
    $tree = PhpFile::buildTree('DefiningFile'); // construction de l'arbre
    FileIncGraph::build($tree); // fabrication du graphe
    //echo '<pre>',str_replace("''","'",Yaml::dump(['$incIn'=> FileIncGraph::$incIn], 99, 2));
    //echo "<pre>classesInFile= "; print_r(FileIncGraph::$classesInFile);
    echo '<pre>',
          str_replace("''","'",
            Yaml::dump([
              '$classesInFile' => array_map(
                function(array $phpClasses) {
                  return array_map(function(PhpClass $class): int { return $class->lineNr; }, $phpClasses); }, 
                FileIncGraph::$classesInFile
              )],
              99, 2));
    break;
  }
  case 'fileIncludedIn': {
    echo "<h2>Inclusions inversées entre fichiers</h2>\n";
    $tree = PhpFile::buildTree('DefiningFile'); // construction de l'arbre
    FileIncGraph::build($tree); // fabrication du graphe
    echo '<pre>',Yaml::dump(FileIncGraph::exportInvIncludes(), 99, 2);
    break;
  }
  case 'classInFile': {
    echo "<h2>Liste des classes et pour chacune les fichiers dans lesquels elle est définie</h2>\n";
    $tree = PhpFile::buildTree('DefiningFile'); // construction de l'arbre
    FileIncGraph::build($tree); // fabrication du graphe
    echo '<pre>',str_replace("''", "'", Yaml::dump(FileIncGraph::exportClasses(), 99, 2));
    break;
  }
  case 'buildBlocks': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('buildBlocks');
    else {
      $file = new DefiningFile($_GET['rpath']);
      echo $file->blocksAsHtml();
    }
    break;
  }
  case 'usesInAPhpFile': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('usesInAPhpFile');
    else {
      $usingFile = new UsingFile($_GET['rpath']);
      //echo "<pre>calls="; print_r($calls);
      echo "<pre>",
           Yaml::dump([$_GET['rpath'].'calls'=> $usingFile->asArray()]),
           "</pre>\n";
    }
    break;
  }
  case 'useClassOrFunction': {
    if (isset($_GET['class']))
      UsingFile::usingClassOrFunction('class', $_GET['class']);
    elseif (isset($_GET['function']))
      UsingFile::usingClassOrFunction('function', $_GET['function']);
    else
      // choix d'une classe ou d'une fonction
      DefiningFile::chooseClassOrFunction($_GET['rpath'] ?? '');
    break;
  }
  case 'useGraph': {
    echo "<h2>Affichage des utilisations des classes et fonctions ",
          ($_GET['sameDir']??null) ? "y c. dans le même module" : "entre modules","</h2>\n";
    UseGraph::buildDefs();
    //echo "<pre>functions = "; print_r(UseGraph::$functions); echo "classes ="; print_r(UseGraph::$classes); echo "</pre>\n";
    UseGraph::buildUses();
    UseGraph::show();
    break;
  }
}
