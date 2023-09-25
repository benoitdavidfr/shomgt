<?php
/**
 * Fichier Php avec ses définitions de fonctions et classes
 * @package phpanalyzer
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/token.inc.php';

use Symfony\Component\Yaml\Yaml;

//ini_set('memory_limit', '1024M');

/** Block de code Php encadré par { et } */
class PhpBlock {
  /** @var int $startTokenNr, no du token de début du block correspondant à '{' */
  readonly public int $startTokenNr; 
  readonly public int $lineNr;
  /** @var int $lastTokenNr; no du token de fin du block correspondant à '}' */
  readonly public int $lastTokenNr;
  /** @var list<PhpBlock> $subBlocks; liste de blocks enfants */
  readonly public array $subBlocks;
  
  /** Création d'un block en distinguant classe, fonction ou autre block.
   * Pour être détectées, les fonctions autres que __construct() doivent avoir un type de retour
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   * @param TokenArray $tokens; tokens du fichier contenant le block
   */
  static function create(string $namespace, int $startTokenNr, TokenArray $tokens): PhpBlock {
    $verbose = $_GET['verbose'] ?? null;
    //die("Fin ligne ".__LINE__);
    if ($verbose == 'true') {
      echo "PhpBlock::create(namespace='$namespace', startTokenNr=$startTokenNr)<br>\n";
      echo "symbStr=",$tokens->symbStr($startTokenNr-1, -12),"<br>\n";
    }
    
    // class {nom_classe} {
    // class {nom_classe} extends {nom_classe_mère} {
    // class {nom_class} implements {interface_name} {
    // class {nom_class} extends {nom_classe_mère} implements {interface_name} {
    $pattern = '!^(T_WHITESPACE,)?'
        .'(T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,)?'   // implements {interface_name}
        .'((T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,T_WHITESPACE,)?' // extends {nom_classe_mère}
        .'T_STRING,T_WHITESPACE,T_CLASS!';                       // class {nom_classe}
    if (preg_match($pattern, $tokens->symbStr($startTokenNr-1, -12))) {
      if ($verbose)
        echo "Définition de classe détectée<br>\n";
      return new PhpClass($namespace, $startTokenNr, $tokens);
    }
    // symbStr={T_WHITESPACE}{T_STRING}?{T_WHITESPACE}
    elseif (preg_match('!^(T_WHITESPACE,)?(T_STRING,|T_ARRAY,)(\?,)?(T_WHITESPACE,)?:!', $tokens->symbStr($startTokenNr-1, -5))) {
      if ($verbose)
        echo "Function détectée avec type de retour<br>\n";
      return new PhpFunction($namespace, $startTokenNr, $tokens);
    }
    else {
      if (preg_match('!^(T_WHITESPACE,)?\)!', $tokens->symbStr($startTokenNr-1, -4))) {
        if ($verbose)
          echo "Function détectée sans type de retour<br>\n";
        $openBracketNr = $tokens->findSrcBackward($startTokenNr, '('); // recherche de la ( de début des paramètres
        //echo 'symbStrBracket=',$tokens->symbStr($openBracketNr, -12),"<br>\n";
        //echo "srcCode=",htmlentities($tokens->srcCode($openBracketNr-12, $openBracketNr, '')),"<br>\n";
        if ($tokens->symbStr($openBracketNr, -4) == '(,T_STRING,T_WHITESPACE,T_FUNCTION') {
          //echo "détection de function xxx(<br>\n";
          if ($tokens[$openBracketNr-1]->src == '__construct') {
            if ($verbose)
              echo "Détection de __construct()<br>\n";
            return new PhpFunction($namespace, $startTokenNr, $tokens);
          }
          else { // cas éventuel d'une fonction sans type de retour non reconnue
            if ($verbose)
              echo "fonction sans type de retour non reconnue<br>\n";
          }
        }
        elseif ($tokens->symbStr($openBracketNr, -5) == '(,T_WHITESPACE,T_STRING,T_WHITESPACE,T_FUNCTION') {
          //echo "détection de function xxx (<br>\n";
          if ($tokens[$openBracketNr-2]->src == '__construct') {
            if ($verbose)
              echo "Détection de __construct ()<br>\n";
            return new PhpFunction($namespace, $startTokenNr, $tokens);
          }
          else { // cas éventuel d'une fonction sans type de retour non reconnue
            if ($verbose)
              echo "fonction sans type de retour non reconnue<br>\n";
          }
        }
      }
      if ($verbose)
        echo "NI Class Ni Function détectée<br>\n";
      return new self($namespace, $startTokenNr, $tokens);
    }
  }
  
