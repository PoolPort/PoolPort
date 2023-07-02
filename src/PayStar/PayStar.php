<?php

namespace PoolPort\PayStar;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class PayStar extends PortAbstract implements PortInterface
{
	/**
	 * Url of PayStar CURL service
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://core.paystar.ir/api/pardakht/create?';

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://core.paystar.ir/api/pardakht/payment?token=';

	/**
	 * Address of CURL server for verify payment
	 *
	 * @var string
	 */
	protected $serverVerifyUrl = 'https://core.paystar.ir/api/pardakht/verify?';

	/**
	 * Payment Token
	 *
	 * @var string
	 */
	 protected $token;

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
		header('Location: '.$this->gateUrl.$this->token);
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
	 * Send pay request to PayStar gateway
	 *
	 * @return bool
	 *
	 * @throws PayStarErrorException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$params = array(
			'amount' => intval($this->amount),
			'order_id' => intval($this->transactionId()),
			'callback' => $this->buildRedirectUrl($this->config->get('paystar.callback-url')),
			'phone' => $this->config->get('paystar.user-mobile'),
		);

		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->serverUrl.http_build_query($params),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $params,
			CURLOPT_HTTPHEADER => array(
			  'Authorization: Bearer '.$this->config->get('paystar.pin'),
			  'Content-Type: application/json'
			),
		  ));

		$response = json_decode(curl_exec($curl));
		curl_close($curl);

		if($response->status == 1) {
			$this->refId = $response->data->ref_num;
			$this->set($response->data->payment_amount);
			$this->transactionSetRefId();
			$this->token = $response->data->token;
			return true;
		}

		$this->transactionFailed();
		$this->newLog($response->status, PayStarErrorException::$errors[$response->status]);
		throw new PayStarErrorException($response);
	}

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws PayStarErrorException
	 */
	protected function userPayment()
	{
		$this->refId = @$_POST['ref_num'];
		$this->transactionId = @$_POST['order_id'];
		$this->cardNumber = @$_POST['card_number'];
		$this->trackingCode = @$_POST['tracking_code'];
		$payRequestResCode = @$_POST['status'];

		if ($payRequestResCode == 1) {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, @PayStarErrorException::$errors[$payRequestResCode]);
		throw new PayStarErrorException($payRequestResCode);
	}


	/**
	 * Verify payment
	 *
	 * @throws PayStarErrorException
	 */
	protected function verifyPayment()
	{
		$params = array(
			'ref_num' => $this->refId(),
			'amount' => intval($this->amount),
		);

		$ch = curl_init($this->serverVerifyUrl.http_build_query($params));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $h = array('Authorization: Bearer '.$this->config->get('paystar.pin'), 'Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$result = json_decode(curl_exec($ch));
		curl_close($ch);

		if ($result === false || !isset($result->status)) {
			$this->transactionFailed();
			$this->newLog(-1, @PayStarErrorException::$errors[-1]);
			throw new PayStarErrorException(-1);
		}

		if ($result->ConfirmPaymentResult->status != 1) {
			$this->transactionFailed();
			$this->newLog($result->ConfirmPaymentResult->status, @PayStarErrorException::$errors[$result->ConfirmPaymentResult->status]);
			throw new PayStarErrorException($result->ConfirmPaymentResult->status);
		}

		$this->cardNumber = $result->data->card_number;
		$this->transactionSucceed();
		$this->newLog($result->ConfirmPaymentResult->status, self::TRANSACTION_SUCCEED_TEXT);

		return true;
	}
}
