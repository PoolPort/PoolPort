<?php

namespace PoolPort\Evano;

use PoolPort\Config;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Evano extends PortAbstract implements PortInterface
{
    const TIMEOUT = 30;

    const LOGIN_PATH = '/services/auth/v1.0/client/login';

    const PAYMENT_PATH = '/services/payment/pg/v1.0';

    const ORDER_INQUIRY_PATH = '/services/ecommerce/orders/v1.0/thirdparty';

    const ORDER_STATUS_PATH = '/services/ecommerce/order/v1.0/thirdparty';

    const CODE_OK = 200;

    const CODE_UNAUTHORIZED = 1002;

    /**
     * Token lifetime is 15 minutes from login, `expiresIn` of the response is not reliable
     */
    const TOKEN_TTL = 900;

    const TOKEN_EXPIRY_MARGIN = 60;

    const TRANSACTION_STATUS_RESERVED = 'RESERVED';

    const ORDER_STATUS_CANCELLED = 'CANCELLED';

    const ORDER_STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    const MSISDN_PATTERN = '/^9\d{9}$/';

    const NATIONAL_CODE_PATTERN = '/^\d{10}$/';

    /**
     * Number of latest error entries kept in transaction meta `error_logs`
     */
    const ERROR_LOGS_LIMIT = 20;

    /**
     * Order statuses that exist only after a successful Confirm
     *
     * @var array
     */
    private static $confirmedOrderStatuses = [
        'AWAITING_FULFILLMENT',
        'AWAITING_PICKUP',
        'AWAITING_SHIPMENT',
        'SHIPPED',
        'PARTIALLY_REFUNDED',
    ];

    /**
     * Login tokens per api url and client id, shared by all instances of the process
     *
     * @var array [key => ['token' => string, 'expires_at' => int]]
     */
    private static $tokens = [];

    /**
     * Address of payment page
     *
     * @var string
     */
    private $paymentUrl;

    /**
     * Order details
     *
     * @var array ['nationalCode' => string, 'code' => string|null, 'description' => string|null]
     */
    private $items = [];

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
     * Set order details
     *
     * @param array $items [
     *     'nationalCode' => string,       // National code of user, 10 digits
     *     'code'         => string|null,  // Order code in merchant system, transaction id when not set
     *     'description'  => string|null,
     * ]
     *
     * @return $this
     */
    public function addItem(array $items)
    {
        $this->items = $items;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->validateOrder();
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
     * Cancels the order when nothing is refunded yet, otherwise refunds the remaining amount
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    public function refundPayment($transaction, $params = [])
    {
        $this->assertRefundable($transaction, 'Refund');

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

        $status = $refundedAmount === 0 ? self::ORDER_STATUS_CANCELLED : self::ORDER_STATUS_PARTIALLY_REFUNDED;

        return $this->sendRefundRequest($status, $amount, $refundedAmount, 'Refund');
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
        $this->assertRefundable($transaction, 'Partial Refund');

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

        return $this->sendRefundRequest(self::ORDER_STATUS_PARTIALLY_REFUNDED, $amount, $refundedAmount, 'Partial Refund');
    }

    /**
     * Payload of create order request
     *
     * @return array
     */
    public function getPayRequestPayload()
    {
        $payload = [
            'amount'       => (int)$this->amount,
            'msisdn'       => $this->msisdn(),
            'nationalCode' => $this->item('nationalCode'),
            'callbackUrl'  => $this->callbackPath($this->buildRedirectUrl($this->config->get('evano.callback-url'))),
            'code'         => (string)$this->getMeta('code'),
            'trackId'      => (string)$this->getMeta('track_id'),
        ];

        if ($this->item('description') !== '') {
            $payload['description'] = $this->item('description');
        }

        return $payload;
    }

    /**
     * Order detail set by addItem, empty string when not set
     *
     * @param string $key
     *
     * @return string
     */
    private function item($key)
    {
        return isset($this->items[$key]) ? (string)$this->items[$key] : '';
    }

    /**
     * Validate user identity before creating the transaction
     *
     * @return void
     *
     * @throws EvanoException
     */
    protected function validateOrder()
    {
        if (!preg_match(self::MSISDN_PATTERN, $this->msisdn())) {
            $this->failOrderValidation('user-mobile must be a valid mobile number');
        }

        if (!preg_match(self::NATIONAL_CODE_PATTERN, $this->item('nationalCode'))) {
            $this->failOrderValidation('National code must be 10 digits');
        }

        if ((int)$this->amount <= 0) {
            $this->failOrderValidation('Amount must be greater than zero');
        }
    }

    /**
     * @param string $message
     *
     * @return void
     *
     * @throws EvanoException
     */
    private function failOrderValidation($message)
    {
        $this->logError('ValidateOrder', $message, ['context' => [
            'amount' => (int)$this->amount,
            'msisdn' => $this->msisdn(),
        ]]);

        throw new EvanoException($message, 400);
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
            $this->setMeta([
                'track_id'        => Uuid::uuid4()->toString(),
                'code'            => $this->item('code') !== '' ? $this->item('code') : (string)$this->transactionId(),
                'amount'          => $this->amount,
                'refunded_amount' => 0,
            ]);

            $payload = $this->getPayRequestPayload();
            $response = $this->request('POST', self::PAYMENT_PATH . '/order', $payload);
            $data = $this->resultData($response);

            if ($response['code'] !== self::CODE_OK || empty($data['id']) || empty($data['orderId'])) {
                $this->logError('CreateOrder', 'Create order failed', [
                    'response' => $response,
                    'context'  => ['request' => $payload],
                ]);
                throw new EvanoException($this->responseMessage($response), $this->exceptionCode($response));
            }

            $reserveId = (string)$data['id'];
            $this->paymentUrl = rtrim($this->config->get('evano.gateway-url'), '/') . '/?' . http_build_query(['reserveId' => $reserveId]);

            $this->setMeta([
                'reserve_id'  => $reserveId,
                'order_id'    => (string)$data['orderId'],
                'payment_url' => $this->paymentUrl,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $this->refId = $reserveId;
            $this->transactionSetRefId();

        } catch (EvanoException $e) {
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
     * Check callback, inquire payment by trackId and confirm the reserved transaction
     * Transport and server errors keep the transaction status so verify can be retried
     *
     * @return void
     *
     * @throws EvanoException
     * @throws PoolPortException
     */
    protected function verifyPayment()
    {
        $paymentId = $this->resolveCallbackPaymentId();
        $trackId = (string)$this->getMeta('track_id');

        $inquiry = $this->inquirePayment($trackId);
        $payment = $this->resultData($inquiry);

        $this->setMeta(['payment_inquiry' => $payment]);

        if ($inquiry['code'] !== self::CODE_OK || empty($payment['id'])) {
            $this->logError('PaymentInquiry', 'Payment inquiry failed', [
                'response' => $inquiry,
                'context'  => ['track_id' => $trackId],
            ]);
            throw new EvanoException($this->responseMessage($inquiry), $this->exceptionCode($inquiry));
        }

        if ((string)$payment['id'] !== $paymentId) {
            $this->failVerify('Callback paymentId does not match inquired transaction', $payment);
        }

        if (isset($payment['value']) && (int)$payment['value'] !== (int)$this->amount) {
            $this->failVerify('Paid amount does not match transaction amount', $payment);
        }

        if (isset($payment['orderId']) && (string)$payment['orderId'] !== (string)$this->getMeta('order_id')) {
            $this->failVerify('Inquired orderId does not match transaction', $payment);
        }

        $status = isset($payment['status']) ? (string)$payment['status'] : null;

        if ($status === self::TRANSACTION_STATUS_RESERVED) {
            $this->confirmPayment($paymentId);
        } elseif (!$this->isOrderConfirmed()) {
            $this->failVerify("Transaction status is {$status}", $payment);
        }

        $this->setMeta(['payment_id' => $paymentId]);
        $this->trackingCode = $paymentId;
        $this->transactionSucceed();
        $this->newLog(PortAbstract::TRANSACTION_SUCCEED, PortAbstract::TRANSACTION_SUCCEED_TEXT);
    }

    /**
     * Finalize reserved payment, without Confirm the amount returns to user wallet after 45 minutes
     *
     * @param string $paymentId
     *
     * @return void
     *
     * @throws EvanoException
     * @throws PoolPortException
     */
    protected function confirmPayment($paymentId)
    {
        $path = self::PAYMENT_PATH . '/confirm/' . rawurlencode((string)$this->getMeta('reserve_id')) . '/' . rawurlencode($paymentId);

        try {
            $response = $this->request('POST', $path);
        } catch (\Exception $e) {
            $this->logError('Confirm', 'Confirm request failed', [
                'exception' => $e,
                'context'   => ['path' => $path],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($response['code'] !== self::CODE_OK) {
            $this->transactionFailed();
            $this->logError('Confirm', 'Confirm failed', [
                'response' => $response,
                'context'  => ['path' => $path],
            ]);
            throw new EvanoException($this->responseMessage($response), $this->exceptionCode($response));
        }
    }

    /**
     * Inquire payment transaction by trackId
     *
     * @param string $trackId
     *
     * @return array
     *
     * @throws PoolPortException
     */
    protected function inquirePayment($trackId)
    {
        try {
            return $this->request('GET', self::PAYMENT_PATH . '/inquiry', ['trackId' => $trackId]);
        } catch (\Exception $e) {
            $this->logError('PaymentInquiry', 'Payment inquiry request failed', [
                'exception' => $e,
                'context'   => ['track_id' => $trackId],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Inquire order by Evano order id
     *
     * @return array|null first order of the result, null when not found
     *
     * @throws EvanoException
     * @throws PoolPortException
     */
    protected function inquireOrder()
    {
        $orderId = (string)$this->getMeta('order_id');

        try {
            $response = $this->request('GET', self::ORDER_INQUIRY_PATH, ['id' => $orderId]);
        } catch (\Exception $e) {
            $this->logError('OrderInquiry', 'Order inquiry request failed', [
                'exception' => $e,
                'context'   => ['order_id' => $orderId],
            ]);
            throw new PoolPortException($e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($response['code'] !== self::CODE_OK) {
            $this->logError('OrderInquiry', 'Order inquiry failed', [
                'response' => $response,
                'context'  => ['order_id' => $orderId],
            ]);
            throw new EvanoException($this->responseMessage($response), $this->exceptionCode($response));
        }

        $data = $this->resultData($response);

        return !empty($data['result'][0]) ? $data['result'][0] : null;
    }

    /**
     * Whether a previous Confirm has already finalized the order, for retried verify requests
     *
     * @return bool
     *
     * @throws EvanoException
     * @throws PoolPortException
     */
    protected function isOrderConfirmed()
    {
        $order = $this->inquireOrder();
        $status = isset($order['status']) ? (string)$order['status'] : null;

        $this->setMeta(['order_status' => $status]);

        return in_array($status, self::$confirmedOrderStatuses, true);
    }

    /**
     * Send order status update for cancel or partial refund
     * refundAmount is only sent with PARTIALLY_REFUNDED status
     *
     * @param string $status
     * @param int $amount
     * @param int $refundedAmount
     * @param string $logCode
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    protected function sendRefundRequest($status, $amount, $refundedAmount, $logCode)
    {
        $refundRequest = ['status' => $status];

        if ($status === self::ORDER_STATUS_PARTIALLY_REFUNDED) {
            $refundRequest['refundAmount'] = $amount;
        }

        $path = self::ORDER_STATUS_PATH . '/' . rawurlencode((string)$this->getMeta('order_id')) . '/status';

        try {
            $response = $this->request('PUT', $path, $refundRequest);

            if ($response['code'] !== self::CODE_OK) {
                $this->logError($logCode, 'Refund request rejected', [
                    'response' => $response,
                    'context'  => ['request' => $refundRequest],
                ]);
                throw new EvanoException($this->responseMessage($response), $this->exceptionCode($response));
            }

            $refunds = (array)$this->getMeta('refunds');
            $refunds[] = [
                'status'      => $status,
                'amount'      => $amount,
                'request_id'  => isset($response['body']['meta']['transactionId']) ? $response['body']['meta']['transactionId'] : null,
                'refunded_at' => date('Y-m-d H:i:s'),
            ];

            $this->setMeta([
                'refunded_amount' => $refundedAmount + $amount,
                'refunds'         => $refunds,
            ]);

            $this->newLog($logCode, json_encode($response['body'], JSON_UNESCAPED_UNICODE));

            return true;

        } catch (EvanoException $e) {
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
     * Only succeeded transactions with an Evano order can be refunded
     *
     * @param object $transaction
     * @param string $logCode
     *
     * @return void
     *
     * @throws PoolPortException
     */
    private function assertRefundable($transaction, $logCode)
    {
        if ((int)$transaction->status !== PortAbstract::TRANSACTION_SUCCEED) {
            $this->logError($logCode, 'Transaction is not succeed', ['context' => ['status' => (int)$transaction->status]]);
            throw new PoolPortException('Transaction is not succeed', 400);
        }

        if (empty($this->getMeta('order_id'))) {
            $this->logError($logCode, 'Evano order id is missing');
            throw new PoolPortException('Evano order id is missing', 400);
        }
    }

    /**
     * Validate callback parameters and return paymentId of a successful payment
     *
     * @return string
     *
     * @throws EvanoException
     */
    protected function resolveCallbackPaymentId()
    {
        if (isset($_GET['userCancelled']) && $_GET['userCancelled'] === 'true') {
            $this->failCallback('User cancelled payment');
        }

        if (!isset($_GET['verified']) || $_GET['verified'] !== 'true') {
            $errorCode = isset($_GET['errorCode']) ? $_GET['errorCode'] : '';
            $this->failCallback("Payment is not verified (errorCode: {$errorCode})");
        }

        if (empty($_GET['paymentId'])) {
            $this->failCallback('Callback paymentId is missing');
        }

        return (string)$_GET['paymentId'];
    }

    /**
     * @param string $message
     *
     * @return void
     *
     * @throws EvanoException
     */
    private function failCallback($message)
    {
        $this->transactionFailed();
        $this->logError('Callback', $message, ['context' => [
            'callback'   => $_GET,
            'track_id'   => $this->getMeta('track_id'),
            'reserve_id' => $this->getMeta('reserve_id'),
        ]]);
        throw new EvanoException($message, 400);
    }

    /**
     * @param string $message
     * @param array|null $payment
     *
     * @return void
     *
     * @throws EvanoException
     */
    private function failVerify($message, $payment)
    {
        $this->transactionFailed();
        $this->logError('Verify', $message, ['context' => [
            'callback' => $_GET,
            'payment'  => $payment,
            'order_id' => $this->getMeta('order_id'),
        ]]);
        throw new EvanoException($message, 400);
    }

    /**
     * Mobile in 9xxxxxxxxx format, config accepts 09xxxxxxxxx and 989xxxxxxxxx too
     *
     * @return string
     */
    private function msisdn()
    {
        $mobile = preg_replace('/\D/', '', (string)$this->config->get('evano.user-mobile'));

        return substr($mobile, -10);
    }

    /**
     * Evano expects callbackUrl as a path without domain
     *
     * @param string $url
     *
     * @return string
     */
    private function callbackPath($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (empty($path) ? '/' : $path) . (empty($query) ? '' : '?' . $query);
    }

    /**
     * Cached login token, renewed TOKEN_EXPIRY_MARGIN seconds before 15 minutes expiry
     *
     * @param bool $refresh
     *
     * @return string
     *
     * @throws EvanoException
     */
    private function token($refresh = false)
    {
        $key = $this->config->get('evano.base-url') . '|' . $this->config->get('evano.client-id');

        if (!$refresh && isset(self::$tokens[$key]) && self::$tokens[$key]['expires_at'] > time()) {
            return self::$tokens[$key]['token'];
        }

        unset(self::$tokens[$key]);

        $response = $this->send('GET', self::LOGIN_PATH, [], [
            'clientId'     => $this->config->get('evano.client-id'),
            'clientSecret' => $this->config->get('evano.client-secret'),
            'scope'        => $this->config->get('evano.scope'),
        ]);
        $data = $this->resultData($response);

        if ($response['code'] !== self::CODE_OK || empty($data['token'])) {
            $this->logError('Login', 'Login failed', ['response' => $response]);
            throw new EvanoException($this->responseMessage($response), $this->exceptionCode($response));
        }

        self::$tokens[$key] = [
            'token'      => (string)$data['token'],
            'expires_at' => time() + self::TOKEN_TTL - self::TOKEN_EXPIRY_MARGIN,
        ];

        return self::$tokens[$key]['token'];
    }

    /**
     * Send an authorized request, logs in again and retries once on Unauthorized
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data JSON body, query string for GET requests
     *
     * @return array ['statusCode' => int, 'code' => int|null, 'body' => array|null, 'raw' => string]
     *
     * @throws EvanoException
     */
    private function request($method, $endpoint, array $data = [])
    {
        $response = $this->send($method, $endpoint, $data, ['Authorization' => 'Bearer ' . $this->token()]);

        if ($response['code'] === self::CODE_UNAUTHORIZED) {
            $response = $this->send($method, $endpoint, $data, ['Authorization' => 'Bearer ' . $this->token(true)]);
        }

        return $response;
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @param array $headers
     *
     * @return array ['statusCode' => int, 'code' => int|null, 'body' => array|null, 'raw' => string]
     */
    private function send($method, $endpoint, array $data = [], array $headers = [])
    {
        $client = new Client();

        $options = [
            'headers'     => array_merge(['Accept' => 'application/json'], $headers),
            'timeout'     => self::TIMEOUT,
            'http_errors' => false,
        ];

        if ($method === 'GET') {
            if (!empty($data)) {
                $options['query'] = $data;
            }
        } elseif (!empty($data)) {
            $options['json'] = $data;
        }

        $response = $client->request($method, rtrim($this->config->get('evano.base-url'), '/') . $endpoint, $options);
        $raw = (string)$response->getBody();
        $body = json_decode($raw, true);

        return [
            'statusCode' => $response->getStatusCode(),
            'code'       => $this->resultCode($body),
            'body'       => $body,
            'raw'        => $raw,
        ];
    }

    /**
     * result.status.code is the decision basis, not HTTP status
     *
     * @param mixed $body
     *
     * @return int|null
     */
    private function resultCode($body)
    {
        if (isset($body['result']['status']['code'])) {
            return (int)$body['result']['status']['code'];
        }

        if (isset($body['status']['code'])) {
            return (int)$body['status']['code'];
        }

        return null;
    }

    /**
     * @param array $response
     *
     * @return array|null
     */
    private function resultData(array $response)
    {
        return isset($response['body']['result']['data']) && is_array($response['body']['result']['data'])
            ? $response['body']['result']['data']
            : null;
    }

    /**
     * @param array $response
     *
     * @return int
     */
    private function exceptionCode(array $response)
    {
        return $response['code'] !== null ? $response['code'] : $response['statusCode'];
    }

    /**
     * Exception message of a failed Evano response, raw body when it is not JSON
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
            $code = isset($details['code']) ? $details['code'] : $this->exceptionCode($response);
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
            error_log('PoolPort Evano error: ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        try {
            $this->newLog($code, "{$action}: {$message}");

            $errorLogs = (array)$this->getMeta('error_logs');
            $errorLogs[] = $entry;

            $this->setMeta(['error_logs' => array_slice($errorLogs, -self::ERROR_LOGS_LIMIT)]);
        } catch (\Exception $e) {
            error_log('PoolPort Evano error (log write failed: ' . $e->getMessage() . '): ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
