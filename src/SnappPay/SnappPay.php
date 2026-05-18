<?php

namespace PoolPort\SnappPay;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use GuzzleHttp\Exception\ConnectException;

class SnappPay extends PortAbstract implements PortInterface
{
    const GRANT_TYPE_PASSWORD = 'password';
    const SCOPE               = 'online-merchant';
    const COMMISSION_TYPE     = 100;

    const STATUS_SETTLE  = 'SETTLE';
    const STATUS_VERIFY  = 'VERIFY';
    const STATUS_PENDING = 'PENDING';

    const TIMEOUT_STANDARD = 30;
    const TIMEOUT_STATUS   = 10;

    const MAX_RETRIES = 3;

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.snapppay.ir/api/online';

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
     * Execute HTTP request with automatic error handling
     *
     * @param string $method   HTTP method (GET, POST, etc)
     * @param string $endpoint API endpoint path
     * @param array  $data     Request body data
     * @param int    $timeout  Request timeout in seconds
     *
     * @return array Parsed response
     * @throws SnappPayException
     */
    private function request($method, $endpoint, array $data = [], $timeout = self::TIMEOUT_STANDARD)
    {
        try {
            $client = new Client();
            $url = $this->gateUrl . $endpoint;

            $options = [
                'headers' => [
                    'Authorization' => "Bearer " . $this->getMeta('access_token'),
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => $timeout,
            ];

            if ($method === 'GET') {
                $options['query'] = $data;
            } else {
                $options['json'] = $data;
            }

            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            return array_merge($body, ['statusCode' => $statusCode]);

        } catch (\Exception $e) {
            $this->newLog('RequestError', $e->getMessage());
            throw $e;
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
     * Implements retry logic with status fallback as per documentation
     *
     * @return array
     * @throws SnappPayException
     */
    protected function verifyPayment()
    {
        if (!$this->validateCallbackData()) {
            $this->transactionFailed();
            throw new SnappPayException('Payment failed or invalid callback data', 400);
        }

        return $this->executeWithRetry(
            function () {
                return $this->request('POST', '/payment/v1/verify', [
                    'paymentToken' => $this->refId,
                ], self::TIMEOUT_STANDARD);
            },
            function ($status) {
                if ($status === self::STATUS_VERIFY) {
                    return true;
                }

                if ($status === self::STATUS_PENDING) {
                    return null;
                }

                return false;
            }
        );
    }

    /**
     * Validate callback data from payment gateway
     *
     * @return bool
     */
    private function validateCallbackData()
    {
        return !empty($_POST['state']) && $_POST['state'] === 'OK' && !empty($_POST['transactionId']);
    }

    /**
     * Execute operation with retry logic and status fallback
     * According to SnappPay documentation for VERIFY and SETTLE flows
     *
     * @param callable $operation      The operation to execute (POST request)
     * @param callable $statusDecision Callback to decide action based on status
     *                                 Returns: true = success, false = fail, null = retry
     * @param bool     $isSettleOp     Whether this is a settle operation (affects success handling)
     *
     * @return array The response from the operation
     * @throws SnappPayException
     */
    private function executeWithRetry(callable $operation, callable $statusDecision, $isSettleOp = false)
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $operation();

                if ($this->isSuccessfulResponse($response)) {
                    if ($isSettleOp) {
                        $this->markSettleSuccess($response);
                    }
                    return $response;
                }

                $this->newLog('OperationFailed', json_encode($response));
                $statusResp = $this->getPaymentStatus($this->refId);
                $decision = $this->makeDecision($statusResp, $statusDecision, "attempt $attempt");

                if ($decision === true) {
                    // Success via status
                    if ($isSettleOp) {
                        $this->markSettleSuccess($statusResp);
                    }

                    return $statusResp;
                }

                if ($decision === null) {
                    // Retry
                    continue;
                }

                // Failed
                $this->transactionFailed();
                throw new SnappPayException('Operation failed: ' . json_encode($statusResp), 400);

            } catch (ConnectException $e) {
                // Timeout: check status
                return $this->handleTimeout($statusDecision, $isSettleOp, "attempt $attempt");

            } catch (\Exception $e) {
                if ($this->isTimeoutError($e)) {
                    return $this->handleTimeout($statusDecision, $isSettleOp, "attempt $attempt");
                }

                $this->transactionFailed();
                $this->newLog('Error', $e->getMessage());

                throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
            }
        }

        // Exhausted retries
        $this->transactionFailed();

        throw new SnappPayException('Operation failed after ' . self::MAX_RETRIES . ' retries', 500);
    }

