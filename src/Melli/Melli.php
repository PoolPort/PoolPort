<?php

namespace PoolPort\Melli;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Melli extends PortAbstract implements PortInterface
{
    const GRANT_TYPE_PASSWORD = 'password';
    const SCOPE               = 'online-merchant';
    const COMMISSION_TYPE     = 100;
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://fms-gateway-staging.apps.public.okd4.teh-1.snappcloud.io/api/online';

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

            $response = $client->request("POST", "{$this->gateUrl}/v1/oauth/token", [
                "form_params" => [
                    'username'   => $this->config->get('snappPay.username'),
                    'password'   => $this->config->get('snappPay.password'),
                    'grant_type' => self::GRANT_TYPE_PASSWORD,
                    'scope'      => self::SCOPE,
                ],
                'headers'     => [
                    'Authorization' => $this->generateSignature(),
                    'Content-Type'  => 'application/x-www-form-urlencoded'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode != 200) {
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            $this->accessToken = $response['access_token'];

            $this->setMeta(['access_token' => $this->accessToken]);

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
        $clientId = $this->config->get('snappPay.client_id');
        $clientSecret = $this->config->get('snappPay.client_secret');
        $key = base64_encode("{$clientId}:{$clientSecret}");

        return "Basic {$key}";
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws SnappPayException
     */
    protected function sendPayRequest()
    {
        try {
            $client = new Client();

            $response = $client->request('POST', "{$this->gateUrl}/payment/v1/token", [
                'json'    => $this->getPayRequestPayload(),
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 || empty($response['successful'])) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            $this->paymentUri = $response['response']['paymentPageUrl'];
            $this->refId = $response['response']['paymentToken'];
            $this->transactionSetRefId();

            $this->setMeta([
                'paymentToken' => $this->refId,
                'amount'       => $this->amount
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getPayRequestPayload()
    {
        $cartList = [];

        $discountAmount = isset($this->items['discountAmount'])
            ? $this->items['discountAmount']
            : 0;

        $externalSourceAmount = isset($this->items['externalSourceAmount'])
            ? $this->items['externalSourceAmount']
            : 0;

        $transactionId = isset($this->items['transactionId'])
            ? $this->items['transactionId']
            : uniqid($this->transactionId());

        foreach ($this->items['cartList'] as $cart) {
            $cartItems = [];
            $cartItemsTotal = 0;

            foreach ($cart['cartItems'] as $item) {
                $itemTotal = $item['amount'] * $item['count'];
                $cartItemsTotal += $itemTotal;

                $cartItems[] = [
                    'id'             => $item['id'],
                    'amount'         => $item['amount'],
                    'category'       => $item['category'],
                    'count'          => $item['count'],
                    'name'           => $item['name'],
                    'commissionType' => isset($item['commissionType']) ? $item['commissionType'] : self::COMMISSION_TYPE,
                ];
            }

            $shippingAmount = isset($cart['shippingAmount'])
                ? $cart['shippingAmount']
                : 0;

            $taxAmount = isset($cart['taxAmount'])
                ? $cart['taxAmount']
                : 0;

            $totalAmount = $cartItemsTotal + $shippingAmount + $taxAmount;

            $cartList[] = [
                'cartId'             => $cart['cartId'],
                'cartItems'          => $cartItems,
                'isShipmentIncluded' => !empty($cart['isShipmentIncluded']),
                'isTaxIncluded'      => !empty($cart['isTaxIncluded']),
                'shippingAmount'     => $shippingAmount,
                'taxAmount'          => $taxAmount,
                'totalAmount'        => $totalAmount,
            ];
        }

        $payload = [
            'amount'               => (int)$this->amount,
            'cartList'             => $cartList,
            'discountAmount'       => $discountAmount,
            'externalSourceAmount' => $externalSourceAmount,
            'mobile'               => $this->getUserMobile(),
            'returnURL'            => $this->buildRedirectUrl($this->config->get('snappPay.callback-url')),
            'transactionId'        => (string)$transactionId,
        ];

        if (!empty($this->items['forcedPaymentMethodTypes'])) {
            $payload['forcedPaymentMethodTypes'] = $this->items['forcedPaymentMethodTypes'];
        }

        return $payload;
    }

    private function getUserMobile()
    {
        $mobile = $this->config->get('snappPay.user-mobile');

        if (strpos($mobile, '0') === 0) {
            $mobile = '+98' . substr($mobile, 1);
        }

        return $mobile;
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
        $this->settlePayment();

        return $this;
    }

    /**
     * Verify user payment from snappPay server
     *
     * @return bool
     *
     * @throws SnappPayException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            if (empty($_POST['state']) || $_POST['state'] !== 'OK' || empty($_POST['transactionId'])) {
                $this->transactionFailed();
                throw new SnappPayException('Payment failed or invalid callback data', 400);
            }

            $response = $client->request('POST', "{$this->gateUrl}/payment/v1/verify", [
                    'json'    => [
                        'paymentToken' => $this->refId,
                    ],
                    'headers' => [
                        'Authorization' => "Bearer " . $this->getMeta('access_token'),
                        'Content-Type'  => 'application/json',
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 || empty($response['successful'])) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Settle the payment after verify
     *
     * @return bool
     *
     * @throws SnappPayException
     */
    protected function settlePayment()
    {
        try {
            $client = new Client();

            $response = $client->request('POST', "{$this->gateUrl}/payment/v1/settle", [
                    'json'    => [
                        'paymentToken' => $this->refId,
                    ],
                    'headers' => [
                        'Authorization' => "Bearer " . $this->getMeta('access_token'),
                        'Content-Type'  => 'application/json',
                    ],
                ]
            );


            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 || empty($response['successful'])) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            $this->trackingCode = $response['response']['transactionId'];
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
     * @throws SnappPayException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->authenticate();

            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request('POST', "{$this->gateUrl}/payment/v1/cancel", [
                    'json'    => [
                        'paymentToken' => $meta['paymentToken'],
                    ],
                    'headers' => [
                        'Authorization' => "Bearer {$this->accessToken}",
                        'Content-Type'  => 'application/json',
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 || empty($response['successful'])) {
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            $this->newLog('Canceled', json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Partial refund user payment
     *
     * @return bool
     *
     * @throws SnappPayException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $this->authenticate();

            $client = new Client();

            $payload = $this->getPartialRefundPayload($params, $transaction, $amount);

            $response = $client->request('POST', "{$this->gateUrl}/payment/v1/update", [
                    'json'    => $payload,
                    'headers' => [
                        'Authorization' => "Bearer {$this->accessToken}",
                        'Content-Type'  => 'application/json',
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode !== 200 || empty($response->successful)) {
                $this->newLog($statusCode, json_encode($response));
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            $this->newLog('PartialRefunded', json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getPartialRefundPayload($params, $transaction, $amount)
    {
        $meta = json_decode($transaction->meta, true);

        $cartList = [];
        $orderAmount = 0;

        $discountAmount = isset($params['discountAmount'])
            ? $params['discountAmount']
            : 0;

        $externalSourceAmount = isset($params['externalSourceAmount'])
            ? $params['externalSourceAmount']
            : 0;

        foreach ($params['cartList'] as $cart) {
            $cartItems = [];
            $cartItemsTotal = 0;

            foreach ($cart['cartItems'] as $item) {
                $itemTotal = $item['amount'] * $item['count'];
                $cartItemsTotal += $itemTotal;

                $cartItems[] = [
                    'id'             => $item['id'],
                    'amount'         => $item['amount'],
                    'category'       => $item['category'],
                    'count'          => $item['count'],
                    'name'           => $item['name'],
                    'commissionType' => isset($item['commissionType']) ? $item['commissionType'] : self::COMMISSION_TYPE,
                ];
            }

            $shippingAmount = isset($cart['shippingAmount'])
                ? $cart['shippingAmount']
                : 0;

            $taxAmount = isset($cart['taxAmount'])
                ? $cart['taxAmount']
                : 0;

            $totalAmount = $cartItemsTotal + $shippingAmount + $taxAmount;

            $cartList[] = [
                'cartId'             => $cart['cartId'],
                'cartItems'          => $cartItems,
                'isShipmentIncluded' => !empty($cart['isShipmentIncluded']),
                'isTaxIncluded'      => !empty($cart['isTaxIncluded']),
                'shippingAmount'     => $shippingAmount,
                'taxAmount'          => $taxAmount,
                'totalAmount'        => $totalAmount,
            ];
        }

        if ($amount >= $meta['amount']) {
            throw new SnappPayException('Update amount must be less than original order amount', 400);
        }

        return [
            'paymentToken'         => $meta['paymentToken'],
            'amount'               => (int)$amount,
            'cartList'             => $cartList,
            'discountAmount'       => $discountAmount,
            'externalSourceAmount' => $externalSourceAmount,
        ];
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