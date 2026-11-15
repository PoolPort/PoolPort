<?php

namespace PoolPort\TenClub;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class TenClub extends PortAbstract implements PortInterface
{
    const TIMEOUT = 30;

    const PRODUCTION_ORIGIN = 'https://pay.tenclub.ir';

    const SANDBOX_ORIGIN = 'https://sandbox.pay.tenclub.ir';

    /**
     * Absolute API path signed and called for create payment
     */
    const PAYMENTS_PATH = '/api/v1/payments';

    const STATUS_PENDING = 'Pending';

    const STATUS_SUCCEEDED = 'Succeeded';

    const STATUS_FAILED = 'Failed';

    const STATUS_CANCELLED = 'Cancelled';

    const STATUS_EXPIRED = 'Expired';

    /**
     * Hosted payment page returned by create payment
     *
     * @var string|null
     */
    private $paymentUrl;

    /**
     * Merchant order id sent as orderId
     *
     * @var string|null
     */
    private $orderId;

    /**
     * Short description, max 255 characters
     *
     * @var string|null
     */
    private $description;

    /**
     * Customer email
     *
     * @var string|null
     */
    private $email;

    /**
     * Flat metadata object, max 10 keys
     *
     * @var array
     */
    private $metadata = [];

    /**
     * Payment lifetime in seconds. Gateway default is 1800, allowed range 300..7200
     *
     * @var int|null
     */
    private $expiresIn;

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
        $this->amount = (int) $amount;

        return $this;
    }

    /**
     * @param string $orderId
     *
     * @return $this
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param string $email
     *
     * @return $this
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @param array $metadata
     *
     * @return $this
     */
    public function setMetadata(array $metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param int $expiresIn
     *
     * @return $this
     */
    public function setExpiresIn($expiresIn)
    {
        $this->expiresIn = (int) $expiresIn;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->newTransaction();
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        header('Location: '.$this->paymentUrl, true, 302);
        exit;
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
     * POST /api/v1/payments and store paymentId plus paymentUrl
     *
     * @return void
     *
     * @throws TenClubException
     */
    protected function sendPayRequest()
    {
        throw new TenClubException('TenClub create payment is not implemented.');
    }

    /**
     * GET /api/v1/payments/{paymentId}. Succeeded is the only paid status.
     *
     * @return void
     *
     * @throws TenClubException
     */
    protected function verifyPayment()
    {
        throw new TenClubException('TenClub verify payment is not implemented.');
    }

    /**
     * Signed JSON request. Path must be the absolute API path used in the signature.
     *
     * @param string $method
     * @param string $pathAndQuery
     * @param array|string|null $body
     * @param array $extraHeaders
     *
     * @return array{statusCode: int, body: array, raw: string}
     *
     * @throws TenClubException
     */
    protected function request($method, $pathAndQuery, $body = null, array $extraHeaders = [])
    {
        $method = strtoupper($method);
        $rawBody = '';

        if ($body !== null) {
            $rawBody = is_string($body)
                ? $body
                : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($rawBody === false) {
                throw new TenClubException('TenClub request body could not be encoded.');
            }
        }

        try {
            $client = new Client([
                'timeout' => self::TIMEOUT,
                'http_errors' => false,
            ]);

            $options = [
                'headers' => $this->signedHeaders($method, $pathAndQuery, $rawBody, $extraHeaders),
            ];

            if ($rawBody !== '') {
                $options['body'] = $rawBody;
            }

            $response = $client->request($method, $this->endpointUrl($pathAndQuery), $options);
            $contents = (string) $response->getBody();
            $decoded = $contents === '' ? [] : json_decode($contents, true);

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
                'raw' => $contents,
            ];
        } catch (TenClubException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new TenClubException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Create-payment payload. Null fields are omitted.
     *
     * @return array
     */
    protected function createPaymentBody()
    {
        $body = [
            'amount' => (int) $this->amount,
            'callbackUrl' => $this->buildRedirectUrl($this->config->get('tenclub.callback-url')),
        ];

        if ($this->description !== null && $this->description !== '') {
            $body['description'] = $this->description;
        }

        if ($this->orderId !== null && $this->orderId !== '') {
            $body['orderId'] = $this->orderId;
        }

        $mobile = $this->config->get('tenclub.user-mobile');
        if (!empty($mobile)) {
            $body['mobile'] = $mobile;
        }

        if ($this->email !== null && $this->email !== '') {
            $body['email'] = $this->email;
        }

        if (!empty($this->metadata)) {
            $body['metadata'] = $this->metadata;
        }

        if ($this->expiresIn !== null) {
            $body['expiresIn'] = $this->expiresIn;
        }

        return $body;
    }

    /**
     * Idempotency-Key for POST /payments. Stable for this transaction.
     *
     * @return string
     */
    protected function idempotencyKey()
    {
        return 'poolport-'.$this->transactionId();
    }

    /**
     * @param string $pathAndQuery
     *
     * @return string
     */
    protected function endpointUrl($pathAndQuery)
    {
        $configured = rtrim((string) $this->config->get('tenclub.base-url', self::PRODUCTION_ORIGIN), '/');
        $origin = preg_replace('#/api/v1$#', '', $configured);

        return $origin.$pathAndQuery;
    }

    /**
     * @param string|null $paymentId
     *
     * @return string
     */
    protected function paymentPath($paymentId = null)
    {
        if ($paymentId === null || $paymentId === '') {
            return self::PAYMENTS_PATH;
        }

        return self::PAYMENTS_PATH.'/'.$paymentId;
    }

    /**
     * canonical = Timestamp + "\n" + Nonce + "\n" + Method + "\n" + PathAndQuery + "\n" + BodySha256Hex
     *
     * @param string $method
     * @param string $pathAndQuery
     * @param string $rawBody exact bytes that will be sent
     * @param array $extraHeaders
     *
     * @return array
     */
    protected function signedHeaders($method, $pathAndQuery, $rawBody, array $extraHeaders = [])
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = $timestamp."\n".$nonce."\n".$method."\n".$pathAndQuery."\n".hash('sha256', $rawBody);
        $signature = hash_hmac('sha256', $canonical, (string) $this->config->get('tenclub.api-secret'));

        $headers = [
            'X-Api-Key' => (string) $this->config->get('tenclub.api-key'),
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'Accept' => 'application/json',
        ];

        if ($rawBody !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        return array_merge($headers, $extraHeaders);
    }
}
