<?php
/**
 * validation de la conformité d'une instance Php à un schéma JSON
 *
 * Pour valider la conformité d'un fichier JSON ou Yaml à un schéma, utiliser la méthode statique
 *   JsonSchema::autoCheck() qui prend en paramètre une instance et retourne un Status
 * Une autre possibilité pour valider la conformité d'une instance définie comme valeur Php à un schéma, il faut:
 *   - créer un objet JsonSchema en fournissant le contenu du schema sous la forme d'un array Php
 *   - appeler sur cet objet la méthode check avec l'instance Php à vérifier
 *   - analyser le statut retourné (classe Status) par cette vérification
 * voir https://json-schema.org/understanding-json-schema/reference/index.html (draft-06)
 * La classe est utilisée avec des valeurs Php
 * 3 types d'erreurs sont gérées:
 *   - une structuration non prévue de schéma génère une exception
 *   - une non conformité d'une instance à un schéma fait échouer la vérification
 *   - une alerte peut être produite dans certains cas sans faire échouer la vérification
 * Lorsque le schéma est conforme au méta-schéma, la génération d'une exception correspond à un bug du code.
 * Ce validateur implémente la spec http://json-schema.org/draft-06/schema# en totalité.
 *
 * journal:
 * - 3/9/2023:
 *   - reformattage de la doc en PHPDoc
 *   - définition d'un espace de noms
 *   - suppression de is_assoc_array() replacé par !array_is_list()
 * - 22/1/2021:
 *   - passage à Php 8
 *   - redéfinition de la logique de tests élémentaires
 *   - JsonSchema::autocheck() transmet le répertoire du doc au schéma
 * - 5/11/2020:
 *   - ajout constructeur sur Status pour permettre une initialisation non vide
 * - 3/4/2020:
 *   - modification du mécanisme de registre de schéma
 *   - Les URI en http://id.georef.eu/ et http://docs.georef.eu/ ne sont pas réécrites dans Lib::predef()
 *     mais traitées par Lib::deref() en faisant appel à getFragmentFromPath() définie dans YamlDoc
 * - 24/2/2019:
 *   - modification des règles de réécriture dans Lib::predef()
 * - 5-8/2/2019:
 *   - ajout de la possibilité dans Lib::file_get_contents() d'exécuter un script Php renvoyant un array Php
 * - 26/1/2019:
 *   - modification dans JsonSchema::autoCheck() du cas ou on ajoute .schema.yaml au nom du fichier du schéma
 * - 24/1/2019:
 *   - utilisation du mot-clé $schema à la place de jSchema
 * - 23/1/2019:
 *   - correction d'un bug lors de l'ouverture d'un schéma dont le chemin est défini en relatif
 *   - amélioration de l'erreur lors qu'un élément d'un schéma n'est pas défini
 * - 19/1/2019:
 *   - scission du fichier jsonschema.inc.php en jsonschema.inc.php et jsonschelt.inc.php
 *   - ajout de Lib::deref() pour déréférencer un pointeur JSON
 *   - permet d'utiliser les URL http://{name}.georef.eu/ pour des docs autres que des schémas
 *   - ajout de la possibilité de définir des options d'affichage dans JsonSchema::check()
 *   - ajout de JsonSchema::autoCheck() pour vérifier qu'une instance est conforme au schéma défini par le champ jSchema
 * - 18/1/2019:
 *   - Ajout fonctionnalité d'utilisation de schémas prédéfinis
 * - 16-17/1/2019:
 *   - Correction d'un bug sur items
 *   - Modif de la logique de vérification pour ne pas traiter les ensembles de types comme des anyOf()
 *   - Traitement de AdditionalItems comme schema
 * - 15/1/2019:
 *   - Correction d'un bug sur PropertyNames
 * - 11-14/1/2018:
 *   - Renforcement des tests et correction de bugs
 *   - ajout additionalProperties, propertyNames, minProperties, maxProperties, minItems, maxItems, contains, uniqueItems
 *     minLength, maxLength, pattern, Generic Enumerated misc values, Generic const misc values, multiple, dependencies
 *     allOf, oneOf, not, Tuple validation, Tuple validation w additional items, format
 *   - Publication sur Gihub
 * - 9-10/1/2018:
 *   - Réécriture complète en 3 classes: schéma JSON, élément d'un schéma et statut d'une vérification
 *   - Correction d'un bug dans la vérification d'un schéma par le méta-schéma
 * - 8/1/2018:
 *   - BUG trouvé: lorsqu'un schéma référence un autre schéma dans le même répertoire,
 *   - le répertoire de référence doit être le répertoire courant du schéma
 * - 7/1/2019:
 *   - BUG trouvé dans l'utilisation d'une définition dans un oneOf,
 *   - oneOf coupe le lien vers le schema racine pour éviter d'enregistrer les erreurs
 *     alors que les référence vers une définition a besoin de ce lien
 *     voir http://localhost/schema/?action=check&file=ex/route500
 *   - début de correction
 *     - quand on a une hiérarchie de schéma, dans lequel chercher une définition ?
 *   - a priori je prenais la racine mais ce n'est pas toujours le cas
 *   - solution: distinguer les vrai schémas des pseudo-schémas qui sont des parties d'un schéma
 * - 3/1/2019
 *   - les fonctions complémentaires ne sont définies que si elles ne le sont pas déjà
 *   - correction bug
 * - 2/1/2019
 *   - ajout oneOf
 *   - correction du test d'une propriété requise qui prend la valeur nulle
 *   - correction de divers bugs détectés par les tests sur des exemples de GeoJSON
 *   - assouplissement de la détection dans $ref au premier niveau d'un schema
 *   - ajout d'un mécanisme de tests unitaires
 *   - ajout patternProperties et test sur http://localhost/yamldoc/?doc=dublincoreyd&ypath=%2Ftables%2Fdcmes%2Fdata
 * - 1/1/2019
 *   - première version
 */
