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
	protected $serverUrl = 'https://pec.shaparak.ir/pecpaymentgateway/eshopservice.asmx?wsdl';

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://pec.shaparak.ir/pecpaymentgateway/default.aspx?au=';

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
			'pin' => $this->config->get('parsian.pin'),
			'amount' => $this->amount,
			'orderId' => $this->transactionId(),
			'callbackUrl' => $this->buildQuery($this->config->get('parsian.callback-url'), array('transaction_id' => $this->transactionId())),
			'authority' => 0,
			'status' => 1
		);

		try{
			$soap = new SoapClient($this->serverUrl, $this->config);
			$response = $soap->PinPaymentRequest($params);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response !== false) {
			$authority = $response->authority;
			$status = $response->status;

			if($authority && $status == 0){
				$this->refId = $authority;
				$this->transactionSetRefId();
				return true;
			}

			$errorMessage = ParsianResult::errorMessage($status);
			$this->transactionFailed();
			$this->newLog($status, $errorMessage);
			throw new ParsianErrorException($errorMessage, $status);

		} else {
			$this->transactionFailed();
			$this->newLog(-1,'خطا در اتصال به درگاه پارسیان');
			throw new ParsianErrorException('خطا در اتصال به درگاه پارسیان',-1);
		}
	}

	/**
	 * Verify payment
	 *
	 * @throws ParsianErrorException
	 */
	protected function verifyPayment()
	{
		if (!isset($_REQUEST['au']) && !isset($_REQUEST['rs']))
			throw new ParsianErrorException('درخواست غیر معتبر',-1);

		$authority = $_REQUEST['au'];
		$status = $_REQUEST['rs'];

		if ($status != 0) {
			$errorMessage = ParsianResult::errorMessage($status);
			$this->newLog($status,$errorMessage);
			throw new ParsianErrorException($errorMessage,$status);
		}

		if ($this->refId != $authority)
			throw new ParsianErrorException('تراکنشی یافت نشد',-1);

		$params = array(
			'pin' => $this->config->get('parsian.pin'),
			'authority' => $authority,
			'status'    => 1
		);

		try {
			$soap = new SoapClient($this->serverUrl, $this->config);
			$result = $soap->PinPaymentEnquiry($params);

		} catch (\SoapFault $e) {
			throw new ParsianErrorException($e->getMessage(), -1);
		}

		if ($result === false || !isset($result->status))
			throw new ParsianErrorException('پاسخ دریافتی از بانک نامعتبر است.',-1);

		if ($result->status != 0) {
			$errorMessage = ParsianResult::errorMessage($result->status);
			$this->transactionFailed();
			$this->newLog($result->status,$errorMessage);
			throw new ParsianErrorException($errorMessage,$result->status);
		}

		$this->trackingCode = $authority;
		$this->transactionSucceed();
	}
}
