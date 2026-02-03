<?php

namespace PoolPort\MehraCart;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class MehraCart extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://ipg.mehracard.ir/api';

    /**
     * Address of payment gateway
     *
     * @var string
     */
    private $paymentUri = 'https://ipg.mehracard.ir/pay/StartPayment';

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
        $this->sendPayRequest();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws MehraCartException
     */
    protected function sendPayRequest()
    {
        try {
            $this->newTransaction();

            $orderId = !empty($this->items['OrderId']) ? $this->items['OrderId'] : '';

            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/payment/request", [
                "json" => [
                    'MerchantId'  => $this->config->get('mehracart.MerchantId'),
                    'Amount'      => $this->amount,
                    'Description' => !empty($this->items['Description']) ? $this->items['Description'] : '',
                    'CallbackUrl' => $this->buildRedirectUrl($this->config->get('mehracart.callback-url')),
                    'OrderId'     => $orderId,

                    'Metadata' => [
                        'mobile' => $this->config->get('mehracart.mobile', ''),
                        'email'  => !empty($this->items['Metadata']['email']) ? $this->items['Metadata']['email'] : ''
                    ],
                ],

                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new MehraCartException(json_encode($response), $statusCode);
            }

            $autority = $response['data']['authority'];
            $this->paymentUri = "{$this->paymentUri}?authority={$autority}";

            $this->setMeta([
                'Authority' => $autority,
                'OrderId'   => $orderId
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
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

        $this->verifyPayment();

        return $this;
    }

    /**
     * Verify user payment from mehracart server
     *
     * @return bool
     *
     * @throws MehraCartException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/verify/request", [
                "json" => [
                    'MerchantId' => $this->config->get('mehracart.MerchantId'),
                    'Authority'  => $_GET['Authority'],
                    'RefId'      => $_GET['RefId'],
                ],

                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if (!isset($response['code']) || $response['code'] != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response);
                throw new MehraCartException($response, $statusCode);
            }

            $this->refId = $_GET['RefId'];
            $this->transactionSetRefId();

            $this->trackingCode = $_GET['RefId'];
            $this->transactionSucceed();

            $this->setMeta([
                'RefId' => $_GET['RefId']
            ]);

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
     * @throws MehraCartException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/Reverse/request", [
                "json"    => [
                    'RefId' => (int)$meta['RefId'],
                ],
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents(), true);

            if (!isset($response['code']) || $response['code'] != 200) {
                $this->newLog($statusCode, json_encode($response));
                throw new MehraCartException(json_encode($response), $statusCode);
            }

            $this->newLog('Refunded', json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
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