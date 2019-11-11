# Installation de shomgt sur un serveur Linux

*Benoit DAVID - 11/11/2019 (v2)*

Cette documentation détaille comment installer *shomgt* sur un serveur Linux utilisant Docker ;
cela permet notamment de disposer des cartes Shom (au travers des web-services *wms* et *tile*)
sans avoir besoin d'une connexion internet en installant *shomgt* sur un serveur intranet.

Cette documentation peut aussi être utilisée pour installer *shomgt* sur un serveur sur internet en sécurisant
les différents accès.
Elle peut aussi être utilisée a priori pour installer *shomgt* sur un ordinateur individuel.

L'installation a été testée sur une [VPS OVH](https://www.ovh.com/fr/vps/) sous l'OS `Docker on Ubuntu 16.04 (32 bits)`.
Cependant elle est encore expérimentale.
Si vous mettez en oeuvre cette procédure, merci de m'en faire un retour.

## 1) Pré-requis
Un serveur Linux avec :

  - le logiciel Docker installé
  - un accès ssh au compte root du serveur
  - environ 100 Go de disque (cet espace dépend du nombre de cartes, qqs Go peuvent être suffisants pour qqs cartes)
  
Shomgt s'éxécutant dans un conteneur Docker, il est théoriquement possible d'effectuer l'installation
sur toute machine supportant Docker mais cela n'a pas été testé.

## 2) Principes
Le code source sera installé par un utilisateur Linux nommé `user`,
qui devra auparavant être créé, ce qui créera le répertoire `/home/user`.  
Cette installation sera effectuée dans le répertoire `/home/user/html/shomgt` 
par `git install` à partir de `https://github.com/benoitdavidfr/shomgt`.  
De plus chez `user` le répertoire `/home/user/shomgeotiff` contiendra les cartes Shom organisées par livraison
qui pourront être déposées au moyen d'un serveur ftp.

Le code source contient la définition du conteneur Docker dans lequel s'éxécutera le code Php.
Ce conteneur fait correspondre:

  - le répertoire `/home/user/` sous Linux avec `/var/www` sous Docker
  - le port IP 80 de Linux avec le port IP 80 de Docker
  
Le serveur Apache sera démarré dans le conteneur Docker.  
Des commandes `bash` (ligne de commande Linux) seront exécutées dans le conteneur pour reformatter les cartes Shom
dans une structure utilisable par *shomgt*.

A l'issue de l'installation,
*shomgt* sera disponible sur l'URL `http://{serveur}/shomgt` où `{serveur}` est le nom ou le numéro IP du serveur.

## 3) Remarque préliminaire
Dans les commandes ci-dessous, les caractères initiaux `#`, `$`, `docker#` ou `docker$` ne doivent pas être tapés.
Ils rappellent l'environnement dans lequel la commande doit être éxécutée: 
  
  - `#` indique que l'on doit être sur le serveur Linux comme utilisateur root
  - `$` indique que l'on doit être sur le serveur Linux comme utilisateur user
  - `docker#` indique que l'on doit être dans le conteneur Docker comme utilisateur root
  - `docker$` indique que l'on doit être dans le conteneur Docker comme utilisateur www-data

## 4) Mise en oeuvre pas à pas

a) Se loguer sur la machine Linux sous root et créer l'utilisateur user en répondant aux questions posées,
puis autoriser cet utilisateur à exécuter `sudo`,
enfin se déloguer:

    # adduser user
    # adduser user sudo
    # exit

b) Se loguer sur la machine Linux sous user,
créer un répertoire shomgeotiff et dedans les sous-répertoires indiqués :  

    $ mkdir shomgeotiff
    $ mkdir shomgeotiff/current
    $ mkdir shomgeotiff/incoming
    $ mkdir shomgeotiff/incoming/0
    
c) Copier dans le répertoire `0` les cartes Shom chacune sous la forme d'une archive 7z livrée par le Shom.
Cette copie peut être effectuée par ftp en installant auparavant un serveur ftp sur la machine Linux.
On peut pour cela installer le serveur pure-ftpd (voir https://doc.ubuntu-fr.org/pure-ftp).

    $ sudo apt-get install pure-ftpd
    $ sudo /etc/init.d/pure-ftpd restart

d) Toujours logué sur la machine Linux sous user, créer un répertoire html et dans ce répertoire cloner le code Github:  

    $ mkdir html
    $ cd html
    $ git clone https://github.com/benoitdavidfr/shomgt
    
e) Fabriquer le conteneur Docker nommé `php72sgt` puis le lancer:

    $ sudo docker build -t php72sgt shomgt/docker
    $ sudo docker run -p 80:80 -d --name php72sgt -h docker \
          --mount type=bind,source=/home/user,target=/var/www php72sgt
          
Le conteneur s'exécute en tache de fond en lançant le serveur Apache.

f) La commande `docker exec` permet de lancer des commandes dans le conteneur.
Cette fonctionnalité est utilisée pour démarrer un bash dans le conteneur soit sous root, soit sous www-data.  
Se mettre dans le conteneur Docker sous root pour réaffecter récursivement le répertoire `/var/www` à `www-data:www-data`

    $ sudo docker exec -it --user=root php72sgt /bin/bash
    docker# chown -R www-data:www-data /var/www
    docker# exit

g) Se mettre dans le conteneur Docker sous `www-data` pour reformatter les cartes Shom

    $ sudo docker exec -it --user=www-data php72sgt /bin/bash 

