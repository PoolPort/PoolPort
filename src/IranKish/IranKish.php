<?php

namespace PoolPort\IranKish;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\PoolPortException;

class IranKish extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = 'https://ikc.shaparak.ir/api/v3/tokenization/make';

    /**
     * Address of SOAP server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://ikc.shaparak.ir/api/v3/confirmation/purchase';

    /**
     * @var string
     */
    protected $token;

    /**
     * @var string
     */
    protected $retrievalReferenceNumber;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $config, DataBaseManager $db, $portId)
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
        $token = $this->token;

        require 'IranKishRedirector.php';
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
     * @throws IranKishException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $tokenData = $this->generateAuthenticationEnvelope(
                $this->config->get('irankish.public-key'),
                $this->config->get('irankish.terminal-id'),
                $this->config->get('irankish.pass-phrase'),
                $this->amount
            );
        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('OpenSSL Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }

        try {
            $client = new Client();
            $response = $client->post($this->serverUrl, [
                'json' => [
                    'authenticationEnvelope' => $tokenData,
                    'request' => [
                        'transactionType' => 'Purchase',
                        'terminalId' => $this->config->get('irankish.terminal-id'),
                        'acceptorId' => $this->config->get('irankish.acceptor-id'),
                        'amount' => intval($this->amount),
                        'revertUri' => $this->buildRedirectUrl($this->config->get('irankish.callback-url')),
                        'requestId' => $this->transactionId(),
                        'requestTimestamp' => time(),
                        'cmsPreservationId' => $this->config->get('irankish.user-mobile'),
                    ],
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->responseCode != "00") {
                throw new \Exception($response->description);
            }

            $this->token = $response->result->token;
            $this->refId = $response->result->token;
			$this->transactionSetRefId();
            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return array
     */
    protected function generateAuthenticationEnvelope($publicKey, $terminalId, $password, $amount)
    {
        $data = $terminalId.$password.str_pad($amount, 12, '0', STR_PAD_LEFT).'00';
        $data = hex2bin($data);
        $AESSecretKey = openssl_random_pseudo_bytes(16);
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($data, $cipher, $AESSecretKey, OPENSSL_RAW_DATA, $iv);
        $hmac = hash('sha256', $ciphertext_raw, true);
        $crypttext = '';

        openssl_public_encrypt($AESSecretKey . $hmac, $crypttext, file_get_contents($publicKey));

        return array(
            "data" => bin2hex($crypttext),
            "iv" => bin2hex($iv),
        );
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws PoolPortException
     */
    protected function userPayment()
    {
        $responseCode = @$_POST['responseCode'];
        $this->trackingCode = @$_POST['systemTraceAuditNumber'];
        $this->retrievalReferenceNumber = @$_POST['retrievalReferenceNumber'];
        $this->cardNumber = $_POST['maskedPan'];

        if ($responseCode != "00") {
            $this->transactionFailed();
            $this->newLog($responseCode, $responseCode);
            throw new PoolPortException($responseCode);
        }

        return true;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws IranKishException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        try {
            $client = new Client();
            $response = $client->post($this->serverVerifyUrl, [
                'json' => [
                    'terminalId' => $this->config->get('irankish.terminal-id'),
                    'systemTraceAuditNumber' => $this->trackingCode(),
                    'retrievalReferenceNumber' => $this->retrievalReferenceNumber,
                    'tokenIdentity' => $this->refId(),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->responseCode != "00") {
                throw new \Exception($response->description);
            }

            $this->transactionSucceed();
            $this->newLog('100', self::TRANSACTION_SUCCEED_TEXT);
            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
