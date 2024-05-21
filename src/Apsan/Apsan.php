<?php

namespace PoolPort\Apsan;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;

class Apsan extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://pay.cpg.ir/api/v1';

    private $uniqueIdentifier;

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
        $fields['token'] = $this->refId;

        require 'ApsanRedirector.php';
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
     * @throws ApsanException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();
            $this->uniqueIdentifier = uniqid();

            $response = $client->request("POST", "{$this->gateUrl}/Token", [
                "json"    => [
                    'amount'           => $this->amount,
                    'redirectUri'      => $this->buildRedirectUrl($this->config->get('apsan.callback-url')),
                    'terminalId'       => $this->config->get('apsan.terminalId'),
                    'uniqueIdentifier' => $this->uniqueIdentifier,
                ],
                'headers' => [
                    'Authorization' => $this->generateSignature(),
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response->description);
                throw new ApsanException($response->description, $statusCode);
            }

            $this->refId = $response->result;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from apsan server
     *
     * @return bool
     *
     * @throws ApsanException
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/payment/acknowledge", [
                "json"    => [
                    'token' => $this->refId(),
                ],
                'headers' => [
                    'Authorization' => $this->generateSignature(),
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($statusCode != 200) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response->description);
                throw new ApsanException($response->description, $statusCode);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * generate a signature
     *
     * @return string
     */
    protected function generateSignature()
    {
        $username = $this->config->get('apsan.username');
        $password = $this->config->get('apsan.password');

        return "Basic " . base64_encode("$username:$password");
    }
}