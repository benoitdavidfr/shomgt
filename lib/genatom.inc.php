<?php
/*PhpDoc:
name:  genatom.inc.php
title: lib/genatom.inc.php - fonction de génération d'un fil Atom
functions:
doc: |
  La fonction est utilisée pour générer un fil Atom principal ou secondaire à partir des données en base
journal: |
  31/12/2020:
    - duplication dans shomgt/lib
  25/8/2016:
    - extraction de la fonction gen_atom_feed($feed, $entries) dans le fichier genatom.inc.php
*/
/*PhpDoc: functions
name: gen_atom_feed
title: function gen_atom_feed($feed, $entries)
doc: |
  génère un flux Atom, les entrées doivent être triées par date et heure décroissantes
  feed : [
    'title'=> titre du fil
    'author'=> [ 'name'=> nom, 'email'=> email ], // l'auteur du fil,
    'uri'=> l'URI du fil,
    'links'=> [ [
      'href'=> href
      'rel'=> rel
      'type'=> type
      'comment'=> commentaire
    ] ],
  ]
  entries : [ [ 
      'title'=> titre de l'élément
      'uri'=> l'URI de l'élément,
      'updated'=>date et heure UTC,
      'links'=> [ [
        'href'=> href
        'rel'=> rel
        'type'=> type
        'comment'=> commentaire
      ] ],
      'categories' => [ [
        'term'=> l'URI du CRS
        'label'=> etiquette du CRS
      ] ]
  ] ]
*/
function gen_atom_feed($feed, $entries) {
//  echo "gen_atom_feed(): feed="; print_r($feed);
  $updated = '1970-01-01T00:00:00Z';
  if ($entries)
    $updated = $entries[0]['updated'];
  foreach(['title','uri'] as $k)
    $feed[$k] = str_replace(['&','<','>'],['&amp;','&lt;','&gt;'], $feed[$k]);
  header('Content-type: text/xml; charset="utf8"');
  echo <<<EOT
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>$feed[title]</title>
  <!-- lien vers le document lui-même -->
  <link href="$feed[uri]" rel="self" type="application/atom+xml" hreflang="fr" title="Ce document"/>\n
EOT;
  foreach ($feed['links'] as $link) {
    $link['href'] = str_replace(['&','<','>'],['&amp;','&lt;','&gt;'], $link['href']);
    if (isset($link['comment'])) echo "  <!-- $link[comment] -->\n";
    echo "  <link href=\"$link[href]\" rel=\"$link[rel]\" type=\"$link[type]\"/>\n";
  }
  echo "  <updated>$updated</updated>
  <author>
    <name>",$feed['author']['name'],"</name>
    <email>",$feed['author']['email'],"</email>
  </author>
  <id>$feed[uri]</id>\n";
  foreach ($entries as $entry) {
    echo "  <entry>
    <title>$entry[title]</title>\n";
    if (isset($entry['links']))
      foreach ($entry['links'] as $link) {
        $link['href'] = str_replace(['&','<','>'],['&amp;','&lt;','&gt;'], $link['href']);
        echo (isset($link['comment']) ? "    <!-- $link[comment] -->\n" : ''),
             "    <link href=\"$link[href]\" rel=\"$link[rel]\" type=\"$link[type]\"/>\n";
      }
    if (isset($entry['categories']))
      foreach ($entry['categories'] as $category)
        echo "    <category term=\"$category[term]\" label=\"$category[label]\"/>\n";
    echo <<<EOT
    <updated>$entry[updated]</updated>
    <id>$entry[uri]</id>
  </entry>\n
EOT;
  }
  die("</feed>\n");
}
