<?php

namespace PoolPort\Top;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Top extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://pay.top.ir/api/WPG';

    private $paymentUrl;

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
        header("Location: {$this->paymentUrl}");
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
     * @throws TopException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();
            $merchantOrderId = mt_rand(1000000, 99999999999);

            $response = $client->request("POST", "{$this->gateUrl}/CreateOrder", [
                'auth'    => $this->getAuth(),
                "json"    => [
                    'MerchantOrderId'   => $merchantOrderId,
                    'MerchantOrderDate' => now(),
                    'AdditionalData'    => !empty($this->items['AdditionalData']) ? json_encode($this->items['AdditionalData']) : "",
                    'Amount'            => (int)$this->amount,
                    'CallBackUrl'       => $this->buildRedirectUrl($this->config->get('top.callback-url')),
                    'ReceptShowTime'    => !empty($this->items['ReceptShowTime']) ? $this->items['ReceptShowTime'] : 2,
                    'walletCode'        => $this->config->get('top.username'),
                    'MobileNumber'      => $this->config->get('top.user-mobile'),
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['status'] != 0) {
                $this->transactionFailed();
                $this->newLog($response['status'], json_encode($response));
                throw new TopException(json_encode($response), $response['status']);
            }

            $this->paymentUrl = $response['data']['serviceURL'];

            $this->setMeta([
                'token'           => $response['data']['token'],
                'MerchantOrderId' => $merchantOrderId
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from top server
     *
     * @return bool
     *
     * @throws TopException
     */
    protected function verifyPayment()
    {
        try {
            $meta = $this->getMeta();
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/ConfirmPurchase", [
                'auth'    => $this->getAuth(),
                "json"    => [
                    'token'               => $meta['token'],
                    'MerchantOrderId'     => $meta['MerchantOrderId'],
                    'transactionDateTime' => now()->format('Y-m-d'),
                    'additionalData'      => !empty($this->items['additionalData']) ? $this->items['additionalData'] : "",
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['status'] != 0) {
                $this->transactionFailed();
                $this->newLog($response['status'], json_encode($response));
                throw new TopException(json_encode($response), $response['status']);
            }

            $this->refId = $response['data']['returnId'];
            $this->trackingCode = $response['data']['returnId'];
            $this->transactionSetRefId();
            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function addItem($data)
    {
        $this->items = $data;

        return $this;
    }

    public function getAuth()
    {
        $username = $this->config->get('top.username');
        $password = $this->config->get('top.password');

        return [$username, $password];
    }
}