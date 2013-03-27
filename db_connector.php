<?php

/** 
 * KPI Watchdog DB connector v1.0
 * 
 * This file creates API for read-only access to your MYSQL database. Using this
 * API connection, you can collect selected aggregated data in your KPI Watchdog
 * profile.
 * 
 * Fill in the information in REQUIRED section bellow and copy this file to any
 * folder on your server accessible from web. You can also rename this file to
 * increase security. For more information about other optional settings, please
 * check README.md file.
 * 
 * @author kpiwatchdog.com <info@kpiwatchdog.com>
 * @copyright 2013 kpiwatchdog.com
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * CONFIG - DB (required)
 */
define('KPIW_DB_TYPE', 'mysql');
define('KPIW_DB_HOST', '');
define('KPIW_DB_NAME', '');
define('KPIW_DB_USERNAME', '');
define('KPIW_DB_PASSWORD', '');
define('KPIW_DB_CHARSET', 'utf8');

/**
 * CONFIG - SECURITY (optional)
 */
define('KPIW_API_ALLOW_LOOPBACK_IP', true); // allow access from localhost for testing purposes
define('KPIW_API_ALLOW_PRIVATE_IP', true);  // allow access from LAN for testing purposes
define('KPIW_API_REQUIRE_HTTPS', false);	// allow access outside LAN only using HTTPS protocol (SSL must be installed on the server)
define('KPIW_API_KEY', '');					// optional API key (use the same key in your DB Connector settings on kpiwatchdog.com)

define('KPIW_IP', '54.246.101.51');			// predifined KPI Watchdog IP (do not change)

//==============================================================================
class Kpiw_Api {
	private $_db;
	private $_tables;
	private $_fields;
	
	public function __construct() {
		$this->_connectDb();
	}
	
	public function listTables() {
		return $this->_listTables();
	}
	
	public function listFields() {
		$table = $_GET['table'];
		$tables = $this->_listTables();
		if (!in_array($table, $tables)) {
			$res = array('error' => 'Unkown table: ' . (!strlen($table) ? 'null' : $table));
			return $res;
		}
		
		return $this->_listFields($table);
	}
	
