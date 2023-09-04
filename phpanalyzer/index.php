<?php
/**
 * construit le graphe des inclusions entre fichiers Php pour afficher différentes informations
 */
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/** simplification de l'utilisation des token Php */
readonly class Token {
  /** @var ?int $lineNo; no de la ligne du source Php */
  public ?int $lineNo;
  /** @var ?int $id; id. du token */
  public ?int $id;
  /** @var ?string $name; nom du token */
  public ?string $name;
  /** @var string $src; code source correspondant au token */
  public string $src;
  
  /** lit les tokens d'un fichier et les retourne sous la forme d'une liste
   * @return list<Token> */
  static function get_all(string $path): array {
    $code = file_get_contents($path);
    $tokens = [];
    foreach (token_get_all($code) as $token)
      $tokens[] = new Token($token);
    return $tokens;
  }
  
  function __construct(mixed $token) {
    if (is_array($token)) {
      $this->id = $token[0];
      $this->name = token_name($token[0]);
      $this->lineNo = $token[2];
      $this->src = $token[1];
    }
    else {
      $this->id = null;
      $this->name = null;
      $this->lineNo = null;
      $this->src = $token;
    }
  }
};

/** Fichier Php analysé avec son chemin relatif et ses tokens et organisation des fichiers en un arbre */
class PhpFileAn {
  /** liste des sous-répertoires exclus du parcours lors de la construction de l'arbre */
  const EXCLUDED = ['.','..','.git','vendor','shomgeotiff','gan','data','temp'];
  /** @var string $rpath chemin relatf par rapport à $root */
  readonly public string $rpath;
  /** @var list<Token> $tokens; liste des tokens correspondant au source du fichier */
  readonly public array $tokens; // Token[]
  
  /** @var string $root; chemin de la racine de l'arbre */
  static string $root;

  /** Construit et retourne l'arbre des répertoires et fichiers
   *
   * Structure récursive de l'arbre:
   * TREE ::= PhpFileAn # pour un fichier
   *        | {{rpath} => TREE} # pour un répertoire
   *
   * @return array<string, mixed>|PhpFileAn */
  static function buildTree(string $rpath='', bool $verbose=false): array|PhpFileAn {
    if ($verbose)
      echo "analyze($rpath)\n";
    if (is_file(self::$root.$rpath) && (substr($rpath, -4)=='.php')) {
      return new self($rpath);
    }
    elseif (is_dir(self::$root.$rpath)) {
      $result = [];
      foreach (new DirectoryIterator(self::$root.$rpath) as $entry) {
        if (in_array($entry, self::EXCLUDED)) continue;
        if ($tree = self::buildTree("$rpath/$entry"))
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
  
  function __construct(string $rpath) {
    $this->rpath = $rpath;
    $this->tokens = Token::get_all(self::$root.$rpath);
  }
  
  /** Retourne l'objet comme array
   * @return array<mixed> */
  function asArray(): array {
    return [
      'title'=> "<a href='viewtoken.php?rpath=$this->rpath'>".$this->title()."</a>",
      'includes'=> $this->includes(),
      'classes'=> $this->classes(),
    ];
  }
  
  /** Retourne le titre du fichier extrait du commentaire PhpDoc */
  function title(): string {
    if (isset($this->tokens[1]) && ($this->tokens[1]->id == T_DOC_COMMENT)) {
      $comment = $this->tokens[1]->src;
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
  function includes(): array {
    $includes = [];
    foreach ($this->tokens as $i => $token) {
      if ($token->id == T_REQUIRE_ONCE) {
        if ($this->tokens[$i+4]->id == T_CONSTANT_ENCAPSED_STRING) {
          //echo "string=",$this->tokens[$i+4]->src,"\n";
          $inc = dirname(self::$root.$this->rpath).substr($this->tokens[$i+4]->src, 1, -1);
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
  function namespace(): string {
    // Cas où le namspace est la première instruction après T_OPEN_TAG
    if (isset($this->tokens[1]) && ($this->tokens[1]->id == T_NAMESPACE)) {
      if ($this->tokens[3]->id == T_STRING) {
        $namespace = $this->tokens[3]->src;
        //echo "namespace=$namespace\n";
        return "$namespace\\";
      }
    }
    // Cas où le namespace est après T_OPEN_TAG et T_DOC_COMMENT et T_WHITESPACE
    if (isset($this->tokens[3]) && ($this->tokens[3]->id == T_NAMESPACE)) {
      if ($this->tokens[5]->id == T_STRING) {
        $namespace = $this->tokens[5]->src;
        //echo "namespace=$namespace\n";
        return "$namespace\\";
      }
    }
    return '';
  }
  
  /** Retourne la liste des classes définies dans le fichier avec le numéro de la ligne à laquelle la classe est définie
   * @return array<string, int> */
  function classes(): array {
    $classes = [];
    $namespace = $this->namespace();
    foreach ($this->tokens as $i => $token) {
      if ($token->id == T_CLASS) {
        if ($this->tokens[$i+2]->id == T_STRING) {
          $classes[$namespace.$this->tokens[$i+2]->src] = $this->tokens[$i+2]->lineNo;
        }
      }
    }
    return $classes;
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
      self::$titles[$rpath] = $tree->title();
      foreach ($tree->includes() as $inc) {
        if (substr($inc, 0, 6)=='{root}') {
          $inc = substr($inc, 6);
          //echo "inc=$inc<br>\n";
          //echo "addGraph($rpath, $inc)<br>\n";
          self::$incIn[$inc][$rpath] = 1;
        }
      }
      foreach ($tree->classes() as $className => $noLine) {
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
}
