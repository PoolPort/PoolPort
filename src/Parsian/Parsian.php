<?php

namespace PoolPort\Parsian;

use PoolPort\Config;
use PoolPort\SoapClient;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Parsian extends PortAbstract implements PortInterface
{
	/**
	 * Url of parsian gateway web service
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?wsdl';

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://pec.shaparak.ir/NewIPG/?Token=';

	/**
	 * Address of SOAP server for verify payment
	 *
	 * @var string
	 */
	protected $serverVerifyUrl = 'https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?WSDL';

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
		$this->amount = intval($amount);
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
		$url = $this->gateUrl . $this->refId();

		include __DIR__.'/submitForm.php';
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
	 * Send pay request to parsian gateway
	 *
	 * @return bool
	 *
	 * @throws ParsianErrorException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$params = array(
			'LoginAccount' => $this->config->get('parsian.pin'),
			'Amount' => intval($this->amount),
			'OrderId' => intval($this->transactionId()),
			'CallBackUrl' => $this->buildQuery($this->config->get('parsian.callback-url'), array('transaction_id' => $this->transactionId())),
			'Originator' => $this->config->get('parsian.user-mobile'),
		);

		try{
			$soap = new SoapClient($this->serverUrl, $this->config);
			$response = $soap->SalePaymentRequest(array('requestData' => $params));

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response->SalePaymentRequestResult->Status === 0) {

			$this->refId = $response->SalePaymentRequestResult->Token;
			$this->transactionSetRefId();
			return true;

		} else {
			$this->transactionFailed();
			$this->newLog($response->SalePaymentRequestResult->Status, $response->SalePaymentRequestResult->Message);
			throw new ParsianErrorException($response->SalePaymentRequestResult->Message, $response->SalePaymentRequestResult->Status);
		}
	}

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws ParsianErrorException
	 */
	protected function userPayment()
	{
		$this->refId = @$_POST['Token'];
		$this->trackingCode = @$_POST['RRN'];
		$payRequestResCode = @$_POST['status'];

		if ($payRequestResCode == 0) {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, @ParsianErrorException::$errors[$payRequestResCode]);
		throw new ParsianErrorException($payRequestResCode);
	}


	/**
	 * Verify payment
	 *
	 * @throws ParsianErrorException
	 */
	protected function verifyPayment()
	{
		$params = array(
			'LoginAccount' => $this->config->get('parsian.pin'),
			'Token' => $this->refId(),
		);

		try {
			$soap = new SoapClient($this->serverVerifyUrl, $this->config);
			$result = $soap->ConfirmPayment(array('requestData' => $params));

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($result === false || !isset($result->ConfirmPaymentResult->Status)) {
			$this->transactionFailed();
			$this->newLog(-1, @ParsianErrorException::$errors[-1]);
			throw new ParsianErrorException(-1);
		}

		if ($result->ConfirmPaymentResult->Status !== 0) {
			$this->transactionFailed();
			$this->newLog($result->ConfirmPaymentResult->Status, @ParsianErrorException::$errors[$result->ConfirmPaymentResult->Status]);
			throw new ParsianErrorException($result->ConfirmPaymentResult->Status);
		}

		$this->cardNumber = $result->ConfirmPaymentResult->CardNumberMasked;
		$this->transactionSucceed();
		$this->newLog($result->ConfirmPaymentResult->Status, self::TRANSACTION_SUCCEED_TEXT);

		return true;
	}
}
