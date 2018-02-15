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

        $response = json_decode(curl_exec($ch));
        curl_close($ch);

        if (isset($response->status) && $response->status === 1 && $response->transId > 0) {
            $this->refId = $response->transId;
            $this->transactionSetRefId();
            return true;
        }

        $this->transactionFailed();
        $this->newLog($response, PaySendException::$errors[$response]);
        throw new PaySendException($response);
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
        $this->refIf = @$_POST['id_get'];
        $trackingCode = @$_POST['trans_id'];

        if (is_numeric($trackingCode) && $trackingCode > 0) {
            $this->trackingCode = $trackingCode;
            return true;
        }

	    $this->transactionFailed();
        $this->newLog(-4, PayReceiveException::$errors[-4]);
	    throw new PayReceiveException(-4);
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
            'id_get' => $this->refId(),
            'trans_id' => $this->trackingCode()
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response == 1) {
    		$this->transactionSucceed();
    		$this->newLog($response, self::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response, PayReceiveException::$errors[$response]);
        throw new PayReceiveException($response);
    }
}
