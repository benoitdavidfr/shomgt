# Test de pfm.php - ajout 2 livraisons dans une même commande 
#
rm -rf portfolio # suppresion du précédent
mkdir portfolio portfolio/current portfolio/archives portfolio/deliveries # création d'un nouveau portefeuille
mkdir portfolio/deliveries/20230609
echo "Jeu a du 9 juin" > portfolio/deliveries/20230609/a
echo "Jeu b du 9 juin" > portfolio/deliveries/20230609/b
echo "Jeu d du 9 juin" > portfolio/deliveries/20230609/d
echo "{title: 'MD du 9/6 contient a et b et d'}" > portfolio/deliveries/20230609/index.yaml

mkdir portfolio/deliveries/20230610 # création livraison 10/6
echo "Jeu a du 10 juin" > portfolio/deliveries/20230610/a
echo "Jeu c du 10 juin" > portfolio/deliveries/20230610/c
echo "{title: 'MD du 10/6 contient a et c et retire d', toDelete: {FRd: d}}" > portfolio/deliveries/20230610/index.yaml

echo "\n**lsd:"
php pfm.php lsd

echo "ajout livraisons des 9 et 10/6\n"
php pfm.php add 20230609 20230610 # ajout livraisons des 9 et 10/6
#echo "\n**Fin ajout 10 juin"; find portfolio -print ; ls -l portfolio/current ; exit

echo "\n**ls:" ; php pfm.php ls
echo "\n**lsa:" ; php pfm.php lsa
echo "\n**lsd:" ; php pfm.php lsd

echo "\nretrait livraison du 10/6";
php pfm.php cancel

echo "\n**ls:" ; php pfm.php ls
echo "\n**lsa:" ; php pfm.php lsa
echo "\n**lsd:" ; php pfm.php lsd
