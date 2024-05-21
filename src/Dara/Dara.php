<?php

namespace PoolPort\Dara;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;

class Dara extends PortAbstract implements PortInterface
{
    const ALGORITHM = "DES-EDE3";

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://ipg.daracard.co/api/v0';

    private $orderId;

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
        header('Location: ' . $this->refId());
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
     * @throws DaraException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $this->orderId = uniqid();
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/Request/PaymentRequest/", [
                "json" => [
                    'Amount'        => $this->amount,
                    'ReturnUrl'     => $this->buildRedirectUrl($this->config->get('dara.callback-url')),
                    'MerchantId'    => $this->config->get('dara.merchant-id'),
                    'TerminalId'    => $this->config->get('dara.terminal-id'),
                    'LocalDateTime' => date("m/d/Y g:i:s a"),
                    'OrderId'       => $this->orderId,
                    'SignData'      => $this->generateSignature(),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->ResultCode != 0) {
                $this->transactionFailed();
                $this->newLog($response->ResultCode, $response->ResultMessage);
                throw new DaraException($response->ResultMessage, $response->ResultCode);
            }

            $this->refId = $response->ResultData->Token;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from dara server
     *
     * @return bool
     *
     * @throws Dara
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->gateUrl}/Advice/Verify/", [
                "json" => [
                    'Token' => $this->refId(),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->ResultCode != 0) {
                $this->transactionFailed();
                $this->newLog($response->ResultCode, $response->ResultMessage);
                throw new DaraException($response->ResultMessage, $response->ResultCode);
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
        $key = $this->config->get('dara.merchant-id');
        $terminalId = $this->config->get('dara.terminal-id');
        $string = "$terminalId;{$this->orderId};{$this->amount}";
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($string, self::ALGORITHM, $key, 0);

        return base64_encode($ciphertext);
    }
}
