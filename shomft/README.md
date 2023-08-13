# Module shomft de ShomGT3
L'objectif principal de ce module est de simplifier l'utilisation du serveur WFS du Shom
et de mettre à disposition dans le projet les couches vecteur GeoJSON suivantes:

- définition simplifiée de la ZEE française (`frzee.geojson`) produite dans le cadre du projet
  sous la forme de polygones en ditinguant les zones suivantes:
  - métropole - zone Manche-Mer du Nord (FX-MMN)
  - métropole - zone Atlantique (FX-Atl)
  - métropole - zone Méditerranée (FX-Med)
  - Martinique (MQ)
  - Guadeloupe (GP)
  - Guyane (GF)
  - La Réunion (RE)
  - Mayotte (YT)
  - St Pierre et Miquelon (PM)
  - St Barthélemy (BL)
  - St Martin (MF)
  - Wallis + Futuna (WF) en 2 polygones de chaque côté de l'anti-méridien
  - Polynésie (PF)
  - Nouvelle Calédonie (NC)
  - Clipperton (CP)
  - les TAAF (TF) en distinguant
    - Glorieuses
    - Tromelin
    - Juan de Nova
    - Bassas da India + Europa
    - Iles Crozet
    - Iles Kerguelen
    - Saint Paul + Amsterdam
    - Terre Adélie, même si cette zone n'a pas le même statut
  
- liste des cartes GéoTiff (`gt.json`) extraite du WFS du Shom par agrégation des couches
  - `CARTES_MARINES_GRILLE:grille_geotiff_800`
  - `CARTES_MARINES_GRILLE:grille_geotiff_300_800`
  - `CARTES_MARINES_GRILLE:grille_geotiff_30_300`
  - `CARTES_MARINES_GRILLE:grille_geotiff_30`

- liste des cartes GéoTiff par intervalle d'échelles
  - `gt10M`: échelle inférieure à 1/6.000.000
  - `gt4M`:  échelle comprise entre 1/6.000.000 et 1/3.000.000
  - `gt2M`:  échelle comprise entre 1/3.000.000 et 1/1.400.000
  - `gt1M`:  échelle comprise entre 1/1.400.000 et 1/700.000
  - `gt500k`: échelle comprise entre 1/700.000 et 1/380.000
  - `gt250k`: échelle comprise entre 1/380.000 et 1/180.000
  - `gt100k`: échelle comprise entre 1/180.000 et 1/90.000
  - `gt50k`: échelle comprise entre 1/90.000 et 1/45.000
  - `gt25k`: échelle comprise entre 1/45.000 et 1/22.000
  - `gt12k`: échelle comprise entre 1/22.000 et 1/11.000
  - `gt5k`: échelle supérieure au 1/11.000

  ces listes ne sont disponibles qu'au travers de l'API avec l'URL /ft.php/{collection}/items

- liste des cartes spéciales (`aem.json`) extraite du WFS du Shom
  de la couche `GRILLE_CARTES_SPECIALES_AEM_WFS:emprises_aem_3857_table`

- délimitations maritimes (`delmar.json`) extraites du WFS du Shom par agrégation des couches:
  - `DELMAR_BDD_WFS:au_baseline` : lignes de base droites
  - `DELMAR_BDD_WFS:au_maritimeboundary_agreedmaritimeboundary` : délimitation établie par un accord entre Etats
  - `DELMAR_BDD_WFS:au_maritimeboundary_contiguouszone` : limite extérieure de la zone contiguë
  - `DELMAR_BDD_WFS:au_maritimeboundary_continentalshelf` : limite extérieure du plateau continental
  - `DELMAR_BDD_WFS:au_maritimeboundary_economicexclusivezone` : limite extérieure de la ZEE
  - `DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary` : limite d'espace maritime revendiqué par la France sans accord
  - `DELMAR_BDD_WFS:au_maritimeboundary_territorialsea` : limite extérieure de la mer territoriale

Pour actualiser les fichiers `gt.json`, `aem.json` et `delmar.json`, il suffit de les supprimer
et d'appeler /ft.php/collections/{collection}/items où {collection} est respectivement `gt`, `aem` ou `delmar`.
