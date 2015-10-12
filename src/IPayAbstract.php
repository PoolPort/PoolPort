<?php

namespace IPay;

abstract class IPayAbstract
{
    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_INIT = 0;

    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_SUCCEED = 1;

    /**
     * Transaction succeed text for put in log
     */
    const TRANSACTION_SUCCEED_TEXT = 'پرداخت با موفقیت انجام شد.';

    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_FAILED = 2;

    /**
     * transaction id
     *
     * @var null|int
     */
    protected $transactionId = null;

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
     * @var int
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

    /**
     * Return card number
     *
     * @return string
     */
    public function cardNumber()
    {
        return $this->cardNumber;
    }

    public function trackingCode()
    {
        return $this->trackingCode;
    }

    /**
     * get transaction id
     *
     * @return int|null
     */
    public function transactionId()
    {
        return $this->transactionId;
    }

    public function refId()
    {
        return $this->refId;
    }

    /**
     * Insert new transaction to ipay_transactions table
     *
     * @return int last inserted id
     */
    protected function newTransaction()
    {
        $dbh = $this->db->getDBH();

        $date = new \DateTime;
        $status = self::TRANSACTION_INIT;

        $stmt = $dbh->prepare("INSERT INTO ipay_transactions (port_id, price, status, last_change_date)
                               VALUES (:port_id, :price, :status, :last_change_date)");
        $stmt->bindParam(':port_id', $this->portId);
        $stmt->bindParam(':price', $this->amount);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':last_change_date', $date->getTimestamp());
        $stmt->execute();

        $this->transactionId = $dbh->lastInsertId();

        return $this->transactionId;
    }

    /**
     * commit transaction
     * set status field to success status
     *
     * @return bool
     */
    protected function commitTransaction()
    {
        $dbh = $this->db->getDBH();

        $statement = $dbh->prepare('UPDATE ipay_transactions
                                    SET `status` = :status,
                                        `cardNumber` = :cardNumber,
                                        `last_change_date` = :change_date,
                                        `tracking_code` = :tracking_code
                                    WHERE id = :transactionId');

        $time = new \DateTime();

        return $statement->execute([
            ':transactionId'    => $this->transactionId,
            ':status'           => self::TRANSACTION_SUCCEED,
            ':change_date'      => $time->getTimestamp(),
            ':tracking_code'    => $this->trackingCode,
            ':cardNumber'       => $this->cardNumber
        ]);
    }

    /**
     * failed transaction
     * set status field to error status
     *
     * @return bool
     */
    protected function failedTransaction()
    {
        $dbh = $this->db->getDBH();

        $statement = $dbh->prepare('UPDATE ipay_transactions
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
     * set log
     *
     * @param string|int $statusCode
     * @param string $statusMessage
     */
    protected function setLog($statusCode,$statusMessage)
    {

    }


}
