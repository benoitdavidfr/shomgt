<?php
/**
 * liste des noms de base des fichiers Php chacun avec les répertoires le contenant 
 */
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

$files = [];
foreach (new DirectoryIterator('.') as $dir) {
  if (in_array($dir, ['.','..'])) continue;
  if (is_dir($dir)) {
    foreach (new DirectoryIterator($dir) as $file) {
      if (!is_file("$dir/$file")) continue;
      if (substr($file, -4)<>'.php') continue;
      echo "$file\n";
      $files[(string)$file][(string)$dir] = 1;
    }
  }
  elseif (substr($dir, -4) == '.php') {
    echo "$dir\n";
    $files[(string)$dir]['.'] = 1;
  }
}
//ksort($files);
print_r($files);
foreach ($files as $file => $dirs) {
  if (count($dirs) > 1)
    echo Yaml::dump([$file => array_keys($dirs)]);
}
