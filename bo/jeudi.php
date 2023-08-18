<?php
// tester si l'intervalle entre 2 dates comprends un jour de semaine donné avec une heure donnée
// exemple jeudi 20h

// Teste si à la fois $now - $first >= 1 jour et il existe une $dayOfWeekT$h:$m  entre $first et $now 
function dateBetween(DateTimeImmutable $first, DateTimeImmutable $now, int $dayOfWeek=4, int $h=20, int $mn=0): bool {
  //echo 'first = ',$first->format(DateTimeInterface::ISO8601),', now = ',$now->format(DateTimeInterface::ISO8601),"<br>\n";
  
  //$diff = $first->diff($now); print_r($diff); echo "<br>\n";
  /*if ($diff->invert == 1) {
    echo "diff.invert==1 <=> now < first<br>\n";
  }
  else {
    echo "diff.invert<>=1 <=> first < now<br>\n";
  }*/
  if ($first->diff($now)->d == 0) // Il y a moins d'un jour d'écart
    return false;
  if ($first->diff($now)->days >= 7) // Il y a plus de 7 jours d'écart
    return true;
  
  $W = $now->format('W'); // le no de la semaine de $now
  //echo "Le no de la semaine de now est $W<br>\n";
  $o = $now->format('o'); // l'année ISO semaine de $last
  //echo "L'année (ISO semaine) d'aujourd'hui est $o<br>\n";
  // j'appelle $thursday le jour qui doit être le $dayOfWeek
  $thursday = $now->setISODate($o, $W, $dayOfWeek)->setTime($h, $mn);
  //echo "Le jeudi 20h UTC de la semaine d'aujourd'hui est ",$thursday->format(DateTimeInterface::ISO8601),"<br>\n";
  
  $diff = $thursday->diff($now); // intervalle de $thursday à $now
  //print_r($diff); echo "<br>\n";
  if ($diff->invert == 1) { // c'est le jeudi d'après now => prendre le jeudi d'avant
    //echo $thursday->format(DateTimeInterface::ISO8601)," est après maintenant<br>\n";
    $oneWeek = new \DateInterval("P7D"); // 7 jours
    $thursday = $thursday->sub($oneWeek);
    //echo $thursday->format(DateTimeInterface::ISO8601)," est le jeudi de la semaine précédente<br>\n";
  }
  else {
    //echo $thursday->format(DateTimeInterface::ISO8601)," est avant maintenant, c'est donc le jeudi précédent<br>\n";
  }
  $thursday_minus_first = $first->diff($thursday);
  //print_r($thursday_minus_first);
  if ($thursday_minus_first->invert == 1) {
    //echo "thursday_minus_first->invert == 1 <=> thursday < first<br>\n";
    return false;
  }
  else {
    //echo "thursday_minus_first->invert <> 1 <=> first < thursday <br>\n";
    return true;
  }
}

function testDateBetween(): void {
  $first = DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-15');
  $now = new DateTimeImmutable;
  echo 'first = ',$first->format(DateTimeInterface::ISO8601),', now = ',$now->format(DateTimeInterface::ISO8601),"<br>\n";
  $db = dateBetween($first, $now, 4, 20, 00);
  echo "dateBetween=",$db ? 'true' : 'false',"<br>\n";
  
  $first = DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-11'); // Ve
  $now = DateTimeImmutable::createFromFormat('Y-m-d', '2023-08-15'); // Ma
  echo 'first = ',$first->format(DateTimeInterface::ISO8601),', now = ',$now->format(DateTimeInterface::ISO8601),"<br>\n";
  $db = dateBetween($first, $now, 4, 20, 00);
  echo "dateBetween=",$db ? 'true' : 'false',"<br>\n";
}
testDateBetween();