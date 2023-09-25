<?php
/**
 * changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
 *
 * Objectif d'effectuer simplement des changements de projection sur un même ellipsoide.
 * Fonctions (long,lat) -> (x,y) et inverse
 * Implémente les projections Lambert93, WebMercator, WorldMercator et UTM sur l'ellipsoide IAG_GRS_1980 par défaut.
 * Le Web Mercator est défini dans:
 * http://earth-info.nga.mil/GandG/wgs84/web_mercator/(U)%20NGA_SIG_0011_1.0.0_WEBMERC.pdf
 *
 * La structuration informatique choisie consiste à définir les projections comme des classes portant des méthodes
 * proj() pour projeter des coordonnées géographiques en coordonnées cartésiennes
 * et à l'inverse geo() pour calculer les coordonnées géographiques à partir des coordonnées cartésiennes.
 * Cette structuration permet notamment de ne pas avoir à créer un objet particulier pour effectuer un changement
 * de coordonnées et d'utiliser facilement ces changements dans gegeom.inc.php où le changement prend en paramètre
 * une fonction d'un système dans un autre. Elle permet ainsi une bonne indépendance entre le présent module
 * et la définition des primitives géométriques dans gegeom.inc.php
 *
 * Ces projections héritent d'une classe définissant l'ellipsoide sur lequel elles sont définies.
 * Ces ellipsoides sont définis par 2 classes.
 *  - soit la classe IAG_GRS_1980 qui définit l'ellipsoide du même nom,
 *  - soit la classe Ellipsoid qui peut être paramétrée pour différents ellipsoides.
 *  
 * Si une projection définit l'ellipsoide sur lequel elle s'applique, comme par exemple Lambert93 ou WebMercator,
 * alors la classe définissant cette projection hérite de la classe définissant cet ellipsoide.
 * A l'inverse, si la projection peut être définie pour différents ellipsoides, comme par exemple UTM ou WorldMercator,
 * alors la classe définissant cette projection hérite de la classe Ellipsoid qui peut ainsi être paramétrée.
 *
 * Utilisation d'exceptions étendues avec un code string dont la valeur reprend le nom de la constante.
 * 
 * Pour calculer des surfaces, ajouter la projection sinusoidale qui est unique et équivalente (conserve localement
 * les surfaces)
 * https://fr.wikipedia.org/wiki/Projection_sinuso%C3%AFdale
 * Voir ~/html/geovect/coordsys/light.inc.php
 *
 * journal: |
 * - 5-10/2/2022:
 *   - Ajout d'une exception dans les projections WebMercator et WorldMercator lorsque la latitude est < -85 ou > 85
 *   - Transformation des Exception en \SExcept et fourniture d'un code de type string
 *   - Amélioration de la doc
 * - 18/3/2019:
 *   - Modification du code pour permettre de calculer les projections sur différents Ellipsoides.
 *   - Cette version ne permet pas d'effectuer un chagt d'ellipsoide.
 * - 3/3/2019:
 *   - fork de ~/html/geometry/coordsys.inc.php, passage en v3
 *   - modification des interfaces pour utiliser systématiquement des positions [X, Y] ou [longitude, latitude] en degrés décimaux
 *   - modification des interfaces d'UTM, la zone est un paramètre supplémentaire, ajout de ma méthode zone()
 *   - La détection de WKT est transférée dans une classe spécifique.
 * - 4/11/2018:
 *   - chgt du code WM en WebMercator
 *   - ajout de WorldMercator sous le code WorldMercator
 * - 22/11/2017:
 *   - intégration dans geometry
 * - 14-15/12/2016
 *   - ajout de l'UTM
 *   - chgt de l'organisation des classes et de l'interface
 *   - passage en v2
 * - 14/11/2016
 *   - correction d'un bug
 * - 12/11/2016
 *   - ajout de wm2geo() et geo2wm()
 * - 26/6/2016
 *   - ajout de chg pour améliorer l'indépendance de ce module avec geom2d.inc.php
 * - 23/6/2016
 *   - première version
 * @package coordsys
 */
namespace coordsys;

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/sexcept.inc.php';

