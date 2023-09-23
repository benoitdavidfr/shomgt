<?php
/** Fichier test de définition et utilisation de fonction et de classe */
namespace ns;
function fun1(): void { $a = 1; } // fonction retournant un void
function fun2(): array { $a = 1; return []; } // fonction retournant un array
function fun11() : void { $a = 1; } // fonction avec blanc avant :
function fun21(int $a, string $b) : array { $a = 1; return []; } // fonction avec paramètres

class C { // définition d'une classe
  function __construct() { $a = 1; }
  function nonStatMeth(): void { $a = 1; }
  static function statMeth(): void { $a = 1; }
};

class D extends C { // définition d'une sous-classe
};

interface I {};
  
class E implements I {};

class F extends C implements I {};

fun1(); // appel fonction
fun2 (); // appel fonction avec blanc
$c = new C; // création d'un objet
$c = new C('a'); // création d'un objet avec paramètre
$c->nonStatMeth(); // appel d'une méthode non statique
C::statMeth(); // appel d'une méthode statique

fun1(function () { return ''; });
echo "Ok ",__FILE__,"<br>\n";
