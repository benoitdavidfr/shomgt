<?php
/**
 * Fichier php avec l'utilisation des définitions de fonctions et de classes
 * @package phpanalyzer
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/token.inc.php';

use Symfony\Component\Yaml\Yaml;

/** classe abstraite des utilisations */
readonly abstract class PhpUse {
  /** Numéro du token de référence de l'appel
   * '(' pour un appel de fonction ou de méthode,
   * mot-clé extends pour une extension de classse, mot-clé new pour une création d'objet */
  public int $tokenNr;
  /** Numéro de ligne de l'appel */
  public int $lineNr;

  function __construct(int $tokenNr, int $lineNr) { $this->tokenNr = $tokenNr; $this->lineNr = $lineNr; }

  abstract function __toString(): string;

  /** Retourne un call comme un array
   * @return array<mixed> */
  abstract function asArray(): array;
  
  /** Retourne le nom qualifié de la classe utilisée ou '' si aucune
   * $namespace est l'espace de nom déclaré dans le fichier utilisant */
  abstract function usedClassName(string $namespace): string;
  
  /** Retourne le nom qualifié de la fonction utilisée ou '' si aucune
   * $namespace est l'espace de nom déclaré dans le fichier utilisant */
  abstract function usedFunctionName(string $namespace): string;

  /** fabrique un nom qualifié à partir du nom de classe ou fonction trouvé dans le fichier et du de l'espace de nom du fichier */
  static function qualifName(string $namespace, string $cfname): string {
    if (!$cfname) // pas de nom
      return '';
    elseif (substr($cfname, 0, 1)=='\\') // le nom est déjà qualifié
      return $cfname;
    else // le nom qualifié est la concaténation de l'espace de nom et du nom non qualifié
      return $namespace.$cfname;
  }
};

/** appel de fonction */
readonly class FunctionCall extends PhpUse {
  /** Nom de la fonction appelée */
  public string $name;
  
  function __construct(int $nr, int $lineNr, string $name) { parent::__construct($nr, $lineNr); $this->name = $name; }
  
  function __toString(): string { return "fun $this->name(), ligne $this->lineNr"; }
  
  function asArray(): array { return ['type'=> 'FunctionCall', 'name'=> $this->name]; }

  function usedClassName(string $namespace): string { return ''; }
  function usedFunctionName(string $namespace): string { return self::qualifName($namespace, $this->name); }
};

/** création d'un objet d'une classe */
readonly class NewCall extends PhpUse {
  /** nom de la classe de la méthode appelée si elle est connue */
  public string $class;
  
  function __construct(int $nr, int $lineNr, string $class) { parent::__construct($nr, $lineNr); $this->class = $class; }
  
  function __toString(): string { return "new $this->class, ligne $this->lineNr"; }
  
  function asArray(): array { return ['type'=> 'NewCall', 'class'=> $this->class]; }

  function usedClassName(string $namespace): string { return self::qualifName($namespace, $this->class); }
  function usedFunctionName(string $namespace): string { return ''; }
};

/** Appel d'une méthode statique d'une classe */
readonly class StaticMethodCall extends PhpUse {
  /** nom de la classe de la méthode appelée (toujours connue) */
  public string $class;
  /** Nom de la méthode appelée. */
  public string $name;
  
  function __construct(int $nr, int $lineNr, string $class, string $name) {
    parent::__construct($nr, $lineNr);
    $this->class = $class;
    $this->name = $name;
  }
  
  function __toString(): string { return "$this->class::$this->name(), ligne $this->lineNr"; }
  
  function asArray(): array {
    return ['type'=> 'StaticMethodCall', 'class'=> $this->class, 'name'=> $this->name];
  }

  function usedClassName(string $namespace): string { return self::qualifName($namespace, $this->class); }
  function usedFunctionName(string $namespace): string { return ''; }
};

/** Appel d'une méthode non statique d'une classe généralement inconnue */
readonly class NonStaticMethodCall extends StaticMethodCall {
  function __construct(int $nr, int $lineNr, string $name, string $class='') {
    parent::__construct($nr, $lineNr, $class, $name);
  }
  
  function __toString(): string { return "$this->class::$this->name(), ligne $this->lineNr"; }
  
  function asArray(): array {
    return ['type'=> 'NonStaticMethodCall', 'class'=> $this->class, 'name'=> $this->name, 'lineNr'=> $this->lineNr];
  }
};

/** Utilisation d'une classe par création d'une sous-classe */
readonly class PhpExtends extends PhpUse {
  /** @var string $extendedClass; la classe étendue */
  public string $extendedClass;
  /** @var string $defClass; la nouvelle classe définie */
  public string $defClass;
  
  function __construct(int $nr, int $lineNr, string $extendedClass, string $defClass) {
    parent::__construct($nr, $lineNr);
    $this->extendedClass = $extendedClass;
    $this->defClass = $defClass;
  }
  
  function __toString(): string { return "$this->defClass extends $this->extendedClass, ligne $this->lineNr"; }
  
  function asArray(): array {
    return ['type'=> 'Extends', 'extendedClass'=> $this->extendedClass, 'defClass'=> $this->defClass, 'lineNr'=> $this->lineNr];
  }

  function usedClassName(string $namespace): string { return self::qualifName($namespace, $this->extendedClass); }
  function usedFunctionName(string $namespace): string { return ''; }
};

