<?php

namespace PoolPort\Saderat;

use DateTime;
use SoapClient;
use PoolPort\Config;
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
        $refId = $this->refId;

        require 'MellatRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

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
        $this->setKeys();

        $fields = array(
            "Token_param" => array(
                "AMOUNT" => $this->getEncryptedAmount(),
                "CRN" => $this->getEncryptedTrancactionId(),
                "MID" => $this->getEncryptedMerchantId(),
                "SIGNATURE" => $this->createSignature(),
                "REFERALADRESS" => $this->getEncryptedCallbackUrl(),
                "TID" => $this->getEncryptedTerminalId(),
            )
        );

        try {
            $soap = new SoapClient($this->serverUrl, array("stream_context" => stream_context_create(
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
        dd($response);
        $this->refId = $response[1];
        $result = openssl_verify(base64_decode($response->return->token), $fields['SIGNATURE'], $this->publicKey);
        $this->transactionSetRefId($this->transactionId);
    }

    protected function setKeys()
    {
        $this->publicKey = openssl_pkey_get_public('file://'.$this->config->get('saderat.public-key'));
        $this->privateKey = openssl_pkey_get_private('file://'.$this->config->get('saderat.private-key'));
    }

    protected function getEncryptedAmount()
    {
        openssl_public_encrypt($this->amount, $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    protected function getEncryptedTrancactionId()
    {
        openssl_public_encrypt($this->transactionId(), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    protected function getEncryptedMerchantId()
    {
        openssl_public_encrypt($this->config->get('saderat.merchant-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    protected function getEncryptedTerminalId()
    {
        openssl_public_encrypt($this->config->get('saderat.terminal-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    protected function getEncryptedCallbackUrl()
    {
        openssl_public_encrypt($this->config->get('saderat.callback-url'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    protected function createSignature()
    {
        $data = $this->amount.$this->transactionId().$this->config->get('saderat.merchant-id')
            .$this->config->get('saderat.callback-url').$this->config->get('saderat.terminal-id');

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }
}
