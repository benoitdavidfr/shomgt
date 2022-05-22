## ShomGT - services de consultation des cartes raster GéoTIFF du Shom

L'objectif de ce projet est d'exposer sous la forme de web-services le contenu des
[cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française pour permettre au [MTE](http://www.ecologie.gouv.fr)
et au [MCTRCT](http://www.cohesion-territoires.gouv.fr/) et à leurs services d'assurer leurs missions de service public.

La principale plus-value du projet est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [QGis](https://www.qgis.org/) ou [Leaflet](https://leafletjs.com/).

ShomGT intègre des cartes Shom **soumises à des contraintes de diffusion**.
Les services de l'Etat et les EPA de l'Etat disposent des droits  d'utilisation de ces cartes, en application de 
[l'article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consulter le Shom (<bureau.prestations@shom.fr>).

ShomGT est disponible sous 2 formes:

- le code source disponible publiquement sur [Github](https://github.com/benoitdavidfr/shomgt),
- une image Docker sur le GitLab du MTE (registry.gitlab-forge.din.developpement-durable.gouv.fr/benoit.david/shomgt3)
  contenant à la fois le code source et les données disponible ;
  cette image n'est accessible qu'aux personnes ayant un compte Cerbère (SSO du pôle ministériel).

De plus, les services de ShomGT sont exposés aux ayants droits sur https://geoapi.fr/shomgt/

ShomGT propose les services suivants :

  - 2 services exposant le contenu des cartes, l'un au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map),
    adapté notamment à une utilisation avec les logiciels [QGis](https://www.qgis.org/) et [Leaflet](https://leafletjs.com/),
    et un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
  - un service GeoJSON exposant les silhouettes des GéoTiffs,
  - un service de téléchargement des GéoTiffs avec des infos associées.


