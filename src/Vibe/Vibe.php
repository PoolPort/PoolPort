<?php

namespace PoolPort\Vibe;

use PoolPort\Config;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Vibe extends PortAbstract implements PortInterface
{
    const TIMEOUT = 30;

    const SERVICES_PATH = '/cpg-added/api/v1/services';

    const REFUND_STATUS_DONE = 'DONE';

    /**
     * Number of latest error entries kept in transaction meta `error_logs`
     */
    const ERROR_LOGS_LIMIT = 20;

    /**
     * Refund statuses that mean Vibe rejected the refund request
     *
     * @var array
     */
    private static $failedRefundStatuses = ['FAILED', 'AMOUNT_NOT_ENOUGH', 'SALE_NOT_REFUNDABLE'];

    /**
     * Address of payment page returned by create order
     *
     * @var string
     */
    private $paymentUrl;

    /**
     * Cart details sent as `data` (goods_amount, tax, items)
     *
     * @var array
     */
    private $cart = [];

    /**
     * Cart amount with installment markup, including tax
     *
     * @var int|null
     */
    private $cartAmountInstallment = null;

    /**
     * Number of installments, only for dynamic installment contracts
     *
     * @var int|null
     */
    private $installments = null;

    /**
     * Order id sent to Vibe, stored in transaction meta as `order_id`
     *
     * @var string|null
     */
    private $orderId = null;

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
        $this->validateCart();
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        header("Location: {$this->paymentUrl}");
        exit();
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
     * Refund remaining amount of user payment
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    public function refundPayment($transaction, $params = [])
    {
        $refundedAmount = (int)$this->getMeta('refunded_amount');
        $amount = (int)$transaction->price - $refundedAmount;

        if ($amount <= 0) {
            $this->logError('Refund', 'Refund amount is not valid', ['context' => [
                'price'           => (int)$transaction->price,
                'refunded_amount' => $refundedAmount,
                'amount'          => $amount,
            ]]);
            throw new PoolPortException('Refund amount is not valid', 400);
        }

        return $this->sendRefundRequest($amount, $refundedAmount, 'Refund');
    }

    /**
     * Partial refund user payment
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        $refundedAmount = (int)$this->getMeta('refunded_amount');
        $amount = (int)$amount;

        if ($amount <= 0 || $refundedAmount + $amount > (int)$transaction->price) {
            $this->logError('Partial Refund', 'Partial refund amount is not valid', ['context' => [
                'price'           => (int)$transaction->price,
                'refunded_amount' => $refundedAmount,
                'amount'          => $amount,
            ]]);
            throw new PoolPortException('Partial refund amount is not valid', 400);
        }

        return $this->sendRefundRequest($amount, $refundedAmount, 'Partial Refund');
    }

    /**
     * Set cart details
     *
     * @param array $cart ['goods_amount' => int, 'tax' => int, 'items' => [['id', 'name', 'price', 'quantity', 'discount', 'category'?], ...]]
     *
     * @return $this
     */
    public function addItem(array $cart)
    {
        $this->cart = $cart;

        return $this;
    }

    /**
     * Set installment details, only for dynamic installment contracts
     *
     * @param int $cartAmountInstallment cart amount with markup, including tax
     * @param int|null $installments
     *
     * @return $this
     */
    public function setInstallments($cartAmountInstallment, $installments = null)
    {
        $this->cartAmountInstallment = $cartAmountInstallment;
        $this->installments = $installments;

        return $this;
    }

    /**
     * Payload of create order request
     *
     * @return array
     */
    public function getPayRequestPayload()
    {
        $payload = [
            'order_id'     => $this->orderId,
            'cart_amount'  => (int)$this->amount,
            'callback_url' => $this->buildRedirectUrl($this->config->get('vibe.callback-url')),
            'data'         => $this->cart,
        ];

        if (!is_null($this->cartAmountInstallment)) {
            $payload['cart_amount_installment'] = (int)$this->cartAmountInstallment;
        }

        if (!is_null($this->installments)) {
            $payload['installments'] = (int)$this->installments;
        }

        $storeCode = $this->config->get('vibe.store-code');
        if (!empty($storeCode)) {
            $payload['store_code'] = $storeCode;
        }

        return $payload;
    }

    /**
     * Validate cart before creating the transaction
     *
     * @return void
     *
     * @throws VibeException
     */
    protected function validateCart()
    {
        if (!isset($this->cart['goods_amount'], $this->cart['tax'])) {
            $this->failCartValidation('goods_amount and tax are required');
        }

        if (empty($this->cart['items']) || !is_array($this->cart['items'])) {
            $this->failCartValidation('At least one item is required');
        }

        if ((int)$this->amount !== (int)$this->cart['goods_amount'] + (int)$this->cart['tax']) {
            $this->failCartValidation('cart_amount must equal goods_amount + tax');
        }
    }

    /**
     * @param string $message
     *
     * @return void
     *
     * @throws VibeException
     */
    private function failCartValidation($message)
    {
        $this->logError('ValidateCart', $message, ['context' => [
            'cart_amount' => (int)$this->amount,
            'cart'        => $this->cart,
        ]]);

        throw new VibeException($message, 400);
    }

    /**
     * Send create order request to server
     *
     * @return void
     *
     * @throws PoolPortException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();
        $payload = null;

        try {
            $this->orderId = Uuid::uuid4()->toString();
            $this->setMeta([
                'order_id'        => $this->orderId,
                'amount'          => $this->amount,
                'refunded_amount' => 0,
            ]);

            $payload = $this->getPayRequestPayload();
            $response = $this->request('POST', '/api/v1/orders/', $payload);

            if ($response['statusCode'] !== 200 || empty($response['body']['payment_url'])) {
                $this->logError('CreateOrder', 'Create order failed', [
                    'response' => $response,
                    'context'  => ['request' => $payload],
                ]);
                throw new VibeException($this->responseMessage($response), $response['statusCode']);
            }

            $this->paymentUrl = $response['body']['payment_url'];
            $paymentToken = $this->extractPaymentToken($this->paymentUrl);

            $this->setMeta([
                'payment_url'   => $this->paymentUrl,
                'payment_token' => $paymentToken,
                'created_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            $this->refId = $paymentToken !== null ? $paymentToken : (string)$this->transactionId();
            $this->transactionSetRefId();

        } catch (VibeException $e) {
            $this->transactionFailed();
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->logError('CreateOrder', 'Create order request failed', [
                'exception' => $e,
                'context'   => ['request' => $payload],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Verify order on server, the only source of payment result
     * Transport and server errors keep the transaction status so verify can be retried
     *
     * @return void
     *
     * @throws VibeException
     * @throws PoolPortException
     */
    protected function verifyPayment()
    {
        $vibeRefId = $this->resolveCallbackRefId();

        $verifyRequest = ['ref_id' => $vibeRefId];

        try {
            $response = $this->request('POST', '/merchants/api/v1/orders/verify', $verifyRequest);
        } catch (\Exception $e) {
            $this->logError('Verify', 'Verify order request failed', [
                'exception' => $e,
                'context'   => ['request' => $verifyRequest],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($response['statusCode'] !== 200) {
            $this->logError('Verify', 'Verify order failed', [
                'response' => $response,
                'context'  => ['request' => $verifyRequest],
            ]);
            throw new VibeException($this->responseMessage($response), $response['statusCode']);
        }

        if (empty($response['body']['status'])) {
            $callbackResult = isset($_GET['result']) ? $_GET['result'] : '';
            $message = "Order is not finalized (callback result: {$callbackResult})";

            $this->transactionFailed();
            $this->logError('Verify', $message, [
                'code'     => 'NOK',
                'response' => $response,
                'context'  => ['request' => $verifyRequest, 'callback' => $_GET],
            ]);
            throw new VibeException($message, 400);
        }

        $this->refId = $vibeRefId;
        $this->transactionSetRefId();
        $this->trackingCode = $vibeRefId;
        $this->transactionSucceed();
        $this->newLog(PortAbstract::TRANSACTION_SUCCEED, PortAbstract::TRANSACTION_SUCCEED_TEXT);
    }

    /**
     * Send refund request to server and confirm it by refund status
     * A 200 response only means the request is accepted, pending statuses are kept in meta for re-inquiry
     * refunded_amount includes pending refunds, because Vibe counts them against the remaining amount
     *
     * @param int $amount
     * @param int $refundedAmount
     * @param string $logCode
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    protected function sendRefundRequest($amount, $refundedAmount, $logCode)
    {
        $refundRequest = null;

        try {
            $requestId = Uuid::uuid4()->toString();
            $refundRequest = [
                'request_id'        => $requestId,
                'refunded_order_id' => (string)$this->getMeta('order_id'),
                'refunded_amount'   => $amount,
            ];

            $response = $this->request('POST', self::SERVICES_PATH . '/refund', $refundRequest);

            if ($response['statusCode'] !== 200) {
                $this->logError($logCode, 'Refund request rejected', [
                    'response' => $response,
                    'context'  => ['request' => $refundRequest],
                ]);
                throw new VibeException($this->responseMessage($response), $response['statusCode']);
            }

            $this->newLog('RefundAccepted', json_encode($response['body'], JSON_UNESCAPED_UNICODE));

            $refundStatus = $this->getRefundStatus($requestId);
            $status = $refundStatus['status'] ?? null;

            if (in_array($status, self::$failedRefundStatuses, true)) {
                $message = "Refund {$requestId} failed with status {$status}";
                $this->logError($logCode, $message, [
                    'context' => [
                        'request'       => $refundRequest,
                        'accepted'      => $response['body'],
                        'refund_status' => $refundStatus['body'],
                    ],
                ]);
                throw new VibeException($message, 400);
            }

            $refunds = (array)$this->getMeta('refunds');
            $refunds[] = [
                'request_id' => $requestId,
                'amount'     => $amount,
                'status'     => $status,
                'accepted'   => $response['body'],
                'data'       => $refundStatus['body'] ?? $refundStatus['data'] ?? null,
            ];

            $this->setMeta([
                'refunded_amount' => $refundedAmount + $amount,
                'refunds'         => $refunds,
            ]);

            $this->newLog(
                $status === self::REFUND_STATUS_DONE ? $logCode : 'RefundPending',
                json_encode($refundStatus['body'] ?? $refundStatus['data'] ?? null, JSON_UNESCAPED_UNICODE)
            );

            return true;

        } catch (VibeException $e) {
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        } catch (\Exception $e) {
            $this->logError($logCode, 'Refund request failed', [
                'exception' => $e,
                'context'   => ['request' => $refundRequest],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Inquire refund status by request id
     *
     * @param string $requestId
     *
     * @return array{status: string|null, data: array|null, body: array|null}
     */
    protected function getRefundStatus($requestId)
    {
        try {
            $response = $this->request('GET', self::SERVICES_PATH . '/refund/status/request_id/' . rawurlencode($requestId));
        } catch (\Exception $e) {
            $this->logError('RefundStatus', 'Refund status request failed', [
                'exception' => $e,
                'context'   => ['request_id' => $requestId],
            ]);
            return ['status' => null, 'data' => null, 'body' => null];
        }

        if ($response['statusCode'] !== 200 || empty($response['body']['data']['status'])) {
            $this->logError('RefundStatus', 'Refund status inquiry failed', [
                'response' => $response,
                'context'  => ['request_id' => $requestId],
            ]);
            return ['status' => null, 'data' => null, 'body' => $response['body'] ?? null];
        }

        return [
            'status' => (string)$response['body']['data']['status'],
            'data'   => $response['body']['data'],
            'body'   => $response['body'],
        ];
    }

    /**
     * Validate callback parameters against the transaction and return Vibe reference id
     * Vibe sends its own reference as ref_id and echoes our order id as order_id
     *
     * @return string
     *
     * @throws VibeException
     */
    protected function resolveCallbackRefId()
    {
        if (isset($_GET['order_id']) && (string)$_GET['order_id'] !== (string)$this->getMeta('order_id')) {
            $this->failCallback('Callback order_id does not match transaction');
        }

        if (empty($_GET['ref_id'])) {
            return (string)$this->refId;
        }

        $callbackRefId = (string)$_GET['ref_id'];
        $paymentToken = $this->getMeta('payment_token');

        if (!empty($paymentToken) && $callbackRefId !== (string)$paymentToken) {
            $this->failCallback('Callback ref_id does not match payment token');
        }

        return $callbackRefId;
    }

    /**
     * @param string $message
     *
     * @return void
     *
     * @throws VibeException
     */
    private function failCallback($message)
    {
        $this->transactionFailed();
        $this->logError('Callback', $message, ['context' => [
            'callback'      => $_GET,
            'order_id'      => $this->getMeta('order_id'),
            'payment_token' => $this->getMeta('payment_token'),
        ]]);
        throw new VibeException($message, 400);
    }

    /**
     * Return `t` query parameter of payment url
     *
     * @param string $paymentUrl
     *
     * @return string|null
     */
    private function extractPaymentToken($paymentUrl)
    {
        $query = parse_url($paymentUrl, PHP_URL_QUERY);

        if (empty($query)) {
            return null;
        }

        parse_str($query, $params);

        return !empty($params['t']) ? (string)$params['t'] : null;
    }

    /**
     * Send a request to Vibe
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data JSON body, ignored for GET requests
     *
     * @return array ['statusCode' => int, 'body' => array|null, 'raw' => string]
     */
    private function request($method, $endpoint, array $data = [])
    {
        $client = new Client();

        $options = [
            'headers'     => [
                'X-API-Key'    => $this->config->get('vibe.api-key'),
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'timeout'     => self::TIMEOUT,
            'http_errors' => false,
        ];

        if ($method === 'GET') {
            if (!empty($data)) {
                $options['query'] = $data;
            }
        } else {
            $options['json'] = $data;
        }

        $response = $client->request($method, rtrim($this->config->get('vibe.base-url'), '/') . $endpoint, $options);
        $raw = (string)$response->getBody();

        return [
            'statusCode' => $response->getStatusCode(),
            'body'       => json_decode($raw, true),
            'raw'        => $raw,
        ];
    }

    /**
     * Exception message of a failed Vibe response, raw body when it is not JSON
     *
     * @param array $response
     *
     * @return string
     */
    private function responseMessage(array $response)
    {
        if (is_array($response['body'])) {
            return json_encode($response['body'], JSON_UNESCAPED_UNICODE);
        }

        return $response['raw'] !== '' ? $response['raw'] : 'Empty response with HTTP status ' . $response['statusCode'];
    }

    /**
     * Log a short summary in poolport_status_log and keep full details in transaction meta `error_logs`
     * Without a transaction, details go to PHP error_log. Logging failures never hide the original error.
     *
     * @param string $action
     * @param string $message
     * @param array $details ['code' => string|int, 'response' => array, 'exception' => \Exception, 'context' => array]
     *
     * @return void
     */
    private function logError($action, $message, array $details = [])
    {
        $entry = [
            'action'    => $action,
            'message'   => $message,
            'logged_at' => date('Y-m-d H:i:s'),
        ];

        $code = isset($details['code']) ? $details['code'] : 'Error';

        if (isset($details['response'])) {
            $response = $details['response'];
            $code = isset($details['code']) ? $details['code'] : $response['statusCode'];
            $entry['status_code'] = $response['statusCode'];
            $entry['response'] = is_array($response['body']) ? $response['body'] : $response['raw'];
        }

        if (isset($details['exception'])) {
            $exception = $details['exception'];
            $entry['exception'] = [
                'class'   => get_class($exception),
                'message' => $exception->getMessage(),
                'code'    => $exception->getCode(),
            ];
        }

        if (!empty($details['context'])) {
            $entry['context'] = $details['context'];
        }

        if ($this->transactionId() === null) {
            error_log('PoolPort Vibe error: ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        try {
            $this->newLog($code, "{$action}: {$message}");

            $errorLogs = (array)$this->getMeta('error_logs');
            $errorLogs[] = $entry;

            $this->setMeta(['error_logs' => array_slice($errorLogs, -self::ERROR_LOGS_LIMIT)]);
        } catch (\Exception $e) {
            error_log('PoolPort Vibe error (log write failed: ' . $e->getMessage() . '): ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
