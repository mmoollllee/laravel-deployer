<?php

/**
 * Entry recipe for a full Laravel application (database + assets + migrations).
 *
 * In a site's deploy.php:
 *
 *   require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';
 */

namespace Deployer;

require_once __DIR__ . '/../lib/functions.php';
require 'recipe/laravel.php';   // Deployer core (resolved via its own include path)
require 'contrib/rsync.php';    // Deployer contrib (download/upload options)
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/database.php';
