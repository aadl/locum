<?php
date_default_timezone_set('America/Detroit');
$locum_lib_dir = '/usr/local/lib/locum';

// Include Locum libraries
require_once($locum_lib_dir . '/locum-client.php');
$locum = new locum_client;

while (1) {
  $bnum = $locum->redis->zrange('availcache:timestamps', 10, 10, 'WITHSCORES');
  
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
?>
