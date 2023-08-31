# Catalogue des cartes MapCat

Le catalogue des cartes est contenu dans le fichier [mapcat.yaml](mapcat.yaml) ;
dont le schéma JSON est défini dans le fichier [mapcat.schema.yaml](mapcat.schema.yaml).

Ce catalogue constitue une référence indispensable pour le fonctionnement de sgupdt et view :

- l'extension spatiale (champ `spatial`) et l'échelle (champ `scaleDenominator`) des cartes et de leurs cartouches
  sont utilisés dans `sgupdt` et `view` pour visualiser les cartes,
- les bordures (champ `borders`) sont nécessaires aux fichiers GéoTiff non ou mal géoréférencés,
- le titre (champ `title`) est utilisé pour l'afficher dans la carte Leaflet,
- le champ `layer` est utilisé pour affecter à une couche les cartes spéciales (cartes AEM, MancheGrid, ...),
- les champs `z-order`, `toDelete` et `outgrowth` permettent d'améliorer l'affichage des images dans le service WMS
  et l'API tile,
- le champ `obsolete` signale les cartes obsolètes pour le Shom,
- enfin, le champ `noteCatalog` permet de mémoriser les choix effectués dans le gestion de ce catalogue
  et le champ `badGan` mentionne plus particulièrement les écarts souhaités au GAN.

Ce catalogue chargé en base pour pouvoir être mis à jour dans le BO
et est téléchargé par les instances réparties de ShomGt lors de la mise à jour des cartes.
Ainsi son amélioration bénéficie à toutes les instance de ShomGt.

Si vous souhaitez améliorer ce catalogue, notamment les champs `z-order`, `toDelete` et `outgrowth` pour améliorer
l'affichage des images, proposez des pull requests sur le fichier mapcat.yaml.

## Utilitaire cmpgan.php
Cet utilitaire compare le catalogue au GAN pour détecter des incohérences non souhaitées.

## Cartes particulières
Cette section décrit quelques cas particuliers de cartes.

### Carte sans zone principale
Certaines cartes sont composées uniquement de cartouches, le fichier `{num}_pal300.tif` n'est alors pas géoréférencé.  
Ce cas est illustré par la carte 7427 dont l'extrait dans le catalogue figure ci-dessous :

    FR7427:
      groupTitle: 'Côte Ouest de France'
      title: 'La Gironde - La Garonne et La Dordogne'
      mapsFrance:
        - FX-Atl
      noteCatalog: 'Correction des titres des cartouches, pas de partie principale'
      insetMaps:
        - title: '1 - La Gironde - La Garonne et La Dordogne (1/3)'
          scaleDenominator: '52.000'
          spatial: { SW: '45°08,90''N - 000°55,00''W', NE: '45°30,00''N - 000°39,40''W' }
        - title: '2 - La Gironde - La Garonne et La Dordogne (2/3)'
          scaleDenominator: '52.300'
          spatial: { SW: '44°50,18''N - 000°45,00''W', NE: '45°11,40''N - 000°17,50''W' }
          toDelete:
            geotiffname: 7427_5_gtw
            polygons: # Cartouche 7427-B - Port de Blaye
              - 
                - 45°11,40'N - 0°17,50'W
                - 44°57,81'N - 0°17,50'W
                - 44°57,81'N - 0°24,32'W
                - 44°57,81'N - 0°24,32'W
                - 45°05,44'N - 0°33,62'W
                - 45°11,40'N - 0°33,62'W
        - title: '3 - La Gironde - La Garonne et La Dordogne (3/3)'
          scaleDenominator: '52.400'
          spatial: { SW: '44°51,90''N - 000°22,40''W', NE: '44°57,10''N - 000°13,60''W' }
        - title: 'A - Port de Pauillac'
          scaleDenominator: '20.000'
          spatial: { SW: '45°10,24''N - 000°45,58''W', NE: '45°14,24''N - 000°42,58''W' }
        - title: 'B - Port de Blaye'
          scaleDenominator: '20.000'
          spatial: { SW: '45°03,44''N - 000°43,00''W', NE: '45°08,44''N - 000°37,08''W' }

### Carte spéciale
Pour les cartes spéciales, le champ `layer` du catalogue permet de connaître à quelle couche cette carte doit être affectée.   
Ce cas est illustré par la carte 7330 dont l'extrait dans le catalogue figure ci-dessous :

    FR7330:
      groupTitle: 'Océan Atlantique Nord'
      title: 'De Cherbourg à Hendaye - Action de l''Etat en Mer en Zone Maritime Atlantique'
      mapsFrance: [FX-Atl, FX-MMN]
      scaleDenominator: 1.070.000
      spatial: {SW: "41°28,00'N - 010°30,00'W", NE: "52°00,00'N - 000°00,00'E"}
      layer: gtaem

### Carte ayant une zone cartographiée à cheval sur l'anti-méridien
Certaines cartes ont une zone cartographiée à cheval sur l'anti-méridien.
C'est le cas de la carte 7283 dont l'extrait dans le catalogue figure ci-dessous :

    FR7283:
      groupTitle: 'Océan Pacifique Sud'
      title: 'Des îles Fidji (Fiji) aux îles Tonga - Iles Wallis et Futuna'
      mapsFrance: [WF]
      scaleDenominator: 1.000.000
      spatial:
        SW: '16°30,00''S - 177°00,00''E'
        NE: '09°40,56''S - 172°47,00''W'
        exception: astrideTheAntimeridian
      replaces: '(remplace 6067)'

### Carte ayant une extension en latitude supérieure à 360°
Les 2 cartes 0101 et 8510, dont les extraits dans le catalogue figurent ci-dessous,
ont une extension en latitude supérieure à 360° et doivent donc être traitées de manière particulière.
Par convention dans ce cas la latitude du bord Est prend une valeur supérieure à 180°.

    FR0101:
      groupTitle: 'Planisphère terrestre'
      title: 'Planisphère terrestre (axé sur 65° Ouest)'
      mapsFrance: [FR]
      scaleDenominator: 40.000.000
      spatial: { SW: "79°00,00'S - 100°00,00'E", NE: "80°00,00'N - 490°00,00'E", exception: circumnavigateTheEarth }
      noteCatalog: |
        correction de l'extension géo. pour que le bord Est ait une longitude supérieure au bord West et que l'extension en
        longitude soit supérieure à 360°, correction aussi du titre
      badGan: carte absente du GAN
    
    FR8510:
      title: 'Délimitations des zones maritimes'
      mapsFrance: [FR]
      scaleDenominator: 38.000.000
      spatial: { SW: "76°46'S - 088°E", NE: "79°17'N - 448°E", exception: circumnavigateTheEarth }
      borders: { left: 217, bottom: 202, right: 224, top: 212 }
      noteCatalog: "Carte spéciale absente du GAN et non géoréférencée, fait le tour de la Terre, le bord Est est supérieur à 180°"
      badGan: "Carte spéciale absente du GAN"
      layer: gtZonMar
  
