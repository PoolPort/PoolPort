<?php

namespace PoolPort\JiBit;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class JiBit extends PortAbstract implements PortInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'https://pg.jibit.mobi';

    /**
     * Address of main CURL server
     * Set after sendPayRequest
     *
     * @var string
     */
    protected $gateUrl = null;

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
        header('Location: '.$this->gateUrl);
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
     * @throws JiBitException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $token = $this->getToken();

        $fields = array(
            'amount' => $this->amount,
            'callBackUrl' => $this->buildQuery($this->config->get('jibit.callback-url'), array('transaction_id' => $this->transactionId)),
            'userIdentity' => $this->config->get('jibit.user-mobile'),
            'merchantOrderId' => $this->config->get('jibit.merchant-id')
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl."/order/initiate");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer '.$token,
            'Content-Type: application/json'
        ));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['errorCode']) && $response['errorCode'] === 0) {
            $this->refId = $response['result']['orderId'];
            $this->gateUrl = $response['result']['redirectUrl'];
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['errorCode'], @$response['message']);
        throw new JiBitException(@$response['message'], @$response['errorCode']);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws JiBitException
     */
    protected function userPayment()
    {
        $status = @$_GET['status'];

        if ($status == 'PURCHASE_BY_USER') {
            return true;
        }

	    $this->transactionFailed();
        $this->newLog($status, @JiBitException::$paymentErrors[$status]);
	    throw new JiBitException($status.@JiBitException::$paymentErrors[$status]);
    }

    /**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws JiBitException
	*/
	protected function verifyPayment()
    {
        $token = $this->getToken();

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl.'/order/verify/'.$this->refId);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer '.$token,
            'Content-Type: application/json'
        ));


        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['errorCode']) && $response['errorCode'] == 0) {
    		$this->transactionSucceed();
    		$this->newLog(0, self::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog(@$response['errorCode'], @$response['message']);
        throw new JiBitException(@$response['message'], @$response['errorCode']);
    }

    /**
     * Get a token from server
     *
     * @return string
     *
     * @throws JiBitException
     */
    protected function getToken()
    {
        $fields = array(
            'username' => $this->config->get('jibit.merchant-id'),
            'password' => $this->config->get('jibit.password'),
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl."/authenticate");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['errorCode']) && $response['errorCode'] === 0) {
            $token = $response['result']['token'];
        } else {
            $this->transactionFailed();
            $this->newLog(@$response['errorCode'], @$response['message']);
            throw new JiBitException(@$response['message'], @$response['errorCode']);
        }

        return $token;
    }
}
