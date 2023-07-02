<?php

namespace PoolPort\PayPing;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class PayPing extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = "https://api.payping.ir/v2/pay";

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
        header('Location: '.$this->serverUrl."/gotoipg/$this->refId");
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
     * @throws PayPingException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $fields = array(
                "amount" => $this->amount / 10,
                "payerIdentity" => $this->config->get('payping.user-mobile'),
                "returnUrl" => $this->buildRedirectUrl($this->config->get('payping.callback-url')),
                "clientRefId" => $this->transactionId(),
            );

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Bearer ".$this->config->get('payping.token')));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $responseEncoded = json_decode($response, true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (isset($responseEncoded['code']) && !empty($responseEncoded['code'])) {
                $this->refId = $responseEncoded['code'];
                $this->transactionSetRefId();
                return true;
            }

        } catch(\Exception $e) {
            $this->transactionFailed();
            $this->newLog($e->getCode(), $e->getMessage());
            throw $e;
        }

        $this->transactionFailed();
        $this->newLog($httpCode, $response);
        throw new PayPingException($response, $httpCode);
    }

    /**
     * Check user payment
     *
     * @return void
     */
    protected function userPayment()
    {
        $this->trackingCode = @$_POST['refid'];
        $this->cardNumber = (float) @$_POST['cardnumber'];
    }

    /**
     * Verify user payment from bank server
     *
     * @return void
     */
    protected function verifyPayment()
    {
        try {
            $fields = array(
                "refId" => $this->trackingCode(),
                "amount" => $this->amount / 10,
            );

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $this->serverUrl."/verify");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Bearer ".$this->config->get('payping.token')));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $responseEncoded = json_decode($response, true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $this->transactionSucceed();
                $this->newLog('00', self::TRANSACTION_SUCCEED_TEXT);
                return true;
            }

        } catch(\Exception $e) {
            $this->transactionFailed();
            $this->newLog($e->getCode(), $e->getMessage());
            throw $e;
        }

        $this->transactionFailed();

        if (is_array($responseEncoded)) {
            $code = null;
            $message = null;
            foreach ($responseEncoded as $c => $m) {
                $code = $c;
                $message = $m;
            }

            $this->newLog($code, $message);
            throw new PayPingException($message, $code);
        }

        throw new PayPingException("");
    }
}
