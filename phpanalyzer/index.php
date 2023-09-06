<?php
/**
 * construit le graphe des inclusions entre fichiers Php pour afficher différentes informations
 */
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

ini_set('memory_limit', '1024M');

/** simplification de l'utilisation des token Php */
readonly class Token {
  /** @var ?int $lineNr; no de la ligne du source Php */
  public ?int $lineNr;
  /** @var ?int $id; id. du token */
  public ?int $id;
  /** @var ?string $name; nom du token */
  public ?string $name;
  /** @var string $src; code source correspondant au token */
  public string $src;
  
  function __construct(array|string $token) {
    $lineNr = 0;
    if (is_array($token)) {
      $this->id = $token[0];
      $this->name = token_name($token[0]);
      $this->lineNr = $token[2];
      $this->src = $token[1];
      $lineNr = $this->lineNr;
    }
    else {
      $this->id = null;
      $this->name = null;
      $this->lineNr = $lineNr;
      $this->src = $token;
    }
  }
};

/** Tous les tokens d'un fichier */
readonly class AllTokens {
  /** @var list<Token> $t; liste des tokens correspondant au source du fichier */
  readonly public array $tokens; // Token[]
  
  /** lit les tokens d'un fichier et les stocke */
  function __construct(string $path) {
    $code = file_get_contents($path);
    $tokens = [];
    foreach (token_get_all($code) as $token)
      $tokens[] = new Token($token);
    $this->tokens = $tokens;
  }
  
  /** Retourne le code source entre le token no $startNr et le token $endNr.
   * token $start compris, token $end non compris
  */
  function srcCode(int $startNr, int $endNr, string $id): string {
    $code = '';
    //$code = "$id ($startNr->$endNr)\n";
    if ($endNr == -1)
      $endNr = count($this->tokens);
    for ($nr=$startNr; $nr<$endNr; $nr++) {
      $code .= $this->tokens[$nr]->src;
    }
    return $code;
  }
};

/** Fichier Php analysé avec son chemin relatif et ses tokens et organisation des fichiers en un arbre */
class PhpFileAn {
  /** liste des sous-répertoires exclus du parcours lors de la construction de l'arbre */
  const EXCLUDED = ['.','..','.git','vendor','shomgeotiff','gan','data','temp'];
  /** @var string $rpath chemin relatf par rapport à $root */
  readonly public string $rpath;
  readonly public string $title;
  readonly public array $includes;
  readonly public array $classes;
  
  /** @var string $root; chemin de la racine de l'arbre */
  static string $root;

  /** Construit et retourne l'arbre des répertoires et fichiers
   *
   * Structure récursive de l'arbre:
   * TREE ::= PhpFileAn # pour un fichier
   *        | {{rpath} => TREE} # pour un répertoire
   *
   * @return array<string, mixed>|PhpFileAn */
  static function buildTree(string $class='PhpFileAn', string $rpath='', bool $verbose=false): array|PhpFileAn {
    if ($verbose)
      echo "buildTree(class=$class, rpath=$rpath)<br>\n";
    if (is_file(self::$root.$rpath) && (substr($rpath, -4)=='.php')) {
      return new $class($rpath, new AllTokens(self::$root.$rpath));
    }
    elseif (is_dir(self::$root.$rpath)) {
      $result = [];
      foreach (new DirectoryIterator(self::$root.$rpath) as $entry) {
        if (in_array($entry, self::EXCLUDED)) continue;
        if ($tree = self::buildTree($class, "$rpath/$entry", $verbose))
          $result["$entry"] = $tree;
      }
      if ($result)
        return $result;
      else
        return [];
    }
    else {
      //echo "$rpath ni phpFile ni dir\n";
      return [];
    }
  }
  
