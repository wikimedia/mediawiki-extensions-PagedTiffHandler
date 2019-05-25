<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Due to creation of File->tiffMetaArray/tiffImage property
$cfg['suppress_issue_types'][] = 'PhanUndeclaredProperty';

return $cfg;
