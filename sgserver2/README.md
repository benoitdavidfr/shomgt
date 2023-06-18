# Module sgserver de ShomGT3
L'objectif de sgserver est d'exposer à *sgupdt* les cartes du Shom gérées dans un répertoire appelé **shomgeotiff**.
Il est mis à jour régulièrement grâce à *dashboard*.

## 1. Variables d'environnement
- `SHOMGT3_PORTFOLIO_PATH`: chemin du répertoire shomgeotiff  
  Ce répertoire ne doit pas être accessible depuis internet pour éviter un contournement du contrôle d'accès

## 2. Dépôt shomgeotiff des cartes
2 configurations sont possibles, soit on souhaite conserver l'historique des livraisons de cartes,
soit on souhaite au contraire minimiser le volume de stockage.

Par exemple, on peut souhaiter conserver l'historique sur la machine de développement
et minimiser le stockage sur la machine de production.

### 2.1 Dépôt shomgeotiff conservant l'historique des livraisons de cartes
Le répertoire shomgeotiff contient 3 sous-répertoires:

- `ìncoming` qui contient un sous-répertoire par livraison de cartes à intégrer dans le portfeuille ;
  chaque sous-répertoire de livrason doit être nommé par la date de livraison en format `YYYYMMDD` ;
  chacun de ces répertoires de livraison contient:
  - d'une part les cartes livrées chacune sous la forme d'une archive `{num}.7z`, où `{num}` est le numéro de la carte
  - d'autre part un fichier `index.yaml` qui documente la livraison et qui peut contenir une propriété `toDelete` contenant
    la liste des cartes à supprimer dans le portefeuille, chacune identifiée par son numéro précédé de 'FR'
- `archives` qui contient un sous-répertoire par livraison de cartes intégrée dans le portefeuille
  avec les mêmes informations que le répertoire de livraison de `incoming`,
  plus, associé à chaque archive `{num}.7z` de carte un fichier `{num}.md.json`
  qui contient quelques champs de métadonnées, spécifiés ci-dessous, extraits des MD ISO 19139 de la carte
- `current` qui contient pour chaque carte dans sa dernière version valide 2 liens symboliques:
  - d'une part vers l'archive 7z de cette dernière version
  - d'autre part vers le fichier de MD simplifié de cette dernière version
  
#### 2.1.1 Spécification du fichier `{num}.md.json`
Le fichier `{num}.md.json` dontient les champs suivants extraits des MD ISO 19139 de la carte:

- `title` : titre de la carte extrait de `//gmd:identificationInfo/*/gmd:citation/*/gmd:title/*`
- `alternate` : titre alternatif éventuel ou '', extrait de //gmd:identificationInfo/*/gmd:citation/*/gmd:alternateTitle/*
- `version` : version de la carte sous la forme `{annee}c{noDeCorrection}` déduit du champ `edition`
- `edition` : recopie du champ `//gmd:identificationInfo/*/gmd:citation/*/gmd:edition/*`
- `ganWeek` : si présent dans le champ ci-dessus, la semaine GAN de mise à jour de la carte, sinon ''
- `ganDate` : si `ganWeek` présente, traduction en date ISO dans le format `YYYY-MM-DD`
- `revision` : recopie de `"//gmd:identificationInfo/*/gmd:citation/*/gmd:date[./gmd:CI_Date/gmd:dateType/*/@codeListValue='revision']/gmd:CI_Date/gmd:date/gco:Date"`
- `creation` : recopie de `"//gmd:identificationInfo/*/gmd:citation/*/gmd:date[./gmd:CI_Date/gmd:dateType/*/@codeListValue='creation']/gmd:CI_Date/gmd:date/gco:Date"`
      
#### 2.1.2 Exemple du fichier `7107.md.json`
Exemple d'un fichier pour lequel le champ `ganWeek` est défini

    {
        "title": "7107 - Port de La Trinité-Sur-Mer, Port du Crouesty - Entrée du Golfe du Morbihan",
        "alternate": "",
        "version": "2023c0",
        "edition": "Edition n° 3 - 2023 - Dernière correction : 0 - GAN : 2319",
        "ganWeek": "2319",
        "ganDate": "2023-05-10",
        "revision": "2023-05-10"
    }
