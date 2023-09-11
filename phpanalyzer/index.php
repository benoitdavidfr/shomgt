<?php
/**
 * construit le graphe des inclusions entre fichiers Php pour afficher différentes informations
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/token.inc.php';
require_once __DIR__.'/phpblock.inc.php';

use Symfony\Component\Yaml\Yaml;

//ini_set('memory_limit', '1024M');


readonly abstract class Call {
  /** Numéro du token ( de l'appel */
  public int $tokenNr;
  /** Numéro de ligne de l'appel */
  public int $lineNr;

  /** Test de la détection des appels de fonctions et méthodes */
  static function detect(TokenArray $tokens): void {
    echo "<b>Call::detect()</b><br>\n";
    for ($nr=0; $nr < count($tokens); $nr++) {
      if ($tokens[$nr]->src == '(') {
        // $nr pointe sur une '('
        $nr2 = $nr;
        if ($tokens[$nr-1]->id == T_WHITESPACE) {
          $nr2--; // si la '(' est précédée d'un T_WHITESPACE alors $nr2 pointe sur ce T_WHITESPACE
          echo "T_WHITESPACE détecté avant '('<br>\n";
        }
        $symbstr = $tokens->symbstr($nr2-1, -3);
        // Détection de boucle foreach/for/while, switch, if, exit
        if (in_array($tokens[$nr2-1]->id, [T_FOREACH,T_FOR,T_WHILE,T_SWITCH,T_IF,T_EXIT])) {
          echo "Détection de boucle foreach/for/while, switch, if, exit<br>\n";
        }
        // Détection d'une ( d'expression
        elseif (in_array($tokens[$nr2-1]->id, [T_CONCAT_EQUAL,T_ELSEIF,T_BOOLEAN_OR,T_BOOLEAN_AND,T_ISSET])) {
         echo "Détection d'une ( d'expression<br>\n";
       }
       elseif (in_array($tokens[$nr2-1]->src, ['=','.','('])) {
         echo "Détection d'une ( d'expression hors token<br>\n";
       }
       // Détection d'un appel de méthode statique
        elseif (preg_match('!^T_STRING,T_DOUBLE_COLON,(T_STRING|T_NAME_FULLY_QUALIFIED)!', $symbstr)) {
          echo "Appel détecté méthode statique: ",$tokens->srcCode($nr-3, $nr+1),"<br>\n";
        }
        // Détection d'un appel de fonction
        elseif (preg_match(
            '!^(T_STRING|T_NAME_FULLY_QUALIFIED),(T_WHITESPACE,)?'
            .'(;|}|{|\(|=|T_IS_GREATER_OR_EQUAL|T_IS_EQUAL|>|<|T_RETURN|,|T_DOUBLE_ARROW|\.)!', $symbstr)) {
          $funName = $tokens[$nr2-1]->src;
          echo "Appel détecté $funName<br>\n";
        }
        // Appel de méthode non statique
        elseif (preg_match('!^T_STRING,T_OBJECT_OPERATOR!', $symbstr)) {
          echo "Appel de méthode non statique<br>\n";
        }
        // Détection d'une définition de fonction ou méthode
        elseif (preg_match('!^(T_STRING|T_NAMESPACE),T_WHITESPACE,T_FUNCTION!', $symbstr)) {
          $funName = $tokens[$nr2-1]->src;
          echo "Détection de définition de la fonction ou méthode '$funName'<br>\n";
        }
        // Détection d'un appel de new
        elseif (preg_match('!^(T_STRING|T_NAME_FULLY_QUALIFIED|T_VARIABLE),T_WHITESPACE,T_NEW!', $symbstr)) {
          $className = $tokens[$nr2-1]->src;
          echo "Détection appel de new $className<br>\n";
        }
        // Détection de définition de fonction anonyme
        elseif (preg_match('!^(T_FUNCTION|T_USE)!', $symbstr)) {
          echo "Détection de définition de fonction anonyme<br>\n";
        }
        // Cas non prévu
        else {
          echo "Dans PhpFile::calls() cas non traité de parenthèse ouvrante sur '$symbstr'<br>\n",
            "context: <table border=1><tr><td><pre>",
            htmlentities($tokens->srcCode($nr-10, $nr-1, '')),
            '<b><u>',htmlentities($tokens->srcCode($nr-1, $nr+2, '')),'</u></b>',
            htmlentities($tokens->srcCode($nr+2, $nr+12, '')),
            "</pre></td></tr><tr><td><pre>",
            $tokens->symbStr($nr-10, 20),
            "</pre></td></tr></table>\n";
        }
      }
    }
  }

  /** Création des appels de fonctions et méthodes
   * @return list<Call> */
  static function create(TokenArray $tokens): array {
    echo "<b>Call::create()</b><br>\n";
    $calls = [];
    for ($nr=0; $nr < count($tokens); $nr++) {
      if ($tokens[$nr]->src == '(') {
        // $nr pointe sur une '('
        $nr2 = $nr;
        if ($tokens[$nr-1]->id == T_WHITESPACE) {
          $nr2--; // si la '(' est précédée d'un T_WHITESPACE alors $nr2 pointe sur ce T_WHITESPACE
        }
        $symbstr = $tokens->symbstr($nr2-1, -3); // tokens avant la ( et l'éventuel blanc
       // Détection d'un appel de méthode statique
        if (preg_match('!^T_STRING,T_DOUBLE_COLON,(T_STRING|T_NAME_FULLY_QUALIFIED)!', $symbstr)) {
          $calls[] = new StaticMethodCall($tokens[$nr2-3]->src, $tokens[$nr2-1]->src);
        }
        // Détection d'un appel de fonction
        elseif (preg_match(
            '!^(T_STRING|T_NAME_FULLY_QUALIFIED),(T_WHITESPACE,)?'
            .'(;|}|{|\(|=|T_IS_GREATER_OR_EQUAL|T_IS_EQUAL|>|<|T_RETURN|,|T_DOUBLE_ARROW|\.)!', $symbstr)) {
          $funName = $tokens[$nr2-1]->src;
          $calls[] = new FunctionCall($tokens[$nr2-1]->src);
        }
        // Appel de méthode non statique
        elseif (preg_match('!^T_STRING,T_OBJECT_OPERATOR!', $symbstr)) {
          $calls[] = new NonStaticMethodCall($tokens[$nr2-1]->src);
        }
        // Détection d'un appel de new
        elseif (preg_match('!^(T_STRING|T_NAME_FULLY_QUALIFIED|T_VARIABLE),T_WHITESPACE,T_NEW!', $symbstr)) {
          $className = $tokens[$nr2-1]->src;
          $calls[] = new NewCall($tokens[$nr2-1]->src);
        }
      }
    }
    return $calls;
  }
};

