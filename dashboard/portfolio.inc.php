<?php
/*PhpDoc:
title: dashboard/portfolio.inc.php - lecture du portefeuille de cartes - 12/6/2023
*/

class Portfolio { // Portefeuille des cartes exposées sur ShomGt issu des fichiers .md.json
  /** @var array<string, string|array<string, int|string>> $all */
  static array $all; // contenu des fichiers .md.json structuré par carte comme dictionnaire indexé sur le no de carte 
  
  static function init(): void {
    if (!(($PF_PATH = getenv('SHOMGT3_DASHBOARD_PORTFOLIO_PATH')) || ($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))))
      throw new Exception("Variables d'env. SHOMGT3_DASHBOARD_PORTFOLIO_PATH et SHOMGT3_PORTFOLIO_PATH non définies");
    foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
      if (substr($entry, -8) <> '.md.json') continue;
      self::$all[substr($entry, 0, -8)] = json_decode(file_get_contents("$PF_PATH/current/$entry"), true);
    }
    //echo '<pre>'; print_r(self::$all);
  }
  
  static function exists(string $mapnum): bool {
    return isset(self::$all[$mapnum]);
  }
};
