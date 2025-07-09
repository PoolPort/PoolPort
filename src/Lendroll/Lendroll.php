<?php

namespace PoolPort\Lendroll;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Lendroll extends PortAbstract implements PortInterface
{

    protected $apiUrl     = 'https://open.api.lendroll.ir/api/v1.1/Gateway';
    protected $paymentUrl = 'https://open.api.lendroll.ir/v1.1/Gateway/start';

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
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $fields['Authority'] = $this->refId();
        $fields['url'] = $this->paymentUrl;

        require 'LendrollRedirector.php';
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
     * @throws LendrollException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/request", [
                "json"    => [
                    'amount'      => $this->amount,
                    'description' => !empty($this->items['description']) ? $this->items['description'] : 'خرید',
                    'merchantId'  => $this->config->get('lendroll.merchantId'),
                    'callbackUrl' => $this->buildRedirectUrl($this->config->get('lendroll.callback-url')),
                    'orderId'     => $this->transactionId(),
                ],
                "headers" => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if (!$response->successful) {
                $this->transactionFailed();
                $errorMessages = json_encode($response->messages);
                $this->newLog($response->resultCode, $errorMessages);
                throw new LendrollException($errorMessages, $response->resultCode);
            }

            $this->refId = $response->data->authority;
            $this->transactionSetRefId();

            $this->setMeta([
                'amount'    => $this->amount,
                'authority' => $this->refId,
                'orderId'   => $this->transactionId(),
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from lendroll server
     *
     * @return bool
     *
     * @throws LendrollException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();
            $meta = $this->getMeta();
            $referenceId = $_POST['referenceId'];
            $this->setMeta(['referenceId' => $referenceId]);

            $response = $client->request("POST", "{$this->apiUrl}/verify", [
                "json"    => [
                    'authority'   => $meta['authority'],
                    'merchantId'  => $this->config->get('lendroll.merchantId'),
                    'amount'      => $meta['amount'],
                    'orderId'     => $meta['orderId'],
                    'referenceId' => $referenceId,
                ],
                "headers" => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if (!$response->successful) {
                $errorMessages = json_encode($response->messages);
                $this->newLog($response->resultCode, $errorMessages);
                throw new LendrollException($errorMessages, $response->resultCode);
            }

            $this->trackingCode = $referenceId;
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
     * @throws LendrollException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/refund", [
                "json" => [
                    'merchantId'  => $this->config->get('lendroll.merchantId'),
                    'orderId'     => $meta['orderId'],
                    'referenceId' => $meta['referenceId'],
                ],

                "headers" => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if (!$response->successful) {
                $errorMessages = json_encode($response->messages);
                $this->newLog($response->resultCode, $errorMessages);
                throw new LendrollException($errorMessages, $response->resultCode);
            }

            $this->newLog('Refunded', json_encode($response));

            return $response;

        } catch (\Exception $e) {
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
