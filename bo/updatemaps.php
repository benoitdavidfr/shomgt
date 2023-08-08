<?php
// bo/updatemaps.php - prise en compte des cartes dans sgupdt

require_once __DIR__.'/login.inc.php';

if (!($login = Login::login())) {
  die("Accès non autorisé\n");
}

echo "<!DOCTYPE html><html><head><title>addmaps</title></head><body>\n";
echo "<h2>Prise en compte les nouvelles versions déposées dans la consultation des cartes</h2>\n";

//$command = 'php ../sgupdt/main.php';
//exec($command, $output, $result_code);