/**
 * interface de définition d'un ellipsoide
 *
 * La présente interface spécifie comment un ellipsoide doit être défini, à savoir pouvoir restituer les 3 paramètres:
 *    - demi grand axe (semi-major axis) en mètres
 *    - excentricité (eccentricity)
 *    - excentricité au carré
 *  Le calcul de ces paramètres dépend des paramètres stockés.
 *  
 *  Terminologie:
 *    a : semi-major axis
 *    b : semi-minor axis
 *    flattening : f = ( a − b ) / a
 *    eccentricity : e ** 2 = 1 - (1 - flattening) ** 2
 *    e ** 2 = 1 - b ** 2 / a ** 2
 */
interface iEllipsoid {
  static function a(): float; // semi-major axis / demi grand axe
  static function e2(): float; // eccentricity ** 2
  static function e(): float; // eccentricity
};

/**
 * interface de définition d'un système de coordonnées
 *
 * Un système de coordonnées doit savoir convertir une pos. géographique (longitude, latitude) définie en degrés déc.
 * en coordonnées projetées [X, Y] dans l'espace euclidien, et vice-versa.
 */
interface iCoordSys {
  /**
   * convertit une position géographique (longitude, latitude) en degrés déc. en coordonnées projetées [X, Y]
   *
   * @param TPos $lonlat position géographique (longitude, latitude) en degrés déc.
   * @param ?string $proj utilisé s'il est nécessaire de préciser le système de coordonnées, par exemple en UTM la zone
   * @return TPos coordonnées projetées [X, Y]
   */
  static function proj(array $lonlat, ?string $proj=null): array;

  /**
   * convertit des coordonnées projetées [X, Y] en position géographique [longitude, latitude] en degrés décimaux
   *
   * @param TPos $xy coordonnées projetées [X, Y]
   * @param ?string $proj utilisé s'il est nécessaire de préciser le système de coordonnées, par exemple en UTM la zone
   * @return TPos position géographique (longitude, latitude) en degrés déc.
   */
  static function geo(array $xy, ?string $proj=null): array;
};
  
/** définition de l'ellipsoide IAG_GRS_1980 */
class IAG_GRS_1980 implements iEllipsoid {
  const PARAMS = [
    'title'=> "Ellipsoide GRS (Geodetic Reference System) 1980 défini par l'IAG (Int. Association of Geodesy)",
    'epsg'=> 'EPSG:7019',
    'comment'=> "Ellipsoide international utilisé notamment pour RGF93, Lambert 93, ETRS89, ...",
    'a'=> 6378137.0, // Demi grand axe de l'ellipsoide - en anglais Equatorial radius - en mètres
    'f' => 1/298.2572221010000, // aplatissement (en: inverse flattening) = (a - b) / a, 
  ];
    
  static function a(): float { return self::PARAMS['a']; }
  static function e2(): float { return 1 - pow(1 - self::PARAMS['f'], 2); }
  static function e(): float { return sqrt(self::e2()); }
};

/** définition du système de coordonnées Lambert 93 défini sur l'ellipsoide IAG_GRS_1980 */
class Lambert93 extends IAG_GRS_1980 implements iCoordSys {
  const c = 11754255.426096; //constante de la projection
  const n = 0.725607765053267; //exposant de la projection
  const xs = 700000; //coordonnées en projection du pole
  const ys = 12655612.049876; //coordonnées en projection du pole
  
  /** convertit une pos. (longitude, latitude) en degrés déc. en [X, Y] */
  static function proj(array $lonlat, ?string $proj=null): array {
    list($longitude, $latitude) = $lonlat;
    // définition des constantes
    $e = self::e(); //première exentricité de l'ellipsoïde

    // pré-calculs
    $lat_rad= $latitude/180*PI(); //latitude en rad
    $lat_iso= atanh(sin($lat_rad))-$e*atanh($e*sin($lat_rad)); //latitude isométrique

    //calcul
    $x = ((self::c * exp(-self::n * $lat_iso)) * sin(self::n * ($longitude-3)/180*pi()) + self::xs);
    $y = (self::ys - (self::c*exp(-self::n*($lat_iso))) * cos(self::n * ($longitude-3)/180*pi()));
    return [$x,$y];
  }
  
  /** retourne [longitude, latitude] en degrés décimaux */
  static function geo(array $xy, ?string $proj=null): array {
    list($X, $Y) = $xy;
    $e = self::e(); // 0.0818191910428158; //première exentricité de l'ellipsoïde

    // pré-calcul
    $a = (log(self::c/(sqrt(pow(($X-self::xs),2)+pow(($Y-self::ys),2))))/self::n);

    // calcul
    $longitude = ((atan(-($X-self::xs)/($Y-self::ys)))/self::n+3/180*PI())/PI()*180;
    $latitude = asin(tanh(
                  (log(self::c/sqrt(pow(($X-self::xs),2)+pow(($Y-self::ys),2)))/self::n)
                 + $e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*sin(1))))))))))))))))))))
                 ))/PI()*180;
    return [ $longitude , $latitude ];
  }
};
  
