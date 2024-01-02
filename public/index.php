<?php
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    if ($context['APP_ENV'] == 'dev') {
        ini_set('memory_limit', '256M');
    }
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
