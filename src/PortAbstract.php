<?php

namespace PoolPort;

abstract class PortAbstract
{
    /**
     * Status code for status field in poolport_transactions table
     */
    const TRANSACTION_INIT = 0;

    /**
     * Status code for status field in poolport_transactions table
     */
    const TRANSACTION_SUCCEED = 1;

    /**
     * Transaction succeed text for put in log
     */
    const TRANSACTION_SUCCEED_TEXT = 'پرداخت با موفقیت انجام شد.';

    /**
     * Status code for status field in poolport_transactions table
     */
    const TRANSACTION_FAILED = 2;

    /**
     * Transaction id
     *
     * @var null|int
     */
    protected $transactionId = null;

    /**
     * Transaction row in database
     */
    protected $transaction = null;

    /**
     * Customer card number
     *
     * @var string
     */
    protected $cardNumber = '';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var DataBaseManager
     */
    protected $db;

    /**
     * Port id
     *
     * @var int
     */
    protected $portId;

    /**
     * Reference id
     *
     * @var string
     */
    protected $refId;

    /**
     * Amount in Rial
     *
     * @var int
     */
    protected $amount;

    /**
     * Tracking code payment
     *
     * @var string
     */
    protected $trackingCode;

    /**
     * Initialize of class
     *
     * @param Config $config
     * @param DataBaseManager $db
     * @param int $portId
     */
    public function __construct(Config $config, DataBaseManager $db, $portId)
    {
        $this->config = $config;
        $this->portId = $portId;
        $this->db = $db;
    }

    /**
     * Get port id, $this->portId
     *
     * @return int
     */
    public function portId()
    {
        return $this->portId;
    }

    /**
     * Return card number
     *
     * @return string
     */
    public function cardNumber()
    {
        return $this->cardNumber;
    }

    /**
     * Return tracking code
     */
    public function trackingCode()
    {
        return $this->trackingCode;
    }

    /**
     * Get transaction id
     *
     * @return int|null
     */
    public function transactionId()
    {
        return $this->transactionId;
    }

    /**
     * Return reference id
     */
    public function refId()
    {
        return $this->refId;
    }

    /**
     * Insert new transaction to poolport_transactions table
     *
     * @return int last inserted id
     */
    protected function newTransaction()
    {
        $dbh = $this->db->getDBH();

        $date = new \DateTime;
        $date = $date->getTimestamp();

        $status = self::TRANSACTION_INIT;

        $stmt = $dbh->prepare("INSERT INTO poolport_transactions (port_id, price, status, last_change_date)
                               VALUES (:port_id, :price, :status, :last_change_date)");
        $stmt->bindParam(':port_id', $this->portId);
        $stmt->bindParam(':price', $this->amount);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':last_change_date', $date);
        $stmt->execute();

        $this->transactionId = $dbh->lastInsertId();

        return $this->transactionId;
    }

    /**
     * Commit transaction
     * Set status field to success status
     *
     * @return bool
     */
    protected function transactionSucceed()
    {
        $dbh = $this->db->getDBH();

        $statement = $dbh->prepare('UPDATE poolport_transactions
                                    SET `status` = :status,
                                        `cardNumber` = :cardNumber,
                                        `last_change_date` = :last_change_date,
                                        `payment_date` = :payment_date,
                                        `tracking_code` = :tracking_code
                                    WHERE id = :transactionId');

        $time = new \DateTime();

        return $statement->execute([
            ':transactionId'    => $this->transactionId,
            ':status'           => self::TRANSACTION_SUCCEED,
            ':last_change_date' => $time->getTimestamp(),
            ':payment_date'     => $time->getTimestamp(),
            ':tracking_code'    => $this->trackingCode,
            ':cardNumber'       => $this->cardNumber
        ]);
    }

    /**
     * Failed transaction
     * Set status field to error status
     *
     * @return bool
     */
    protected function transactionFailed()
    {
        $dbh = $this->db->getDBH();

        $statement = $dbh->prepare('UPDATE poolport_transactions
                                    SET `status` = :status,
                                        `last_change_date` = :change_date
                                    WHERE id = :transactionId');

        $time = new \DateTime();

        return $statement->execute([
            ':transactionId'    => $this->transactionId,
            ':status'           => self::TRANSACTION_FAILED,
            ':change_date'      => $time->getTimestamp()
        ]);
    }

    /**
     * Update transaction refId
     *
     * @return void
     */
    protected function transactionSetRefId()
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("UPDATE poolport_transactions
                               SET ref_id = :ref_id
                               WHERE id = :id");

        $stmt->execute([
            ':ref_id' => $this->refId,
            ':id'     => $this->transactionId
        ]);
    }

    /**
     * New log
     *
     * @param string|int $statusCode
     * @param string $statusMessage
     */
    protected function newLog($statusCode, $statusMessage)
    {
        $dbh = $this->db->getDBH();

        $date = new \DateTime;
        $date = $date->getTimestamp();

        $stmt = $dbh->prepare("INSERT INTO poolport_status_log (transaction_id, result_code, result_message, log_date)
                               VALUES (:transaction_id, :result_code, :result_message, :log_date)");
        $stmt->bindParam(':transaction_id', $this->transactionId);
        $stmt->bindParam(':result_code', $statusCode);
        $stmt->bindParam(':result_message', $statusMessage);
        $stmt->bindParam(':log_date', $date);
        $stmt->execute();
    }

    /**
     * Reset a config per request in poolport.php configuration file
     *
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function setConfig($key, $value)
    {
        $this->config->set($key, $value);

        return $this;
    }

    /**
     * Add query string to a url
     *
     * @param string $url
     * @param array $query
     * @return string
     */
    protected function buildQuery($url, array $query)
    {
        $query = http_build_query($query);

        $questionMark = strpos($url, '?');
        if (!$questionMark)
            return "$url?$query";
        else {
            return substr($url, 0, $questionMark + 1).$query."&".substr($url, $questionMark + 1);
        }
    }
}
