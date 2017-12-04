<?php

namespace PoolPort\Saderat;

use DateTime;
use PoolPort\Config;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Saderat extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = "https://mabna.shaparak.ir/TokenService?wsdl";

    /**
     * Address of verify SOAP server
     *
     * @var string
     */
    protected $verifyUrl = "https://mabna.shaparak.ir/TransactionReference/TransactionReference?wsdl";

    /**
     * Public key
     *
     * @var mixed
     */
    private $publicKey = null;

    /**
     * Private key
     *
     * @var mixed
     */
    private $privateKey = null;

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
        $token = $this->refId;

        require 'SaderatRedirector.php';
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
     * @throws MellatException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();
        $this->setKeys();

        $fields = array(
            "Token_param" => array(
                "AMOUNT" => $this->getEncryptedAmount(),
                "CRN" => $this->getEncryptedTrancactionId(),
                "MID" => $this->getEncryptedMerchantId(),
                "REFERALADRESS" => $this->getEncryptedCallbackUrl(),
                "SIGNATURE" => $this->createSignature(),
                "TID" => $this->getEncryptedTerminalId()
            )
        );

        try {
            // Disable SSL
            $soap = new SoapClient($this->serverUrl, $this->config, array("stream_context" => stream_context_create(
                array(
                    'ssl' => array(
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                    )
                )
            )));
            $response = $soap->reservation($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response->return->result != 0) {
            $this->transactionFailed();
            $this->newLog($response->return->result, SaderatException::getError($response->return->result));
            throw new SaderatException($response->return->result);
        }

        $this->refId = $response->return->token;
        $this->transactionSetRefId($this->transactionId);

        $result = openssl_verify($response->return->token, base64_decode($response->return->signature), $this->publicKey);

        if ($result != 1) {
            $this->transactionFailed();
            $this->newLog('Signature', SaderatException::getError('poolport-faild-signature-verify'));
            throw new SaderatException('poolport-faild-signature-verify');
        }
    }

    /**
     * Generate public and private keys
     *
     * @return void
     */
    protected function setKeys()
    {
        $this->publicKey = openssl_pkey_get_public('file://'.$this->config->get('saderat.public-key'));
        $this->privateKey = openssl_pkey_get_private('file://'.$this->config->get('saderat.private-key'));
    }

    /**
     * Encrypt amount
     *
     * @return string
     */
    protected function getEncryptedAmount()
    {
        openssl_public_encrypt($this->amount, $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt transaction id as CRN
     *
     * @return string
     */
    protected function getEncryptedTrancactionId()
    {
        openssl_public_encrypt($this->transactionId(), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt merchant id
     *
     * @return string
     */
    protected function getEncryptedMerchantId()
    {
        openssl_public_encrypt($this->config->get('saderat.merchant-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt termianl id
     *
     * @return string
     */
    protected function getEncryptedTerminalId()
    {
        openssl_public_encrypt($this->config->get('saderat.terminal-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt callback url
     *
     * @return string
     */
    protected function getEncryptedCallbackUrl()
    {
        $callBackUrl = $this->buildQuery($this->config->get('saderat.callback-url'), array('transaction_id' => $this->transactionId));
        openssl_public_encrypt($callBackUrl, $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt tracking code
     *
     * @return string
     */
    protected function getEncryptedTrackingCode()
    {
        openssl_public_encrypt($this->trackingCode(), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Create and encrypt signature
     *
     * @return string
     */
    protected function createSignature()
    {
        $data = $this->amount.$this->transactionId().$this->config->get('saderat.merchant-id').
            $this->buildQuery($this->config->get('saderat.callback-url'), array('transaction_id' => $this->transactionId)).
            $this->config->get('saderat.terminal-id');

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }

    /**
     * Create and encrypt verify signature
     *
     * @return string
     */
    protected function createVerifySignature()
    {
        $data = $this->config->get('saderat.merchant-id').$this->trackingCode().$this->transactionId();

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }

    /**
     * Check user payment
     *
     * @return void
     */
    protected function userPayment()
    {
        if (empty($_POST) || @$_POST['RESCODE'] != '00') {
            $this->transactionFailed();
            $this->newLog(@$_POST['RESCODE'], SaderatException::getError(@$_POST['RESCODE']));
            throw new SaderatException(@$_POST['RESCODE']);
        }
    }

    /**
     * Verify user payment from bank server
     *
     * @return void
     */
    protected function verifyPayment()
    {
        $this->setKeys();

        $this->trackingCode = @$_POST['TRN'];

        $fields = array(
            "SaleConf_req" => array(
                "CRN" => $this->getEncryptedTrancactionId(),
                "MID" => $this->getEncryptedMerchantId(),
                "TRN" => $this->getEncryptedTrackingCode(),
                "SIGNATURE" => $this->createVerifySignature()
            )
        );

        try {
            // Disable SSL
            $soap = new SoapClient($this->verifyUrl, $this->config, array("stream_context" => stream_context_create(
                array(
                    'ssl' => array(
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                    )
                )
            )));
            $response = $soap->sendConfirmation($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if (empty($_POST) || @$_POST['RESCODE'] != '00') {
            $this->transactionFailed();
            $this->newLog(@$_POST['RESCODE'], SaderatException::getError(@$_POST['RESCODE']));
            throw new SaderatException(@$_POST['RESCODE']);
        }
        if ($response->return->RESCODE != '00') {
            $this->transactionFailed();
            $this->newLog($response->return->RESCODE, SaderatException::getError($response->return->RESCODE));
            throw new SaderatException($response->return->RESCODE);
        }

        $data = $response->return->RESCODE.$response->return->REPETETIVE.$response->return->AMOUNT.
            $response->return->DATE.$response->return->TIME.$response->return->TRN.$response->return->STAN;

        $result = openssl_verify($data, base64_decode($response->return->SIGNATURE), $this->publicKey);

        if ($result != 1) {
            $this->transactionFailed();
            $this->newLog('Signature', SaderatException::getError('poolport-faild-signature-verify'));
            throw new SaderatException('poolport-faild-signature-verify');
        }

        $this->transactionSucceed();
        $this->newLog('00', self::TRANSACTION_SUCCEED_TEXT);
        return true;
    }
}
