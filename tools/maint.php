#!/usr/bin/php5 -q
<?php

// You may want/need to change this
ini_set('memory_limit', '400M');

$first_record = 1400000;
$last_record = 1420000;
$large_record_split = 50;

// Init scripts, library locations, and binaries
$locum_lib_dir = '/usr/local/lib/locum';

// Include Locum libraries
require_once($locum_lib_dir . '/locum-server.php');
$locum = new locum_server;
// Data maintenance
//$locum->verify_bibs();
// Uncomment to also verify suppressed bibs
//$locum->verify_suppressed();
//$locum->new_bib_scan();
//$locum->rebuild_holds_cache();

// Rebuild Facet Heap
//$locum->rebuild_facet_heap();

// Restart services, reindex, etc.
//$locum->index();

// This can all be done in situ
//$locum->verify_status();
$locum->verify_syndetics();
