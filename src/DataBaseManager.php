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
	 * Create transactions and status log tables
	 *
	 * @return void
	 */
	
	protected function createTables()
	{
		$query = "CREATE TABLE IF NOT EXISTS `ipay_transactions` (
					`id` int(11) NOT NULL,
					`bank_id` tinyint(2) NOT NULL,
					`price` decimal(15,2) NOT NULL,
					`ref_id` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`tracking_code` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`status` tinyint(1) NOT NULL DEFAULT '0',
					`payment_date` int NOT NULL ,
					`last_change_date` int NULL DEFAULT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_persian_ci;

				ALTER TABLE `ipay_transactions`
				ADD PRIMARY KEY (`id`), ADD KEY `order_id` (`order_id`), ADD KEY `bank_id` (`bank_id`);

				ALTER TABLE `ipay_transactions`
				MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


				CREATE TABLE IF NOT EXISTS `ipay_status_log` (
					`id` int(11) NOT NULL,
					`transaction_id` int(11) NOT NULL,
					`result_code` varchar(10) COLLATE utf8_persian_ci NOT NULL,
					`result_message` varchar(255) COLLATE utf8_persian_ci NOT NULL,
				    `log_date` int NOT NULL DEFAULT 
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_persian_ci;

				ALTER TABLE `ipay_status_log`
				ADD PRIMARY KEY (`id`), ADD KEY `transaction_id` (`transaction_id`);

				ALTER TABLE `ipay_status_log`
				MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;";

		$this->dbh->exec($query);
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

}
