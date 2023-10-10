<?php

namespace PoolPort\PNA;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\PoolPortException;

class PNA extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = 'https://pna.shaparak.ir/ref-payment2/RestServices/mts/generateTransactionDataToSign/';

    /**
     * Address of server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://pna.shaparak.ir/ref-payment2/RestServices/mts/verifyMerchantTrans/';

    /**
     * @var string
     */
    protected $sessionId;

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

        require 'PNARedirector.php';
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
     * @throws MellatException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $sessionId = $this->getSessionId();
        $purchaseSigned = $this->signPurchaseParam($sessionId);

        try {
            $client = new Client();
            $response = $client->post('https://pna.shaparak.ir/ref-payment2/RestServices/mts/generateSignedDataToken/', [
                'json' => [
                    'WSContext' => [
                        'SessionId' => $sessionId,
                        'UserId' => $this->config->get('pna.mid'),
                        'Password' => $this->config->get('pna.password'),
                    ],
                    'UniqueId' => $purchaseSigned['UniqueId'],
                    'Signature' => $purchaseSigned['Signature'],
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->Result != 'erSucceed') {
                throw new \Exception($response->Result);
            }

            $this->refId = $response->Token;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return array
     */
    protected function signPurchaseParam($sessionId)
    {
        try {
            $client = new Client();
            $response = $client->post($this->serverUrl, [
                'json' => [
                    'ReserveNum' => $this->transactionId(),
                    'TransType' => 'EN_GOODS',
                    'Amount' => $this->amount,
                    'RedirectUrl' => $this->buildRedirectUrl($this->config->get('pna.callback-url')),
                    'WSContext' => [
                        'SessionId' => $sessionId,
                        'UserId' => $this->config->get('pna.mid'),
                        'Password' => $this->config->get('pna.password'),
                    ],
                    'MobileNo' => $this->config->get('pna.user-mobile'),
                    'UserId' => $this->config->get('pna.user-mobile'),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->Result != 'erSucceed') {
                throw new \Exception($response->Result);
            }

            $input = tmpfile();
            fwrite($input, $response->DataToSign);

            $output = tmpfile();

            openssl_pkcs7_sign(
                stream_get_meta_data($input)['uri'],
                stream_get_meta_data($output)['uri'],
                'file://'.$this->config->get('pna.public-key'),
                ['file://'.$this->config->get('pna.public-key'), $this->config->get('pna.password')],
                [],
                PKCS7_NOSIGS
            );

            $data = file_get_contents(stream_get_meta_data($output)['uri']);

            $parts = explode("\n\n", $data, 2);
            $string = $parts[1];

            $parts1 = explode("\n\n", $string, 2);
            $signature = $parts1[0];

            return [
                'UniqueId' => $response->UniqueId,
                'Signature' => $signature,
            ];

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws MellatException
     */
    protected function userPayment()
    {
        dd($_POST);
        $this->trackingCode = @$_POST['RefNum'];
        $this->cardNumber = (float) @$_POST['CardMaskPan'];
        $state = @$_POST['State'];

        if ($state != 'ok') {
            $this->transactionFailed();
            $this->newLog($state, "");
            throw new PoolPortException($state);
        }

        return true;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws MellatException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $sessionId = $this->getSessionId();

        try {
            $client = new Client();
            $response = $client->post($this->serverVerifyUrl, [
                'json' => [
                    'WSContext' => [
                        'SessionId' => $sessionId,
                        'UserId' => $this->config->get('pna.mid'),
                        'Password' => $this->config->get('pna.password'),
                    ],
                    'Token' => $this->refId(),
                    'RefNum' => $this->trackingCode(),
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            dd($response);
        } catch (\Exception $e) {
            dd($e);
        }
    }

    /**
     * @return string
     */
    protected function getSessionId()
    {
        try {
            $client = new Client();
            $response = $client->post("https://pna.shaparak.ir/ref-payment2/RestServices/mts/merchantLogin/", [
                "json" => [
                    "UserName" => $this->config->get('pna.mid'),
                    "Password" => $this->config->get('pna.password'),
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->Result != 'erSucceed') {
                throw new \Exception($response->Result);
            }

            return $response->SessionId;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('SessionId', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
