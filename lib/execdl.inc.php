<?php
/*PhpDoc:
title: execdl.inc.php - fonctions execCmde() et download()
name: execdl.inc.php
functions:
doc: |
journal: |
  20/6/2022:
    - modif du retour d'execCmde() pour récupérer l'output de la commande appelée en cas d'erreur
  20/5/2022:
    - modif pour traiter un téléchargement avec authentification
  18/5/2022:
    - création à partir de main.php
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

/**
 * execCmde - exécute une commande Linux - enrobage de exec()
 *
 * exécute une cmde Linux, $verbose est le degré de verbosité
 * retourne [] ssi tout est ok, cad le retour d'exec() !== false && $result_code == 0
 * sinon retourne ['result_code'=> {result_code}, 'output'=> {output}]
 *
 * @param string $cmde
 * @param int $verbose
 * @return array<string, string|int>
 */
function execCmde(string $cmde, int $verbose): array {
  //echo "execCmde($cmde)\n";
  if ($verbose >= 1)
    echo ">> $cmde\n";
  $output = [];
  $result_code = 0;
  $return = exec($cmde, $output, $result_code);
  if ($output && ($verbose > 1)) {
    echo "output="; print_r($output);
  }
  if (($return === false) || ($result_code <> 0)) // erreur d'appel d'exec() ou code retour <> 0
    return ['result_code'=> $result_code, 'output'=> $output];
  else  // tout est ok
    return [];
}

/*PhpDoc: functions
title: download - téléchargement d'un fichier en utilisant la commande wget
name: download
doc: |
  effectue un wget sur l'url et stocke le résultat dans $outputFile ; retourne le code http ; si code<>200 le fichier est vide
  Utilise les variables d'environnement http_proxy: et https_proxy si elles sont définies
  ainsi que le login/passwd défini dans l'URL du serveur
*/
function download(string $url, string $outputFile, int $verbose): int {
  //echo "download($url, $outputFile)<br>\n";
  execCmde("wget -O $outputFile -o ".__DIR__."/wgetlogfile.log --server-response $url", $verbose);
  $log = file_get_contents(__DIR__.'/wgetlogfile.log');
  //echo $log;
  //sleep(10*60);
  /* modif 20/5/2022
     Lorsqu'une authentification est nécessaire, il y a plusieurs appels HTTP et donc plusieurs match du pattern
     Il est alors nécessaire de récupérer le dernier match qui correspond au dernier appel HTTP */
  $httpCode = null;
  while (preg_match('!\n  HTTP/1\.1 (\d+) ([^\n]+)\n!', $log, $matches)) {
    $httpCode = $matches[1];
    //echo "httpCode=$httpCode\n";
    $log = preg_replace('!\n  HTTP/1\.1 (\d+) ([^\n]+)!', '', $log, 1);
  }
  //echo "httpCode=$httpCode\n";
  if (!$httpCode) {
    echo file_get_contents(__DIR__.'/wgetlogfile.log');
    throw new Exception("No match httpCode dans download($url)");
  }

  if ($httpCode <> 200)
    unlink($outputFile); // efface le fichier vide
  return $httpCode;
}


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire
  
  
// Test de download() sur le serveur servtest.php
echo "<h2>Test de download() sur servtest.php</h2><pre>\n";
foreach (['200', '404', '400', '410', '204'] as $test) {
  echo "$test => ",download("http://localhost/geoapi/shomgt3/sgupdt/servtest.php?test=$test", "../test/$test", 9),"\n";
}

echo "auth => ",download("http://user:mdp@localhost/geoapi/shomgt3/sgupdt/servtest.php?test=401", "../test/auth", 9),"\n";
echo file_get_contents("../test/auth");

echo "badauth => ",download("http://user:mauvaismdp@localhost/geoapi/shomgt3/sgupdt/servtest.php?test=401", "../test/badauth", 9),"\n";

die();
