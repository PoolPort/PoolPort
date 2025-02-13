<?php

namespace PoolPort\Pasargad;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Pasargad extends PortAbstract implements PortInterface
{
    const SERVICE_CODE = 8;
    const SERVICE_TYPE = 'PURCHASE';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://pep.shaparak.ir/dorsa1';

    /**
     * Address of payment gateway
     *
     * @var string
     */
    private $paymentUri;

    private $token;

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
        $this->authenticate();
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

        $this->confirmPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws PasargadException
     */
    protected function sendPayRequest()
    {
        try {
            $client = new Client();
            $invoice = $this->transactionId();

            $response = $client->request("POST", "{$this->gateUrl}/api/payment/purchase", [
                "json" => [
                    'amount'         => $this->amount,
                    'callbackApi'    => $this->buildRedirectUrl($this->config->get('pasargad.callback-url')),
                    'mobileNumber'   => $this->config->get('pasargad.user-mobile', ''),
                    'invoice'        => "$invoice",
                    'invoiceDate'    => jdate('Y/m/d H:i:s'),
                    'serviceCode'    => self::SERVICE_CODE,
                    'serviceType'    => self::SERVICE_TYPE,
                    'terminalNumber' => $this->config->get('pasargad.terminal-number'),
                    'description'    => isset($this->items['description']) ? $this->items['description'] : null,
                    'payerMail'      => isset($this->items['payerMail']) ? $this->items['payerMail'] : null,
                    'payerName'      => isset($this->items['payerName']) ? $this->items['payerName'] : null,
                    'pans'           => isset($this->items['pans']) ? $this->items['pans'] : null,
                    'nationalCode'   => isset($this->items['nationalCode']) ? $this->items['nationalCode'] : null,
                ],

                "headers" => [
                    'Authorization' => "Bearer {$this->token}",
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || $response->resultCode != 0) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new PasargadException(json_encode($response), $statusCode);
            }

            $this->paymentUri = $response->data->url;

            $this->setMeta([
                'invoice' => $invoice,
                'urlId'   => $response->data->urlId,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from pasargad server
     *
     * @return bool
     *
     * @throws PasargadException
     */
    protected function confirmPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/api/payment/confirm-transactions", [
                "json"    => [
                    'invoice' => "{$this->getMeta('invoice')}",
                    'urlId'   => $this->getMeta('urlId'),
                ],
                "headers" => [
                    'Authorization' => "Bearer " . $this->getMeta('token'),
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || $response->resultCode != 0) {
                $this->transactionFailed();
                $this->newLog($statusCode, json_encode($response));
                throw new PasargadException(json_encode($response), $statusCode);
            }

            $this->trackingCode = $response->data->trackId;
            $this->refId = $response->data->referenceNumber;
            $this->cardNumber = $response->data->maskedCardNumber;
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
     * Authenticate and obtain an access token.
     *
     * @return void
     *
     * @throws PoolPortException
     */
    protected function authenticate()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/token/getToken", [
                "json" => [
                    'username' => $this->config->get('pasargad.username'),
                    'password' => $this->config->get('pasargad.password'),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200 || $response->resultCode != 0) {
                $this->newLog($statusCode, json_encode($response));
                throw new PasargadException(json_encode($response), $statusCode);
            }

            $this->token = $response->token;

            $this->setMeta([
                'token' => $this->token
            ]);

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