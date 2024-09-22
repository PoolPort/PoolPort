<?php

namespace PoolPort\BazaarPay;

use GuzzleHttp\Client;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;

class BazaarPay extends PortAbstract implements PortInterface
{
    const PAID_COMMITTED = 'paid_committed';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.bazaar-pay.ir/badje/v1';

    private $token;

    private $paymentUrl;


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
     * {@inheritdoc}
     */
    public function redirect()
    {
        $redirectUrl = $this->buildRedirectUrl($this->config->get('bazaarpay.callback-url'));
        $redirectUrl = urlencode($redirectUrl);
        $phone = $this->config->get('bazaarpay.user-mobile', '');

        header("Location: {$this->paymentUrl}&phone={$phone}&redirect_url={$redirectUrl}");
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
     * Send pay request to server
     *
     * @return void
     *
     * @throws BazaarPayException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/checkout/init/", [
                "json"    => [
                    'amount'       => $this->amount,
                    'destination'  => $this->config->get('bazaarpay.destination'),
                    'service_name' => $this->config->get('bazaarpay.service_name'),
                ],
                'headers' => [
                    'Authorization' => 'Token ' . $this->config->get('bazaarpay.token'),
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = $response->getBody()->getContents();

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response);
                throw new BazaarPayException($response, $statusCode);
            }

            $response = json_decode($response);
            $this->token = $response->checkout_token;
            $this->paymentUrl = $response->payment_url;
            $this->refId = $this->token;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from bazaar pay server
     *
     * @return bool
     *
     * @throws BazaarPayException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/commit/", [
                "json"    => [
                    'checkout_token' => $this->refId,
                ],
                'headers' => [
                    'Authorization' => 'Token ' . $this->config->get('bazaarpay.token'),
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = $response->getBody()->getContents();

            if(empty($response)) {
                return $this->tracePayment();
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode != 204) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response);
                throw new BazaarPayException($response, $statusCode);
            }

            $this->transactionSucceed();

            return json_decode($response);

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
     * @throws BazaarPayException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/refund/", [
                "json"    => [
                    'checkout_token' => $transaction->ref_id,
                ],
                'headers' => [
                    'Authorization' => 'Token ' . $this->config->get('bazaarpay.token'),
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = $response->getBody()->getContents();

            if ($statusCode != 204) {
                $this->newLog($statusCode, $response);
                throw new BazaarPayException($response, $statusCode);
            }

            $this->newLog('Refunded', $response);

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * trace user payment
     *
     * @param $refId
     *
     * @return mixed
     * @throws PoolPortException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function tracePayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/trace/", [
                "json"    => [
                    'checkout_token' => $this->refId,
                ],
                'headers' => [
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || ($statusCode == 200 && $response->status != self::PAID_COMMITTED)) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new BazaarPayException(json_encode($response), $statusCode);
            }

            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}