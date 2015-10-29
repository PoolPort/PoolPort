<?php

namespace IPay\Mellat;

use DateTime;
use SoapClient;
use IPay\Config;
use IPay\IPayAbstract;
use IPay\IPayInterface;
use IPay\DataBaseManager;

class IPayMellat extends IPayAbstract implements IPayInterface
{
    /**
     * Determine request passes
     *
     * @var bool
     */
    protected $requestPass = false;

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
     * @inheritdoc
     */
    public function __construct(Config $config, DataBaseManager $db, $portId) {
        parent::__construct($config, $db, $portId);


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
    public function verify($transaction)
    {
        $this->transaction = $transaction;
        $this->transactionId = $transaction->id;

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

        $this->newTransaction();

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $this->transactionId,
            'amount' => $this->amount,
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'additionalData' => $this->additionalData,
            'callBackUrl' => $this->buildQuery($this->config->get('mellat.callback-url'), array('transaction_id' => $this->transactionId)),
            'payerId' => 0,
        );

        $response = $soap->bpPayRequest($fields);

        $response = explode(',', $response->return);

        if ($response[0] != '0') {
            $this->newLog($response[0], IPayMellatException::$errors[$response[0]]);
            throw new IPayMellatException($response[0]);
        }
        $this->refId = $response[1];
        $this->transactionSetRefId($this->transactionId);
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

        if ($payRequestResCode != 0) {
            $this->newLog($payRequestResCode, IPayMellatException::$errors[$payRequestResCode]);
            $this->transactionFailed();
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
            'orderId' => $this->transactionId,
            'saleOrderId' => $this->transactionId,
            'saleReferenceId' => 45//$this->trackingCode
        );

        $response = $soap->bpVerifyRequest($fields);

        if ($response->return != '0') {
            $this->newLog($response->return, IPayMellatException::$errors[$response->return]);
            $this->transactionFailed();
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
            $this->newLog($response->return, self::TRANSACTION_SUCCEED_TEXT);
            $this->transactionSucceed();
            return true;
        }

        $this->newLog($response->return, IPayMellatException::$errors[$response->return]);
        $this->transactionFailed();
        throw new IPayMellatException($response->return);
    }
}
