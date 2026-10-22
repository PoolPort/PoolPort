<?php

namespace PoolPort\Vibe;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Vibe extends PortAbstract implements PortInterface
{
    const TIMEOUT = 30;

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
            'order_id'     => (int)$this->transactionId(),
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
            throw new VibeException('goods_amount and tax are required', 400);
        }

        if (empty($this->cart['items']) || !is_array($this->cart['items'])) {
            throw new VibeException('At least one item is required', 400);
        }

        if ((int)$this->amount !== (int)$this->cart['goods_amount'] + (int)$this->cart['tax']) {
            throw new VibeException('cart_amount must equal goods_amount + tax', 400);
        }
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

        try {
            $response = $this->request('/api/v1/orders/', $this->getPayRequestPayload());

            if ($response['statusCode'] !== 200 || empty($response['body']['payment_url'])) {
                $this->newLog($response['statusCode'], json_encode($response['body'], JSON_UNESCAPED_UNICODE));
                throw new VibeException(json_encode($response['body'], JSON_UNESCAPED_UNICODE), $response['statusCode']);
            }

            $this->paymentUrl = $response['body']['payment_url'];
            $paymentToken = $this->extractPaymentToken($this->paymentUrl);

            $this->setMeta([
                'payment_url'   => $this->paymentUrl,
                'payment_token' => $paymentToken,
            ]);

            $this->refId = $paymentToken !== null ? $paymentToken : (string)$this->transactionId();
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
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

        try {
            $response = $this->request('/api/v1/orders/verify', ['ref_id' => $vibeRefId]);
        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }

        if ($response['statusCode'] !== 200) {
            $this->newLog($response['statusCode'], json_encode($response['body'], JSON_UNESCAPED_UNICODE));
            throw new VibeException(json_encode($response['body'], JSON_UNESCAPED_UNICODE), $response['statusCode']);
        }

        if (empty($response['body']['status'])) {
            $callbackResult = isset($_GET['result']) ? $_GET['result'] : '';
            $message = "Order is not finalized (callback result: {$callbackResult})";

            $this->transactionFailed();
            $this->newLog('NOK', $message);
            throw new VibeException($message, 400);
        }

        $this->refId = $vibeRefId;
        $this->transactionSetRefId();
        $this->trackingCode = $vibeRefId;
        $this->transactionSucceed();
        $this->newLog(PortAbstract::TRANSACTION_SUCCEED, PortAbstract::TRANSACTION_SUCCEED_TEXT);
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
        if (isset($_GET['order_id']) && (string)$_GET['order_id'] !== (string)$this->transactionId()) {
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
        $this->newLog('Error', $message);
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
     * Send a POST request to Vibe
     *
     * @param string $endpoint
     * @param array $data
     *
     * @return array ['statusCode' => int, 'body' => array|null]
     */
    private function request($endpoint, array $data)
    {
        $client = new Client();

        $response = $client->request('POST', rtrim($this->config->get('vibe.base-url'), '/') . $endpoint, [
            'json'        => $data,
            'headers'     => [
                'X-API-Key'    => $this->config->get('vibe.api-key'),
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'timeout'     => self::TIMEOUT,
            'http_errors' => false,
        ]);

        return [
            'statusCode' => $response->getStatusCode(),
            'body'       => json_decode($response->getBody()->getContents(), true),
        ];
    }
}
