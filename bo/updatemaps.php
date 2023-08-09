<?php
/* bo/updatemaps.php - mise à jour des cartes par sgupdt
**
** Lance en arrière plan la mise à jour des cartes par sgupdt en stockant le résultat dans un fichier temporaire de sortie,
** Permet de consulter ce fichier temporaire de sortie au fur et à mesure de son remplissage,
** en effet cette mise à jour peut être assez longue.
** Revient à la fin au menu du BO en demandant d'effacer le fichier temporaire.
** C'est à l'utilisateur de décider quand la mise à jour est terminée.
*/  
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;

if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

echo "<!DOCTYPE html><html><head><title>updatemaps@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo "<h2>Prise en compte des nouvelles versions dans la consultation des cartes</h2><pre>\n";

$uniqid = $_GET['next'] ?? uniqid();
$outputFilePath = __DIR__."/temp/output_sgupdt$uniqid.txt";
$command = "php ../sgupdt/main.php > $outputFilePath 2>&1 &";

if (!isset($_GET['next'])) { // premier appel 
  exec($command, $output, $result_code);
  if ($result_code <> 0)
    echo Yaml::dump(['command'=> $command, '$result_code'=> $result_code, '$output'=> $output],
                    2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"\n";
  sleep(2); // j'attend 2 seconde que le script ait démarré
}

$output = file_get_contents($outputFilePath);
echo Yaml::dump(['command'=> $command, 'output'=> $output], 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"\n";

echo "</pre><a href='?next=$uniqid'>Afficher la suite</a><br>\n";
$outputFileBasename = basename($outputFilePath);
echo "<a href='index.php?action=deleteTempOutputSgupdt&filename=$outputFileBasename'>Revenir au menu du BO</a><br>\n";
