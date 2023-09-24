<?php
/** script utilisé pour le test de runbatch.php */
$nbre = 150;
echo "batchtest $nbre\n";
for($i=0; $i < $nbre; $i++) {
  echo "i=$i / $nbre\n";
  if( ($i % 20) == 0) {
    echo "sleep 1\n";
    sleep(1);
  }
}
echo "Fin batchtest\n";