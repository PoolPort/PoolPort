<?php

namespace PoolPort\Vandar;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Vandar extends PortAbstract implements PortInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'https://ipg.vandar.io/api/v3';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = "https://ipg.vandar.io/v3";

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
        header('Location: '.$this->gateUrl."/".$this->refId());
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
     * @throws VandarException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $fields = array(
            'api_key' => $this->config->get('vandar.api_key'),
            'amount' => $this->amount,
            'callback_url' => $this->buildRedirectUrl($this->config->get('vandar.callback-url')),
            'mobile_number' => $this->config->get('jibit.user-mobile'),
            'factorNumber' => '',
            'description' => '',
            'valid_card_number' => ''
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl."/send");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['status']) && $response['status'] === 1) {
            $this->refId = $response['token'];
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @$response['errors'][0]);
        throw new VandarException(@$response['errors'][0], @$response['status']);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws VandarException
     */
    protected function userPayment()
    {
        $token = @$_GET['token'];
        $paymentStatus = @$_GET['payment_status'];

        if ($paymentStatus == 'OK') {
            return true;
        }

	    $this->transactionFailed();
        $this->newLog(0, $paymentStatus);
	    throw new VandarException($paymentStatus, 0);
    }

    /**
	* Verify user payment from vandar server
	*
	* @return bool
	*
	* @throws VandarException
	*/
	protected function verifyPayment()
    {
        $ch = curl_init();

        $fields = array(
            'api_key' => $this->config->get('vandar.api_key'),
            'token' => $this->refId()
        );

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl."/verify");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['status']) && $response['status'] == 1) {
            $this->cardNumber = $response['cardNumber'];
    		$this->transactionSucceed();
    		$this->newLog(0, self::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @$response['message']);
        throw new VandarException(@$response['message'], @$response['status']);
    }
}
