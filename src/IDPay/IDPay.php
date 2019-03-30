<?php

namespace PoolPort\IDPay;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\IDPay\IDPaySendException;

class IDPay extends PortAbstract implements PortInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'https://api.idpay.ir/v1.1/payment';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://api.idpay.ir/v1.1/payment/verify';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://idpay.ir/p/ws-sandbox/';

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
     * @throws IDPaySendException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $fields = array(
			'order_id' => $this->transactionId,
			'amount'   => $this->amount,
			'name'     => $this->config->get('idpay.phone'),
			'phone'    => $this->config->get('idpay.phone'),
			'mail'     => $this->config->get('idpay.mail'),
			'desc'     => $this->config->get('idpay.desc'),
			'callback' => $this->config->get('idpay.callback-url')."?transaction_id=".$this->transactionId,
		);


        $ch = curl_init();

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $this->config->get('idpay.api'),
            'X-SANDBOX:' . $this->config->get('idpay.sandbox'),
        ));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['link'])) {
            $this->refId = $response['id'];
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @IDPaySendException::$errors[@$response['errorCode']]);
        throw new IDPaySendException(@$response['errorCode']);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws IDPayReceiveException
     */
    protected function userPayment()
    {
        $status = @$_POST['status'];
        $this->trackingCode = @$_POST['id'];
        $this->cardNumber = @$_POST['card_no'];
        $this->refId=@$_POST['track_id'];

        if ($status == 10) {
            return true;
        }

	    $this->transactionFailed();
      $this->newLog($status, @IDPayReceiveException::$errors[$status]);
	    throw new IDPayReceiveException($status);
    }

    /**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws IDPayReceiveException
	*/
	protected function verifyPayment()
    {
        $fields = array(
            'id' => $this->trackingCode,
            'order_id' => $this->transactionId
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $this->config->get('idpay.api'),
            'X-SANDBOX:' . $this->config->get('idpay.sandbox'),
        ));



        $response = json_decode(curl_exec($ch), true);

        curl_close($ch);

        if (isset($response['status']) && $response['status'] == 100 && $response['amount'] == $this->amount) {

    		$this->transactionSucceed();
    		$this->newLog($response['status'], self::TRANSACTION_SUCCEED_TEXT);
            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['status'], @IDPayReceiveException::$errors[@$response['status']]);
        throw new IDPayReceiveException(@$response['status']);
    }
}
