<?php
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL') ?: '');
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY') ?: '');
}
if (SUPABASE_URL === '' || SUPABASE_KEY === '') {
    throw new RuntimeException('Set SUPABASE_URL and SUPABASE_KEY in environment variables or config.local.php.');
}
