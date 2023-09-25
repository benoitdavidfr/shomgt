<?php
/** Fichier test d'utilisation de définitions sans espace de nom explicite
* @package phpanalyzer\TEST
 */
namespace ns;

require_once __DIR__.'/def.php';

fun1(); // appel fonction
fun2 (); // appel fonction avec blanc
$c = new C; // création d'un objet
$c = new C('a'); // création d'un objet avec paramètre
$c->nonStatMeth(); // appel d'une méthode non statique
C::statMeth(); // appel d'une méthode statique

fun1(function () { return ''; });
echo "Ok ",__FILE__,"<br>\n";