readonly class FunctionCall extends Call {
  /** Nom de la fonction appelée */
  public string $name;
  
  function __construct(string $name) { $this->name = $name; }
  
  function asArray(): array {
    return ['type'=> 'FunctionCall', 'name'=> $this->name];
  }
};

readonly class NewCall extends Call {
  /** nom de la classe de la méthode appelée si elle est connue */
  public string $class;
  
  function __construct(string $class) { $this->class = $class; }
  
  function asArray(): array {
    return ['type'=> 'NewCall', 'class'=> $this->class];
  }
};

readonly class StaticMethodCall extends Call {
  /** nom de la classe de la méthode appelée (toujours connue) */
  public string $class;
  /** Nom de la méthode appelée. */
  public string $name;
  
  function __construct(string $class, string $name) { $this->class=$class; $this->name = $name; }
  
  function asArray(): array {
    return ['type'=> 'StaticMethodCall', 'class'=> $this->class, 'name'=> $this->name];
  }
};

readonly class NonStaticMethodCall extends StaticMethodCall {
  /** nom de la classe de la méthode appelée (toujours connue) */
  public string $class;
  /** Nom de la méthode appelée. */
  public string $name;

  function __construct(string $name, string $class='') { $this->name = $name; $this->class=$class; }
  
  function asArray(): array {
    return ['type'=> 'NonStaticMethodCall', 'class'=> $this->class, 'name'=> $this->name];
  }
};


