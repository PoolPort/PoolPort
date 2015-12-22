<?php

namespace PoolPort;

use PDO;
use PoolPort\Config;

class DataBaseManager
{
	/**
     * @var PoolPort\Config
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
	 * @param PoolPort\Config $config
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
	 * Return a row object from poolport_transactions table
	 *
	 * @param int $transactionId
	 *
	 * @return Object|false
	 */
	public function find($transactionId)
	{
		$stmt = $this->dbh->prepare("SELECT * FROM poolport_transactions WHERE id = :id LIMIT 1");
		$stmt->bindParam(':id', $transactionId);
		$stmt->execute();

		return $stmt->fetch(PDO::FETCH_OBJ);
	}

	/**
	 * Create transactions and status log tables
	 *
	 * @return void
	 */

	protected function createTables()
	{
		$query = "CREATE TABLE IF NOT EXISTS `poolport_transactions` (
					`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`port_id` tinyint(2) UNSIGNED NOT NULL,
					`price` decimal(15,2) NOT NULL,
					`ref_id` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`tracking_code` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`cardNumber` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`status` tinyint(1) NOT NULL DEFAULT '0',
					`payment_date` int NULL DEFAULT NULL,
					`last_change_date` int NOT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


				CREATE TABLE IF NOT EXISTS `poolport_status_log` (
					`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`transaction_id` int(11) UNSIGNED NOT NULL,
					`result_code` varchar(10) COLLATE utf8_persian_ci NULL DEFAULT NULL,
					`result_message` varchar(255) COLLATE utf8_persian_ci NULL DEFAULT NULL,
				    `log_date` int NOT NULL,
					PRIMARY KEY (`id`),
					INDEX (`transaction_id`),
					CONSTRAINT `fk_transaction_id` FOREIGN KEY (`transaction_id`) REFERENCES `poolport_transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";

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
		$this->dbh = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
	}

}
