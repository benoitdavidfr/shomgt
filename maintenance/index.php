<?php
// maintenance/indes.php - fichier d'accueil lorsque le site est en maintenance
echo "<h1>Site en maintenance</h1>\n";
die(file_get_contents(__DIR__.'/welcome.html'));