/** Fichier Php analysé avec son chemin relatif et organisation des fichiers en un arbre */
class PhpFile {
  /** liste des sous-répertoires exclus du parcours lors de la construction de l'arbre */
  const EXCLUDED = ['.','..','.git','vendor','shomgeotiff','gan','data','temp'];
  /** @var string $rpath chemin relatf par rapport à $root */
  readonly public string $rpath;
  readonly public string $title;
  /** @var list<string> $includes; liste des fichiers inclus */ 
  readonly public array $includes;
  /** @var PhpBlock[] $blocks liste des blocks contenus dans le fichier */
  readonly public array $blocks;
  /** @var array<string, PhpClass> $classes  dictionnaire des classes */
  readonly public array $classes;
  
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
  static function buildTree(string $class='PhpFile', string $rpath='', bool $verbose=false): array|PhpFile {
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
  
  function __construct(string $rpath, TokenArray $tokens=null) {
    $this->rpath = $rpath;
    if (!$tokens)
      $tokens = new TokenArray(self::$root.$rpath);
    $this->title = $this->title($tokens);
    $this->includes = $this->includes($tokens);
    
    // construction des blocks
    $blocks = [];
    for ($tnr=0; $tnr < count($tokens); $tnr++) {
      if ($tokens[$tnr]->src == '}') {
        echo "} détectée dans PhpFile::__construct() au token $tnr<br>\n";
      }
      elseif ($tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $block = PhpBlock::create($tnr, $tokens);
        //echo "{ détectée au token $tnr retournée au token $block->lastTokenNr<br>\n";
        $blocks[] = $block;
        $tnr = $block->lastTokenNr;
      }
    }
    $this->blocks = $blocks;
    
    $this->classes = $this->classes();
  }
  
  /** Retourne l'objet comme array.
   * @return array<mixed> */
  function asArray(): array {
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title."</a>",
      'includes'=> $this->includes,
      'classes'=> array_map(function(PhpClass $class) { return $class->asArray(); }, $this->classes),
      //'blocks'=> array_map(function(PhpBlock $block) { return $block->asArray(); }, $this->blocks),
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
        echo $tokens->symbStr($i, 5),"<br>\n";
        if ($tokens->symbStr($i, 5) == 'T_REQUIRE_ONCE,T_WHITESPACE,T_DIR,.,T_CONSTANT_ENCAPSED_STRING') {
          $inc = dirname(self::$root.$this->rpath).substr($tokens[$i+4]->src, 1, -1);
          if (($rp = realpath($inc)) === false) {
            echo "<b>Erreur d'inclusion de $inc dans $this->rpath</b>\n";
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
  
  /** retourne le spacename du fichier ou '' si aucun n'a été défini */
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
  
  /** Récupère les classes dans les blocks
   * @return array<string,PhpClass> */
  function classes(): array {
    $classes = [];
    foreach ($this->blocks as $block) {
      if (get_class($block) == 'PhpClass') {
        $classes[$block->name] = $block;
      }
    }
    return $classes;
  }
  
  /** représente les blocks contenus dans le fichier comme une table Html */
  function blocksAsHtml(): string {
    $tokens = new TokenArray(self::$root.$this->rpath);
    $rows = [];
    if (!$this->blocks) {
      $rows[] = htmlentities($tokens->srcCode(0, -1, "/only")); // le code de tout le fichier
    }
    else {
      foreach ($this->blocks as $nb => $block) {
        $startTokenNr = ($nb==0) ? 0 : $this->blocks[$nb-1]->lastTokenNr+1;
        $rows[] = htmlentities($tokens->srcCode($startTokenNr, $block->startTokenNr+1, "{$nb}/pre")); // le code avant le block nb
        $rows[] = $block->blocksAsHtml($tokens, "$nb"); // le code du block courant
      }
      $rows[] = htmlentities($tokens->srcCode($block->lastTokenNr+1, -1, "FIN"));
    }
    return "<table border=1>"
          ."<tr><td><pre>".implode("</pre></td></tr>\n<tr><td><pre>", $rows)."</pre></td></tr>"
          ."</table>";
  }

};

/** déduit de l'arbre des fichiers les graphes pour déduire les relations inverses */
class Graph {
  /** @var array<string, string> dictionnaire [{rpath => {title}] */
  static array $titles=[];
  /** @var array<string, array<string, 1>> $incIn matrice [{rpathInclus} => [{rpath_incluants} => 1]] */
  static array $incIn=[];
  /** @var array<string, array<string, int>> $classesInFile matrice [{className} => [{rpath_incluants} => {noLigne}]] */
  static array $classesInFile=[];
  
  /** parcours l'arbre d'inclusion et construit les propriétés $titles et $incIn.
   * @param array<mixed> $tree l'arbre d'inclusion
   */
  static function build(array|PhpFile $tree, string $rpath=''): void {
    //echo "build(rpath=$rpath)<br>\n";
    if (is_object($tree)) { // c'est un fichier Php
      self::$titles[$rpath] = $tree->title;
      foreach ($tree->includes as $inc) {
        if (substr($inc, 0, 6)=='{root}') {
          $inc = substr($inc, 6);
          //echo "inc=$inc<br>\n";
          //echo "addGraph($rpath, $inc)<br>\n";
          self::$incIn[$inc][$rpath] = 1;
        }
      }
      foreach ($tree->classes as $className => $noLine) {
        self::$classesInFile[$className][$rpath] = $noLine;
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
    foreach (self::$classesInFile as $className => $files) {
      foreach ($files as $rpath => $lineNo) {
        // Url vers le fichier à la bonne ligne
        $url = "<a href='viewtoken.php?rpath=$rpath&lineNo=$lineNo#line$lineNo'>$rpath</a>";
        $export[$className]['files'][$url] = $lineNo;
      }
    }
    return $export;
  }
};

PhpFile::$root = realpath(__DIR__.'/..');

echo "<!DOCTYPE html>\n<html><head><title>phpanalyzer</title></head><body>\n";
switch ($_GET['action'] ?? null) {
  case null: {
    echo "<a href='?action=includes'>Arbre des répertoires et fichiers avec fichiers inclus et classes définies</a><br>\n";
    echo "<a href='?action=graph'>Affichage du graphe</a><br>\n";
    echo "<a href='?action=fileIncludedIn'>Inclusions entre fichiers inversées</a><br>\n";
    echo "<a href='?action=classInFile'>Liste des classes et fichiers la définissant</a><br>\n";
    echo "<a href='?action=buildBlocks'>Test de construction des blocks</a><br>\n";
    echo "<a href='?action=detectCalls'>Test de détection des calls</a><br>\n";
    echo "<a href='?action=createCalls'>Construction des calls d'un fichier</a><br>\n";
    break;
  }
  case 'includes': {
    echo "<h2>Arbre des répertoires et fichiers avec inclusions et classes</h2>\n";
    $tree = PhpFile::buildTree(); // construction de l'arbre
    echo '<pre>',str_replace("''", "'", Yaml::dump([PhpFile::$root => PhpFile::treeAsArray($tree)], 99, 2));
    break;
  }
  case 'graph': {
    echo "<h2>Affichage du graphe</h2>\n";
    $tree = PhpFile::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    //echo '<pre>',str_replace("''","'",Yaml::dump(['$incIn'=> Graph::$incIn], 99, 2));
    echo '<pre>',str_replace("''","'",Yaml::dump(['$classesInFile'=> Graph::$classesInFile], 99, 2));
    break;
  }
  case 'fileIncludedIn': {
    echo "<h2>Inclusions inversées</h2>\n";
    $tree = PhpFile::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    echo '<pre>',Yaml::dump(Graph::exportInvIncludes(), 99, 2);
    break;
  }
  case 'classInFile': {
    echo "<h2>Liste des classes et pour chacune les fichiers dans lesquels elle est définie</h2>\n";
    $tree = PhpFile::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    echo '<pre>',str_replace("''", "'", Yaml::dump(Graph::exportClasses(), 99, 2));
    break;
  }
  case 'buildBlocks': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('buildBlocks');
    else {
      $file = new PhpFile($_GET['rpath']);
      echo $file->blocksAsHtml();
    }
    break;
  }
  case 'detectCalls': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('detectCalls');
    else {
      Call::detect(new TokenArray(PhpFile::$root.$_GET['rpath']));
    }
    break;
  }
  case 'createCalls': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('createCalls');
    else {
      $calls = Call::create(new TokenArray(PhpFile::$root.$_GET['rpath']));
      //echo "<pre>calls="; print_r($calls);
      echo "<pre>",
           Yaml::dump([
             $_GET['rpath'].'calls'=>
               array_map(function(Call $call): array { return $call->asArray(); }, $calls)
           ]),
           "</pre>\n";
    }
    break;
  }
}
