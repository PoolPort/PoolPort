<?php

namespace PoolPort\IranKish;

use DateTime;
use SoapClient;
use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class IranKish extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = 'https://ikc.shaparak.ir/TToken/Tokens.xml';

    /**
     * Address of SOAP server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://ikc.shaparak.ir/TVerify/Verify.xml';

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
        $merchantId = $this->config->get('irankish.merchant-id');

        require 'IranKishRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws IranKishException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $fields = array(
            'amount' => $this->amount,
            'merchantId' => $this->config->get('irankish.merchant-id'),
            'description' => $this->config->get('irankish.description'),
            'invoiceNo' => $this->transactionId(),
            'paymentId' => $this->transactionId(),
            'specialPaymentId' => $this->transactionId(),
            'revertURL' => $this->buildQuery($this->config->get('irankish.callback-url'), array('transaction_id' => $this->transactionId)),
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->MakeToken($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response->MakeTokenResult->result == false) {
            $this->transactionFailed();
            $this->newLog($response->MakeTokenResult->result, $response->MakeTokenResult->message);
            throw new IranKishException;
        }
        $this->refId = $response->MakeTokenResult->token;
        $this->transactionSetRefId($this->transactionId);
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws IranKishException
     */
    protected function userPayment()
    {
        $this->refId = @$_POST['token'];
        $this->trackingCode = @$_POST['referenceId'];
        $resultCode = @$_POST['resultCode'];

        if ($resultCode == '100') {
            return true;
        }

        $this->transactionFailed();
        $this->newLog($resultCode, @IranKishException::$errors[$resultCode]);
        throw new IranKishException($resultCode);
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws IranKishException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $fields = array(
            'token' => $this->refId,
            'referenceNumber' => $this->trackingCode,
            'merchantId' => $this->config->get('irankish.merchant-id'),
            'sha1Key' => $this->config->get('irankish.sha1-key')
        );

        try {
            $soap = new SoapClient($this->serverVerifyUrl);
            $response = $soap->KicccPaymentsVerification($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        $response = floatval($response->KicccPaymentsVerificationResult);

        if ($response > 0) {
            $this->transactionSucceed();
            $this->newLog('100', self::TRANSACTION_SUCCEED_TEXT);
            return true;
        } else {
            $this->transactionFailed();
            $this->newLog($response, @IranKishException::$errors[$response]);
            throw new IranKishException($response);
        }
    }
}
