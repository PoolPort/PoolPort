<?php

namespace PoolPort\AP;

use DateTime;
use PoolPort\Config;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class AP extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = "https://services.asanpardakht.net/paygate/merchantservices.asmx?wsdl";

    /**
     * Address of verify SOAP server
     *
     * @var string
     */
    protected $verifyUrl = "https://services.asanpardakht.net/paygate/statuswatch.asmx?wsdl";

    /**
     * Address of time SOAP server
     *
     * @var string
     */
    protected $timeUrl = "https://services.asanpardakht.net/paygate/servertime.asmx?wsdl";

    /**
     * Encrypted credintial
     *
     * @var string
     */
    private $encryptedCredintial = null;

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
        $token = $this->refId;
        $mobile = $this->config->get('ap.user-mobile');

        require 'APRedirector.php';
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
     * @throws APException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        try {
            $fields = array(
                "merchantConfigurationID" => $this->config->get('ap.merchant-config-id'),
                "encryptedRequest" => $this->encrypt(array(
                    '1',
                    $this->config->get('ap.username'),
                    $this->config->get('ap.password'),
                    $this->transactionId(),
                    $this->amount,
                    $this->syncTime(),
                    '',
                    $this->buildRedirectUrl($this->config->get('ap.callback-url')),
                    '0'
                ))
            );

            $soap = new SoapClient($this->serverUrl, $this->config);
            $response = $soap->RequestOperation($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        $response = explode(',', $response->RequestOperationResult);
        if (count($response) == 2 && $response[0] == 0) {
            $this->refId = $response[1];
            $this->transactionSetRefId($this->transactionId);

        } else {
            $this->transactionFailed();
            $this->newLog($response[0], APException::getError($response[0]));
            throw new APException($response[0]);
        }
    }

    /**
     * Check user payment
     *
     * @return void
     */
    protected function userPayment()
    {
        // Because this port use an old algorithm for encryption and decryption and we must do it from their server
        // We ignore this step to avoid reduce speed
        return true;
    }

    /**
     * Verify user payment from bank server
     *
     * @return void
     */
    protected function verifyPayment()
    {
        try {
            // Checking payment
            $fields = array(
                "merchantConfigurationID" => $this->config->get('ap.merchant-config-id'),
                "encryptedCredintials" => $this->encryptedCredintials(),
                "localInvoiceID" => $this->transactionId()
            );

            $soap = new SoapClient($this->verifyUrl, $this->config);
            $response = $soap->CheckTransactionResult($fields);

            $result = json_decode($response->CheckTransactionResultResult);
            if ($result->Result != '1100') {
                $this->transactionFailed();
                $this->newLog($result->Result, APException::getError($result->Result));
                throw new APException($result->Result);
            }

            $this->trackingCode = $result->PayGateTranID;
            $this->cardNumber = $result->CardNumber;

            // Verify payment
            $fields = array(
                "merchantConfigurationID" => $this->config->get('ap.merchant-config-id'),
                "encryptedCredentials" => $this->encryptedCredintials(),
                "payGateTranID" => $this->trackingCode()
            );

            $soap = new SoapClient($this->serverUrl, $this->config);
            $response = $soap->RequestVerification($fields);

            if ($response->RequestVerificationResult != '500') {
                $this->transactionFailed();
                $this->newLog($response->RequestVerificationResult, APException::getError($response->RequestVerificationResult));
                throw new APException($response->RequestVerificationResult);
            }

            // Reconciliation payment
            $fields = array(
                "merchantConfigurationID" => $this->config->get('ap.merchant-config-id'),
                "encryptedCredentials" => $this->encryptedCredintials(),
                "payGateTranID" => $this->trackingCode()
            );

            $soap = new SoapClient($this->serverUrl, $this->config);
            $response = $soap->RequestReconciliation($fields);

            if ($response->RequestReconciliationResult != '600') {
                $this->transactionFailed();
                $this->newLog($response->RequestReconciliationResult, APException::getError($response->RequestReconciliationResult));
                throw new APException($response->RequestReconciliationResult);
            }

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        $this->transactionSucceed();
        $this->newLog('00', self::TRANSACTION_SUCCEED_TEXT);
        return true;
    }

    /**
     * Return Synchronize time or just server time
     *
     * @return string
     */
    protected function syncTime()
    {
        if ($this->config->get('ap.sync-time')) {
            $soap = new SoapClient($this->timeUrl, $this->config);
            $response = $soap->GetPaymentServerTime();
            return $response->GetPaymentServerTimeResult;

        } else {
            $date = new DateTime;
            return $date->format('Ymd His');
        }
    }

    /**
     * Encrypt with Asan Pardakht server
     *
     * @param array $data
     *
     * @return string
     */
    protected function encrypt($data)
    {
        $fields = array(
            'aesKey' => $this->config->get('ap.encryption-key'),
            'aesVector' => $this->config->get('ap.encryption-vector'),
            'toBeEncrypted' => implode(',', $data)
        );

        $soap = new SoapClient('https://services.asanpardakht.net/paygate/internalutils.asmx?wsdl', $this->config);
        $response = $soap->EncryptInAES($fields);

        return $response->EncryptInAESResult;
    }

    /**
     * Return encrypted credintial
     *
     * @return string
     */
    protected function encryptedCredintials()
    {
        if (is_null($this->encryptedCredintial)) {
            $this->encryptedCredintial = $this->encrypt(array(
                $this->config->get('ap.username').','.$this->config->get('ap.password')
            ));
        }

        return $this->encryptedCredintial;
    }
}
