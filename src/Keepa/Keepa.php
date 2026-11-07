<?php

namespace PoolPort\Keepa;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Keepa extends PortAbstract implements PortInterface
{
    const PAYMENT_STATUS_VERIFIED = 1;

    const PAYMENT_STATUS_WAITING_TO_VERIFY = 8;

    const REFUND_REASON_DUPLICATE_PURCHASE = 1;

    const REFUND_REASON_CUSTOMER_REQUEST = 2;

    const REFUND_REASON_SUSPECTED_FRAUD = 3;

    const REFUND_REASON_OTHER = 4;

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://sapi.keepa.ir/creditcore/thirdpartygateway';

    private $accessToken;

    private $token;

    private $paymentUrl;

    private $recieptNumber;

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
            $this->authenticate();

            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/cpg-payments/v2/get-token", [
                "json"        => [
                    'terminalId'    => (int)$this->config->get('keepa.terminal-id'),
                    'invoiceNumber' => (string)$this->transactionId(),
                    'amount'        => (int)$this->amount,
                    'callbackUrl'   => $this->buildRedirectUrl($this->config->get('keepa.callback-url')),
                    'payload'       => !empty($this->items['payload']) ? $this->items['payload'] : null,
                    'items'         => !empty($this->items['items']) ? $this->items['items'] : null,
                ],
                'headers'     => [
                    'Authorization' => $this->getToken(),
                    'Content-Type'  => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode < 200 || $statusCode >= 300 || empty($response->token) || empty($response->paymentUrl)) {
                $this->transactionFailed();
                $message = $this->extractErrorMessage($response, 'خطا در دریافت توکن پرداخت');
                $this->newLog($statusCode, $message);
                throw new KeepaException($message, $statusCode);
            }

            $this->token = $response->token;
            $this->paymentUrl = $response->paymentUrl;
            $this->refId = $this->token;
            $this->transactionSetRefId();