namespace jsonschema;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/jsonschfrg.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Statut de vérification de conformité d'une instance à un schéma
 *
 * Un objet de cette classe est retourné par une vérification et contiendra les erreurs et alertes fournies.
 *
 * Les erreurs sont structurées dans un arbre correspondant aux oneOf rencontrés lors de la vérification.
 * Chaque noeud de l'arbre contient une liste de chaines et de sous-arbre, chacun indexé par une chaine.
 * Exemple: http://localhost/schema/?schema=geojson%2Fgeometry.schema.yaml&instance=type%3A+LineString%0D%0Acoordinates%3A+%5B1%2C+2%5D&action=fchoice
*/
class Status {
  /** @var array<int, string> $warnings */
  private array $warnings; // liste des warnings
  /** @var array<int, string|array<string, mixed>> $errors */
  private array $errors; // erreurs dans l'instance [string|{label: string, children: [errors]}]
  
  /**
   * @param array<int, string|array<string, mixed>> $errors
   * @param array<int, string> $warnings
   */
  function __construct(array $errors=[], array $warnings=[]) {
    $this->errors = $errors;
    $this->warnings = $warnings;
  }
  
  function __toString(): string {
    return Yaml::dump([['Errors'=>$this->errors, 'Warnings'=> $this->warnings], 999]);
  }
  
  /** ajoute une erreur, retourne $this */
  function setError(string $message): self { $this->errors[] = $message; return $this; }
  
  /** ajoute une branche d'erreurs, retourne $this
   * @param array<int, mixed> $statusArray */
  function setErrorBranch(string $message, array $statusArray): self {
    $children = [];
    foreach ($statusArray as $status2)
      $children[] = $status2->errors;
    $this->errors[] = ['label'=> $message, 'children'=> $children];
    return $this;
  }
  
  /** retourne true ssi pas d'erreur */
  function ok(): bool { return count($this->errors)==0; }
  
