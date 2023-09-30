# Composants externes

ShomGT3 utilise les composants externes suivants:

  - **[symfony/yaml](https://symfony.com/doc/current/components/yaml.html)**
    qui permet de charger et d'écrire des fichiers Yaml.  
    Ce composant est intégré en utilisant l'application [composer](https://getcomposer.org/)
    qui crée le sous-répertoire vendor dans le répertoire racine de ShomGT.
  
  - **[SevenZipArchive.php](https://github.com/PHPGangsta/SevenZipArchive)**
    qui facilite la gestion des archives avec la version en ligne de commande de 7-Zip.  
    Le fichier *SevenZipArchive.php* est intégré dans le code de ShomGT3.
    
  - **[GDAL](https://gdal.org/)**
    qui est une bibliothèque de traduction pour les formats de données géospatiales raster et vectorielles.
    Cette bibliothèque est installée dans le système d'exploitation.
