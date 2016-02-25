<?php

/**
 * CONFIG - SECURITY
 */
define('KPIW_API_ALLOW_LOOPBACK_IP', true); // allow access from localhost for testing purposes
define('KPIW_API_ALLOW_PRIVATE_IP', true);  // allow access from LAN for testing purposes
define('KPIW_API_REQUIRE_HTTPS', false); // allow access outside LAN only using HTTPS protocol (SSL must be installed on the server)
define('KPIW_API_KEY', '');     // optional API key (use the same key in your DB Connector settings on kpiwatchdog.com)

define('KPIW_IP', '54.246.101.51');   // predefined KPI Watchdog IP (do not change)

require_once('includes/functions.php');
require_once('includes/helpers/short.php');

/**
 * Api class <- implement API methods here
 */
class Kpiw_Api
{
    public $_db;
    
    public function getData()
    {
        $this->_checkRequiredFields(array('list_id'));
        $listId = short($_GET['list_id'], true);
        if (!$listId) {
            throw new Exception('Incorrect ListID');
        }

        $this->_connectDb();

        $query = 'SELECT * ';
        $query.= 'FROM campaigns ';
        $query.= 'WHERE sent != "" ';
        $query.= 'AND CONCAT(",", to_send_lists, ",") LIKE CONCAT("%,", ?, ",%") ';
        $query.= 'ORDER BY id DESC ';
        $query.= 'LIMIT 1';
        $bind = array($listId);

        $stmt = $this->_db->prepare($query);
        $stmt->execute($bind);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = array();

        $segments = array(
            '_total' => array('val' => 0),
            //'_total' => array('val' => (int) $campaign['recipients']),
            'Opened' => array('val' => 0),
            'Clicked' => array('val' => 0),
            'Bounced' => array('val' => 0),
            'Unsubscribed' => array('val' => 0),
            'Marked as spam' => array('val' => 0),
        );

        // OPENED
        if ($campaign['opens'] != '') {
            $opens = explode(',', $campaign['opens']);
            $subscribers = array();
            foreach ($opens as $subscriber) {
                $subscriberArr = explode(':', $subscriber);
                $subscribers[reset($subscriberArr)]++;
            }
            $q0 = 'SELECT COUNT(*) ';
            $q0.= 'FROM subscribers ';
            $q0.= 'WHERE list = ? ';
            $q0.= 'AND id IN (' . implode(',', array_keys($subscribers)) . ')';
            $s0 = $this->_db->prepare($q0);
            $s0->execute(array($listId));

            $segments['Opened']['val'] = (int) $s0->fetchColumn();
        }
        
        // CLICKED
        // get_click_percentage();
        $q1 = 'SELECT * FROM links WHERE campaign_id = ?';
        $s1 = $this->_db->prepare($q1);
        $s1->execute(array($campaign['id']));
        $links = $s1->fetchAll(PDO::FETCH_ASSOC);
        $clicks = array();
        foreach ($links as $l) {
            if ($l['clicks'] != '') {
                $clicks = array_merge($clicks, explode(',', $l['clicks']));
            }
        }

        if (!empty($clicks)) {
            $q2 = 'SELECT COUNT(*) ';
            $q2.= 'FROM subscribers ';
            $q2.= 'WHERE list = ? ';
            $q2.= 'AND id IN (' . implode(',', array_unique($clicks)) . ')';
            $s2 = $this->_db->prepare($q2);
            $s2->execute(array($listId));

            $segments['Clicked']['val'] = (int) $s2->fetchColumn();
        }

        // TOTAL
        // BOUNCED -> get_bounced();
        // UNSUBSCRIBED -> get_unsubscribes();
        // MARKED AS SPAM -> get_complaints();

        $q3 = 'SELECT COUNT(*), SUM(bounced = 1), SUM(unsubscribed = 1), SUM(complaint = 1) ';
        $q3.= 'FROM subscribers ';
        $q3.= 'WHERE last_campaign = ? ';
        $q3.= 'AND list = ?';
        $s3 = $this->_db->prepare($q3);
        $s3->execute(array($campaign['id'], $listId));
        $stats = $s3->fetch(PDO::FETCH_NUM);

        $segments['_total']['val'] = (int) $stats[0];
        $segments['Bounced']['val'] = (int) $stats[1];
        $segments['Unsubscribed']['val'] = (int) $stats[2];
        $segments['Marked as spam']['val'] = (int) $stats[3];

        // $defaultTimezone = '';
        // date_default_timezone_set($campaign['timezone'] ? : $defaultTimezone);
        $data[date('Y-m-d', $campaign['sent'])] = $segments;

        return $data;
    }

    ////////////////////////////////////////////////////////////////////////////
    private function _connectDb()
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new Exception('PDO extension not installed.');
        }

        global $dbHost;
        global $dbUser;
        global $dbPass;
        global $dbName;
        global $dbPort;

        $dsn = 'mysql:' . 'host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName;
        $this->_db = new PDO($dsn, $dbUser, $dbPass, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
    }

    private function _checkRequiredFields($required)
    {
        foreach ($required as $r) {
            if (!isset($_GET[$r])) {
                throw new Exception('Missing field: ' . $r);
            }
        }
    }

}

//==============================================================================

/**
 * Connector class - not nessecery to modify
 */
class Kpiw_Connector
{

    private $_error;

    public function __construct()
    {
        if (!$this->_checkRequestMethod()) {
            header("HTTP/1.1 501 Not Implemented");
            $this->_outputAndExit(array('error' => 'Unsupported request method: ' . $_SERVER['REQUEST_METHOD']));
        }

        if (!$this->_checkPermisions()) {
            header("HTTP/1.1 403 Access denied");
            $this->_outputAndExit(array('error' => 'Access denied. ' . $this->_error));
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

    private function _checkRequestMethod()
    {
        return $_SERVER['REQUEST_METHOD'] == 'GET';
    }

    private function _checkPermisions()
    {
        if (KPIW_API_ALLOW_LOOPBACK_IP && $this->_isLoopbackIp($_SERVER['REMOTE_ADDR'])) {
            return true;
        }

        if (KPIW_API_ALLOW_PRIVATE_IP && $this->_isPrivateIp($_SERVER['REMOTE_ADDR'])) {
            return true;
        }

        if (!$this->_isAllowedIp($_SERVER['REMOTE_ADDR'])) {
            $this->_error = 'IP not allowed: ' . $_SERVER['REMOTE_ADDR'] . '.';
            return false;
        }

        if (KPIW_API_REQUIRE_HTTPS && $_SERVER['HTTPS'] != 'on') {
            $this->_error = 'HTTPS required.';
            return false;
        }

        if (strlen(KPIW_API_KEY) > 0 && $_SERVER['PHP_AUTH_USER'] != KPIW_API_KEY) {
            $this->_error = 'API key mismatch.';
            return false;
        }

        return true;
    }

    private function _isLoopbackIp($ip)
    {
        if (strpos($ip, '127.0.0.') === 0) {
            return true;
        }

        return false;
    }

    private function _isPrivateIp($ip)
    {
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

    private function _isAllowedIp($ip)
    {
        return $ip == KPIW_IP;
    }

    private function _checkApiMethod($method)
    {
        return in_array($method, $this->_listApiMethods());
    }

    private function _listApiMethods()
    {
        $methods = get_class_methods('Kpiw_Api');
        $exclude = array('__construct');
        return array_values(array_diff($methods, $exclude));
    }

    private function _outputAndExit($data)
    {
        header('Content-type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

}

new Kpiw_Connector();

