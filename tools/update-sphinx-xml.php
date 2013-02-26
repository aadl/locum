<?php
////////////////////////////////////////////////////////////////////////////////
error_reporting(E_ERROR);
$main_time_start = microtime(true);
require_once('../locum-client.php');
$config = parse_ini_file('../config/indexer-xml-config.ini', TRUE);
$process_limit = 1000; // records to process in each batch
$process_maximum = 1; // number of processes to spawn
$queue = 'queue:couch-sphinx-xml';
$script_name = $_SERVER['SCRIPT_NAME'];
$log_file = 'sphinx-xml.log';
$pids = array();

$l = new locum;
$couch = new couchClient($l->couchserver, $l->couchdatabase,
                         array('curl' => array(CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4)));

// Remove current log file and xml docs
unlink($log_file);
exec('rm ../sphinx/xml/*');

// Count records to process
$all_bibs = $couch->limit(0)->getAllDocs();
$record_total = $all_bibs->total_rows;
echo "Processing $record_total records in groups of $process_limit\n";

// Build the processing queue offsets
$l->redis->del($queue);
$offsets = array();
for ($process_offset = 0; $process_offset < $record_total; $process_offset += $process_limit) {
  $offsets[] = $process_offset;
}
shuffle($offsets); // randomize order in which offsets are processed
foreach ($offsets as $offset) {
  $l->redis->rpush($queue, $offset);
}

$process_count = 0;
while ($process_count < $process_maximum) {
  if ($l->redis->llen($queue)) {
    if ( ($pids[] = pcntl_fork()) === 0) {
      // CHILD PROCESS START ///////////////////////////////////////////////////
      // Loop through offsets in processing queue
      while (($offset = $l->redis->lpop($queue)) !== NULL) {
        $child_time_start = microtime(true);
        $log_msg = '';
        $bibs = array();
        $insurge_keys = array();
        $holds_keys = array();

        // Grab bibs from couch
        $couch_bibs = $couch->limit($process_limit)->skip($offset)->include_docs(TRUE)->getAllDocs();
        foreach ($couch_bibs->rows as $couch_bib) {
          if ($bnum = $couch_bib->doc->bnum) {
            // ILS record
            $bibs[$bnum] = (array) $couch_bib->doc;
            $insurge_keys[] = $bnum;
            $holds_keys[] = $bnum;
          }
          else if ($couch_bib->doc->sphinxid) {
            // Couch only record
            $bnum = $couch_bib->id;
            $bibs[$bnum] = (array) $couch_bib->doc;
            $insurge_keys[] = $bnum;
          }
        }

        // Build list of bnums for joining to mysql tables
        $mysqli = new mysqli($l->mysqli_host, $l->mysqli_username, $l->mysqli_passwd, $l->mysqli_dbname);

        // Grab insurge index for bibs
        if (count($insurge_keys)) {
          $insurge_keys = '"' . implode('", "', $insurge_keys) . '"';
          $sql = 'SELECT * FROM insurge_index ' .
                 'WHERE bnum IN (' . $insurge_keys . ')';
          if ($result = $mysqli->query($sql)) {
            while ($insurge_index = $result->fetch_assoc()) {
              $bnum = $insurge_index['bnum'];
              $bibs[$bnum] = array_merge($bibs[$bnum], $insurge_index);
            }
            $result->free();
          }
          else {
            // Query Error, log and move on
            $log_msg .= sprintf("INSURGE QUERY ERROR:\n\t%s\n\tQUERY: %s\n", $mysqli->error, $sql);
          }
          unset($result);
        }

        // Grab holds count for bibs
        if (count($holds_keys)) {
          $holds_keys = implode(',', $holds_keys);
          $sql = 'SELECT * FROM locum_holds_count ' .
                 'WHERE bnum IN (' . $holds_keys . ')';
          if ($result = $mysqli->query($sql)) {
            while ($holds_count = $result->fetch_assoc()) {
              $bnum = $holds_count['bnum'];
              $bibs[$bnum] = array_merge($bibs[$bnum], $holds_count);
            }
            $result->free();
          }
          else {
            // Query Error, log and move on
            $log_msg .= sprintf("HOLDS QUERY ERROR:\n\t%s\n\tQUERY: %s\n", $mysqli->error, $sql);
          }
          unset($result);
        }

        // Kill the mysqli connection
        $mysqli->kill($mysqli->thread_id);
        $mysqli->close();
        unset($mysqli);

        $bibs_time = number_format(microtime(true) - $child_time_start, 3);
        foreach ($bibs as &$bib) {
          $loop_start = microtime(true);

          prep_bib($bib);
          $prep_time = microtime(true);
          $prep_total += $prep_time - $loop_start;

          write_sphinx_doc_xml($config, $bib, $offset);
          $write_total += microtime(true) - $prep_time;
        }

        // Write to Log
        $mem_usage = memory_get_usage();
        $current_time = '[' . date("H:i:s") . ']';
        $child_time_elapsed = number_format(microtime(true) - $child_time_start, 3);
        $prep_total = number_format($prep_total, 3);
        $write_total = number_format($write_total, 3);
        $offset = str_pad($offset, 6, ' ', STR_PAD_LEFT);
        $log_msg .= "$current_time lim:$process_limit offset:$offset time:$child_time_elapsed sec";
        $log_msg .= " (bibs: $bibs_time prep: $prep_total write: $write_total) mem: $mem_usage\n";
        $prep_total = $write_total = 0;
        file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
      }
      exit();
      // END OF CHILD PROCESS //////////////////////////////////////////////////
    }
    echo "Spawning Process\n";
    $process_count++;
    sleep(1); // delay each worker's spawn
  }
}

