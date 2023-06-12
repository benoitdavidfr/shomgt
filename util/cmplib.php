<?php
// comparaison des sous-répertoires lib 2 à 2
define ('LIBS', ['shomgt', 'sgupdt', 'sgserver', 'main']);
foreach (LIBS as $i => $libi) {
  foreach (LIBS as $j => $libj) {
    if ($j <= $i) continue;
    echo "diff -r $libi/lib $libj/lib\n";
  }
}
  