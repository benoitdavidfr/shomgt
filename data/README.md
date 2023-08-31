# Organisation des fichiers dans data
**data** contient les données structurées afin d'être facilement et efficacement utilisées par [le module view](../view)
et produites par le [module sgupdt](../sgupdt).
C'est un sous-répertoire du répertoire principal de ShomGT ou un volume partagé entre les conteneurs
lorsque ces modules sont déployés comme conteneurs Docker.

Outre ce fichier de documentation, *data* contient le fichier `shomgt.yaml` et 3 sous-répertoires.

## Le fichier shomgt.yaml
Le fichier `shomgt.yaml` définit les couches d'images par leur identifiant et les images qu'elles contiennent.
Chaque image est identifiée par le nom de base du fichier GéoTiff livré par le Shom.
Dans le fichier `shomgt.yaml` chaque couche liste les images qu'elle contient avec comme clé l'identifiant de l'image
et comme propriétés au moins son titre et la couverture spatiale de la zone cartographiée à l'intérieur de son cadre.
Une image peut en outre comporter les propriétés suivantes:

- `outgrowth`: liste d'excroissances de la carte si elle en comporte,
- `borders`: pour les GéoTiffs qui ne sont pas géoréférencés (notamment dans le cas des cartes spéciales),
  nbre de pixels des bords haut, bas, droite et gauche hors cadre à masquer,
- `deleted`: liste des zones effacées dans l'image.

Le fichier `shomgt.yaml` est structuré conformément au [schéma JSON shomgt.schema.yaml](../sgupdt/shomgt.schema.yaml).
Il est produit
par le [script shomgt.php du module sgupdt](../sgupdt#shomgtphp---g%C3%A9n%C3%A8re-le-fichier-shomgtyaml).

## Le sous-répertoire maps
Le sous-répertoire `maps`contient principalement les images et leurs métadonnées ISO 19139 organisées 
dans un sous-répertoire par carte  nommé par le no de la carte
et contenant pour chaque image de la carte:

- un sous-répertoire contenant les fichiers PNG découpant l'image en dalles de taille maximum 1024 x 1024 ;
  le nom de ce sous-répertoire constitue l'identifiant de l'image dans la carte,
- un fichier JSON avec les informations de géoréférencement du fichier GéoTiff initial,
- un fichier XML avec les métadonnées ISO 19139 de l'image.

En outre, chaque sous-répertoire de carte contient une miniature de la carte en PNG.

Il existe des exceptions à cette structuration pour les cartes spéciales dont l'image n'est souvent pas géoréférencée.
Dans ce cas cette information de géoréférencement est absente et doit être remplacée par la propriété `borders` dans shomgt.yaml.
De même les livraisons des cartes spéciales ne comportent souvent pas de métadonnées ISO 19139.

## Le sous-répertoire tilecache
Le sous-répertoire tilecache constitue un cache des tuiles de la couche gtpyr des niveaux 0 à 9.

## Le sous-répertoire temp
Le sous-répertoire temp est utilisé par sgupdt lors de la construction d'un sous-répertoire de maps.
