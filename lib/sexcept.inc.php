<?php
/** Exception avec code string
 * @package shomgt\lib
 */

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

/** Exception avec code string */
class SExcept extends Exception {
  protected string $scode; // code sous forme d'une chaine de caractères
  
  public function __construct(string $message, string $scode='', Throwable $previous = null) {
    $this->scode = $scode;
    parent::__construct($message, 0, $previous);
  }
  
  public function getSCode(): string { return $this->scode; }
};
