# Contrôle d'accès

Le principe du contrôle d'accès est d'autoriser l'accès ssi un des 3 contrôles suivants est satisfait :

  1. vérification que l'IP d'appel appartient à une liste blanche prédéfinie, ce mode permet notamment d'autoriser
     les requêtes provenant du RIE. Il est utilisé pour toutes les fonctionnalités.
  2. vérification qu'un cookie contient un login/mot de passe, utilisé pour les accès Web depuis un navigateur.
  3. authentification HTTP Basic, utilisé pour le service WMS.

Pour la vérification du cookie, la page login.php permet de stocker dans le cookie le login/mdp

Toute la logique de contrôle d'accès est regroupée dans la classe Access définie dans le fichier `lib/accesscntrl.inc.php`.

Les données de contrôle d'accès sont stockées dans le fichier `secrets/secretconfig.inc.php`.
Si ce fichier n'existe pas alors le contrôle d'accès n'est pas activé.

Le fichier `secrets/secretconfig.inc.php` contient une fonction `config(string $rubrique): array|string`
qui retourne les données organisées selon les rubriques suivantes:

- `cntrlFor` correspond à un array indiquant si le contôle est activé pour chacune des fonctionnalités de ShomGT3
  dont la liste est la suivante:
    - `wms` - le serveur WMS
    - `tile` - l'API d'accès aux tuiles
    - `homePage` - la page d'accueil de ShomGT3
    - `geoTiffCatalog` - le catalogue des GéoTiff
    - `sgServer` - le serveur de cartes 7z de ShomGT3
  
  Le tableau doit retourner vrai si le contrôle est activé pour cette fonctionnalité ou faux sinon.
- `ipV4WhiteList` correspond à la liste des adresses IP v4 autorisées (liste blanche)
- `ipV6PrefixWhiteList` correspond à la liste des préfixes des adresses IP v6 autorisées (liste blanche)
- `ipV4BlackList` correspond à la liste des adresses IP v4 interdites pour le contrôle d'accès aux tuiles
- `ipV6PrefixBlackList` correspond à la liste des prefixes des adresses IP v6 interdites pour le contrôle d'accès aux tuiles
- `loginPwds` correspond à la liste des logins/mots de passe codés dans une chaine avec un ':' entre le login et le mot de passe.
- `admins` correspond à la liste des logins/mots de passe des administrateurs codés ed la même manière.

Le fichier [`secrets/secretconfig.modele.inc.php`](../secrets/secretconfig.modele.inc.php) peut être utilisé comme modèle
de fichier `secrets/secretconfig.inc.php`.