<?php
/** pour un entier fournit une représentation avec un '_' comme séparateur des milliers */

/** pour un entier fournit une représentation avec un '_' comme séparateur des milliers */
function addUndescoreForThousand(?int $val): string {
  if ($val === null) return 'undef';
  if ($val < 0)
    return '-'.addUndescoreForThousand(-$val);
  elseif ($val < 1000)
    return sprintf('%d', $val);
  else
    return addUndescoreForThousand(intval(floor($val/1000)))
      .'_'.sprintf('%03d', $val - 1000 * floor($val/1000));
}

if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire

echo addUndescoreForThousand(789),"<br>\n";
echo addUndescoreForThousand(56812789),"<br>\n";
echo addUndescoreForThousand(-56812789),"<br>\n";
echo addUndescoreForThousand(250000),"<br>\n";
echo addUndescoreForThousand(10000000),"<br>\n";
echo addUndescoreForThousand(10_000_000),"<br>\n";
