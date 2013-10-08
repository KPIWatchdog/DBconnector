<?php

/** 
 * KPI Watchdog DB connector v2.0
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
define('KPIW_DB_PORT', 3306);
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

define('KPIW_IP', '54.246.101.51');			// predefined KPI Watchdog IP (do not change)

//==============================================================================
class Kpiw_Api {
	private $_db;
	private $_tables;
	private $_fields;
	
	const VERSION = '2.0';
	
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
	 * - tbl (required)
	 * - fld (required)
	 * - agg (required)
	 * - date_fld
	 * - freq (required if date_fld set)
	 * - start_date
	 * - end_date
	 * - cond[n][tbl]
	 * - cond[n][fld]
	 * - cond[n][cmp]
	 * - cond[n][val]
	 * - seg_fld
	 * - seg_tbl
	 * - join[n][tbl1]
	 * - join[n][fld1]
	 * - join[n][tbl2]
	 * - join[n][fld2]
	 */
	public function getData() {
		$required = array('tbl', 'fld', 'agg');
		foreach ($required as $r) if (!isset($_GET[$r])) {
			return array('error' => 'Missing required parameter: ' . $r);
		}
		
		// sanitize GET parameters
		if (!$this->_checkTable($_GET['tbl'])) {
			return array('error' => 'Unknown table: ' . $_GET['tbl']);
		}
		
		if (!$this->_checkField($_GET['fld'], $_GET['tbl'])) {
			return array('error' => 'Unknown field: ' . $_GET['fld'] . ' in table ' . $_GET['tbl']);
		}
		
		$tables = array($_GET['tbl']);
		$metricTable = $_GET['tbl'];
		$metricField = $metricTable . '.' . $_GET['fld'];
		
		$agg = strtolower($_GET['agg']);
		if (!$this->_checkAgg($agg)) {
			return array('error' => 'Unknown aggregation method: ' . $agg);
		}
		$agg = ($agg == 'count-distinct') ? "COUNT(DISTINCT $metricField)" : (strtoupper($agg) . "($metricField)");
		
		$where = array(1);
		$bind = array();
		$dateField = '\'' . date('Y-m-d') . '\'';
		if (isset($_GET['date_fld'])) {
			if (!$this->_checkTable($_GET['date_tbl'])) {
				return array('error' => 'Unknown table: ' . $_GET['date_tbl']);
			}
			
			if (!$this->_checkField($_GET['date_fld'], $_GET['date_tbl'])) {
				return array('error' => 'Unknown field: ' . $_GET['date_fld'] . ' in table ' . $_GET['date_tbl']);
			}
			
			$tables[] = $_GET['date_tbl'];
			$dateField = $_GET['date_tbl'] . '.' . $_GET['date_fld'];
			switch ($_GET['freq']) {
				case 'd': $dateField = 'DATE(' . $dateField . ')'; break;
				case 'w': $dateField = 'DATE(' . $dateField . ' - INTERVAL (DAYOFWEEK(' . $dateField . ') - 2) DAY)'; break;
				case 'm': $dateField = 'DATE_FORMAT(' . $dateField . ', "%Y-%m-01")'; break;
				default: return array('error' => 'Unknown frequency: ' . $_GET['freq']);	
			}

			$where[] = "$dateField >= :start AND $dateField <= :end";
			$bind['start'] = $_GET['start_date'];
			$bind['end'] = $_GET['end_date'];
		}
		
		if (isset($_GET['cond'])) {
			$cond = is_array($_GET['cond']) ? $_GET['cond'] : array($_GET['cond']);
			foreach ($cond as $c) {
				if (!$this->_checkTable($c['tbl'])) {
					return array('error' => 'Unknown table: ' . $c['tbl']);
				}
				
				if (!$this->_checkField($c['fld'], $c['tbl'])) {
					return array('error' => 'Unknown field: ' . $c['fld'] . ' in table ' . $c['tbl']);
				}
				
				if (!$this->_checkOperator($c['cmp'])) {
					return array('error' => 'Unknown operator in filter: ' . $c['cmp']);
				}
				
				$tables[] = $c['tbl'];
				$where[] = $c['tbl'] . '.' . $c['fld'] . ' ' . $c['cmp'] . $this->_db->quote($c['val']);
			}
		}
		$tables = array_unique($tables);
		$where = implode(' AND ', $where);
		
		$segTables = $tables;
		if (isset($_GET['seg_fld'])) {
			if (!$this->_checkTable($_GET['seg_tbl'])) {
				return array('error' => 'Unknown table: ' . $_GET['seg_tbl']);
			}
			
			if (!$this->_checkField($_GET['seg_fld'], $_GET['seg_tbl'])) {
				return array('error' => 'Unknown field: ' . $_GET['seg_fld'] . ' in table ' . $_GET['seg_tbl']);
			}
			
			$segTables[] = $_GET['seg_tbl'];
			$segTables = array_unique($segTables);
			$segField = $_GET['seg_tbl'] . '.' . $_GET['seg_fld'];
		}
		
		if (count($segTables) > 1) {
			$joinTables = $connections = array();
			if (isset($_GET['join'])) foreach ($_GET['join'] as $j) {
				if (!$this->_checkTable($j['tbl1'])) {
					return array('error' => 'Unknown table: ' . $j['tbl1']);
				}

				if (!$this->_checkField($j['fld1'], $j['tbl1'])) {
					return array('error' => 'Unknown field: ' . $j['fld1'] . ' in table ' . $j['tbl1']);
				}

				if (!$this->_checkTable($j['tbl2'])) {
					return array('error' => 'Unknown table: ' . $j['tbl2']);
				}

				if (!$this->_checkField($j['fld2'], $j['tbl2'])) {
					return array('error' => 'Unknown field: ' . $j['fld2'] . ' in table ' . $j['tbl2']);
				}
				
				$joinTables[] = $j['tbl1'];
				$joinTables[] = $j['tbl2'];
				$connections[$j['tbl1']][$j['tbl2']] = $connections[$j['tbl2']][$j['tbl1']] = $j['tbl1'] . '.' . $j['fld1'] . ' = ' . $j['tbl2'] . '.' . $j['fld2'];
			}
			
			$diff = array_diff($segTables, $joinTables);
			if (count($diff) > 0) {
				return array('error' => 'Missing joins for table(s): ' . implode(', ', $diff));
			}
		}
		
		// get data without segments
		try {
			$join = $this->_getJoin($metricTable, $tables, $connections);
		} catch (Exception $e) {
			return array('error' => $e->getMessage());
		}
		
		$data = array();
		$query = "
			SELECT $dateField, $agg
			FROM $metricTable
			$join
			WHERE $where
			GROUP BY $dateField";
		$stmt = $this->_db->prepare($query);
		$stmt->execute($bind);
		$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

		foreach ($rows as $date => $val) {
			$data[$date]['_total']['val'] = $val;
		}
		
		// get data with segments
		if (isset($_GET['seg_fld'])) {
			try {
				$join = $this->_getJoin($metricTable, $segTables, $connections);
			} catch (Exception $e) {
				return array('error' => $e->getMessage());
			}
			
			$query = "
				SELECT $dateField AS dat, $agg AS val, $segField AS seg
				FROM $metricTable
				$join
				WHERE $where
				GROUP BY $dateField, $segField";
			$stmt = $this->_db->prepare($query);
			$stmt->execute($bind);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rows as $row) {
				$data[$row['dat']][$row['seg']]['val'] = $row['val'];
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
		
		$dsn = KPIW_DB_TYPE . ':host=' . KPIW_DB_HOST . ';port=' . KPIW_DB_PORT . ';dbname=' . KPIW_DB_NAME;
		$this->_db = new PDO($dsn, KPIW_DB_USERNAME, KPIW_DB_PASSWORD, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . KPIW_DB_CHARSET
		));
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
	
	private function _checkOperator($op) {
		return in_array($op, array('=', '>', '>=', '>', '>=', '!=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'));
	}
	
	private function _getJoin($fromTable, $tables, $connections) {
		if (count($tables) < 2 || empty($connections)) {
			return '';
		}
		
		$queue = array($fromTable);
		$visited = array($fromTable);
		$prev = array();
		while (!empty($queue)) {
			$t = array_shift($queue);
			foreach ($connections[$t] as $tNext => $con) {
				if (!in_array($tNext, $visited)) {
					$prev[$tNext] = $t;
					$queue[] = $visited[] = $tNext;
				}
			}
		}
		
		$tables = array_diff($tables, array($fromTable));
		$joinTables = array();
		foreach ($tables as $t) {
			$dist = 0;
			while ($t != $fromTable) {
				$joinTables[$t] = max($dist, $joinTables[$t]);
				
				if (!isset($prev[$t])) {
					throw new Exception('Missing join for table: ' . $t);
				}
				
				$t = $prev[$t];
				$dist++;
			}
		}
		arsort($joinTables);
		
		$join = '';
		foreach ($joinTables as $t => $dist) {
			$join .= 'JOIN ' . $t . ' ON ' . $connections[$t][$prev[$t]] . ' ';
		}
		return $join;
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
		
		if ($method == 'getVersion') {
			$data = array('version' => Kpiw_Api::VERSION);
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
