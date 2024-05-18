<?php

namespace PoolPort\Apsan;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use Symfony\Component\HttpFoundation\Response;

class Apsan extends PortAbstract implements PortInterface
{
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://pay.cpg.ir/api/v1';

    private $token;

    private $uniqueIdentifier;

    /**
     * items of invoice
     *
     * @var array
     */
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
        $fields['token'] = $this->token;

        require 'ApsanRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
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
            $this->uniqueIdentifier = uniqid();

            $data = [
                'amount'           => $this->amount,
                'redirectUri'      => $this->buildRedirectUrl($this->config->get('apsan.callback-url')),
                'terminalId'       => $this->config->get('apsan.terminalId'),
                'uniqueIdentifier' => $this->uniqueIdentifier,
            ];

            $headers = [
                'accept: application/json',
                'Authorization: ' . $this->generateSignature(),
                'Content-Type: application/json'
            ];

            $curl = curl_init("{$this->gateUrl}/Token");
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

            $response = curl_exec($curl);
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $response = json_decode($response);
            curl_close($curl);

            if ($statusCode != Response::HTTP_OK) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response->description);
                throw new ApsanException($response->description, $statusCode);
            }

            $this->token = $response->result;
            session()->put('token', $this->token);
            $this->refId = $this->uniqueIdentifier;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
     *
     * @throws ApsanException
     */
    protected function userPayment()
    {
        try {
            $data = [
                'uniqueIdentifier' => $this->refId(),
            ];

            $headers = [
                'accept: application/json',
                'Authorization: ' . $this->generateSignature(),
                'Content-Type: application/json'
            ];

            $curl = curl_init("{$this->gateUrl}/transaction/status");
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($curl);
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $response = json_decode($response);
            curl_close($curl);

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog("Error", $e->getMessage());
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
            $data = [
                'token' => session('token'),
            ];

            $headers = [
                'accept: application/json',
                'Authorization: ' . $this->generateSignature(),
                'Content-Type: application/json'
            ];

            $curl = curl_init("{$this->gateUrl}/payment/acknowledge");
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

            $response = curl_exec($curl);
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $response = json_decode($response);
            curl_close($curl);

            if ($statusCode != Response::HTTP_OK) {
                $this->transactionFailed();
                $this->newLog($statusCode, $response->description);
                throw new ApsanException($response->description, $statusCode);
            }

            session()->forget('token');

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
    public function generateSignature()
    {
        $username = $this->config->get('apsan.username');
        $password = $this->config->get('apsan.password');

        return "Basic " . base64_encode("$username:$password");
    }
}