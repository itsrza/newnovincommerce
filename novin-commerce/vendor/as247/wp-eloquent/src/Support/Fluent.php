<?php

namespace As247\WpEloquent\Support;

if ( PHP_VERSION_ID >= 80100 ) {
    require_once __DIR__ . '/Fluent-PHP81.php';
} else {
    require_once __DIR__ . '/Fluent-PHP74.php';
}
