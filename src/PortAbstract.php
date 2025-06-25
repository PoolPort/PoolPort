<?php

namespace PoolPort;

use Ramsey\Uuid\Uuid;

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
     * Status code for status field in poolport_transactions table
     */
    const TRANSACTION_PENDING = 3;

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
     * Return result of payment
     * If result is done, return true, otherwise throws an related exception
     *
     * This method must be implements in child class
     *
     * @param object $transaction row of transaction in database
     *
     * @return $this
     */
    public function verify($transaction)
    {
        $this->transaction = $transaction;
        $this->transactionId = intval($transaction->id);
        $this->amount = intval($transaction->price);
        $this->refId = $transaction->ref_id;
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
     * Pending transaction
     * Set status pending to error status
     *
     * @param $transactionId int|null If this param not send, use class transactionId parameter
     *
     * @return bool
     */
    public function transactionPending($transactionId = null)
    {
        $transactionId = $transactionId == null ? $this->transactionId : $transactionId;

        $dbh = $this->db->getDBH();

        $statement = $dbh->prepare('UPDATE poolport_transactions
                                    SET `status` = :status,
                                        `last_change_date` = :change_date
                                    WHERE id = :transactionId');

        $time = new \DateTime();

        return $statement->execute([
            ':transactionId'    => $transactionId,
            ':status'           => self::TRANSACTION_PENDING,
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
     * Set all ports call back url
     *
     * @param string $url
     *
     * @return $this
     */
    public function setGlobalCallbackUrl($url)
    {
        $this->config->set('zarinpal.callback-url', $url);
        $this->config->set('mellat.callback-url', $url);
        $this->config->set('payline.callback-url', $url);
        $this->config->set('sadad.callback-url', $url);
        $this->config->set('jahanpay.callback-url', $url);
        $this->config->set('parsian.callback-url', $url);
        $this->config->set('pasargad.callback-url', $url);
        $this->config->set('saderat.callback-url', $url);
        $this->config->set('irankish.callback-url', $url);
        $this->config->set('simulator.callback-url', $url);
        $this->config->set('saman.callback-url', $url);
        $this->config->set('pay.callback-url', $url);
        $this->config->set('jibit.callback-url', $url);
        $this->config->set('ap.callback-url', $url);
        $this->config->set('bitpay.callback-url', $url);
        $this->config->set('idpay.callback-url', $url);
        $this->config->set('payping.callback-url', $url);
        $this->config->set('vandar.callback-url', $url);
        $this->config->set('pna.callback-url', $url);
        $this->config->set('azki.callback-url', $url);
        $this->config->set('apsan.callback-url', $url);
        $this->config->set('dara.callback-url', $url);
        $this->config->set('keepa.callback-url', $url);
        $this->config->set('bazaarpay.callback-url', $url);
        $this->config->set('tara.callback-url', $url);
        $this->config->set('sib.callback-url', $url);
        $this->config->set('digipay.callback-url', $url);
        $this->config->set('zibal.callback-url', $url);
        $this->config->set('mellatStaff.callback-url', $url);

        return $this;
    }

    /**
     * Set user-mobile config for all ports that support this feature
     *
     * @param string $mobile In format 09xxxxxxxxx
     *
     * @return $this
     */
    public function setGlobalUserMobile($mobile)
    {
        // Convert 09xxxxxxxxx format to 989xxxxxxxxx format for spesific ports
        $withPrefixFormat = '989'.substr($mobile, 2);

        $this->config->set('mellat.user-mobile', $withPrefixFormat);
        $this->config->set('irankish.user-mobile', $withPrefixFormat);
        $this->config->set('sadad.user-mobile', $mobile);
        $this->config->set('jibit.user-mobile', $mobile);
        $this->config->set('ap.user-mobile', $mobile);
        $this->config->set('idpay.user-mobile', $mobile);
        $this->config->set('payping.user-mobile', $mobile);
        $this->config->set('zarinpal.user-mobile', $mobile);
        $this->config->set('vandar.user-mobile', $mobile);
        $this->config->set('saman.user-mobile', $mobile);
        $this->config->set('parsian.user-mobile', $mobile);
        $this->config->set('pna.user-mobile', $mobile);
        $this->config->set('azki.user-mobile', $mobile);
        $this->config->set('apsan.user-mobile', $mobile);
        $this->config->set('dara.user-mobile', $mobile);
        $this->config->set('keepa.user-mobile', $mobile);
        $this->config->set('bazaarpay.user-mobile', $mobile);
        $this->config->set('tara.user-mobile', $mobile);
        $this->config->set('sib.user-mobile', $mobile);
        $this->config->set('digipay.user-mobile', $mobile);
        $this->config->set('zibal.user-mobile', $mobile);
        $this->config->set('pasargad.user-mobile', $mobile);
        $this->config->set('mellatStaff.user-mobile', $mobile);

        return $this;
    }

    /**
     * @var string $url
     *
     * @return string redirect url
     */
    protected function buildRedirectUrl($url)
    {
        $uniqueId = $this->createAUniqueId();
        $uniqueKey = $this->createAUniqueKey();
        $verifyKey = $this->createVerifyKey($uniqueId, $uniqueKey);

        $this->setUniqeid($uniqueId, $uniqueKey, $verifyKey, $this->transactionId());

        $query = http_build_query(["u" => $uniqueId]);

        $questionMark = strpos($url, '?');
        if (!$questionMark)
            return "$url?$query";
        else {
            return substr($url, 0, $questionMark + 1).$query."&".substr($url, $questionMark + 1);
        }
    }

    private function createAUniqueId()
    {
        $dbh = $this->db->getDBH();

        do {
            $uuid = Uuid::uuid4();

            $stmt = $dbh->prepare("SELECT `id` from `poolport_transactions`
                            WHERE `unique_id` = :uuid
                            LIMIT 1
            ");
            $stmt->bindParam(':uuid', $uuid);
            $stmt->execute();

            $found = count($stmt->fetchAll()) > 0;
        } while($found);

        return $uuid;
    }

    private function createAUniqueKey()
    {
        return uniqid("", true);
    }

    private function createVerifyKey($uniqueId, $uniqueKey)
    {
        return password_hash($uniqueId.$uniqueKey, PASSWORD_BCRYPT);
    }

    public static function checkVerifyKey($transaction)
    {
        return password_verify($transaction->unique_id.$transaction->unique_key, $transaction->verify_key);
    }

    protected function setUniqeid($uniqeid, $uniqueKey, $verifyKey, $transactionId)
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("UPDATE poolport_transactions
                               SET unique_id = :unique_id,
                                 unique_key = :unique_key,
                                 verify_key = :verify_key
                               WHERE id = :id");

        $stmt->execute([
            ':unique_id' => $uniqeid,
            ':unique_key' => $uniqueKey,
            ':verify_key' => $verifyKey,
            ':id'     => $transactionId
        ]);
    }

    protected function setMeta(array $data)
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("SELECT meta FROM poolport_transactions WHERE id = :id");

        $stmt->execute([
            ':id' => $this->transactionId()
        ]);

        $currentMeta = $stmt->fetchColumn();
        $currentMeta = $currentMeta ? json_decode($currentMeta, true) : [];
        $newMeta = array_merge($currentMeta, $data);
        $newMeta = json_encode($newMeta, JSON_UNESCAPED_UNICODE);

        $stmt = $dbh->prepare("UPDATE poolport_transactions SET meta = :meta WHERE id = :id");

        $stmt->execute([
            ':meta' => $newMeta,
            ':id'   => $this->transactionId(),
        ]);
    }

    protected function getMeta($key = null)
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("SELECT meta FROM poolport_transactions WHERE id = :id");

        $stmt->execute([
            ':id' => $this->transactionId()
        ]);

        $meta = $stmt->fetchColumn();
        $meta = $meta ? json_decode($meta, true) : [];

        if ($key !== null) {
            return isset($meta[$key]) ? $meta[$key] : null;
        }

        return $meta;
    }
}
