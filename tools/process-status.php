<?php
date_default_timezone_set('America/Detroit');
$locum_lib_dir = '/usr/local/lib/locum';

// Include Locum libraries
require_once($locum_lib_dir . '/locum-client.php');
$locum = new locum_client;

// Rebuild timestamp queue if needed
if ($argv[1] == 'rebuild') {
  echo "Rebuilding timestamps queue...\n";
  $count = $locum->rebuild_status_timestamps();
  echo "Added $count records to the timestamp queue\n";
}
else {
  // Identify queue offset to work from
  $q_offset = (int) ($argv[1] ? $argv[1] * 10 : 0);
  echo "Starting availcache worker with queue offset $q_offset\n";

  while (1) {
    $bnum = $locum->redis->zrange('availcache:timestamps', $q_offset, $q_offset, 'WITHSCORES');

    $now = time();
    $cache_age = $now - $bnum[1];
    $cache_time = sprintf( "%02.2d:%02.2d", floor( $cache_age / 60 ), $cache_age % 60 );
    $now_date = date('m-d-Y G:i:s', $now);
    $cache_date = date('m-d-Y G:i:s', $bnum[1]);
    echo "[$now_date] UPDATING $bnum[0] " .
         "($cache_date, $cache_time min old)\n";

    $locum->redis->zrem('availcache:timestamps', $bnum[0]);
    $locum->get_item_status($bnum[0], TRUE);
  }
}
?>