/** définition du système de coordonnées Web Mercator
 *
 * WebMercator est défini sur une sphère ayant comme rayon le demi grand axe de l'ellipsoide IAG_GRS_1980 */
class WebMercator extends IAG_GRS_1980 implements iCoordSys {
  const ErrorBadLat = 'WebMercator::ErrorBadLat';
  /** correspond à la latitude min pour que la projection soit un carré de largeur 2*pi*a */
  const MinLat = -85.051129;
  /** correspond à la latitude max pour que la projection soit un carré de largeur 2*pi*a */
  const MaxLat =  85.051129;
  
  /** couverture spatiale en degrés décimaux lon, lat
   * @return list<float> */
  static function spatial(): array { return [-180, self::MinLat, 180, self::MaxLat]; }
  
  /** convertit une pos. (longitude, latitude) en degrés déc. en [X, Y] */
  static function proj(array $lonlat, ?string $proj=null): array {
    if (($lonlat[1] < self::MinLat) || ($lonlat[1] > self::MaxLat))
      throw new \SExcept("latitude incorrecte (< MinLat || > MaxLat) dans WebMercator::proj()", self::ErrorBadLat);
    $lambda = $lonlat[0] * pi() / 180.0; // longitude en radians
    $phi = $lonlat[1] * pi() / 180.0;  // latitude en radians
	  
    $x = self::a() * $lambda; // (7-1)
    $y = self::a() * log(tan(pi()/4 + $phi/2)); // (7-2)
    return [$x, $y];
  }
  
  /** convertit des coordonnées Web Mercator en [longitude, latitude] en degrés décimaux
   * @param TPos $xy */
  static function geo(array $xy, ?string $proj=null): array {
    list($X, $Y) = $xy;
    $phi = pi()/2 - 2*atan(exp(-$Y/self::a())); // (7-4)
    $lambda = $X / self::a(); // (7-5)
    return [ $lambda / pi() * 180.0 , $phi / pi() * 180.0 ];
  }
};

/** définition du système de coordonnées LonLatDd correspondant aux coord. géo. en degrés décimaux dans l'ordre (lon,lat) */
class LonLatDd extends IAG_GRS_1980 implements iCoordSys {
  static function proj(array $lonlat, ?string $proj=null): array { return $lonlat; }
  static function geo(array $xy, ?string $proj=null): array { return $xy; }
};

/** définition du système de coordonnées LatLonDd correspond aux coord. géo. en degrés décimaux dans l'ordre (lat,lon) */
class LatLonDd extends IAG_GRS_1980 implements iCoordSys {
  static function proj(array $lonlat, ?string $proj=null): array { return [$lonlat[1], $lonlat[0]]; }
  static function geo(array $xy, ?string $proj=null): array { return [$xy[1], $xy[0]]; }
};

/** définition d'un ellipsoide paramétrable
 *
 * La classe porte d'une part les constantes définissant différents ellipsoides et, d'autre part,
 * la définition d'un ellipsoide courant. Par défaut utilisation de l'ellipsoide IAG_GRS_1980.
 *
 * L'ellipsoide de Clarke 1866 est sélectionné pour tester l'exemple USGS sur UTM.
 *
 * D'autres ellipsoides peuvent être ajoutés au besoin.
 * https://en.wikipedia.org/wiki/Earth_ellipsoid */
class Ellipsoid implements iEllipsoid {
  const ErrorUndef = 'Ellipsoid::ErrorUndef';
  const DEFAULT = 'IAG_GRS_1980'; // ellipsoide par défaut IAG_GRS_1980
  /** constante définissant différents ellipsoides */
  const PARAMS = [
    'IAG_GRS_1980'=> IAG_GRS_1980::PARAMS,
    'WGS-84'=> [
      'title'=> "Ellipsoide WGS-84 utilisé pour le GPS, quasiment identique à l'IAG_GRS-1980",
      'epsg'=> 'EPSG:4326',
      'a'=> 6378137.0, // Demi grand axe de l'ellipsoide - en anglais Equatorial radius - en mètres
      'f' => 1/298.257223563, // aplatissement (en: flatening) = (a - b) / a
    ],
    'Clarke1866'=> [
      'title'=> "Ellipsoide Clarke 1866",
      'epsg'=> 'EPSG:7008',
      'comment'=> "Ellipsoide utilisé pour le système géodésique North American Datum 1927 (NAD 27) utilisé aux USA",
      'a'=> 6378206.4, // Demi grand axe de l'ellipsoide
      'b'=> 6356583.8, // Demi petit axe
      'f'=> 1/294.978698214, // aplatissement (en: flatening) = (a - b) / a
    ],
  ];
  
