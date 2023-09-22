<?php
/** Fichier test d'utilisation de définitions avec espace de nom explicite */
require_once __DIR__.'/def.php';

\ns\fun1(); // appel fonction
\ns\fun2 (); // appel fonction avec blanc
$c = new \ns\C; // création d'un objet
$c = new \ns\C('a'); // création d'un objet avec paramètre
$c->nonStatMeth(); // appel d'une méthode non statique
\ns\C::statMeth(); // appel d'une méthode statique

\ns\fun1(function () { return ''; });
echo "Ok ",__FILE__,"<br>\n";
