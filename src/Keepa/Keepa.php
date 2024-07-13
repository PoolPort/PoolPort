<?php

namespace PoolPort\Keepa;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\Keepa\KeepaException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;

class Keepa extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.kipaa.ir/ipg/v1/supplier';

    private $token;

    private $paymentUrl;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $config, DatabaseManager $db, $portId)
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
        $fields['token'] = $this->token;
        $fields['url'] = $this->paymentUrl;

        require 'KeepaRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->verifyPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws KeepaException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/request_payment_token", [
                "json"    => [
//                    'amount'       => $this->amount,
                    'amount'       => 1000,
                    'callback_url' => $this->buildRedirectUrl($this->config->get('keepa.callback-url')),
                    'mobile'       => $this->config->get('keepa.user-mobile'),
                ],
                'headers' => [
                    'Authorization' => $this->getToken(),
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->Status != 200) {
                $this->transactionFailed();
                $this->newLog($response->Status, $response->Message);
                throw new KeepaException($response->Message, $response->Status);
            }

            $this->token = $response->Content->payment_token;
            $this->paymentUrl = $response->Content->payment_url;
            $this->refId = $this->transactionId();
            $this->transactionSetRefId();

            $this->setMeta([
                'token'     => $this->token,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from keepa server
     *
     * @return bool
     *
     * @throws KeepaException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/verify_transaction", [
                "json"    => [
                    'payment_token'  => $this->getMeta('token'),
                    'reciept_number' => $this->getMeta('reciept_number'),
                ],
                'headers' => [
                    'Authorization' => $this->getToken(),
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->Status != 200) {
                $this->transactionFailed();
                $this->newLog($response->Status, $response->Message);
                throw new KeepaException($response->Message, $response->Status);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * get token
     *
     * @return string
     */
    protected function getToken()
    {
        $token = $this->config->get('keepa.token');

        return "Bearer $token";
    }
}