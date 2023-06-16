# Organisation des fichiers dans data
**data** est un sous-répertoire du répertoire principal de ShomGT qui contient les données exploitées
par [le composant shomgt](../shomgt) et produites par le [composant sgupdt](../sgupdt).
Lorsque ces composant sont déployés comme conteneur Docker, *data* est un volume partagé entre ces conteneurs.

Outre ce fichier de documentation, *data* contient le [fichier shomgt.yaml](shomgt.yaml) et 3 sous-répertoires.

## Le fichier shomgt.yaml
Le fichier shomgt.yaml est structuré selon le schéma JSON défini [shomgt.schema.yaml](../sgupdt/shomgt.schema.yaml).
Il est produit par le [composant sgupdt](../sgupdt).
Le fichier shomgt.yaml définit chaque couche de GéoTiffs par son nom et la liste des GéoTiffs qu'elle contient.
Chaque GéoTiff est à son tour défini par au moins son nom, son titre et l'extension géographique de la zone cartographiée
à l'intérieur de son cadre.
Un GéoTiff peut en outre porter les propriétés suivantes:

- `outgrowth`: liste d'excroissances de la carte si elle en comporte,
- `borders`: pour les GéoTiffs qui ne sont pas géoréférencés (notamment dans le cas des cartes spéciales),
  nbre de pixels des bords haut, bas, droite et gauche à supprimer.
- `deleted`: liste de zones effacées dans le GéoTiff

## Le sous-répertoire maps
Le sous-répertoire `maps` contient un sous-répertoire par carte portant comme nom le no de la carte
et contenant pour chaque GéoTiff de la carte:

- un sous-répertoire contenant les fichiers PNG découpant l'image en dalles de taille maximum 1024 x 1024 ;
  le nom de ce sous-répertoire constitue l'identifiant du GéoTiff dans la carte,
- un fichier JSON avec les informations de géoréférencement du GéoTiff,
- un fichier XML avec les métadonnées ISO 19139 du GéoTiff.

En outre, le sous-répertoire de carte contient un fichier PNG qui est une miniature de la carte.

## Le sous-répertoire tilecache
Le sous-répertoire tilecache constitue un cache des tuiles de la couche gtpyr des niveaux 0 à 9.

## Le sous-répertoire temp
Le sous-répertoire temp est utilisé par sgupdt lors de la construction d'un sous-répertoire de maps.
