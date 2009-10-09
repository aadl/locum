#!/usr/bin/php5 -q
<?php

// You may want/need to change this
ini_set('memory_limit', '400M');

// Init scripts, library locations, and binaries
$locum_lib_dir = '/usr/local/lib/locum';
$mysql_init_script = '/etc/init.d/mysql';
$sphinx_init_script = '/etc/init.d/sphinx';
$sphinx_indexer = '/usr/local/sphinx/bin/indexer';

// Include Locum libraries
require_once($locum_lib_dir . '/locum-server.php');

// Data maintenance
$locum = new locum_server;
$locum->verify_bibs();
$locum->new_bib_scan();
$locum->rebuild_holds_cache();

// Restart services, reindex, etc.
$locum->index();

// This can all be done in situ
$locum->verify_status();
$locum->verify_syndetics();