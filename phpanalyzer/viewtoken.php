<?php
/** Visualisation des tokens d'un fichier Php.
 *
 * J'affiche un tableau en 3 colonnes
 *  1) le no de la ligne du source
 *  2) la ligne du source
 *  3) les tokens correspondant à cette ligne
 *
 * Prend en paramètres:
 *  - rpath: chemin relatif à .. (obligatoire)
 *  - noToken: les tokens ne sont pas affichés (facultatif) 
 *  - lineNo: numéro de la ligne à afficher (facultatif)
 *
 * Utilise des ancres pour chaque ligne du fichier nommées line{lineNo}
 */

{ // Propose de changer noToken en gardant rpath et lineNo
  $noToken = (($_GET['noToken'] ?? 'false') == 'false') ? false : true;
  $lineNr = $_GET['lineNr'] ?? '';
  $lineNrParam = $lineNr ? "&lineNr=$lineNr" : '';
  $lineNrAnchor = $lineNr ? "#line$lineNr" : '';
  if ($noToken) {
    echo "<a href='?rpath=$_GET[rpath]$lineNrParam&noToken=false$lineNrAnchor'>Avec tokens</a><br>\n";
  }
  else {
    echo "<a href='?rpath=$_GET[rpath]$lineNrParam&noToken=true$lineNrAnchor'>Sans token</a><br>\n";
  }
}

$code = file_get_contents(__DIR__."/..$_GET[rpath]");

// Décomposition des tokens par ligne du source
$tokensPerLine = []; // [{noLigne} => [{token}]]
$lineNr = 0;
foreach (token_get_all($code) as $token) {
  if (is_array($token)) {
    $lineNr = $token[2];
    $tokensPerLine[$lineNr][] = ['token_name'=> token_name($token[0]), 'src'=> $token[1]];
  }
  else {
    $tokensPerLine[$lineNr][] = ['src'=> $token];
  }
}

$srcPerLine = explode("\n", "\n$code");

echo "<!DOCTYPE html>\n<html><head><title>$_GET[rpath]</title></head><body>\n";
echo "<table border=1>";

foreach ($srcPerLine as $nol => $src) {
  if ($nol == 0) continue;
  echo "<tr><td><div id='line$nol'>$nol</td>";
  echo "<td>",htmlentities($src),"</td>";
  if (!$noToken) {
    echo "<td><table border=1>";
    foreach ($tokensPerLine[$nol] ?? [] as $tokenOfLine) {
      echo "<tr><td>",$tokenOfLine['token_name'] ?? '',"</td>",
            "<td>",str_replace("\n","<br>\n",htmlentities($tokenOfLine['src'])),"</td></tr>";
    }
    echo "</table></td>";
  }
  echo "</tr>\n";
}
echo "</table>\n";

/*
"<tr><td valign='top'><pre>";

echo "</pre></td><td valign='top'><pre>",htmlentities($code),"</pre></td><td valign='top'><pre>";
foreach (token_get_all($code) as $token) {
    if (is_array($token)) {
        echo "Line {$token[2]}: ", token_name($token[0]), " ('",htmlentities($token[1]),"')\n";
    }
    else
      echo "token: $token\n";
}
echo "</pre></td></tr></table>\n";
*/
