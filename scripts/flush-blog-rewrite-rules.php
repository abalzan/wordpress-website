<?php
/**
 * Flush rewrite rules to enable the new Blog archive at /blog/
 *
 * This script should be run once after deploying the blog integration changes.
 * It flushes WordPress rewrite rules so the new /blog/ archive routing takes effect.
 *
 * Usage: php scripts/flush-blog-rewrite-rules.php
 *
 * @package Conexao_BR_Irlanda
 */

// Load WordPress.
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

echo "Flushing rewrite rules for Blog integration...\n";

// Flush rewrite rules.
flush_rewrite_rules();

echo "Done! The /blog/ archive should now work correctly.\n";
echo "You may need to visit Settings > Permalinks in the admin to trigger a flush.\n";