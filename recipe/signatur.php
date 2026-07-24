<?php

/**
 * Entry recipe for the "signatur" sites: a Laravel app deployed without a
 * database pull and without migrations (static signature generator).
 *
 * In a site's deploy.php:
 *
 *   require 'vendor/mmoollllee/laravel-deployer/recipe/signatur.php';
 */

namespace Deployer;

require_once __DIR__ . '/../lib/functions.php';
require 'recipe/laravel.php';
require 'contrib/rsync.php';
require_once __DIR__ . '/base.php';

// No database, no migrations; sync the whole storage dir and never delete on
// pull.
set('deploy_migrate', false);
set('files', ['storage']);
set('files_pull_delete', false);
