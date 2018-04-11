<?php

namespace PoolPort\Pay;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Pay extends PortAbstract implements PortInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'https://pay.ir/payment/send';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://pay.ir/payment/verify';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://pay.ir/payment/gateway/';

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
        header('Location: '.$this->gateUrl.$this->refId);
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
     * @throws PaySendException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $fields = array(
            'api' => $this->config->get('pay.api'),
            'amount' => $this->amount,
            'redirect' => urlencode($this->buildQuery($this->config->get('pay.callback-url'), array('transaction_id' => $this->transactionId))),
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['status']) && $response['status'] === 1 && $response['transId'] > 0) {
            $this->refId = $response['transId'];
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @PaySendException::$errors[@$response['errorCode']]);
        throw new PaySendException(@$response['errorCode']);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws PayReceiveException
     */
    protected function userPayment()
    {
        $status = @$_POST['status'];
        $this->trackingCode = @$_POST['transId'];
        $this->cardNumber = @$_POST['cardNumber'];

        if ($status == 1) {
            return true;
        }

	    $this->transactionFailed();
        $this->newLog($status, @PayReceiveException::$errors[$status]);
	    throw new PayReceiveException($status);
    }

    /**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws PayReceiveException
	*/
	protected function verifyPayment()
    {
        $fields = array(
            'api' => $this->config->get('pay.api'),
            'transId' => $this->trackingCode()
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['status']) && $response['status'] == 1 && $response['amount'] == $this->amount) {
    		$this->transactionSucceed();
    		$this->newLog($response['status'], self::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @PayReceiveException::$errors[@$response['status']]);
        throw new PayReceiveException(@$response['status']);
    }
}
