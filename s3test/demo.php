<?php
// demo.php - Test du SDK AWS Php avec OVH - BD - 17/8/2022

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
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
]
);

if ($argc == 1) {
  echo "usage: php $argv[0] {action}\n";
  echo " où {action} vaut:\n";
  echo "  - listBuckets\n";
  echo "  - deleteObjectsAndBucket\n";
  echo "  - listObjects\n";
  echo "  - putObject\n";
  echo "  - putObjectFromFile {filename}\n";
  echo "  - getObject {key}\n";
  die();
}

$BUCKET_NAME = 'shomgt';

try {
  switch ($argv[1]) {
    case 'listBuckets': {
      $result = $s3->listBuckets();
      foreach ($result['Buckets'] as $bucket) {
        echo $bucket['Name'] . "\n";
      }
      die();
    }
    case 'deleteObjectsAndBucket': {
      $objects = $s3->getIterator('ListObjects', ([
          'Bucket' => $BUCKET_NAME
      ]));
      echo "Keys retrieved!\n";
      foreach ($objects as $object) {
          echo $object['Key'] . "\n";
          $result = $s3->deleteObject([
              'Bucket' => $BUCKET_NAME,
              'Key' => $object['Key'],
          ]);
      }
      $result = $s3->deleteBucket([
          'Bucket' => $BUCKET_NAME,
      ]);
    }
    case 'listObjects': {
      $result = $s3->listObjects([
        'Bucket' => $BUCKET_NAME,
      ]);
      echo "Liste des objets du conteneur $BUCKET_NAME: \n";
      foreach ($result['Contents'] as $content) {
          echo " - $content[Key]\n";
      }
      break;
    }
    case 'putObject': {
      $result = $s3->putObject([
        'Bucket' => $BUCKET_NAME,
        'Key' => 'my-key',
        'Body' => 'this is the body!',
        'Metadata' => [
          'modified'=> '2022-08-01',
          'version'=> '2008c23',
        ],
      ]);
      break;
    }
    case 'putObjectFromFile': {
      //echo "argc=$argc\n";
      if ($argc < 3) {
        echo "usage: php $argv[0] putObjectFromFile {filename}\n";
        die();
      }
      $file_Path = $argv[2];
      if (!is_file($file_Path))
        die("Erreur fichier $file_Path inexistant\n");
      $key = basename($file_Path);
      
      $result = $s3->putObject([
          'Bucket' => $BUCKET_NAME,
          'Key' => $key,
          'SourceFile' => $file_Path,
          'Metadata' => [
            'modified'=> '2022-08-01',
            'version'=> '2008c23',
          ],
      ]);
      break;
    }
    case 'getObject': {
      if ($argc < 3) {
        echo "usage: php $argv[0] getObject {key}\n";
        die();
      }
      $key = $argv[2];
      $result = $s3->getObject([
        'Bucket' => $BUCKET_NAME,
        'Key' => $key,
      ]);
      break;
    }
  }
  //print_r($result);
  echo Yaml::dump($result->toArray(), 9, 2);
}
catch(S3Exception $e) {
  echo $e->getMessage();
}
