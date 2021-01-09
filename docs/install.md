# Installation de shomgt sur un serveur Linux

*Benoit DAVID - 9/1/2021 (v2.2.1)*

### Evolutions récentes
- 9/1/2021 : évolution de la procédure
- 20/12/2020 : passage à Php 8
- 22/11/2019 : ajout d'une section sur la mise à jour du logiciel

Cette documentation détaille comment installer *shomgt* sur un serveur Linux utilisant Docker ;
cela permet notamment de disposer des cartes Shom
sans avoir besoin d'une connexion internet en installant *shomgt* sur un serveur intranet.

Cette documentation peut aussi être utilisée pour installer *shomgt* sur un serveur sur internet en sécurisant
les différents accès.
Elle peut aussi enfin a priori être utilisée pour installer *shomgt* sur un ordinateur individuel.

L'installation a été testée sur une [VPS OVH](https://www.ovh.com/fr/vps/) sous l'OS `Docker on Ubuntu 16.04 (32 bits)`.
Elle a été aussi mise en oeuvre avec succès par la DIRM NAMO pour installer *shomgt* sur un serveur Ubuntu 18.04.3
sur un ESX avec 'Docker' en package Debian et derrière un proxy,
à l'exception de la détection de cartes à actualiser qui ne fonctionne pas actuellement derrière un proxy.

## 1) Pré-requis
Un serveur Linux avec :

  - le logiciel Docker installé
  - un accès ssh au compte root du serveur
  - au moins 20 Go de disque (cet espace dépend du nombre de cartes)
  - savoir si le serveur est installé derrière un proxy et dans ce cas connaitre son URL
  
Shomgt s'éxécutant dans un conteneur Docker, il est théoriquement possible d'effectuer l'installation
sur toute machine supportant Docker mais cela n'a pas été testé.

## 2) Principes
Le code source sera installé par un utilisateur Linux nommé `user`,
qui devra auparavant être créé, ce qui créera le répertoire `/home/user`.  
Cette installation sera effectuée dans le répertoire `/home/user/html/shomgt` 
par `git install` à partir de `https://github.com/benoitdavidfr/shomgt`.  
De plus chez `user` le répertoire `/home/user/shomgeotiff` contiendra les cartes Shom.

Le code source contient la définition du conteneur Docker dans lequel s'éxécutera le code Php.
Ce conteneur fait correspondre:

  - le répertoire `/home/user/` sous Linux avec `/var/www` sous Docker
  - le port IP 80 de Linux avec le port IP 80 de Docker
  
Le serveur Apache sera démarré dans le conteneur Docker.  
Des commandes `bash` (ligne de commande Linux) seront exécutées dans le conteneur pour télécharger et reformatter les cartes Shom
dans une structure utilisable par *shomgt*.

A l'issue de l'installation,
*shomgt* sera disponible sur l'URL `http://{serveur}/shomgt` où `{serveur}` est le nom ou le numéro IP du serveur.

## 3) Remarques préliminaires
Dans les commandes ci-dessous, les caractères initiaux `#`, `$`, `docker#` ou `docker$` ne doivent pas être tapés.
Ils rappellent l'environnement dans lequel la commande doit être éxécutée: 
  
  - `#` indique que l'on doit être sur le serveur Linux comme utilisateur root
  - `$` indique que l'on doit être sur le serveur Linux comme utilisateur user
  - `docker#` indique que l'on doit être dans le conteneur Docker comme utilisateur root
  - `docker$` indique que l'on doit être dans le conteneur Docker comme utilisateur www-data

Lors de l'installation d'un serveur *shomgt* derrière un proxy, celui-ci doit être configuré dans 4 endroits:

  - lors de l'installation du serveur Ubuntu,
  - lors de l'utilisation de git,
  - lors de la création du conteneur Docker,
  - lors de l'actualisation du fichier mapcat.json par exemple avec wget.


## 4) Mise en oeuvre pas à pas

a) Se loguer sur la machine Linux sous `root` et créer l'utilisateur `user` en répondant aux questions posées,
puis autoriser cet utilisateur à exécuter `sudo`,
enfin se déloguer:

    # adduser user
    # adduser user sudo
    # exit

b) Si le serveur est installé derrière un proxy alors configurer l'utilisation de ce proxy par git:

    $ git config --global http.proxy http://monproxy.mondomaine:8080

c) Toujours logué sur la machine Linux sous user, créer un répertoire html et dans ce répertoire cloner le code Github:  

    $ mkdir ~/html
    $ cd ~/html
    $ git clone https://github.com/benoitdavidfr/shomgt
    
d) Si le serveur est installé derrière un proxy alors configurer l'utilisation de ce proxy par docker ;
pour cela voir la doc sur [https://docs.docker.com/config/daemon/systemd/#httphttps-proxy](https://docs.docker.com/config/daemon/systemd/#httphttps-proxy).

e) Fabriquer le conteneur Docker nommé `php8sgt` puis le lancer:

    $ sudo docker build -t php8sgt shomgt/docker
    $ sudo docker run -p 80:80 -d --name php8sgt -h docker \
          --mount type=bind,source=/home/user,target=/var/www php8sgt

Le conteneur s'exécute en tache de fond en lançant le serveur Apache.

f) La commande `docker exec` permet de lancer des commandes dans le conteneur.
Elle est utilisée pour démarrer un bash dans le conteneur soit sous l'utilisateur `root`,
soit sous l'utilisateur `www-data`.  
Dans le conteneur Docker sous l'utilisateur `root`,
réaffecter récursivement la propriété du répertoire `/var/www` à `www-data:www-data`:

    $ sudo docker exec -it --user=root php8sgt /bin/bash
    docker# chown -R www-data:www-data /var/www
    docker# exit

