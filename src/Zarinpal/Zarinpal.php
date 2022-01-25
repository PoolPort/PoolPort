<?php

namespace PoolPort\Zarinpal;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use GuzzleHttp\Exception\ClientException;

class Zarinpal extends PortAbstract implements PortInterface
{
	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://www.zarinpal.com/pg/StartPay/$Authority';

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
		Header('Location: '.str_replace('$Authority', $this->refId, $this->gateUrl));
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

        try {
			$client = new Client();
			$res = $client->request("POST", "https://api.zarinpal.com/pg/v4/payment/request.json", [
				"json" => [
					'merchant_id' => $this->config->get('zarinpal.merchant-id'),
					'amount' => $this->amount,
					'callback_url' => $this->buildQuery($this->config->get('zarinpal.callback-url'), array('transaction_id' => $this->transactionId)),
					'description' 	=> $this->config->get('zarinpal.description', ''),
					'email' 	=> $this->config->get('zarinpal.user-email', ''),
					'mobile' 	=> $this->config->get('zarinpal.user-mobile', ''),
				]
			]);

			$res = json_decode($res->getBody()->getContents());

        } catch(ClientException $e) {
			$res = json_decode($e->getResponse()->getBody()->getContents());

            $this->transactionFailed();
			$this->newLog($res->errors->code, $res->errors->message);
            throw $e;

        } catch(\Exception $e) {
			$this->transactionFailed();
			$this->newLog('Error', $e->getMessage());
			throw $e;
		}

        if ($res->data->code != 100) {
            $this->transactionFailed();
			$this->newLog($res->data->code, $res->data->message);
            throw new ZarinpalException($res->data->code, $res->data->message);
		}

        $this->refId = $res->data->authority;
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
		$this->newLog("Error", "NOK");
	    throw new ZarinpalException("Error");
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
        try {
			$client = new Client();
			$res = $client->request("POST", "https://api.zarinpal.com/pg/v4/payment/verify.json", [
				"json" => [
					'merchant_id' => $this->config->get('zarinpal.merchant-id'),
					'amount' => $this->amount,
					'authority' => $this->refId,
				]
			]);

			$res = json_decode($res->getBody()->getContents());

        } catch(ClientException $e) {
			$res = json_decode($e->getResponse()->getBody()->getContents());

            $this->transactionFailed();
			$this->newLog($res->errors->code, $res->errors->message);
            throw $e;

        } catch(\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw $e;
        }

		if ($res->data->code != 100) {
            $this->transactionFailed();
			$this->newLog($res->data->code, $res->data->message);
            throw new ZarinpalException($res->data->code, $res->data->message);
		}

		$this->trackingCode = $res->data->ref_id;
		$this->cardNumber = $res->data->card_pan;

		$this->transactionSucceed();
		$this->newLog($res->data->code, self::TRANSACTION_SUCCEED_TEXT);
		return true;
	}
}
