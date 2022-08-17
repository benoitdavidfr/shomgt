<?php
// demo.php - Test du SDK AWS Php - BD - 2022-08-17

require __DIR__.'/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

$s3 = new S3Client([
  'version' => 'latest',
  'region' => 'GRA',
  'credentials' => [
    'key'=> 'f4cff209cd3c43f3881f528008c43e4b',
    'secret'=> 'f9b4a4752067428dbf52b6ffe7bf9168',
  ],
  'endpoint'=> 'https://s3.gra.cloud.ovh.net',
]);

if ($argc == 1) {
  echo "usage: php $argv[0] {action}\n";
  echo " où {action} vaut:\n";
  echo "  - listBuckets\n";
  echo "  - putObject\n";
  echo "  - getObject\n";
  die();
}

try {
  switch ($argv[1]) {
    case 'listBuckets': {
      $result = $s3->listBuckets();
      break;
    }
    case 'putObject': {
      $result = $s3->putObject([
        'Bucket' => 'shomgeotiff',
        'Key' => 'my-key',
        'Body' => 'this is the body!'
      ]);
      break;
    }
    case 'getObject': {
      $result = $s3->getObject([
        'Bucket' => 'shomgeotiff',
        'Key' => '6623.7z',
      ]);
      break;
    }
  }
  print_r($result);
}
catch(S3Exception $e) {
  echo $e->getMessage();
}
