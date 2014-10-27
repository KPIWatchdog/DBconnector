<?php

/**
 * CONFIG - SECURITY
 */
define('KPIW_API_ALLOW_LOOPBACK_IP', true); // allow access from localhost for testing purposes
define('KPIW_API_ALLOW_PRIVATE_IP', true);  // allow access from LAN for testing purposes
define('KPIW_API_REQUIRE_HTTPS', false);	// allow access outside LAN only using HTTPS protocol (SSL must be installed on the server)
define('KPIW_API_KEY', '');					// optional API key (use the same key in your DB Connector settings on kpiwatchdog.com)

define('KPIW_IP', '54.246.101.51');			// predefined KPI Watchdog IP (do not change)

/**
 * CONFIG - DB (required only if you want to implement API methods reading data from database)
 */
define('KPIW_DB_TYPE', 'mysql');
define('KPIW_DB_HOST', '');
define('KPIW_DB_NAME', '');
define('KPIW_DB_USERNAME', '');
define('KPIW_DB_PASSWORD', '');
define('KPIW_DB_CHARSET', '');

//==============================================================================

/**
 * Api class <- implement API methods here
 */
class Kpiw_Api {
	/**
	 * Example daily API method
	 * 
	 * Returns random data for chosen date range.
	 * Result straucture (comments are optional):
	 *	array (
	 *		[$date1] => array (
	 *			[$segment1] => array (
	 *				[val] => $value1,
	 *				[comment] => $comment1
	 *			)
	 *		),
	 *		...
	 *	)
	 */
	public function randomData() {
		$required = array('start_date', 'end_date', 'freq');
		foreach ($required as $r) if (!isset($_GET[$r])) {
			$data = array('error' => 'Missing field: ' . $r);
			return $data;
		}
		
		if ($_GET['freq'] != 'd') {
			$data = array('error' => 'KPI frequency (' . $_GET['freq'] .') does not match API frequency (d)');
			return $data;
		}
		
		$startTime = strtotime($_GET['start_date']);
		$endTime = strtotime($_GET['end_date']);
		
		$data = array();
		if ($startTime > 0 && $endTime > 0) while ($startTime <= $endTime) {
			$date = date('Y-m-d', $startTime);
			$data[$date]['_total']['val'] = mt_rand(0, 1000);
			$startTime = strtotime('+1 day', $startTime);
		}
		
		return $data;
	}
	
	/**
	 * Example API DB method
	 * 
	 * Returns some aggregated data from database.
	 */
	public function dbData() {
		$required = array('start_date', 'end_date', 'freq');
		foreach ($required as $r) if (!isset($_GET[$r])) {
			$data = array('error' => 'Missing field: ' . $r);
			return $data;
		}
		
		if ($_GET['freq'] != 'd') {
			$data = array('error' => 'KPI frequency (' . $_GET['freq'] .') does not match API frequency (d)');
			return $data;
		}
		
		$this->_connectDb();
		
		$query = "
			SELECT DATE(add_date), COUNT(*)
			FROM cms_articles
			WHERE DATE(add_date) >= :start AND DATE(add_date) <= :end
			GROUP BY DATE(add_date)";
		$stmt = $this->_db->prepare($query);
		$stmt->execute(array('start' => $_GET['start_date'], 'end' => $_GET['end_date']));
		$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
		
		foreach ($rows as $date => $val) {
			$data[$date]['_total']['val'] = $val;
		}
		
		return $data;
	}
	
	/**
	 * Another example API DB method
	 * 
	 * Returns some aggregated data from database.
	 */
	public function dbDataWithSegments() {
		$required = array('start_date', 'end_date', 'freq');
		foreach ($required as $r) if (!isset($_GET[$r])) {
			$data = array('error' => 'Missing field: ' . $r);
			return $data;
		}
		
		if ($_GET['freq'] != 'd') {
			$data = array('error' => 'KPI frequency (' . $_GET['freq'] .') does not match API frequency (d)');
			return $data;
		}
		
		$this->_connectDb();
		
		$query = "
			SELECT DATE(add_date) AS dat, category_name AS cat, COUNT(*) AS val
			FROM cms_articles
			INNER JOIN cms_categories USING (category_id)
			WHERE DATE(add_date) >= :start AND DATE(add_date) <= :end
			GROUP BY DATE(add_date), category_id";
		$stmt = $this->_db->prepare($query);
		$stmt->execute(array('start' => $_GET['start_date'], 'end' => $_GET['end_date']));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		
		foreach ($rows as $row) {
			$data[$row['dat']][$row['cat']]['val'] = $row['val'];
		}
		
		return $data;
	}
	