$main_time_elapsed = microtime(true) - $main_time_start;
echo "Spawned $process_count Processes in $main_time_elapsed seconds\n";

// Wait for worker completion
echo "Waiting for worker process completion...";
foreach ($pids as $pid) {
  pcntl_waitpid($pid, $status, WUNTRACED);
  echo "\nProcess $pid Finished";
}
echo "\n";

// Generate master XML files from the pieces
foreach ($config as $index) {
  build_master_xml_file($index['file_path']);
  echo "Generated Master " . $index['file_path'] . " XML file\n";
}

$main_time_elapsed = microtime(true) - $main_time_start;
$main_time_minutes = sprintf( "%02.2d:%02.2d", floor( $main_time_elapsed / 60 ), $main_time_elapsed % 60 );
echo "Completed XML Generation in $main_time_minutes seconds\n";

// FUNCTIONS ///////////////////////////////////////////////////////////////////

function prep_bib(&$bib) {
  // Item Status Fields
  $bib['ages'] = '';
  $bib['branches'] = '';

  // Availability Attributes
  $lc = new locum_client();
  $bib_status = $lc->get_item_status($bib['bnum'], FALSE, TRUE);
  $formats = $lc->locum_config['formats'];

  // magnatune field names
  if ($bib['magnatune_id']) {
    $bib['author'] = $bib['artist'];
    $bib['series'] = 'magnatune';
    $bib['callnum'] = 'magnatune';
    $bib['mat_code'] = 'z';
    $bib['loc_code'] = 'online';
    $bib['bib_lastupdate'] = $bib['bib_created'];
    $bib['active'] = '1';
  }

  if (count($bib_status['ages'])) {
    $ages = array();
    foreach($bib_status['ages'] as $age => $details) {
      $ages[] = $lc->string_poly($age);
    }
    $bib['ages'] = implode(',', $ages);
    unset($ages);
  }
  if (count($bib_status['branches'])) {
    $branches = array();
    foreach($bib_status['branches'] as $branch => $details) {
      if ($details['avail']) {
        $branches[] = $lc->string_poly($branch);
      }
    }
    if (count($branches)) {
      $branches[] = $lc->string_poly('any');
    }
    $bib['branches'] = implode(',', $branches);
    unset($branches);
  }
  $callnum = $bib['callnum'];
  if (count($bib_status['items'])) {
    foreach ($bib_status['items'] as $item) {
      if (preg_match('/Zoom/',$item['callnum'])) {
        $bib['callnum'] = $bib['callnum'] . " " . trim($item['callnum']);
      }
      if (strpos($item['location'],'StaffPicks')) {
        $bib['callnum'] = $bib['callnum'] . " StaffPick";
      }
    }
  }
  unset($bib_status);

  // Series
  if (is_array($bib['series']) && count($bib['series'])) {
    $series_arr = $bib['series'];
    foreach ($series_arr as &$series) {
      // Convert to 32 bit hash and store lookup
      $series_string = $series;
      $series = $lc->string_poly($series);
      $lc->redis->set('poly_string:' . $series, $series_string);
    }
    $bib['series_attr'] = implode(',', $series_arr);
  }

  // Copy fields
  $bib['publisher'] = $bib['pub_info'];
  $bib['pubyear'] = $bib['pub_year'];
  $bib['langcode'] = $bib['lang'];
  $bib['mat_name'] = $formats[$bib['mat_code']];
  $bib['pub_decade'] = floor($bib['pub_year'] / 10) * 10;

  // CRC32s
  $bib['pub_info'] = $lc->string_poly($bib['pub_info']);
  $bib['lang'] = $lc->string_poly($bib['lang']);
  $bib['loc_code'] = $lc->string_poly($bib['loc_code']);
  $bib['mat_code'] = $lc->string_poly($bib['mat_code']);
  $bib['title_attr'] = $lc->string_poly(strtolower($bib['title']));
  $bib['cover_code'] = $lc->string_poly($bib['cover_img']);

  // Timestamps
  $bib['bib_created'] = strtotime($bib['bib_created']);
  $bib['bib_last_update'] = strtotime($bib['bib_last_update']);

  // Ordinals
  $bib['title_ord'] = strtolower($bib['title']);
  $bib['author_ord'] = strtolower($bib['author']);

  // Null check
  $bib['author_null'] = (empty($bib['author']) ? 1 : 0);

  // Non numeric ID check
  if ($bib['sphinxid']) {
    $bib['bnum'] = $bib['sphinxid'];
  }

  unset($lc);
}

