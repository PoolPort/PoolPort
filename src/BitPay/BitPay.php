<?php

namespace PoolPort\BitPay;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class BitPay extends PortAbstract implements PortInterface
{
	/**
	 * Address of main CURL server
	 *
	 * @var string
	 */
	protected $serverUrl = 'http://bitpay.ir/payment';

	/**
	 * Address of CURL server for send payment
	 * @var string
	 */
	protected $serverSendUrl = 'http://bitpay.ir/payment/gateway-send';

	/**
	 * Address of CURL server for verify payment
	 *
	 * @var string
	 */
	protected $serverVerifyUrl = 'http://bitpay.ir/payment/gateway-result-second';

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'http://bitpay.ir/payment/gateway-';

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

		return $this;
	}

	/**
	 * Send pay request to server
	 *
	 * @return Boolean
	 *
	 * @throws BitPaySendException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$fields = array(
			'api' => $this->config->get('bitpay.api'),
			'amount' => $this->amount,
			'redirect' => urlencode($this->buildRedirectUrl($this->config->get('bitpay.callback-url'))),
			'name' => $this->config->get('bitpay.name', ''),
			'email' => $this->config->get('bitpay.email', ''),
			'description' => $this->config->get('bitpay.description', ''),
		);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $this->serverSendUrl);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = json_decode(curl_exec($ch), true);
		curl_close($ch);

		if($response > 0) {
			$this->refId = $response;
			$this->transactionSetRefId();
			return true;
		}

		$this->transactionFailed();
		$this->newLog($response, BitPaySendException::$errors[$response]);
		throw new BitPaySendException($response);
	}

	/**
	 * Check user payment
	 * @return boolean
	 * @throws \PoolPort\BitPay\BitPayReceiveException
	 */
	protected function userPayment()
	{
		$this->trackingCode = $_POST['trans_id'];

		$fields = array(
			'api' => $this->config->get('bitpay.api'),
			'id_get' => $_POST['id_get'],
			'trans_id' => $this->trackingCode,
		);

		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$this->serverVerifyUrl);
		curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		$response = curl_exec($ch);
		curl_close($ch);

		if($response) {
			$this->transactionSucceed();
			$this->newLog($response, self::TRANSACTION_SUCCEED_TEXT);

			return true;
		}

		$this->transactionFailed();
		$this->newLog($response, BitPayReceiveException::$errors[$response]);
		throw new BitPayReceiveException($response);
	}
}
