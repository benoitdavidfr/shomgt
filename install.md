# Installation de shomgt sur un serveur Linux

*Benoit DAVID - 10/11/2019*

Cette documentation détaille comment installer *shomgt* sur un serveur Linux en utilisant Docker ;
cela permet notamment d'installer *shomgt* sur un serveur intranet
afin de disposer des cartes Shom (au travers des web-services *wms* et *tile*) sans avoir besoin de connexion internet.

Cette documentation peut aussi être utilisée pour installer *shomgt* sur un serveur sur internet en sécurisant
les différents accès.
Elle peut a priori aussi être utilisée pour installer *shomgt* sur un ordinateur individuel.

L'installation a été testée sur une [VPS OVH](https://www.ovh.com/fr/vps/) sous l'OS `Docker on Ubuntu 16.04 (32 bits)`.

## Pré-requis
Un serveur Linux avec :

  - le logiciel Docker installé
  - un accès ssh au compte root du serveur
  - environ 100 Go de disque

## Principes
Le code source sera installé par `git install` à partir de `https://github.com/benoitdavidfr/shomgt`.  
Ce code contient une définition Docker dans le répertoire `docker`.  
Ce code source sera installé chez un utilisateur Linux nommé `user` qui devra être créé
ce qui créera le répertoire `/home/user`  
Sous Linux chez `user` *shomgt* sera installé dans un répertoire `/home/user/html/shomgt`  
De même chez `user` le répertoire `/home/user/shomgeotiff` contiendra les cartes Shom par livraison
qui seront déposées au moyen d'un serveur ftp.

Le container Docker sera fabriqué (build) puis exécuté (run) en faisant correspondre:

  - le répertoire `/var/www` sous Docker avec `/home/user/` sous Linux
  - le port IP 80 de Docker avec le port IP 80 de Linux
  
Apache sera démarré dans le container Docker.  
Des commandes `bash` (ligne de commande Linux) seront exécutées dans le container pour reformatter les cartes Shom
dans une structure utilisable par *shomgt*.

A la fin de l'installation,
*shomgt* sera disponible sur l'URL `http://{serveur}/shomgt` où `{serveur}` est le nom ou le numéro IP du serveur.

## Mise en oeuvre pas à pas

Se loguer sur la machine Linux sous root et créer l'utilisateur user en répondant aux questions posées,
puis autoriser cet utilisateur à exécuter `sudo`:

    # adduser user
    # adduser user sudo

Se déloguer de root et se loguer sur la machine Linux sous user,
créer un répertoire shomgeotiff et dedans les sous-répertoires indiqués :  

    $ mkdir shomgeotiff
    $ mkdir shomgeotiff/current
    $ mkdir shomgeotiff/incoming
    $ mkdir shomgeotiff/incoming/livraison
    
Copier dans le répertoire livraison les cartes Shom chacune sous la forme d'une archive 7z livrée par le Shom.
Cette copie peut être effectuée par ftp en installant auparavant un serveur ftp sur la machine Linux.
On peut pour cela installer le serveur pure-ftpd (voir https://doc.ubuntu-fr.org/pure-ftp).

    $ sudo apt-get install pure-ftpd
    $ sudo /etc/init.d/pure-ftpd restart

Logué sur la machine Linux sous user, créer un répertoire html et dans ce répertoire cloner le code Github:  

    $ mkdir html
    $ cd html
    $ git clone https://github.com/benoitdavidfr/shomgt
    
Fabriquer le container Docker puis l'exécuter:

    $ sudo docker build -t php-bash shomgt/docker
    $ sudo docker run -p 80:80 -it --rm --name php-bash -h dockerShomgt \
          --mount type=bind,source=/home/user,target=/var/www php-bash
          
Le container est exécuté en interactif donc on se retrouve dans un shell dans le container.

Réaffecter récursivement le répertoire `/var/www` à `www-data:www-data`

    # chown -R www-data:www-data /var/www

Lancer Apache

    # apachectl start

Se mettre sous `www-data` pour reformatter les cartes Shom

    # su - www-data -s /bin/bash

Aller dans le répertoire shomgt et y installer le composant Yaml de Symfony

    $ cd shomgt
    $ composer require symfony/yaml

Aller dans le module de gestion du catalogue des cartes Shom
pour initialiser le fichier du catalogue à partir du fichier JSON fourni sur Github.

    $ cd cat
    $ php build.php storeFromJSON
    
Aller dans le module de mise à jour des cartes et effectuer le refformattage des cartes précédemment stockées
dans le répertoire livraison de /var/www/shomgeotiff/incoming.
Attention la commande php génère du code sh et son résultat doit donc être éxécuté par sh ;
cela se fait en faisant suivre la commande php par `| sh`

    $ cd ../updt
    $ php updt.php livraison | sh

A ce stade, les cartes Shom installées sont utilisables dans *shomgt* avec les services *wms* et *tile*.  
**Attention, en sortant du shell on arrête le container et donc le serveur Apache**.

## Points de vigilance

- Dans les commandes ci-dessus, le caractère initial `#` ou `$` ne doit pas être tapé.

- Le fait d'arrêter le serveur lorsque l'on sort du shell peut être considéré comme génant ;
  une mise à jour pourra être faite pour améliorer ce point.
  
- Si le container est arrêté, il peut être relancé par la commande ci-dessous, le serveur Apache doit aussi être relancé:

        $ sudo docker run -p 80:80 -it --rm --name php-bash -h dockerShomgt \
            --mount type=bind,source=/home/user,target=/var/www php-bash
        # apachectl start

- Les fichiers dans `/home/user` sous Linux et dans `/var/www` sous Docker sont les mêmes.  
  Lorsque l'on travaille sous Linux, ils doivent appartenir à user
  alors que lorsque l'on travaille sous Docker ils doivent appartenir à www-data.
  Il peut donc être nécessaire de changer leurs droits.  
  Sous Docker sous root pour affecter les droits à www-data taper la commande :
  
        # chown -R www-data:www-data /var/www
        
  Sous Linux pour affecter les droits à user taper la commande :
  
        $ sudo chown -R user:user /home/user

## Ajout incrémental de cartes Shom
Il est possible d'ajouter incrémentalement des cartes Shom, pour cela :

  - sous Linux chez user créer un répertoire dans /home/user/shomgeotiff/incoming et y déposer les cartes Shom à ajouter 
  - puis aller sous Docker chez www-data et aller dans le module updt

        # su - www-data -s /bin/bash
        $ cd ~/html/shomgt/updt
        $ php updt.php {nouvelle_livraison} | sh
        
    où `{nouvelle_livraison}` est le nom du répertoire contenant les nouvelles cartes.  
    Il est conseillé d'utiliser des noms explicites, par exemple la date le livraison.
    
## Détection de cartes à actualiser

Le module de gestion du catalogue permet de connaitre les cartes à actualiser.
Le catalogue des cartes Shom est lui-même actualisé à partir, d'une part, du flux WFS du Shom
et, d'autre part, des Groupes d'Avis aux Navigateurs (GAN) des cartes.
Avant de consulter les cartes à actualiser, il faut actualiser le catalogue.
Pour cela aller sous Docker chez www-data et aller dans le module updt

    # su - www-data -s /bin/bash
    $ cd ~/html/shomgt/cat

La première chose à faire est de moissonner les GAN, cette opération prend un peu de temps.

    $ php build.php harvestGan
    
Une fois ce moissonnage effectué correctement, il convient de créer fichier du catalogue:
    $ php build.php store
 
Il est ensuite possible consulter le catalogue à l'URL `http://{serveur}/shomgt/cat`
et notamment les cartes à actualiser.