h) Aller dans le répertoire shomgt et y installer le composant Yaml de Symfony

    docker$ cd ~/html/shomgt
    docker$ composer require symfony/yaml

i) Aller dans le module de gestion du catalogue des cartes Shom
pour initialiser le fichier du catalogue à partir du fichier JSON fourni sur Github.

    docker$ cd cat
    docker$ php build.php storeFromJSON
    
j) Aller dans le module de mise à jour des cartes et effectuer le refformattage des cartes précédemment stockées
dans le répertoire `/var/www/shomgeotiff/incoming/0`.
Attention la commande php génère du code sh et son résultat doit donc être éxécuté par sh ;
cela se fait en faisant suivre la commande php par `| sh`

    docker$ cd ../updt
    docker$ php updt.php 0 | sh

A ce stade, les cartes Shom installées sont utilisables dans *shomgt* avec les services *wms* et *tile*.  
**Vérifier cette installation au moyen de la carte Leaflet *mapwcat*
disponible sur `http://{serveur}/shomgt/mapwcat.php`**  
Le service *wms* est disponible à l'URL `http://{serveur}/shomgt/wms.php`

## 5) Arrêt/relance du serveur Shomgt
Pour arrêter le serveur *shomgt*, c'est à dire le serveur Apache dans le conteneur,
il faut sous Linux arrêter le conteneur Docker appelé `php72sgt`:

    $ sudo docker stop php72sgt
    $ sudo docker rm php72sgt

Pour le relancer, relancer sous Linux le conteneur Docker appelé `php72sgt`:

    $ sudo docker run -p 80:80 -d --name php72sgt -h docker --mount type=bind,source=/home/user,target=/var/www php72sgt

## 6) Points de vigilance

- Les fichiers dans `/home/user` sous Linux et dans `/var/www` sous Docker sont les mêmes.  
  Lorsque l'on travaille sous Linux, ils doivent appartenir à user
  alors que lorsque l'on travaille sous Docker ils doivent appartenir à www-data.
  Il peut donc être nécessaire de changer leurs droits.  
  Sous Docker sous root pour affecter les droits à www-data taper la commande :
  
        docker# chown -R www-data:www-data /var/www
        
  Sous Linux sous user pour affecter les droits à user taper la commande :
  
        $ sudo chown -R user:user /home/user

## 7) Ajout incrémental de cartes Shom
Pour ajouter incrémentalement des cartes Shom :

  - réaffecter les droits à user en tapant sous Linux sous user la commande :
  
        $ sudo chown -R user:user /home/user
  
  - sous Linux et chez user créer un répertoire `{nouvelle_livraison}` dans `/home/user/shomgeotiff/incoming`
    et y déposer les cartes Shom à ajouter.
    `{nouvelle_livraison}` est le nom du répertoire contenant les nouvelles cartes ;
    il est conseillé d'utiliser des noms explicites, par exemple la date le livraison en format YYYYMMDD.
    
        $ mkdir ~/shomgeotiff/incoming/{nouvelle_livraison}

  - puis aller sous Docker chez root et réaffecter les droits à www-data

        $ sudo docker exec -it --user=root php72sgt /bin/bash
        docker# chown -R www-data:www-data /var/www
        docker# exit

  - puis aller sous Docker chez www-data et effectuer la mise à jour dans le module updt

        $ sudo docker exec -it --user=www-data php72sgt /bin/bash 
        docker$ cd ~/html/shomgt/updt
        docker$ php updt.php {nouvelle_livraison} | sh
        
    
## 8) Détection de cartes à actualiser

Le module de gestion du catalogue permet de connaitre les cartes à actualiser.
Le catalogue des cartes Shom est lui-même actualisé à partir, d'une part, du flux WFS du Shom
et, d'autre part, des Groupes d'Avis aux Navigateurs (GAN) des cartes.
Avant de consulter les cartes à actualiser, il faut actualiser le catalogue.
Pour cela aller sous Docker chez www-data et aller dans le module cat

    $ sudo docker exec -it --user=www-data php72sgt /bin/bash 
    docker$ cd ~/html/shomgt/cat

La première chose à faire est de moissonner les GAN, cette opération prend une quinzaine de minutes.

    docker$ php build.php harvestGan
    
Une fois ce moissonnage effectué correctement, il convient de créer fichier du catalogue:

    docker$ php build.php store
 
Ce catalogue peut ensuite être consulté à l'URL `http://{serveur}/shomgt/cat`
et notamment les cartes à actualiser.  
Il convient alors de récupérer ces cartes, par exemple auprès du Shom,
et de les intégrer dans *shomgt* au moyen de la procédure décrite ci-dessus section 7.

## 9) Sécurisation de shomgt

Par défaut, aucun mécanisme de contrôle d'accès n'est mis en oeuvre
ce qui correspond à une utilisation du serveur en intranet.  
Pour limiter l'accès à shomgt, notamment en cas d'installation sur internet,
il convient de créer un fichier `secretconfig.inc.php` dans le répertoire `ws`
en prenant comme exemple le fichier `config.inc.php`.
Une fois créé le fichier `secretconfig.inc.php` remplacera le fichier `config.inc.php`.

## 10) Enregistrement des logs d'appels

De même, par défaut, les logs d'appel sont désactivés.
Pour les activer, il convient dans le fichier `secretconfig.inc.php` de configurer le serveur et la base MySQL
dans lesquels sera créée la table de log.
