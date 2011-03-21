<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * @package Locum
 * @author Eric J. Klooster
 */

require_once('locum.php');

/**
 * This class is the covers component of Locum.
 */

class locum_covers extends locum {
  private $cli;
  private $lookup_count;
  private $xisbn_count;
  private $sources_count;
  private $start_time;

  public function __construct() {
    $this->start_time = time();
    parent::__construct();
    $this->locum_config = array_merge($this->locum_config, parse_ini_file('config/locum-covers.ini', true));
    $this->cli = (php_sapi_name() == "cli");
    $this->xisbn_count = 0;
    $this->lookup_count = 0;
    $this->sources_count = array();
    foreach($this->locum_config['coversources'] as $id => $value) {
      $this->sources_count[$id] = 0;
    }
  }

  public function get_batch($break_num = 0, $limit = 100, $type = 'NEW') {
    $limit = intval($limit);
    $db = MDB2::connect($this->dsn);

    if ($type != 'RETRY') {
      // First grab the bnums with no corresponding cache table rows
      $sql = "SELECT locum_bib_items.bnum FROM locum_bib_items " .
             "LEFT JOIN locum_covercache ON locum_bib_items.bnum = locum_covercache.bnum " .
             "WHERE locum_covercache.bnum IS NULL " .
             "AND locum_bib_items.bnum > :break " .
             "ORDER BY locum_bib_items.bnum ASC " .
             "LIMIT $limit";
      $statement = $db->prepare($sql, array('integer'));
      $result = $statement->execute(array('break' => $break_num));
      if (PEAR::isError($result) && $this->cli) {
        echo "DB connection failed... " . $result->getMessage() . "\n";
      }
      $statement->free();
      $num_rows = $result->numRows();
      $batch_type = 'NEW';
    }

    if ($num_rows == 0) {
      // If no new records, grab the oldest processed records that don't have a cached image
      if ($break_num && $type == 'RETRY') {
        $sql = "SELECT bnum FROM locum_covercache " .
               "WHERE cover_stdnum = '' " .
               "AND updated >= ( " .
               "SELECT updated FROM locum_covercache " .
               "WHERE bnum = :break) " .
               "ORDER BY updated ASC " .
               "LIMIT $limit ";
        $statement = $db->prepare($sql, array('integer'));
        $result = $statement->execute(array('break' => $break_num));
      } else {
        $sql = "SELECT bnum FROM locum_covercache " .
               "WHERE cover_stdnum = '' " .
               "ORDER BY updated ASC " .
               "LIMIT $limit ";
        $statement = $db->prepare($sql);
        $result = $statement->execute();
      }
      if (PEAR::isError($result) && $this->cli) {
        echo "DB connection failed... " . $result->getMessage() . "\n";
      }
      $statement->free();
      $num_rows = $result->numRows();
      $batch_type = 'RETRY';
    }
    $start = $result->fetchRow(MDB2_FETCHMODE_ASSOC);
    $end = $result->fetchRow(MDB2_FETCHMODE_ASSOC, $num_rows-1);
    return array('start' => $start['bnum'], 'end' => $end['bnum'], 'type' => $batch_type);
  }

