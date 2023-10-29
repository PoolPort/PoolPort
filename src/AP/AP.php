<?php

namespace PoolPort\AP;

use GuzzleHttp\Client;
use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class AP extends PortAbstract implements PortInterface
{
    /**
     * API Endpoint
     *
     * @var string
     */
    protected $serverUrl = "https://ipgrest.asanpardakht.ir/v1";

    /**
     * @var string
     */
    private $payGateTranID;

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
        $mobile = $this->config->get('ap.user-mobile');

        require 'APRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();
        $this->settlePayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws APException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();
            $response = $client->post($this->serverUrl."/Token", [
                'headers' => [
                    'usr' => $this->config->get('ap.username'),
                    'pwd' => $this->config->get('ap.password'),
                ],
                'json' => [
                    'merchantConfigurationId' => $this->config->get('ap.merchant-config-id'),
                    'serviceTypeId' => 1,
                    'localInvoiceId' => $this->transactionId(),
                    'amountInRials' => $this->amount,
                    'localDate' => date('Ymd His'),
                    'callbackURL' => $this->buildRedirectUrl($this->config->get('ap.callback-url')),
                    'additionalData' => '',
                    'paymentId' => 0,
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            $this->refId = $response;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check user payment
     *
     * @return void
     */
    protected function userPayment()
    {
        try {
            $client = new Client();
            $response = $client->get($this->serverUrl."/TranResult", [
                'headers' => [
                    'usr' => $this->config->get('ap.username'),
                    'pwd' => $this->config->get('ap.password'),
                    'Content-Type: application/json',
                ],
                'query' => [
                    'merchantConfigurationId' => $this->config->get('ap.merchant-config-id'),
                    'localInvoiceId' => $this->transactionId(),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            $this->cardNumber = $response->cardNumber;
            $this->trackingCode = $response->rrn;
            $this->payGateTranID = $response->payGateTranID;

            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog("Error", $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from bank server
     *
     * @return void
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();
            $client->post($this->serverUrl."/Verify", [
                'headers' => [
                    'usr' => $this->config->get('ap.username'),
                    'pwd' => $this->config->get('ap.password'),
                ],
                'json' => [
                    'merchantConfigurationId' => $this->config->get('ap.merchant-config-id'),
                    'payGateTranId' => $this->payGateTranID,
                ],
            ]);

            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog("Error", $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Settlement payment
     *
     * @return void
     */
    protected function settlePayment()
    {
        try {
            $client = new Client();
            $client->post($this->serverUrl."/Settlement", [
                'headers' => [
                    'usr' => $this->config->get('ap.username'),
                    'pwd' => $this->config->get('ap.password'),
                ],
                'json' => [
                    'merchantConfigurationId' => $this->config->get('ap.merchant-config-id'),
                    'payGateTranId' => $this->payGateTranID,
                ],
            ]);

            $this->transactionSucceed();
            $this->newLog('100', self::TRANSACTION_SUCCEED_TEXT." ({$this->payGateTranID})");
            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog("Error", $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
