<?php

namespace PoolPort\DigiPay;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class DigiPay extends PortAbstract implements PortInterface
{
    const GRANT_TYPE_PASSWORD = 'password';
    const DIGIPAY_VERSION     = '02-02-2022';
    const AGENT_WEB           = 'WEB';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.mydigipay.com/digipay/api';

    /**
     * Address of payment gateway
     *
     * @var string
     */
    private $paymentUri;

    private $accessToken;

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
        $this->newTransaction();
        $this->authenticate();
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        header("Location: " . $this->paymentUri);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->setMeta($_POST);

        $this->verifyPayment();

        $this->deliverPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws DigiPayException
     */
    protected function sendPayRequest()
    {
        try {
            $client = new Client();
            $prividerId = $this->transactionId();
            $basketId = mt_rand(1000000000, 999999999999);
            $type = $this->config->get('digipay.type');

            $this->setMeta([
                'items' => $this->items
            ]);

            $response = $client->request("POST", "{$this->gateUrl}/tickets/business?type={$type}", [
                "json" => [
                    'amount'      => $this->amount,
                    'callbackUrl' => $this->buildRedirectUrl($this->config->get('digipay.callback-url')),
                    'providerId'  => "$prividerId",
                    'cellNumber'  => $this->config->get('digipay.user-mobile', ''),

                    "basketDetailsDto" => [
                        "basketId" => "$basketId",
                        "items"    => $this->items
                    ]
                ],

                "headers" => [
                    'Authorization'   => "Bearer {$this->accessToken}",
                    'Content-Type'    => 'application/json; charset=UTF-8',
                    'Agent'           => self::AGENT_WEB,
                    'Digipay-Version' => self::DIGIPAY_VERSION,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new DigiPayException(json_encode($response), $statusCode);
            }

            $this->paymentUri = $response->redirectUrl;
            $this->refId = $response->ticket;
            $this->transactionSetRefId();

            $this->setMeta([
                'ticket' => $response->ticket,
                'amount' => $this->amount
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from azki server
     *
     * @return bool
     *
     * @throws DigiPayException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/purchases/verify/{$_POST['trackingCode']}?type={$_POST['type']}", [
                "headers" => [
                    'Authorization' => "Bearer " . $this->getMeta('access_token'),
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = $response->getBody()->getContents();

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response);
                throw new DigiPayException($response, $statusCode);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Deliver the payment after verify
     *
     * @return bool
     *
     * @throws DigiPayException
     */
    protected function deliverPayment()
    {
        try {
            $client = new Client();
            $items = $this->getMeta('items');
            $products = collect($items)->pluck('brand')->toArray();
            $trackingCode = $this->getMeta('trackingCode');

            $response = $client->request("POST", "{$this->gateUrl}/purchases/deliver?type={$_POST['type']}", [
                "json" => [
                    'deliveryDate'  => round(microtime(true) * 1000),
                    'invoiceNumber' => mt_rand(10000000, 999999999),
                    'trackingCode'  => $trackingCode,
                    'products'      => $products,
                ],

                "headers" => [
                    'Authorization' => "Bearer " . $this->getMeta('access_token'),
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = $response->getBody()->getContents();

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response);
                throw new DigiPayException($response, $statusCode);
            }

            $this->trackingCode = $trackingCode;
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
     * @throws DigiPayException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->authenticate();

            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/refunds?type={$meta['type']}", [
                "json" => [
                    'providerId'       => $meta['providerId'],
                    'amount'           => $meta['amount'],
                    'saleTrackingCode' => $meta['trackingCode'],
                ],

                "headers" => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = $response->getBody()->getContents();

            if ($statusCode != 200) {
                $this->newLog($statusCode, $response);
                throw new DigiPayException($response, $statusCode);
            }

            $this->newLog('Refunded', $response);

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Authenticate and obtain an access token.
     *
     * @return void
     *
     * @throws PoolPortException
     */
    protected function authenticate()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/oauth/token/", [
                "form_params" => [
                    'username'   => $this->config->get('digipay.username'),
                    'password'   => $this->config->get('digipay.password'),
                    'grant_type' => self::GRANT_TYPE_PASSWORD,
                ],
                'headers'     => [
                    'Authorization' => $this->generateSignature(),
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->newLog($statusCode, json_encode($response));
                throw new DigiPayException(json_encode($response), $statusCode);
            }

            $this->accessToken = $response->access_token;

            $this->setMeta([
                'access_token' => $this->accessToken
            ]);

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * generate a signature for authorization
     *
     * @return string
     */
    private function generateSignature()
    {
        $clientId = $this->config->get('digipay.client-id');
        $clientSecret = $this->config->get('digipay.client-secret');
        $key = base64_encode("{$clientId}:{$clientSecret}");

        return "Basic {$key}";
    }

    /**
     * add items to invoice
     *
     * @return $this
     */
    public function addItem($items)
    {
        $this->items = $items;

        return $this;
    }
}