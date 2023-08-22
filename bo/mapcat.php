<?php
/*PhpDoc:
title: bo/mapcat.php - gestion du catalogue MapCat et confrontation des données de localisation de MapCat avec celles du GAN
classes:
doc: |
  L'objectif est d'une part de vérifier les contraintes sur MapCat et, d'autre part, d'identifier les écarts entre mapcat
  et le GAN pour
    - s'assurer que mapcat est correct
    - marquer dans mapcat dans le champ badGan l'écart

  Le traitement dans le GAN des excroissances de cartes est hétérogène.
  Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.
journal: |
  13/8/2023:
    - restructuration dans le cadre du BO v4 et ajout de la vérification des contraintes
  24/4/2023:
    - prise en compte dans CmpMapCat::scale() de la possibilité que scaleDenominator ne soit pas défini
    - prise en compte dans CmpMapCat::cmpGans() que la carte soit définie dans MapCat et absente du GAN
  3/8/2022:
    - corrections listée par PhpStan level 6
  2/7/2022:
    - reprise après correction des GAN par le Shom à la suite de mon message
    - ajout comparaison des échelles
  24/6/2022:
    - migration 
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../lib/jsonschema.inc.php';
require_once __DIR__.'/../dashboard/gan.inc.php';

use Symfony\Component\Yaml\Yaml;


if (!callingThisFile(__FILE__)) return; // retourne si le fichier est inclus


echo "<!DOCTYPE html>\n<html><head><title>bo/mapcat@$_SERVER[HTTP_HOST]</title></head><body>\n";

/** retourne la liste des images géoréférencées de la carte sous la forme [{id} => $info]
 * @return array<string, array<string, mixed>> */
function geoImagesOfMap(string $mapNum, MapCat $mapCat): array {
  $spatials = $mapCat->spatial ? [$mapNum => $mapCat->asArray()] : [];
  //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
  foreach($mapCat->insetMaps ?? [] as $i => $insetMap) {
    $spatials["$mapNum/inset$i"] = $insetMap;
  }
  return $spatials;
}

function cmpGans(): void { // comparaison MapCat / GAN
  echo "<table border=1><th>mapid</th><th>badGan</th><th>inset</th>",
    "<th>cat'scale</th><th>gan'scale</th><th>ok?</th>",
    "<th>cat'SW</th><th>gan'SW</th><th>ok?</th>",
    "<th>x</th><th>cat'NE</th><th>gan'NE</th><th>ok?</th>\n";
  foreach (MapCat::mapNums(['current']) as $mapNum) {
    $mapCat = MapCat::get($mapNum);
    //echo "<pre>"; print_r($map); echo "</pre>";
    if (!($gan = Gan::$gans[substr($mapNum, 2)] ?? null)) { // carte définie dans MapCat et absente du GAN
      echo "<tr><td>$mapNum</td><td>",$mapCat->badGan ?? '',"</td><td></td>";
      echo "<td>",$mapCat->scale(),"</td><td colspan=9>Absente du GAN</td></tr>\n";
      continue;
    }
    //echo "<pre>gan="; print_r($gan); echo "</pre>";
    //echo "<pre>map="; print_r($map); echo "</pre>";
    if ($mapCat->spatial && $gan->spatial()) {
      $ganspatial = [
        'SW' => str_replace('—', '-', $gan->spatial()['SW']),
        'NE' => str_replace('—', '-', $gan->spatial()['NE']),
      ];
      $mapspatial = $mapCat->spatial;
      //echo "<pre>"; print_r($map); echo "</pre>";
      if ($mapCat->badGan || ($mapCat->scale() <> $gan->scale())
          || ($mapspatial['SW'] <> $ganspatial['SW']) || ($mapspatial['NE'] <> $ganspatial['NE'])) {
        echo "<tr><td>$mapNum</td><td>",$mapCat->badGan ?? '',"</td><td></td>";
        echo "<td>",$mapCat->scale(),"</td><td>",$gan->scale(),"</td>",
          "<td>",($mapCat->scale() == $gan->scale()) ? 'ok' : '<b>KO</b>',"</td>\n";
        echo "<td>$mapspatial[SW]</td><td>$ganspatial[SW]</td>",
          "<td>",($mapspatial['SW'] == $ganspatial['SW']) ? 'ok' : '<b>KO</b',"</td>";
        echo "<td></td><td>$mapspatial[NE]</td><td>$ganspatial[NE]</td>",
          "<td>",($mapspatial['NE'] == $ganspatial['NE']) ? 'ok' : '<b>KO</b',"</td>";
        echo "</tr>\n";
      }
    }
    foreach ($mapCat->insetMaps ?? [] as $i => $insetMap) {
      try {
        $ganpart = Gan::$gans[substr($mapNum, 2)]->inSet(GBox::fromGeoDMd($insetMap['spatial']));
        $ganpartspatial = [
          'SW' => str_replace('—', '-', $ganpart->spatial()['SW']),
          'NE' => str_replace('—', '-', $ganpart->spatial()['NE']),
        ];
        if (($ganpart->scale() <> $mapCat->insetScale($i))
           || ($ganpartspatial['SW'] <> $insetMap['spatial']['SW'])
           || ($ganpartspatial['NE'] <> $insetMap['spatial']['NE'])) {
          echo "<tr><td>$mapNum/$i</td><td>",$mapCat->badGan ?? '',"</td><td>$insetMap[title]</td>";
          //echo "<td><pre>"; print_r($insetMap); echo "</pre></td>";
          echo "<td>",$mapCat->insetScale($i),"</td><td>",$ganpart->scale(),"</td>",
            "<td>",($ganpart->scale() == $mapCat->insetScale($i)) ? 'ok' : '<b>KO</b>',"</td>";
          echo "<td>",$insetMap['spatial']['SW'],"\n";
          //echo "<td><pre>"; print_r($ganpart); echo "</pre></td>";
          echo "<td>$ganpartspatial[SW]</td>",
            "<td>",$ganpartspatial['SW'] == $insetMap['spatial']['SW'] ? 'ok' : '<b>KO</b>',"</td>";
          echo "<td></td><td>",$insetMap['spatial']['NE'],"\n";
          echo "<td>$ganpartspatial[NE]</td>",
            "<td>",$ganpartspatial['NE'] == $insetMap['spatial']['NE'] ? 'ok' : '<b>KO</b>',"</td>";
          echo "</tr>\n";
        }
      }
      catch (SExcept $e) {
      }
    }
  }
  echo "</table>\n";
}

