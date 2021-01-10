<?php
/*PhpDoc:
name: xmltoarrayparser.inc.php
title: xmltoarrayparser.inc.php - XML to Associative Array Class
doc: |
  Provient de https://www.php.net/manual/fr/ref.xml.php
  *
  *  Usage:
  *     $domObj = new xmlToArrayParser($xml);
  *     $domArr = $domObj->array;
  *    
  *     if($domObj->parse_error) echo $domObj->get_xml_error();
  *     else print_r($domArr);
  *
  *     On Success:
  *     eg. $domArr['top']['element2']['attrib']['var2'] => val2
  *
  *     On Error:
  *     eg. Error Code [76] "Mismatched tag", at char 58 on line 3
*/

/**
* Convert an xml file or string to an associative array (including the tag attributes):
* $domObj = new xmlToArrayParser($xml);
* $elemVal = $domObj->array['element']
* Or:  $domArr=$domObj->array;  $elemVal = $domArr['element'].
*
* @version  2.0
* @param Str $xml file/string.
*/
class xmlToArrayParser {
  /** The array created by the parser can be assigned to any variable: $anyVarArr = $domObj->array.*/
  public  array $array = array();
  public  $parse_error = false;
  private XMLParser $parser;
  private $pointer;
 
  /** Constructor: $domObj = new xmlToArrayParser($xml); */
  public function __construct($xml) {
    $this->pointer =& $this->array;
    $this->parser = xml_parser_create("UTF-8");
    xml_set_object($this->parser, $this);
    xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, false);
    xml_set_element_handler($this->parser, "tag_open", "tag_close");
    xml_set_character_data_handler($this->parser, "cdata");
    $this->parse_error = xml_parse($this->parser, ltrim($xml)) ? false : true;
  }
 
  /** Free the parser. */
  public function __destruct() { xml_parser_free($this->parser);}

  /** Get the xml error if an an error in the xml file occured during parsing. */
  public function get_xml_error() {
    if ($this->parse_error) {
      $errCode = xml_get_error_code ($this->parser);
      $thisError =  "Error Code [". $errCode ."] \"<strong style='color:red;'>" . xml_error_string($errCode)."</strong>\",
                            at char ".xml_get_current_column_number($this->parser) . "
                            on line ".xml_get_current_line_number($this->parser)."";
    }
    else
      $thisError = $this->parse_error;
    return $thisError;
  }
 
  private function tag_open(XMLParser $parser, $tag, $attributes) {
    $this->convert_to_array($tag, 'attrib');
    $idx = $this->convert_to_array($tag, 'cdata');
    if (isset($idx)) {
      $this->pointer[$tag][$idx] = Array('@idx' => $idx,'@parent' => &$this->pointer);
      $this->pointer =& $this->pointer[$tag][$idx];
    }
    else {
      $this->pointer[$tag] = Array('@parent' => &$this->pointer);
      $this->pointer =& $this->pointer[$tag];
    }
    if (!empty($attributes)) {
      $this->pointer['attrib'] = $attributes;
    }
  }

  /** Adds the current elements content to the current pointer[cdata] array. */
  private function cdata(XMLParser $parser, $cdata) { $this->pointer['cdata'] = trim($cdata); }

  private function tag_close(XMLParser $parser, $tag) {
    $current = & $this->pointer;
    if (isset($this->pointer['@idx'])) {
      unset($current['@idx']);
    }
   
    $this->pointer = & $this->pointer['@parent'];
    unset($current['@parent']);
   
    if (isset($current['cdata']) && count($current) == 1) {
      $current = $current['cdata'];
    }
    elseif (empty($current['cdata'])) {
      unset($current['cdata']);
    }
  }
 
  /** Converts a single element item into array(element[0]) if a second element of the same name is encountered. */
  private function convert_to_array($tag, $item) {
    if (isset($this->pointer[$tag][$item])) {
      $content = $this->pointer[$tag];
      $this->pointer[$tag] = array((0) => $content);
      $idx = 1;
    }
    elseif (isset($this->pointer[$tag])) {
      $idx = count($this->pointer[$tag]);
      if (!isset($this->pointer[$tag][0])) {
        foreach ($this->pointer[$tag] as $key => $value) {
            unset($this->pointer[$tag][$key]);
            $this->pointer[$tag][0][$key] = $value;
        }
      }
    }
    else
      $idx = null;
    return $idx;
  }
}



if (__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) return; // Tests unitaires de la classe 


/*
This is supplimental information for the "class xmlToArrayParser".
This is a fully functional error free, extensively tested php class unlike the posts that follow it.

Key phrase: Fully functional, fully tested, error free XML To Array parser.

* class xmlToArrayParser
*
  Notes:
  1. 'attrib' and 'cdata' are keys added to the array when the element contains both attributes and content.
  2. Ignores content that is not in between it's own set of tags.
  3. Don't know if it recognizes processing instructions nor do I know about processing instructions.
     <\?some_pi some_attr="some_value"?>  This is the same as a document declaration.
  4. Empty elements are not included unless they have attributes.
  5. Version 2.0, Dec. 2, 2011, added xml error reporting.
 
  Usage:
    $domObj = new xmlToArrayParser($xml);
    $elemVal = $domObj->array['element']
    Or assign the entire array to its own variable:
    $domArr = $domObj->array;
    $elemVal = $domArr['element']
 
  Example:
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <top>
      <element1>element content 1</element1>
      <element2 var2="val2" />
      <element3 var3="val3" var4="val4">element content 3</element3>
      <element3 var5="val5">element content 4</element3>
      <element3 var6="val6" />
      <element3>element content 7</element3>
    </top>';
   
    $domObj = new xmlToArrayParser($xml);
    $domArr = $domObj->array;
   
    if($domObj->parse_error) echo $domObj->get_xml_error();
    else print_r($domArr);

    On Success:
    $domArr['top']['element1'] => element content 1
    $domArr['top']['element2']['attrib']['var2'] => val2
    $domArr['top']['element3']['0']['attrib']['var3'] => val3
    $domArr['top']['element3']['0']['attrib']['var4'] => val4
    $domArr['top']['element3']['0']['cdata'] => element content 3
    $domArr['top']['element3']['1']['attrib']['var5'] => val5
    $domArr['top']['element3']['1']['cdata'] => element content 4
    $domArr['top']['element3']['2']['attrib']['var6'] => val6
    $domArr['top']['element3']['3'] => element content 7
   
    On Error:
    Error Code [76] "Mismatched tag", at char 58 on line 3
*
*/
header('Content-type: text/plain; charset="utf8"');

$xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<top>
  <element1>element content 1</element1>
  <element2 var2="val2" />
  <element3 var3="val3" var4="val4">element content 3</element3>
  <element3 var5="val5">element content 4</element3>
  <element3 var6="val6" />
  <element3>element content 7</element3>
</top>';
$domObj = new xmlToArrayParser($xml);
$domArr = $domObj->array;

if($domObj->parse_error) echo $domObj->get_xml_error();
else print_r($domArr);

