<?php
// servtest.php - Serveur de test pour tester download() de lib/execdl.inc.php

$test = $_GET['test'] ?? null;

function menu() {
  if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']))
    echo "Non authentifié<br>\n";
  else
    echo "$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]<br>\n";
  echo "Menu:<br>\n";
  foreach (['200','401','auth','desauth'] as $test) {
    $url = "$_SERVER[SCRIPT_NAME]?test=$test";
    echo " - <a href='$url'>$url</a><br>\n";
  }
}

switch ($test) {
  case null: {
    menu();
    die();
  }
  
  case '200': {
    menu();
    die("servtest.php 200 OK\n");
  }
  
  case '404': {
    header('HTTP/1.1 404 Not found');
    header('Content-type: text/plain; charset="utf-8"');
    die("servtest.php 404 Not found\n");
  }
  
  case '410': {
    header('HTTP/1.1 410 Not found');
    header('Content-type: text/plain; charset="utf-8"');
    die("servtest.php 410 Gone\n");
  }
  
  case '400': {
    header('HTTP/1.1 400 Bad Request');
    header('Content-type: text/plain; charset="utf-8"');
    die("servtest.php 400 Bad Request\n");
  }
  
  case '204': {
    header('HTTP/1.1 204 No Content');
    header('Content-type: text/plain; charset="utf-8"');
    die("servtest.php 204 No Content\n");
  }
  
  case 'xxx': {
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
      header('WWW-Authenticate: Basic realm="Authentification pour acces à sertest.php"');
      header('HTTP/1.1 401 Unauthorized');
      header('Content-type: text/plain; charset="utf-8"');
      die("servtest.php 401 Unauthorized\n");
    }
    elseif ($_SERVER['PHP_AUTH_USER'] == '') {
      header('HTTP/1.1 403 Forbidden');
      header('Content-type: text/plain; charset="utf-8"');
      die("servtest.php Forbidden Echec de l'authentification\n");
    }
    elseif ("$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]" <> 'user:mdp') {
      header('WWW-Authenticate: Basic realm="Authentification pour acces à sertest.php"');
      header('HTTP/1.1 401 Unauthorized');
      header('Content-type: text/plain; charset="utf-8"');
      die("servtest.php 401 Erreur d'authentification pour \"$_SERVER[PHP_AUTH_USER]\"\n");
    }
    else {
      echo "servtest.php 200 OK authentifié avec \"$_SERVER[PHP_AUTH_USER]\"<br>\n";
      menu();
      die();
    }
  }
  
  case '401': {
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
      header('WWW-Authenticate: Basic realm="Authentification pour acces à sertest.php"');
      header('HTTP/1.1 401 Unauthorized');
      echo "servtest.php 401 Unauthorized Abandon de l'authentification<br>\n";
      menu();
      die();
    }
    elseif ("$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]" <> 'user:mdp') {
      header('WWW-Authenticate: Basic realm="Authentification pour acces à sertest.php"');
      header('HTTP/1.1 401 Unauthorized');
      echo "servtest.php 401 Unauthorized Echec de l'authentification<br>\n";
      menu();
      die();
    }
    else {
      echo "servtest.php 200 OK authentifié avec \"$_SERVER[PHP_AUTH_USER]\"<br>\n";
      menu();
      die();
    }
  }
  
  case 'auth': {
    menu();
    die();
  }
  
  case 'desauth': {
    header('WWW-Authenticate: Basic realm="Authentification pour acces à sertest.php"');
    header('HTTP/1.1 401 Unauthorized');
    header('Content-type: text/html; charset="utf-8"');
    menu();
    die();
  }
  
  default: {
    die("Cas '$test' non prévu\n");
  }
}