	private function _connectDb() {
		if (KPIW_DB_TYPE != 'mysql') {
			throw new Exception('Database type not supported: ' . KPIW_DB_TYPE);
		}
		
		if (!extension_loaded('pdo_mysql')) {
			throw new Exception('PDO extension not installed.');
		}
		
		$dsn = KPIW_DB_TYPE . ':' . 'host=' . KPIW_DB_HOST . ';dbname=' . KPIW_DB_NAME;
		$this->_db = new PDO($dsn, KPIW_DB_USERNAME, KPIW_DB_PASSWORD, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . KPIW_DB_CHARSET));
	}
}

//==============================================================================

/**
 * Connector class - not nessecery to modify
 */
class Kpiw_Connector {
	const VERSION = '3.0';
	
	public function __construct() {
		if (!$this->_checkRequestMethod()) {
			header("HTTP/1.1 501 Not Implemented");
			$this->_outputAndExit(array('error' => 'Unsupported request method: ' . $_SERVER['REQUEST_METHOD']));
		}
		
		if (!$this->_checkPermisions()) {
			header("HTTP/1.1 403 Access denied");
			$this->_outputAndExit(array('error' => 'Access denied.'));
		}
		
		$method = $_GET['method'];
		if ($method == 'listMethods') {
			$data = $this->_listApiMethods();
			$this->_outputAndExit($data);
		}
		
		if ($method == 'getVersion') {
			$data = array('version' => self::VERSION);
			$this->_outputAndExit($data);
		}
		
		if (!$this->_checkApiMethod($method)) {
			header("HTTP/1.1 400 Bad Request");
			$this->_outputAndExit(array('error' => 'Unknown method: ' . (!strlen($method) ? 'null' : $method)));
		}
		
		try {
			$api = new Kpiw_Api();
			$data = $api->$method();
		} catch (Exception $e) {
			header("HTTP/1.1 500 Internal Server Error");
			$this->_outputAndExit(array('error' => $e->getMessage()));
		}
		
		if (isset($data['error'])) {
			header("HTTP/1.1 400 Bad Request");
		}
		$this->_outputAndExit($data);
	}
	
	private function _checkRequestMethod() {
		return $_SERVER['REQUEST_METHOD'] == 'GET';
	}
	
	private function _checkPermisions() {
		if (KPIW_API_ALLOW_LOOPBACK_IP && $this->_isLoopbackIp($_SERVER['REMOTE_ADDR'])) {
			return true;
		}
		
		if (KPIW_API_ALLOW_PRIVATE_IP && $this->_isPrivateIp($_SERVER['REMOTE_ADDR'])) {
			return true;
		}
		
		if (!$this->_isAllowedIp($_SERVER['REMOTE_ADDR'])) {
			return false;
		}
		
		if (KPIW_API_REQUIRE_HTTPS && $_SERVER['HTTPS'] != 'on') {
			return false;
		}
		
		if (strlen(KPIW_API_KEY) > 0 && $_SERVER['PHP_AUTH_PW'] != KPIW_API_KEY) {
			return false;
		}
		
		return true;
	}
	
	private function _isLoopbackIp($ip) {
		if (strpos($ip, '127.0.0.') === 0) {
			return true;
		}
		
		return false;
	}
	
	private function _isPrivateIp($ip) {
		$ipArr = explode('.', $ip);
		if ($ipArr[0] == 10) {
			return true;
		}
		
		if ($ipArr[0] == 172 && $ipArr[1] >= 16) {
			return true;
		}
		
		if ($ipArr[0] == 192 && $ipArr[1] == 168) {
			return true;
		}
		
		return false;
	}
	
	private function _isAllowedIp($ip) {
		return $ip == KPIW_IP;
	}
	
	private function _checkApiMethod($method) {
		return in_array($method, $this->_listApiMethods());
	}
	
	private function _listApiMethods() {
		$methods = get_class_methods('Kpiw_Api');
		$exclude = array('__construct');
		return array_values(array_diff($methods, $exclude));
	}
	
	private function _outputAndExit($data) {
		header('Content-type: application/json; charset=UTF-8');
		echo json_encode($data);
		exit;
	}
}

new Kpiw_Connector();