  /** retourne les erreurs
   *
   * @return list<string|array<string, mixed>>
   */
  function errors(): array { return $this->errors; }
  
  /** affiche les erreurs */
  function showErrors(string $message=''): void {
    if ($this->errors)
      echo $message,'<pre><b>',Yaml::dump(['Errors'=>$this->errors], 999),"</b></pre>\n";
  }
  
  /** ajoute un warning */
  function setWarning(string $message): void { $this->warnings[] = $message; }
  
  /**
   * retourne les alertes
   *
   * @return list<string>
   */
  function warnings(): array { return $this->warnings; }
  
  /** affiche les warnings */
  function showWarnings(string $message=''): void {
    echo $message;
    if ($this->warnings)
      echo '<pre><i>',Yaml::dump(['Warnings'=> $this->warnings], 999),"</i></pre>";
  }
  
  /** ajoute à la fin du statut courant le statut en paramètre et renvoie le statut courant */
  function append(Status $toAppend): Status {
    $this->warnings = array_merge($this->warnings, $toAppend->warnings);
    $this->errors = array_merge($this->errors, $toAppend->errors);
    return $this;
  }
};

/**
 * classe statique portant qqs méthodes statiques */
class Lib {
  /** @var array<string, array<string, mixed>> $predefs */
  static array $predefs=[]; // dictionnaire [ {predef} => {local} ] utilisé par self::predef()
  /** @var array<string, array<string, mixed>> $patterns */
  static array $patterns=[]; // dictionnaire [ {pattern} => {local} ] utilisé par self::predef()
  
