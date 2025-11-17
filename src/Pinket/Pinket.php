<?php

namespace PoolPort\Pinket;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Pinket extends PortAbstract implements PortInterface
{

    protected $apiUrl = 'https://pinket.com/api/thirdparty/v1';

    protected $paymentUrl = '';

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
     * Send pay request to server
     *
     * @return void
     *
     * @throws PinketException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/order", [
                "json"    => [
                    'callbackUrl' => $this->buildRedirectUrl($this->config->get('pinket.callback-url')),
                    'sessionUid'  => $this->config->get('pinket.user-mobile'),
                    'amount'      => (int)$this->amount,
                    'totalAmount' => (int)$this->amount,
                    'items'       => $this->items
                ],
                "headers" => [
                    'api-key'      => $this->config->get('pinket.token'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->newLog($statusCode, $response->error);
                throw new PinketException($response->error, $statusCode);
            }

            $this->paymentUrl = $response->data->redirectUrl;
            $this->refId = $response->data->orderId;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from pinket server
     *
     * @return bool
     *
     * @throws PinketException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/order/{$this->refId}/confirm", [
                "headers" => [
                    'api-key'      => $this->config->get('pinket.token'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->newLog($statusCode, $response->error);
                throw new PinketException($response->error, $statusCode);
            }

            $this->trackingCode = $this->refId;
            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function addItem(array $items)
    {
        $this->items = $items;

        return $this;
    }
}