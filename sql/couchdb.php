#!/usr/bin/php
<?php
/**
 * @file
 *   Initialize the sphinx view.
 */
chdir('..');
require_once 'locum.php';

$locum = new locum;
$client = new couchClient($locum->couchserver, $locum->couchdatabase);

// Create the view.
try {
  $doc = $client->getDoc('_design/sphinx');
} catch ( Exception $e ) {
  if ( $e->getCode() == 404 ) {
    // document doesn't exist. create a new one
    $doc = new stdClass();
    $doc->_id = '_design/sphinx';
  }
  else {
    var_dump($e);
    exit;
  }
}

$doc->views = new stdClass;
$doc->views->by_sphinxid = new stdClass;
$doc->views->by_sphinxid->map = "function(doc) { if (doc.bnum) { emit(doc.bnum, null); } }";

// Save view.
$client->storeDoc($doc);
