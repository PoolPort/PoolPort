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

            $response = $client->request("POST", "{$this->gateUrl}/CreateOrder", [
                "json"    => [
                    'MerchantOrderId'   => $this->transactionId(),
                    'MerchantOrderDate' => now(),
                    'AdditionalData'    => !empty($this->items) ? json_encode($this->items) : "",
                    'Amount'            => (int)$this->amount,
                    'CallBackUrl'       => $this->buildRedirectUrl($this->config->get('sib.callback-url')),
                    'ReceptShowTime'    => !empty($this->items['ReceptShowTime']) ? $this->items['ReceptShowTime'] : 2,
                    'OrderDetails'      => '',
                    'OrderItems'        => '',
                    'walletCode'        => !empty($this->items['walletCode']) ? $this->items['walletCode'] : "",
                    'MobileNumber'      => $this->config->get('top.user-mobile'),
                ],
                'headers' => [
                    'Authorization' => $this->basicAuth(),
                    'Content-Type'  => 'application/json',
                ]
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
                'MerchantOrderId' => $this->transactionId()
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
        // transaction is successfull
        if (isset($_POST['transaction'])) {
            $this->refId = $_POST['transaction'];
            $this->trackingCode = $_POST['rnn'];
            $this->cardNumber = $_POST['cardNumber'];
            $this->transactionSetRefId();
            $this->transactionSucceed();

            $this->setMeta([
                'transaction_callback' => $_POST
            ]);

            return true;
        } else { // failed to get transaction data
            return $this->tracePayment();
        }
    }

    public function tracePayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/webservice/check", [
                "json"    => [
                    'token'        => $this->config->get('sib.token'),
                    'merchantCode' => $this->config->get('sib.merchantCode'),
                    'productId'    => $this->getMeta('productId'),
                    'paymentToken' => $this->getMeta('paymentToken')
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['result'] == false) {
                $this->transactionFailed();
                $this->newLog($response['errorCode'], json_encode($response));
                throw new TopException(json_encode($response), $response['errorCode']);
            }

            $this->setMeta([
                'transaction_callback' => $response['data'][0]
            ]);

            $this->refId = $response['data'][0]['transaction'];
            $this->trackingCode = $response['data'][0]['rnn'];
            $this->cardNumber = $response['data'][0]['cardNumber'];
            $this->transactionSetRefId();
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
     * @throws TopException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/webservice/returnTransaction", [
                "json"    => [
                    'token'        => $this->config->get('sib.token'),
                    'merchantCode' => $this->config->get('sib.merchantCode'),
                    'productId'    => $meta['productId'],
                    'paymentToken' => $meta['paymentToken']
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['result'] == false) {
                $this->newLog($response['errorCode'], json_encode($response));
                throw new TopException(json_encode($response), $response['errorCode']);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Partial refund user payment
     *
     * @return bool
     *
     * @throws TopException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/webservice/returnTransaction", [
                "json"    => [
                    'token'        => $this->config->get('sib.token'),
                    'merchantCode' => $this->config->get('sib.merchantCode'),
                    'productId'    => $meta['productId'],
                    'paymentToken' => $meta['paymentToken'],
                    'amount'       => $amount,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['result'] == false) {
                $this->newLog($response['errorCode'], json_encode($response));
                throw new TopException(json_encode($response), $response['errorCode']);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function addItem($productId = null, $inCount = 0, $prepaid = 0, $extraParams = "")
    {
        $this->items = [
            'productId'   => (string)$productId,
            'inCount'     => (int)$inCount,
            'prepaid'     => (int)$prepaid,
            'extraParams' => (string)$extraParams
        ];

        return $this;
    }

    public function basicAuth()
    {
        $username = $this->config->get('top.username');
        $password = $this->config->get('top.password');

        return base64_encode("$username:$password");
    }
}