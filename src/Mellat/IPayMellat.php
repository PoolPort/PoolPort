<?php

namespace IPay\Mellat;

use DateTime;
use SoapClient;
use IPay\Config;
use IPay\IPayAbstract;
use IPay\IPayInterface;
use IPay\DataBaseManager;
use IPay\Exceptions\RetryException;

class IPayMellat extends IPayAbstract implements IPayInterface
{
    /**
     * Determine request passes
     *
     * @var bool
     */
    protected $requestPass = false;

    /**
     * Refer id
     *
     * @var string
     */
    protected $refId;

    /**
     * Keep ResCode of bpPayRequest response
     *
     * @var int
     */
    protected $payRequestResCode;

    /**
     * Keep saleOrderId
     *
     * @var int
     */
    protected $saleOrderId;

    /**
     * Sale refrence id
     *
     * @var int
     */
    protected $saleReferenceId;

    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';


    /**
     * Additional data for send to port
     *
     * @var string
     */
    protected $additionalData;

    /**
     * @var int
     */
    protected $orderId;

    /**
     * @inheritdoc
     */
    public function __construct(Config $config, DataBaseManager $db, $portId) {
        parent::__construct($config,$db,$portId);


        $this->username = $this->config->get('mellat.username');
        $this->password = $this->config->get('mellat.password');
        $this->termId = $this->config->get('mellat.terminalId');
    }

    /**
     * This method use for set price in Rial.
     *
     * @param int $amount in Rial
     *
     * @return $this
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Some of the ports can be send additional data to port server.
     * This method for set this additional data.
     *
     * @param array $data
     *
     * @return $this
     */
    public function with(array $data = array())
    {
        if (isset($data['additionalData']))
            $this->additionalData = $data['additionalData'];

        return $this;
    }

    /**
     * This method use for done everything that necessary before redirect to port.
     *
     * @return $this
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * This method use for redirect to port
     *
     * @return mixed
     */
    public function redirect()
    {
        $refId = $this->refId;
        require 'IPayMellatRedirector.php';
    }

    /**
     * Return result of payment
     * If result is done, return $this, otherwise throws an related exception
     *
     * @return $this
     */
    public function verify()
    {
        $this->userPayment();
        $this->verifyPayment();
        $this->settleRequest();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws IPayMellatException
     */
    protected function sendPayRequest()
    {
        $soap = new SoapClient($this->serverUrl);
        $dateTime = new DateTime();

        $this->orderId = $this->newTransaction();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $this->orderId,
            'amount' => $this->amount,
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'additionalData' => $this->additionalData,
            'callBackUrl' => $this->config->get('mellat.callback-url'),
            'payerId' => 0,
        );

        $response = $soap->bpPayRequest($fields);

        $response = explode(',', $response->return);

        if ($response[0] != '0') {
            $this->newLog($this->orderId, $response[0], IPayMellatException::$errors[$response[0]]);
            throw new IPayMellatException($response[0]);
        }
        $this->refId = $response[1];
        $this->updateTransactionRefId($this->orderId);
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws IPayMellatException
     */
    protected function userPayment()
    {
        $this->refId = @$_POST['RefId'];
        $this->orderId = @$_POST['SaleOrderId'];
        $this->trackingCode = @$_POST['SaleReferenceId'];
        $this->cardNumber = @$_POST['CardHolderPan'];
        $payRequestResCode = (int) @$_POST['ResCode'];

        // Check before not submitted
        $transaction = $this->db->find($this->orderId);
        if ($transaction->status == self::TRANSACTION_SUCCEED) {
            throw new RetryException;
        }

        if ($payRequestResCode != 0) {
            $this->newLog($this->orderId, $payRequestResCode, IPayMellatException::$errors[$payRequestResCode]);
            $this->updateTransactionFailed($this->orderId, self::TRANSACTION_FAILED);
            throw new IPayMellatException($payRequestResCode);
        }

        return true;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws IPayMellatException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $soap = new SoapClient($this->serverUrl);

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $this->orderId,
            'saleOrderId' => $this->orderId,
            'saleReferenceId' => $this->trackingCode
        );

        $response = $soap->bpVerifyRequest($fields);

        if ($response->return != '0') {
            $this->newLog($this->orderId, $response->return, IPayMellatException::$errors[$response->return]);
            $this->updateTransactionFailed($this->orderId, self::TRANSACTION_FAILED);
            throw new IPayMellatException($response->return);
        }

        return true;
    }

    /**
     * Send settle request
     *
     * @return bool
     *
     * @throws IPayMellatException
     * @throws SoapFault
     */
    protected function settleRequest()
    {
        $soap = new SoapClient($this->serverUrl);

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $this->orderId,
            'saleOrderId' => $this->orderId,
            'saleReferenceId' => $this->trackingCode
        );

        $response = $soap->bpSettleRequest($fields);

        if ($response->return == '0' || $response->return == '45') {
            $this->newLog($this->orderId, $response->return, self::TRANSACTION_SUCCEED_TEXT);
            $this->updateTransactionSucceed($this->orderId);
            return true;
        }

        $this->newLog($this->orderId, $response->return, IPayMellatException::$errors[$response->return]);
        $this->updateTransactionFailed($this->orderId, self::TRANSACTION_FAILED);
        throw new IPayMellatException($response->return);
    }


    /**
     * Update transaction refId
     *
     * @param int $id id of row in ipay_transactions table
     *
     * @return void
     */
    protected function updateTransactionRefId($id)
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("UPDATE ipay_transactions SET ref_id = :ref_id WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':ref_id', $this->refId);

        $stmt->execute();
    }

    /**
     * Update transaction when failed
     *
     * @param int $id id of row in ipay_transactions table
     *
     * @return void
     */
    protected function updateTransactionFailed($id)
    {
        $dbh = $this->db->getDBH();

        $status = self::TRANSACTION_FAILED;

        $stmt = $dbh->prepare("UPDATE ipay_transactions SET status = :status WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':status', $status);

        $stmt->execute();
    }

    /**
     * Update transaction when succeed
     *
     * @param int $id id of row in ipay_transactions table
     *
     * @return void
     */
    protected function updateTransactionSucceed($id)
    {
        $dbh = $this->db->getDBH();

        $date = new DateTime;
        $status = self::TRANSACTION_SUCCEED;

        $stmt = $dbh->prepare("UPDATE ipay_transactions SET status = :status, tracking_code = :tracking_code, payment_date = :payment_date, last_change_date = :last_change_date WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':tracking_code', $this->trackingCode);
        $stmt->bindParam(':payment_date', $date->getTimestamp());
        $stmt->bindParam(':last_change_date', $date->getTimestamp());

        $stmt->execute();
    }

    /**
     * New log in ipay_status_log table
     *
     * @param int $transactionId
     * @param string $resultCode
     * @param string $resultMessage
     *
     * @return void
     */
    protected function newLog($transactionId, $resultCode, $resultMessage)
    {
        $dbh = $this->db->getDBH();

        $date = new DateTime;

        $stmt = $dbh->prepare("INSERT INTO ipay_status_log (transaction_id, result_code, result_message, log_date) VALUES (:transaction_id, :result_code, :result_message, :log_date)");
        $stmt->bindParam(':transaction_id', $transactionId);
        $stmt->bindParam(':result_code', $resultCode);
        $stmt->bindParam(':result_message', $resultMessage);
        $stmt->bindParam(':log_date', $date->getTimestamp());
        $stmt->execute();
    }
}
