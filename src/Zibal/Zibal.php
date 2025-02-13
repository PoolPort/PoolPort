<?php

namespace PoolPort\Zibal;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Zibal extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://gateway.zibal.ir';

    /**
     * Address of payment gateway
     *
     * @var string
     */
    private $paymentUri;

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
        $this->newTransaction();
        $this->sendPayRequest();

        return $this;
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
     * Send pay request to server
     *
     * @return void
     *
     * @throws ZibalException
     */
    protected function sendPayRequest()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/v1/request", [
                "json" => [
                    'amount'              => $this->amount,
                    'callbackUrl'         => $this->buildRedirectUrl($this->config->get('zibal.callback-url')),
                    'merchant'            => $this->config->get('zibal.merchant'),
                    'mobile'              => $this->config->get('zibal.user-mobile', ''),
                    'description'         => isset($this->items['description']) ? $this->items['description'] : null,
                    'orderId'             => isset($this->items['orderId']) ? $this->items['orderId'] : $this->transactionId(),
                    'allowedCards'        => isset($this->items['allowedCards']) ? $this->items['allowedCards'] : null,
                    'ledgerId'            => isset($this->items['ledgerId']) ? $this->items['ledgerId'] : null,
                    'nationalCode'        => isset($this->items['nationalCode']) ? $this->items['nationalCode'] : null,
                    'checkMobileWithCard' => isset($this->items['checkMobileWithCard']) ? $this->items['checkMobileWithCard'] : null,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || $response->result != 100) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new ZibalException(json_encode($response), $statusCode);
            }

            $this->paymentUri = "{$this->gateUrl}/start/".$response->trackId;
            $this->refId = $response->trackId;
            $this->transactionSetRefId();

            $this->setMeta([
                'trackId' => $response->trackId,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from Zibal server
     *
     * @return bool
     *
     * @throws ZibalException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/v1/verify", [
                "json" => [
                    'merchant' => $this->config->get('zibal.merchant'),
                    'trackId'  => $this->getMeta('trackId'),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || $response->result != 100) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new ZibalException(json_encode($response), $statusCode);
            }

            $this->trackingCode = $response->refNumber;
            $this->cardNumber = $response->cardNumber;
            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
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