<?php

namespace PoolPort\Saman;

use DateTime;
use PoolPort\Config;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Saman extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = 'https://sep.shaparak.ir/payments/initpayment.asmx?WSDL';

    /**
     * Address of SOAP server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL';

    /**
     * @var string|null
     */
    protected $token;

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
        $token = $this->token;
        $callbackUrl = $this->buildQuery($this->config->get('saman.callback-url'), array('transaction_id' => $this->transactionId));

        require 'SamanRedirector.php';
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
     * @throws SamanException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $fields = array(
            'MID' => $this->config->get('saman.merchant-id'),
            'ResNum' => $this->transactionId(),
            'Amount' => $this->amount
        );

        try {
            $soap = new SoapClient($this->serverUrl, $this->config);
            $response = $soap->RequestToken($fields['MID'], $fields['ResNum'], $fields['Amount']);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if (intval($response) < 0) {
            $this->transactionFailed();
            $this->newLog($response, @SamanException::$errors[$response]);
            throw SamanException::error($response);
        }
        $this->token = $response;
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws SamanException
     */
    protected function userPayment()
    {
        $stateCode = intval(@$_POST['StateCode']);
        $this->refId = @$_POST['RefNum'];
        $this->trackingCode = @$_POST['TRACENO'];
        $this->cardNumber = @$_POST['SecurePan'];

        if ($stateCode == '0') {
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog($stateCode, @SamanException::$stateErrors[$stateCode]);
        throw SamanException::stateError($stateCode);
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws SamanException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $fields = array(
            'RefNum' => $this->refId,
            'MerchantId' => $this->config->get('saman.merchant-id')
        );

        try {
            $soap = new SoapClient($this->serverVerifyUrl, $this->config);
            $response = $soap->verifyTransaction($fields['RefNum'], $fields['MerchantId']);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response == $this->amount) {
            $this->transactionSucceed();
            $this->newLog('100', self::TRANSACTION_SUCCEED_TEXT);
            return true;
        } else {
            $this->transactionFailed();
            $this->newLog($response, @SamanException::$stateErrors[$response]);
            throw SamanException::stateError($stateCode);
        }
    }
}
