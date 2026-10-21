<?php

namespace PoolPort\Fadax;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Fadax extends PortAbstract implements PortInterface
{
    const PAYMENT_TOKEN_STATUS_SUCCEED = 1001;

    const CALLBACK_STATE_OK = 'ok';

    const VERIFY_STATUS_OK = 'ok';

    const PAYMENT_STATUS_COMPLETE = 'complete';

    protected $baseUrl = 'https://gateway.fadax.ir';

    /**
     * Api path prefix, appended to fadax.base-url
     *
     * @var string
     */
    protected $apiPrefix = 'supplier/v1';

    /**
     * Address of payment page returned by payment-token api
     *
     * @var string
     */
    private $paymentPageUrl;

    /**
     * Cart data, keys: cartItems (title, fee, count), shippingCost
     *
     * @var array
     */
    private $items = [];

    /**
     * @var Client
     */
    private $client;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $config, DataBaseManager $db, $portId)
    {
        parent::__construct($config, $db, $portId);

        $clientOptions = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ];

        $this->client = new Client($clientOptions);
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
     * Add cart items to payment request
     *
     * @param array $items
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
    public function redirect()
    {
        header("Location: {$this->paymentPageUrl}");
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
     * Get payment token from fadax server
     *
     * @return void
     *
     * @throws PoolPortException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $cartItems = $this->cartItems();
            $cartTotal = array_sum(array_column($cartItems, 'subTotal'));
            $shippingCost = isset($this->items['shippingCost']) ? (int)$this->items['shippingCost'] : 0;
            $totalAmount = (int)$this->amount;

            // Fadax rejects the request unless totalAmount == cartTotal + shippingCost - discountAmount
            $discountAmount = $cartTotal + $shippingCost - $totalAmount;

            if ($discountAmount < 0) {
                throw new FadaxException('مبلغ قابل پرداخت از جمع اقلام سبد خرید بیشتر است', -1);
            }

            $payload = [
                'cartItems'      => $cartItems,
                'cartTotal'      => $cartTotal,
                'discountAmount' => $discountAmount,
                'shippingCost'   => $shippingCost,
                'totalAmount'    => $totalAmount,
                'returnURL'      => $this->buildRedirectUrl($this->config->get('fadax.callback-url')),
            ];

            $mobile = $this->config->get('fadax.user-mobile');

            if (!empty($mobile)) {
                $payload['mobile'] = $mobile;
            }

            $response = $this->client->request('POST', $this->url('payment-token'), [
                'json'    => $payload,
                'headers' => $this->headers(),
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (empty($response->success)
                || !isset($response->response->status)
                || (int)$response->response->status !== self::PAYMENT_TOKEN_STATUS_SUCCEED
                || empty($response->response->paymentToken)
                || empty($response->response->transactionId)
                || empty($response->response->paymentPageURL)
            ) {
                $code = $this->extractErrorCode($response, $statusCode);
                $message = $this->extractErrorMessage($response, 'خطا در دریافت توکن پرداخت فدکس');
                $this->newLog($code, $message);
                throw new FadaxException($message, $code);
            }

            $this->paymentPageUrl = $response->response->paymentPageURL;
            $this->refId = $response->response->paymentToken;
            $this->transactionSetRefId();

            $this->setMeta([
                'paymentToken'   => $this->refId,
                'transactionId'  => $response->response->transactionId,
                'paymentPageURL' => $this->paymentPageUrl,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from fadax server
     *
     * @return object
     *
     * @throws PoolPortException
     */
    protected function verifyPayment()
    {
        try {
            $callback = $this->getCallbackData();

            $this->setMeta([
                'callback' => $callback,
            ]);

            if (array_key_exists('success', $callback) && $callback['success'] === false) {
                throw new FadaxException('پرداخت در فدکس ناموفق بوده است', -1);
            }

            if (($callback['state'] ?? null) !== self::CALLBACK_STATE_OK) {
                throw new FadaxException('پرداخت در فدکس ناموفق بوده است', -1);
            }

            $response = $this->client->request('POST', $this->url('verify'), [
                'json'    => [
                    'paymentToken' => $this->refId(),
                ],
                'headers' => $this->headers(),
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            $verified = !empty($response->success)
                && isset($response->response->status)
                && $response->response->status === self::VERIFY_STATUS_OK;

            // Verify fails for an already verified token (duplicate transaction), so the status api decides
            if (!$verified && $this->paymentStatus() !== self::PAYMENT_STATUS_COMPLETE) {
                $code = $this->extractErrorCode($response, $statusCode);
                $message = $this->extractErrorMessage($response, 'خطا در تایید پرداخت فدکس');
                $this->newLog($code, $message);
                throw new FadaxException($message, $code);
            }

            $this->trackingCode = !empty($callback['fadaxTrackingNumber'])
                ? $callback['fadaxTrackingNumber']
                : $this->refId();

            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get payment status from fadax server
     *
     * @return string|null complete, failed, pendVerify
     */
    protected function paymentStatus()
    {
        try {
            $response = $this->client->request('GET', $this->url('status'), [
                'query'   => [
                    'paymentToken' => $this->refId(),
                ],
                'headers' => $this->headers(),
            ]);

            $response = json_decode($response->getBody()->getContents());

            return isset($response->response->status) ? $response->response->status : null;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());

            return null;
        }
    }

    /**
     * Data posted by fadax to callback url
     *
     * @return array
     */
    protected function getCallbackData()
    {
        $data = $_POST;

        if (empty($data)) {
            $json = json_decode(file_get_contents('php://input'), true);
            $data = is_array($json) ? $json : [];
        }

        $success = null;

        if (array_key_exists('success', $data)) {
            $success = is_bool($data['success'])
                ? $data['success']
                : filter_var($data['success'], FILTER_VALIDATE_BOOLEAN);
        }

        $payload = (isset($data['response']) && is_array($data['response']))
            ? $data['response']
            : $data;

        $callback = [
            'success' => $success,
        ];

        foreach (['state', 'fadaxTrackingNumber', 'refId', 'transactionId'] as $key) {
            if (isset($payload[$key])) {
                $callback[$key] = (string)$payload[$key];
            } elseif (isset($data[$key])) {
                $callback[$key] = (string)$data[$key];
            } else {
                $callback[$key] = null;
            }
        }

        return $callback;
    }

    /**
     * Normalized cart items, falls back to a single item for whole amount
     *
     * @return array
     */
    private function cartItems()
    {
        $items = !empty($this->items['cartItems']) ? $this->items['cartItems'] : [
            [
                'title' => 'خرید',
                'fee'   => $this->amount,
                'count' => 1,
            ]
        ];

        $cartItems = [];

        foreach ($items as $item) {
            $fee = (int)ceil($item['fee']);
            $count = (int)$item['count'];

            $cartItems[] = [
                'title'    => $item['title'],
                'fee'      => $fee,
                'count'    => $count,
                'subTotal' => $fee * $count,
            ];
        }

        return $cartItems;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function url($path)
    {
        return "{$this->baseUrl}/{$this->apiPrefix}/{$path}";
    }

    /**
     * @return array
     */
    private function headers()
    {
        return [
            'Authorization' => 'Bearer ' . $this->config->get('fadax.token'),
            'Accept'        => '*/*',
        ];
    }

    /**
     * @param object|null $response
     * @param int $default
     *
     * @return int
     */
    private function extractErrorCode($response, $default)
    {
        if (isset($response->response->errorCode)) {
            return (int)$response->response->errorCode;
        }

        if (isset($response->response->status) && is_numeric($response->response->status)) {
            return (int)$response->response->status;
        }

        return (int)$default;
    }

    /**
     * @param object|null $response
     * @param string $default
     *
     * @return string
     */
    private function extractErrorMessage($response, $default)
    {
        if (!empty($response->response->message)) {
            return $response->response->message;
        }

        return $response ? json_encode($response, JSON_UNESCAPED_UNICODE) : $default;
    }
}
