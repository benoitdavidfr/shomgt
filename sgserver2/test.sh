rm -r portfolio # suppresion des précédents
mkdir portfolio portfolio/current portfolio/archives portfolio/deliveries # création d'un nouveau portefeuille
mkdir portfolio/deliveries/20230609
echo "Jeu a du 9 juin" > portfolio/deliveries/20230609/a
echo "Jeu b du 9 juin" > portfolio/deliveries/20230609/b
echo "MD du 9/6 contient a et b" > portfolio/deliveries/20230609/index.yaml
php pfm.php add 20230609 # ajout livraison du 9/6
#echo "\n**Fin ajout 9 juin"; find portfolio -print ; exit

mkdir portfolio/deliveries/20230610 # création livraison 10/6
echo "Jeu a du 10 juin" > portfolio/deliveries/20230610/a
echo "Jeu c du 10 juin" > portfolio/deliveries/20230610/c
echo "MD du 10/6 contient a et c" > portfolio/deliveries/20230610/index.yaml
php pfm.php add 20230610 # ajout livraison du 10/6
echo "\n**Fin ajout 10 juin"; find portfolio -print ; ls -l portfolio/current ; exit

rmdir new20230610
php pfm.php cancel
echo "\n**Fin cancel"; find portfolio -print 

php pfm.php cancel
echo "\n**Fin cancel2"; find portfolio -print 
