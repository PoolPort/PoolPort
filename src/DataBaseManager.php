<?php

namespace PoolPort;

use PDO;
use PoolPort\Config;

class DataBaseManager
{
	/**
     * @var Config
     */
	protected $config;

	/**
	* Keep DB connection
	*
	* @var PDO
	*/
	protected $dbh;

    /**
     * Initialize class
     *
     * @param Config $config
     *
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
	public function find($uniqeId, $lock = false)
	{
		$l = "";
		if ($lock) {
			$l = "FOR UPDATE";
		}

		$stmt = $this->dbh->prepare("SELECT * FROM poolport_transactions WHERE unique_id = :unique_id LIMIT 1 $l");
		$stmt->bindParam(':unique_id', $uniqeId);

		$stmt->execute();

		return $stmt->fetch(PDO::FETCH_OBJ);
	}

	/**
	 * Start mysql transaction
	 *
	 * @return void
	 */
	public function beginTransaction()
	{
		$this->dbh->beginTransaction();
	}

	/**
	 * Commit mysql transaction
	 *
	 * @return void
	 */
	public function commit()
	{
		$this->dbh->commit();
	}

	/**
	 * Roll back mysql transaction
	 *
	 * @return void
	 */
	public function rollBack()
	{
		$this->dbh->rollBack();
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
					`unique_id` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`unique_key` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`verify_key` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`port_id` tinyint(2) UNSIGNED NOT NULL,
					`price` decimal(15,2) NOT NULL,
					`ref_id` varchar(1024) COLLATE utf8_persian_ci DEFAULT NULL,
					`tracking_code` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`cardNumber` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`status` tinyint(1) NOT NULL DEFAULT '0',
					`payment_date` int NULL DEFAULT NULL,
					`last_change_date` int NOT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

				ALTER TABLE `poolport_transactions`
					ADD KEY `unique_id` (`unique_id`);

				CREATE TABLE IF NOT EXISTS `poolport_status_log` (
					`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`transaction_id` int(11) UNSIGNED NOT NULL,
					`result_code` varchar(255) COLLATE utf8_persian_ci NULL DEFAULT NULL,
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
