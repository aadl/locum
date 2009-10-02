<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * @package Locum
 * @author John Blyberg
 */

require_once('locum.php');

/**
 * The Locum Client class represents the "front end" of Locum.  IE, the interactive piece.
 * This is the class you would use to do searches, place holds, get patron info, etc.
 * Ideally, this code should never have to be touched.
 */
class locum_client extends locum {

  /**
   * Does an index search via Sphinx and returns the results
   *
   * @param string $type Search type.  Valid types are: author, title, series, subject, keyword (default)
   * @param string $term Search term/phrase
   * @param int $limit Number of results to return
   * @param int $offset Where to begin result set -- for pagination purposes
   * @param array $sort_array Numerically keyed array of sort parameters.  Valid options are: newest, oldest
   * @param array $location_array Numerically keyed array of location params.  NOT IMPLEMENTED YET
   * @param array $facet_args String-keyed array of facet parameters. See code below for array structure
   * @return array String-keyed result set
   */
  public function search($type, $term, $limit, $offset, $sort_opt = NULL, $format_array = array(), $location_array = array(), $facet_args = array(), $override_search_filter = FALSE, $limit_available = FALSE) {
    
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($type, $term, $limit, $offset, $sort_opt = NULL, $format_array = array(), $location_array = array(), $facet_args = array(), $override_search_filter = FALSE, $limit_available = FALSE);
    }
    
    
    require_once($this->locum_config['sphinx_config']['api_path'] . '/sphinxapi.php');
    $db =& MDB2::connect($this->dsn);
    
    $term_arr = explode('?', trim(preg_replace('/\//', ' ', $term)));
    $term = trim($term_arr[0]);
    
    if ($term == '*' || $term == '**') { 
      $term = ''; 
    } else {
      $term_prestrip = $term;
      //$term = preg_replace('/[^A-Za-z0-9*\- ]/iD', '', $term);
      $term = preg_replace('/\*\*/','*', $term);
    }
    $final_result_set['term'] = $term;
    $final_result_set['type'] = trim($type);

    $cl = new SphinxClient();
    
    $cl->SetServer($this->locum_config['sphinx_config']['server_addr'], (int) $this->locum_config['sphinx_config']['server_port']);

    // As always, defaults to 'keyword'
    
    $bool = FALSE;
    $cl->SetMatchMode(SPH_MATCH_ALL);
    if($term == "") { $cl->SetMatchMode(SPH_MATCH_ANY); }
    if(preg_match("/ \| /i",$term) || preg_match("/ \-/i",$term) || preg_match("/ \!/i",$term)) { $cl->SetMatchMode(SPH_MATCH_BOOLEAN); $bool = TRUE; }
    if(preg_match("/ OR /i",$term)) { $cl->SetMatchMode(SPH_MATCH_BOOLEAN); $term = preg_replace('/ OR /i',' | ',$term);$bool = TRUE; }
    if(preg_match("/\"/i",$term) || preg_match("/\@/i",$term)) { $cl->SetMatchMode(SPH_MATCH_EXTENDED2); $bool = TRUE; }
    
    switch ($type) {
      case 'author':
        $cl->SetFieldWeights(array('author' => 50, 'addl_author' => 30));
        $idx = 'bib_items_author';
        break;
      case 'title':
        $cl->SetFieldWeights(array('title' => 50, 'title_medium' => 50, 'series' => 30));
        $idx = 'bib_items_title';
        break;
      case 'series':
        $cl->SetFieldWeights(array('title' => 5, 'series' => 80));
        $idx = 'bib_items_title';
        break;
      case 'subject':
        $idx = 'bib_items_subject';
        break;
      case 'callnum':
        $cl->SetFieldWeights(array('callnum' => 100));
        $idx = 'bib_items_callnum';
        //$cl->SetMatchMode(SPH_MATCH_ANY);
        break;
      case 'tags':
        $cl->SetFieldWeights(array('tag_idx' => 100));
        $idx = 'bib_items_tags';
        $cl->SetMatchMode(SPH_MATCH_PHRASE);
        break;
      case 'reviews':
        $cl->SetFieldWeights(array('review_idx' => 100));
        $idx = 'bib_items_reviews';
        break;
      case 'keyword':
      default:
        $cl->SetFieldWeights(array('title' => 50, 'title_medium' => 50, 'author' => 70, 'addl_author' => 40, 'tag_idx' =>35, 'series' => 25, 'review_idx' => 10, 'notes' => 10, 'subjects' => 5 ));
        $idx = 'bib_items_keyword';
        break;

    }

