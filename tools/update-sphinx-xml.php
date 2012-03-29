<?php
////////////////////////////////////////////////////////////////////////////////
error_reporting(E_ERROR);
$main_time_start = microtime(true);
require_once('../vendor/predis/lib/Predis.php');
require_once('../locum-client.php');
$config = parse_ini_file('../config/indexer-xml-config.ini', TRUE);
$process_limit = 1000; // records to process in each batch
$process_maximum = 2; // number of processes to spawn
$queue = 'queue:sphinx-xml';
$script_name = $_SERVER['SCRIPT_NAME'];
$log_file = 'sphinx-xml.log';
$pids = array();

$r = new Predis_Client(array('host' => 'multivac'));
$l = new locum;
$host = $l->mysqli_host;
$username = $l->mysqli_username;
$passwd = $l->mysqli_passwd;
$dbname = $l->mysqli_dbname;
unset($l);

// Remove current log file and xml docs
unlink($log_file);
exec('rm ../sphinx/xml/*');

// Count records to process
$mysqli = new mysqli($host, $username, $passwd, $dbname);
$result = $mysqli->query("SELECT COUNT(bnum) AS count FROM locum_bib_items");
$row = $result->fetch_object();
$record_total = $row->count;
$mysqli->kill($mysqli->thread_id);
$mysqli->close();
unset($mysqli);

// Build the processing queue offsets
$r->del($queue);
$offsets = array();
for ($process_offset = 0; $process_offset < $record_total; $process_offset += $process_limit) {
  $offsets[] = $process_offset;
}
shuffle($offsets); // randomize order in which offsets are processed
foreach ($offsets as $offset) {
  $r->rpush($queue, $offset);
}

$process_count = 0;
while ($process_count < $process_maximum) {
  if ($r->llen($queue)) {
    if ( ($pids[] = pcntl_fork()) === 0) {
      // CHILD PROCESS START ///////////////////////////////////////////////////
      // Loop through offsets in processing queue
      while (($offset = $r->lpop($queue)) !== NULL) {
        $child_time_start = microtime(true);
        $mysqli = new mysqli($host, $username, $passwd, $dbname);

        // Grab bibs from locum_bib_items
        $sql = 'SELECT * FROM locum_bib_items ' .
               'ORDER BY bnum ASC LIMIT ' . $process_limit . ' OFFSET ' . $offset;
        $result = $mysqli->query($sql);
        $bibs = array();
        while ($bib = $result->fetch_assoc()) {
          $bibs[$bib['bnum']] = $bib;
        }
        $result->free();
        unset($result);

        // Build list of bnums for joining
        $bnums = implode(',', array_keys($bibs));

        // Grab holds count for bibs
        $sql = 'SELECT * FROM locum_holds_count ' .
               'WHERE bnum IN (' . $bnums . ')';
        $result = $mysqli->query($sql);
        while ($holds_count = $result->fetch_assoc()) {
          $bnum = $holds_count['bnum'];
          $bibs[$bnum] = array_merge($bibs[$bnum], $holds_count);
        }
        $result->free();
        unset($result);

        // Grab insurge index for bibs
        $sql = 'SELECT * FROM insurge_index ' .
               'WHERE bnum IN (' . $bnums . ')';
        $result = $mysqli->query($sql);
        while ($insurge_index = $result->fetch_assoc()) {
          $bnum = $insurge_index['bnum'];
          $bibs[$bnum] = array_merge($bibs[$bnum], $insurge_index);
        }
        $result->free();
        unset($result);

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
        $log_msg = "$current_time lim:$process_limit offset:$offset time:$child_time_elapsed sec";
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

  $lc = new locum_client();
  $bib_status = $lc->get_item_status($bib['bnum'], FALSE, TRUE);
  $formats = $lc->locum_config['formats'];
  unset($lc);

  if (count($bib_status['ages'])) {
    $ages = array();
    foreach($bib_status['ages'] as $age => $details) {
      $ages[] = sprintf('%u', crc32($age));
    }
    $bib['ages'] = implode(',', $ages);
    unset($ages);
  }
  if (count($bib_status['branches'])) {
    $branches = array();
    if (count($bib_status['branches'])) {
      $branches[] = sprintf('%u', crc32('any'));
    }
    foreach($bib_status['branches'] as $branch => $details) {
      $branches[] = sprintf('%u', crc32($loc));
    }
    $bib['branches'] = implode(',', $locs);
    unset($branches);
  }
  $callnum = $bib['callnum'];
  foreach($bib_status['items'] as $item){
    if(preg_match('/Zoom/',$item['callnum'])) {
      $bib['callnum'] = $bib['callnum'] . " " . trim($item['callnum']);
    }
    if(strpos($item['location'],'StaffPicks')){
      $bib['callnum'] = $bib['callnum'] . " StaffPick";
    }
  }
  unset($bib_status);

  // Copy fields
  $bib['publisher'] = $bib['pub_info'];
  $bib['pubyear'] = $bib['pub_year'];
  $bib['langcode'] = $bib['lang'];
  $bib['mat_name'] = $formats[$bib['mat_code']];

  // CRC32s
  $bib['pub_info'] = sprintf('%u', crc32($bib['pub_info']));
  $bib['lang'] = sprintf('%u', crc32($bib['lang']));
  $bib['loc_code'] = sprintf('%u', crc32($bib['loc_code']));
  $bib['mat_code'] = sprintf('%u', crc32($bib['mat_code']));
  $bib['titleattr'] = sprintf('%u', crc32(strtolower($bib['title'])));
  $bib['cover_code'] = sprintf('%u', crc32($bib['cover_img']));

  // Timestamps
  $bib['bib_created'] = strtotime($bib['bib_created']);
  $bib['bib_last_update'] = strtotime($bib['bib_last_update']);

  // Ordinals
  $bib['title_ord'] = strtolower($bib['title']);
  $bib['author_ord'] = strtolower($bib['author']);

  // Null check
  $bib['author_null'] = (empty($bib['author']) ? 1 : 0);
}

function write_sphinx_doc_xml($config, $bib, $process_id) {
  $dom = new DOMDocument;
  $dom->encoding = "utf-8";
  $dom->formatOutput = true;

  foreach($config as $index) {
    $file_path = '../sphinx/xml/' . $index['file_path'] . '_' . $process_id . '.xml';
    // Each index has its own XML file and fields
    $doc = $dom->createElement('sphinx:document');
    $doc->setAttribute('id', $bib['bnum']);

    foreach ($index['fields'] as $field) {
      $tmp = $dom->createElement($field);
      $tmp->appendChild($dom->createTextNode($bib[$field]));
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
?>
