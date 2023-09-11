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
  function __construct(array|string $token, int $lineNr) {
    if (is_array($token)) {
      $this->id = $token[0];
      $this->name = token_name($token[0]);
      $this->lineNr = $token[2];
      $this->src = $token[1];
    }
    else {
      $this->id = null;
      $this->name = null;
      $this->lineNr = $lineNr;
      $this->src = $token;
    }
  }
  
  function __toString(): string {
    return Yaml::dump(['lineNr'=> $this->lineNr, 'name'=> $this->name, 'src'=> $this->src]);
  }
};

/** Les tokens correspondant à un fichier gérés comme un array de Token.
 * Classe distincte de PhpFile car il est souvent préférable de ne pas conserver tous les tokens qui prennent de la place.
 * Ainsi l'objet TokenArray peut être créé temporairement pour effectuer des traitements.
 * @extends ArrayObject<int,Token> */
class TokenArray extends ArrayObject {
  function __construct(string $path) {
    $code = file_get_contents($path);
    $lineNr = 0;
    foreach (token_get_all($code) as $token) {
      $token = new Token($token, $lineNr);
      $this[] = $token;
      $lineNr = $token->lineNr;
    }
  }
  
  /** Génère une représentation symbolique d'un fragment de code commencant au token no $startNr et de longueur $len.
   * Si $len > 0 alors cette repr. symbolique est constituée de la concaténation pour les tokens ayant un name
   * de ce name et pour les autres du src séparés par ','.
   * Si $len < 0 alors la repr. est structurée en sens inverse */
  function symbStr(int $startNr, int $len): string {
    if ($startNr < 0)
      $startNr = 0;
    if ($startNr >= count($this))
      $startNr = count($this) - 1;
    $code = '';
    if ($len > 0) {
      $endNr = $startNr + $len;
      if ($endNr > count($this))
        $endNr = count($this);
      for ($nr=$startNr; $nr<$endNr; $nr++) {
        $code .= ($this[$nr]->name ? $this[$nr]->name : $this[$nr]->src).',';
      }
    }
    elseif ($len < 0) {
      for ($i=0; $i < - $len ; $i++) {
        $nr = $startNr - $i;
        if ($nr < 0)
          return $code;
        $code .= ($this[$nr]->name ? $this[$nr]->name : $this[$nr]->src).',';
      }
    }
    return substr($code, 0, -1); // j'enlève le dernier séparateur
  }
  
  /** Reconstruit le code source entre le token no $startNr et le token $endNr.
   * token $startNr compris, token $endNr non compris
  */
  function srcCode(int $startNr, int $endNr, string $id=''): string {
    $code = '';
    //$code = "$id ($startNr->$endNr)\n";
    if ($startNr < 0)
      $startNr = 0;
    if (($endNr == -1) || ($endNr > count($this)))
      $endNr = count($this);
    for ($nr=$startNr; $nr<$endNr; $nr++) {
      $code .= $this[$nr]->src;
    }
    return $code;
  }