    // Filter out the records we don't want shown, per locum.ini
    if (!$override_search_filter) {
      if (trim($this->locum_config['location_limits']['no_search'])) {
        $cfg_filter_arr = parent::csv_parser($this->locum_config['location_limits']['no_search']);
        foreach ($cfg_filter_arr as $cfg_filter) {
          $cfg_filter_vals[] = crc32($cfg_filter);
        }
        $cl->SetFilter('loc_code', $cfg_filter_vals, TRUE);
      }
    }

    // Valid sort types are 'newest' and 'oldest'.  Default is relevance.
    switch($sort_opt) {
      case 'newest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'pub_year DESC, @relevance DESC');
        break;
      case 'oldest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'pub_year ASC, @relevance DESC');
        break;
      case 'catalog_newest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'bib_created DESC, @relevance DESC');
        break;
      case 'catalog_oldest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'bib_created ASC, @relevance DESC');
        break;
      case 'title':
        $cl->SetSortMode(SPH_SORT_ATTR_ASC, 'title_ord');
        break;
      case 'author':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'author_null ASC, author_ord ASC');
        break;
      case 'top_rated':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'rating_idx');
        break;
      case 'popular_week':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_week');
        break;
      case 'popular_month':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_month');
        break;
      case 'popular_year':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_year');
        break;
      case 'popular_total':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_total');
        break;
      case 'atoz':
        $cl->SetSortMode(SPH_SORT_ATTR_ASC, 'title_ord');
        break;
      case 'ztoa':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'title_ord');
        break;
      default:
        $cl->SetSortMode(SPH_SORT_RELEVANCE);
        break;
    }

    // Filter by material types
    if (is_array($format_array)) {
      foreach ($format_array as $format) {
        if (strtolower($format) != 'all') {
          $filter_arr_mat[] = crc32(trim($format));
        }
      }
      if (count($filter_arr_mat)) { $cl->SetFilter('mat_code', $filter_arr_mat); }
    }
    
    // Filter by location
    if (count($location_array)) {
      foreach ($location_array as $location) {
        if (strtolower($location) != 'all') {
          $filter_arr_loc[] = crc32(trim($location));
        }
      }
      if (count($filter_arr_loc)) { $cl->SetFilter('loc_code', $filter_arr_loc); }
    }

    $cl->SetRankingMode(SPH_RANK_WORDCOUNT);
    $cl->SetLimits(0, 5000, 5000);
    $sph_res_all = $cl->Query($term, $idx); // Grab all the data for the facetizer
    
    if(empty($sph_res_all['matches']) && $bool == FALSE && $term != "*" && $type != "tags") {
      $term = '"'.$term.'"/1';
      $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
      $sph_res_all = $cl->Query($term, $idx);
      $forcedchange = 'yes';
    }
    
    $cl->SetLimits((int) $offset, (int) $limit);


    // And finally.... we search.
    $sph_res = $cl->Query($term, $idx);

    // Include descriptors
    $final_result_set['num_hits'] = $sph_res['total'];
    if ($sph_res['total'] <= $this->locum_config['api_config']['suggestion_threshold']) {
      if ($this->locum_config['api_config']['use_yahoo_suggest'] == TRUE) {
        $final_result_set['suggestion'] = self::yahoo_suggest($term_prestrip);
      }
    }
    
    if (is_array($sph_res['matches'])) {
      foreach ($sph_res['matches'] as $bnum => $attr) {
        $bib_hits[] = $bnum;
      }
    }
    if (is_array($sph_res_all['matches'])) {
      foreach ($sph_res_all['matches'] as $bnum => $attr) {
        $bib_hits_all[] = $bnum;
      }
    }
    
    // Limit list to available
    
    if ($limit_available && $final_result_set['num_hits']) {
      $limit_available = strval($limit_available);
      // remove bibs from full list that we *know* are unavailable
      $cache_cutoff = date("Y-m-d H:i:00", time() - 3600); // 1 hour
      if (array_key_exists($limit_available, $this->locum_config['locations'])) {
        // if location passed in, filter out by that location, otherwise just the ones that are empty
        $location_sql = "NOT LIKE '%$limit_available%'";
      } else {
        $location_sql = "= ''";
      }
      
      $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      $utfprep = $db->query($utf);
      
      $sql = "SELECT bnum FROM locum_availability WHERE bnum IN (" . implode(", ", $bib_hits_all) . ") AND locations $location_sql AND timestamp > '$cache_cutoff'";
      $init_result =& $db->query($sql);
      $unavail_bibs = $init_result->fetchCol();
      $bib_hits_all = array_values(array_diff($bib_hits_all,$unavail_bibs));

      // rebuild from the full list
      unset($bib_hits);
      $available_count = 0;
      foreach ($bib_hits_all as $key => $bib_hit) {
        $bib_avail = self::get_item_status($bib_hit);
        $available = (array_key_exists($limit_available, $this->locum_config['locations']) ? is_array($bib_avail['locations'][$limit_available]) : ($bib_avail['total'] > 0));
        if ($available) {
          $available_count++;
          if ($available_count > $offset) {
            $bib_hits[] = $bib_hit;
            if (count($bib_hits) == $limit) {
              //found as many as we need for this page
              break;
            }
          }
        } else {
          // remove the bib from the bib_hits_all array
          unset($bib_hits_all[$key]);
        }
      }
      
      // trim out the rest of the array based on *any* cache value
      if(!empty($bib_hits_all)) {
        $sql = "SELECT bnum FROM locum_availability WHERE bnum IN (" . implode(", ", $bib_hits_all) . ") AND locations $location_sql";
        $init_result =& $db->query($sql);
        if($init_result){  
          $unavail_bibs =& $init_result->fetchCol();
          $bib_hits_all = array_values(array_diff($bib_hits_all,$unavail_bibs));
        }
      }
      $final_result_set['num_hits'] = count($bib_hits_all);
    }

    // Refine by facets
    
    if (count($facet_args)) {
      $where = '';

      // Series
      if ($facet_args['facet_series']) {
        $where .= ' AND (';
        $or = '';
        foreach ($facet_args['facet_series'] as $series) {
          $where .= $or . ' series LIKE \'' . $db->escape($series, 'text') . '%\'';
          $or = ' OR';
        }
        $where .= ')';
      }

      // Language
      if ($facet_args['facet_lang']) {
        foreach ($facet_args['facet_lang'] as $lang) {
          $lang_arr[] = $db->quote($lang, 'text');
        }
        $where .= ' AND lang IN (' . implode(', ', $lang_arr) . ')';
      }
      // Pub. Year
      if ($facet_args['facet_year']) {
        $where .= ' AND pub_year IN (' . implode(', ', $facet_args['facet_year']) . ')';
      }
      
      // Ages
      if ($facet_args['age']) {
        $where .= " AND ages LIKE '%" . $facet_args['age'] . "%'";
      }
      
      if(!empty($bib_hits_all)) {
        $sql1 = 'SELECT bnum FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where;
        $sql2 = 'SELECT bnum FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where . " LIMIT $offset, $limit";
        $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
        $utfprep = $db->query($utf);
        $init_result =& $db->query($sql1);
        $bib_hits_all = $init_result->fetchCol();
        $init_result =& $db->query($sql2);
        $bib_hits = $init_result->fetchCol();
      }
      $facet_total = count($bib_hits_all);
      $final_result_set['num_hits'] = $facet_total;
    }

    // First, we have to get the values back, unsorted against the Sphinx-sorted array
    if (count($bib_hits)) {
      $sql = 'SELECT * FROM locum_bib_items WHERE bnum IN (' . implode(', ', $bib_hits) . ')';
      $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      $utfprep = $db->query($utf);
      $init_result =& $db->query($sql);
      $init_bib_arr = $init_result->fetchAll(MDB2_FETCHMODE_ASSOC);
      foreach ($init_bib_arr as $init_bib) {
        // Get availability
        $init_bib['availability'] = self::get_item_status($init_bib['bnum']);
        $bib_reference_arr[(string) $init_bib['bnum']] = $init_bib;
      }

      // Now we reconcile against the sphinx result
      foreach ($sph_res_all['matches'] as $sph_bnum => $sph_binfo) {
        if (in_array($sph_bnum, $bib_hits)) {
          $final_result_set['results'][] = $bib_reference_arr[$sph_bnum];
        }
      }
    }
    
    $db->disconnect();
    $final_result_set['facets'] = self::facetizer($bib_hits_all);
    if($forcedchange == 'yes') { $final_result_set['changed'] = 'yes'; }
    
    return $final_result_set;

  }

  /**
   * Formulates the array used to put together the faceted search panel.
   * This function is called from the search function.
   *
   * @param array $bib_hits_all Standard array of bib numbers
   * @return array Faceted array of information for bib numbers passed.  Keyed by: mat, series, loc, lang, pub_year
   */
  public function facetizer($bib_hits_all) {

    $db =& MDB2::connect($this->dsn);
    if (count($bib_hits_all)) {
      $where_str = 'WHERE bnum in (' . implode(",", $bib_hits_all) . ')';
      
      $sql['mat'] = 'SELECT DISTINCT mat_code, COUNT(mat_code) AS mat_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY mat_code ORDER BY mat_code_sum DESC';
      $sql['series'] = 'SELECT DISTINCT series, COUNT(series) AS series_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY series ORDER BY series ASC';
      $sql['loc'] = 'SELECT DISTINCT loc_code, COUNT(loc_code) AS loc_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY loc_code ORDER BY loc_code_sum DESC';
      $sql['lang'] = 'SELECT DISTINCT lang, COUNT(lang) AS lang_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY lang ORDER BY lang_sum DESC';
      $sql['pub_year'] = 'SELECT DISTINCT pub_year, COUNT(pub_year) AS pub_year_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY pub_year ORDER BY pub_year DESC';

      foreach ($sql AS $fkey => $fquery) {
        $tmp_res =& $db->query($fquery);
        $tmp_res_arr = $tmp_res->fetchAll();
        foreach ($tmp_res_arr as $values) {
          if ($values[0] && $values[1]) { $result[$fkey][$values[0]] = $values[1]; }
        }
      }
      
      // Create non-distinct facets for age
      foreach ($this->locum_config['ages'] as $age_code => $age_name) {
        $sql = "SELECT COUNT(bnum) as age_sum FROM locum_facet_heap $where_str AND ages LIKE '%$age_code%'";
        $res =& $db->query($sql);
        $result['ages'][$age_code] = $res->fetchOne();
      }
      
      // Create facets from availability cache
      $sql = "SELECT COUNT(bnum) as avail_sum FROM locum_availability $where_str AND locations != ''";
      $res =& $db->query($sql);
      $result['avail']['any'] = $res->fetchOne();
      foreach ($this->locum_config['locations'] as $loc_code => $loc_name) {
        $sql = "SELECT COUNT(bnum) as avail_sum FROM locum_availability $where_str AND locations LIKE '%$loc_code%'";
        $res =& $db->query($sql);
        $result['avail'][$loc_code] = $res->fetchOne();
      }
      
      $db->disconnect();
      return $result;
    }
  }

  /**
   * Returns an array of item status info (availability, location, status, etc).
   *
   * @param string $bnum Bib number
   * @return array Detailed item availability 
   */
  public function get_item_status($bnum, $force_refresh = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum, $force_refresh);
    }
    
    $db = MDB2::connect($this->dsn);
    
    if (!$force_refresh && $this->locum_config['avail_cache']['cache']) {
      $this->locum_config['avail_cache']['cache_cutoff'];
      $cache_cutoff = date("Y-m-d H:i:s", (time() - (60 * $this->locum_config['avail_cache']['cache_cutoff'])));
      // check the cache table
      $sql = "SELECT * FROM locum_availability WHERE bnum = :bnum AND timestamp > '$cache_cutoff'";
      $statement = $db->prepare($sql, array('integer'));
      $dbr = $statement->execute(array('bnum' => $bnum));
      if (PEAR::isError($dbr) && $this->cli) {
        echo "DB connection failed... " . $dbr->getMessage() . "\n";
      }
      $statement->Free();
      $cached = $dbr->NumRows();
    }
    if ($cached) {
      $row = $dbr->fetchRow(MDB2_FETCHMODE_ASSOC);
      $avail_array = unserialize($row['available']);
      return $avail_array;
    }
    
    $status = $this->locum_cntl->item_status($bnum);
    $result['total'] = count($status['items']);
    $result['avail'] = 0;
    $result['holds'] = $status['holds'];
    $result['on_order'] = $status['on_order'];
    $result['orders'] = $status['orders'];
    $result['nextdue'] = 0;
    $result['items'] = $status['items'];
    $result['locations'] = array();
    $result['callnums'] = array();
    $result['ages'] = array();
    $loc_codes = array();
    if (count($status['items'])) {
      foreach ($status['items'] as $item) {
        $result['locations'][$item['loc_code']][$item['age']]++;
        if (!in_array($item['age'], $result['ages'])) {
          $result['ages'][] = $item['age'];
        }
        if (!in_array($item['callnum'], $result['callnums'])) {
          $result['callnums'][] = $item['callnum'];
        }
        if ($result['nextdue'] == 0 || $result['nextdue'] > $item['due']) {
          $result['nextdue'] = $item['due'];
        }
        if (!in_array($item['loc_code'], $loc_codes)) {
          $loc_codes[] = $item['loc_code'];
        }
      }
    }
    
    if ($this->locum_config['avail_cache']['cache']) {
      // Update Cache
      $avail_blob = serialize($result);
      $ages = count($result['ages']) ? "'" . implode(',', $result['ages']) . "'" : 'NULL';
      $locs = count($loc_codes) ? "'" . implode(',', $loc_codes) . "'" : 'NULL';
      $sql = "REPLACE INTO locum_availability (bnum, ages, locations, available) VALUES (:bnum, $ages, $locs, '$avail_blob')";
      $statement = $db->prepare($sql, array('integer'));
      $dbr = $statement->execute(array('bnum' => $bnum));
      if (PEAR::isError($dbr) && $this->cli) {
        echo "DB connection failed... " . $dbr->getMessage() . "\n";
      }
      $statement->Free();
    }
    
    return $result;
  }
  
  /**
   * Returns information about a bib title.
   *
   * @param string $bnum Bib number
   * @return array Bib item information
   */
  public function get_bib_item($bnum) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum);
    }
    
    $db = MDB2::connect($this->dsn);
    $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
    $utfprep = $db->query($utf);
    $res = $db->query("SELECT * FROM locum_bib_items WHERE bnum = '$bnum' AND active = '1' LIMIT 1");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    return $item_arr[0];
  }
  
  /**
   * Returns information about an array of bib titles.
   *
   * @param array $bnum_arr Bib number array
   * @return array Bib item information for $bnum_arr
   */
  public function get_bib_items_arr($bnum_arr) {
    if (count($bnum_arr)) {
      $db =& MDB2::connect($this->dsn);
      $sql = 'SELECT * FROM locum_bib_items WHERE bnum IN (' . implode(', ', $bnum_arr) . ')';
      $res =& $db->query($sql);
      $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
      $db->disconnect();
      foreach ($item_arr as $item) {
        $bib[(string) $item['bnum']] = $item;
      }
    }
    return $bib;
  }

  /**
   * Returns an array of patron information
   *
   * @param string $pid Patron barcode number or record number
   * @return boolean|array Array of patron information or FALSE if login fails
   */
  public function get_patron_info($pid) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($pid);
    }
    
    $patron_info = $this->locum_cntl->patron_info($pid);
    return $patron_info;
  }

  /**
   * Returns an array of patron checkouts
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function get_patron_checkouts($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL);
    }
    
    $patron_checkouts = $this->locum_cntl->patron_checkouts($cardnum, $pin);
    return $patron_checkouts;
  }

  /**
   * Returns an array of patron checkouts for history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function get_patron_checkout_history($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL);
    }
    
    $patron_checkout_history = $this->locum_cntl->patron_checkout_history($cardnum, $pin);
    return $patron_checkout_history;
  }
  /**
   * Opts patron in or out of checkout history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function set_patron_checkout_history($cardnum, $pin = NULL, $action) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL, $action);
    }
    
    $success = $this->locum_cntl->patron_checkout_history_toggle($cardnum, $pin, $action);
    return $success;
  }
  
  /**
   * Returns an array of patron holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron holds or FALSE if login fails
   */
  public function get_patron_holds($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL);
    }
    
    $patron_holds = $this->locum_cntl->patron_holds($cardnum, $pin);
    return $patron_holds;
  }
  
  /**
   * Renews items and returns the renewal result
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array Array of varname => item numbers to be renewed, or NULL for everything.
   * @return boolean|array Array of item renewal statuses or FALSE if it cannot renew for some reason
   */
  public function renew_items($cardnum, $pin = NULL, $items = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL, $items = NULL);
    }
    
    $renew_status = $this->locum_cntl->renew_items($cardnum, $pin, $items);
    return $renew_status;
  }

  /**
   * Cancels holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array Array of varname => item numbers to be renewed, or NULL for everything.
   * @return boolean TRUE or FALSE if it cannot cancel for some reason
   */
  public function cancel_holds($cardnum, $pin = NULL, $items = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL, $items = NULL);
    }
    
    $cancel_status = $this->locum_cntl->cancel_holds($cardnum, $pin, $items);
    return $cancel_status;
  }
  
  // <CraftySpace+>
  /**
   * Places or removes freezes on holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array $holdfreezes_to_update Array of bnum => new status.
   * @return boolean TRUE or FALSE if it cannot cancel for some reason
   */
  public function update_holdfreezes($cardnum, $pin, $holdfreezes_to_update) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $holdfreezes_to_update);
    }
    
    $update_status = $this->locum_cntl->update_holdfreezes($cardnum, $pin, $holdfreezes_to_update);
    return $update_status;
  }
  
  // </CraftySpace+>
  /**
   * Places holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $bnum Bib item record number to place a hold on
   * @param string $varname additional variable name (such as an item number for item-level holds) to place a hold on
   * @param string $pin Patron pin/password
   * @param string $pickup_loc Pickup location value
   * @return boolean TRUE or FALSE if it cannot place the hold for some reason
   */
  public function place_hold($cardnum, $bnum, $varname = NULL, $pin = NULL, $pickup_loc = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $bnum, $varname = NULL, $pin = NULL, $pickup_loc = NULL);
    }
    
    $request_status = $this->locum_cntl->place_hold($cardnum, $bnum, $varname, $pin, $pickup_loc);
    if ($request_status['success']) {
      $db =& MDB2::connect($this->dsn);
      $db->query("INSERT INTO locum_holds_placed VALUES ('$bnum', NOW())");
    }
    return $request_status;
  }
  
  /**
   * Returns an array of patron fines
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron holds or FALSE if login fails
   */
  public function get_patron_fines($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL);
    }
    
    $patron_fines = $this->locum_cntl->patron_fines($cardnum, $pin);
    return $patron_fines;
  }
  
  /**
   * Pays patron fines.
   * $payment_details structure:
   * [varnames]     = An array of varnames to id which fines to pay.
   * [total]      = payment total.
   * [name]      = Name on the credit card.
   * [address1]    = Billing address.
   * [address2]    = Billing address.  (opt)
   * [city]      = Billing address city.
   * [state]      = Billing address state.
   * [zip]      = Billing address zip.
   * [email]      = Cardholder email address.
   * [ccnum]      = Credit card number.
   * [ccexpmonth]    = Credit card expiration date.
   * [ccexpyear]    = Credit card expiration year.
   * [ccseccode]    = Credit card security code.
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array payment_details
   * @return array Payment result
   */
  public function pay_patron_fines($cardnum, $pin = NULL, $payment_details) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin = NULL, $payment_details);
    }
    
    $payment_result = $this->locum_cntl->pay_patron_fines($cardnum, $pin, $payment_details);
    return $payment_result;
  }
  
  
  /************ External Content Functions ************/
  
  /**
   * Formulates "Did you mean?" I may move to the Yahoo API for this..
   * 
   * @param string $str String to check
   * @return string|boolean Either returns a string suggestion or FALSE
   */
  public function yahoo_suggest($str) {
    if (trim($str) && $this->locum_config['api_config']['yahh_app_id']) {
      $appid = $this->locum_config['api_config']['yahh_app_id'];
    } else {
      $appid = 'YahooDemo';
    }
    $url = 'http://search.yahooapis.com/WebSearchService/V1/spellingSuggestion?appid=' . $appid . '&query=' . $str;
    $suggest_obj = @simplexml_load_file($url);

    if (trim($suggest_obj->Result)) {
      return trim($suggest_obj->Result);
    } else {
      return FALSE;
    }
  }
  
  /*
   * Client-side version of get_syndetics().  Does not harvest, only checks the database.
   */
  public function get_syndetics($isbn) {

    $cust_id = $this->locum_config['api_config']['syndetic_custid'];
    $db =& MDB2::connect($this->dsn);
    $res = $db->query("SELECT links FROM locum_syndetics_links WHERE isbn = '$isbn' AND updated > DATE_SUB(NOW(), INTERVAL 2 MONTH) LIMIT 1");
    $dbres = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    
    if ($dbres[0]['links']) {
      $links = explode('|', $dbres[0]['links']);
    } else {
      return FALSE;
    }
    
    if ($links) {
      foreach ($links as $link) {
        $link_result[$valid_hits[$link]] = 'http://www.syndetics.com/index.aspx?isbn=' . $isbn . '/' . $link . '.html&client=' . $cust_id;
      }
    }
    $db->disconnect();
    return $link_result;
  }
  

}