  public function process_covers($break, $limit, $type = 'NEW') {
    $num_found = 0;
    $not_found = array();
    $total = 0;
    $db = MDB2::connect($this->dsn);

    if ($type == 'NEW') {
      $sql = "SELECT locum_bib_items.* FROM locum_bib_items " .
             "LEFT JOIN locum_covercache ON locum_bib_items.bnum = locum_covercache.bnum " .
             "WHERE locum_covercache.bnum IS NULL " .
             "AND locum_bib_items.bnum >= :break " .
             "ORDER BY locum_bib_items.bnum ASC " .
             "LIMIT :limit";
    } else {
      $sql = "SELECT locum_bib_items.* FROM locum_bib_items " .
             "LEFT JOIN locum_covercache ON locum_bib_items.bnum = locum_covercache.bnum " .
             "WHERE locum_covercache.cover_stdnum = '' " .
             "AND locum_covercache.updated >= ( " .
             "SELECT updated FROM locum_covercache " .
             "WHERE bnum = :break) " .
             "ORDER BY locum_covercache.updated ASC " .
             "LIMIT :limit";
    }
    $statement = $db->prepare($sql, array('integer', 'integer'));
    $result = $statement->execute(array('break' => $break, 'limit' => $limit));
    if (PEAR::isError($result) && $this->cli) {
      echo "DB connection failed... " . $result->getMessage() . "\n";
    }
    $statement->free();

    while($bib_rec = $result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      $total++;
      if ($bib_rec['stdnum']) {
        // clean the stdnum field to just the number
        ereg("^[0-9a-zA-Z]+", $bib_rec['stdnum'], $regs);
        $bib_rec['stdnum'] = $regs[0];
      }
      if ($this->cli) echo $bib_rec['bnum'] . ": ";
      if ($cover = self::get_coverimage($bib_rec)) {
        if ($this->cli) echo $cover['stdnum'] . "::" . $cover['image_url'] . "\n";
        $num_found++;
        if (self::create_covercache($bib_rec['bnum'], $cover['image_url'], $cover['stdnum'])) {
          if ($this->cli) echo "SUCCESS\n";
        } else {
          if ($this->cli) echo "ERROR CREATING CACHE\n";
        }
      } else {
        // Update covercache table to record timestamp of failure
        $db->query("REPLACE INTO locum_covercache SET bnum = " . $bib_rec['bnum'] . ", cover_stdnum = ''");
        if ($this->cli) echo "IMAGE NOT FOUND Material: " . $this->locum_config['formats'][$bib_rec['mat_code']] . "\n";
        $not_found[$bib_rec['mat_code']]++;
      }
    }

    if ($this->cli) {
      $end_time = time();
      $percentage = number_format(($num_found / $total) * 100, 2);
      $average = number_format(($end_time - $this->start_time) / $total, 2);
      echo "\nPROCESSING COMPLETE: $num_found / $total ($percentage%)\n";
      echo "TIME: " . date("m/d/Y H:i:s", $this->start_time) . "-" . date("m/d/Y H:i:s", $end_time);
      echo " ($average sec. avg.)\n";
      echo "Not Found by Material: ";
      foreach ($not_found as $mat_code => $number) {
        echo $this->locum_config['formats'][$mat_code] . ": " . $number . " ";
      }
      echo "\nLOOKUP COUNT: " . $this->lookup_count . " XISBN COUNT: " . $this->xisbn_count . "\n";
      foreach ($this->sources_count as $id => $count) {
        echo $id . " COUNT: " . $count . " ";
      }
      echo "\n";
    }
  }

  /**
   * return an array of related isbns
   * utilizes worldcat xisbn service
   */
  public function get_xisbn($isbn) {
    if ($this->xisbn_count < $this->locum_config['xisbn_config']['limit']) {
      if ($this->cli) echo "XISBN ";
      $requestURL = "http://xisbn.worldcat.org/webservices/xid/isbn/$isbn";
      $requestIP = $this->locum_config['xisbn_config']['requestIP'];
      $secret = $this->locum_config['xisbn_config']['secret'];
      $hash = md5("$requestURL|$requestIP|$secret");
      $token = $this->locum_config['xisbn_config']['token'];

      $xml = simplexml_load_file($requestURL . "?method=getEditions&format=xml&token=$token&hash=$hash");

      $isbns = array();
      if ($xml->isbn) {
        foreach($xml->isbn as $xisbn) {
          $isbns[] = (string)$xisbn;
        }
      }
      $this->xisbn_count++;

      return $isbns;
    }
    else {
      return FALSE; // over the limit for lookups
    }
  }

  /**
   * Display a block of covercache statistics.
   */
  public function get_stats() {
    $db = MDB2::connect($this->dsn);
    $total = array_shift($db->queryRow("SELECT COUNT(bnum) FROM locum_bib_items"));
    $processed = array_shift($db->queryRow("SELECT COUNT(locum_bib_items.bnum) FROM locum_bib_items, locum_covercache WHERE locum_bib_items.bnum = locum_covercache.bnum"));
    $cached = array_shift($db->queryRow("SELECT COUNT(locum_bib_items.bnum) FROM locum_bib_items, locum_covercache WHERE locum_bib_items.bnum = locum_covercache.bnum AND locum_covercache.cover_stdnum <> ''"));

    $stats .= "Total Bib Records   : $total\n";
    $stats .= "Records Processed   : $processed (" . number_format(($processed / $total) * 100, 2) . "%)\n";
    $stats .= "Unprocessed Records : " . ($total - $processed) . "\n";
    $stats .= "Covers Cached       : $cached (" . number_format(($cached / $total) * 100, 2) . "%)\n";

    return $stats;
  }

