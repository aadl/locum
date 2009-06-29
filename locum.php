<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * @package Locum
 * @author John Blyberg
 */


/**
 * This is the parent Locum class for all locum-related activity.
 * This is called as the parent by either the client or the server piece.
 */
class locum {

	public $locum_config;
	public $locum_cntl;
	public $db;
	public $dsn;

	/**
	 * Locum constructor.
	 * This function prepares Locum for activity.
	 */
	public function __construct() {
		if (function_exists('locum_constructor_override')) {
		  locum_constructor_override($this);
		  return;
		}
		
		ini_set('memory_limit','128M');
		$this->locum_config = parse_ini_file('config/locum.ini', true);

		// Take care of requirements
		require_once('MDB2.php');
		require($this->locum_config['locum_config']['dsn_file']);
		$this->dsn = $dsn;
		$connector_type = 'locum_'
			. $this->locum_config['ils_config']['ils'] . '_'
			. $this->locum_config['ils_config']['ils_version'];
		require_once('connectors/' . $connector_type . '/' . $connector_type . '.php');

		// Fire up the Locum connector
		$locum_class_name = 'locum_' . $this->locum_config['ils_config']['ils'] . '_' . $this->locum_config['ils_config']['ils_version'];
		$this->locum_cntl =& new $locum_class_name;
		$this->locum_cntl->locum_config = $this->locum_config;
	}


	/**
	 * Instead of using stdout, Locum will handle output via this logging transaction.
	 *
	 * @param string $msg Log message
	 * @param int $severity Loglevel/severity of the message
	 * @param boolean $silent Output to stdout.  Default: yes
	 */
	public function putlog($msg, $severity = 1, $silent = TRUE) {
		if ($severity > 5) { $severity = 5; }
		$logfile = $this->locum_config['locum_config']['log_file'];
		$quiet = $this->locum_config['locum_config']['run_quiet'];

		for ($i = 0; $i < $severity; $i++) { $indicator .= '*'; }
		$indicator = '[' . str_pad($indicator, 5) . ']';
		file_put_contents($logfile, $indicator . ' ' . $msg . "\n", FILE_APPEND);
		if (!$quiet && !$silent) { print $indicator . ' ' . $msg . "\n"; }
	}

	/**
	 * Returns a specifically formatted array or string based on locum config values passed.  This is primarily used internally,
	 * for instance, with custom search handlers.
	 *
	 * @param string $csv Comma (or otherwise) separated values
	 * @param string $implode Optional implode character.  If not provided, the function will return an array of values
	 * @param string $separator Optional separator string for the values.  Defaults to comma.
	 * @return string|array Formatted values
	 */
	public function csv_parser($csv, $implode = FALSE, $separator = ',') {
		$csv_array = explode($separator, trim($csv));
		$cleaned = array();
		foreach ($csv_array as $csv_value) {
			$cleaned[] = trim($csv_value);
		}
		if ($implode) {
			$cleaned = implode($implode, $cleaned);
		}
		return $cleaned;
	}
}