	/**
	 * GET params:
	 * - table (required)
	 * - field (required)
	 * - agg (required)
	 * - freq
	 * - date_field
	 * - start_date
	 * - end_date
	 * - join_table
	 * - join_field
	 * - cat_field
	 * - join_field_fk
	 */
	public function getData() {
		$required = array('table', 'field', 'agg');
		foreach ($required as $r) if (!isset($_GET[$r])) {
			return array('error' => 'Missing required parameter: ' . $r);
		}
		
		// sanitize GET parameters
		$table = $_GET['table'];
		if (!$this->_checkTable($table)) {
			return array('error' => 'Unknown table: ' . $table);
		}
		
		$field = $_GET['field'];
		if (!$this->_checkField($field, $table)) {
			return array('error' => 'Unknown field: ' . $field . ' in table ' . $table);
		}
		$field = $table . '.' . $field;
		
		$agg = strtolower($_GET['agg']);
		if (!$this->_checkAgg($agg)) {
			return array('error' => 'Unknown aggregation method: ' . $agg);
		}
		$agg = ($agg == 'count-distinct') ? "COUNT(DISTINCT $field)" : (strtoupper($agg) . "($field)");
		
		if (isset($_GET['join_table']) && isset($_GET['join_field']) && isset($_GET['cat_field'])) {
			// sanitize GET parameters
			$joinTable = $_GET['join_table'];
			if (!$this->_checkTable($joinTable)) {
				return array('error' => 'Unknown table: ' . $joinTable);
			}

			$joinField = $_GET['join_field'];
			if (!$this->_checkField($joinField, $joinTable)) {
				return array('error' => 'Unknown field: ' . $joinField . ' in table ' . $joinTable);
			}
			$joinField = $joinTable . '.' . $joinField;

			$joinFieldFk = isset($_GET['join_field_fk']) ? $_GET['join_field_fk'] : $_GET['join_field'];
			if (!$this->_checkField($joinFieldFk, $table)) {
				return array('error' => 'Unknown field: ' . $joinFieldFk . ' in table ' . $table);
			}
			$joinFieldFk = $table . '.' . $joinFieldFk;

			$catField = $_GET['cat_field'];
			if (!$this->_checkField($catField, $joinTable)) {
				return array('error' => 'Unknown field: ' . $catField . ' in table ' . $joinTable);
			}
			$catField = $joinTable . '.' . $catField;
		}

		// STATS WITH DATE FIELD
		$data = array();
		if (isset($_GET['date_field'])) {
			$dateField = $_GET['date_field'];
			if (!$this->_checkField($dateField, $table)) {
				return array('error' => 'Unknown field: ' . $dateField . ' in table ' . $table);
			}
			$dateField = $table . '.' . $dateField;
			
			switch ($_GET['freq']) {
				case 'w':
					$dateField = 'DATE(' . $dateField . ' - INTERVAL (DAYOFWEEK(' . $dateField . ') - 2) DAY)'; break;
				case 'm':
					$dateField = 'DATE_FORMAT(' . $dateField . ', "%Y-%m-01")'; break;
				case 'd':
					$dateField = 'DATE(' . $dateField . ')'; break;
				default:
					return array('error' => 'Unknown frequency: ' . $_GET['freq']);	
			}

			$query = "
				SELECT $dateField, $agg
				FROM $table
				WHERE $dateField >= :start AND $dateField <= :end
				GROUP BY $dateField";
			$stmt = $this->_db->prepare($query);
			$stmt->execute(array('start' => $_GET['start_date'], 'end' => $_GET['end_date']));
			$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

			foreach ($rows as $date => $val) {
				$data[$date]['_total']['val'] = $val;
			}

			// segments
			if (isset($_GET['join_table']) && isset($_GET['join_field']) && isset($_GET['cat_field'])) {
				$query = "
					SELECT $dateField AS dat, $agg AS val, $catField AS cat
					FROM $table
					INNER JOIN $joinTable ON $joinField = $joinFieldFk
					WHERE $dateField >= :start AND $dateField <= :end
					GROUP BY $dateField, $catField";
				$stmt = $this->_db->prepare($query);
				$stmt->execute(array('start' => $_GET['start_date'], 'end' => $_GET['end_date']));
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

				foreach ($rows as $row) {
					$data[$row['dat']][$row['cat']]['val'] = $row['val'];
				}
			}

		// STATS WITHOUT DATE FIELD
		} else {
			if (isset($_GET['start_date']) || isset($_GET['end_date'])) {
				return array('error' => 'Date field not specified.');
			}

			$query = "
				SELECT $agg
				FROM $table";
			$stmt = $this->_db->prepare($query);
			$stmt->execute();
			$row = $stmt->fetch(PDO::FETCH_NUM);

			$date = date('Y-m-d', strtotime('yesterday'));
			$data[$date]['_total']['val'] = $row[0];

			if (isset($_GET['join_table']) && isset($_GET['join_field']) && isset($_GET['cat_field'])) {
				$query = "
					SELECT $catField AS cat, $agg AS val
					FROM $table
					INNER JOIN $joinTable ON $joinField = $joinFieldFk
					GROUP BY $catField";
				$stmt = $this->_db->prepare($query);
				$stmt->execute();
				$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

				foreach ($rows as $cat => $val) {
					$data[$date][$cat]['val'] = $val;
				}
			}
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
	
	private function _listTables() {
		if (!isset($this->_tables)) {
			$stmt = $this->_db->query('SHOW TABLES');
			$this->_tables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
		}
		
		return $this->_tables;
	}
	
	private function _listFields($table) {
		if (!isset($this->_fields[$table])) {
			$stmt = $this->_db->query('DESCRIBE ' . $table);
			$this->_fields[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
		}
		
		return $this->_fields[$table];
	}
	
	private function _checkTable($table) {
		return in_array($table, $this->_listTables());
	}
	
	private function _checkField($field, $table) {
		return in_array($field, $this->_listFields($table));
	}
	
	private function _checkAgg($agg) {
		return in_array($agg, array('count', 'count-distinct', 'sum'));
	}
}

class Kpiw_Connector {
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
		
		if (strlen(KPIW_API_KEY) > 0 && $_SERVER['PHP_AUTH_USER'] != KPIW_API_KEY) {
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