  /**
   * retrieve the widest cover image from a given isbn
   */
  private function isbn_coverimage_url($isbn) {
    if ($this->cli) echo "trying $isbn ";
    $this->lookup_count++;
    $isbn = trim($isbn);
    $images = array();
    $lt_key = "6e6aec9cdd7691dfbf7cafbed38e5ec8";

    $sources = $this->locum_config['coversources'];

    foreach ($sources as $id => &$source) {
      if ($this->locum_config['sourcelimits'][$id] &&
          $this->lookup_count > $this->locum_config['sourcelimits'][$id]) {
        break;
      }
      $source = str_replace("%ISBN%", $isbn, $source);
      if (fopen($source, "rb")) {
        list($width, $height) = self::getcoverimgsize($source);
        if ($width > 1) {
          $images[$width] = $id;
        }
      }
    }

    // return largest image (or NULL if none found)
    ksort($images);
    $largest_id = end($images);
    $this->sources_count[$largest_id]++;
    return $sources[$largest_id];
  }

  public function get_coverimage($bib) {
    $cover = array('stdnum' => $bib['stdnum'][0], 'image_url' => "");

    if ($cover['stdnum']) {
      $cover['stdnum'] = preg_replace("/[^A-Z0-9]/", "", $cover['stdnum']);
      $cover['image_url'] = self::isbn_coverimage_url($cover['stdnum']);

      if (empty($cover['image_url'])) {
        // try xisbn
        foreach (self::get_xisbn($cover['stdnum']) as $xisbn) {
          if ($cover['image_url'] = self::isbn_coverimage_url($xisbn)) {
            $cover['stdnum'] = $xisbn;
            break;
          }
        }
      }
    }
    if (empty($cover['image_url']) && $bib['title'] &&
        (strpos($this->locum_config['amazon_search']['mat_codes'], $bib['mat_code']) !== FALSE) ) {
      $keywords = str_replace("  ", " ", preg_replace("/[^0-9A-Za-z' ]/", '', trim($bib['title'])));
      if ($this->cli) echo "AZN_API ";
      $amazon_vars = array(
        'Operation' => "ItemSearch",
        'Sort' => "relevancerank",
        'ResponseGroup' => "Medium",
        'Keywords' => $keywords,
      );
      switch($bib['mat_code']) {
        case 'g':
          $amazon_vars['SearchIndex'] = "DVD";
          break;
        case 'j':
          $amazon_vars['SearchIndex'] = "Music";
          $amazon_vars['Artist'] = preg_replace("/[^A-Za-z ]/", '', trim($bib['author']));
          break;
      }

      $xml = self::aws_signed_request('com', $amazon_vars, $this->locum_config['amazon_search']['accesskey'], $this->locum_config['amazon_search']['secret']);
      $bibTitleChars = strtolower(preg_replace("/[^0-9A-Za-z]/", '', $keywords . ' ' . $bib['title_medium'] . ' ' . $bib['edition']));
      foreach($xml->Items->Item as $item) {
        if ($bib['mat_code'] == 'g' && empty($item->ItemAttributes->AudienceRating)) { // Ignore videos with no audience rating
          break;
        }
        $itemTitleChars = strtolower(preg_replace("/[^0-9A-Za-z]/", '', $item->ItemAttributes->Title));
        if (strpos($itemTitleChars, $bibTitleChars) !== FALSE) {
          if ($url = (string)$item->LargeImage->URL) {
            if (fopen($url, "rb")) {
              list($width, $height) = self::getcoverimgsize($url);
              if ($width > 1) {
                $matches[] = array('stdnum' => $item->ASIN, 'image_url' => $url);
              }
            }
          }
        }
      }
      if ($matches) {
        $cover = reset($matches); // take closest
      }
    }
    if ($cover['image_url'])
      return $cover;
    else
      return FALSE;
  }