// Classe portant en constante la définition SQL de la table user
// ainsi qu'une méthode statique traduisant cette constate en requête SQL
class MapCatDef {
  // la structuration de la constante est définie dans son champ description
  const MAPCAT_TABLE_SCHEMA = [
    'description' => "Ce dictionnaire définit le schéma d'une table SQL avec:\n"
            ." - le champ 'comment' précisant la table concernée,\n"
            ." - le champ obligatoire 'columns' définissant le dictionnaire des colonnes avec pour chaque entrée:\n"
            ."   - la clé définissant le nom SQL de la colonne,\n"
            ."   - le champ 'type' obligatoire définissant le type SQL de la colonne,\n"
            ."   - le champ 'keyOrNull' définissant si la colonne est ou non une clé et si elle peut ou non être nulle\n"
            ."   - le champ 'comment' précisant un commentaire sur la colonne.\n"
            ."   - pour les colonnes de type 'enum' correspondant à une énumération le champ 'enum'\n"
            ."     définit les valeurs possibles dans un dictionnaire où chaque entrée a:\n"
            ."     - pour clé la valeur de l'énumération et\n"
            ."     - pour valeur une définition et/ou un commentaire sur cette valeur.",
    'comment' => "table du catalogue des cartes avec 1 n-uplet par carte et par mise à jour",
    'columns'=> [
      'id'=> [
        'type'=> 'int',
        'keyOrNull'=> 'not null auto_increment primary key',
        'comment'=> "id du n-uplet incrémenté pour permettre des versions sucessives par carte",
      ],
      'mapnum'=> [
        'type'=> 'char(6)',
        'keyOrNull'=> 'not null',
        'comment'=> "numéro de carte sur 4 chiffres précédé de 'FR'",
      ],
      /*'title'=> [
        'type'=> 'varchar(256)',
        'keyOrNull'=> 'not null',
        'comment'=> "titre de la carte sans le numéro en tête",
      ],  // pas nécessaire peut être lu dans jdoc */
      'kind'=> [
        'type'=> 'enum',
        'keyOrNull'=> 'not null',
        'enum'=> [
          'current' => "carte courante",
          'obsolete' => "carte obsolete",
        ],
        'comment'=> "carte courante ou obsolète",
      ],
      /*'obsoletedt'=> [
        'type'=> 'datetime',
        'comment'=> "date de suppression pour les cartes obsolètes, ou null si elle ne l'est pas",
      ], pas nécessaire, peut être lu dans jdoc*/
      'jdoc'=> [
        'type'=> 'JSON',
        'keyOrNull'=> 'not null',
        'comment'=> "enregistrement conforme au schéma JSON",
      ],
      /*'bbox'=> [
        'type'=> 'POLYGON',
        'keyOrNull'=> 'not null',
        'comment'=> "boite engobante de la carte en WGS84",
      ], voir le besoin */
      'updatedt'=> [
        'type'=> 'datetime',
        'keyOrNull'=> 'not null',
        'comment'=> "date de création/mise à jour de l'enregistrement dans la table",
      ],
      'user'=> [
        'type'=> 'varchar(256)',
        'comment'=> "utilisateur ayant réalisé la mise à jour, null pour une versions système",
      ],
    ],
  ]; // Définition du schéma SQL de la table mapcat

