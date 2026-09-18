<?php

use App\Kernel;

ini_set('session.gc_maxlifetime', '28800');
ini_set('session.cookie_lifetime', '28800');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
