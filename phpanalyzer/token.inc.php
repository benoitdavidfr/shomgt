<?php
/**
 * Gestion des tokens d'un fichier Php pour phpanalyzer
 */
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

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

