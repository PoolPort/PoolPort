<?php

namespace PoolPort\ResalatWallet;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class ResalatWallet extends PortAbstract implements PortInterface
{
    protected $gateUrl = 'https://wallet-bff.rqb.ir';
    protected $apiUrl = 'https://api.rqb.ir/megawallet/api';

    private $accessToken;

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
        $this->authenticate();
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $hostkey = $this->config->get('resalatWallet.hostkey');

        header('Location: ' . "{$this->gateUrl}/wpg/sendtoken?cToken={$this->refId()}&hostkey={$hostkey}");
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
     * @throws DaraException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $client = new Client();

            $body = [
                'hostID'                 => $this->config->get('resalatWallet.hostkey'),
                'nationalCodeCommercial' => $this->config->get('resalatWallet.nationalcode'),
                'mobileNoCommercial'     => $this->config->get('resalatWallet.mobileno'),
                'terminalNo'             => $this->config->get('resalatWallet.terminalno'),
                'amount'                 => 12, // $this->amount,
                'mobileNoPersonal'       => $this->config->get('resalatWallet.user-mobile'),
                'callBackURL'            => $this->buildRedirectUrl($this->config->get('resalatWallet.callback-url'))
            ];

            $hostKey = $this->config->get('resalatWallet.hostkey');
            $apiKey = $this->config->get('resalatWallet.api_key');
            $signatureString = "POST#/megawallet/api/wallet/requesttoken#{$apiKey}#".json_encode($body);

            $response = $client->request("POST", "{$this->apiUrl}/wallet/requesttoken", [
                "headers" => [
                    'Content-Type'   => 'application/json',
                    'APIKey'         => $apiKey,
                    'Signature'      => $this->generateSignature($signatureString),
                    'HostKey'        => $hostKey,
                    'HostSignature'  => $this->generateSignature($hostKey),
                    'Authorization'  => "Bearer {$this->accessToken}",
                    'Accept-Version' => '2',
                ],
                "json" => $body
            ]);

            // Get response body as string
            $rawResponse = $response->getBody()->getContents();

            // Try to decode JSON to Array
            $response = json_decode($rawResponse, true);

            // Handle error response
            if ($this->isErrorResponse($response)) {
                $result = $response['message']['result'];

                $this->transactionFailed();
                $this->newLog($result['errorCode'], $result['errorDesc']);
                throw new ResalatWalletException($result['errorDesc'], (int) $result['errorCode']);

            } elseif (isset($response['status']) && $response['status'] == 401) {
                $this->transactionFailed();
                $this->newLog(401, "Unauthorized (on send pay request)");
                throw new ResalatWalletException("Unauthorized (on send pay request)", 401);
            }

            // Handle success response
            $this->refId = trim('"', $rawResponse);
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws ResalatWalletException
	 */
	protected function userPayment()
	{
		$this->trackingCode = @$_GET['requestNo'];
		$payRequestResCode = @$_GET['status'];

		if ($payRequestResCode == 1) {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, $payRequestResCode);
		throw new ResalatWalletException($payRequestResCode);
	}