/** Fichier Php avec les utilisations de fonctions et classes */
class UsingFile extends PhpFile {
  /** @var array<int,PhpUse> $uses; liste des utilisations détectées dans le fichier */
  readonly array $uses;
  
  /** Affiche les appels à la classe ou la fonction $name */
  static function usingClassOrFunction(string $type, string $name, string $rpath=''): void {
    //echo "UsingFile::usingClassOrFunction(type=$type, name=$name, rpath=$rpath)<br>\n";
    if (is_dir(parent::$root.$rpath)) {
      foreach (new DirectoryIterator(parent::$root.$rpath) as $entry) {
        if (in_array($entry, PhpFile::EXCLUDED)) continue;
        //echo "entry $entry<br>\n";
        self::usingClassOrFunction($type, $name, "$rpath/$entry");
      }
    }
    elseif (substr($rpath, -4)=='.php') {
      //echo "rpath=$rpath<br>\n";
      $file = new UsingFile($rpath);
      $namespace = '\\'.$file->namespace;
      //echo "namespace=$namespace<br>\n";
      foreach ($file->uses as $use) {
        if ($type == 'class') {
          if ($use->usedClassName($namespace) == $name)
            echo "$rpath: <b>$use</b><br>\n";
        }
        elseif ($type == 'function') {
          /*if ($use->usedFunctionName($namespace))
            echo $use->usedFunctionName($namespace)," == $name ?<br>\n";*/
          if ($use->usedFunctionName($namespace) == $name)
            echo "$rpath: <b>$use</b><br>\n";
        }
      }
    }
  }

  /** Création des utilisations de fonctions et classes à partir d'un fichier Php */
  function __construct(string $rpath, TokenArray $tokens=null) {
    //echo "<b>UsingFile::__construct()</b><br>\n";
    if (!$tokens)
      $tokens = new TokenArray(parent::$root.$rpath);
    
    parent::__construct($rpath, $tokens);

    $uses = [];
    for ($nr=0; $nr < count($tokens); $nr++) {
      if ($tokens[$nr]->id == T_EXTENDS) { // Détection de la cération d'une sous-classe
        //echo "extends détecté<br>\n";
        $symbstr = $tokens->symbstr($nr-3, 7);
        //echo "symstr=",$symbstr,"<br>\n";
        $uses[] = new PhpExtends($nr, $tokens[$nr]->lineNr, $tokens[$nr+2]->src, $tokens[$nr-2]->src);
      }
      elseif ($tokens[$nr]->id == T_NEW) { // Détection d'un appel de new
        $uses[] = new NewCall($nr, $tokens[$nr]->lineNr, $tokens[$nr+2]->src);
      }
      elseif ($tokens[$nr]->src == '(') { /// détection d'un appel de fonction ou méthode
        $lineNr = $tokens[$nr]->lineNr;
        // $nr pointe sur une '('
        $nr2 = $nr;
        if ($tokens[$nr-1]->id == T_WHITESPACE) {
          $nr2--; // si la '(' est précédée d'un T_WHITESPACE alors $nr2 pointe sur ce T_WHITESPACE
        }
        $symbstr = $tokens->symbstr($nr2-1, -3); // tokens avant la ( et l'éventuel blanc
       // Détection d'un appel de méthode statique
        if (preg_match('!^T_STRING,T_DOUBLE_COLON,(T_STRING|T_NAME_FULLY_QUALIFIED)!', $symbstr)) {
          $uses[] = new StaticMethodCall($nr, $lineNr, $tokens[$nr2-3]->src, $tokens[$nr2-1]->src);
        }
        // Détection d'un appel de fonction
        elseif (preg_match(
            '!^(T_STRING|T_NAME_FULLY_QUALIFIED),(T_WHITESPACE,)?'
            .'(;|}|{|\(|=|T_IS_GREATER_OR_EQUAL|T_IS_EQUAL|>|<|T_RETURN|,|T_DOUBLE_ARROW|\.)!', $symbstr)) {
          $funName = $tokens[$nr2-1]->src;
          $uses[] = new FunctionCall($nr, $lineNr, $tokens[$nr2-1]->src);
        }
        // Appel de méthode non statique
        elseif (preg_match('!^T_STRING,T_OBJECT_OPERATOR!', $symbstr)) {
          $uses[] = new NonStaticMethodCall($nr, $lineNr, $tokens[$nr2-1]->src);
        }
      }
    }
    $this->uses = $uses;
  }

  function asArray(): array {
    return array_map(function(PhpUse $use): array { return $use->asArray(); }, $this->uses);
  }
};
