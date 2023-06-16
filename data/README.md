# Organisation des fichiers dans data
**data** est un sous-répertoire du répertoire principal de ShomGT qui contient les données
 produites par le [module sgupdt](../sgupdt) et exploitées par [le module shomgt](../shomgt).
Lorsque ces modules sont déployés comme conteneurs Docker, *data* est un volume partagé entre ces conteneurs.

Outre ce fichier de documentation, *data* contient le shomgt.yaml et 3 sous-répertoires.

## Le fichier shomgt.yaml
Le fichier [fichier shomgt.yaml](shomgt.yaml) est structuré conformément au [schéma JSON shomgt.schema.yaml](../sgupdt/shomgt.schema.yaml).
Il est produit
par le [script shomgt.php du module sgupdt](../sgupdt#shomgtphp---g%C3%A9n%C3%A9ration-du-fichier-shomgtyaml).
Le fichier shomgt.yaml définit chaque couche de GéoTiffs par son identifiant et la liste des GéoTiffs qu'elle contient.
Chaque GéoTiff est à son tour défini par au moins son identifiant, son titre et l'extension géographique
de la zone cartographiée à l'intérieur de son cadre.
Un GéoTiff peut en outre comporter les propriétés suivantes:

- `outgrowth`: liste d'excroissances de la carte si elle en comporte,
- `borders`: pour les GéoTiffs qui ne sont pas géoréférencés (notamment dans le cas des cartes spéciales),
  nbre de pixels des bords haut, bas, droite et gauche à masquer,
- `deleted`: liste de zones effacées dans le GéoTiff.

## Le sous-répertoire maps
Le sous-répertoire `maps`contient principalement les images des GéoTiff et leur métadonnées ISO 19139 organisés par carte.   
Il contient en effet un sous-répertoire par carte portant comme nom le no de la carte
et contenant pour chaque GéoTiff de la carte:

- un sous-répertoire contenant les fichiers PNG découpant l'image en dalles de taille maximum 1024 x 1024 ;
  le nom de ce sous-répertoire constitue l'identifiant du GéoTiff dans la carte,
- un fichier JSON avec les informations de géoréférencement du GéoTiff,
- un fichier XML avec les métadonnées ISO 19139 du GéoTiff.

En outre, chaque sous-répertoire de carte contient un fichier PNG qui est une miniature de la carte.

Il existe des exceptions à cette structuration pour les cartes spéciales dont le l'image n'est souvent pas géoréférencée.
Dans ce cas cette information de géoréférencement est absente et doit être remplacée par la propriété `borders` dans shomgt.yaml.
De même les livraisons des cartes spéciales ne comportent généralement pas de métadonnées ISO.

## Le sous-répertoire tilecache
Le sous-répertoire tilecache constitue un cache des tuiles de la couche gtpyr des niveaux 0 à 9.

## Le sous-répertoire temp
Le sous-répertoire temp est utilisé par sgupdt lors de la construction d'un sous-répertoire de maps.