    /**
     * Verify user payment from dara server
     *
     * @return bool
     *
     * @throws ResalatWallet
     */
    protected function verifyPayment()
    {
        try {
            $this->authenticate();

            $client = new Client();

            $hostKey = $this->config->get('resalatWallet.hostkey');
            $apiKey = $this->config->get('resalatWallet.api_key');
            $requestNo = $_GET['requestNo'];
            $body = ['requestNo' => $requestNo];
            $signatureString = "GET#/megawallet/api/purchase/requestverifywpg#{$apiKey}#".json_encode($body);

            $response = $client->request("GET", "{$this->apiUrl}/purchase/requestverifywpg", [
                "headers" => [
                    'Content-Type'   => 'application/json',
                    'APIKey'         => $apiKey,
                    'Signature'      => $this->generateSignature($signatureString),
                    'HostKey'        => $hostKey,
                    'HostSignature'  => $this->generateSignature($hostKey),
                    'Authorization'  => "Bearer {$this->accessToken}",
                    'Accept-Version' => '2',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            // Handle error response
            if ($this->isErrorResponse($response)) {
                $result = $response['message']['result'];

                $this->newLog($result['errorCode'], $result['errorDesc']);
                throw new ResalatWalletException($result['errorDesc'], (int) $result['errorCode']);

            } elseif (isset($response['status']) && $response['status'] == 401) {
                $this->newLog(401, "Unauthorized (on verify payment)");
                throw new ResalatWalletException("Unauthorized (on verify payment)", 401);
            }

            // Handle success response
            if ($this->isSuccessResponse($response)) {
                $this->trackingCode = $requestNo;
                $this->transactionSucceed();
                return true;
            }

            throw new ResalatWalletException("Unexpected API response(on verify payment): " . substr(json_encode($response), 0, 200));

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refund user payment
     *
     * @return bool
     *
     * @throws ResalatWalletException
     */
    public function refundPayment($transaction, $params = [])
    {
       try {
            $this->authenticate();

            $this->setTransactionId($transaction->id);

            $meta = json_decode($transaction->meta, true);
            $invoiceNumber = $meta['invoice_number'];

            $hostKey = $this->config->get('resalatWallet.hostkey');
            $apiKey = $this->config->get('resalatWallet.api_key');
            $body = ["requestNo" => "{$invoiceNumber}"];
            $signatureString = "POST#/megawallet/api/b2b/purchasereverse#{$apiKey}#".json_encode($body);

            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/b2b/purchasereverse", [
                "headers" => [
                    'Content-Type'   => 'application/json',
                    'APIKey'         => $apiKey,
                    'Signature'      => $this->generateSignature($signatureString),
                    'HostKey'        => $hostKey,
                    'HostSignature'  => $this->generateSignature($hostKey),
                    'Authorization'  => "Bearer {$this->accessToken}",
                    'Accept-Version' => '2',
                ],
                "json" => $body,
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            if ($this->isErrorResponse($response)) {
                $result = $response['message']['result'];

                $this->newLog($result['errorCode'], $result['errorDesc']);
                throw new ResalatWalletException($result['errorDesc'], (int) $result['errorCode']);
            }

            if ($this->isSuccessResponse($response)) {
                $this->newLog('Refunded', json_encode($response));
                return true;
            }

            throw new ResalatWalletException("Unexpected API response(on refund): " . substr(json_encode($response), 0, 200));

            return false;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function authenticate()
    {
        try {
            $client = new Client();

            $hostKey = $this->config->get('resalatWallet.hostkey');
            $apiKey = $this->config->get('resalatWallet.api_key');

            $body = [
                'mobileNo' => $this->config->get('resalatWallet.mobileno'),
                'nationalCode' => $this->config->get('resalatWallet.nationalcode')
            ];

            $signatureString = "POST#/megawallet/api/jwt/authenticate/commercialtrust#{$apiKey}#".json_encode($body);

            $response = $client->request("POST", "{$this->apiUrl}/jwt/authenticate/commercialtrust", [
                "headers" => [
                    'Content-Type'   => 'application/json',
                    'APIKey'         => $apiKey,
                    'Signature'      => $this->generateSignature($signatureString),
                    'HostKey'        => $hostKey,
                    'HostSignature'  => $this->generateSignature($hostKey),
                    'Accept-Version' => '2'
                ],
                "json" => $body
            ]);

            // Get response body as string
            $rawResponse = $response->getBody()->getContents();

            // Try to decode JSON response to Array
            $response = json_decode($rawResponse, true);

            if ($this->isErrorResponse($response)) {
                $result = $response['message']['result'];

                $this->newLog($result['errorCode'], $result['errorDesc']);
                throw new ResalatWalletException($result['errorDesc'], (int) $result['errorCode']);
            }

            $this->accessToken = trim('"', $rawResponse);

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Generate a signature
     *
     * @return string
     */
    protected function generateSignature($data)
    {
        try {
            $privatePem = file_get_contents($this->config->get('resalatWallet.private_key_file_path'));
            $pathphrase = $this->config->get('resalatWallet.private_key_pathphrase');

            // Load private key (handles passphrase if provided)
            $privateKey = openssl_pkey_get_private($privatePem, $pathphrase);
            if ($privateKey === false) {
                throw new ResalatWalletException("Failed to load private key.");
            }
            // Sign the data using RSA-SHA1 (signature)
            $ok = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA1);

            if ($ok !== true) {
                throw new ResalatWalletException("openssl_sign failed.");
            }

            return base64_encode($signature);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function isErrorResponse($response)
    {
        return is_array($response) && isset($response['message']['result']['errorCode']) 
                && intval($response['message']['result']['errorCode']) !== 0;
    }

    public function isSuccessResponse($response)
    {
        return is_array($response) && isset($response['message']['result']['errorCode']) 
                && intval($response['message']['result']['errorCode']) === 0;
    }
}
