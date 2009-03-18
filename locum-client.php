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
	public function search($type, $term, $limit, $offset, $sort_opt = NULL, $format_array = array(), $location_array = array(), $facet_args = array(), $override_search_filter = FALSE) {
		
		require_once($this->locum_config[sphinx_config][api_path] . '/sphinxapi.php');
		$db =& MDB2::connect($this->dsn);
		
		$term_arr = explode('?', trim(preg_replace('/\//', ' ', $term)));
		$term = trim($term_arr[0]);
		
		if ($term == '*') { 
			$term = ''; 
		} else {
			$term_prestrip = $term;
			$term = preg_replace('/[^A-Za-z0-9*\- ]/iD', '', $term);
		}
		$final_result_set[term] = $term;
		$final_result_set[type] = trim($type);

		$cl = new SphinxClient();
		
		$cl->SetServer($this->locum_config[sphinx_config][server_addr], (int) $this->locum_config[sphinx_config][server_port]);

		// As always, defaults to 'keyword'
		$match_type = SPH_MATCH_ALL;
		switch ($type) {
			case 'author':
				$cl->SetFieldWeights(array('author' => 50, 'addl_author' => 30));
				$idx = 'bib_items_author';
				break;
			case 'title':
				$cl->SetFieldWeights(array('title' => 50, 'series' => 30));
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
				$match_type = SPH_MATCH_ANY;
				break;
			case 'tags':
				$cl->SetFieldWeights(array('tag_idx' => 100));
				$idx = 'bib_items_tags';
				$match_type = SPH_MATCH_PHRASE;
				break;
			case 'reviews':
				$cl->SetFieldWeights(array('review_idx' => 100));
				$idx = 'bib_items_reviews';
				break;
			case 'keyword':
			default:
				$cl->SetFieldWeights(array('title' => 50, 'author' => 50, 'addl_author' => 40, 'tag_idx' =>35, 'series' => 25, 'review_idx' => 10, 'notes' => 10, 'subjects' => 5 ));
				$idx = 'bib_items_keyword';
				break;

		}

		// Filter out the records we don't want shown, per locum.ini
		if (!$override_search_filter) {
			if (trim($this->locum_config[location_limits][no_search])) {
				$cfg_filter_arr = parent::csv_parser($this->locum_config[location_limits][no_search]);
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
				$cl->SetSortMode(SPH_SORT_ATTR_ASC, 'author_ord');
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
			default:
				$cl->SetSortMode(SPH_SORT_RELEVANCE);
				break;
		}

		// Filter by material types
		if (count($format_array)) {
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
			print_r($foo);
			if (count($filter_arr_loc)) { $cl->SetFilter('loc_code', $filter_arr_loc); }
		}

		$cl->SetLimits(0, 5000, 5000);
		$cl->SetMatchMode($match_type);
		$sph_res_all = $cl->Query($term, $idx); // Grab all the data for the facetizer
		
		$cl->SetLimits((int) $offset, (int) $limit);


		// And finally.... we search.
		$sph_res = $cl->Query($term, $idx);

		// Include descriptors
		$final_result_set[num_hits] = $sph_res[total];
		if ($sph_res[total] <= $this->locum_config[api_config][suggestion_threshold]) {
			if ($this->locum_config[api_config][use_google_suggest] == TRUE) {
				$final_result_set[suggestion] = self::google_suggest($term_prestrip);
			}
		}
		
		if (is_array($sph_res[matches])) {
			foreach ($sph_res[matches] as $bnum => $attr) {
				$bib_hits[] = $bnum;
			}
		}
		if (is_array($sph_res_all[matches])) {
			foreach ($sph_res_all[matches] as $bnum => $attr) {
				$bib_hits_all[] = $bnum;
			}
		}

		// Refine by facets
		if (count($facet_args)) {
			$where = '';

			// Series
			if ($facet_args[facet_series]) {
				$where .= ' AND (';
				$or = '';
				foreach ($facet_args[facet_series] as $series) {
					$where .= $or . ' series LIKE \'' . $db->escape($series, 'text') . '%\'';
					$or = ' OR';
				}
				$where .= ')';
			}

			// Language
			if ($facet_args[facet_lang]) {
				foreach ($facet_args[facet_lang] as $lang) {
					$lang_arr[] = $db->quote($lang, 'text');
				}
				$where .= ' AND lang IN (' . implode(', ', $lang_arr) . ')';
			}
			// Pub. Year
			if ($facet_args[facet_year]) {
				$where .= ' AND pub_year IN (' . implode(', ', $facet_args[facet_year]) . ')';
			}
			
			$sql1 = 'SELECT bnum FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where;
			$sql2 = 'SELECT bnum FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where . " LIMIT $offset, $limit";

			$init_result =& $db->query($sql1);
			$bib_hits_all = $init_result->fetchCol();
			$facet_total = count($bib_hits_all);
			$init_result =& $db->query($sql2);
			$bib_hits = $init_result->fetchCol();
			$final_result_set[num_hits] = $facet_total;
		}

		// First, we have to get the values back, unsorted against the Sphinx-sorted array
		if ($final_result_set[num_hits] > 0) {
			$sql = 'SELECT * FROM locum_bib_items WHERE bnum IN (' . implode(', ', $bib_hits) . ')';

			$init_result =& $db->query($sql);
			$init_bib_arr = $init_result->fetchAll(MDB2_FETCHMODE_ASSOC);
			foreach ($init_bib_arr as $init_bib) {
				$bib_reference_arr[(string) $init_bib[bnum]] = $init_bib;
			}

			// Now we reconcile against the sphinx result
			foreach ($sph_res_all[matches] as $sph_bnum => $sph_binfo) {
				if (in_array($sph_bnum, $bib_hits)) {
					$final_result_set[results][] = $bib_reference_arr[$sph_bnum];
				}
			}

		}
		
		$db->disconnect();
		$final_result_set[facets] = self::facetizer($bib_hits_all);
		
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
			$where_str = ' WHERE bnum in (';
			foreach ($bib_hits_all as $bnum) {
				$where_str .= $bnum . ',';
			}
			$where_str = substr($where_str, 0, -1) . ') ';
			

			$sql[mat] = 'SELECT DISTINCT mat_code, COUNT(mat_code) AS mat_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY mat_code ORDER BY mat_code_sum DESC';
			$sql[series] = 'SELECT DISTINCT series, COUNT(series) AS series_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY series ORDER BY series ASC';
			$sql[loc] = 'SELECT DISTINCT loc_code, COUNT(loc_code) AS loc_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY loc_code ORDER BY loc_code_sum DESC';
			$sql[lang] = 'SELECT DISTINCT lang, COUNT(lang) AS lang_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY lang ORDER BY lang_sum DESC';
			$sql[pub_year] = 'SELECT DISTINCT pub_year, COUNT(pub_year) AS pub_year_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY pub_year ORDER BY pub_year DESC';
//			$sql[subj] = 'SELECT DISTINCT subjects, COUNT(subjects) AS subjects_sum FROM bib_items_subject ' . $where_str . 'GROUP BY subjects ORDER BY subjects ASC';

			foreach ($sql AS $fkey => $fquery) {
				$tmp_res =& $db->query($fquery);
				$tmp_res_arr = $tmp_res->fetchAll();
				foreach ($tmp_res_arr as $values) {
					if ($values[0] && $values[1]) { $result[$fkey][$values[0]] = $values[1]; }
				}
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
	public function get_item_status($bnum) {
		$result = $this->locum_cntl->item_status($bnum);
		return $result;
	}
	
	/**
	 * Returns information about a bib title.
	 *
	 * @param string $bnum Bib number
	 * @return array Bib item information
	 */
	public function get_bib_item($bnum) {
		$db =& MDB2::connect($this->dsn);
		$res =& $db->query("SELECT * FROM locum_bib_items WHERE bnum = '$bnum' LIMIT 1");
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
				$bib[(string) $item[bnum]] = $item;
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
		$patron_checkouts = $this->locum_cntl->patron_checkouts($cardnum, $pin);
		return $patron_checkouts;
	}
	
	/**
	 * Returns an array of patron holds
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @return boolean|array Array of patron holds or FALSE if login fails
	 */
	public function get_patron_holds($cardnum, $pin = NULL) {
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
		$cancel_status = $this->locum_cntl->cancel_holds($cardnum, $pin, $items);
		return $cancel_status;
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
		$request_status = $this->locum_cntl->place_hold($cardnum, $bnum, $varname, $pin, $pickup_loc);
		if ($request_status[success]) {
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
		$patron_fines = $this->locum_cntl->patron_fines($cardnum, $pin);
		return $patron_fines;
	}
	
	/**
	 * Pays patron fines.
	 * $payment_details structure:
	 * [varnames] 		= An array of varnames to id which fines to pay.
	 * [total]			= payment total.
	 * [name]			= Name on the credit card.
	 * [address1]		= Billing address.
	 * [address2]		= Billing address.  (opt)
	 * [city]			= Billing address city.
	 * [state]			= Billing address state.
	 * [zip]			= Billing address zip.
	 * [email]			= Cardholder email address.
	 * [ccnum]			= Credit card number.
	 * [ccexpmonth]		= Credit card expiration date.
	 * [ccexpyear]		= Credit card expiration year.
	 * [ccseccode]		= Credit card security code.
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @param array payment_details
	 * @return array Payment result
	 */
	public function pay_patron_fines($cardnum, $pin = NULL, $payment_details) {
		$payment_result = $this->locum_cntl->pay_patron_fines($cardnum, $pin, $payment_details);
		return $payment_result;
	}
	
	/**
	 * Formulates "Did you mean?" I may move to the Yahoo API for this..
	 * 
	 * @param string $str String to check
	 * @return string|boolean Either returns a string suggestion or FALSE
	 */
	public function google_suggest($str) {
		$str_array = explode( ' ', $str );
		$words = implode( '+', $str_array );
		$ch = curl_init();
		$url = "http://www.google.com/search?q=" . $words;
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$html = curl_exec($ch);
		curl_close($ch);
		preg_match_all('/Did you mean: <\/font><a href=(.*?)class=p>(.*?)<\/a>/i', $html, $spelling1);
		preg_match_all('/See results for:(.*?)>(.*?)<\/a>/i', $html, $spelling2);

		if (isset($spelling1[2][0])) {
			return strip_tags($spelling1[2][0]);
		} else if (isset($spelling2[2][0])) {
			return strip_tags($spelling2[2][0]);
		} else {
			return FALSE;
		}
	}

}