    /**
     * Check if response indicates successful operation
     *
     * @param array $response
     *
     * @return bool
     */
    private function isSuccessfulResponse(array $response)
    {
        return isset($response['statusCode'])
            ? $response['statusCode'] === 200 && !empty($response['successful'])
            : (isset($response['successful']) && $response['successful']);
    }

    /**
     * Mark settle operation as successful and update tracking code
     *
     * @param array $response
     *
     * @return void
     */
    private function markSettleSuccess(array $response)
    {
        $this->trackingCode = isset($response['response']['transactionId'])
            ? $response['response']['transactionId']
            : null;

        $this->transactionSucceed();
    }

    /**
     * Get payment status using the Status Payment Get endpoint
     * GET: /payment/v1/status?paymentToken=[payment_token]
     *
     * @param string $paymentToken
     *
     * @return array
     * @throws PoolPortException
     */
    protected function getPaymentStatus($paymentToken)
    {
        try {
            $response = $this->request('GET', '/payment/v1/status', [
                'paymentToken' => $paymentToken,
            ], self::TIMEOUT_STATUS);

            if (!$this->isSuccessfulResponse($response)) {
                $this->newLog('StatusQueryFailed', json_encode($response));
                $statusCode = isset($response['statusCode']) ? $response['statusCode'] : 400;
                throw new SnappPayException(json_encode($response), $statusCode);
            }

            return $response;

        } catch (SnappPayException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->newLog('StatusQueryError', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Make decision based on status and decision callback
     *
     * @param array    $statusResp     Status response
     * @param callable $statusDecision Decision callback
     * @param string   $context        Logging context
     *
     * @return bool|null true = success, false = fail, null = retry
     */
    private function makeDecision(array $statusResp, callable $statusDecision, $context = '')
    {
        $status = $this->extractStatus($statusResp);
        $this->newLog('StatusCheck', "Status: $status at $context");

        return $statusDecision($status);
    }

    /**
     * Extract and normalize payment status from response
     *
     * @param array $response
     *
     * @return string|null
     */
    private function extractStatus(array $response)
    {
        return isset($response['response']['status'])
            ? strtoupper($response['response']['status'])
            : null;
    }

    /**
     * Handle timeout by checking payment status and making decision
     *
     * @param callable $statusDecision
     * @param bool     $isSettleOp
     * @param string   $context
     *
     * @return array
     * @throws SnappPayException
     */
    private function handleTimeout(callable $statusDecision, $isSettleOp = false, $context = '')
    {
        $this->newLog('Timeout', $context);

        $statusResp = $this->getPaymentStatus($this->refId);
        $decision = $this->makeDecision($statusResp, $statusDecision, "timeout at $context");

        if ($decision === true) {
            if ($isSettleOp) {
                $this->markSettleSuccess($statusResp);
            }

            return $statusResp;
        }

        if ($decision === null) {
            // Retry
            return $this->executeWithRetry(
                function () {
                    return $this->request('POST', '/payment/v1/settle', [
                        'paymentToken' => $this->refId,
                    ], self::TIMEOUT_STANDARD);
                },
                $statusDecision,
                $isSettleOp
            );
        }

        $this->transactionFailed();
        $this->newLog('StatusAfterTimeout', json_encode($statusResp));

        throw new SnappPayException('Operation failed after timeout: ' . json_encode($statusResp), 400);
    }

    /**
     * Check if exception is a timeout error
     *
     * @param \Exception $e
     *
     * @return bool
     */
    private function isTimeoutError(\Exception $e)
    {
        $msg = strtolower($e->getMessage());

        return strpos($msg, 'timed out') !== false || strpos($msg, 'timeout') !== false;
    }

    /**
     * Settle the payment after verify
     * Implements retry logic with status fallback as per documentation
     *
     * @return array
     * @throws SnappPayException
     */
    protected function settlePayment()
    {
        return $this->executeWithRetry(
            function () {
                return $this->request('POST', '/payment/v1/settle', [
                    'paymentToken' => $this->refId,
                ], self::TIMEOUT_STANDARD);
            },
            function ($status) {
                if ($status === self::STATUS_VERIFY) {
                    // Back to VERIFY -> retry settle
                    return null;
                }
                if ($status === self::STATUS_SETTLE) {
                    // Successfully settled
                    return true;
                }
                // Other statuses -> fail
                return false;
            },
            true // Mark as settle operation
        );
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