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
  chacun de ces répertoires de livraison contient:
  - d'une part les cartes livrées chacune sous la forme d'une archive `{num}.7z` où `{num}` est le numéro de la carte
  - d'autre part un fichier `index.yaml` qui documente la livraison et qui peut contenir une propriété `toDelete` contenant
    la liste des cartes à supprimer dans le portefeuille, chacune identifiée par son numéro précédé de 'FR'
- `archives` qui contient un sous-répertoire par livraison de cartes intégrée dans le portefeuille
  avec les mêmes informations que le répertoire de livraison de incoming
  plus associé à chaque archive {num}.7z de carte un fichier {num}.md.json qui contient quelques champs de métadonnées
  extraits des MD ISO 19139 de la carte spécifiés ci-dessous
- `current` qui contient pour chaque carte dans sa dernière version valide 2 liens symboliques:
  - d'une part vers l'archive 7z de cette dernière version
  - d'autre part vers le fichier de MD simplifié de cette dernière version
  
#### 2.1.1 Spécification du fichier `{num}.md.json`
Le fichier `{num}.md.json` dontient les champs suivants extraits des MD ISO 19138 de la carte:

- `title` : titre de la carte extrait de `//gmd:identificationInfo/*/gmd:citation/*/gmd:title/*`
- `alternate` : titre alternatif éventuel ou '', extrait de //gmd:identificationInfo/*/gmd:citation/*/gmd:alternateTitle/*
- `version` : version de la carte sous la forme {annee}c{noDeCorrection}
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
#### 2.1.3 Exemple du fichier `6619.md.json`
Exemple d'un fichier pour lequel le champ `ganWeek` n'est pas défini

    {
        "title": "6619 - Côte Sud-Est de l'Amérique du Nord, Bahamas et Grandes Antilles",
        "alternate": "fac-similé de la carte carte US n°108 publiée en 1972 (édition décembre 1984).",
        "version": "1988c197",
        "edition": "Edition n° 3 - 1988 - Dernière correction : 197",
        "ganWeek": "",
        "ganDate": "",
        "revision": "1988-01-01"
    }
#### 2.1.4 Exemple du fichier `7291.md.json`
Exemple d'un fichier pour lequel le champ `alternate` est défini

    {
        "title": "7291 - De Piombino à Fiumicino et côte Est de Corse",
        "alternate": "fac-similé de la carte carte IT n°913 publiée en 1990.",
        "version": "2018c18",
        "edition": "Edition n° 2 - 2018 - Dernière correction : 18",
        "ganWeek": "",
        "ganDate": "",
        "revision": "2018-03-23"
    }

### 2.2 Dépôt shomgeotiff minimisant le volume de stockage
[[[TO DO]]]

## 3. Serveur sgserver
[[[TO DO]]]

## 4. Gestionnaire du portefeuille
[[[TO DO]]]

## 5. Cartes particulières
[[[TO DO]]]
