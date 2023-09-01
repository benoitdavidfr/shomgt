<?php
/*PhpDoc:
title: requiregraph.php - construction de la liste des inclusions require et require_once - 11/6/2023
*/
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class RequiredGraphHtml {
  /** @param array<string, string> $options */
  static function selectOptions(string $outputFormat, array $options): string {
    $html = '';
    foreach ($options as $key => $label) {
      $html .= "        <option".(($outputFormat==$key) ? " selected='selected'": '')." value='$key'>$label</option>\n";
    }
    return $html;
  }
};

set_time_limit(60);
$action = $_GET['a'] ?? 'showIncludes';
if (!in_array($action, ['context.jsonld'])) { // formulaire 
  echo "<html><head><title>requiregraph $action</title></head><body>
    <form>
    <select name='a' id='a'>\n",
    RequiredGraphHtml::selectOptions($action, [
      'dumpLinks'=> "affiche le résultat du grep",
      'readSrc'=> "lit la liste des fichiers Php puis les ordres require entre fichiers puis effectue un affichage basique",
      'showIncludes'=> "affiche les inclusions à partir du fichier incluant",
      'showIncluded'=> "affiche les inclusions à partir du fichier inclus",
    ]),
    "      </select>
    <input type='submit' value='Submit' /></form><pre>\n";
}

class PhpFile {
  static string $ROOT; // La racine de shomgt
  protected string $path; // path du fichier ss $ROOT 
  protected ?string $title=null; // titre récupéré dans le src
  /** @var array<string, array<string, string>> $includes */
  protected array $includes=[]; // [{path} => ['grepSrc'=> {grepSrc}]]
  /** @var array<int, string> $includedIn */
  protected array $includedIn=[]; // [{path}] path par rapport à $ROOT
  /** @var array<int,string> $sameAs; */
  protected array $sameAs=[]; // liens d'identités 
  /** @var array<string, self> */
  static array $all; // [{path} => {PhpFile}]  
  
  function __construct(string $path) { // crée un fichier 
    //echo "PhpFile::__construct(path: $path)\n";
    $this->path = substr($path, strlen(self::$ROOT));
    //echo "file_get_contents($path)\n";
    if (!is_file($path)) return;
    $src = file_get_contents($path);
    //echo "$path:\n", htmlspecialchars($src); die();
    $this->title = preg_match('!title: ([^\n]*)\n!', $src, $matches) ? $matches[1] : null;
  }
  
  function addInc(string $includedFile, string $grepSrc): void { // ajoute un fichier inclus 
    $this->includes[substr($includedFile, strlen(self::$ROOT))]['grepSrc'] = $grepSrc;
  }
  
  static function add(string $path, string $includedFile, string $grepSrc): void { // ajoute un lien includes 
    //echo "add($path, $includedFile, $grepSrc)\n";
    $path = realpath("../$path");
    //echo "  path -> $path\n";
    $includedFile = realpath(dirname($path)."/$includedFile");
    //echo "  includedFile -> $includedFile\n";
    if (!isset(self::$all[substr($path, strlen(self::$ROOT))])) {
      self::$all[substr($path, strlen(self::$ROOT))] = new self($path);
    }
    self::$all[substr($path, strlen(self::$ROOT))]->addInc($includedFile, $grepSrc);
  }
  
  /** @param array<string, mixed> $options */
  static function readPhpFiles(array $options=[]): void { // création des fichiers Php 
    self::$ROOT = dirname(dirname(__FILE__));
    $command = "find ".self::$ROOT." -name '*.php' -print";
    $output = [];
    $result_code = null;
    exec($command, $output, $result_code);
    if ($options['dump'] ?? null) {
      echo '$output@readPhpFiles = '; print_r($output);
    }
    foreach ($output as $path) {
      if (preg_match('!/vendor/!', $path)) // exclusion de vendor sauf autoload
        continue;
      else {
        self::$all[substr($path, strlen(self::$ROOT))] = new self($path);
      }
    }
  }
  
  /** @param array<string, mixed> $options */
  static function readLinks(array $options=[]): void { // recherche des liens par grep 
    $command = "grep require ../*.php ../*/*.php ../*/*/*.php";
    $output = [];
    $result_code = null;
    exec($command, $output, $result_code);
    if ($options['dump'] ?? null)
      print_r($output);
    foreach ($output as $line) {
      if (!preg_match("!^\\.\\.([^:]+): *require(_once)? (__DIR__\\.)?'([^']*)';!", $line, $matches)) {
        if ($options['dump'] ?? null)
          echo "NO MATCH for $line\n";
        continue;
      }
      //echo 'matches@readlinks = '; print_r($matches);
      PhpFile::add($matches[1], $matches[3] ? substr($matches[4], 1) : $matches[4], $matches[0]);
    }
  }
  
  static function buildIncludedIn(): void { // génère les liens inverses includedIn
    foreach (self::$all as $path => $phpFile) {
      foreach ($phpFile->includes as $includedFilePath => $grepSrc) {
        //echo "$path includes $includedFilePath\n";
        if (!isset(self::$all[$includedFilePath])) {
          self::$all[$includedFilePath] = new self(self::$ROOT.$includedFilePath);
        }
        self::$all[$includedFilePath]->includedIn[] = $path;
      }
    }
  }
    
  /** @return array<string, array<string, mixed>> */
  static function allIncludes(): array {
    $allIncludes = [];
    foreach (self::$all as $path => $phpFile) {
      if ($phpFile->title)
        $allIncludes[$path]['title'] = $phpFile->title;
      if ($phpFile->sameAs)
        $allIncludes[$path]['sameAs'] = $phpFile->sameAs;
      $allIncludes[$path]['includes'] = array_keys($phpFile->includes);
    }
    return $allIncludes;
  }

  /** @return array<string, array<string, mixed>> */
  static function allIncluded(): array {
    $allIncluded = [];
    foreach (self::$all as $path => $phpFile) {
      if ($phpFile->includedIn) {
        if ($phpFile->title)
          $allIncluded[$path]['title'] = $phpFile->title;
        if ($phpFile->sameAs)
          $allIncluded[$path]['sameAs'] = $phpFile->sameAs;
        $allIncluded[$path]['includedIn'] = $phpFile->includedIn;
      }
    }
    return $allIncluded;
  }
};

switch ($action) {
  case 'dumpLinks': {
    PhpFile::readPhpFiles();
    PhpFile::readLinks(['dump'=>true]);
    break;
  }
  case 'readSrc': {
    PhpFile::readPhpFiles();
    PhpFile::readLinks();
    PhpFile::buildincludedIn();
    ksort(PhpFile::$all);
    echo 'PhpFile::$all = '; print_r(PhpFile::$all);
    break;
  }
  case 'showIncludes': {
    echo "**showIncludes\n";
    PhpFile::readPhpFiles();
    PhpFile::readLinks();
    PhpFile::buildIncludedIn();
    echo Yaml::dump(PhpFile::allIncludes(), 3);
    break;
  }
  case 'showIncluded': {
    echo "**showIncluded\n";
    PhpFile::readPhpFiles();
    PhpFile::readLinks();
    PhpFile::buildIncludedIn();
    echo Yaml::dump(PhpFile::allIncluded(), 3);
    break;
  }
}
