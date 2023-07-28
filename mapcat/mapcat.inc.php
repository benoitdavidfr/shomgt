<?php
/* mapcat/mapcat.inc.php - accès au catalogue MapCat
*/
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class Spatial { // transforme une extension spatiale dans le format MapCat en un code JS d'un objet L.geoJSON pour Leaflet 
  protected array $sw; // position SW en LonLatDD
  protected array $ne; // position NE en LonLatDD
  
  private static function LatLonDM2LonLatDD(string $latLonDM): array { // convertit une position LatLonDM en LonLat degrés décimaux
    if (!preg_match("!^(\d+)°((\d\d(,\d+)?)')?(N|S) - (\d+)°((\d\d(,\d+)?)')?(E|W)$!", $latLonDM, $matches))
      throw new Exception("Erreur match sur $latLonDM");
    //echo "<pre>matches = "; print_r($matches); echo "</pre>\n";
    $lat = $matches[1] + ($matches[3] ?  str_replace(',','.', $matches[3])/60 : 0);
    if ($matches[5]=='S') $lat = - $lat;
    if (!preg_match('!^0*([1-9]\d*)?$!', $matches[6], $matches2))
      throw new Exception("Erreur match sur $matches[6]");
    //echo "<pre>matches2 = "; print_r($matches2); echo "</pre>\n";
    $lon = ($matches2[1] ?? 0) + ($matches[8] ? str_replace(',','.', $matches[8])/60 : 0);
    if ($matches[10]=='W') $lon = - $lon;
    //echo "lat=$lat, lon=$lon<br>\n";
    return [$lon, $lat];
  }
  
  function __construct(array $spatial) {
    //$spatial = ['SW'=> "51°00,00'S - 104°00,00'E", 'NE'=> "02°36,26'S - 167°57,92'W"]; // test antiméridien
    $this->sw = self::LatLonDM2LonLatDD($spatial['SW']);
    $this->ne = self::LatLonDM2LonLatDD($spatial['NE']);
    if ($this->ne[0] < $this->sw[0]) { // la boite intersecte l'antiméridien
      $this->ne[0] += 360;
    }
  }
  
  function dcmiBox(): array { // export utilisant les champs définis par le Dublin Core
    return [
      'westlimit' => $this->sw[0],
      'southlimit'=> $this->sw[1],
      'eastlimit' => $this->ne[0],
      'northlimit'=> $this->ne[1],
    ];
  }
  
  private function nw(): array { return [$this->sw[0], $this->ne[1]]; }
  private function se(): array { return [$this->ne[0], $this->sw[1]]; }
  
  private function shift(float $dlon): self { // créée une nouvelle boite décalée de $dlon
    $shift = clone $this;
    $shift->sw[0] += $dlon;
    $shift->ne[0] += $dlon;
    return $shift;
  }
  
  private function ring(): array { return [$this->nw(), $this->ne, $this->se(), $this->sw, $this->nw()]; } // liste de positions
  
  // A linear ring MUST follow the right-hand rule with respect to the area it bounds,
  // i.e., exterior rings are clockwise, and holes are counterclockwise.
  private function multiPolygon(): array { // génère un MultiPolygone GeoJSON 
    if ($this->ne[0] < 180) { // cas standard
      return [
        'type'=> 'MultiPolygon',
        'coordinates'=> [[ $this->ring() ]],
      ];
    }
    else { // la boite intersecte l'antiméridien => duplication de l'autre côté
      return [
        'type'=> 'MultiPolygon',
        'coordinates'=> [[ $this->ring() ], [ $this->shift(-360)->ring() ]],
      ];
      
    }
  }
  
  private function layer(string $popupContent): array { // génère une FeatureCollection GeoJson contenant le multiPolygone
    return [
      'type'=> 'FeatureCollection',
      'features'=> [[
        'type'=> 'Feature',
        'geometry'=> $this->multiPolygon(),
        'properties'=> [
          'popupContent'=> $popupContent,
        ],
      ]],
    ];
  }
  
  function lgeoJSON0(): string { // génère un objet L.geoJSON - modèle avec constante
    return <<<EOT
  L.geoJSON(
          { "type": "MultiPolygon",
            "coordinates": [
               [[[ 180.0,-90.0 ],[ 180.1,-90.0 ],[ 180.1,90.0],[ 180.0,90.0 ],[ 180.0,-90.0 ] ] ],
               [[[-180.0,-90.0 ],[-180.1,-90.0 ],[-180.1,90.0],[-180.0,90.0 ],[-180.0,-90.0 ] ] ]
            ]
          },
          { style: { "color": "red", "weight": 2, "opacity": 0.65 } });

EOT;
  }
  function lgeoJSON(array $style, string $popupContent): string { // retourne le code JS génèrant l'objet L.geoJSON
    return
      sprintf('L.geoJSON(%s,{style: %s, onEachFeature: onEachFeature});',
        json_encode($this->layer($popupContent)),
        json_encode($style))
      ."\n";
  }

  static function test(): void {
    echo "Spatial::test()<br>\n";
    if (1) { // test de LatLonDM2LonLatDD
      foreach (["42°39,93'N - 9°00,93'E", "42°39'N - 9°00'E", "42°N - 9°E", "44°09,00'N - 002°36,00'W", 
                "45°49,00'N - 001°00,00'W", "50°40,95'N - 000°54,92'E", "00°40,95'S - 000°54,92'E"] as $spatial) {
        $lonLat = self::LatLonDM2LonLatDD($spatial);
        echo "<pre>LatLonDM2LonLatDD($spatial) -> [$lonLat[0], $lonLat[1]]</pre>\n";
      }
    }
    elseif (1) {
      echo "<pre>";
      $spatial = new Spatial(['SW'=>"42°39,93'N - 9°00,93'E", 'NE'=> "42°39'N - 9°00'E"]);
      print_r($spatial->multiPolygon());
      $spatial = new Spatial(['SW'=> "51°00,00'S - 104°00,00'E", 'NE'=> "02°36,26'S - 167°57,92'W"]);
      print_r($spatial->multiPolygon());
    }
    die("Fin ok ligne ".__LINE__."\n");
  }
};
//Spatial::test();

