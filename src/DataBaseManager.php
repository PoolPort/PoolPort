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
	 * Return a row object from ipay_transactions table
	 *
	 * @param int $transactionId
	 *
	 * @return Object|false
	 */
	public function find($transactionId)
	{
		$stmt = $this->dbh->prepare("SELECT * FROM ipay_transactions WHERE id = :id LIMIT 1");
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
		$query = "CREATE TABLE IF NOT EXISTS `ipay_transactions` (
					`id` int(11) NOT NULL,
					`port_id` tinyint(2) NOT NULL,
					`price` decimal(15,2) NOT NULL,
					`ref_id` varchar(255) COLLATE utf8_persian_ci DEFAULT NULL,
					`tracking_code` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`cardNumber` varchar(50) COLLATE utf8_persian_ci DEFAULT NULL,
					`status` tinyint(1) NOT NULL DEFAULT '0',
					`payment_date` int NULL DEFAULT NULL,
					`last_change_date` int NULL DEFAULT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

				ALTER TABLE `ipay_transactions`
				ADD PRIMARY KEY (`id`), ADD KEY `port_id` (`port_id`);

				ALTER TABLE `ipay_transactions`
				MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


				CREATE TABLE IF NOT EXISTS `ipay_status_log` (
					`id` int(11) NOT NULL,
					`transaction_id` int(11) NOT NULL,
					`result_code` varchar(10) COLLATE utf8_persian_ci NOT NULL,
					`result_message` varchar(255) COLLATE utf8_persian_ci NOT NULL,
				    `log_date` int NOT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

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
		$this->dbh = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
	}

}