  // fabrique le code SQL de création de la table à partir d'une des constantes de définition du schéma
  /** @param array<string, mixed> $schema */
  static function createTableSql(string $tableName, array $schema): string {
    $cols = [];
    foreach ($schema['columns'] ?? [] as $cname => $col) {
      $cols[] = "  $cname "
        .match($col['type'] ?? null) {
          'enum' => "enum('".implode("','", array_keys($col['enum']))."') ",
          default => "$col[type] ",
          null => die("<b>Erreur, la colonne '$cname' doit comporter un champ 'type'</b>."),
      }
      .($col['keyOrNull'] ?? '')
      .(isset($col['comment']) ? " comment \"$col[comment]\"" : '');
    }
    return ("create table $tableName (\n"
      .implode(",\n", $cols)."\n)"
      .(isset($schema['comment']) ? " comment \"$schema[comment]\"\n" : ''));
  }

  static function getMapSchema(): array { // construit le schéma d'une carte dans MapCat déduit du schéma de MapCat
    $catSchema = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.schema.yaml');
    return [
      '$id'=> 'https://sgserver.geoapi.fr/index.php/cat/schema/map',
      '$schema'=> $catSchema['$schema'],
      'definitions' => $catSchema['definitions'],
      '$ref'=> '#/definitions/map',
    ];
  }
  
  static function validatesAgainstSchema(string $yaml): array { // valide le doc. / schéma
    $mapSchema = new JsonSchema(self::getMapSchema());
    try {
      $doc = Yaml::parse($yaml);
    }
    catch (Symfony\Component\Yaml\Exception\ParseException $e) {
      return [$e->getMessage()];
    }
    $status = $mapSchema->check($doc);
    return $status->errors();
  }
};

if (!($user = Login::loggedIn())) {
  die("Erreur, ce sript nécessite d'être logué\n");
}

