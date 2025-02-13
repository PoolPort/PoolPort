<?php

namespace PoolPort\Tara;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Tara extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl       = 'https://stage-pay.tara360.ir/pay';
    protected $refundGateUrl = 'https://stage.tara-club.ir/club';

    private $accessToken;

    private $refundToken;

    private $items;

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
        $this->authenticate();
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $fields['username'] = $this->config->get('tara.username');
        $fields['token'] = $this->refId();
        $fields['url'] = "{$this->gateUrl}/api/ipgPurchase";

        require 'TaraRedirector.php';
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

    protected function authenticate()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/api/v2/authenticate", [
                "json"    => [
                    'username' => $this->config->get('tara.username'),
                    'password' => $this->config->get('tara.password'),
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->result != 0) {
                $this->newLog($response->result, json_encode($response));
                throw new TaraException(json_encode($response), $response->result);
            }

            $this->accessToken = $response->accessToken;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws TaraException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $this->setMeta([
            'accessToken' => $this->accessToken,
        ]);

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/api/getToken", [
                "json"    => [
                    'amount'      => $this->amount,
                    'callBackUrl' => $this->buildRedirectUrl($this->config->get('tara.callback-url')),
                    'mobile'      => $this->config->get('tara.user-mobile'),
                    'ip'          => $this->getUserIP(),

                    'serviceAmountList' => [
                        [
                            'serviceId' => $this->config->get('tara.service-id'),
                            'amount'    => $this->amount,
                        ]
                    ],

                    'taraInvoiceItemList' => $this->items['taraInvoiceItem'],
                    'vat'                 => $this->items['vat'],
                    'orderId'             => $this->items['orderId'],
                    'additionalData'      => $this->items['additionalData'],
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->result != 0) {
                $this->transactionFailed();
                $this->newLog($response->result, json_encode($response));
                throw new TaraException(json_encode($response), $response->result);
            }

            $this->refId = $response->token;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from tara server
     *
     * @return bool
     *
     * @throws TaraException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/api/purchaseVerify", [
                "json"    => [
                    'ip'    => $this->getUserIP(),
                    'token' => $this->refId(),
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getMeta('accessToken'),
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->result != 0) {
                $this->transactionFailed();
                $this->newLog($response->result, json_encode($response));
                throw new TaraException(json_encode($response), $response->result);
            }

            $this->setMeta([
                'rrn' => $response->rrn
            ]);

            $this->trackingCode = $response->rrn;
            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refund user payment
     *
     * @return bool
     *
     * @throws TaraException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->refundLogin();
            $meta = json_decode($transaction->meta, true);
            $referenceNumber = $meta['rrn'];
            $client = new Client();

            $response = $client->request("POST", "{$this->refundGateUrl}/api/v1/user/purchase/limited/refund/$referenceNumber", [
                "json"    => [
                    'description' => !empty($params['description']) ? $params['description'] : '',
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->refundToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->success == false) {
                $this->newLog($response->data->code, json_encode($response));
                throw new TaraException(json_encode($response), $response->data->code);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function refundLogin()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->refundGateUrl}/api/v1/user/login/refund", [
                "json"    => [
                    'principal' => $this->config->get('tara.refund.username'),
                    'password'  => $this->config->get('tara.refund.password'),
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->success == false) {
                $this->newLog($response->data->code, json_encode($response));
                throw new TaraException(json_encode($response), $response->data->code);
            }

            $this->refundToken = $response->accessCode;

            $this->setMeta([
                'accessCode' => $response->accessCode
            ]);

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function getUserIP()
    {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }

        return trim($ip);
    }

    public function addItem($taraInvoiceItem, $orderId = null, $vat = 0, $additionalData = "")
    {
        $orderId = $orderId ? $orderId : $this->transactionId();

        $this->items = [
            'taraInvoiceItem' => $taraInvoiceItem,
            'orderId'         => (string)$orderId,
            'vat'             => $vat,
            'additionalData'  => (string)$additionalData
        ];

        return $this;
    }
}