#### 2.1.3 Exemple du fichier `6680.md.json`
Exemple d'un fichier pour lequel le champ `ganWeek` n'est pas défini

    {
        "title": "6680 - De l'Ile d'Ouessant à l'Ile de Batz",
        "alternate": "",
        "version": "2003c87",
        "edition": "Edition n° 3 - 2003 - Dernière correction : 87",
        "ganWeek": "",
        "ganDate": "",
        "revision": "2003-05-01"
    }
#### 2.1.4 Exemple du fichier `7291.md.json`
Exemple d'un fichier pour lequel le champ `alternate` est défini

    {
        "title": "7291 - De Piombino à Fiumicino et côte Est de Corse",
        "alternate": "fac-similé de la carte carte IT n°913 publiée en 1990 (édition février 2020)",
        "version": "2021c4",
        "edition": "Edition n° 3 - 2021 - Dernière correction : 4 - GAN : 2249",
        "ganWeek": "2249",
        "ganDate": "2022-12-07",
        "revision": "2022-12-07"
    }

### 2.2 Dépôt shomgeotiff minimisant le volume de stockage
Pour minimiser le volume de stockage, le répertoire `current` contient les réels fichiers `{num}.7z`
et `{num}.md.json` au lieu des liens symboliques. Le répertoire `archives` n'est alors plus nécessaire.

### 2.3 Problème rencontré avec les dates de validité des cartes
Dans certains cas, la date de révision indiquée dans les MD ISO 19139 est manifestement fausse.

Par exemple, la carte 7259 livrée en juin 2017 mentionne:

    {
        "title": "7259 - Ile Maré",
        "alternate": "",
        "version": "1995c16",
        "edition": "Publication 1995 - Dernière correction : 16",
        "ganWeek": "",
        "ganDate": "",
        "revision": "1995-07-31"
    }

Or le GAN mentionne que la correction 16 a été publiée semaine 1704, soit le 26/1/2017,
ce qui est cohérent avec la date de livraison.  
On peut penser que la date de révision mentionnée est celle de la publication initiale de la carte avant corrections.
Ainsi dans ce cas il n'est pas possible de connaître la vraie date de révision de la carte à partir des MD ISO 19139.

D'autres cas similaires peuvent être mentionnés :

- Carte 6680, correction 87, mentionnée ci-dessus, publiée selon le GAN semaine 2223

- Carte 7271, correction 212, publiée selon le GAN semaine 1504:

      {
          "title": "7271 - Australasie et mers adjacentes",
          "alternate": "fac-similé de la carte AU 4060",
          "version": "1990c212",
          "edition": "Publication 1990 - Dernière correction : 212",
          "ganWeek": "",
          "ganDate": "",
          "revision": "1990-07-03"
      }

- Carte 7349, correction 29, publiée selon le GAN semaine 2039

      {
          "title": "7349 - De la Réunion à Maurice (Mauritius) - Ile Tromelin",
          "alternate": "",
          "version": "1996c29",
          "edition": "Publication 1996 - Dernière correction : 29",
          "ganWeek": "",
          "ganDate": "",
          "revision": "1996-10-21"
      }

## 3. Serveur sgserver
Le serveur de carte est implémenté par le script `index.php` qui inclue le fichier `SevenZipArchive.7z`.  
L'API du serveur est défini dans le [fichier api.yaml](api.yaml).  
Le serveur se fonde sur les fichiers présents dans le répertoire `current` de shomgeotiff,
que ce soient de réels fichiers ou des liens vers les fichiers stockés dans `archives`.
Le contenu des fichiers `{num}.md.json` permet de remplir le champ `lastVersion` du point d'accès `/maps.json`.

## 4. Gestionnaire du portefeuille
Lorsque shomgeotiff conserve l'historique des livraisons de carte, la gestion du portefeuille peut être réalisée
avec l'utilitaire pfm.php en mode CLI.

La procédure consiste à:

1. préparer dans `incoming` un répertoire de livraison ayant comme nom la date de livraison sous la forme `YYYYMMDD`
   et contenant les différentes cartes livrées ainsi que le fichier index.yaml cité en 2.1,
2. exécuter la commande `php pfm.php {nomDuRépertoireDeLivraison}` qui va:
   a. déplacer le répertoire dans `archives`,
   b. générer les fichiers .md.json,
   c. mettre à jour les liens dans `current`

L'utilitaire pfm.php permet aussi par exemple de tracer les changements de version et retrait éventuel des cartes.
