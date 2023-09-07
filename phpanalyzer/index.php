<?php
/**
 * construit le graphe des inclusions entre fichiers Php pour afficher différentes informations
 */
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

//ini_set('memory_limit', '1024M');

/** simplification de l'utilisation des tokens Php */
readonly class Token {
  /** @var int $lineNr; no de la ligne du source Php évt. déduit des tokens précédents */
  public int $lineNr;
  /** @var ?int $id; id. du token */
  public ?int $id;
  /** @var ?string $name; nom du token */
  public ?string $name;
  /** @var string $src; code source correspondant au token */
  public string $src;
  
  /** Construction d'un objet Token à partir d'un des éléments récupérés par token_get_all() 
   * @param list<mixed>|string $token */
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

/** Tous les tokens d'un fichier.
 * Classe distincte de PhpFile car il est souvent préférable de ne pas conserver tous les tokens qui prennent de la place.
 * Ainsi l'objet AllTokens peut être créé temporairement pour effectuer des traitements.
 */
readonly class AllTokens {
  /** @var list<Token> $tokens; liste des tokens correspondant au source du fichier */
  readonly public array $tokens; // Token[]
  
  /** lit les tokens d'un fichier et les stocke */
  function __construct(string $path) {
    $code = file_get_contents($path);
    $tokens = [];
    foreach (token_get_all($code) as $token)
      $tokens[] = new Token($token);
    $this->tokens = $tokens;
  }
  
  /** Génère une représentation symbolique d'un fragment de code commencant au token no $startNr et de longueur $len.
   * Cette repr. symbolique est constituée de la concétanation pour les tokens ayant un name de ce name entre {}
   * et pour les autres du src. */
  function symbStr(int $startNr, int $len): string {
    $code = '';
    $endNr = $startNr + $len;
    if ($endNr > count($this->tokens))
      $endNr = count($this->tokens);
    for ($nr=$startNr; $nr<$endNr; $nr++) {
      if ($this->tokens[$nr]->name)
        $code .= '{'.$this->tokens[$nr]->name.'}';
      else
        $code .= $this->tokens[$nr]->src;
    }
    return $code;
  }
  
  /** Reconstruit le code source entre le token no $startNr et le token $endNr.
   * token $startNr compris, token $endNr non compris
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

/** Block de code Php encadré par { et } */
class PhpBlock {
  /** @var int $startTokenNr, no du token de début du block correspondant à '{' */
  readonly public int $startTokenNr; 
  /** @var int $lastTokenNr; no du token de fin du block correspondant à '}' */
  readonly public int $lastTokenNr;
  /** @var list<PhpBlock> $subBlocks; liste de blocks enfants */
  readonly public array $subBlocks;
  
  /** Création d'un block
   * l@param ist<Token> $tokens; liste des tokens du fichier contenant le block
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   */
  function __construct(AllTokens $all, int $startTokenNr) {
    //echo "Appel PhpBlock::__construct(startTokenNr=$startTokenNr)<br>\n";
    $this->startTokenNr = $startTokenNr;
    $subBlocks = [];
    for ($tnr=$startTokenNr+1; $tnr < count($all->tokens); $tnr++) {
      if ($all->tokens[$tnr]->src == '}') {
        $this->lastTokenNr = $tnr;
        $this->subBlocks = $subBlocks;
        return;
      }
      elseif ($all->tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $subBlock = new PhpBlock ($all, $tnr);
        $subBlocks[] = $subBlock;
        $tnr = $subBlock->lastTokenNr;
      }
    }
    echo "<b>Erreur, fin de construction non trouvée pour le block commencé au token $startTokenNr</b></p>\n";
    $this->subBlocks = $subBlocks;
    $this->lastTokenNr = count($all->tokens)-1;
  }

  /** Retourne un PhpBlock comme un array
   * @return array<mixed> */
  function asArray(): array {
    return [
      'startTokenNr'=> $this->startTokenNr,
      'lastTokenNr'=> $this->lastTokenNr,
      'subBlocks'=> array_map(function(PhpBlock $sb) { return $sb->asArray(); }, $this->subBlocks),
    ];
  }
  
  /** représente le block comme une cellule d'une table Html */
  function blocksAsHtml(AllTokens $all, string $id): string {
    if (!$this->subBlocks) {
      $rows = [htmlentities($all->srcCode($this->startTokenNr+1, $this->lastTokenNr+1, "$id/0/leaf"))];
    }
    else {
      $rows = [];
      foreach ($this->subBlocks as $nb => $block) {
        $startTokenNr = ($nb==0) ? $this->startTokenNr+1 : $this->subBlocks[$nb-1]->lastTokenNr+1;
        //$rows[] = "<i>avant le block $nb</i>";
        $rows[] = htmlentities($all->srcCode($startTokenNr+1, $block->startTokenNr+1, "$id/$nb/pre")); // le code avant le block nb
        $rows[] = $block->blocksAsHtml($all, "$id/$nb"); // le code du block courant
      }
      $rows[] = htmlentities($all->srcCode($block->lastTokenNr+1, $this->lastTokenNr+1, "$id/$nb"));
    }
    $blankCell = "<td>&nbsp;&nbsp;</td>"; // cellule blanche pour décaler les blocks et améliorer la clareté
    return "<table border=1>"
      ."<tr>$blankCell<td><pre>".implode("</pre></td></tr>\n<tr>$blankCell<td><pre>", $rows)."</pre></td></tr>"
      ."</table>";
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
  /** @var array<string, int> $classes  liste des classes avec le no de ligne de sa définition */
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
      return new $class($rpath, new AllTokens(self::$root.$rpath));
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
  
  function __construct(string $rpath, AllTokens $all=null) {
    $this->rpath = $rpath;
    if (!$all)
      $all = new AllTokens(self::$root.$rpath);
    $this->title = $this->title($all);
    $this->includes = $this->includes($all);
    $this->classes = $this->classes($all);
    
    // construction des blocks
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
  
  /** Retourne l'objet comme array.
   * @return array<mixed> */
  function asArray(): array {
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title."</a>",
      'includes'=> $this->includes,
      'classes'=> $this->classes,
      //'blocks'=> array_map(function(PhpBlock $block) { return $block->asArray(); }, $this->blocks),
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
        if (preg_match('!^{T_REQUIRE_ONCE}{T_WHITESPACE}{T_DIR}\.{T_CONSTANT_ENCAPSED_STRING}$!', $all->symbStr($i, 5))) {
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
        else {
          echo "<b>Erreur, T_REQUIRE_ONCE détecté mais non interprété</b><br>\n";
        }
      }
    }
    return $includes;
  }
  
  /** retourne le spacename du fichier ou '' si aucun n'a été défini */
  function namespace(AllTokens $all): string {
    // Cas où le namspace est la première instruction après T_OPEN_TAG
    if (preg_match('!^{T_NAMESPACE}{T_WHITESPACE}{T_STRING}$!', $all->symbStr(1, 3))) {
      $namespace = $all->tokens[3]->src;
      //echo "namespace=$namespace<br>\n";
      return "$namespace\\";
    }
    // Cas où le namespace est après T_OPEN_TAG et T_DOC_COMMENT et T_WHITESPACE
    if (preg_match('!^{T_DOC_COMMENT}{T_WHITESPACE}{T_NAMESPACE}{T_WHITESPACE}{T_STRING}$!', $all->symbStr(1, 5))) {
      $namespace = $all->tokens[5]->src;
      //echo "namespace=$namespace<br>\n";
      return "$namespace\\";
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
        if (preg_match('!^{T_CLASS}{T_WHITESPACE}{T_STRING}$!', $all->symbStr($i, 3))) {
          $classes[$namespace.$all->tokens[$i+2]->src] = $all->tokens[$i+2]->lineNr;
        }
        else {
          echo "<b>Erreur, T_CLASS détecté mais non interprété</b><br>\n";
        }
      }
    }
    return $classes;
  }
  
  /** représente les blocks comme une table Html */
  function blocksAsHtml(): string {
    $all = new AllTokens(self::$root.$this->rpath);
    $rows = [];
    if (!$this->blocks) {
      $rows[] = htmlentities($all->srcCode(0, -1, "/only")); // le code de tout le fichier
    }
    else {
      foreach ($this->blocks as $nb => $block) {
        $startTokenNr = ($nb==0) ? 0 : $this->blocks[$nb-1]->lastTokenNr+1;
        $rows[] = htmlentities($all->srcCode($startTokenNr, $block->startTokenNr+1, "{$nb}/pre")); // le code avant le block nb
        $rows[] = $block->blocksAsHtml($all, "$nb"); // le code du block courant
      }
      $rows[] = htmlentities($all->srcCode($block->lastTokenNr+1, -1, "FIN"));
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
}
