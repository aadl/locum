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
  public function search($type, $term, $limit, $offset, $sort_opt = NULL, $format_array = array(), $location_array = array(), $facet_args = array(), $override_search_filter = FALSE, $limit_available = FALSE, $show_inactive = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($type, $term, $limit, $offset, $sort_opt, $format_array, $location_array, $facet_args, $override_search_filter, $limit_available);
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
      $term = preg_replace('/\*\*/','*', $term); //fix for how iii used to do wildcards
    }
    $final_result_set['term'] = $term;
    $final_result_set['type'] = trim($type);

    $cl = new SphinxClient();
    $cl->SetServer($this->locum_config['sphinx_config']['server_addr'], (int) $this->locum_config['sphinx_config']['server_port']);

    // Defaults to 'keyword', non-boolean
    $bool = FALSE;
    $cl->SetMatchMode(SPH_MATCH_ALL);

    if (!$term) {
      // Searches for everything (usually for browsing purposes--Hot/New Items, etc..)
      $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
    } else {
      $picturebook = array('picturebook','picture book');
      $picbk_search = '(@callnum ^E)';
      $term = str_ireplace($picturebook,$picbk_search,$term);
      if($type == 'keyword') {
        $fiction_search = '@@relaxed (@subjects fiction | @callnum mystery | @callnum fantasy | @callnum fiction | @callnum western | @callnum romance)';
        $term = str_ireplace('fiction',$fiction_search,$term);
        $nonfiction = array('nonfiction','non-fiction');
        $nonfic_search = '@@relaxed (@callnum "0*" | @callnum "1*" | @callnum "2*" | @callnum "3*" | @callnum "4*" | @callnum "5*" | @callnum "6*" | @callnum "7*" | @callnum "8*" | @callnum "9*")';
        $term = str_ireplace($nonfiction,$nonfic_search,$term);
      }
      // Is it a boolean search?
      if (preg_match("/ \| /i", $term) || preg_match("/ \-/i", $term) || preg_match("/ \!/i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_BOOLEAN);
        $bool = TRUE;
      }
      if (preg_match("/ OR /i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_BOOLEAN);
        $term = preg_replace('/ OR /i',' | ',$term);
        $bool = TRUE;
      }

      // Is it a phrase search?
      if (preg_match("/\"/i", $term) || preg_match("/\@/i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
        $bool = TRUE;
      }
    }

    // Set up for the various search types
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
        //$cl->SetMatchMode(SPH_MATCH_PHRASE);
        break;
      case 'reviews':
        $cl->SetFieldWeights(array('review_idx' => 100));
        $idx = 'bib_items_reviews';
        break;
      case 'keyword':
      default:
        $cl->SetFieldWeights(array('title' => 400, 'title_medium' => 30, 'author' => 70, 'addl_author' => 40, 'tag_idx' =>25, 'series' => 25, 'review_idx' => 10, 'notes' => 10, 'subjects' => 5 ));
        $idx = 'bib_items_keyword';
        break;
    }

    // Filter out the records we don't want shown, per locum.ini
    if (!$override_search_filter) {
      if (trim($this->locum_config['location_limits']['no_search'])) {
        $cfg_filter_arr = $this->csv_parser($this->locum_config['location_limits']['no_search']);
        foreach ($cfg_filter_arr as $cfg_filter) {
          $cfg_filter_vals[] = $this->string_poly($cfg_filter);
        }
        $cl->SetFilter('loc_code', $cfg_filter_vals, TRUE);
      }
    }

    // Valid sort types are 'newest' and 'oldest'.  Default is relevance.
    switch ($sort_opt) {
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
        $cl->SetSortMode(SPH_SORT_EXPR, "@weight + (hold_count_total)*0.02");
        break;
    }

    // Filter by material types
    if (is_array($format_array)) {
      foreach ($format_array as $format) {
        if (strtolower($format) != 'all') {
          $filter_arr_mat[] = $this->string_poly(trim($format));
        }
      }
      if (count($filter_arr_mat)) {
        $cl->SetFilter('mat_code', $filter_arr_mat);
      }
    }

    // Filter by location
    if (count($location_array)) {
      foreach ($location_array as $location) {
        if (strtolower($location) != 'all') {
          $filter_arr_loc[] = $this->string_poly(trim($location));
        }
      }
      if (count($filter_arr_loc)) {
        $cl->SetFilter('loc_code', $filter_arr_loc);
      }
    }

    // Filter by pub_year
    if ($facet_args['facet_year']) {
      if (strpos($facet_args['facet_year'][0], '-') !== FALSE) {
        $min_year = 1;
        $max_year = 9999;

        $args = explode('-', $facet_args['facet_year'][0]);
        $min_arg = (int) $args[0];
        $max_arg = (int) $args[1];

        if ($min_arg && ($min_arg > $min_year)) {
          $min_year = $min_arg;
        }
        if ($max_arg && ($max_arg < $max_year)) {
          $max_year = $max_arg;
        }

        $cl->setFilterRange('pub_year', $min_year, $max_year);
      }
      else {
        $cl->SetFilter('pub_year', $facet_args['facet_year']);
      }
    }

    // Filter by pub_decade
    if ($facet_args['facet_decade']) {
      $cl->SetFilter('pub_decade', $facet_args['facet_decade']);
    }

    // Filter by Series
    if (count($facet_args['facet_series'])) {
      foreach ($facet_args['facet_series'] as &$facet_series) {
        $facet_series = $this->string_poly($facet_series);
      }
      $cl->SetFilter('series_attr', $facet_args['facet_series']);
    }

    // Filter by Language
    if (count($facet_args['facet_lang'])) {
      foreach ($facet_args['facet_lang'] as &$facet_lang) {
        $facet_lang = $this->string_poly($facet_lang);
      }
      $cl->SetFilter('lang', $facet_args['facet_lang']);
    }

    // Filter inactive records
    if (!$show_inactive) {
      $cl->SetFilter('active', array('0'), TRUE);
    }

    // Filter by age
    if (count($facet_args['age'])) {
      foreach($facet_args['age'] as $age_facet) {
        $cl->SetFilter('ages', array($this->string_poly($age_facet)));
      }
    }

    // Filter by availability
    if ($limit_available) {
      $cl->SetFilter('branches', array($this->string_poly($limit_available)));
    }

    $cl->SetRankingMode(SPH_RANK_SPH04);

    $proximity_check = $cl->Query($term, $idx); // Quick check on number of results
    // If original match didn't return any results, try a proximity search
    if (empty($proximity_check['matches']) && $bool == FALSE && $term != "*" && $type != "tags") {
      $term = '"' . $term . '"/1';
      $cl->SetMatchMode(SPH_MATCH_EXTENDED);
      $forcedchange = 'yes';
    }

    // Paging/browsing through the result set.
    $sort_limit = 2000;
    if(($offset + $limit) > $sort_limit){
      $sort_limit = $offset + $limit;
    }
    $cl->SetLimits((int) $offset, (int) $limit, (int) $sort_limit);

    // And finally.... we search.
    $cl->AddQuery($term, $idx);

    // CREATE FACETS
    $cl->SetLimits(0, 1000); // Up to 1000 facets
    $cl->SetArrayResult(TRUE); // Allow duplicate documents in result, for facet grouping

    $cl->SetGroupBy('pub_year', SPH_GROUPBY_ATTR);
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('pub_decade', SPH_GROUPBY_ATTR);
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('mat_code', SPH_GROUPBY_ATTR, '@count desc');
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('branches', SPH_GROUPBY_ATTR, '@count desc');
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('ages', SPH_GROUPBY_ATTR, '@count desc');
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('lang', SPH_GROUPBY_ATTR, '@count desc');
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $cl->SetGroupBy('series_attr', SPH_GROUPBY_ATTR, '@count desc');
    $cl->AddQuery($term, $idx);
    $cl->ResetGroupBy();

    $results = $cl->RunQueries();

    // Include descriptors
    $final_result_set['num_hits'] = $results[0]['total_found'];
    if ($results[0]['total'] <= $this->locum_config['api_config']['suggestion_threshold'] || $forcedchange == 'yes') {
      if ($this->locum_config['api_config']['use_yahoo_suggest'] == TRUE) {
        $final_result_set['suggestion'] = $this->yahoo_suggest($term_prestrip);
      }
    }

    // Pull full records out of Couch
    if ($final_result_set['num_hits']) {
      $skip_avail = $this->csv_parser($this->locum_config['format_special']['skip_avail']);
      $bib_hits = array();

      foreach ($results[0]['matches'] as $match) {
        $bib_hits[] = (string) $match['id'];
      }

      $final_result_set['results'] = $this->get_bib_items_arr($bib_hits);

      foreach ($final_result_set['results'] as &$result) {
        $result = $result['value'];
        if ($result['bnum']){
          // Get availability (Only cached)
          $result['status'] = $this->get_item_status($result['bnum'], FALSE, TRUE);
        }
      }
    }

    $final_result_set['facets'] = $this->sphinx_facetizer($results);

    if ($forcedchange == 'yes') {
      $final_result_set['changed'] = 'yes';
    }

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
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bib_hits_all);
    }

    $db =& MDB2::connect($this->dsn);
    if (count($bib_hits_all)) {
      $where_str = 'WHERE bnum IN (' . implode(",", $bib_hits_all) . ')';

      $sql['mat'] = 'SELECT DISTINCT mat_code, COUNT(mat_code) AS mat_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY mat_code ORDER BY mat_code_sum DESC';
      $sql['series'] = 'SELECT DISTINCT series, COUNT(series) AS series_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY series ORDER BY series ASC';
      $sql['loc'] = 'SELECT DISTINCT loc_code, COUNT(loc_code) AS loc_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY loc_code ORDER BY loc_code_sum DESC';
      $sql['lang'] = 'SELECT DISTINCT lang, COUNT(lang) AS lang_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY lang ORDER BY lang_sum DESC';
      $sql['pub_year'] = 'SELECT DISTINCT pub_year, COUNT(pub_year) AS pub_year_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY pub_year ORDER BY pub_year DESC';
      $sql['pub_decade'] = 'SELECT DISTINCT pub_decade, COUNT(pub_decade) AS pub_decade_sum FROM locum_facet_heap ' . $where_str . ' GROUP BY pub_decade ORDER BY pub_decade DESC';

      foreach ($sql AS $fkey => $fquery) {
        $tmp_res =& $db->query($fquery);
        $tmp_res_arr = $tmp_res->fetchAll();
        foreach ($tmp_res_arr as $values) {
          if ($values[0] && $values[1]) { $result[$fkey][$values[0]] = $values[1]; }
        }
      }

      // Create non-distinct facets for age
      foreach ($this->locum_config['ages'] as $age_code => $age_name) {
        $sql = "SELECT COUNT(bnum) as age_sum FROM locum_avail_ages $where_str AND age = '$age_code'";
        $res =& $db->query($sql);
        $age_count = $res->fetchOne();
        if ($age_count) {
          $result['ages'][$age_code] = $age_count;
        }
      }

      // Create facets from availability cache
      $result['avail']['any'] = 0;
      foreach ($this->locum_config['branches'] as $branch_code => $branch_name) {
        $sql = "SELECT COUNT(DISTINCT(bnum)) FROM locum_avail_branches $where_str AND branch = '$branch_code' AND count_avail > 0";
        $res =& $db->query($sql);
        $avail_count = $res->fetchOne();
        if (!$avail_count) { $avail_count = 0; }
        $result['avail']['any'] = $result['avail']['any'] + $avail_count;
        if ($avail_count) {
          $result['avail'][$branch_code] = $avail_count;
        }
      }

      $db->disconnect();
      return $result;
    }
  }

  public function sphinx_facetizer($results) {
    // Build lookup hashtable
    $hash = array();

    // Ages
    foreach ($this->locum_config['ages'] as $code => $name) {
      $index = $this->string_poly($code);
      $hash['ages'][$index] = strtolower($name);
    }
    ksort($hash['ages']);

    // Branches
    $any_code = $this->string_poly('any');
    $hash['branches'][$any_code] = 'any';
    foreach ($this->locum_config['branches'] as $code => $name) {
      $index = $this->string_poly($code);
      $hash['branches'][$index] = $code;
    }
    ksort($hash['branches']);

    // Material Formats
    foreach ($this->locum_config['formats'] as $code => $name) {
      $index = $this->string_poly($code);
      $hash['formats'][$index] = $code;
    }
    ksort($hash['formats']);

    // Languages
    foreach ($this->locum_config['languages'] as $code => $name) {
      $index = $this->string_poly($code);
      $hash['languages'][$index] = $code;
    }
    ksort($hash['languages']);

    $facets = array('pub_year',
                    'pub_decade',
                    'mat',
                    'avail',
                    'ages',
                    'lang',
                    'series');

    // Pub Year
    if (is_array($results[1]['matches'])) {
      foreach ($results[1]['matches'] as $match) {
        $pubyear = $match['attrs']['@groupby'];
        $count = $match['attrs']['@count'];
        $facets['pub_year'][$pubyear] = $count;
      }
    }

    // Pub Decade
    if (is_array($results[2]['matches'])) {
      foreach ($results[2]['matches'] as $match) {
        $pubdecade = $match['attrs']['@groupby'];
        $count = $match['attrs']['@count'];
        $facets['pub_decade'][$pubdecade] = $count;
      }
    }

    // Mat Code
    if (is_array($results[3]['matches'])) {
      foreach ($results[3]['matches'] as $match) {
        $mat_crc = $match['attrs']['@groupby'];
        $count = $match['attrs']['@count'];
        $format = $hash['formats'][$mat_crc];
        $facets['mat'][$format] = $count;
      }
    }

    // Branches
    if (is_array($results[4]['matches'])) {
      foreach ($results[4]['matches'] as $match) {
        $b_crc = $match['attrs']['@groupby'];
        $count = $match['attrs']['@count'];
        $branch = $hash['branches'][$b_crc];
        $facets['avail'][$branch] = $count;
      }
    }

    // Ages
    if (is_array($results[5]['matches'])) {
      foreach ($results[5]['matches'] as $match) {
        $age = $hash['ages'][$match['attrs']['@groupby']];
        $count = $match['attrs']['@count'];
        $facets['ages'][$age] = $count;
      }
    }

    // Languages
    if (is_array($results[6]['matches'])) {
      foreach ($results[6]['matches'] as $match) {
        $language = $hash['languages'][$match['attrs']['@groupby']];
        $count = $match['attrs']['@count'];
        $facets['lang'][$language] = $count;
      }
    }

    // Series
    if (is_array($results[7]['matches'])) {
      foreach ($results[7]['matches'] as $match) {
        if ($series = $match['attrs']['@groupby']) {
          if ($series_name = $this->redis->get('poly_string:' . $series)) {
            $series = $series_name;
          }
          $count = $match['attrs']['@count'];
          $facets['series'][$series] = $count;
        }
      }
    }

    return $facets;
  }

  /**
   * Rebuild the availcache:timestamps queue for processing.
   * Make sure every bib record has an entry in the queue.
   */
  public function rebuild_status_timestamps($skip = 0) {
    $couch = new couchClient($this->couchserver, $this->couchdatabase);
    try {
      $batch_limit = 1000;
      $total_rows = 9999999; // first batch will give us the total rows
      $default_timestamp = time() - (60 * 60 * 24); // default time is 24 hours ago, should move to top of queue
      $batch_count = 0;
      $added_count = 0;

      while ($skip < $total_rows) {
        $bibs = $couch->limit($batch_limit)->skip($skip)->getView('bib', 'lastupdate');

        // Check for total_rows
        if ($bibs->total_rows != $total_rows) {
          $total_rows = $bibs->total_rows;
        }

        // Update timestamps
        foreach ($bibs->rows as $bib) {
          $current = $this->redis->zscore('availcache:timestamps', $bib->id);
          if (empty($current)) {
            $this->redis->zadd('availcache:timestamps', $default_timestamp, $bib->id);
            $added_count++;
          }
        }

        // Check next batch
        $batch_count++;
        $skip = $batch_count * $batch_limit;
      }

      return $added_count;
    }
    catch ( Exception $e ) {
      return FALSE;
    }
  }

  /**
   * Returns an array of item status info (availability, location, status, etc).
   *
   * @param string $bnum Bib number
   * @return array Detailed item availability
   */
  public function get_item_status($bnum, $force_refresh = FALSE, $cache_only = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum, $force_refresh);
    }

    $result = array();
    $current_json = $this->redis->get('availcache:' . $bnum);

    if ($cache_only) {
      // use the cache table, regardless of timestamp
      if ($current_json) {
        $cached = TRUE;
      }
    }
    else if (!$force_refresh && $this->locum_config['avail_cache']['cache']) {
      // check the cache table
      $cutoff_timestamp = time() - (60 * $this->locum_config['avail_cache']['cache_cutoff']);
      if ($this->redis->zscore('availcache:timestamps', $bnum) > $cutoff_timestamp) {
        $cached = TRUE;
      }
    }

    if ($cached) {
      $result = json_decode($current_json, TRUE); // return as array
    }
    else {
      // Scrape and store new availability data
      if ($bib = self::get_bib_item($bnum)) {
        $skiporder = ($bib['mat_code'] == 's');
        $status = $this->locum_cntl->item_status($bnum, $skiporder);
        $result['avail'] = 0;
        $result['total'] = count($status['items']);
        $result['libuse'] = 0;
        $result['holds'] = $status['holds'];
        $result['on_order'] = $status['on_order'];
        $result['orders'] = count($status['orders']) ? $status['orders'] : array();
        $result['nextdue'] = 0;

        $result['locations'] = array();
        $result['callnums'] = array();
        $result['ages'] = array();
        $result['branches'] = array();
        $loc_codes = array();
        if (count($status['items'])) {
          foreach ($status['items'] as &$item) {
            // Tally availability
            $result['avail'] += $item['avail'];
            // Tally libuse
            $result['libuse'] += $item['libuse'];

            // Parse Locations
            $result['locations'][$item['loc_code']][$item['age']]['avail'] += $item['avail'];
            $result['locations'][$item['loc_code']][$item['age']]['total']++;

            // Parse Ages
            $result['ages'][$item['age']]['avail'] += $item['avail'];
            $result['ages'][$item['age']]['total']++;

            // Parse Branches
            $result['branches'][$item['branch']]['avail'] += $item['avail'];
            $result['branches'][$item['branch']]['total']++;

            // Parse Callnums
            if ($item['callnum'] !== $bib['callnum'] && strstr($bib['callnum'], $item['callnum'])) {
              $item['callnum'] = $bib['callnum'];
            }
            $result['callnums'][$item['callnum']]['avail'] += $item['avail'];
            $result['callnums'][$item['callnum']]['total']++;

            // Determine next item due date
            if ($result['nextdue'] == 0 || ($item['due'] > 0 && $result['nextdue'] > $item['due'])) {
              $result['nextdue'] = $item['due'];
            }
            // Parse location code
            if (!in_array($item['loc_code'], $loc_codes) && trim($item['loc_code'])) {
              $loc_codes[] = $item['loc_code'];
            }
          }
        }
        $result['items'] = $status['items'];

        // Cache the result
        $this->redis->zadd('availcache:timestamps', time(), $bnum);
        $available_json = json_encode($result);

        if ($available_json != $current_json || $force_refresh) {
          // Only update the cache if the scraped value is different than the current value
          $this->redis->set('availcache:' . $bnum, $available_json);

          // Update Location Attributes in Sphinx
          $branches = array();
          foreach($result['branches'] as $branch => $details) {
            if ($details['avail']) {
              $branches[] = crc32($branch); // UpdateAttributes automatically converts to unsigned
            }
          }
          if (count($branches)) {
            $branches[] = crc32('any'); // UpdateAttributes automatically converts to unsigned
          }

          require_once($this->locum_config['sphinx_config']['api_path'] . '/sphinxapi.php');
          $cl = new SphinxClient();
          $cl->SetServer($this->locum_config['sphinx_config']['server_addr'], (int) $this->locum_config['sphinx_config']['server_port']);

          // Specify indexes to update (abstract into config?)
          $indexes = 'bib_items_keyword ' .
                     'bib_items_author ' .
                     'bib_items_title ' .
                     'bib_items_subject ' .
                     'bib_items_callnum ' .
                     'bib_items_tags ' .
                     'bib_items_reviews';
          $index_count = 7; // Match count of index names in $indexes string

          $update_num = $cl->UpdateAttributes($indexes, array('branches'), array($bnum => array($branches)), TRUE);
          if ($update_num != $index_count) {
            $log = '[' . date("Y-m-d H:i:s") . '] record num: b' . $bnum .
                   ', updated ' . $update_num . '/' . $index_count . ' indices';
            $this->redis->set('availcache:mva_update:last_error', $log);
            $this->redis->incr('availcache:mva_update:error_count');
          }
        }
      }
    }

    return $result;
  }

  /**
   * Returns information about a bib title.
   *
   * @param string $bnum Bib number
   * @param boolean $get_inactive Return records whose active = 0
   * @return array Bib item information
   */
  public function get_bib_item($bnum, $get_inactive = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum);
    }

