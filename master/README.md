## Mise en oeuvre d'un mode maitre/esclave

### Principe
Le maitre expose au moyen d'un fil Atom son catalogue, ses cartes courantes et sa liste des cartes obsolètes.  
L'esclave consulte régulièrement ce fil pour :

  - mettre à jour si nécessaire son catalogue,
  - identifier les nouvelles cartes, les télécharger, puis les installer,
  - identifier les cartes obsolètes et les supprimer de son portefeuille.
  
L'intérêt de ce mode est la facilité d'installation et de mise à jour d'un esclave.

Il existe donc 3 modes d'utilisation d'un serveur ShomGt:
  - Le mode **autonome** est l'ancien mode ; l'administrateur doit détecter les nouvelles cartes et les carte obsolètes
    et les gérer à la main.
  - Le mode **maitre** ajoute au mode autonome le fil Atom de téléchargement dans master.
  - Le mode **esclave** automatise la gestion des évolutions des cartes grâce à ../updt/slaveupdt.php

### Mise en oeuvre
Pour mettre en oeuvre un maitre il faut créer le fichier histo.pser en effectuant en CLI 'php histo.php'
et le mettre à jour en l'effacant à chaque modification du portefeuille de cartes.