function write_sphinx_doc_xml($config, $bib, $process_id) {
  $dom = new DOMDocument;
  $dom->encoding = "utf-8";
  $dom->formatOutput = true;

  foreach ($config as $index) {
    $file_path = '../sphinx/xml/' . $index['file_path'] . '_' . $process_id . '.xml';
    // Each index has its own XML file and fields
    $doc = $dom->createElement('sphinx:document');
    $doc->setAttribute('id', $bib['bnum']);

    foreach ($index['fields'] as $field) {
      if (is_array($bib[$field]) or is_object($bib[$field])) {
        // Concatenate into a single string for indexing
        $bib[$field] = flatten($bib[$field]);
      }
      $tmp = $dom->createElement($field);
      $tmp->appendChild($dom->createTextNode(trim($bib[$field])));
      $doc->appendChild($tmp);
      unset($tmp);
    }

    file_put_contents($file_path, $dom->saveXML($doc) . PHP_EOL, FILE_APPEND | LOCK_EX);
    unset($file_path);
    unset($doc);
  }
  unset($dom);
}

/**
 * A little bash-fu to concatenate all the file pieces
 */
function build_master_xml_file($file_name, $remove_pieces = FALSE) {
  // start with the seed XML, with the schema definitions
  exec('cp ../sphinx/xml_seeds/' . $file_name . '.xml ../sphinx/xml');
  // Concatenate all the batched files
  exec('cat ../sphinx/xml/' . $file_name . '_*.xml >> ../sphinx/xml/' . $file_name . '.xml');
  // Put closing tag on the file
  exec('echo \'</sphinx:docset>\' >> ../sphinx/xml/' . $file_name . '.xml');
  // Clean out the pieces?
  if ($remove_pieces) {
    exec('rm ../sphinx/xml/' . $file_name . '_*.xml');
  }
}

/**
 * Flatten multi-dimensional values with a single string of space separated values
 */
function flatten($a) {
  $return = array();
  $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($a));
  foreach ($it as $v) {
    $return[] = $v;
  }
  return implode(' ', $return);
}
?>
