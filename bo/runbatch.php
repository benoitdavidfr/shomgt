<?php
/*PhpDoc:
name: runbatch.php
title: bo/runbatch.php - lancement d'un des 2 scripts CLI de mise à jour des cartes par sgupdt et de mise à jour des GAN
doc: |
  Lance en arrière plan un script CLI en stockant le résultat dans un fichier temporaire de sortie,
  Permet de consulter ce fichier temporaire de sortie au fur et à mesure de son remplissage,
  notamment dans le cas où l'exécution est assez longue.
  Revient à la fin au menu du BO en demandant d'effacer le fichier temporaire.
  C'est à l'utilisateur de décider quand la mise à jour est terminée.
*/  
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/user.php';

use Symfony\Component\Yaml\Yaml;

if (!($login = Login::loggedIn()) || !in_array(userRole($login), ['normal','admin'])) {
  die("Accès non autorisé\n");
}

$batches = [
  'test'=> ['title'=> "Test",'cmde'=> 'php batchtest.php'],
  'pwd'=> ['title'=> 'pwd', 'cmde'=> 'pwd'],
  'sgupdt'=> [
    'title'=> "Prise en compte des nouvelles versions dans la consultation des cartes",
    'cmde'=> 'php ../sgupdt/main.php',
  ],
  'harvestGan'=> [
    'title'=> "Moissonnage du GAN",
    'cmde'=> 'php ../dashboard/gan.php newHarvestAndStore',
  ],
]; // batches prévus

$batch = $_GET['batch'] ?? die("Erreur, le paramètre 'batch' est obligatoire");
$batch = $batches[$batch] ?? die("Erreur, batch=$batch inconnu");

echo "<!DOCTYPE html><html><head><title>runbatch@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo "<h2>$batch[title]</h2><pre>\n";

$uniqid = $_GET['next'] ?? uniqid();
$outputFilePath = __DIR__."/temp/output_$_GET[batch]$uniqid.txt";

$command = "$batch[cmde] > $outputFilePath 2>&1 &";

if (!isset($_GET['next'])) { // premier appel 
  exec($command, $output, $result_code);
  if ($result_code <> 0)
    echo Yaml::dump(['command'=> $command, '$result_code'=> $result_code, '$output'=> $output],
                    2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"\n";
  sleep(2); // j'attend 2 seconde que le script ait démarré
}

$output = file_get_contents($outputFilePath);
echo Yaml::dump(['command'=> $command, 'output'=> $output], 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"\n";

$no = ($_GET['no'] ?? 0) + 1; // permet de générer un URL différent à chaque fois
$href = "?batch=$_GET[batch]&amp;next=$uniqid"
        .(($_GET['returnTo'] ?? null) ? "&amp;returnTo=$_GET[returnTo]" : '')
        ."&amp;no=$no"
        .'#endOfFle';
echo "</pre><a id='endOfFle' href='$href'>Afficher la suite</a><br>\n";
$outputFileBasename = basename($outputFilePath);
switch ($_GET['returnTo'] ?? null) {
  case 'dashboard': {
    echo "<a href='../dashboard/index.php?action=deleteTempOutputBatch&filename=$outputFileBasename'>",
         "Revenir au menu du tableau de bord</a><br>\n";
    break;
  }
  default: {
    echo "<a href='index.php?action=deleteTempOutputBatch&filename=$outputFileBasename'>Revenir au menu du BO</a><br>\n";
    break;
  }
}
