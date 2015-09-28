<?php

namespace IPay;

use PDO;
use IPay\Config;

class DataBaseManager
{
	/**
     * @var IPay\Config
     */
	protected $config;

	/**
	* Keep DB conection
	*
	* @var PDO
	*/
	protected $dbh;

	/**
	 * Initialize class
	 *
	 * @param IPay\Config $config
	 *
	 * @return void
	 */
	public function __construct(Config $config)
	{
		$this->config = $config;

		$this->connect();

		if ($this->config->get('database.create', false)) {
			$this->createTables();
		}
	}

	/**
	 * Return handler database connection
	 *
	 * @return PDO
	 */
	public function getDBH()
	{
		return $this->dbh;
	}

	/**
	 * Create necessary tables for ipay
	 *
	 * @return void
	 */
	protected function createTables()
	{
		$this->createMellatOrdersLog();
	}

	/**
	 * Initialize database connection
	 *
	 * @return void
	 */
	protected function connect()
	{
		$host = $this->config->get('database.host');
		$dbname = $this->config->get('database.dbname');
		$username = $this->config->get('database.username');
		$password = $this->config->get('database.password');
		$this->dbh = new PDO("mysql:host=$host;dbname=$dbname;", $username, $password);
	}

	/**
	 * Create mellat_orders_log table
	 *
	 * @return void
	 */
	protected function createMellatOrdersLog()
	{
		$query = "CREATE TABLE IF NOT EXISTS `mellat_orders_log` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`ref_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
					`sale_order_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
					`sale_refrences_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
					`additional_data` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
					`message` varchar(250) COLLATE utf8_unicode_ci NOT NULL,
					`timestamp` datetime NOT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;";

		$this->dbh->exec($query);
	}
}
