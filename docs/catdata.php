<?php
// génération

// liste des scaleDenomnator, sous la forme [label => valeur], définissant n intervalles
$sds = [
  '10M'=>1e7, '6.5M'=>6.5e6, '2.5M'=>2.5e6, '1.1M'=>1.1e6, '700k'=>7e5, '375k'=>3.75e5, '200k'=>2e5,
  '100k'=>1e5, '50k'=>5e4, '25k'=>2.5e4, '12.5k'=>1.25e4, 0=>0
];
foreach (array_keys($sds) as $no => $sdmin) {
  $sdvmax = ($no == 0) ? '' : $sdvmin;
  $sdvmin = $sds[$sdmin];
  echo "php ../cat/geojson.php $sdvmin $sdvmax > cat$sdmin.geojson\n";
}

// génération du README.md
$readme = "<!-- Ce fichier est généré par catdata.php -->\n\n";
$readme .= "## Catalogue des cartes GéoTiff du Shom\n\n";

$readme .= "- [carte du catalogue des cartes](https://benoitdavidfr.github.io/shomgt/map.html)\n\n";

foreach (array_keys($sds) as $no => $sdmin) {
  if ($no == 0)
    $readme .= "- [cartes dont l'échelle < 1/$sdmin](cat$sdmin.geojson)\n";
  else
    $readme .= "- [cartes aux échelles comprises entre 1/$sdmin et 1/$sdmax](cat$sdmin.geojson)\n";
  $sdmax = $sdmin;
}
file_put_contents('README.md', $readme);
