<?php
/**
 * Fichier php avec ses appels Php
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/token.inc.php';

use Symfony\Component\Yaml\Yaml;

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

  /** Construit et retourne l'arbre des répertoires et fichiers
   *
   * Structure récursive de l'arbre:
   * TREE ::= PhpFile # pour un fichier
   *        | {{rpath} => TREE} # pour un répertoire
   *
   * Peut être appelé avec un nom de sous-classe de PhpFile pour construire un arbre d'objet de cette sous-classe.
   * @return array<string, mixed>|PhpFile *
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
  */
  
  function __construct(int $tokenNr, int $lineNr) { $this->tokenNr = $tokenNr; $this->lineNr = $lineNr; }

  /** Retourne un call comme un array
   * @return array<mixed> */
  abstract function asArray(): array;
};

/** appel de fonction */
readonly class FunctionCall extends Call {
  /** Nom de la fonction appelée */
  public string $name;
  
  function __construct(int $nr, int $lineNr, string $name) { parent::__construct($nr, $lineNr); $this->name = $name; }
  
  function asArray(): array {
    return ['type'=> 'FunctionCall', 'name'=> $this->name];
  }
};

/** appel de création d'un objet d'une classe */
readonly class NewCall extends Call {
  /** nom de la classe de la méthode appelée si elle est connue */
  public string $class;
  
  function __construct(int $nr, int $lineNr, string $class) { parent::__construct($nr, $lineNr); $this->class = $class; }
  
  function asArray(): array {
    return ['type'=> 'NewCall', 'class'=> $this->class];
  }
};

/** Appel d'une méthode statique d'une classe */
readonly class StaticMethodCall extends Call {
  /** nom de la classe de la méthode appelée (toujours connue) */
  public string $class;
  /** Nom de la méthode appelée. */
  public string $name;
  
  function __construct(int $nr, int $lineNr, string $class, string $name) {
    parent::__construct($nr, $lineNr);
    $this->class = $class;
    $this->name = $name;
  }
  
  function asArray(): array {
    return ['type'=> 'StaticMethodCall', 'class'=> $this->class, 'name'=> $this->name];
  }
};

/** Appel d'une méthode non statique d'une classe, cette classe est souvent inconnue */
readonly class NonStaticMethodCall extends StaticMethodCall {
  function __construct(int $nr, int $lineNr, string $name, string $class='') {
    parent::__construct($nr, $lineNr, $class, $name);
  }
  
  function asArray(): array {
    return ['type'=> 'NonStaticMethodCall', 'class'=> $this->class, 'name'=> $this->name, 'lineNr'=> $this->lineNr];
  }
};

/** Fichier Php avec ses caractéristiques d'appels à des fonctions et méthodes */
class CallingFile extends PhpFile {
  /** @var array<string,Call> $calls; liste des appels du fichier */
  readonly array $calls;
  
  /** Création des appels de fonctions et méthodes à partir d'un fichier Php
   * @return list<Call> */
  function __construct(string $rpath, TokenArray $tokens=null) {
    //echo "<b>Call::create()</b><br>\n";
    if (!$tokens)
      $tokens = new TokenArray(parent::$root.$rpath);
    
    parent::__construct($rpath, $tokens);

    $calls = [];
    for ($nr=0; $nr < count($tokens); $nr++) {
      if ($tokens[$nr]->src == '(') {
        $lineNr = $tokens[$nr]->lineNr;
        // $nr pointe sur une '('
        $nr2 = $nr;
        if ($tokens[$nr-1]->id == T_WHITESPACE) {
          $nr2--; // si la '(' est précédée d'un T_WHITESPACE alors $nr2 pointe sur ce T_WHITESPACE
        }
        $symbstr = $tokens->symbstr($nr2-1, -3); // tokens avant la ( et l'éventuel blanc
       // Détection d'un appel de méthode statique
        if (preg_match('!^T_STRING,T_DOUBLE_COLON,(T_STRING|T_NAME_FULLY_QUALIFIED)!', $symbstr)) {
          $calls[] = new StaticMethodCall($nr, $lineNr, $tokens[$nr2-3]->src, $tokens[$nr2-1]->src);
        }
        // Détection d'un appel de fonction
        elseif (preg_match(
            '!^(T_STRING|T_NAME_FULLY_QUALIFIED),(T_WHITESPACE,)?'
            .'(;|}|{|\(|=|T_IS_GREATER_OR_EQUAL|T_IS_EQUAL|>|<|T_RETURN|,|T_DOUBLE_ARROW|\.)!', $symbstr)) {
          $funName = $tokens[$nr2-1]->src;
          $calls[] = new FunctionCall($nr, $lineNr, $tokens[$nr2-1]->src);
        }
        // Appel de méthode non statique
        elseif (preg_match('!^T_STRING,T_OBJECT_OPERATOR!', $symbstr)) {
          $calls[] = new NonStaticMethodCall($nr, $lineNr, $tokens[$nr2-1]->src);
        }
        // Détection d'un appel de new
        elseif (preg_match('!^(T_STRING|T_NAME_FULLY_QUALIFIED|T_VARIABLE),T_WHITESPACE,T_NEW!', $symbstr)) {
          $className = $tokens[$nr2-1]->src;
          $calls[] = new NewCall($nr, $lineNr, $tokens[$nr2-1]->src);
        }
      }
    }
    $this->calls = $calls;
  }

  function asArray(): array {
    return array_map(function(Call $call): array { return $call->asArray(); }, $this->calls);
  }
};