echo '<pre>',Yaml::dump(['$_POST'=> $_POST ?? [], '$_GET'=> $_GET ?? []]),"</pre>\n";

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) {
  case null: {
    echo "<h2>Gestion du catalogue MapCat</h2><h3>Menu</h3><ul>\n";
    echo "<li><a href='index.php'>Retour au menu du BO</a></li>\n";
    echo "<li><a href='?action=check'>Vérifie les contraintes sur MapCat</a></li>\n";
    echo "<li><a href='?action=cmpGan'>Confronte les données de localisation de MapCat avec celles du GAN</a></li>\n";
    echo "<li><a href='?action=createTable'>Crée la table mapcat et charge le catalogue</a></li>\n";
    echo "<li><a href='?action=showMapCat'>Affiche le catalogue à partir de la table en base</a></li>\n";
    echo "<li><a href='?action=updateMapCat'>Met à jour le catalogue</a></li>\n";
    die();
  }
  case 'check': { // Vérifie les contraintes sur MapCat
    $mapCat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    
    { // Vérifie qu'aucun no de carte apparait dans plusieurs sections
      $maps = [];
      foreach (MapCat::ALL_KINDS as $kind) {
        foreach (MapCat::mapNums([$kind]) as $mapNum) {
          $maps[$mapNum][$kind] = MapCat::get($mapNum, [$kind]);
        }
      }
      $found = false;
      foreach ($maps as $mapNum => $kindMap) {
        if (count($kindMap) > 1) {
          echo '<pre>',Yaml::dump([$mapNum => $kindMap]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Aucun no de carte apparait dans plusieurs sections<br>\n";
    }

    { // vérifie que toute carte current et obsolete dont l'image principale n'est pas géoréférencée a des cartouches
      // cad que (scaleDenominator && spatial) || insetMaps toujours vrai
      $found = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        if (!$mapCat->insetMaps && (!$mapCat->scaleDenominator || !$mapCat->spatial)) {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Toute carte current et obsolete dont l'image principale n'est pas géoréférencée a des cartouches<br>\n";
    }

    { // Vérifie que Le mapsFrance de toute carte de maps est <> unknown
      $found = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        if ($mapCat->mapsFrance == 'unknown') {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Le mapsFrance de toute carte current et obsolete est <> unknown<br>\n";
    }
    
    { // Vérifie les contraintes sur le champ spatial et que les exceptions sont bien indiquées
      $bad = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        foreach(geoImagesOfMap($mapNum, $mapCat) as $id => $info) {
          $spatial = new Spatial($info['spatial']);
          if ($error = $spatial->isBad()) {
            echo '<pre>',Yaml::dump([$error => [$mapNum => $info]], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
            $bad = true;
          }
        }
      }
      if (!$bad) {
        echo "Tous les champs spatial respectent leurs contraintes, à savoir:<br>\n";
        echo '<pre>',
             Yaml::dump(['Spatial::CONSTRAINTS'=> Spatial::CONSTRAINTS, 'Spatial::EXCEPTIONS'=> Spatial::EXCEPTIONS], 4),
             "</pre>\n";
      }
    }
    break;
  }
  case 'cmpGan': { // Confronte les données de localisation de MapCat avec celles du GAN
    GanStatic::loadFromPser(); // charge les GANs sepuis le fichier gans.pser du dashboard
    //echo '<pre>gans='; print_r(Gan::$gans);
    cmpGans();
    break;
  }
  case 'createTable': { // crée et peuple la table mapcat à partir du fichier mapcat.yaml
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    MySql::open($LOG_MYSQL_URI);
    MySql::query('drop table if exists mapcat');
    $query = MapCatDef::createTableSql('mapcat', MapCatDef::MAPCAT_TABLE_SCHEMA);
    //echo "<pre>query=$query</pre>\n";
    MySql::query($query);

    //MySql::query('delete from mapcat');
    foreach (MapCatFromFile::mapNums() as $mapNum) {
      echo "mapNum = $mapNum<br>\n";
      $mapCat = MapCatFromFile::get($mapNum);
      //$title = MySql::$mysqli->real_escape_string($mapCat->title);
      $jdoc = $mapCat->asArray();
      $kind = $jdoc['kind'];
      unset($jdoc['kind']);
      $jdoc = MySql::$mysqli->real_escape_string(json_encode($jdoc));
      //$obsoletedt = $mapCat->obsoleteDate ? "'$mapCat->obsoleteDate'" : 'null';
      $query = "insert into mapcat(mapnum, kind, jdoc, updatedt) "
        ."values('$mapNum', '$kind', '$jdoc', now())";
      //echo "<pre>query=$query</pre>\n";
      MySql::query($query);
    }
    break;
  }
  case 'showMapCat': { // affiche le contenu de la table mapcat
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    MySql::open($LOG_MYSQL_URI);
    $mapCat = [];
    foreach (MySql::query("select * from mapcat order by id") as $tuple) {
      $tuple['jdoc'] = json_decode($tuple['jdoc'], true);
      $mapCat[$tuple['mapnum']] = $tuple;
    }
    echo "<pre>\n";
    foreach ($mapCat as $mapNum => $mapCatEntry) {
      echo YamlDump([$mapCatEntry], 5);
    }
    echo "</pre>\n";
    break;
  }
  case 'updateMapCat': { // affiche les entrées de MapCat pour en sélectionner une pour mise à jour
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    MySql::open($LOG_MYSQL_URI);
    $mapCat = [];
    $sql = 'select id, mapnum, jdoc->"$.title" title from mapcat where kind=\'current\' order by id';
    foreach (MySql::query($sql) as $tuple) {
      $mapCat[$tuple['mapnum']] = ['id'=> $tuple['id'], 'title'=> "$tuple[mapnum] - ".substr($tuple['title'], 1, -1)];
    }
    ksort($mapCat);
    foreach ($mapCat as $tuple) {
      echo "<a href='?action=updateMapCatId&amp;id=$tuple[id]'>$tuple[title]</a><br>\n";
    }
    break;
  }
  case 'updateMapCatId': { // affiche le formulaire de mise à jour d'une entrée de mapcat et effectue la mise à jour en base
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    MySql::open($LOG_MYSQL_URI);
    if (isset($_POST['yaml'])) { // Retour d'une saisie d'une description
      $yaml = $_POST['yaml'];
      if (!($errors = MapCatDef::validatesAgainstSchema($yaml))) { // description conforme, l'enregistrement est créé en base
        $jdocRes = MySql::$mysqli->real_escape_string(json_encode(Yaml::parse($yaml)));
        $query = "insert into mapcat(mapnum, kind, jdoc, updatedt, user) "
                            ."values('$_POST[mapnum]', 'current', '$jdocRes', now(), '$user')";
        echo "<pre>query=$query</pre>\n";
        MySql::query($query);
        echo "maj carte $_POST[mapnum] ok<br>\n";
        switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
          case 'mapcat': { echo "<a href='mapcat.php'>Retour</a><br>\n"; break; }
          case 'addmaps': { echo "<a href='addmaps.php'>Retour</a><br>\n"; break; }
          default: die("valeur de return '$return' non prévue");
        }
        break;
      }
      else { // description non conforme
        echo "<b>Erreur, la description fournie n'est pas conforme au schéma JSON:</b>\n";
        echo '<pre>',Yaml::dump($errors),"</pre>";
        $mapcat = [
          'mapnum'=> $_POST['mapnum'],
          'yaml'=> $yaml,
        ];
      }
    }
    else { // Premier affichage du formulaire
      if (isset($_GET['id']))
        $mapcat = MySql::getTuples("select mapnum, jdoc from mapcat where id=$_GET[id]")[0];
      elseif (isset($_GET['mapnum'])) {
        $mapcat = MySql::getTuples("select mapnum, jdoc from mapcat where mapnum='FR$_GET[mapnum]' order by id desc")[0];
        if (!$mapcat)
          die("Erreur FR$_GET[mapnum] n'existe pas dans la table mapcat");
      }
      else
        die("Appel de ".__FILE__."incorrect");
      $mapcat['yaml'] = YamlDump(json_decode($mapcat['jdoc'], true), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
    // Fabrique le formulaire à partir de la variable $mapcat
    echo "<b>Mise à jour de la description dans le catalogue MapCat de la carte $mapcat[mapnum]:</b></p>\n";
    $hiddenValues = [
      'action'=> 'updateMapCatId',
      'mapnum'=> $mapcat['mapnum'],
      'return'=> $_GET['return'] ?? 'mapcat'
    ];
    echo Html::textArea('yaml', $mapcat['yaml'], 16, 100, 'maj', $hiddenValues, '', 'post');
    echo "</p><b>Le schéma JSON à respecter pour cette description est le suivant:</b><br>";
    echo '<pre>',Yaml::dump(MapCatDef::getMapSchema(), 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
    switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
      case 'mapcat': { echo "<a href='mapcat.php'>Retour</a><br>\n"; break; }
      case 'addmaps': { echo "<a href='addmaps.php'>Retour</a><br>\n"; break; }
      default: die("valeur de return '$return' non prévue");
    }
    break;
  }
  case 'insertMapCat': {
    echo "<b>Ajout de la description dans le catalogue MapCat de la carte $_GET[mapnum]:</b></p>\n";
    $hiddenValues = [
      'action'=> 'updateMapCatId',
      'mapnum'=> "FR$_GET[mapnum]",
      'return'=> $_GET['return'] ?? 'mapcat'
    ];
    echo Html::textArea('jdoc', '', 16, 100, 'ajout', $hiddenValues, '', 'post');
    
    echo "</p><b>Le schéma JSON à respecter pour cette description est le suivant:</b><br>";
    echo '<pre>',Yaml::dump(MapCatDef::getMapSchema(), 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
    
    switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
      case 'mapcat': { echo "<a href='mapcat.php'>Retour</a><br>\n"; break; }
      case 'addmaps': { echo "<a href='addmaps.php'>Retour</a><br>\n"; break; }
      default: die("valeur de return '$return' non prévue");
    }
    break;
    
  }
  default: {
    echo "action '$action' inconnue<br>\n";
    break;
  }
}
