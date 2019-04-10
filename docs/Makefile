all: mapcat.json france.geojson cat0.geojson
	echo maj ok
mapcat.json: ../cat/mapcat.json
	cp ../cat/mapcat.json .
france.geojson: ../cat/france.geojson
	cp ../cat/france.geojson .
cat0.geojson: catdata.php ../cat/geojson.php ../cat/mapcat.json
	php catdata.php | sh