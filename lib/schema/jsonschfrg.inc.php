<?php
/*PhpDoc:
name: jsonschfrg.inc.php
title: jsonschfrg.inc.php - définition de la classe JsonSchFragment utilisée par le validateur de schéma JSON
classes:
doc: |
journal: |
  25/4/2020:
    ajout vérification de la contrainte enum pour un object, un array et un numberOrInteger en plus du string
  3/4/2020:
    chgt de nom du fichier
  8/2/2019:
    JsonSchFragment est utilisée en dehors de la classe JsonSchema
  24/1/2019:
    chgt de nom de la classe de Elt en Fragment et du fichier de jsonschelt en jsonschfrt
  19/1/2019:
    scission du fichier jsonschema.inc.php en jsonschema.inc.php et jsonschelt.inc.php
  1-18/1/2019:
    Voir journal dans jsonschema.inc.php
*/
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/*PhpDoc: classes
name: JsonSchFragment
title: class JsonSchFragment - classe utilisée par JsonSchema définissant un fragment d'un schema JSON
doc: |
  Un JsonSchFragment correspond à un fragment d'un schéma ; il connait son schéma père
  afin d'être capable pour retrouver une définition définie en relatif dans son schéma père
*/
class JsonSchFragment {
  const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP'; // DateTimeInterface::RFC3339_EXTENDED
  private $verbose; // verbosité boolean
  private $def; // définition de l'élément courant du schema sous la forme d'un array ou d'un booléen Php
  private $schema; // l'objet schema contenant l'élément, indispensable pour retrouver ses définitions
  // et pour connaitre son répertoire courant en cas de référence relative
  
  function __construct($def, JsonSchema $schema, bool $verbose) {
    if ($verbose)
      echo "JsonSchFragment::_construct(def=",json_encode($def),", schema, verbose=",$verbose?'true':'false',")<br>\n";
    if (!is_array($def) && !is_bool($def)) {
      $errorMessage = "TypeError: Argument def passed to JsonSchFragment::__construct() must be of the type array or boolean";
      echo "JsonSchFragment::__construct(def=",json_encode($this->def),")<br>$errorMessage<br><br>\n";
      throw new Exception($errorMessage);
    }
    $this->verbose = $verbose;
    $this->def = $def;
    $this->schema = $schema;
  }
  
