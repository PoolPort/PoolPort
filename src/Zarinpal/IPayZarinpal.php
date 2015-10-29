<?php

namespace IPay\Zarinpal;

use DateTime;
use SoapClient;
use IPay\Config;
use IPay\IPayAbstract;
use IPay\IPayInterface;
use IPay\DataBaseManager;
use IPay\Zarinpal\IPayZarinpalException;

class IPayZarinpal extends IPayAbstract implements IPayInterface
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

		$this->setServer();
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
		$this->amount = ($amount / 10);

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
	 * @throws IPayZarinpalException
     */
    public function sendPayRequest()
    {
        $soap = new SoapClient($this->serverUrl);

		$this->newTransaction();

        $fields = array(
            'MerchantID' => $this->config->get('zarinpal.merchant-id'),
            'Amount' => $this->amount,
            'CallbackURL' => $this->buildQuery($this->config->get('zarinpal.callback-url'), array('transaction_id' => $this->transactionId)),
			'Description' 	=> $this->config->get('zarinpal.description', ''),
			'Email' 	=> $this->config->get('zarinpal.email', ''),
			'Mobile' 	=> $this->config->get('zarinpal.mobile', ''),
        );

        $response = $soap->PaymentRequest($fields);

        if ($response->Status != 100) {
			$this->newLog($response->Status, IPayZarinpalException::$errors[$response->Status]);
            throw new IPayZarinpalException($response->Status);
		}

        $this->refId = $response->Authority;
		$this->transactionSetRefId($this->transactionId);
    }

	/**
     * Check user payment with GET data
     *
     * @return bool
	 *
	 * @throws IPayZarinpalException
     */
    public function userPayment()
    {
        $this->authority = @$_GET['Authority'];
        $status = @$_GET['Status'];

        if ($status == 'OK') {
			return true;
        }

		$this->newLog(-22, IPayZarinpalException::$errors[-22]);
	    $this->transactionFailed();
	    throw new IPayZarinpalException(-22);
    }

	/**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*
	* @throws IPayZarinpalException
	*/
	public function verifyPayment()
	{
		$soap = new SoapClient($this->serverUrl);

		$fields = array(
			'MerchantID' => $this->config->get('zarinpal.merchant-id'),
			'Authority' => $this->refId,
			'Amount' => $this->amount,
		);

		$response = $soap->PaymentVerification($fields);

		if ($response->Status != 100) {
			$this->newLog($response->Status, IPayZarinpalException::$errors[$response->Status]);
            $this->transactionFailed();
            throw new IPayZarinpalException($response->Status);
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
	public function setServer()
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
