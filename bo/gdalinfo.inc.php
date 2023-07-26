<?php
// gdalinfo.inc.php - fourniture d'un gdalinfo d'un fichier .tif ou .pdf - 23/7/2023

{/*PhpDoc: classes
name: GBox
title: class GBox - Gestion d'une BBox en coord. géo., chaque position codée comme [lon, lat]
doc: |
  Par convention, on cherche à respecter:
    (-180 <= lon <= 180) && (-90 <= lat <= 90)
  sauf pour les boites à cheval sur l'antiméridien où:
    (-180 <= lonmin <= 180 < lonmax <= 180+360 )
  Cette convention est différente de celle utilisée par GeoJSON.
  Toutefois, uGeoJSON génère des bbox avec des coord. qqc, y compris lonmin < -180
*/}
class GBox { 
  protected array $min=[]; // position SW en LonLat
  protected array $max=[]; // position NE en LonLat
  
  function __construct(array $polygon=[]) {
    if (!$polygon) return;
    /* Je fais l'hypothèse que l'ordre des positions dans le polygone est le même dans gdalinfo que dans cornerCoordinates
    ** à savoir: upperLeft, lowerLeft, lowerRight, upperRight, upperLeft
    ** Si ((lowerLeft[0] > 0) && (lowerRight[0] < 0)) cela indique que la boite est à cheval sur l'antiméridien
    */
    $lpos = $polygon['coordinates'][0]; // la liste des positions du polygone
    $this->min = $lpos[1];
    if (($lpos[1][0] > 0) && ($lpos[2][0] < 0)) { // boite est à cheval sur l'antéméridien
      $this->max = [$lpos[3][0]+360, $lpos[3][1]];
    }
    else { // la boite n'est PAS à cheval sur l'antiméridien
      $this->max = $lpos[3];
    }
  }
    
  function bound(array $pos): void { // agrandit le bbox avec la position $pos en LonLat
    if (!$this->min) {
      $this->min = $pos;
      $this->max = $pos;
    }
    else {
      if ($pos[0] < $this->min[0]) $this->min[0] = $pos[0];
      if ($pos[1] < $this->min[1]) $this->min[1] = $pos[1];
      if ($pos[0] > $this->max[0]) $this->max[0] = $pos[0];
      if ($pos[1] > $this->max[1]) $this->max[1] = $pos[1];
    }
  }
  
  function union(GBox $gbox): GBox { // retourne l'union des 2 bbox
    if (!$gbox->min) {
      return $this;
    }
    $union = $this;
    $union->bound($gbox->min);
    $union->bound($gbox->max);
    return $union;
  }
  
  function latLngBounds(): array { // retourne un array de 2 positions en LatLng pour LeafLet
    return [[$this->min[1], $this->min[0]], [$this->max[1], $this->max[0]]];
  }
};

class Gdalinfo { // contenu du gdalinfo d'un fichier .tif ou .pdf
  const CRS_WKT_PATTERN =
    '!^PROJCRS{"WGS 84 / World Mercator",\s*'
      .'BASEGEOGCRS{"WGS 84",\s*'
        .'DATUM{"World Geodetic System 1984",\s*ELLIPSOID{"WGS 84",6378137,298.2572\d*,\s*LENGTHUNIT{"metre",1}}},\s*'
        .'PRIMEM{"Greenwich",0,\s*ANGLEUNIT{"degree",0.0174532925199433}},\s*'
        .'ID{"EPSG",4326}},\s*'
      .'CONVERSION{"Mercator \(variant [AB]\)",\s*'
        .'METHOD{"Mercator \(variant [AB]\)",\s*ID{"EPSG",980[45]}},\s*'
        .'PARAMETER{"Latitude of [^"]*",0,\s*ANGLEUNIT{"degree",0.0174532925199433},\s*ID{"EPSG",\d*}},\s*'
        .'PARAMETER{"Longitude of natural origin",0,\s*ANGLEUNIT{"degree",0.0174532925199433},\s*ID{"EPSG",8802}},\s*'
        .'(PARAMETER{"Scale factor at natural origin",1,\s*SCALEUNIT{"unity",1},\s*ID{"EPSG",8805}},\s*)?'
        .'PARAMETER{"False easting",0,\s*LENGTHUNIT{"metre",1},\s*ID{"EPSG",8806}},\s*'
        .'PARAMETER{"False northing",0,\s*LENGTHUNIT{"metre",1],\s*ID{"EPSG",8807}}},\s*'
      .'CS{Cartesian,2},\s*'
      .'AXIS{"easting",east,\s*ORDER{1},\s*LENGTHUNIT{"metre",1}},\s*'
      .'AXIS{"northing",north,\s*ORDER{2},\s*LENGTHUNIT{"metre",1}},\s*'
      .'ID{"EPSG",3395}}\s*'
    .'$!';
  protected array $info; // contenu du gdalinfo
  
  function __construct(string $path) {
    $cmde = "gdalinfo -json $path";
    //echo "$cmde<br>\n";
    exec($cmde, $output, $retval);
    //echo "<pre>"; print_r($output); echo "retval=$retval</pre>\n";
    $this->info = json_decode(implode("\n", $output), true);
  }
  
  function asArray(): array { return $this->info; }
  
  // indique si le géoréférencement est absent, correct ou incorrect, retourne
  //  - null si non géoréférencé cad champ 'coordinateSystem' non défini
  //  - 'ok' si géoréférencé correctement cad champ 'coordinateSystem/wkt' correspond au motif
  //  - 'KO' si géoréférencé incorrectement cad champ 'coordinateSystem/wkt' ne correspond pas au motif
  function georef(): ?string {
    if (!isset($this->info['coordinateSystem']))
      return null;
    $pattern = str_replace(['{','}'], ['\[','\]'], self::CRS_WKT_PATTERN);
    $georef = preg_match($pattern, $this->info['coordinateSystem']['wkt']) ? 'ok' : 'KO';
    //echo "georef=$georef\n";
    return $georef;
  }
  
  function gbox(): ?GBox { // retourne le GBox ssi il est défini dans le gdalinfo
    if (!isset($this->info['wgs84Extent']))
      return null;
    else
      return new GBox($this->info['wgs84Extent']);
  }

  static function test(string $path7z, string $entry): void {
    $archive = new SevenZipArchive($path7z);
    $archive->extractTo(__DIR__.'/temp', $entry);
    $gdalInfo = new GdalInfo(__DIR__."/temp/$entry");
    //print_r($gdalInfo);
    echo $gdalInfo->georef(),"\n";
  }
};

if ((php_sapi_name() == 'cli') && ($argv[0]=='gdalinfo.inc.php')) {
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  if (0)
    GdalInfo::test("$PF_PATH/incoming/20230628aem/7330.7z", '7330/7330_2019.pdf');
  elseif (1)
    GdalInfo::test("$PF_PATH/attente/20230628aem/8502.7z", '8502/8502CXC_Ed2_2023.tif');
}