class MapCat { // Un objet MapCat correspond à l'entrée du catalogue correspondant à une carte
  protected array $cat=[]; // contenu de l'entrée du catalogue correspondant à une carte
  static array $maps=[]; // contenu du champ maps de MapCat
  static array $obsoleteMaps=[]; // contenu du champ obsoleteMaps de MapCat
  
  // retourne l'entrée du catalogue correspondant à $mapNum sous la forme d'un objet MapCat
  function __construct(string $mapNum, bool $obsolete=true) {
    if (!self::$maps) {
      $mapCat = self::$maps = Yaml::parseFile(__DIR__.'/mapcat.yaml');
      self::$maps = $mapCat['maps'];
      self::$obsoleteMaps = $mapCat['obsoleteMaps'];
    }
    $this->cat = self::$maps["FR$mapNum"] ?? [];
    if (!$this->cat && $obsolete) { // Je cherche la carte dans les cartes obsolètes
      $cat = self::$obsoleteMaps["FR$mapNum"] ?? [];
      if ($cat) {
        //print_r($cat);
        $obsoleteDate = array_keys($cat)[count($cat)-1];
        $this->cat = array_merge(['obsoleteDate'=> $obsoleteDate], $cat[$obsoleteDate]);
      }
    }
  }
  
  function empty(): bool { return ($this->cat == []); }
    
  function __get(string $property) { return $this->cat[$property] ?? null; }
  
  function asArray(): array { return $this->cat; }
  
  function spatials(): array { // retourne la liste des extensions spatiales sous la forme [nom => Spatial]
    $spatials = $this->spatial ? ['image principale de la carte'=> new Spatial($this->spatial)] : [];
    //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
    foreach ($this->insetMaps ?? [] as $i => $insetMap) {
      $spatials[$insetMap['title']] = new Spatial($insetMap['spatial']);
    }
    return $spatials;
  }

  function insetTitlesSorted(): array { // retourne la liste des titres des cartouches triés
    if (!$this->insetMaps) return [];
    $insetTitles = [];
    foreach ($this->insetMaps as $insetMap) {
      $insetTitles[] = $insetMap['title'];
    }
    sort($insetTitles);
    return $insetTitles;
  }
};
