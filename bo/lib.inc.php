<?php
namespace bo;

/*PhpDoc:
name: pflib.inc.php
title: bo/pflib.inc.php - biblio de functions - 2-13/8/2023
*/

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

/** @return array<int, string> */
function directoryEntries(string $path): array { // retourne les entrées d'un répertoire sauf '.','..' et '.DS_Store'
  $entries = [];
  foreach (new \DirectoryIterator($path) as $entry) {
    if (!in_array($entry, ['.','..','.DS_Store']))
      $entries[(string)$entry] = 1;
  }
  ksort($entries);
  return array_keys($entries);
}

/** supprime les - suivis d'un retour à la ligne dans Yaml::dump()
 * @param mixed $data
 */
function YamlDump(mixed $data, int $level=3, int $indentation=2, int $options=0): string {
  $options |= Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;
  $dump = Yaml::dump($data, $level, $indentation, $options);
  //return $dump;
  //return preg_replace('!-\n *!', '- ', $dump);
  return preg_replace('!: \|-ZZ\n!', ": |-\n", preg_replace('!-\n *!', '- ', preg_replace('!(: +)\|-\n!', "\$1|-ZZ\n", $dump)));
}

// regroupe qqs méthodes statiques de création de formulaires simples
class Html {
  /** affiche un bouton HTML
   * @param array<string, string> $hiddenValues
   */
  static function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string {
    $form =  "<form action='$action' method='$method'>";
    foreach ($hiddenValues as $name => $value)
      $form .= "  <input type='hidden' name='$name' value='$value' />";
    return $form
      ."  <input type='submit' value='$submitValue'>"
      ."</form>";
  }
  
  /** création d'un formulaire Html de choix d'une valeur (<select>)
   * @param array<int, string>|array<string, string> $choices
   * @param array<string, string> $hiddenValues
   */
  static function select(string $name, array $choices, string $selected='', string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='get'): string {
    {/*Paramètres:
        $name: nom du select, sera le champ de $_GET/$_POST contenant la valeur choisie
        $choices: la liste des choix possibles soit sous la forme de liste, soit sous la forme d'un dictionnaire [nom => libellé]
        $selected: le nom du choix pré-selectionné dans l'affichage
        $submitValue: libellé du bouton de sélection
        $hiddenValues: dict. [nom => valeur] transmis dans la variable $_GET ou $_POST
        $action: chemin du script à exécuter après sélection de la valeur
        $method: 'get' pour une transmission Http GET, 'post' pour une transmission POST
      Le code Html est formatté pour faciliter son débuggage
    */}
    $spaces = '    ';
    $form =  "$spaces<form action='$action' method='$method'>\n";
    foreach ($hiddenValues as $hvname => $value) {
      $form .= "$spaces  <input type='hidden' name='$hvname' value='$value' />\n";
    }
    $form .= "$spaces  <select name='$name'>\n";
    foreach ($choices as $choice => $label) {
      if (is_int($choice)) $choice = $label;
      $form .= "$spaces    <option value='$choice'".($choice==$selected ? ' selected' : '').">$label</option>\n";
    }
    return $form
      ."$spaces  </select>\n"
      ."$spaces  <input type='submit' value='$submitValue'>\n"
      ."$spaces</form>\n";
  }
  
  // création d'un formulaire de saisie d'un texte (<textarea>)
  /** @param array<string, string> $hiddenValues */
  static function textArea(string $name, string $text, int $rows=3, int $cols=50, string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='get'): string {
    $form = "<form action='$action' method='$method'>\n";
    foreach ($hiddenValues as $hname => $hvalue)
      $form .= "  <input type='hidden' name='$hname' value='$hvalue' />\n";
    return $form
      ."<textarea name='$name' rows='$rows' cols='$cols'>".htmlspecialchars($text)."</textarea>\n"
      ."<input type='submit' value='$submitValue'>\n"
      ."</form>";
  }
};

/** Permet de distinguer si un script est inclus dans un autre ou est directement appelé en mode CLI ou Web
 * retourne '' si ce fichier n'a pas été directement appelé, cad qu'il est inclus dans un autre,
 *  'web' s'il est appelé en mode web, 'cli' s'il est appelé en mode CLI
 * @return ''|'web'|'cli' */
function callingThisFile(string $file): string {
  global $argv;
  if (php_sapi_name() == 'cli') {
    return ($argv[0] == basename($file)) ? 'cli' : '';
  }
  else {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    if (substr($documentRoot, -1)=='/') // Sur Alwaysdata $_SERVER['DOCUMENT_ROOT'] se termine par un '/'
      $documentRoot = substr($documentRoot, 0, -1);
    return ($file == $documentRoot.$_SERVER['SCRIPT_NAME']) ? 'web' : '';
  }
}

function dumpString(string $s): void { // affiche les codes ASCII des caractères d'une chaine
  echo "<table border=1><tr>";
  for ($i = 0; $i < strlen($s); $i++) {
    $c = substr($s, $i, 1);
    echo "<td>$c</td>\n";
  }
  echo "</tr><tr>\n";
  for ($i = 0; $i < strlen($s); $i++) {
    $c = substr($s, $i, 1);
    printf ("<td>%x</td>\n", ord($c));
  }
  echo "</tr></table>\n";
}

if (!callingThisFile(__FILE__)) return;

if (0) { // @phpstan-ignore-line
  echo "callingThisFile=",callingThisFile(__FILE__),"<br>\n";
  die("Fin dans ".__FILE__.", ligne ".__LINE__."<br>\n");
}
elseif (1) {
  foreach ([
    [
      'title'=> "un MULTI_LINE_LITERAL_BLOCK et une liste avec des objets",
      'description'=> "La description\nsur plusieurs\nlignes",
      'liste'=> [
        "a",
        ['title'=> "le fils"],
      ],
      'eof'=> null,
    ],
    [
      'title'=> "Un des sous-objets avec un MULTI_LINE_LITERAL_BLOCK",
      'description'=> "La description\nsur plusieurs\nlignes",
      'liste'=> [
        "a",
        [
          'title'=> "le fils",
          'description'=> "La description du fils\nsur plusieurs\nlignes",
        ],
      ],
      'eof'=> null,
    ],
  ] as $doc)
  echo "<pre>",YamlDump($doc),"</pre>\n";
}
