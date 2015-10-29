<?php

namespace IPay\Payline;

use IPay\Config;
use IPay\IPayAbstract;
use IPay\IPayInterface;
use IPay\DataBaseManager;

class IPayPayline extends IPayAbstract implements IPayInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'http://payline.ir/payment/gateway-send';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'http://payline.ir/payment/gateway-result-second';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'http://payline.ir/payment/gateway-';

    /**
     * Initialize class
     *
     * @param Config $config
     * @param DataBaseManager $db
     * @param int $portId
     *
     */
    public function __construct(Config $config, DatabaseManager $db, $portId)
    {
        parent::__construct($config, $db, $portId);
    }

    /**
     * This method use for set price in Rial.
     *
     * @param int $amount in Rial
     *
     * @return $this
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Some of the ports can be send additional data to port server.
     * This method for set this additional data.
     *
     * @param array $data
     *
     * @return $this
     */
    public function with(array $data)
    {
        return $this;
    }

    /**
     * This method use for done everything that necessary before redirect to port.
     *
     * @return $this
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * This method use for redirect to port
     *
     * @return mixed
     */
    public function redirect()
    {
        header('Location: '.$this->gateUrl.$this->refId);
    }

    /**
     * Return result of payment
     * If result is done, return true, otherwise throws an related exception
     *
     * @param object $transaction row of transaction in database
     *
     * @return boolean
     */
    public function verify($transaction)
    {
        $this->transaction = $transaction;
        $this->transactionId = $transaction->id;
        $this->amount = $transaction->price;
        $this->refId = $transaction->ref_id;

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws IPayPaylineSendException
     */
    public function sendPayRequest()
    {
        $this->newTransaction();

        $fields = array(
            'api' => $this->config->get('payline.api'),
            'amount' => $this->amount,
            'redirect' => urlencode($this->buildQuery($this->config->get('payline.callback-url'), array('transaction_id' => $this->transactionId))),
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if (is_numeric($response) && $response > 0) {
            $this->refId = $response;
            $this->transactionSetRefId();

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response, IPayPaylineSendException::$errors[$response]);
        throw new IPayPaylineSendException($response);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws IPayPaylineReceiveException
     */
    protected function userPayment()
    {
        $this->refIf = @$_POST['id_get'];
        $this->trackingCode = @$_POST['trans_id'];

        if (is_numeric($this->trackingCode) && $this->trackingCode > 0) {
            return true;
        }

        $this->newLog(-4, IPayPaylineReceiveException::$errors[-4]);
	    $this->transactionFailed();
	    throw new IPayPaylineReceiveException(-4);
    }

    /**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws IPayPaylineReceiveException
	*/
	protected function verifyPayment()
    {
        $fields = array(
            'api' => $this->config->get('payline.api'),
            'id_get' => $this->refId,
            'trans_id' => $this->trackingCode
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response == 1) {
    		$this->newLog($response, self::TRANSACTION_SUCCEED_TEXT);
    		$this->transactionSucceed();

            return true;
        }

        $this->newLog($response, IPayPaylineReceiveException::$errors[$response]);
        $this->transactionFailed();
        throw new IPayPaylineReceiveException($response);
    }
}