  function __toString(): string { return json_encode($this->def, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
  
  function def(): array { return $this->def; }

  // le schema d'une des propriétés de l'object ou null si elle n'est pas définie
  private function schemaOfProperty(string $id, string $propname, JsonSchStatus $status): ?JsonSchFragment {
    if (0 && $this->verbose)
      echo "schemaOfProperty(id=$id, propname=$propname)@def=$this<br><br>\n";
    if (isset($this->def['properties'][$propname]) && $this->def['properties'][$propname])
      return new self($this->def['properties'][$propname], $this->schema, $this->verbose);
    if (isset($this->def['patternProperties'])) {
      foreach ($this->def['patternProperties'] as $pattern => $property)
        if (preg_match("!$pattern!", $propname))
          return new self($property, $this->schema, $this->verbose);
    }
    if (isset($this->def['additionalProperties'])) {
      if ($this->def['additionalProperties'] === false) {
        $status->setError("Erreur propriété $id.$propname interdite");
        return null;
      }
      else
        return new self($this->def['additionalProperties'], $this->schema, $this->verbose);
    }
    $status->setWarning("Attention: la propriété '$id.$propname' ne correspond à aucun motif");
    return null;
  }
  
  // vérification que l'instance correspond à l'élément de schema
  // $id est utilisé pour afficher les erreurs, $status est le statut en entrée, le retour est le statut modifié
  function checkC($instance, string $id, JsonSchStatus $status): JsonSchStatus {
    if (!$this->verbose)
      return $this->checkI($instance, $id, $status);
    $s = new JsonSchStatus;
    $s = $this->checkI($instance, $id, $s);
    if (!$s->ok()) {
        echo "&lt;- check(instance=",json_encode($instance),", id=$id)@def=$this<br><br>\n";
      $s->showErrors();
    }
    $status->append($s);
    return $status;
  }
  function check($instance, string $id='', ?JsonSchStatus $status=null): JsonSchStatus {
    if (!$status)
      $status = new JsonSchStatus;
    if ($this->verbose)
      echo "JsonSchFragment::check(instance=",json_encode($instance),", id=$id)@def=$this<br><br>\n";
    if (is_bool($this->def))
      return $this->def ? $status : $status->setError("Schema faux pour $id");
    if (!is_array($this->def))
      throw new Exception("schema non défini pour $id comme array, def=".json_encode($this->def));
    if (isset($this->def['$ref']))
      return $this->checkRef($id, $instance, $status);
    if (isset($this->def['anyOf']))
      return $this->checkAnyOf($id, $instance, $status);
    if (isset($this->def['oneOf']))
      return $this->checkOneOf($id, $instance, $status);
    if (isset($this->def['allOf']))
      return $this->checkAllOf($id, $instance, $status);
    if (isset($this->def['not']))
      return $this->checkNot($id, $instance, $status);
    
    $types = !isset($this->def['type']) ? [] :
        (is_string($this->def['type']) ? [$this->def['type']] : 
          (is_array($this->def['type']) ? $this->def['type'] : null));
    if ($types === null)
      throw new Exception("def[type]=".json_encode($this->def['type'])." ni string ni list pour $id");
    
    // vérifie la compatibilité entre le type indiqué par le schema et le type Php de l'instance
    if ($types)
      $status = $this->checkType($id, $types, $instance, $status);
    
    // vérifie les propriétés imposées
    if ((!$types || in_array('object', $types)))
      $status = $this->checkObject($id, $instance, $status);
    if (!$types || in_array('array', $types))
      $status = $this->checkArray($id, $instance, $status);
    if (!$types || array_intersect(['number', 'integer'], $types))
      $status = $this->checkNumberOrInteger($id, $instance, $status);
    if (!$types || in_array('string', $types))
      $status = $this->checkString($id, $instance, $status);
    return $status;
  }
   
  // traitement du cas où le schema est défini par un $ref
  private function checkRef(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkRef(id=$id, instance=",json_encode($instance),")@def=",json_encode($this->def),"<br><br>\n";
    $path = $this->def['$ref'];
    if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $path, $matches))
      throw new Exception("Chemin $path non compris dans JsonSchema::__construct()");
    $filepath = $matches[1];
    $eltpath = isset($matches[4]) ? $matches[4] : '';
    //echo "checkRef: filepath=$filepath, eltpath=$eltpath<br>\n";
    if (!$filepath) { // Si pas de filepath alors même fichier schéma
      $content = JsonSch::subElement($this->schema->def(), $eltpath);
      if (!$content) {
        if ($this->verbose)
          echo "<b>Erreur eltpath $eltpath non trouvé</b><br>\n";
        return $status->setError("Erreur eltpath $eltpath non trouvé");
      }
      $schemaElt = new self($content, $this->schema, $this->verbose);
      return $schemaElt->check($instance, $id, $status);
    }
    else {
      try { // Si filepath alors fichier schéma différent
        $schema = new JsonSchema($path, $this->verbose, $this->schema);
        return $schema->check($instance, [], $id, $status);
      } catch (Exception $e) {
        return $status->setError("Sur $id erreur ".$e->getMessage());
      }
    }
  }
  