  /** transforme l'arbre des fichiers en un arbre composé d'Array
   * @param array<mixed>|PhpFileAn $tree
   * @return array<mixed>
   */
  static function treeAsArray(array|PhpFileAn $tree): array {
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
  
  function __construct(string $rpath, AllTokens $tokens) {
    $this->rpath = $rpath;
    $this->title = $this->title($tokens);
    $this->includes = $this->includes($tokens);
    $this->classes = $this->classes($tokens);
  }
  
  /* * retourne un objet AllTokens
  function tokens(): AllTokens { return new AllTokens(self::$root.$this->rpath); } */
  
  /** Retourne l'objet comme array
   * @return array<mixed> */
  function asArray(): array {
    $tokens = $this->tokens();
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title."</a>",
      'includes'=> $this->includes,
      'classes'=> $this->classes,
    ];
  }
  
  /** Retourne le titre du fichier extrait du commentaire PhpDoc */
  function title(AllTokens $all): string {
    if (isset($all->tokens[1]) && ($all->tokens[1]->id == T_DOC_COMMENT)) {
      $comment = $all->tokens[1]->src;
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
  function includes(AllTokens $all): array {
    $includes = [];
    foreach ($all->tokens as $i => $token) {
      if ($token->id == T_REQUIRE_ONCE) {
        if ($all->tokens[$i+4]->id == T_CONSTANT_ENCAPSED_STRING) {
          //echo "string=",$this->tokens[$i+4]->src,"\n";
          $inc = dirname(self::$root.$this->rpath).substr($all->tokens[$i+4]->src, 1, -1);
          if (($rp = realpath($inc)) === false) {
            echo "<b>Erreur d'inclusion de $inc dans $this->rpath</b>\n";
            $includes[] = "$inc (BAD)";
          }
          elseif (substr($rp, 0, strlen(self::$root)) == self::$root)
            $includes[] = '{root}'.substr($rp, strlen(self::$root));
          else
            $includes[] = $rp;
        }
      }
    }
    return $includes;
  }
  
  /** retourne le spacename du fichier */
  function namespace(AllTokens $all): string {
    // Cas où le namspace est la première instruction après T_OPEN_TAG
    if (isset($all->tokens[1]) && ($all->tokens[1]->id == T_NAMESPACE)) {
      if ($all->tokens[3]->id == T_STRING) {
        $namespace = $all->tokens[3]->src;
        //echo "namespace=$namespace\n";
        return "$namespace\\";
      }
    }
    // Cas où le namespace est après T_OPEN_TAG et T_DOC_COMMENT et T_WHITESPACE
    if (isset($all->tokens[3]) && ($all->tokens[3]->id == T_NAMESPACE)) {
      if ($all->tokens[5]->id == T_STRING) {
        $namespace = $all->tokens[5]->src;
        //echo "namespace=$namespace\n";
        return "$namespace\\";
      }
    }
    return '';
  }
  
  /** Retourne la liste des classes définies dans le fichier avec le numéro de la ligne à laquelle la classe est définie
   * @return array<string, int> */
  function classes(AllTokens $all): array {
    $classes = [];
    $namespace = $this->namespace($all);
    foreach ($all->tokens as $i => $token) {
      if ($token->id == T_CLASS) {
        if ($all->tokens[$i+2]->id == T_STRING) {
          $classes[$namespace.$all->tokens[$i+2]->src] = $all->tokens[$i+2]->lineNr;
        }
      }
    }
    return $classes;
  }
};

/** Block de code Php encadré par { et } */
class PhpBlock {
  /** @var int $startTokenNr, no du token de début du block correspondant à '{' */
  readonly public int $startTokenNr; 
  /** @var int $lastTokenNr; no du token de fin du block correspondant à '}' */
  readonly public int $lastTokenNr;
  /** @var list<Block> $subBlocks; liste de blocks enfants */
  readonly public array $subBlocks;
  
  /** Création d'un block
   * l@param ist<Token> $tokens; liste des tokens du fichier contenant le block
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   */
  function __construct(AllTokens $all, int $startTokenNr) {
    echo "Appel PhpBlock::__construct(startTokenNr=$startTokenNr)<br>\n";
    $this->startTokenNr = $startTokenNr;
    $subBlocks = [];
    for ($tnr=$startTokenNr+1; $tnr < count($all->tokens); $tnr++) {
      if ($all->tokens[$tnr]->src == '}') {
        $this->lastTokenNr = $tnr;
        $this->subBlocks = $subBlocks;
        return;
      }
      elseif ($all->tokens[$tnr]->src == '{') {
        echo "{ détectée au token $tnr<br>\n";
        $subBlock = new PhpBlock ($all, $tnr);
        $subBlocks[] = $subBlock;
        $tnr = $subBlock->lastTokenNr;
      }
    }
  }

  function asArray(): array {
    return [
      'startTokenNr'=> $this->startTokenNr,
      'lastTokenNr'=> $this->lastTokenNr,
      'subBlocks'=> array_map(function(PhpBlock $sb) { return $sb->asArray(); }, $this->subBlocks),
    ];
  }
  
  /** représente le block comme une cellule d'une table Html */
  function asHtml(AllTokens $all, string $id): string {
    if (!$this->subBlocks) {
      $rows = [htmlentities($all->srcCode($this->startTokenNr+1, $this->lastTokenNr+1, "$id/0/leaf"))];
    }
    else {
      $rows = [];
      foreach ($this->subBlocks as $nb => $block) {
        $startTokenNr = ($nb==0) ? $this->startTokenNr+1 : $this->subBlocks[$nb-1]->lastTokenNr+1;
        //$rows[] = "<i>avant le block $nb</i>";
        $rows[] = htmlentities($all->srcCode($startTokenNr+1, $block->startTokenNr+1, "$id/$nb/pre")); // le code avant le block nb
        $rows[] = $block->asHtml($all, "$id/$nb"); // le code du block courant
      }
      $rows[] = htmlentities($all->srcCode($block->lastTokenNr+1, $this->lastTokenNr+1, "$id/$nb"));
    }
    $blankCell = "<td>&nbsp;&nbsp;</td>";
    return "<table border=1>"
      ."<tr>$blankCell<td><pre>".implode("</pre></td></tr>\n<tr>$blankCell<td><pre>", $rows)."</pre></td></tr>"
      ."</table>";
  }
};

/** Détermination des blocks */
class PhpFileBlock extends PhpFileAn {
  /** @var PhpBocks[] $blocks liste des blocks contenus dans le fichier */
  readonly public array $blocks;
  
  /** liste tous les fichiers avec un lien vers */
  static function chooseFile(string $rpath=''): void {
    foreach (new DirectoryIterator(PhpFileAn::$root.$rpath) as $entry) {
      if (in_array($entry, self::EXCLUDED)) continue;
      if (is_file(PhpFileAn::$root.$rpath."/$entry") && (substr($entry, -4)=='.php')) {
        echo "<a href='?action=phpFileBlock&rpath=$rpath/$entry'>$rpath/$entry</a><br>\n";
      }
      elseif (is_dir(PhpFileAn::$root.$rpath."/$entry")) {
        self::chooseFile("$rpath/$entry");
      }
    }
  }
  
  function __construct(string $rpath) {
    $all = new AllTokens(self::$root.$rpath);
    parent::__construct($rpath, $all);
    $blocks = [];
    for ($tnr=0; $tnr < count($all->tokens); $tnr++) {
      if ($all->tokens[$tnr]->src == '}') {
        echo "} détectée dans PhpFileBlock::__construct() au token $tnr<br>\n";
      }
      elseif ($all->tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $block = new PhpBlock ($all, $tnr);
        //echo "{ détectée au token $tnr retournée au token $block->lastTokenNr<br>\n";
        $blocks[] = $block;
        $tnr = $block->lastTokenNr;
      }
    }
    $this->blocks = $blocks;
  }
  
  /** Retourne l'objet comme array
   * @return array<mixed> */
  function asArray(): array {
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title."</a>",
      'includes'=> $this->includes,
      'classes'=> $this->classes,
      'blocks'=> array_map(function(PhpBlock $block) { return $block->asArray(); }, $this->blocks),
    ];
  }
  
  /** représente $this comme une table Html */
  function asHtml(): string {
    $all = new AllTokens(self::$root.$this->rpath);
    $rows = [];
    foreach ($this->blocks as $nb => $block) {
      $startTokenNr = ($nb==0) ? 0 : $this->blocks[$nb-1]->lastTokenNr+1;
      $rows[] = htmlentities($all->srcCode($startTokenNr, $block->startTokenNr+1, "{$nb}/pre")); // le code avant le block nb
      $rows[] = $block->asHtml($all, "$nb"); // le code du block courant
    }
    $rows[] = htmlentities($all->srcCode($block->lastTokenNr+1, -1, "FIN"));
    return "<table border=1><tr><td><pre>".implode("</pre></td></tr>\n<tr><td><pre>", $rows)."</pre></td></tr></table>";
  }
  
  function showFile(): void {
    //echo '<pre>',str_replace("''","'",Yaml::dump($this->asArray(), 99, 2)),"</pre>\n";
    //echo "<pre>"; print_r($this);
    echo $this->asHtml();
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
  static function build(array|PhpFileAn $tree, string $rpath=''): void {
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

PhpFileAn::$root = realpath(__DIR__.'/..');

echo "<!DOCTYPE html>\n<html><head><title>phpanalyzer</title></head><body>\n";
switch ($_GET['action'] ?? null) {
  case null: {
    echo "<a href='?action=includes'>Arbre des répertoires et fichiers avec fichiers inclus et classes définies</a><br>\n";
    echo "<a href='?action=graph'>Affichage du graphe</a><br>\n";
    echo "<a href='?action=fileIncludedIn'>Inclusions entre fichiers inversées</a><br>\n";
    echo "<a href='?action=classInFile'>Liste des classes et fichiers la définissant</a><br>\n";
    echo "<a href='?action=phpFileBlock'>phpFileBlock</a><br>\n";
    break;
  }
  case 'includes': {
    echo "<h2>Arbre des répertoires et fichiers avec inclusions et classes</h2>\n";
    $tree = PhpFileAn::buildTree(); // construction de l'arbre
    echo '<pre>',str_replace("''", "'", Yaml::dump([PhpFileAn::$root => PhpFileAn::treeAsArray($tree)], 99, 2));
    break;
  }
  case 'graph': {
    echo "<h2>Affichage du graphe</h2>\n";
    $tree = PhpFileAn::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    //echo '<pre>',str_replace("''","'",Yaml::dump(['$incIn'=> Graph::$incIn], 99, 2));
    echo '<pre>',str_replace("''","'",Yaml::dump(['$classesInFile'=> Graph::$classesInFile], 99, 2));
    break;
  }
  case 'fileIncludedIn': {
    echo "<h2>Inclusions inversées</h2>\n";
    $tree = PhpFileAn::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    echo '<pre>',Yaml::dump(Graph::exportInvIncludes(), 99, 2);
    break;
  }
  case 'classInFile': {
    echo "<h2>Liste des classes et pour chacune les fichiers dans lesquels elle est définie</h2>\n";
    $tree = PhpFileAn::buildTree(); // construction de l'arbre
    Graph::build($tree); // fabrication du graphe
    echo '<pre>',str_replace("''", "'", Yaml::dump(Graph::exportClasses(), 99, 2));
    break;
  }
  case 'phpFileBlock': {
    if (!isset($_GET['rpath']))
      PhpFileBlock::chooseFile();
    else {
      $file = new phpFileBlock($_GET['rpath']);
      $file->showFile();
    }
    break;
  }
}