  // remplace les chemins prédéfinis par leur équivalent local
  // utilise le fichier predef.yaml chargé dans self::$predefs et self::$patterns
  // si aucun remplacement, renvoie le path initial
  static function predef(string $path): string {
    //echo "predef(path=$path)<br>\n";
    if (!self::$predefs) {
      if (($txt = @file_get_contents(__DIR__.'/jsonschpredef.yaml')) === false)
        throw  new \Exception("ouverture impossible du fichier jsonschpredef.yaml");
      try {
        $yaml = Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (\Exception $e) {
        throw  new \Exception("Analyse Yaml du fichier jsonschpredef.yaml incorrect: ".$e->getMessage());
      }
      foreach ($yaml['predefs'] as $id => $predef) {
        self::$predefs[$id] = $predef['localPath'];
        if (isset($yaml['aliases']))
          foreach ($yaml['aliases'] as $alias)
            self::$predefs[$alias] = $predef['localPath'];
      }
      self::$patterns = $yaml['patterns'];
    }
    //echo (isset(self::$predefs[$path]) ? "remplacé par: ".__DIR__.'/'.self::$predefs[$path] : "absent"),"<br>\n";
    if (isset(self::$predefs[$path]))
      return __DIR__.'/'.self::$predefs[$path];
    foreach (self::$patterns as $pattern => $replacement) {
      if (preg_match("!$pattern!", $path, $matches)) {
        $path2 = preg_replace("!$pattern!", $replacement['localPath'], $path, 1);
        //echo "remplacé par: ".__DIR__.$path2,"<br>\n";
        if (is_file(__DIR__.$path2))
          return __DIR__.$path2;
        elseif (substr($path2, -5)=='.yaml') {
          $path3 = substr($path2, 0, strlen($path2)-5).'.php';
          if (is_file(__DIR__.$path3))
            return __DIR__.$path3;
        }
        throw  new \Exception("neither $path2 nor $path3 is a file"); // @phpstan-ignore-line
      }
    }
    return $path;
  }
  
  // remplacement d'un objet { '$ref'=> {path} } pour fournir le contenu référencé ou une exception
  // le {path} ne doit pas être uniquement un #...
  // les chemins prédéfinis sont remplacés
  static function deref(mixed $def): mixed {
    if (!is_array($def) || !isset($def['$ref']))
      return $def;
    $path = self::predef($def['$ref']);
    //echo "path après predef: $path<br>\n";
    if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)(#(.*))?$!', $path, $matches))
      throw  new \Exception("Chemin $path non compris dans Lib::deref()");
    $filepath = $matches[1]; // partie avant #
    $eltpath = isset($matches[4]) ? $matches[4] : ''; // partie après #
    $doc = self::file_get_contents($filepath);
    return self::subElement($doc, $eltpath);
  }
  
  // sélection d'un élément de l'array $content défini par le path $path
  // ERREUR je ne tiens pas compte des échappements définis dans https://tools.ietf.org/html/rfc6901
  /** @param array<mixed> $content */
  static function subElement(array $content, string $path): mixed {
    if (!$path)
      return $content;
    if (!preg_match('!^/([^/]+)(/.*)?$!', $path, $matches))
      throw  new \Exception("Erreur path '$path' mal formé dans subElement()");
    $first = $matches[1];
    $path = isset($matches[2]) ? $matches[2] : '';
    if (!isset($content[$first]))
      return null;
    elseif (!$path)
      return $content[$first];
    else
      return self::subElement($content[$first], $path);
  }
  /** @param array<mixed> $content */
  static function subElementC(array $content, string $path): mixed { // appel de subObject() commenté 
    echo "subElement(content=",json_encode($content),", path=$path)<br>\n";
    $result = self::subElement($content, $path);
    echo "returns: ",json_encode($result),"<br>\n";
    return $result;
  }
 
  static function test_subElement(): never {
    echo "Test subElement<br>\n";
    $object = ['a'=>'a', 'b'=> ['c'=> 'bc', 'd'=> ['e'=> 'bde']]];
    Lib::subElementC($object, '/b');
    Lib::subElementC($object, '/b/d');
    Lib::subElementC($object, '/x');
    Lib::subElementC($object, '/a');
    die("FIN test subElement<br><br>\n");
  }
  
  // récupère le contenu d'un fichier JSON ou Yaml ou exécute un fichier Php renvoyant un array Php
  // retourne un array Php ou en cas d'erreur génère une exception
  /** @return array<mixed> */
  static function file_get_contents(string $path): array {
    //echo "Lib::file_get_contents(path=$path)<br>\n";
    /*if (preg_match('!^http://(id|docs).georef.eu/!', $path))
      return getFragmentFromUri($path); // ???? */
    if (($txt = @file_get_contents($path)) === false)
      throw  new \Exception("ouverture impossible du fichier $path");
    if ((substr($path, -5)=='.yaml') || (substr($path, -4)=='.yml')) {
      try {
        return Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (\Exception $e) {
        throw  new \Exception("Analyse Yaml du fichier $path incorrect: ".$e->getMessage());
      }
    }
    elseif (substr($path, -5)=='.json') {
      if (($doc = json_decode($txt, true)) === null)
        throw  new \Exception("Décodage JSON du fichier $path incorrect: ".json_last_error_msg());
      return $doc;
    }
    elseif (substr($path, -4)=='.php') {
      // Le script Php doit renvoyer un array Php
      return require $path;
    }
    else {
      try {
        return Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (\Exception $e) {
        if (($doc = json_decode($txt, true)) !== null)
          return $doc;
        throw  new \Exception(
            "Décodage Yaml+JSON du fichier $path incorrect: ".$e->getMessage().'+'.json_last_error_msg());
      }
    }
  }
  
  // encode en JSON une valeur avec par défaut les options JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
  static function encode(mixed $val, int $options=0): string {
    $options |= JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    return json_encode($val, $options);
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Lib::subElement  
  if (!isset($_GET['test']))
    echo "<a href='?test=Lib::subElement'>Test de Lib::subElement()</a><br>\n";
  elseif ($_GET['test']=='Lib::subElement')
    Lib::test_subElement();
}

/**
 * schéma JSON initialisé soit par un chemin dans un fichier JSON/Yaml, soit par un array Php
 */
class JsonSchema {
  const SCHEMAIDS = [ // liste des id acceptés pour le champ $schema
    'http://json-schema.org/schema#',
    'http://json-schema.org/draft-06/schema#',
    'http://json-schema.org/draft-07/schema#',
  ];
  private bool $verbose; // @phpstan-ignore-line // true pour afficher des commentaires
  private ?string $filepath; // chemin du fichier contenant le schéma éventuellement null si inconnu
  /** @var bool|array<mixed> $def */
  private array|bool $def; // contenu du schéma comme array Php ou boolean
  private ?Fragment $elt; // objet Fragment correspondant au schéma ou null si $def est booléen
  private Status $status; // objet Status contenant le statut issu de la création du schéma

  /**
   * création d'un JsonSchema
   *
   * Un objet JsonSchema peut être initialisé par un fragment JSON/Yaml stocké dans un fichier et identifié par un chemin
   * de la forme {filePath}(#{eltPath})? où:
   *   - {filePath} identifie un fichier et peut être utilisé dans file_get_contents()
   *   - {eltPath} est le chemin d'un élément de la forme (/{elt})+ à l'intérieur du fichier
   * Le fichier est soit un fichier json dont l'extension doit être .json,
   * soit un fichier yaml dont l'extension doit être .yaml ou .yml.
   * Un JsonSchema peut aussi être défini par un array, dans ce cas si le champ $id n'est pas défini alors les chemins
   * de fichier utilisés dans les références vers d'autres schémas ne doivent pas être définis en relatif
   *
   * @param bool|string|array<mixed> $def chemin d'un fichier contenant le JSON/Yaml, ou contenu comme array Php ou comme booléen
   * @param bool $verbose=false indique éventuellement si l'analyse doit être commentée
   * @param ?JsonSchema $parent=null contient éventuellement le schema père et n'est utilisé qu'en interne à la classe
   */
  function __construct(bool|string|array $def, bool $verbose=false, ?JsonSchema $parent=null) {
    //echo "JsonSchema::__construct($def)<br>\n";
    $def0 = $def;
    $this->verbose = $verbose;
    if ($verbose)
      echo "JsonSchema::_construct(def=",json_encode($def),",",
           " parent->filepath=",$parent ? $parent->filepath : 'none',")<br>\n";
    $this->status = new Status;
    if (is_string($def)) { // le paramètre $def est le chemin du fichier contenant l'objet JSON
      $def = Lib::predef($def); // remplacement des chemins prédéfinis par leur équivalent local
      if (!preg_match('!^((https?://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def, $matches))
        throw  new \Exception("Chemin $def non compris dans JsonSchema::__construct()");
      $filepath = $matches[1]; // partie avant #
      $eltpath = $matches[4] ?? ''; // partie après #
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      if ((substr($filepath, 0, 7)=='http://') || (substr($filepath, 0, 8)=='https://')
          || (substr($filepath, 0, 1)=='/')) { // si chemin défini en absolu
        //echo "chemin défini en absolu<br>\n";
        $def = Lib::file_get_contents($filepath);
        $this->filepath = $filepath;
      }
      else { // cas où le chemin du fichier est défini en relatif, utiliser alors le répertoire du schéma parent
        //echo "chemin défini en relatif<br>\n";
        if (!$parent)
          throw  new \Exception("Ouverture de $filepath impossible sans schema parent");
        if (!($pfilepath = $parent->filepath))
          throw  new \Exception("Ouverture de $filepath impossible car le filepath du schema parent n'est pas défini");
        $pfiledir = dirname($pfilepath);
        $this->filepath = "$pfiledir/$filepath";
        $def = Lib::file_get_contents($this->filepath);
      }
      $eltDef = $eltpath ? Lib::subElement($def, $eltpath) : $def; // la définition de l'élément
      if (!$eltDef)
        throw  new \Exception("Ouverture de $def0 impossible car le chemin $eltpath n'existe pas dans le schéma");
    }
    elseif (is_array($def)) { // le premier paramètre est le contenu comme array Php
      $this->filepath = null;
      $eltDef = $def;
      if (isset($def['$id']) && preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def['$id'], $matches))
        $this->filepath = $matches[1];
      elseif (!isset($def['$ref']))
        $this->status->setWarning("Attention le schema ne comporte pas d'identifiant \$id");
    }
    elseif (is_bool($def)) {
      $this->filepath = null;
      $this->def = $def;
      $this->elt = null;
      return;
    }
    else
      throw  new \Exception("Erreur paramètre def incorrect dans la création d'un schéma");
    $this->def = $def;
    
    /*Je ne comprends pas
    if (!isset($def['$ref']) &&
        (!isset($def['$schema']) || !in_array($def['$schema'], self::SCHEMAIDS))) {
      echo '<pre>$def='; print_r($def);
      $this->status->setWarning("Attention le schema ne comporte aucun des id. json-schema.org, draft-06 ou draft-07");
    }*/
    if (isset($def['definitions'])) {
      foreach (array_keys($def['definitions']) as $defid)
        self::checkDefinition($defid, $def['definitions']);
    }
    $this->elt = new Fragment($eltDef, $this, $verbose);
  }
  
  /** définition du contenu du schéma sous la forme d'un array Php ou un boolean
   * @return bool|array<mixed> */
  function def(): bool|array { return $this->def; }
 
  /**
   * lance une exception si détecte une boucle dans les défs ou une référence à une déf. inexistante
   *
   * @param string $defid
   * @param array<string, mixed> $defs
   * @param array<int, string> $defPath
   */
  private static function checkDefinition(string $defid, array $defs, array $defPath=[]): void {
    //echo "checkLoopInDefinition(defid=$defid, defs=",json_encode($defs),")<br>\n";
    if (in_array($defid, $defPath))
      throw  new \Exception("boucle (".implode(', ', $defPath).", $defid) détectée");
    if (!isset($defs[$defid]))
      throw  new \Exception("Erreur, définition $defid inconnue");
    $def = $defs[$defid];
    ///echo "def="; print_r($def); echo "<br>\n";
    if (array_keys($def)[0]=='anyOf') {
      //echo "anyOf<br>\n";
      foreach ($def['anyOf'] as $childDef) {
        if (array_keys($childDef)[0]=='$ref') {
          //echo "childDef ref<br>\n";
          $ref = $childDef['$ref'];
          //echo "ref = $ref<br>\n";
          if (!preg_match('!^#/definitions/(.*)$!', $ref, $matches))
            throw  new \Exception("Référence '$ref' non comprise");
          $defid2 = $matches[1];
          self::checkDefinition($defid2, $defs, array_merge($defPath, [$defid]));
        }
      }
    }
  }
  
  /**
   * check - vérification de la conformité d'une instance au JsonSchema, renvoit un Status
   *
   * Un check() prend un statut initial et le modifie pour le renvoyer à la fin
   *  - le premier paramètre est l'instance à valider comme valeur Php
   *  - le second paramètre indique éventuellement l'affichage à effectuer en fonction du résultat de la validation
   *    c'est un array qui peut comprendre les champs suivants:
   *     - showOk : chaine à afficher si ok
   *     - showWarnings : chaine à afficher si ok avec les Warnings
   *     - showKo : chaine à afficher si KO
   *     - showErrors : chaine à afficher si KO avec les erreurs
   *  - le troisième paramètre indique éventuellement un chemin utilisé dans les messages d'erreurs
   *  - le quatrième paramètre fournit éventuellement un statut en entrée et n'est utilisé qu'en interne à la classe
   *
   * @param array<string,string> $options
  */
  function check(mixed $instance, array $options=[], string $id='', ?Status $status=null): Status {
    // au check initial, je clone le statut initial du schéma car je ne veux pas partager le statut entre check
    if (!$status)
      $status = clone $this->status;
    else
      $status->append($this->status);
    // cas particuliers des schémas booléens
    if (is_bool($this->def)) {
      if (!$this->def)
        $status->setError("Schema faux pour $id");
    }
    else
      $status = $this->elt->check($instance, $id, $status);
    // affichage éventuel du résultat en fonction des options
    if ($status->ok()) {
      if (isset($options['showOk']))
        echo $options['showOk'];
      if (isset($options['showWarnings']))
        $status->showWarnings($options['showWarnings']);
    }
    else {
      if (isset($options['showKo']))
        echo $options['showKo'];
      if (isset($options['showErrors']))
        $status->showErrors($options['showErrors']);
    }
    return $status;
  }
  
  /**
   * autoCheck() - valide la conformité d'une instance à son schéma défini par le champ $schema
   *
   * autoCheck() évalue la conformité d'une instance à son schéma défini par le champ $schema
   * autoCheck() prend un ou 2 paramètres
   *   - le premier paramètre est l'instance à valider soit comme valeur Php, soit le chemin du fichier la contenant
   *  - le second paramètre d'options indique éventuellement notamment l'affichage à effectuer en fonction du résultat
   *    de la validation ; c'est un array qui peut comprendre les champs suivants:
   *     - showOk : chaine à afficher si ok
   *     - showWarnings : chaine à afficher si ok avec les Warnings
   *     - showKo : chaine à afficher si KO
   *     - showErrors : chaine à afficher si KO avec les erreurs
   *     - verbose : défini et vrai pour un appel verbeux, non défini ou faux pour un appel non verbeux
   * autoCheck() renvoit un Status
   *
   * @param array<string,string|bool> $options
  */
  static function autoCheck(mixed $instance, array $options=[]): Status {
    $verbose = $options['verbose'] ?? false;
    if ($verbose)
      echo "JsonSchema::autoCheck(instance=",json_encode($instance),",options=",json_encode($options),")<br>\n";
    
    if (is_string($instance)) { // le premier paramètre est le chemin du fichier contenant l'instance
      $instance = Lib::predef($instance); // remplacement des chemins prédéfinis par leur équivalent local
      if (!preg_match('!^([^#]+)(#(.*))?$!', $instance, $matches))
        throw  new \Exception("Chemin $instance non compris dans JsonSchema::autoCheck()");
      $filepath = $matches[1]; // partie avant #
      $eltpath = isset($matches[3]) ? $matches[3] : ''; // partie après #
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      $def = Lib::file_get_contents($filepath);
      $instance = $eltpath ? Lib::subElement($def, $eltpath) : $def; // la définition de l'élément
      $pathDir = dirname($filepath);
    }
    if (!isset($instance['$schema']))
      return new Status(['propriété $schema absente du document']);
    $jSchema = $instance['$schema'];
    if (is_string($jSchema) && (strpos($jSchema, '#') === false))
      $jSchema .= '.schema.yaml';
    if (is_string($jSchema) && (substr($jSchema, 0, 7)<>'http://') && (substr($jSchema, 0, 8)<>'https://')
      && (substr($jSchema, 0, 1)<>'/') && isset($pathDir))
        $jSchema = "$pathDir/$jSchema";
    $schema = new JsonSchema($jSchema, $verbose);
    return $schema->check($instance, $options);
  }

  static function test(): never {
    echo "Test JsonSchema<br>\n";
    foreach ([['type'=> 'string'], ['type'=> 'number']] as $schemaDef) {
      $schema = new JsonSchema($schemaDef);
      $status = $schema->check('Test');
      if ($status->ok()) {
        echo "ok<br>\n";
        $status->showWarnings();
      }
      else
        $status->showErrors();
    }
    echo "FIN test JsonSchema<br><br>\n";
    die();
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe JsonSchema 
  if (!isset($_GET['test']))
    echo "<a href='?test=JsonSchema'>Test de la classe JsonSchema</a><br>\n";
  elseif ($_GET['test']=='JsonSchema')
    JsonSchema::test();
}
