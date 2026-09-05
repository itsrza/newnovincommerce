<?php

namespace As247\WpEloquent\Container;

if ( PHP_VERSION_ID >= 80100 ) {
    require_once __DIR__ . '/Container-PHP81.php';
} else {
    require_once __DIR__ . '/Container-PHP74.php';
}