  /** Retourne le no de token correspondant au $src précédent $startNr.
   * Retourne -1 si la chaine n'a pas été trouvée */
  function findSrcBackward(int $startNr, string $src): int {
    for ($nr = $startNr; $nr >= 0; $nr--) {
      if ($this[$nr]->src == $src)
        return $nr;
    }
    return -1;
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
  
  /** Création d'un block en distinguant classe, fonction ou autre block.
   * Pour être détectées, les fonctions autres que __construct() doivent avoir un type de retour
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   * @param TokenArray $tokens; tokens du fichier contenant le block
   */
  static function create(int $startTokenNr, TokenArray $tokens): PhpBlock {
    //die("Fin ligne ".__LINE__);
    echo "PhpBlock::create(startTokenNr=$startTokenNr)<br>\n";
    echo "symbStr=",$tokens->symbStr($startTokenNr-1, -12),"<br>\n";
    
    // class {nom_classe} {
    // class {nom_classe} extends {nom_classe_mère} {
    // class {nom_class} implements {interface_name} {
    // class {nom_class} extends {nom_classe_mère} implements {interface_name} {
    $pattern = '!^(T_WHITESPACE,)?'
        .'(T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,)?'   // implements {interface_name}
        .'((T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,T_WHITESPACE,)?' // extends {nom_classe_mère}
        .'T_STRING,T_WHITESPACE,T_CLASS!';                       // class {nom_classe}
     if (preg_match($pattern, $tokens->symbStr($startTokenNr-1, -12))) {
      echo "Définition de classe détectée<br>\n";
      return new PhpClass($startTokenNr, $tokens);
    }
    // symbStr={T_WHITESPACE}{T_STRING}?{T_WHITESPACE}
    elseif (preg_match('!^(T_WHITESPACE,)?(T_STRING,|T_ARRAY,)(\?,)?(T_WHITESPACE,)?:!', $tokens->symbStr($startTokenNr-1, -5))) {
      echo "Function détectée avec type de retour<br>\n";
      return new PhpFunction($startTokenNr, $tokens);
    }
    else {
      if (preg_match('!^(T_WHITESPACE,)?\)!', $tokens->symbStr($startTokenNr-1, -4))) {
        echo "Function détectée sans type de retour<br>\n";
        $openBracketNr = $tokens->findSrcBackward($startTokenNr, '('); // recherche de la ( de début des paramètres
        echo 'symbStrBracket=',$tokens->symbStr($openBracketNr, -12),"<br>\n";
        echo "srcCode=",htmlentities($tokens->srcCode($openBracketNr-12, $openBracketNr, '')),"<br>\n";
        if ($tokens->symbStr($openBracketNr, -4) == '(,T_STRING,T_WHITESPACE,T_FUNCTION') {
          echo "détection de function xxx(<br>\n";
          if ($tokens[$openBracketNr-1]->src == '__construct') {
            //echo "Détection de __construct()<br>\n";
            return new PhpFunction($startTokenNr, $tokens);
          }
          else
            echo "fonction sans type de retour non reconnue<br>\n";
          // cas éventuel d'une fonction sans type de retour non reconnue
        }
        elseif ($tokens->symbStr($openBracketNr, -5) == '(,T_WHITESPACE,T_STRING,T_WHITESPACE,T_FUNCTION') {
          echo "détection de function xxx (<br>\n";
          if ($tokens[$openBracketNr-2]->src == '__construct') {
            echo "Détection de __construct ()<br>\n";
            return new PhpFunction($startTokenNr, $tokens);
          }
          else
            echo "fonction sans type de retour non reconnue<br>\n";
          // cas éventuel d'une fonction sans type de retour non reconnue
        }
      }
      echo "NI Class Ni Function détectée<br>\n";
      return new self($startTokenNr, $tokens);
    }
  }
  
  /** Création d'un block de base.
   * Analyse l'existence de sous-blocks
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   * @param TokenArray $tokens; liste des tokens du fichier contenant le block
   */
  function __construct(int $startTokenNr, TokenArray $tokens) {
    echo "Appel PhpBlock::__construct(startTokenNr=$startTokenNr)<br>\n";
    $this->startTokenNr = $startTokenNr;
    $subBlocks = [];
    for ($tnr=$startTokenNr+1; $tnr < count($tokens); $tnr++) {
      if ($tokens[$tnr]->src == '}') {
        $this->lastTokenNr = $tnr;
        $this->subBlocks = $subBlocks;
        return;
      }
      elseif ($tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $subBlock = PhpBlock::create($tnr, $tokens);
        $subBlocks[] = $subBlock;
        $tnr = $subBlock->lastTokenNr;
      }
    }
    echo "<b>Erreur, fin de construction non trouvée pour le block commencé au token $startTokenNr</b></p>\n";
    $this->subBlocks = $subBlocks;
    $this->lastTokenNr = count($tokens)-1;
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
  function blocksAsHtml(TokenArray $tokens, string $id): string {
    $rows = [];
    if (!$this->subBlocks) {
      $rows[] = htmlentities($tokens->srcCode($this->startTokenNr+1, $this->lastTokenNr+1, "$id/0/leaf"));
    }
    else {
      foreach ($this->subBlocks as $nb => $block) {
        $startTokenNr = ($nb==0) ? $this->startTokenNr+1 : $this->subBlocks[$nb-1]->lastTokenNr+1;
        //$rows[] = "<i>avant le block $nb</i>";
        // le code avant le block nb
        $rows[] = htmlentities($tokens->srcCode($startTokenNr+1, $block->startTokenNr+1, "$id/$nb/pre"));
        $rows[] = $block->blocksAsHtml($tokens, "$id/$nb"); // le code du block courant
      }
      $rows[] = htmlentities($tokens->srcCode($block->lastTokenNr+1, $this->lastTokenNr+1, "$id/$nb"));
    }
    $blankCell = "<td>&nbsp;&nbsp;</td>"; // cellule blanche pour décaler les blocks et améliorer la clareté
    return
       //'<b>'.get_class($this).'</b>'
      "<table border=1>"
      ."<tr>$blankCell<td><pre>".implode("</pre></td></tr>\n<tr>$blankCell<td><pre>", $rows)."</pre></td></tr>"
      ."</table>";
  }
};

/** Block correspondant à la définition d'une classe */
class PhpClass extends PhpBlock {
  readonly public string $name;
  readonly public string $parentClassName; // '' si pas de classe mère
  readonly public string $interface; // '' si pas d'interface
  readonly public int $lineNr;
  //readonly public string $title;

  /** Définition d'une classe ; gère les différents cas de figure */
  function __construct(int $startTokenNr, TokenArray $tokens) {
    parent::__construct($startTokenNr, $tokens);

    $this->lineNr = $tokens[$startTokenNr]->lineNr;
    if ($tokens[$startTokenNr-1]->id == T_WHITESPACE)
      $startTokenNr--;
    if ($tokens->symbStr($startTokenNr-1, -3) == 'T_STRING,T_WHITESPACE,T_CLASS') { // class {nom_classe} {
      //echo "class {nom_classe}<br>\n";
      $this->name = $tokens[$startTokenNr-1]->src;
      $this->parentClassName = '';
      $this->interface = '';
      //echo "class $this->name<br>\n";
    }
    else {
      // class {nom_classe} extends {nom_classe_mère} {
      $symbStrPattern = '!^(T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,' // extends {nom_classe_mère}
          .'T_WHITESPACE,T_STRING,T_WHITESPACE,T_CLASS!'; // class {nom_classe} 
      //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
      if (preg_match($symbStrPattern, $tokens->symbStr($startTokenNr-1, -7))) {
        //echo "class {nom_classe} extends {nom_classe_mère}<br>\n";
        $this->name = $tokens[$startTokenNr-5]->src;
        $this->parentClassName = $tokens[$startTokenNr-1]->src;
        $this->interface = '';
        //echo "class $this->name extends $this->parentClassName<br>\n";
      }
      else {
        // class {nom_class} implements {interface_name} {
        $symbStr = 'T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,T_STRING,T_WHITESPACE,T_CLASS';
        //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
        if ($tokens->symbStr($startTokenNr-1, -7) == $symbStr) {
          //echo "class {nom_classe} implements {interface_name}<br>\n";
          $this->name = $tokens[$startTokenNr-5]->src;
          $this->parentClassName = '';
          $this->interface = $tokens[$startTokenNr-1]->src;
          //echo "class $this->name implements $this->interface<br>\n";
        }
        else {
          // class {nom_class} extends {nom_classe_mère} implements {interface_name} {
          $symbStrPattern = '!^'
                    .'T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,' // implements {interface_name} {
                    .'(T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,T_WHITESPACE,' // extends {nom_classe_mère}
                    .'T_STRING,T_WHITESPACE,T_CLASS!'; // class {nom_class}
          //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
          if (preg_match($symbStrPattern, $tokens->symbStr($startTokenNr-1, -11))) {
            //echo "class {nom_classe} extends {nom_classe_mère} implements {interface_name}<br>\n";
            $this->name = $tokens[$startTokenNr-9]->src;
            $this->parentClassName = $tokens[$startTokenNr-5]->src;
            $this->interface = $tokens[$startTokenNr-1]->src;
            //echo "class $this->name extends $this->parentClassName implements $this->interface<br>\n";
          }
          else {
            die("Fin ligne ".__LINE__." sur erreur dans PhpClass::__construct()");
          }
        }
      }
    }
  }

  /** Retourne une PhpClass comme un array
   * @return array<mixed> */
  function asArray(): array {
    return array_merge(
      [
        'class'=> [
          'name'=> $this->name,
          'parentClassName'=> $this->parentClassName,
          'interface'=> $this->interface,
        ],
      ],
      parent::asArray());
  }
  
  /** représente le block comme une cellule d'une table Html */
  function blocksAsHtml(TokenArray $tokens, string $id): string {
    return "<b>Class $this->name"
      .($this->parentClassName ? " extends $this->parentClassName" : '')
      .($this->interface ? " implements $this->interface" : '')
      .'</b><br>'
      .parent::blocksAsHtml($tokens, $id);
  }
};

/** Block correspondant à la définition d'une fonction ou d'une méthode */
class PhpFunction extends PhpBlock {
  readonly public string $name; // '' si fonction anonyme
  readonly public string $params;
  readonly public int $lineNr;
  //readonly public string $title;
  
  function __construct(int $startTokenNr, TokenArray $tokens) {
    echo "PhpFunction::__construct(startTokenNr=$startTokenNr)<br>\n";
    parent::__construct($startTokenNr, $tokens);
    
    $this->lineNr = $tokens[$startTokenNr]->lineNr;
    
    $nr = $tokens->findSrcBackward($startTokenNr, '('); // recherche de la parenthèse ouvrante dé début des paramètres
    $this->params = $tokens->srcCode($nr, $startTokenNr, '');
    $nr--;
    if ($tokens[$nr]->id == T_WHITESPACE)
      $nr--;
    // $nr pointe sur le premier token <> T_WHITESPACE avant la '('
    echo 'symbStrBracket=',$tokens->symbStr($nr, -12),"<br>\n";
    if (in_array($tokens[$nr]->id, [T_STRING, T_EMPTY, T_NAMESPACE])) { // function ff(
      $this->name = $tokens[$nr]->src;
    }
    elseif (in_array($tokens[$nr]->id, [T_FUNCTION, T_USE])) { // function( | function use(
      $this->name = '';
    }
    else {
      //for($i=0; $i<1000; $i++) echo "lineNr($i)=",$all->tokens[$i],"<br>\n";
      echo "<b>Erreur dans PhpFunction::__construct()</b><br>\n";
      echo "ligne=",$tokens[$nr]->lineNr,"<br>\n";
      echo 'symbStr=',$tokens->symbStr($nr, -3),"<br>\n";
      echo 'srcCode=',htmlentities($tokens->srcCode($nr-8, $nr+5, '')),"<br>\n";
      die("Erreur dans PhpFunction::__construct() ligne ".__LINE__);
    }
  }
  
  /** Retourne une PhpFunction comme un array
   * @return array<mixed> */
  function asArray(): array {
    return array_merge(
      [
        'function'=> [
          'name'=> $this->name,
          'params'=> $this->params,
        ],
      ],
      parent::asArray());
  }
  
  /** représente le block comme une cellule d'une table Html */
  function blocksAsHtml(TokenArray $tokens, string $id): string {
    return "<b>Function ".($this->name ? $this->name : '(anonym)')." $this->params"
      .'</b><br>'
      .parent::blocksAsHtml($tokens, $id);
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

  /** Test de la détection des appels de fonctions et méthodes */
  function calls(): void {
    echo "<b>PhpFile::calls()</b><br>\n";
    $tokens = new TokenArray(self::$root.$this->rpath);
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
    echo "<a href='?action=buildCalls'>Test de construction des calls</a><br>\n";
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
  case 'buildCalls': {
    if (!isset($_GET['rpath']))
      PhpFile::chooseFile('buildCalls');
    else {
      $file = new PhpFile($_GET['rpath']);
      echo $file->calls();
    }
    break;
  }
}
