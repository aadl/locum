#!/usr/bin/php5 -q
<?php

// You may want/need to change this
ini_set('memory_limit', '400M');
$first_record = 1000000;
$last_record = 1392000;
$large_record_split = 50;

// Init scripts, library locations, and binaries
$locum_lib_dir = '/usr/local/lib/locum';

// Include Locum libraries
require_once($locum_lib_dir . '/locum-server.php');

// Instantiate Locum Server
$locum = new locum_server;
if (($last_record - $first_record) > 1000) {
  $split_amount = ceil(($last_record - $first_record) / $large_record_split);
  $begin_at_bib = $first_record;
  for ($i = 0; $i < $large_record_split; $i++){
    $split_bib_arr[$i]['first'] = $begin_at_bib;
    $split_bib_arr[$i]['last'] = $begin_at_bib + $split_amount;
    $begin_at_bib = $begin_at_bib + $split_amount + 1;
  }
  foreach ($split_bib_arr as $split_bib) {
    $locum->harvest_bibs($split_bib['first'], $split_bib['last'], TRUE, FALSE);
  }
} else {
  $locum->harvest_bibs($first_record, $last_record, TRUE, FALSE);
}

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
//$locum->verify_syndetics();
