<?php

namespace PoolPort\Mellat;

use DateTime;
use SoapClient;
use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Mellat extends PortAbstract implements PortInterface
{
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
     * {@inheritdoc}
     */
    public function __construct(Config $config, DataBaseManager $db, $portId)
    {
        parent::__construct($config, $db, $portId);
    }

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function with(array $data = array())
    {
        $this->additionalData = $data;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $refId = $this->refId;

        require 'MellatRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify();

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
     * @throws MellatException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $fields = array(
            'terminalId' => $this->config->get('mellat.terminalId'),
            'userName' => $this->config->get('mellat.username'),
            'userPassword' => $this->config->get('mellat.password'),
            'orderId' => $this->transactionId(),
            'amount' => $this->amount(),
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'additionalData' => $this->additionalData,
            'callBackUrl' => $this->buildQuery($this->config->get('mellat.callback-url'), array('transaction_id' => $this->transactionId)),
            'payerId' => 0,
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->bpPayRequest($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw new MellatException('SoapFault', $e->getMessage());
        }

        $response = explode(',', $response->return);

        if ($response[0] != '0') {
            $this->transactionFailed();
            $this->newLog($response[0], MellatException::$errors[$response[0]]);
            throw new MellatException($response[0]);
        }
        $this->refId = $response[1];
        $this->transactionSetRefId($this->transactionId);
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws MellatException
     */
    protected function userPayment()
    {
        $this->refId = @$_POST['RefId'];
        $this->trackingCode = @$_POST['SaleReferenceId'];
        $this->cardNumber = @$_POST['CardHolderPan'];
        $payRequestResCode = (int) @$_POST['ResCode'];

        if ($payRequestResCode != 0) {
            $this->newLog($payRequestResCode, MellatException::$errors[$payRequestResCode]);
            $this->transactionFailed();
            throw new MellatException($payRequestResCode);
        }

        return true;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws MellatException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $fields = array(
            'terminalId' => $this->config->get('mellat.terminalId'),
            'userName' => $this->config->get('mellat.username'),
            'userPassword' => $this->config->get('mellat.password'),
            'orderId' => $this->transactionId(),
            'saleOrderId' => $this->transactionId(),
            'saleReferenceId' => $this->trackingCode
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->bpVerifyRequest($fields);

        } catch(\SoapFault $e) {
            $this->newLog('SoapFault', $e->getMessage());
            $this->transactionFailed();
            throw new MellatException('SoapFault', $e->getMessage());
        }

        if ($response->return != '0') {
            $this->newLog($response->return, MellatException::$errors[$response->return]);
            $this->transactionFailed();
            throw new MellatException($response->return);
        }

        return true;
    }

    /**
     * Send settle request
     *
     * @return bool
     *
     * @throws MellatException
     * @throws SoapFault
     */
    protected function settleRequest()
    {
        $fields = array(
            'terminalId' => $this->config->get('mellat.terminalId'),
            'userName' => $this->config->get('mellat.username'),
            'userPassword' => $this->config->get('mellat.password'),
            'orderId' => $this->transactionId(),
            'saleOrderId' => $this->transactionId(),
            'saleReferenceId' => $this->trackingCode
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->bpSettleRequest($fields);

        } catch(\SoapFault $e) {
            $this->newLog('SoapFault', $e->getMessage());
            $this->transactionFailed();
            throw new MellatException('SoapFault', $e->getMessage());
        }

        if ($response->return == '0' || $response->return == '45') {
            $this->newLog($response->return, self::TRANSACTION_SUCCEED_TEXT);
            $this->transactionSucceed();
            return true;
        }

        $this->newLog($response->return, MellatException::$errors[$response->return]);
        $this->transactionFailed();
        throw new MellatException($response->return);
    }
}
