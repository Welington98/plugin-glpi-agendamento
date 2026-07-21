<?php

require_once __DIR__ . '/../vendor/autoload.php';

// GLPI's translation helper is normally provided by GLPI core. These unit
// tests exercise plugin logic in isolation (no GLPI core available), so we
// stub it to return the source string untouched.
if (!function_exists('__')) {
    function __(string $str, string $domain = ''): string
    {
        return $str;
    }
}