            $this->setMeta([
                'token'        => $this->token,
                'access_token' => $this->accessToken,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function authenticate()
    {
        if (!empty($this->accessToken)) {
            return;
        }

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/clients/authorize", [
                'json'        => [
                    'clientId'     => $this->config->get('keepa.client-id'),
                    'clientSecret' => $this->config->get('keepa.client-secret'),
                ],
                'headers'     => [
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode < 200 || $statusCode >= 300 || empty($response->accessToken)) {
                $message = $this->extractErrorMessage($response, 'خطا در دریافت توکن احراز هویت');
                $this->newLog($statusCode, $message);
                throw new KeepaException($message, $statusCode);
            }

            $this->accessToken = $response->accessToken;

        } catch (\Exception $e) {
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function extractErrorMessage($response, $fallbackMessage = 'خطای نامشخص')
    {
        if (is_object($response)) {
            if (isset($response->Message) && !empty($response->Message)) {
                return $response->Message;
            }

            if (isset($response->Details) && is_array($response->Details) && count($response->Details) > 0) {
                $messages = [];
                foreach ($response->Details as $detail) {
                    if (!empty($detail->Description)) {
                        $messages[] = $detail->Description;
                    }
                }

                if (!empty($messages)) {
                    return implode(' | ', $messages);
                }
            }

            if (isset($response->statusTitle) && !empty($response->statusTitle)) {
                return $response->statusTitle;
            }
        }

        return $fallbackMessage;
    }

    /**
     * get token
     *
     * @return string
     */
    protected function getToken()
    {
        return "Bearer {$this->accessToken}";
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        header("Location: {$this->paymentUrl}");
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->recieptNumber = isset($_GET['token']) ? $_GET['token'] : $this->getMeta('token');

        if (empty($this->recieptNumber)) {
            $this->transactionFailed();
            $this->newLog(400, 'توکن پرداخت در callback ارسال نشده است');
            throw new PoolPortException('توکن پرداخت در callback ارسال نشده است', 400);
        }

        $inquiryResponse = $this->verifyPayment();

        if ((int)$inquiryResponse->status !== self::PAYMENT_STATUS_VERIFIED) {
            $this->confirmPayment();
        }

        return $this;
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
            $this->authenticate();

            $client = new Client();

            $response = $client->request("GET", "{$this->gateUrl}/cpg-payments/v2/inquiry/{$this->recieptNumber}", [
                'headers'     => [
                    'Authorization' => $this->getToken(),
                    'Content-Type'  => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->transactionFailed();
                $message = $this->extractErrorMessage($response, 'خطا در استعلام پرداخت');
                $this->newLog($statusCode, $message);
                throw new KeepaException($message, $statusCode);
            }

            if ((int)$response->status === self::PAYMENT_STATUS_VERIFIED) {
                $this->trackingCode = $this->refId;
                $this->transactionSucceed();

                return $response;
            }

            if ((int)$response->status !== self::PAYMENT_STATUS_WAITING_TO_VERIFY) {
                $this->transactionFailed();
                $message = isset($response->statusTitle) ? $response->statusTitle : 'وضعیت تراکنش برای تایید معتبر نیست';
                $this->newLog((int)$response->status, $message);
                throw new KeepaException($message, (int)$response->status);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Confirm user payment from keepa server
     *
     * @return bool
     *
     * @throws KeepaException
     */
    protected function confirmPayment()
    {
        try {
            $this->authenticate();

            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/cpg-payments/v2/verify", [
                "json"        => [
                    'token'  => $this->recieptNumber,
                    'amount' => $this->amount,
                ],
                'headers'     => [
                    'Authorization' => $this->getToken(),
                    'Content-Type'  => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->transactionFailed();
                $message = $this->extractErrorMessage($response, 'خطا در تایید پرداخت');
                $this->newLog($statusCode, $message);
                throw new KeepaException($message, $statusCode);
            }

            $this->trackingCode = isset($response->refNum) ? $response->refNum : $this->recieptNumber;
            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refund the full verified payment.
     *
     * @param object $transaction
     * @param array $params reason (1-4) and optional description
     *
     * @return object
     *
     * @throws KeepaException
     */
    public function refundPayment($transaction, $params = [])
    {
        return $this->sendRefundRequest($transaction, (int)$transaction->price, $params, 'Refunded');
    }

    /**
     * Refund part of a verified payment.
     *
     * @param object $transaction
     * @param int $amount refund amount in Rial
     * @param array $params reason (1-4) and optional description
     *
     * @return object
     *
     * @throws KeepaException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        return $this->sendRefundRequest($transaction, (int)$amount, $params, 'PartialRefunded');
    }

    /**
     * Send refund request to Keepa.
     *
     * Full and partial refunds use the same endpoint. Only verified payments can be refunded.
     *
     * @param object $transaction
     * @param int $amount
     * @param array $params
     * @param string $logStatus
     *
     * @return object
     *
     * @throws PoolPortException
     */
    protected function sendRefundRequest($transaction, $amount, $params, $logStatus)
    {
        try {
            $this->setTransactionId($transaction->id);
            $this->authenticate();

            $meta = json_decode($transaction->meta, true);
            $token = !empty($meta['token']) ? $meta['token'] : $transaction->ref_id;

            if (empty($token)) {
                throw new KeepaException('توکن پرداخت برای عودت وجه یافت نشد', 114);
            }

            if ($amount <= 0 || $amount > (int)$transaction->price) {
                throw new KeepaException('مبلغ عودت وجه نامعتبر است', 174);
            }

            $reason = isset($params['reason']) ? (int)$params['reason'] : self::REFUND_REASON_CUSTOMER_REQUEST;

            $allowedReasons = [
                self::REFUND_REASON_DUPLICATE_PURCHASE,
                self::REFUND_REASON_CUSTOMER_REQUEST,
                self::REFUND_REASON_SUSPECTED_FRAUD,
                self::REFUND_REASON_OTHER,
            ];

            if (!in_array($reason, $allowedReasons, true)) {
                throw new KeepaException('علت عودت وجه نامعتبر است', 175);
            }

            $payload = [
                'token'        => $token,
                'refundAmount' => $amount,
                'reason'       => $reason,
            ];

            if (!empty($params['description'])) {
                $description = (string)$params['description'];

                if (mb_strlen($description) > 500) {
                    throw new KeepaException('توضیح عودت وجه نامعتبر است', 176);
                }

                $payload['description'] = $description;
            }

            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/cpg-payments/v2/refund", [
                "json"        => $payload,
                'headers'     => [
                    'Authorization' => $this->getToken(),
                    'Content-Type'  => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode < 200 || $statusCode >= 300) {
                $message = $this->extractErrorMessage($response, 'خطا در عودت وجه');
                $this->newLog($statusCode, $message);
                throw new KeepaException($message, $statusCode);
            }

            $this->newLog($logStatus, json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}