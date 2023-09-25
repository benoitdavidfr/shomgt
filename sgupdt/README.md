# Module sgupdt de ShomGT
L'objectif de ce module est de construire et mettre à jour dans le répertoire [data](../data)
les fichiers nécessaires à [view](../view),
à partir des fichiers 7z fournis par le Shom exposés par [sgserver](../sgserver) et le catalogue [mapcat](../mapcat).

## Expositions
Ce module expose le script `main.php` qui doit être appelé en CLI pour effectuer la mise à jour du répertoire data.

## Variables d'environnement

- `SHOMGT3_SERVER_URL` doit contenir l'URL du serveur de cartes 7z sgserver
  qui doit contenir si nécessaire le login/passwd
- `SHOMGT3_UPDATE_DURATION`contient le délai en jours entre 2 mises à jour (défaut 28)
- `http_proxy` contient si nécessaire le proxy pour accéder à sgserver, défaut pas de proxy
- `https_proxy` contient si nécessaire le proxy à utiliser pour le serveur de cartes 7z, défaut pas de proxy

## Documentation du code
Ce module correspond au [package shomgt\sgupdt
de la doc PhphDoc](https://benoitdavidfr.github.io/shomgt/phpdoc/packages/shomgt-sgupdt.html).
