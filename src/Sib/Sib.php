<?php

namespace PoolPort\Sib;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Sib extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://testdev.sibpay.ir';

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
     * @throws SibException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $productId = !empty($this->items['productId']) ? $this->items['productId'] : $this->transactionId();
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/webservice/request", [
                "json"    => [
                    'amount'       => (int)$this->amount,
                    'productId'    => $productId,
                    'inCount'      => !empty($this->items['inCount']) ? $this->items['inCount'] : 0,
                    'prepaid'      => !empty($this->items['prepaid']) ? $this->items['prepaid'] : 0,
                    'extraParams'  => !empty($this->items['extraParams']) ? $this->items['extraParams'] : '',
                    'token'        => $this->config->get('sib.token'),
                    'merchantCode' => $this->config->get('sib.merchantCode'),
                    'callbackUrl'  => $this->buildRedirectUrl($this->config->get('sib.callback-url')),
                    'mobile'       => $this->config->get('sib.user-mobile'),
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['result'] == false) {
                $this->transactionFailed();
                $this->newLog($response['errorCode'], json_encode($response));
                throw new SibException(json_encode($response), $response['errorCode']);
            }

            $this->paymentUrl = $response['data'][0]['paymentUrl'];

            $this->setMeta([
                'paymentToken' => $response['data'][0]['paymentToken'],
                'productId'    => $productId
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from sib server
     *
     * @return bool
     *
     * @throws SibException
     */
    protected function verifyPayment()
    {
        // transaction is successfull
        if (isset($_POST['transaction'])) {
            $this->refId = $_POST['transaction'];
            $this->trackingCode = $_POST['rnn'];
            $this->transactionSetRefId();
            $this->transactionSucceed();

            $this->setMeta([
                'transaction_callback' => $_POST
            ]);

            return true;
        } // failed to get transaction data
        else {
            return $this->tracepayment();
        }
    }

    public function tracepayment()
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
                throw new SibException(json_encode($response), $response['errorCode']);
            }

            $this->setMeta([
                'transaction_callback' => $response['data'][0]
            ]);

            $this->refId = $response['data'][0]['transaction'];
            $this->trackingCode = $_POST['rnn'];
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
     * @throws SibException
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
                throw new SibException(json_encode($response), $response['errorCode']);
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
}