  /** Création d'un block de base.
   * Analyse l'existence de sous-blocks
   * @param int $startTokenNr, no du token de début du block correspondant à '{' 
   * @param TokenArray $tokens; liste des tokens du fichier contenant le block
   */
  function __construct(string $namespace, int $startTokenNr, TokenArray $tokens) {
    //echo "Appel PhpBlock::__construct(startTokenNr=$startTokenNr)<br>\n";
    $this->startTokenNr = $startTokenNr;
    $this->lineNr = $tokens[$startTokenNr]->lineNr;
    $subBlocks = [];
    for ($tnr=$startTokenNr+1; $tnr < count($tokens); $tnr++) {
      if ($tokens[$tnr]->src == '}') {
        $this->lastTokenNr = $tnr;
        $this->subBlocks = $subBlocks;
        return;
      }
      elseif ($tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $subBlock = PhpBlock::create($namespace, $tnr, $tokens);
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
  //readonly public string $title;

  /** Définition d'une classe ; gère les différents cas de figure */
  function __construct(string $namespace, int $startTokenNr, TokenArray $tokens) {
    $verbose = $_GET['verbose'] ?? null;
    parent::__construct($namespace, $startTokenNr, $tokens);

    if ($tokens[$startTokenNr-1]->id == T_WHITESPACE)
      $startTokenNr--;
    if ($tokens->symbStr($startTokenNr-1, -3) == 'T_STRING,T_WHITESPACE,T_CLASS') { // class {nom_classe} {
      if ($verbose)
        echo "class {nom_classe}<br>\n";
      $this->name = $namespace.$tokens[$startTokenNr-1]->src;
      $this->parentClassName = '';
      $this->interface = '';
      if ($verbose)
        echo "class $this->name<br>\n";
    }
    else {
      // class {nom_classe} extends {nom_classe_mère} {
      $symbStrPattern = '!^(T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,' // extends {nom_classe_mère}
          .'T_WHITESPACE,T_STRING,T_WHITESPACE,T_CLASS!'; // class {nom_classe} 
      //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
      if (preg_match($symbStrPattern, $tokens->symbStr($startTokenNr-1, -7))) {
        if ($verbose)
          echo "class {nom_classe} extends {nom_classe_mère}<br>\n";
        $this->name = $namespace.$tokens[$startTokenNr-5]->src;
        $this->parentClassName = $tokens[$startTokenNr-1]->src;
        $this->interface = '';
        if ($verbose)
          echo "class $this->name extends $this->parentClassName<br>\n";
      }
      else {
        // class {nom_class} implements {interface_name} {
        $symbStr = 'T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,T_STRING,T_WHITESPACE,T_CLASS';
        //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
        if ($tokens->symbStr($startTokenNr-1, -7) == $symbStr) {
          if ($verbose)
            echo "class {nom_classe} implements {interface_name}<br>\n";
          $this->name = $namespace.$tokens[$startTokenNr-5]->src;
          $this->parentClassName = '';
          $this->interface = $tokens[$startTokenNr-1]->src;
          if ($verbose)
            echo "class $this->name implements $this->interface<br>\n";
        }
        else {
          // class {nom_class} extends {nom_classe_mère} implements {interface_name} {
          $symbStrPattern = '!^'
                    .'T_STRING,T_WHITESPACE,T_IMPLEMENTS,T_WHITESPACE,' // implements {interface_name} {
                    .'(T_STRING|T_NAME_FULLY_QUALIFIED),T_WHITESPACE,T_EXTENDS,T_WHITESPACE,' // extends {nom_classe_mère}
                    .'T_STRING,T_WHITESPACE,T_CLASS!'; // class {nom_class}
          //echo "symbStr=",$tokens->symbStr($startTokenNr-1, -7),"<br>\n";
          if (preg_match($symbStrPattern, $tokens->symbStr($startTokenNr-1, -11))) {
            if ($verbose)
              echo "class {nom_classe} extends {nom_classe_mère} implements {interface_name}<br>\n";
            $this->name = $tokens[$startTokenNr-9]->src;
            $this->parentClassName = $tokens[$startTokenNr-5]->src;
            $this->interface = $tokens[$startTokenNr-1]->src;
            if ($verbose)
              echo "class $this->name extends $this->parentClassName implements $this->interface<br>\n";
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
  //readonly public string $title;
  
  function __construct(string $namespace, int $startTokenNr, TokenArray $tokens) {
    $verbose = $_GET['verbose'] ?? null;
    if ($verbose)
      echo "PhpFunction::__construct(startTokenNr=$startTokenNr)<br>\n";
    parent::__construct($namespace, $startTokenNr, $tokens);
    
    $nr = $tokens->findSrcBackward($startTokenNr, '('); // recherche de la parenthèse ouvrante dé début des paramètres
    $this->params = $tokens->srcCode($nr, $startTokenNr, '');
    $nr--;
    if ($tokens[$nr]->id == T_WHITESPACE)
      $nr--;
    // $nr pointe sur le premier token <> T_WHITESPACE avant la '('
    //echo 'symbStrBracket=',$tokens->symbStr($nr, -12),"<br>\n";
    if (in_array($tokens[$nr]->id, [T_STRING, T_EMPTY, T_NAMESPACE])) { // function ff(
      $this->name = $namespace.$tokens[$nr]->src;
      if ($verbose)
        echo "function $this->name<br>\n";
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

/** Fichier Php avec les blocs dont certains sont des définitions de classe ou fonction */
class DefiningFile extends PhpFile {
  /** @var PhpBlock[] $blocks liste des blocks contenus dans le fichier */
  readonly public array $blocks;
  
  /** parcourt tous les fichiers Php pour afficher les classes et les fonctions définies */
  static function chooseClassOrFunction(string $rpath=''): void {
    if (is_dir(parent::$root.$rpath)) {
      foreach (new DirectoryIterator(parent::$root.$rpath) as $entry) {
        if (in_array($entry, PhpFile::EXCLUDED)) continue;
        if (is_dir(parent::$root.$rpath."/$entry"))
          echo "<a href='?action=$_GET[action]&amp;rpath=$rpath/$entry'><b>$entry</b></a><br>\n";
        elseif (substr($entry, -4) == '.php')
          echo "<a href='?action=$_GET[action]&amp;rpath=$rpath/$entry'>$entry</a><br>\n";
      }
    }
    else {
      $file = new DefiningFile($rpath);
      $namespace = $file->namespace;
      foreach ($file->classes() as $className => $class) {
        echo "<a href='?action=$_GET[action]&amp;class=\\$namespace$className&amp;src=$rpath'>\\$namespace$className</a><br>\n";
      }
      foreach ($file->functions() as $funName => $fun) {
        echo "<a href='?action=$_GET[action]&amp;function=\\$namespace$funName&amp;src=$rpath'>\\$namespace$funName</a><br>\n";
      }
    }
  }
  
  function __construct(string $rpath, TokenArray $tokens=null) {
    if (!$tokens)
      $tokens = new TokenArray(parent::$root.$rpath);
    
    parent::__construct($rpath, $tokens);
    
    // construction des blocks
    $blocks = [];
    for ($tnr=0; $tnr < count($tokens); $tnr++) {
      if ($tokens[$tnr]->src == '}') {
        echo "} détectée dans PhpFile::__construct() au token $tnr<br>\n";
      }
      elseif ($tokens[$tnr]->src == '{') {
        //echo "{ détectée au token $tnr<br>\n";
        $block = PhpBlock::create($this->namespace, $tnr, $tokens);
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
    return array_merge(
      parent::asArray(),
      [
        'classes'=> array_map(function(PhpClass $class) { return $class->asArray(); }, $this->classes()),
        //'blocks'=> array_map(function(PhpBlock $block) { return $block->asArray(); }, $this->blocks),
      ]);
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
    ksort($classes);
    return $classes;
  }
  
  /** Récupère les fonctions dans les blocks
   * @return array<string,PhpFunction> */
  function functions(): array {
    $functions = [];
    foreach ($this->blocks as $block) {
      if (get_class($block) == 'PhpFunction') {
        $functions[$block->name] = $block;
      }
    }
    ksort($functions);
    return $functions;
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
