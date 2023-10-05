# Module view de ShomGT

Ce module propose les services suivants :

- 2 services exposant le contenu des cartes Shom:
  - l'un au [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), adapté notamment à une utilisation 
    avec les logiciels [QGis](https://www.qgis.org/) et [Leaflet](https://leafletjs.com/) et
  - un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
- un service WMS exposant différentes couches vecteur:
  - les silhouettes des GéoTiffs,
  - les périmètres simplifiés des ZEE,
  - les délimitations maritimes,
  - les zones SAR-SRR,
- une carte Leaflet de visualisation des tuiles et des couches vecteur et permettant de télécharger les cartes.

L'utilisation de tuiles offre de meilleures performances que l'utilisation du service WMS,
car il permet une meilleure gestion du cache tant sur le serveur, que sur le réseau et sur le poste client.

## Expositions
Ce module expose:

- l'API des tuiles conforme au standard XYZ, correspondant au script `tile.php`
- le service WMS des images, correspondant au script `wms.php`,
- le service WMS des vecteurs, correspondant au script `wmsv.php`,
- la carte Leaflet de visualisation des tuiles et des couches GeoJSON, correspondant au script `mapwcat.php`.

## Variables d'environnement

Ce module utilise les variables d'environnement suivantes:

- `SHOMGT3_LOG_MYSQL_URI`: URL définissant le serveur et la base MySql utilisés pour enregistrer les logs.
  si non définie alors le log est désactivé.
  L'URL prend la forme suivante: `mysql://{login}:{passwd}@{server}/{base}` où:
  - `{login}` est le login dans la base MySql,
  - `{passwd}` est le mot de passe associé au login,
  - `{server}` est l'adresse du serveur de bases MySql,
  - `{base}` est le nom de la base.
- `SHOMGT3_MAPWCAT_FORCE_HTTPS`: si `true` alors https est forcé dans mapwcat ;
  cette variable est nécessaire par exemple pour utiliser view derrière un proxy inverse Traefik

## Documentation PHPDoc du code source
Le code source de ce module est documenté dans
le [package shomgt\view de PHPDoc](https://benoitdavidfr.github.io/shomgt/phpdoc/packages/shomgt-view.html).
