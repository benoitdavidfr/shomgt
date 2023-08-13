<?php
/*PhpDoc:
name: batchtest.php
title: batchtest..php - script utilisé pour le test de runbatch.php
*/
$nbre = 15;
echo "batchtest $nbre\n";
for($i=0; $i < $nbre; $i++) {
  echo "i=$i\n";
  sleep(1);
}
echo "Fin batchtest\n";