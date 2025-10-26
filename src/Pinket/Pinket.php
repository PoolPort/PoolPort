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

    protected $apiUrl = 'https://test.pinket.com';

    protected $paymentUrl = 'https://pex-net.net/Ipg_SW/Index';

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

            $response = $client->request("POST", "{$this->apiUrl}/Check_Ipg_Tok", [
                "query"   => [

                ],
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if (!$response->Stat) {
                $errorMessage = json_encode($response);
                $this->newLog($response->StatCode, $errorMessage);
                throw new PinketException($errorMessage, $response->StatCode);
            }

            $this->paymentUrl = "{$this->paymentUrl}/{$response->Result}";
            $this->refId = $response->Result;
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

            $response = $client->request("POST", "{$this->apiUrl}/Check_target_Result", [
                "query"   => [

                ],
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if (!$response->Stat) {
                $errorMessage = json_encode($response);
                $this->newLog($response->StatCode, $errorMessage);
                throw new PinketException($errorMessage, $response->StatCode);
            }

            $this->trackingCode = $_POST['order_id'];
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
