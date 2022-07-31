<?php
/*PhpDoc:
name:  sexcept.inc.php
title: sexcept.inc.php - Exception avec code string
classes:
doc: |
*/
/*PhpDoc: classes
name: SExcept
title: class SExcept extends Exception - Exception avec code string
doc: |
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

class SExcept extends Exception {
  protected string $scode; // code sous forme d'une chaine de caractères
  
  public function __construct(string $message, string $scode='', Throwable $previous = null) {
    $this->scode = $scode;
    parent::__construct($message, 0, $previous);
  }
  
  public function getSCode(): string { return $this->scode; }
};
