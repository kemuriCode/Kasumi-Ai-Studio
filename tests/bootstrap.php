<?php

declare(strict_types=1);

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/vendor/autoload.php';

$wordpress_root = dirname( __DIR__, 4 );

// Załaduj WordPress
define( 'WP_USE_THEMES', false );
require_once $wordpress_root . '/wp-config.php';

// Inicjalizuj $wp_rewrite przed załadowaniem wp-settings.php
require_once ABSPATH . WPINC . '/class-wp-rewrite.php';
$GLOBALS['wp_rewrite'] = new WP_Rewrite();

// Teraz załaduj resztę WordPress
require_once ABSPATH . 'wp-settings.php';