/*
    $db = MDB2::connect($this->dsn);
    $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
    $utfprep = $db->query($utf);
    if ($get_inactive) {
      $sql = "SELECT * FROM locum_bib_items WHERE bnum = '$bnum' LIMIT 1";
    } else {
      $sql = "SELECT * FROM locum_bib_items WHERE bnum = '$bnum' AND active = '1' LIMIT 1";
    }
    $res = $db->query($sql);
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    $item_arr[0]['stdnum'] = preg_replace('/[^\d]/','', $item_arr[0]['stdnum']);
    return $item_arr[0];
*/
    $couch = new couchClient($this->couchserver,$this->couchdatabase);
    try {
        $doc = $couch->asArray()->getDoc($bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
    return $doc;
  }

  public function count_download($bnum, $type, $tracknum = NULL) {
    $couch = new couchClient($this->couchserver,$this->couchdatabase);
    try {
        $doc = $couch->getDoc($bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
    if($tracknum) {
      if($type == "play"){
        $count = $doc->tracks->$tracknum->plays ? $doc->tracks->$tracknum->plays : 0;
        $count++;
        $doc->tracks->$tracknum->plays = $count;
      } else if($type == "track") {
        $count = $doc->tracks->$tracknum->downloads ? $doc->tracks->$tracknum->downloads : 0;
        $count++;
        $doc->tracks->$tracknum->downloads = $count;
      }
    }
    else {
      $key = "download_".$type;
      $count = $doc->$key ? $doc->$key : 0;
      $count++;
      $doc->$key = $count;
    }
    $couch->storeDoc($doc);
  }

  public function get_cd_tracks($bnum) {
    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT * FROM sample_tracks WHERE bnum = '$bnum' ORDER BY track");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    return $item_arr;
  }
  public function get_upc($bnum) {
    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT upc FROM sample_bibs WHERE bnum = '$bnum'");
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
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum_arr);
    }

    if (count($bnum_arr)) {
      $couch = new couchClient($this->couchserver,$this->couchdatabase);
      try {
        $doc = $couch->asArray()->keys($bnum_arr)->getView('sphinxemit','by_sphinxid');
      } catch ( Exception $e ) {
        return FALSE;
      }
/*
      $db =& MDB2::connect($this->dsn);
      $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      $utfprep = $db->query($utf);
      $sql = 'SELECT * FROM locum_bib_items WHERE bnum IN (' . implode(', ', $bnum_arr) . ')';
      $res =& $db->query($sql);
      $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
      $db->disconnect();
      foreach ($item_arr as $item) {
        $item['stdnum'] = preg_replace('/[^\d]/','', $item['stdnum']);
        $bib[(string) $item['bnum']] = $item;
      }
*/
    }
    return $doc['rows'];
  }

  public function get_bib_items($bnum_arr) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum_arr);
    }

    if (count($bnum_arr)) {
      $couch = new couchClient($this->couchserver,$this->couchdatabase);
      try {
        $doc = $couch->asArray()->include_docs(true)->keys($bnum_arr)->getAllDocs();
      } catch ( Exception $e ) {
        return FALSE;
      }
    }
    return $doc['rows'];
  }

	/**
	 * Create a new patron in the ILS
	 *
	 * Note: this may not be supported by all connectors. Further, it may turn out that
	 * different ILS's require different data for this function. Thus the $patron_data
	 * parameter is an array which can contain whatever is appropriate for the current ILS.
	 *
	 * @param array $patron_data
	 * @return var
	 */
	public function create_patron($patron_data) {
		if (!is_array($patron_data) || !count($patron_data)) {
			return false;
		}
		$new_patron = $this->locum_cntl->create_patron($patron_data);
		return $new_patron;
	}

	/**
	 * Returns an array of patron information
	 *
	 * @param string $pid Patron barcode number or record number
	 * @param string $user_key for use with Sirsi
	 * @param string $alt_id for use with Sirsi
	 * @return boolean|array Array of patron information or FALSE if login fails
	 */
	public function get_patron_info($pid = null, $user_key = null, $alt_id = null) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($pid, $user_key, $alt_id);
    }

		$patron_info = $this->locum_cntl->patron_info($pid, $user_key, $alt_id);
		return $patron_info;
	}

	/**
	 * Update user info in ILS.
	 * Note: this may not be supported by all connectors.
	 *
	 * @param string $pid Patron barcode number or record number
	 * @param string $email address to set
	 * @param string $pin to set
	 * @return boolean|array
	 */
	public function set_patron_info($pid, $email = null, $pin = null) {
		$success = $this->locum_cntl->set_patron_info($pid, $email, $pin);
		return $success;
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
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    $patron_checkouts = $this->locum_cntl->patron_checkouts($cardnum, $pin);
    foreach($patron_checkouts as &$patron_checkout) {
      // lookup bnum from inum
      if (!$patron_checkout['bnum']) {
        $patron_checkout['bnum'] = self::inum_to_bnum($patron_checkout['inum']);
      }
      if ($patron_checkout['ill'] == 0) {
        $bib = self::get_bib_item($patron_checkout['bnum'], TRUE);
        $patron_checkout['bib'] = $bib;
        $patron_checkout['avail'] = self::get_item_status($patron_checkout['bnum'], FALSE, TRUE);
        $patron_checkout['scraped_title'] = $patron_checkout['title'];
        $patron_checkout['title'] = $bib['title'];
        if ($bib['title_medium']) {
          $patron_checkout['title'] .= ' ' . $bib['title_medium'];
        }
        if($bib['author'] != '') {
          $patron_checkout['author'] = $bib['author'];
        }
        $patron_checkout['addl_author'] = $bib['addl_author'];
        //if($bib['author']) {$patron_checkout['author'] = $bib['author']};
      }
    }
    return $patron_checkouts;
  }

  /**
   * Returns an array of patron checkouts for history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array $last_record Array containing: 'bnum' => Bib num, 'date' => Date of last record harvested.
   *              It will return everything after that record if this value is passed
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function get_patron_checkout_history($cardnum, $pin = NULL, $last_record = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    return $this->locum_cntl->patron_checkout_history($cardnum, $pin, $action);
  }

  /**
   * Opts patron in or out of checkout history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function set_patron_checkout_history($cardnum, $pin = NULL, $action = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $action);
    }

    return $this->locum_cntl->patron_checkout_history_toggle($cardnum, $pin, $action);
  }

  /**
   * Deletes patron checkout history off the ILS server
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param string $action NULL = do nothing, 'all' = delete all records, 'selected' = Delete records in $vars array
   * @param array $vars array of variables referring to records to delete (optional)
   * @param array $last_record Array containing: 'bnum' => Bib num, 'date' => Date of last record harvested
   */
  public function delete_patron_checkout_history($cardnum, $pin = NULL, $action = NULL, $vars = NULL, $last_record = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

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
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    $patron_holds = $this->locum_cntl->patron_holds($cardnum, $pin);
    if (count($patron_holds)) {
      foreach ($patron_holds as &$patron_hold) {
        // lookup bnum from inum
        if (!$patron_hold['bnum']) {
          $patron_hold['bnum'] = self::inum_to_bnum($patron_hold['inum']);
        }
        if ($patron_hold['ill'] == 0) {
          $bib = self::get_bib_item($patron_hold['bnum'], TRUE);
          $patron_hold['bib'] = $bib;
          //$patron_hold['avail'] = self::get_item_status($patron_checkout['bnum'], FALSE, TRUE);
          $patron_hold['scraped_title'] = $patron_hold['title'];
          $patron_hold['title'] = $bib['title'];
          if ($bib['title_medium']) {
            $patron_hold['title'] .= ' ' . $bib['title_medium'];
          }
          if($bib['author'] != '') {
            $patron_hold['author'] = $bib['author'];
          }
          $patron_hold['addl_author'] = $bib['addl_author'];
          //if($bib['author']) {$patron_checkout['author'] = $bib['author']};
        }
      }
    }
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
      return $hook->{__FUNCTION__}($cardnum, $pin, $items);
    }

    $renew_status = $this->locum_cntl->renew_items($cardnum, $pin, $items);
    return $renew_status;
  }

  /**
   * Updates holds/reserves
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array $cancelholds Array of varname => item/bib numbers to be cancelled, or NULL for everything.
   * @param array $holdfreezes_to_update Array of updated holds freezes.
   * @param array $pickup_locations Array of pickup location changes.
   * @return boolean TRUE or FALSE if it cannot cancel for some reason
   */
  public function update_holds($cardnum, $pin = NULL, $cancelholds = array(), $holdfreezes_to_update = array(), $pickup_locations = array(), $suspend_changes = array()) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $cancelholds, $holdfreezes_to_update, $pickup_locations, $suspend_changes);
    }

    return $this->locum_cntl->update_holds($cardnum, $pin, $cancelholds, $holdfreezes_to_update, $pickup_locations, $suspend_changes);
  }

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
      return $hook->{__FUNCTION__}($cardnum, $bnum, $varname, $pin, $pickup_loc);
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
      return $hook->{__FUNCTION__}($cardnum, $pin);
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
      return $hook->{__FUNCTION__}($cardnum, $pin, $payment_details);
    }

    $payment_result = $this->locum_cntl->pay_patron_fines($cardnum, $pin, $payment_details);
    return $payment_result;
  }

  /*
   * Returns an array of random bibs.
   */
  public function get_bib_numbers($limit = 10) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($limit);
    }

    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT bnum FROM locum_bib_items ORDER BY RAND() LIMIT $limit");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    $bnums = array();
    foreach ($item_arr as $item) {
      $bnums[] = $item['bnum'];
    }
    return $bnums;
  }

  public function inum_to_bnum($inum, $force_refresh = FALSE) {
    $inum = intval($inum);
    $db = MDB2::connect($this->dsn);
    $cache_cutoff = date("Y-m-d H:i:s", time() - 60 * 60 * 24 * 7); // 1 week
    $cached = FALSE;

    if (!$force_refresh) {
      // check the cache table
      $sql = "SELECT * FROM locum_inum_to_bnum WHERE inum = :inum AND timestamp > '$cache_cutoff'";
      $statement = $db->prepare($sql, array('integer'));
      $result = $statement->execute(array('inum' => $inum));
      if (PEAR::isError($result) && $this->cli) {
        echo "DB connection failed... " . $results->getMessage() . "\n";
      }
      $statement->Free();
      $cached = $result->NumRows();
    }
    if ($cached) {
      $row = $result->fetchRow(MDB2_FETCHMODE_ASSOC);
      $bnum = $row['bnum'];
    } else {
      // get fresh info from the catalog
      $iii_webcat = $this->locum_config[ils_config][ils_server];
      $url = 'http://' . $iii_webcat . '/record=i' . $inum;
      $bib_page_raw = utf8_encode(file_get_contents($url));
      preg_match('/marc~b([0-9]*)/', $bib_page_raw, $bnum_raw_match);
      $bnum = $bnum_raw_match[1];

      if ($bnum) {
        $sql = "REPLACE INTO locum_inum_to_bnum (inum, bnum) VALUES (:inum, :bnum)";
        $statement = $db->prepare($sql, array('integer', 'integer'));
        $result = $statement->execute(array('inum' => $inum, 'bnum' => $bnum));
        if (PEAR::isError($result) && $this->cli) {
          echo "DB connection failed... " . $results->getMessage() . "\n";
        }
        $statement->Free();
      }
    }
    return $bnum;
  }

  public function get_uid_from_token($token) {
    $db = MDB2::connect($this->dsn);
    $sql = "SELECT * FROM locum_tokens WHERE token = '$token' LIMIT 1";
    $res = $db->query($sql);
    $patron_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    return $patron_arr[0]['uid'];
  }

  public function get_token($uid) {
    $db = MDB2::connect($this->dsn);
    $sql = "SELECT * FROM locum_tokens WHERE uid = '$uid' LIMIT 1";
    $res = $db->query($sql);
    $patron_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    return $patron_arr[0]['token'];
  }

  public function set_token($uid) {
    $db = MDB2::connect($this->dsn);
    $random = mt_rand();
    $token = md5($uid.$random);
    $sql = "REPLACE INTO locum_tokens (uid, token) VALUES (:uid, '$token')";
    $statement = $db->prepare($sql, array('integer'));
    $result = $statement->execute(array('uid' => $uid));
    return $token;
  }

  /************ External Content Functions ************/

  /**
   * Formulates "Did you mean?" I may move to the Yahoo API for this..
   *
   * @param string $str String to check
   * @return string|boolean Either returns a string suggestion or FALSE
   */
  public function yahoo_suggest($str) {
    return FALSE;
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($str);
    }
    $search_string = rawurlencode($str);
    $url = 'http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20search.spelling%20where%20query%3D%22'.$search_string.'%22&format=json';
    $suggest_obj = json_decode(file_get_contents($url));
    if (trim($suggest_obj->query->results->suggestion)) {
      return trim($suggest_obj->query->results->suggestion);
    } else {
      return FALSE;
    }
  }

  /*
   * Client-side version of get_syndetics().  Does not harvest, only checks the database.
   */
  public function get_syndetics($isbn) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($isbn);
    }

    // Strip out yucky non-isbn characters
    $isbn = preg_replace('/[^\dX]/', '', $isbn);

    $cust_id = $this->locum_config['api_config']['syndetic_custid'];
    if (!$cust_id) {
      return NULL;
    }

    $valid_hits = array(
      'TOC'         => 'Table of Contents',
      'BNATOC'      => 'Table of Contents',
      'FICTION'     => 'Fiction Profile',
      'SUMMARY'     => 'Summary / Annotation',
      'DBCHAPTER'   => 'Excerpt',
      'LJREVIEW'    => 'Library Journal Review',
      'PWREVIEW'    => 'Publishers Weekly Review',
      'SLJREVIEW'   => 'School Library Journal Review',
      'CHREVIEW'    => 'CHOICE Review',
      'BLREVIEW'    => 'Booklist Review',
      'HORNBOOK'    => 'Horn Book Review',
      'KIRKREVIEW'  => 'Kirkus Book Review',
      'ANOTES'      => 'Author Notes'
    );

    $db =& MDB2::connect($this->dsn);
    $res = $db->query("SELECT links FROM locum_syndetics_links WHERE isbn = '$isbn' LIMIT 1");
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

  public function make_donation($donate_form_values) {
    return $this->locum_cntl->make_donation($donate_form_values);
  }

}
