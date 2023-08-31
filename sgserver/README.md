# Module sgserver de ShomGT3
L'objectif de sgserver est d'exposer à *sgupdt* les cartes du Shom gérées dans un répertoire appelé **shomgeotiff**.
Il est mis à jour régulièrement grâce à *dashboard*.

## 1. Variables d'environnement
- `SHOMGT3_PORTFOLIO_PATH`: chemin du répertoire shomgeotiff  
  Ce répertoire ne doit pas être accessible depuis internet pour éviter un contournement du contrôle d'accès

## 2. Entrepôt shomgeotiff des cartes
Le répertoire shomgeotiff contient 2 sous-répertoires:

- `archives` qui contient un sous-répertoire par carte du portefeuille contenant d'une part la liste des versions conservées
  pour cette carte avec pour chaque version:
  - un fichier `{mapNum}-{version}.7z` de carte correspondant au fichier 7z livré par le Shom,
  - un fichier `{mapNum}-{version}.md.json` contenant quelques champs de métadonnées,
    spécifiés ci-dessous, extraits des MD ISO 19139 de la carte
  et, d'autre part, si la carte est obsolète un fichier `{num}-{date}.md.json` contenant `{"status": "obsolete"}`
- `current` qui contient
  - d'une part pour chaque carte non obsolète 2 liens symboliques:
    - vers l'archive 7z de sa dernière version
    - vers le fichier de MD simplifié de sa dernière version
  - d'autre part pour chaque carte obsolète 1 lien symbolique vers le le fichier `{num}-{date}.md.json`
  
#### 2.1.1 Spécification du fichier `{num}.md.json`
Le fichier `{num}.md.json` dontient les champs suivants extraits des MD ISO 19139 de la carte:

- `title` : titre de la carte extrait de `//gmd:identificationInfo/*/gmd:citation/*/gmd:title/*`
- `alternate` : titre alternatif éventuel ou '', extrait de //gmd:identificationInfo/*/gmd:citation/*/gmd:alternateTitle/*
- `version` : version de la carte sous la forme `{annee}c{#correction}` déduit du champ `edition`
- `edition` : recopie du champ `//gmd:identificationInfo/*/gmd:citation/*/gmd:edition/*`
- `gan`: si la semaine GAN est définie dans le champ edition alors structure composée des 2 sous-champs:
  - `week`: le numéro de la semaine fourni par le Shom
  - `date`: la date correspondante au numéro de la semaine
- `ganWeek` : si présent dans le champ ci-dessus, la semaine GAN de mise à jour de la carte, sinon null
- `ganDate` : si `ganWeek` présente, traduction en date ISO dans le format `YYYY-MM-DD`
- `dateMD` : date de création ou mise à jour structurée par les 2 sous-champs 
  - `type` : 'creation' ou 'revision'
  - `value` : valeur de la date sous la forme YYYY-MM-DD
- `dateArchive`: date du fichier tif/pdf principal dans l'archive, dans de nombreux cas la meilleure estimation de date

#### 2.1.2 Exemple du fichier `7107.md.json`
Exemple d'un fichier pour lequel le champ `ganWeek` est défini

    {
        "title": "7107 - Port de La Trinité-Sur-Mer, Port du Crouesty - Entrée du Golfe du Morbihan",
        "alternate": "",
        "version": "2023c0",
        "edition": "Edition n° 3 - 2023 - Dernière correction : 0 - GAN : 2319",
        "gan": {
            "week": "2319",
            "date": "2023-05-10"
        },
        "dateMD": {
            "type": "revision",
            "value": "2023-05-10"
        },
        "dateArchive": "2023-05-11"
    }
#### 2.1.3 Exemple du fichier `6680.md.json`
Exemple d'un fichier pour lequel le champ `ganWeek` n'est pas défini

    {
        "title": "6680 - De l'Ile d'Ouessant à l'Ile de Batz",
        "alternate": "",
        "version": "2003c87",
        "edition": "Edition n° 3 - 2003 - Dernière correction : 87",
        "gan": null,
        "dateMD": {
            "type": "revision",
            "value": "2003-05-01"
        },
        "dateArchive": "2022-06-09"
    }
#### 2.1.4 Exemple du fichier `7291.md.json`
Exemple d'un fichier pour lequel le champ `alternate` est défini

    {
        "title": "7291 - De Piombino à Fiumicino et côte Est de Corse",
        "alternate": "fac-similé de la carte carte IT n°913 publiée en 1990 (édition février 2020)",
        "version": "2021c4",
        "edition": "Edition n° 3 - 2021 - Dernière correction : 4 - GAN : 2249",
        "gan": {
            "week": "2249",
            "date": "2022-12-07"
        },
        "dateMD": {
            "type": "revision",
            "value": "2022-12-07"
        },
        "dateArchive": "2022-12-08"
    }

### 2.3 Problème rencontré avec les dates de validité des cartes
Dans certains cas, la date de révision indiquée dans les MD ISO 19139 est manifestement fausse.

Dans l'exemple de la carte 6680 ci-dessus, la date de révision (2003-05-01) n'est pas la date de la correction 87
mais la date de la version initiale de l'édition de 2003.
Ainsi dans ce cas il n'est pas possible de connaître la vraie date de révision de la carte à partir des MD ISO 19139.

## 3. Serveur sgserver
Le serveur de carte est implémenté par le script `index.php` qui inclue le fichier `SevenZipArchive.php`.  
L'API du serveur est défini dans le [fichier api.yaml](api.yaml).  
Le serveur se fonde sur les liens présents dans le répertoire `current` de shomgeotiff.
Le contenu des fichiers `{num}.md.json` permet de remplir le champ `lastVersion` du point d'accès `/maps.json`
ou d'indiquer que la carte est obsolète.

## 4. Erreurs rencontrées avec l'année d'édition des cartes
Dans certains cas, l'année d'édition mentionnée dans les MD ISO 19139 est fausse.
Il est donc utile de vérifier cette année en la comparant à celle indiquée sur la carte et si nécessaire de la corriger
avec un éditeur de texte.

