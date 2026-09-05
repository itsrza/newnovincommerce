<?php

namespace As247\WpEloquent\Database;

if ( PHP_VERSION_ID >= 80100 ) {
    require_once __DIR__ . '/WpPdo-PHP81.php';
} else {
    require_once __DIR__ . '/WpPdo-PHP74.php';
}