g) Se mettre dans le conteneur Docker sous `www-data` pour télécharger et reformatter les cartes Shom

    $ sudo docker exec -it --user=www-data php8sgt /bin/bash 

h) Aller dans le répertoire shomgt et y installer le composant Yaml de Symfony

    docker$ cd ~/html/shomgt
    docker$ composer require symfony/yaml

i) Aller dans le module de mise à jour des cartes pour télécharger puis reformatter les cartes souhaitées.
Commencer par définir si nécessaire le proxy à utiliser en le définissant dans la variable shell `http_proxy`,
par exemple:

    export http_proxy='http://monproxy.mondomaine:8080'

Puis définir si nécessaire le login et mot de passe d'accès au maitre dans la variable `shomgtuserpwd`, par exemple:

    export shomgtuserpwd='demo:demo'

Les cartes peuvent être sélectionnées par zone définie par son code ISO alpha 2, FX pour métropole, RE pour La Réunion, ...
Les codes FR pour toute la France ou WLD pour toutes les cartes peuvent aussi être utilisés.
Attention la commande php génère du code sh et son résultat doit donc être éxécuté par sh ;
cela se fait en faisant suivre la commande php par `| sh`

    docker$ cd updt
    docker$ php slaveupdt.php RE | sh

Les cartes Shom de la zone choisie sont utilisables dans *shomgt* avec les services *wms* et *tile*.  
**Vérifier cette installation au moyen de la carte Leaflet *mapwcat*
disponible sur `http://{serveur}/shomgt/mapwcat.php`**  
Le service *wms* est disponible à l'URL `http://{serveur}/shomgt/wms.php`

## 5) Arrêt/relance du serveur Shomgt
Pour arrêter le serveur *shomgt*, c'est à dire le serveur Apache dans le conteneur,
il faut sous Linux arrêter le conteneur Docker appelé `php8sgt`:

    $ sudo docker stop php8sgt
    $ sudo docker rm php8sgt

Pour le relancer, relancer sous Linux le conteneur Docker appelé `php8sgt`:

    $ sudo docker run -p 80:80 -d --name php8sgt -h docker \
          --mount type=bind,source=/home/user,target=/var/www php8sgt

## 6) Points de vigilance

- Les fichiers dans `/home/user` sous Linux et dans `/var/www` sous Docker sont les mêmes.  
  Lorsque l'on travaille sous Linux, ils doivent appartenir à `user`
  alors que lorsque l'on travaille sous Docker ils doivent appartenir à `www-data`.
  Il peut donc être nécessaire de changer leurs droits.  
  Sous Docker sous root pour affecter la propriété à www-data taper la commande :
  
        docker# chown -R www-data:www-data /var/www
        
  Sous Linux sous user pour affecter la propriété à user taper la commande :
  
        $ sudo chown -R user:user /home/user

## 7) Mise à jour des cartes Shom
Les cartes peuvent être mises à jour en exécutant à nouveau l'étape 4.i.

## 8) Mise à jour du logiciel shomgt

Shomgt peut être mis à jour à partir du code sur Github. Pour cela:

a) arrêter le serveur Shomgt :

    $ sudo docker stop php8sgt
    $ sudo docker rm php8sgt

b) réaffecter la propriété des fichiers à `user` :
  
    $ sudo chown -R user:user /home/user

c) se placer dans le répertoire `shomgt` et effectuer un `git pull` qui synchronise les fichiers avec Github :

    $ cd ~/html/shomgt
    $ git pull https://github.com/benoitdavidfr/shomgt

d) si le `git pull` échoue en raison de 2 fichiers `cat/mapcat.json` et `ws/shomgt.yaml` impossible à fusionner (merge)
alors les effacer puis effectuer à nouveau le `git pull` :

    $ rm cat/mapcat.json
    $ rm ws/shomgt.yaml
    $ git pull https://github.com/benoitdavidfr/shomgt

e) réaffecter la propriété des fichiers à `www-data` :

    $ sudo docker exec -it --user=root php8sgt /bin/bash
    docker# chown -R www-data:www-data /var/www
    docker# exit

f) relancer le serveur Shomgt:

    $ sudo docker run -p 80:80 -d --name php8sgt -h docker \
          --mount type=bind,source=/home/user,target=/var/www php8sgt

## 9) Sécurisation de shomgt

Par défaut, aucun mécanisme de contrôle d'accès n'est mis en oeuvre,
ce qui correspond à une installation du serveur en intranet.  
Pour limiter l'accès à shomgt, notamment en cas d'installation sur internet,
il convient de créer un fichier `secretconfig.inc.php` dans le répertoire `~/html/shomgt/ws`
en copiant la fonction config() du fichier `config.inc.php` et en indiquant:

  - dans le champ `cntrlFor` les fonctionnalités à contrôler,
  - dans le champ `ipWhiteList` les adresses IP autorisées,
  - dans le champ `loginPwds` les couples login/password autorisés.

Une fois ce fichier `secretconfig.inc.php` défini, cette fonction config() remplacera celle définie dans le fichier `config.inc.php`.

## 10) Enregistrement des logs d'appels

De même, par défaut, les logs d'appel sont désactivés.
Pour les activer, il convient :

  - de créer sur un serveur une base MySql dans laquelle une table de log sera automatiquement créée,
  - de référencer cette base dans le fichier `config.inc.php` (ou `secretconfig.inc.php` s'il est créé)
    en indiquant dans le champ `mysqlParams` le serveur et la base MySQL dans lesquels sera créée la table de log
    ainsi que les login et mots de passe de connexion à MySQL.  
    `mysqlParams` est un tableau indexé par le nom du serveur utilisé (sous Php `$_SERVER['HTTP_HOST']`).