  // traitement du cas où le schema est défini par un anyOf
  private function checkAnyOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkAnyOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    $errors = []; // liste des erreurs dans les différentes branches
    foreach ($this->def['anyOf'] as $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        return $status->append($status2);
      else
        $errors[] = $status2;
    }
    return $status->setErrorBranch("aucun schema anyOf pour $id", $errors);
  }
  
  // traitement du cas où le schema est défini par un oneOf
  private function checkOneOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkOneOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    $errors = []; // liste des erreurs dans les différentes branches
    $done = false;
    foreach ($this->def['oneOf'] as $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if (!$status2->ok()) {
        $errors[] = $status2;
        //echo $status2;
      }
      elseif (!$done) {
        //$status->append($status2);
        $done = true;
      }
      else
        return $status->setError("Plusieurs schema oneOf pour $id");
    }
    if ($done)
      return $status;
    else
      return $status->setErrorBranch("aucun schema oneOf pour $id", $errors);
  }
  
  // traitement du cas où le schema est défini par un allOf
  private function checkAllOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkAllOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    foreach ($this->def['allOf'] as $no => $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        $status->append($status2);
      else
        return $status->setErrorBranch("schema $no de allOf non vérifié pour $id", [$status2]);
    }
    return $status;
  }
  
  // traitement du cas où le schema est défini par un not
  private function checkNot(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkNot(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    $schema = new self($this->def['not'], $this->schema, $this->verbose);
    $status2 = new JsonSchStatus;
    $status2 = $schema->check($instance, $id, $status2);
    if ($status2->ok())
      return $status->setError("Sous-schema de not ok pour $id");
    else
      return $status;
  }

  // vérifie la compatibilité entre le type indiqué par le schema et le type Php de l'instance
  private function checkType(string $id, array $types, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkType(id=$id, types=",json_encode($types),", instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (is_array($instance)) {
      if (!$instance) {
        if (!array_intersect(['object','array'], $types))
          $status->setError("$id ! ".implode('|',$types));
      }
      elseif (is_assoc_array($instance)) {
        if (!in_array('object', $types))
          $status->setError("$id ! ".implode('|',$types));
      }
      elseif (!in_array('array', $types))
        $status->setError("$id ! ".implode('|',$types));
    }
    
    if (is_int($instance) && !is_string($instance)) {
      if (!array_intersect(['integer','number'], $types))
        $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    }
    elseif (is_numeric($instance) && !is_string($instance)) {
      if (!in_array('number', $types))
        $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    }

    if (is_object($instance) && (get_class($instance)=='DateTime'))
      $instance = $instance->format(self::RFC3339_EXTENDED);
    if (is_string($instance) && !in_array('string', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    if (is_bool($instance) && !in_array('boolean', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    if (is_null($instance) && !in_array('null', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    return $status;
  }
  
  // traitement des propriétés liées aux objets
  private function checkObject(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkObject(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (!is_array($instance) || ($instance && !is_assoc_array($instance)))
      return $status;
    
    // vérification du respect de l'enum
    if (isset($this->def['enum']) && !in_array($instance, $this->def['enum']))
      $status->setError("Erreur $id not in enum=".JsonSch::encode($this->def['enum']));
    
    // vérification que les propriétés obligatoires sont définies
    if (isset($this->def['required'])) {
      foreach ($this->def['required'] as $prop) {
        if (!array_key_exists($prop, $instance))
          $status->setError("propriété requise $id.$prop absente");
      }
    }
    
    // propertyNames définit le schéma que les propriétés doivent respecter
    if (isset($this->def['propertyNames'])) {
      $propSch = new self($this->def['propertyNames'], $this->schema, $this->verbose);
      foreach (array_keys($instance) as $propname) {
        $status = $propSch->check($propname, "$id.propertyNames.$propname", $status);
      }
    }
    // SinonSi ni patternProp ni additionalProp défini alors vérif que les prop de l'objet sont définies dans le schéma
    elseif (!isset($this->def['patternProperties']) && !isset($this->def['additionalProperties'])) {
      $properties = isset($this->def['properties']) ? array_keys($this->def['properties']) : [];
      if ($undef = array_diff(array_keys($instance), $properties))
        $status->setWarning("Attention: propriétés ".implode(', ',$undef)." de $id non définie(s) par le schéma");
    }
    
    // minProperties
    if (isset($this->def['minProperties']) && (count(array_keys($instance)) < $this->def['minProperties'])) {
      $nbProp = count(array_keys($instance));
      $minProperties = $this->def['minProperties'];
      $status->setError("objet $id a $nbProp propriétés < minProperties = $minProperties");
    }
    // maxProperties
    if (isset($this->def['maxProperties']) && (count(array_keys($instance)) > $this->def['maxProperties'])) {
      $nbProp = count(array_keys($instance));
      $maxProperties = $this->def['maxProperties'];
      $status->setError("objet $id a $nbProp propriétés > maxProperties = $maxProperties");
    }
    
    // vérification des caractéristiques de chaque propriété
    foreach ($instance as $prop => $pvalue) {
      if ($schProp = $this->schemaOfProperty($id, $prop, $status)) {
        $status = $schProp->check($pvalue, "$id.$prop", $status);
      }
    }
    
    // vérification des dépendances
    if (isset($this->def['dependencies']) && $this->def['dependencies']) {
      foreach ($this->def['dependencies'] as $propname => $dependency) {
        //echo "vérification de la dépendance sur $propname<br>\n";
        if (isset($instance[$propname])) { // alors la dépendance doit être vérifiée
          //echo "dependency=",json_encode($dependency),"<br>\n";
          if (!is_array($dependency))
            throw new Exception("Erreur dependency pour $id.$propname ni list ni assoc_array");
          elseif (!is_assoc_array($dependency)) { // property depedency
            //echo "vérification de la dépendance de propriété sur $propname<br>\n";
            foreach ($dependency as $dependentPropName)
              if (!isset($instance[$dependentPropName]))
                $status->setError("$id.$dependentPropName doit être défini car $id.$propname l'est");
          }
          else { // schema depedency
            //echo "vérification de la dépendance de schéma sur $propname<br>\n";
            $schProp = new self($dependency, $this->schema, $this->verbose);
            $status = $schProp->check($instance, $id, $status);
          }
        }
      }
    }
    return $status;
  }
  
  // traitement des propriétés d'array
  private function checkArray(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkArray(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (!is_array($instance) || is_assoc_array($instance))
      return $status;
    
    // vérification du respect de l'enum
    if (isset($this->def['enum']) && !in_array($instance, $this->def['enum']))
      $status->setError("Erreur $id not in enum=".JsonSch::encode($this->def['enum']));
    
    if (isset($this->def['minItems']) && (count($instance) < $this->def['minItems'])) {
      $nbre = count($instance);
      $minItems = $this->def['minItems'];
      $status = $status->setError("array $id contient $nbre items < minItems = $minItems");
    }
    if (isset($this->def['maxItems']) && (count($instance) > $this->def['maxItems'])) {
      $nbre = count($instance);
      $maxItems = $this->def['maxItems'];
      $status = $status->setError("array $id contient $nbre items > maxItems = $maxItems");
    }
    if (isset($this->def['contains'])) {
      $schOfElt = new self($this->def['contains'], $this->schema, $this->verbose);
      $oneOk = false;
      foreach ($instance as $i => $elt) {
        $status2 = $schOfElt->check($elt, "$id.$i", new JsonSchStatus);
        if ($status2->ok()) {
          $status->append($status2);
          $oneOk = true;
          break;
        }
      }
      if (!$oneOk)
        $status->setError("aucun élément de $id ne vérifie contains");
    }
    if (isset($this->def['uniqueItems']) && $this->def['uniqueItems']) {
      if (count(array_unique($instance)) <> count($instance))
        $status->setError("array $id ne vérifie pas uniqueItems");
    }
    if (!isset($this->def['items']))
      return $status;
    if (is_bool($this->def['items']))
      return $this->def['items'] ? $status : $status->setError("items faux pour $id");
    if (!is_array($this->def['items']))
      throw new Exception("items devrait être un objet, un array ou un booléen pour $id");
    if (!is_assoc_array($this->def['items']))
      return $this->checkTuple($id, $instance, $status);
    $schOfItem = new self($this->def['items'], $this->schema, $this->verbose);
    foreach ($instance as $i => $elt)
      $status = $schOfItem->check($elt, "$id.$i", $status);
    return $status;
  }
  
  // traitement du cas où le type indique que la valeur est un object
  private function checkTuple(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkTuple(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    foreach ($this->def['items'] as $i => $defElt) {
      if (isset($instance[$i])) {
        $schOfElt = new self($defElt, $this->schema, $this->verbose);
        $status = $schOfElt->check($instance[$i], "$id.$i", $status);
      }
    }
    if (isset($this->def['additionalItems']) && is_bool($this->def['additionalItems'])) {
      if (count(array_keys($instance)) > count(array_keys($this->def['items'])))
        return $status->setError("additionalItems forbiden for $id");
    }
    if (isset($this->def['additionalItems']) && is_array($this->def['additionalItems'])) {
      $addItemsSchema = new self($this->def['additionalItems'], $this->schema, $this->verbose);
      foreach ($instance as $i => $elt)
        if (!isset($this->def['items'][$i]))
          $staus = $addItemsSchema->check($elt, "$id.$i", $status);
    }
    return $status;
  }

  // traitement du cas où le type indique que l'instance est un numérique ou un entier
  private function checkNumberOrInteger(string $id, $number, JsonSchStatus $status): JsonSchStatus {
    if (!is_numeric($number))
      return $status;
    
    // vérification du respect de l'enum
    if (isset($this->def['enum']) && !in_array($instance, $this->def['enum']))
      $status->setError("Erreur $id not in enum=".JsonSch::encode($this->def['enum']));

    if (isset($this->def['minimum']) && ($number < $this->def['minimum']))
      $status = $status->setError("Erreur $id=$number < minimim = ".$this->def['minimum']);
    if (isset($this->def['exclusiveMinimum']) && ($number <= $this->def['exclusiveMinimum']))
      $status = $status->setError("Erreur $id=$number <= exclusiveMinimum = ".$this->def['exclusiveMinimum']);
    if (isset($this->def['maximum']) && ($number > $this->def['maximum']))
      $status = $status->setError("Erreur $id=$number > maximum = ".$this->def['maximum']);
    if (isset($this->def['exclusiveMaximum']) && ($number >= $this->def['exclusiveMaximum']))
      $status = $status->setError("Erreur $id=$number >= exclusiveMaximum = ".$this->def['exclusiveMaximum']);
    if (isset($this->def['multipleOf']) && !self::hasNoFractionalPart($number/$this->def['multipleOf']))
      $status = $status->setError("Erreur $id=$number non multiple de ".$this->def['multipleOf']);
    return $status;
  }
  
  // teste l'absence de partie fractionaire du nombre passé en paramètre, en pratique elle doit être très faible
  static private function hasNoFractionalPart($f): bool { return abs($f - floor($f)) < 1e-15; }
  
  // traitement du cas où le type indique que l'instance est une chaine ou une date
  private function checkString(string $id, $string, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkString(id=$id, instance=",json_encode($string),")@def=$this<br><br>\n";
    if (is_array($string))
      return $status;
    if (is_object($string) && (get_class($string)=='DateTime'))
      $string = $string->format(self::RFC3339_EXTENDED);
    if (isset($this->def['enum']) && !in_array($string, $this->def['enum']))
      $status->setError("Erreur $id=\"$string\" not in enum=".JsonSch::encode($this->def['enum']));
    if (isset($this->def['const']) && ($string <> $this->def['const']))
      $status->setError("Erreur $id=\"$string\" <> const=\"".$this->def['const']."\"");
    if (!is_string($string))
      return $status;
    if (isset($this->def['minLength']) && (strlen($string) < $this->def['minLength']))
      $status->setError("length($string)=".strlen($string)." < minLength=".$this->def['minLength']);
    if (isset($this->def['maxLength']) && (strlen($string) > $this->def['maxLength']))
      $status->setError("length($string)=".strlen($string)." > maxLength=".$this->def['maxLength']);
    if (isset($this->def['pattern'])) {
      $pattern = $this->def['pattern'];
      if (!preg_match("!$pattern!", $string))
        $status->setError("$string don't match $pattern");
    }
    if (isset($this->def['format']))
      $status = $this->checkStringFormat($id, $string, $status);
    return $status;
  }
  
  // test des formats, certains motifs sont à améliorer
  private function checkStringFormat(string $id, string $string, JsonSchStatus $status): JsonSchStatus {
    $knownFormats = [
      'date-time'=> '^\d\d\d\d-\d\d-\d\dT\d\d:\d\d(:\d\d(\.\d+)?)?(([-+]\d\d:\d\d)|Z)$', // RFC 3339, section 5.6.
      'date'=> '^\d\d\d\d-\d\d-\d\d$',
      'email'=> '^[-a-zA-Z0-9_\.]+@[-a-zA-Z0-9_\.]+$', // A vérifier - email address, see RFC 5322, section 3.4.1.
      'hostname'=> '^[-a-zA-Z0-9\.]+$', // Internet host name, see RFC 1034, section 3.1.
      'ipv4'=> '^\d+\.\d+\.\d+\.\d+(/\d+)?$', // IPv4 address, as defined in RFC 2673, section 3.2.
      'ipv6'=> '^[:0-9a-fA-F]+$', // IPv6 address, as defined in RFC 2373, section 2.2.
      'uri'=> '^(([^:/?#]+):)(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI, according to RFC3986.
      'uri-reference'=> '^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI Reference, RFC3986, section 4.1.
      'json-pointer'=> '^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(/[^/]+)*)?$', // A JSON Pointer, RFC6901.
      'uri-template'=> '^(([^:/?#]+):)(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI Template, RFC6570.
      'regex'=> '',
    ];
    $format = $this->def['format'];
    if (!isset($knownFormats[$format])) {
      $status->setWarning("format $format inconnu pour $id");
      return $status;
    }
    $pattern = $knownFormats[$format];
    if ($pattern && !preg_match("!$pattern!", $string))
      $status->setError("$string don't match $format");
    return $status;
  }
};

