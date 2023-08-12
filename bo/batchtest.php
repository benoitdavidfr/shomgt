<?php
$nbre = 15;
echo "batchtest $nbre\n";
for($i=0; $i < $nbre; $i++) {
  echo "i=$i\n";
  sleep(1);
}
echo "Fin batchtest\n";