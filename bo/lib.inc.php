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

/** Permet de distinguer si un script est inclus dans un autre ou est directement appelé en mode CLI ou Web
 * retourne null si ce script n'a pas été directement appelé, cad qu'il est inclus dans un autre,
 *  'web' s'il est appelé en mode web, 'cli' s'il est appelé en mode CLI
 * Doit être appelé avec en paramètre la constante __FILE__
 * @return null|'web'|'cli' */
function callingThisFile(string $file): ?string {
  global $argv;
  if (php_sapi_name() == 'cli') {
    return ($argv[0] == basename($file)) ? 'cli' : null;
  }
  else {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    if (substr($documentRoot, -1)=='/') // Sur Alwaysdata $_SERVER['DOCUMENT_ROOT'] se termine par un '/'
      $documentRoot = substr($documentRoot, 0, -1);
    return ($file == $documentRoot.$_SERVER['SCRIPT_NAME']) ? 'web' : null;
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
