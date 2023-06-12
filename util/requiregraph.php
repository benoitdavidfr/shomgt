<?php
/*PhpDoc:
title: requiregraph.php - construction de la liste des inclusions require et requi_once - 11/6/2023
bugs:
  - /main/lib/mapversion includes /main/lib/SevenZipArchive.php
*/
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class Html {
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
    Html::selectOptions($action, [
      
      'dumpLinks'=> "affiche le résultat du grep",
      'readSrc'=> "lit la liste des fichiers Php puis les ordres require entre fichiers puis effectue un affichage basique",
      'showIncludes'=> "affiche les inclusions à partir du fichier incluant",
      'showIncluded'=> "affiche les inclusions à partir du fichier inclus",
    ]),
    "      </select>
    <input type='submit' value='Submit' /></form><pre>\n";
}

class PhpFile {
  const ROOT = '/var/www/html/geoapi/shomgt';
  protected string $path; // path du fichier ss ROOT 
  protected ?string $title; // titre récupéré dans le src
  protected array $includes=[]; // [{path} => ['grepSrc'=> {grepSrc}]]
  protected array $includedIn=[]; // [{path}] path par rapport à ROOT
  protected array $sameAs=[]; // liens d'identités 
  static array $all; // [{path} => {PhpFile}]  
  
  function __construct(string $path) { // crée un fichier 
    $this->path = substr($path, strlen(self::ROOT));
    $src = file_get_contents($path);
    //echo "$path:\n", htmlspecialchars($src); die();
    $this->title = preg_match('!title: ([^\n]*)\n!', $src, $matches) ? $matches[1] : null;
  }
  
  function addInc(string $includedFile, string $grepSrc) { // ajoute un fichier inclus 
    $this->includes[substr($includedFile, strlen(self::ROOT))]['grepSrc'] = $grepSrc;
  }
  
  static function add(string $path, string $includedFile, string $grepSrc): void { // ajoute un lien includes 
   // echo "add($path, $includedFile)\n";
    $path = realpath("../$path");
    //echo "  path -> $path\n";
    $includedFile = realpath(dirname($path)."/$includedFile");
    //echo "  includedFile -> $includedFile\n";
    if (!isset(self::$all[substr($path, strlen(self::ROOT))])) {
      self::$all[substr($path, strlen(self::ROOT))] = new self($path);
    }
    self::$all[substr($path, strlen(self::ROOT))]->addInc($includedFile, $grepSrc);
  }
  
  static function readPhpFiles(array $options=[]): void { // création des fichiers Php 
    $command = "find .. -name '*.php' -print";
    $output = [];
    $result_code = null;
    exec($command, $output, $result_code);
    if ($options['dump'] ?? null) {
      echo '$output@readPhpFiles = '; print_r($output);
    }
    foreach ($output as $path) {
      if (preg_match('!/vendor/!', $path) && !preg_match('!/vendor/autoload.php$!', $path)) // exclusion de vendor sauf autoload
        continue;
      $path = realpath(__DIR__."/$path");
      self::$all[substr($path, strlen(self::ROOT))] = new self($path);
    }
  }
  
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
          self::$all[$includedFilePath] = new self(self::ROOT.$includedFilePath);
        }
        self::$all[$includedFilePath]->includedIn[] = $path;
      }
    }
  }
  
  static function buildSameAs(): void { // génère les liens sameAs 
    foreach (self::$all as $path => $phpFile) {
      foreach (['main','shomgt','sgupdt'] as $libname) {
        if (preg_match("!^/$libname/lib/!", $path)) {
          foreach (['main','shomgt','sgupdt'] as $libname2) {
            if ($libname2 == $libname) continue;
            $otherPath = str_replace($libname, $libname2, $path);
            if (isset(self::$all[$otherPath])) {
              //echo "lib $path sameAs $otherPath\n";
              $phpFile->sameAs[] = $otherPath;
            }
          }
        }
      }
    }
  }
  
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

  static function allIncluded(): array {
    $allIncluded = [];
    foreach (self::$all as $path => $phpFile) {
      if (true || $phpFile->includedIn) {
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
    PhpFile::buildSameAs();
    echo Yaml::dump(PhpFile::allIncludes(), 3);
    break;
  }
  case 'showIncluded': {
    echo "**showIncluded\n";
    PhpFile::readPhpFiles();
    PhpFile::readLinks();
    PhpFile::buildIncludedIn();
    PhpFile::buildSameAs();
    echo Yaml::dump(PhpFile::allIncluded(), 3);
    break;
  }
}
