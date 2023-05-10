<?php

namespace PoolPort\Saman;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Saman extends PortAbstract implements PortInterface
{
    /**
     * Address of main server
     *
     * @var string
     */
    protected $serverUrl = 'https://sep.shaparak.ir/MobilePG/MobilePayment';

    /**
     * Address of SOAP server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://verify.sep.ir/Payments/ReferencePayment.asmx?WSDL';

    /**
     * @var string|null
     */
    protected $token;

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

        require 'SamanRedirector.php';
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
     * @throws SamanException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $fields = array(
            'Action' => 'token',
            'Amount' => $this->amount,
            'TerminalId' => $this->config->get('saman.terminal-id'),
            'ResNum' => $this->transactionId(),
            'RedirectURL' => $this->buildRedirectUrl($this->config->get('saman.callback-url')),
            'CellNumber' => $this->config->get('saman.user-mobile'),
        );

        try {
            $client = new Client();
            $response = $client->request('POST', $this->serverUrl, [
                'json' => $fields,
                'curl' => [
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:@SECLEVEL=1'
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

        } catch(\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw $e;
        }

        if ($response->status == 1) {
            $this->token = $response->token;

        } else {
            $this->transactionFailed();
            $this->newLog($response->errorCode, $response->errorDesc);
            throw new SamanException($response->errorDesc, $response->errorCode);
        }
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws SamanException
     */
    protected function userPayment()
    {
        $state = @$_POST['State'];
        $stateCode = intval(@$_POST['Status']);
        $this->refId = @$_POST['RefNum'];
        $this->trackingCode = @$_POST['TraceNo'];
        $this->cardNumber = @$_POST['SecurePan'];

        if ($stateCode == 2) {
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog($stateCode, $state);
        throw new SamanException($state, $stateCode);
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws SamanException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        try {
            $soap = new SoapClient($this->serverVerifyUrl, $this->config);
            $response = $soap->verifyTransaction($this->refId, $this->config->get('saman.terminal-id'));

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response == $this->amount) {
            $this->transactionSucceed();
            $this->newLog('100', self::TRANSACTION_SUCCEED_TEXT);
            return true;
        } else {
            $this->transactionFailed();
            $this->newLog($response, @SamanException::$errors[$response]);
            throw new SamanException(@SamanException::$errors[$response], $response);
        }
    }
}
