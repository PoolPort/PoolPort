<?php

namespace IPay\Mellat;

use DateTime;
use SoapClient;
use IPay\Config;
use IPay\IPayInterface;
use IPay\DataBaseManager;

class IPayMellat implements IPayInterface
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
     * Amount in Rial
     *
     * @var int
     */
    protected $amount;

    /**
     * Additional data for send to port
     *
     * @var string
     */
    protected $additionalData;

    /**
     * @var IPay\Config
     */
    protected $config;

    /**
     * @var IPay/DataBaseManager
     */
    protected $db;

    /**
     * Initialize of class
     *
     * @param string $configFile
     * @return void
     */
    public function __construct(Config $config, DataBaseManager $db)
    {
        $this->config = $config;
        $this->db = $db;

        $this->username = $this->config->get('mellat.username');
        $this->password = $this->config->get('mellat.password');
        $this->termId = $this->config->get('mellat.terminalId');
    }

    /**
     * This method use for set price in Rial.
     *
     * @param int $amount in Rial
     *
     * @return void
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
     * Get refId
     *
     * @return int|string
     */
    public function getRefId()
    {
        return $this->refId;
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
     * Return tracking code
     *
     * @return int|string
     */
    public function trackingCode()
    {
        return $this->refId;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws IPayMellatException
     */
    public function sendPayRequest()
    {
        $soap = new SoapClient($this->serverUrl);
        $dateTime = new DateTime();

        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
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
            throw new IPayMellatException($response[0]);
        }

        $this->refId = $response[1];

        $this->editLog($orderId, $this->refId, '', '', $this->additionalData, 'Start connection to bank.');
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws IPayMellatException
     */
    public function userPayment()
    {
        $this->refId = @$_POST['RefId'];
        $this->payRequestResCode = (int) @$_POST['ResCode'];
        $this->saleOrderId = @$_POST['SaleOrderId'];
        $this->saleReferenceId = @$_POST['SaleReferenceId'];

        if ($this->payRequestResCode != 0) {
            throw new IPayMellatException($this->payRequestResCode);
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
    public function verifyPayment()
    {
        $soap = new SoapClient($this->serverUrl);
        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
            'saleOrderId' => $this->saleOrderId,
            'saleReferenceId' => $this->saleReferenceId
        );

        $response = $soap->bpVerifyRequest($fields);

        if ($response->return != '0') {
            $this->newLog($this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'User not payment.');
            throw new IPayMellatException(17);
        }

        $this->newLog($this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'User payment done.');
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
    public function settleRequest()
    {
        $soap = new SoapClient($this->serverUrl);
        $orderId = $this->newLog();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $orderId,
            'saleOrderId' => $this->saleOrderId,
            'saleReferenceId' => $this->saleReferenceId
        );

        $response = $soap->bpSettleRequest($fields);

        if ($response->return == '0' || $response->return == '45') {
            $this->editLog($orderId, $this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'Settle request done, code = '.$response->return);
            return true;
        }

        $this->editLog($orderId, $this->refId, $this->saleOrderId, $this->saleReferenceId, '', 'Settle request faile, code = '.$response->return);
        throw new IPayMellatException($response->return);
    }

    /**
     * Get ResCode of payRequest method
     *
     * @return int
     */
    public function getPayRequestResCode()
    {
        return $this->payRequestResCode;
    }

    /**
     * Insert new log to table
     *
     * @param string $refId
     * @param string $saleOrderId
     * @param string $saleRefrencesId
     * @param string $AdditionalData
     * @param string $message
     * @return int last inserted id
     */
    public function newLog($refId = '', $saleOrderId = '', $saleRefrencesId = '', $AdditionalData = '', $message = '')
    {
        $dbh = $this->db->getDBH();

        $date = new DateTime;
        $date = $date->format('Y/m/d H:i:s');

        $stmt = $dbh->prepare("INSERT INTO mellat_orders_log (ref_id, sale_order_id, sale_refrences_id, additional_data, message, timestamp) VALUES (:ref_id, :sale_order_id, :sale_refrences_id, :additional_data, :message, :timestamp)");
        $stmt->bindParam(':ref_id', $refId);
        $stmt->bindParam(':sale_order_id', $saleOrderId);
        $stmt->bindParam(':sale_refrences_id', $saleRefrencesId);
        $stmt->bindParam(':additional_data', $AdditionalData);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':timestamp', $date);
        $stmt->execute();

        return $dbh->lastInsertId();
    }

    /**
     * Update log in table
     *
     * @param int $id
     * @param string $refId
     * @param string $saleOrderId
     * @param string $saleRefrencesId
     * @param string $AdditionalData
     * @param string $message
     * @return void
     */
    public function editLog($id, $refId = '', $saleOrderId = '', $saleRefrencesId = '', $AdditionalData = '', $message = '')
    {
        $dbh = $this->db->getDBH();

        $stmt = $dbh->prepare("UPDATE mellat_orders_log SET ref_id = :ref_id, sale_order_id = :sale_order_id, sale_refrences_id = :sale_refrences_id, additional_data = :additional_data, message = :message WHERE id = :id");
        $stmt->bindParam(':ref_id', $refId);
        $stmt->bindParam(':sale_order_id', $saleOrderId);
        $stmt->bindParam(':sale_refrences_id', $saleRefrencesId);
        $stmt->bindParam(':additional_data', $AdditionalData);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $dbh->lastInsertId();
    }
}
