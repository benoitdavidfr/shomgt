<?php
// maintenance/sgserver/index.php
header('HTTP/1.1 503 Service Unavailable');
header('Content-type: text/plain; charset="utf-8"');
die("Site en maintenance, service non disponible\n");
