<?php

namespace PoolPort\Zarinpal;

use DateTime;
use PoolPort\Config;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Zarinpal extends PortAbstract implements PortInterface
{
	/**
     * Address of germany SOAP server
     *
     * @var string
     */
    protected $germanyServer = 'https://de.zarinpal.com/pg/services/WebGate/wsdl';

	/**
     * Address of iran SOAP server
     *
     * @var string
     */
    protected $iranServer = 'https://ir.zarinpal.com/pg/services/WebGate/wsdl';

	/**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl;

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://www.zarinpal.com/pg/StartPay/';

	/**
	 * Address of zarin gate for redirect
	 *
	 * @var string
	 */
	protected $zarinGateUrl = 'https://www.zarinpal.com/pg/StartPay/$Authority/ZarinGate';

    /**
     * {@inheritdoc}
     */
	public function __construct(Config $config, DatabaseManager $db, $portId)
	{
		parent::__construct($config, $db, $portId);

		$this->setServer();
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
		switch ($this->config->get('zarinpal.type')) {
			case 'zarin-gate':
				Header('Location: '.str_replace('$Authority', $this->refId, $this->zarinGateUrl));
				break;

			case 'normal':
			default:
				Header('Location: '.$this->gateUrl.$this->refId);
				break;
		}
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
	 * @throws ZarinpalException
     */
    protected function sendPayRequest()
    {
		$this->newTransaction();

        $fields = array(
            'MerchantID' => $this->config->get('zarinpal.merchant-id'),
            'Amount' => $this->amount / 10,
            'CallbackURL' => $this->buildQuery($this->config->get('zarinpal.callback-url'), array('transaction_id' => $this->transactionId)),
			'Description' 	=> $this->config->get('zarinpal.description', ''),
			'Email' 	=> $this->config->get('zarinpal.user-email', ''),
			'Mobile' 	=> $this->config->get('zarinpal.user-mobile', ''),
        );

        try {
            $soap = new SoapClient($this->serverUrl, $this->config);
            $response = $soap->PaymentRequest($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response->Status != 100) {
            $this->transactionFailed();
			$this->newLog($response->Status, ZarinpalException::$errors[$response->Status]);
            throw new ZarinpalException($response->Status);
		}

        $this->refId = $response->Authority;
		$this->transactionSetRefId($this->transactionId);
    }

	/**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws ZarinpalException
     */
    protected function userPayment()
    {
        $this->authority = @$_GET['Authority'];
        $status = @$_GET['Status'];

        if ($status == 'OK') {
			return true;
        }

	    $this->transactionFailed();
		$this->newLog(-22, ZarinpalException::$errors[-22]);
	    throw new ZarinpalException(-22);
    }

	/**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws ZarinpalException
	*/
	protected function verifyPayment()
	{

		$fields = array(
			'MerchantID' => $this->config->get('zarinpal.merchant-id'),
			'Authority' => $this->refId,
			'Amount' => $this->amount / 10,
		);

        try {
    		$soap = new SoapClient($this->serverUrl, $this->config);
    		$response = $soap->PaymentVerification($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

		if ($response->Status != 100) {
            $this->transactionFailed();
			$this->newLog($response->Status, ZarinpalException::$errors[$response->Status]);
            throw new ZarinpalException($response->Status);
		}

		$this->trackingCode = $response->RefID;
		$this->transactionSucceed();
		$this->newLog($response->Status, self::TRANSACTION_SUCCEED_TEXT);
		return true;
	}

	/**
	 * Set server for soap transfers data
	 *
	 * @return void
	 */
	protected function setServer()
	{
		$server = $this->config->get('zarinpal.server', 'germany');
		switch ($server)
		{
			case 'iran':
				$this->serverUrl = $this->iranServer;
			break;

			case 'germany':
			default:
				$this->serverUrl = $this->germanyServer;
			break;
		}
	}
}
