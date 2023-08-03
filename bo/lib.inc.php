<?php
// bo/pflib.inc.php - biblio de functions - 2/8/2023

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

function directoryEntries(string $path): array { // retourne les entrées d'un répertoire sauf '.','..' et '.DS_Store'
  $entries = [];
  foreach (new DirectoryIterator($path) as $entry) {
    if (!in_array($entry, ['.','..','.DS_Store']))
      $entries[(string)$entry] = 1;
  }
  ksort($entries);
  return array_keys($entries);
}

// supprime les - suivis d'un retour à la ligne dans Yaml::dump()
function YamlDump($data, int $level=3, int $indentation=2, int $options=0): string {
  $dump = Yaml::dump($data, $level, $indentation, $options);
  return preg_replace('!-\n *!', '- ', $dump);
}

// affiche un boutton HTML
function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string {
  $form =  "<form action='$action' method='$method'>";
  foreach ($hiddenValues as $name => $value)
    $form .= "  <input type='hidden' name='$name' value='$value' />";
  return $form
    ."  <input type='submit' value='$submitValue'>"
    ."</form>";
}