  /** ellipsoide courant, par défaut IAG_GRS_1980 */
  static string $current = self::DEFAULT;
  
  /** retourne la liste des ellipsoides proposés
   * @return list<string> */
  static function available(): array { return array_keys(self::PARAMS); }
  
  /** retourne l'ellipsoide courant */
  static function current(): string { return self::$current; }
  
  /** Définition d'un ellipsoide */
  static function set(string $ellipsoid=self::DEFAULT): void {
    if (isset(self::PARAMS[$ellipsoid]))
      self::$current = $ellipsoid;
    else
      throw new \SExcept("Erreur dans Ellipsoid::set($ellipsoid): ellipsoide non défini", self::ErrorUndef);
  }
  
  /** retourne la valeur d'un paramètre stocké pour l'ellipsoide courant */
  private static function param(string $name): ?float { return self::PARAMS[self::$current][$name] ?? null; }
  
  static function a(): float { return self::param('a'); }
  
  static function e2(): float { return 1 - pow(1 - self::param('f'), 2); }
  
  static function e(): float { return sqrt(self::e2()); }
};

/** définition de la projection de Mercator et du système de coordonnées WorldMercator défini sur l'ellipsoide IAG_GRS_1980. */
class WorldMercator extends Ellipsoid implements iCoordSys {
  const ErrorBadLat = 'WorldMercator::ErrorBadLat';
  const ErrorNoConvergence = 'WorldMercator::ErrorNoConvergence';
  /** tolerance de convergence du calcul de la latitude */
  const EPSILON = 1E-11; 
  const MinLat = -85.08405905; // Lat / dist([0,Ø],[0,Lat]) == dist([0,0][-180,0])
  const MaxLat =  85.08405905;
  
  /** couverture spatiale en degrés décimaux lon, lat
   * @return list<float> */
  static function spatial(): array { return [-180, self::MinLat, 180, self::MaxLat]; }

  /** convertit une pos. (longitude, latitude) en degrés déc. en [X, Y] */
  static function proj(array $lonlat, ?string $proj=null): array {
    if (($lonlat[1] < self::MinLat) || ($lonlat[1] > self::MaxLat))
      throw new \SExcept ("latitude incorrecte (< MinLat || > MaxLat) dans WorldMercator::proj()", self::ErrorBadLat);
    $lambda = $lonlat[0] * pi() / 180.0; // longitude en radians
    $phi = $lonlat[1] * pi() / 180.0;  // latitude en radians
    $e = self::e(); //première exentricité de l'ellipsoïde
    $x = self::a() * $lambda; // (7-6)
    $y = self::a() * log(tan(pi()/4 + $phi/2) * pow((1-$e*sin($phi))/(1+$e*sin($phi)),$e/2)); // (7-7)
    return [$x, $y];
  }
    
  /**- prend des coord; World Mercator et retourne [longitude, latitude] en degrés */
  static function geo(array $xy, ?string $proj=null): array {
    list($X, $Y) = $xy;
    $t = exp(-$Y/self::a()); // (7-10)
    $phi = pi()/2 - 2 * atan($t); // (7-11)
    $lambda = $X / self::a(); // (7-12)
    $e = self::e();

    $nbiter = 0;
    while ($nbiter++ < 20) {
      $phi0 = $phi;
      $phi = pi()/2 - 2*atan($t * pow((1-$e*sin($phi))/(1+$e*sin($phi)),$e/2)); // (7-9)
      if (abs($phi-$phi0) < self::EPSILON)
        return [ $lambda / pi() * 180.0 , $phi / pi() * 180.0 ];
    }
    throw new \SExcept("Convergence inachevee dans WorldMercator::geo() pour nbiter=$nbiter", self::ErrorNoConvergence);
  }
};


