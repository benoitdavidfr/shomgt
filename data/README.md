# Organisation des fichiers dans data
**data** est un sous-répertoire du répertoire principal de ShomGT qui contient les données exploitées
par [le composant shomgt](../shomgt) et produites par le [composant sgupdt](../sgupdt).
Lorsque ces composant sont déployés comme conteneur Docker, *data* est un volume partagé entre ces conteneurs.

Outre ce fichier de documentation, *data* contient un fichier et 3 sous-répertoires:

- le [fichier shomgt.yaml](shomgt.yaml)
- le sous-répertoire maps
- le sous-répertoire tilecache
- le sous-répertoire temp