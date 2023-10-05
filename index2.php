<?php
/** texte de la réelle page d'accueil
 *
 * @package shomgt
*/
require_once __DIR__.'/lib/accesscntrl.inc.php';

if (Access::cntrlFor('homePage') && !Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  die("Accès interdit");
}

die(file_get_contents(__DIR__.'/welcome.html'));