/** définition des systèmes de coordonnées UTM zone
 *
 * La projection UTM est définie par zone correspondant à un fuseau de 6 degrés en séparant l’hémisphère Nord du Sud.
 * Soit au total 120 zones (60 pour le Nord et 60 pour le Sud).
 * Cette zone est définie sur 3 caractères, les 2 premiers indiquant le no de fuseau et le 3ème N ou S.
 * La projection UTM peut être définie sur différents ellipsoides.
 * L'exemple USGS utilise l'ellipsoide de Clarke 1866.
 */
class UTM extends Ellipsoid implements iCoordSys {
  const k0 = 0.9996;
  
  static function lambda0(int $nozone): float { return (($nozone-30.5)*6)/180*pi(); } // en radians
  
  static function Xs(): float { return 500000; }
  static function Ys(string $NS): float { return $NS=='S'? 10000000 : 0; }
  
  // distanceAlongMeridianFromTheEquatorToLatitude (3-21)
  static function distanceAlongMeridianFromTheEquatorToLatitude(float $phi): float {
    $e2 = self::e2();
    return (self::a())
         * (   (1 - $e2/4 - 3*$e2*$e2/64 - 5*$e2*$e2*$e2/256)*$phi
             - (3*$e2/8 + 3*$e2*$e2/32 + 45*$e2*$e2*$e2/1024)*sin(2*$phi)
             + (15*$e2*$e2/256 + 45*$e2*$e2*$e2/1024) * sin(4*$phi)
             - (35*$e2*$e2*$e2/3072)*sin(6*$phi)
           );
  }
  
  /** (longitude, latitude) en degrés -> zone UTM
   * @param TPos $pos */
  static function zone(array $pos): string { return sprintf('%02d',floor($pos[0]/6)+31).($pos[1]>0?'N':'S'); }
 
  /** (lon, lat) en degrés déc. -> [X, Y] en UTM zone */
  static function proj(array $lonlat, ?string $zone=null): array {
    list($longitude, $latitude) = $lonlat;
    $nozone = (int)substr($zone, 0, 2);
    $NS = substr($zone, 2);
    // echo "lambda0 = ",$this->lambda0()," rad = ",$this->lambda0()/pi()*180," degres\n";
    $e2 = self::e2();
    $lambda = $longitude * pi() / 180.0; // longitude en radians
    $phi = $latitude * pi() / 180.0;  // latitude en radians
    $ep2 = $e2/(1 - $e2);  // echo "ep2=$ep2 (8-12)\n"; // (8-12)
    $N = self::a() / sqrt(1 - $e2*pow(sin($phi),2)); // echo "N=$N (4-20)\n"; // (4-20)
    $T = pow(tan($phi),2); // echo "T=$T (8-13)\n"; // (8-13)
    $C = $ep2 * pow(cos($phi),2); // echo "C=$C\n"; // (8-14)
    $A = ($lambda - self::lambda0($nozone)) * cos($phi); // echo "A=$A\n"; // (8-15)
    $M = self::distanceAlongMeridianFromTheEquatorToLatitude($phi); // echo "M=$M\n"; // (3-21)
    $M0 = self::distanceAlongMeridianFromTheEquatorToLatitude(0); // echo "M0=$M0\n"; // (3-21)
    $x = (self::k0) * $N * ($A + (1-$T+$C)*pow($A,3)/6 + (5-18*$T+pow($T,2)+72*$C-58*$ep2)*pow($A,5)/120); // (8-9)
    //echo "x = ",($this->k0)," * $N * ($A + (1-$T+$C)*pow($A,3)/6 + (5-18*$T+pow($T,2)+72*$C-58*$ep2)*pow($A,5)/120)\n";
    //echo "x = $x\n";
    $y = (self::k0) * ($M - $M0 + $N * tan($phi) * ($A*$A/2 + (5 - $T + 9*$C +4*$C*$C)
        * pow($A,4)/24 + (61 - 58*$T + $T*$T + 600*$C - 330*$ep2) * pow($A,6)/720));                    // (8-10)
    // echo "y = ($this->k0) * ($M - $M0 + $N * tan($phi) * ($A*$A/2 + (5 - $T + 9*$C +4*$C*$C)
    //          * pow($A,4)/24 + (61 - 58*$T + $T*$T + 600*$C - 330*$ep2) * pow($A,6)/720))\n";
    $k = (self::k0) * (1 + (1 + $C)*$A*$A/2 + (5 - 4*$T + 42*$C + 13*$C*$C - 28*$ep2)*pow($A,4)/24
         + (61 - 148*$T +16*$T*$T)*pow($A,6)/720);                                                    // (8-11)
    return [$x + self::Xs(), $y + self::Ys($NS)];
  }
    
