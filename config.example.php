<?php
// Copy to config.local.php for local use, or configure these environment variables on the host.
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://YOUR_PROJECT.supabase.co');
define('SUPABASE_KEY', getenv('SUPABASE_KEY') ?: 'YOUR_SUPABASE_PUBLISHABLE_KEY');
