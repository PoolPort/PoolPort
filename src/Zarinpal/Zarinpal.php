<?php

namespace PoolPort\Zarinpal;

use DateTime;
use SoapClient;
use PoolPort\Config;
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
		$this->amount = ($amount / 10);

		return $this;
	}

    /**
     * {@inheritdoc}
     */
    public function with(array $data)
	{
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
		parent::verify();

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
            'Amount' => $this->amount,
            'CallbackURL' => $this->buildQuery($this->config->get('zarinpal.callback-url'), array('transaction_id' => $this->transactionId)),
			'Description' 	=> $this->config->get('zarinpal.description', ''),
			'Email' 	=> $this->config->get('zarinpal.email', ''),
			'Mobile' 	=> $this->config->get('zarinpal.mobile', ''),
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->PaymentRequest($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
            throw new ZarinpalException('SoapFault', $e->getMessage());
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

		$this->newLog(-22, ZarinpalException::$errors[-22]);
	    $this->transactionFailed();
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
			'Amount' => $this->amount,
		);

        try {
    		$soap = new SoapClient($this->serverUrl);
    		$response = $soap->PaymentVerification($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw new ZarinpalException('SoapFault', $e->getMessage());
        }

		if ($response->Status != 100) {
			$this->newLog($response->Status, ZarinpalException::$errors[$response->Status]);
            $this->transactionFailed();
            throw new ZarinpalException($response->Status);
		}

		$this->trackingCode = $response->RefID;
		$this->newLog($response->Status, self::TRANSACTION_SUCCEED_TEXT);
		$this->transactionSucceed();
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