  /** coord. UTM zone -> [lon, lat] en degrés */
  static function geo(array $xy, ?string $zone=null): array {
    list($X, $Y) = $xy;
    $nozone = (int)substr($zone, 0, 2);
    $NS = substr($zone, 2);
    $e2 = self::e2();
    $x = $X - self::Xs();
    $y = $Y - self::Ys($NS);
    $M0 = self::distanceAlongMeridianFromTheEquatorToLatitude(0); // echo "M0=$M0\n"; // (3-21)
    $ep2 = $e2/(1 - $e2); // echo "ep2=$ep2\n"; // (8-12)
    $M = $M0 + $y/self::k0; // echo "M=$M\n"; // (8-20)
    $e1 = (1 - sqrt(1-$e2)) / (1 + sqrt(1-$e2)); // echo "e1=$e1\n"; // (3-24)
    $mu = $M/(self::a() * (1 - $e2/4 - 3*$e2*$e2/64 - 5*$e2*$e2*$e2/256)); // echo "mu=$mu\n"; // (7-19)
    $phi1 = $mu + (3*$e1/2 - 27*pow($e1,3)/32)*sin(2*$mu) + (21*$e1*$e1/16
                - 55*pow($e1,4)/32)*sin(4*$mu) + (151*pow($e1,3)/96)*sin(6*$mu)
                + 1097*pow($e1,4)/512*sin(8*$mu); // echo "phi1=$phi1 radians = ",$phi1*180/pi(),"°\n"; // (3-26)
    $C1 = $ep2*pow(cos($phi1),2); // echo "C1=$C1\n"; // (8-21)
    $T1 = pow(tan($phi1),2); // echo "T1=$T1\n"; // (8-22)
    $N1 = self::a()/sqrt(1-$e2*pow(sin($phi1),2)); // echo "N1=$N1\n"; // (8-23)
    $R1 = self::a()*(1-$e2)/pow(1-$e2*pow(sin($phi1),2),3/2); // echo "R1=$R1\n"; // (8-24)
    $D = $x/($N1*self::k0); // echo "D=$D\n"; 
    $phi = $phi1 - ($N1 * tan($phi1)/$R1) * ($D*$D/2 - (5 + 3*$T1 + 10*$C1 - 4*$C1*$C1 -9*$ep2)*pow($D,4)/24
         + (61 + 90*$T1 + 298*$C1 + 45*$T1*$T1 - 252*$ep2 - 3*$C1*$C1)*pow($D,6)/720); // (8-17)
    $lambda = self::lambda0($nozone) + ($D - (1 + 2*$T1 + $C1)*pow($D,3)/6 + (5 - 2*$C1 + 28*$T1
               - 3*$C1*$C1 + 8*$ep2 + 24*$T1*$T1)*pow($D,5)/120)/cos($phi1); // (8-18)
    return [ $lambda / pi() * 180.0, $phi / pi() * 180.0 ];
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


/** Transformation d'une valeur en radians en une chaine en degres sexagesimaux
 *
 * si ptcardinal est fourni alors le retour respecte la notation avec point cardinal
 * sinon c'est la notation signee qui est utilisee
 * dr est la precision de r
 */
function radians2degresSexa(float $r, string $ptcardinal='', float $dr=0): string {
  $signe = '';
  if ($r < 0) {
    if ($ptcardinal) {
      if ($ptcardinal == 'N')
        $ptcardinal = 'S';
      elseif ($ptcardinal == 'E')
        $ptcardinal = 'W';
      elseif ($ptcardinal == 'S')
        $ptcardinal = 'N';
      else
        $ptcardinal = 'E';
    } else
      $signe = '-';
    $r = - $r;
  }
  $deg = $r / pi() * 180;
  $min = ($deg - floor($deg)) * 60;
  $sec = ($min - floor($min)) * 60;
  if ($dr == 0) {
    return $signe.sprintf("%d°%d'%.3f''%s", floor($deg), floor($min), $sec, $ptcardinal);
  } else {
    $dr = abs($dr);
    $ddeg = $dr / pi() * 180;
    $dmin = ($ddeg - floor($ddeg)) * 60;
    $dsec = ($dmin - floor($dmin)) * 60;
    $ret = $signe.sprintf("%d",floor($deg));
    if ($ddeg > 0.5) {
      $ret .= sprintf(" +/- %d ° %s", round($ddeg), $ptcardinal);
      return $ret;
    }
    $ret .= sprintf("°%d",floor($min));
    if ($dmin > 0.5) {
      $ret .= sprintf(" +/- %d ' %s", round($dmin), $ptcardinal);
      return $ret;
    }
    $f = floor(log($dsec,10));
    $fmt = '%.'.($f<0 ? -$f : 0).'f';
    return $ret.sprintf("'$fmt +/- $fmt'' %s", $sec, $dsec, $ptcardinal);
  }
};

echo "<html><head><meta charset='UTF-8'><title>coordsys</title></head><body><pre>";

if (!isset($_GET['test'])) {
  echo "<a href='?test=usgs'>Test d'UTM du cas du rapport USGS pp 269-270</a>\n";
  echo "<a href='?test=ign'>Test sur des points IGN connus dans plusieurs systèmes de coordonnées</a>\n";
  echo "<a href='?test=merc'>Tests Mercator</a>\n";
  echo "<a href='?test=lwemerc'>Tests limites WebMercator</a>\n";
  echo "<a href='?test=lwomerc'>Tests limites WorldMercator</a>\n";
  die();
}

elseif ($_GET['test']=='usgs') { // Test d'UTM fondé sur le cas du rapport USGS pp 269-270
  echo "Example du rapport USGS pp 269-270 utilisant l'Ellipsoide de Clarke 1866\n";
  Ellipsoid::set('Clarke1866');
  $pt = [-73.5, 40.5];
  echo "phi=",radians2degresSexa($pt[1]/180*PI(),'N'),", lambda=", radians2degresSexa($pt[0]/180*PI(),'E'),"\n";
  $utm = UTM::proj($pt, '18N');
  echo "UTM: X=$utm[0] / 127106.5, Y=$utm[1] / 4,484,124.4\n";
  
  $verif = UTM::geo($utm, '18N');
  echo "phi=",radians2degresSexa($verif[1]/180*PI(),'N')," / ",radians2degresSexa($pt[1]/180*PI(),'N'),
       ", lambda=", radians2degresSexa($verif[0]/180*PI(),'E')," / ", radians2degresSexa($pt[0]/180*PI(),'E'),"\n";
  //die("FIN ligne ".__LINE__);
  Ellipsoid::set();
}

elseif ($_GET['test']=='ign') { // vérification des algos sur des points connus dans plusieurs systèmes de coordonnées
  $refs = [
    'Paris I (d) Quartier Carnot'=>[
      'src'=> 'http://geodesie.ign.fr/fiches/pdf/7505601.pdf',
      'L93'=> [658557.548, 6860084.001],
      'LatLong'=> [48.839473, 2.435368],
      'dms'=> ["48°50'22.1016''N", "2°26'07.3236''E"],
      'WebMercator'=> [271103.889193, 6247667.030696],
      'UTM-31N'=> [458568.90, 5409764.67],
    ],
    'FORT-DE-FRANCE V (c)' =>[
      'src'=>'http://geodesie.ign.fr/fiches/pdf/9720905.pdf',
      'UTM'=> ['20N'=> [708544.10, 1616982.70]],
      'dms'=> ["14° 37' 05.3667''N", "61° 03' 50.0647''W" ],
    ],
    'SAINT-DENIS C (a)' =>[
      'src'=>'http://geodesie.ign.fr/fiches/pdf/97411C.pdf',
      'UTM'=> ['40S'=> [338599.03, 7690489.04]],
      'dms'=> ["20° 52' 43.6074'' S", "55° 26' 54.2273'' E" ],
    ],
  ];

  foreach ($refs as $name => $ref) {
    echo "\nCoordonnees Pt Geodesique <a href='$ref[src]'>$name</a>\n";
    if (isset($ref['L93'])) {
      $clamb = $ref['L93'];
      echo "geo ($clamb[0], $clamb[1], L93) ->";
      $cgeo = Lambert93::geo ($clamb);
      printf ("phi=%s / %s lambda=%s / %s\n",
        radians2degresSexa($cgeo[1]/180*PI(),'N', 1/180*PI()/60/60/10000), $ref['dms'][0],
        radians2degresSexa($cgeo[0]/180*PI(),'E', 1/180*PI()/60/60/10000), $ref['dms'][1]);
      $cproj = Lambert93::proj($cgeo);
      printf ("Verification du calcul inverse: %.2f / %.2f , %.2f / %.2f\n\n",
                $cproj[0], $clamb[0], $cproj[1], $clamb[1]);

      $cwm = WebMercator::proj($cgeo);
      printf ("Coordonnées en WebMercator: %.2f / %.2f, %.2f / %.2f\n",
                $cwm[0], $ref['WebMercator'][0], $cwm[1], $ref['WebMercator'][1]);
  
  // UTM
      $zone = UTM::zone($cgeo);
      echo "\nUTM:\nzone=$zone\n";
      $cutm = UTM::proj($cgeo, $zone);
      printf ("Coordonnées en UTM-$zone: %.2f / %.2f, %.2f / %.2f\n",
        $cutm[0], $ref['UTM-31N'][0], $cutm[1], $ref['UTM-31N'][1]);
      $verif = UTM::geo($cutm, $zone);
      echo "Verification du calcul inverse:\n";
      printf ("phi=%s / %s lambda=%s / %s\n",
        radians2degresSexa($verif[1]/180*PI(),'N', 1/180*PI()/60/60/10000), $ref['dms'][0],
        radians2degresSexa($verif[0]/180*PI(),'E', 1/180*PI()/60/60/10000), $ref['dms'][1]);
    }
    elseif (isset($ref['UTM'])) {
      $zone = array_keys($ref['UTM'])[0];
      $cutm0 = $ref['UTM'][$zone];
      $cgeo = UTM::geo($cutm0, $zone);
      printf ("phi=%s / %s lambda=%s / %s\n",
        radians2degresSexa($cgeo[1]/180*PI(),'N'), $ref['dms'][0],
        radians2degresSexa($cgeo[0]/180*PI(),'E'), $ref['dms'][1]);
      $cutm = UTM::proj($cgeo, $zone);
      printf ("Coordonnées en UTM-%s: %.2f / %.2f, %.2f / %.2f\n", $zone, $cutm[0], $cutm0[0], $cutm[1], $cutm0[1]);
    }
  }
}

elseif ($_GET['test']=='merc') {
  //echo "proj([-180,0])="; print_r(WorldMercator::proj([-180,0]));
  //echo "proj([180,0])="; print_r(WorldMercator::proj([180,0]));
  //echo "proj([0,0])="; print_r(WorldMercator::proj([0,0]));
  echo "WebMercator::proj([360,0])="; print_r(WebMercator::proj([360,0]));
  echo "WebMercator::proj([0,80])="; print_r(WebMercator::proj([0,80]));
  echo "WebMercator::proj([180,WebMercator::MaxLat])="; print_r(WebMercator::proj([180,WebMercator::MaxLat]));
  
  echo "WorldMercator::proj([360,0])="; print_r(WorldMercator::proj([360,0]));
  echo "WorldMercator::proj([0,80])="; print_r(WorldMercator::proj([0,80]));


  /*bboxDM:
    SW: '79°00,00''S - 100°00,00''E'
    NE: '80°00,00''N - 490°00,00''E'
  */
  echo "WebMercator::proj([-260,0])="; print_r(WebMercator::proj([-260,0]));

  //echo "proj([0, 90])="; print_r(WorldMercator::proj([0, 90]));
  
  echo 'WebMercator::geo([IAG_GRS_1980::a()*pi(), IAG_GRS_1980::a()*pi()])=';
  print_r(WebMercator::geo([IAG_GRS_1980::a()*pi(), IAG_GRS_1980::a()*pi()]));
}

elseif ($_GET['test']=='lwemerc') { // Test de valeurs de latitude > 89 ou < -89
  for ($lat=89; $lat<=90; $lat += 0.1) {
    echo "lat=$lat -> "; 
    echo 90-$lat;
    //print_r(WebMercator::proj([90,$lat])); 
    print_r(WebMercator::proj([-90,-$lat]));
  }
  $xy = WebMercator::proj([-90,-90]);
  print_r($xy);
  if ($xy[1] == -INF)
    echo "== -INF\n";
}

elseif ($_GET['test']=='lwomerc') { // Test de valeurs de latitude > 89 ou < -89
  $xy = WorldMercator::proj([180, 0]);
  print_r($xy);
  $lonlat = WorldMercator::geo([0, $xy[0]]);
  print_r($lonlat);
}