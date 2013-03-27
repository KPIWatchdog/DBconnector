[KPI Watchdog](http://www.kpiwatchdog.com/) Database Connector
==============================================================

DB Connector file creates API for read-only access to your MySQL database. Using
this API connection, you can collect selected aggregated data in your KPI 
Watchdog profile.

Installation
------------

Fill in the information in DB CONFIG section of db_connector.php and copy this
file to any folder on your server accessible from web. Optionaly you can edit
SECURITY CONFIG section. You can also rename this file in order to increase
security.

Database settings
-----------------

* **KPIW_DB_TYPE:** currently we only support MySQL databases for this connector
  but MariaDB should also work with MySQL driver.
* **KPIW_DB_HOST:** IP address of your database server.
* **KPIW_DB_NAME:** database name.
* **KPIW_DB_USERNAME:** username of your database user.
* **KPIW_DB_PASSWORD:** password of your database user. 
* **KPIW_DB_CHARSET:** character set used for database connection.

For increased security, we recommend you to create a new read-only user with
restricted acess to desired tables/columns. You can also use Views in MySQL to
prepare stored queries you want to read using this DB Connector and allow your
database user to read these Views only.

Security settings
-----------------

* **ALLOW_LOOPBACK_IP:** allow access from localhost (127.0.0.1) for testing 
  purposes
* **ALLOW_PRIVATE_IP:** allow access from LAN (private network IPs) for testing
  purposes
* **API_REQUIRE_HTTPS:** allow access outside LAN only using HTTPS protocol (SSL
  must be installed on the server)
* **API_KEY:** optional API key (use the same key in your DB Connector settings
  on kpiwatchdog.com)

Security features
-----------------

* You are the only person knowing DB connector URL (security through obscurity).
* Access is restricted only to KPI Watchdog IP address.
* You may require secret API key (kind of password you set on both sides- in KPI
  Watchdog settings and in DB Connector).
* You may require encrypted connection (HTTPS).
* Input data are sanitized.

Contact
-------

If you have any questions, feel free to ask!
info@kpiwatchdog.com

Copyright and License
---------------------

Copyright 2013 kpiwatchdog.com

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