  /**
   * create_covercache(): processes the image given by the $image_url into
   * different sizes, writes them to disk
   */
  public function create_covercache($bnum, $image_url, $stdnum, $uid = 0) {
    $success = FALSE;
    $allowed_widths = $this->locum_config['cover_widths'];

    if ($image = self::image_create_file($image_url)) {
      list($width, $height) = self::getcoverimgsize($image_url);
      if ($width && $height) {
        foreach($allowed_widths as $new_width) {
          $new_height = ($new_width / $width) * $height;
          $new_image = imagecreatetruecolor($new_width,$new_height);
          imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
          $new_image_path = $this->locum_config['cover_cache']['image_path'] . "/{$bnum}_{$new_width}.jpg";
          imagejpeg($new_image, $new_image_path, 85);
          chmod($new_image_path, 0666);
          imagedestroy($new_image);
        }
      }
      // Update DB tables with bnum and stdnum
      $db = MDB2::connect($this->dsn);
//      $db->query("UPDATE locum_bib_items SET cover_img = 'CACHE' WHERE bnum = " . $bnum);
      $couch = new couchClient($this->couchserver,$this->couchdatabase);
      try {
        $doc = $couch->getDoc((string)$bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
      $doc->cover_img = "CACHE";
      $couch->storeDoc($doc);
      $sql = "REPLACE INTO locum_covercache SET bnum = :bnum, cover_stdnum = :stdnum, uid = :uid";
      $statement = $db->prepare($sql, array('integer', 'text', 'integer'));
      $statement->execute(array('bnum' => $bnum, 'stdnum' => $stdnum, 'uid' => $uid));
      $success = TRUE;
    }
    return $success;
  }

  /**
   * Get Cover Cache info for a bib
   */
  public function covercache_info($bnum) {
    $db = MDB2::connect($this->dsn);
    $res =& $db->query("SELECT * FROM locum_covercache WHERE bnum = " . intval($bnum));
    if ($cache = $res->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      return $cache;
    } else {
      return FALSE;
    }
  }

  /**
   * Clear an erroneous cover image from the cache
   */
  public function clear_covercache($bnum) {
    //$db = MDB2::connect($this->dsn);
    //$db->query("UPDATE locum_bib_items SET cover_img = '' WHERE bnum = " . $bnum);
    $couch = new couchClient($this->couchserver,$this->couchdatabase);
    try {
        $doc = $couch->getDoc((string)$bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
    unset($doc->cover_img);
    $couch->storeDoc($doc);
  }

  private function image_create_file($f) {
    if ($img = @imagecreatefromstring(@file_get_contents($f))) {
      return $img;
    } else {
      return FALSE;
    }
  }

  /**
   * Blatently stolen from http://www.php.net/manual/en/function.getimagesize.php#88793
   *
   * "As noted below, getimagesize will download the entire image before it checks for
   * the requested information. This is extremely slow on large images that are accessed
   * remotely. Since the width/height is in the first few bytes of the file, there is
   * no need to download the entire file. I wrote a function to get the size of a JPEG
   * by streaming bytes until the proper data is found to report the width and height"
   */
  private function getcoverimgsize($img_loc) {
    if ($handle = fopen($img_loc, "rb")) {
      $new_block = NULL;
      if(!feof($handle)) {
        $new_block = fread($handle, 32);
        $i = 0;
        if($new_block[$i]=="\xFF" && $new_block[$i+1]=="\xD8" && $new_block[$i+2]=="\xFF" && $new_block[$i+3]=="\xE0") {
          $i += 4;
          if($new_block[$i+2]=="\x4A" && $new_block[$i+3]=="\x46" && $new_block[$i+4]=="\x49" && $new_block[$i+5]=="\x46" && $new_block[$i+6]=="\x00") {
            // Read block size and skip ahead to begin cycling through blocks in search of SOF marker
            $block_size = unpack("H*", $new_block[$i] . $new_block[$i+1]);
            $block_size = hexdec($block_size[1]);
            while(!feof($handle)) {
              $i += $block_size;
              $new_block .= fread($handle, $block_size);
              if($new_block[$i]=="\xFF") {
                $isjpeg = TRUE;
                // New block detected, check for SOF marker
                $sof_marker = array("\xC0", "\xC1", "\xC2", "\xC3", "\xC5", "\xC6", "\xC7", "\xC8", "\xC9", "\xCA", "\xCB", "\xCD", "\xCE", "\xCF");
                if(in_array($new_block[$i+1], $sof_marker)) {
                  // SOF marker detected. Width and height information is contained in bytes 4-7 after this byte.
                  $size_data = $new_block[$i+2] . $new_block[$i+3] . $new_block[$i+4] . $new_block[$i+5] . $new_block[$i+6] . $new_block[$i+7] . $new_block[$i+8];
                  $unpacked = unpack("H*", $size_data);
                  $unpacked = $unpacked[1];
                  $height = hexdec($unpacked[6] . $unpacked[7] . $unpacked[8] . $unpacked[9]);
                  $width = hexdec($unpacked[10] . $unpacked[11] . $unpacked[12] . $unpacked[13]);
                  return array($width, $height);
                } else {
                  // Skip block marker and read block size
                  $i += 2;
                  $block_size = unpack("H*", $new_block[$i] . $new_block[$i+1]);
                  $block_size = hexdec($block_size[1]);
                }
              } else {
                $isjpeg = FALSE;
              }
            }
          }
        }
      }
      if (!$isjpeg) {
        if ($image = self::image_create_file($img_loc)) {
          $ifname = rand(1, 999999);
          imagejpeg($image, "/tmp/cache_{$ifname}.jpg", 85);
          $size = getimagesize("/tmp/cache_{$ifname}.jpg");
          unlink("/tmp/cache_{$ifname}.jpg");
          if ($size) { return $size; } else { return FALSE; }
        } else {
          return FALSE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Count how many characters two strings have in common
   */
  public function num_equal_chars($str1, $str2) {
    for($i = 0; $i < strlen($str1); $i++) {
      if ($str1[$i] != $str2[$i]) break;
    }
    return $i;
  }

  private function aws_signed_request($region, $params, $public_key, $private_key)
  {
    /*
    Copyright (c) 2009 Ulrich Mierendorff

    Permission is hereby granted, free of charge, to any person obtaining a
    copy of this software and associated documentation files (the "Software"),
    to deal in the Software without restriction, including without limitation
    the rights to use, copy, modify, merge, publish, distribute, sublicense,
    and/or sell copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
    THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
    FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
    DEALINGS IN THE SOFTWARE.
    */

    /*
    Parameters:
        $region - the Amazon(r) region (ca,com,co.uk,de,fr,jp)
        $params - an array of parameters, eg. array("Operation"=>"ItemLookup",
                        "ItemId"=>"B000X9FLKM", "ResponseGroup"=>"Small")
        $public_key - your "Access Key ID"
        $private_key - your "Secret Access Key"
    */

    // some paramters
    $method = "GET";
    $host = "ecs.amazonaws.".$region;
    $uri = "/onca/xml";

    // additional parameters
    $params["Service"] = "AWSECommerceService";
    $params["AWSAccessKeyId"] = $public_key;
    // GMT timestamp
    $params["Timestamp"] = gmdate("Y-m-d\TH:i:s\Z");
    // API version
    $params["Version"] = "2009-03-31";

    // sort the parameters
    ksort($params);

    // create the canonicalized query
    $canonicalized_query = array();
    foreach ($params as $param=>$value) {
      $param = str_replace("%7E", "~", rawurlencode($param));
      $value = str_replace("%7E", "~", rawurlencode($value));
      $canonicalized_query[] = $param."=".$value;
    }
    $canonicalized_query = implode("&", $canonicalized_query);

    // create the string to sign
    $string_to_sign = $method."\n".$host."\n".$uri."\n".$canonicalized_query;

    // calculate HMAC with SHA256 and base64-encoding
    $signature = base64_encode(hash_hmac("sha256", $string_to_sign, $private_key, True));

    // encode the signature for the request
    $signature = str_replace("%7E", "~", rawurlencode($signature));

    // create request
    $request = "http://".$host.$uri."?".$canonicalized_query."&Signature=".$signature;

    // do request
    $response = @file_get_contents($request);

    if ($response === False) {
      return False;
    } else {
      // parse XML
      $pxml = simplexml_load_string($response);
      if ($pxml === False) {
        return False; // no xml
      } else {
        return $pxml;
      }
    }
  }
}
