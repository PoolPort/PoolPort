<?php

namespace PoolPort\AldyPay;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class AldyPay extends PortAbstract implements PortInterface
{
    /**
     * API Endpoint
     *  - prod: https://bo.aldypay.com
     *  - dev:  https://gateway.uat.ziapp360.ir
     *
     * @var string
     */
    protected $apiUrl = 'https://bo.aldypay.com';

    private $authToken;

    /**
     * items of invoice
     *
     * @var array
     */
    private $items;

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
     * Send pay request to server
     *
     * @return void
     *
     * @throws AldyPayException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $this->buildRedirectUrl($this->config->get('aldypay.callback-url'));

            $this->refId = $this->transactionId();
            $this->transactionSetRefId();

            $this->setMeta([
                'invoice_number'         => $this->transactionId(),
                'order_items'            => $this->items,
                'amount'                 => $this->amount,
                'refunded_amount'        => 0,
                'created_at'             => now()->format('Y-m-d H:i:s'),
                'is_transaction_created' => false,
                'is_invoice_attached'    => false
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {

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
     * In aldyPay, verify payment will call create transaction api
     * - Because use OTP system instead of WPG (website payment gateway)
     *
     * @return void
     *
     * @throws AldyPayException
     */
    protected function verifyPayment()
    {
        try {
            $this->authLogin();

            $meta = $this->getMeta();

            if ($meta['is_transaction_created'] === true) {
                return;
            }

            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/aldypay/transactions", [
                'http_errors' => false, // prevent throwing exceptions on 4xx/5xx
                "headers"     => [
                    'Authorization' => "Bearer {$this->authToken}",
                ],
                "json"        => [
                    'code'        => $meta['code_meli'],
                    'password'    => $_POST['otp_code'],
                    'amount'      => $meta['amount'],
                    'store_code'  => $this->config->get('aldypay.store-code'),
                    'order_items' => $meta['order_items']
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (!isset($response->data->ledgerTransactionIds)) {
                throw new AldyPayException(json_encode($response), $statusCode);
            }

            $this->setMeta(['is_transaction_created' => true]);

            $ledgerTransactionIds = $response->data->ledgerTransactionIds;
            $this->refId = isset($ledgerTransactionIds[0]) ? $ledgerTransactionIds[0] : null;
            $this->transactionSetRefId();

            $this->attachInvoiceToTransaction($meta['invoice_number'], $meta, $ledgerTransactionIds);

            $this->trackingCode = $this->refId;
            $this->transactionSucceed();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function authLogin()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/auth/login", [
                "json" => [
                    'username' => $this->config->get('aldypay.auth-username'),
                    'password' => $this->config->get('aldypay.auth-password')
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (!isset($response->access_token)) {
                throw new AldyPayException(json_encode($response), $statusCode);
            }

            $this->authToken = $response->access_token;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Attach invoice to transaction is required for refund and partial refund
     * - This attach have to run immediately after transaction is created
     * - invoice_number is PoolPort transaction id that will be attach to transaction_number in aldyPay system
     *
     * @param int   $invoiceNumber
     * @param array $meta
     *
     * @return bool
     * @throws AldyPayException
     */
    public function attachInvoiceToTransaction($invoiceNumber, $meta, $ledgerTransactionIds = [])
    {
        try {
            if ($meta['is_invoice_attached'] === true) {
                return;
            }

            $client = new Client();
            $transactionIds = !empty($ledgerTransactionIds) ? $ledgerTransactionIds : [$this->refId()];

            foreach ($transactionIds as $transactionId) {
                $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/invoice", [
                    "json"    => [
                        "transaction_number" => $transactionId,
                        "invoice_number"     => "{$invoiceNumber}"
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->authToken
                    ]
                ]);

                $statusCode = $response->getStatusCode();
                $response = json_decode($response->getBody()->getContents());

                if (!isset($response->data->message) || $statusCode !== 200) {
                    throw new AldyPayException(json_encode($response), $statusCode);
                }
            }

            $this->setMeta(['is_invoice_attached' => true]);
            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * add item to invoice
     *
     * @return $this
     */
    public function addItem($item)
    {
        $this->items = $item;

        return $this;
    }

    /**
     * Send OTP code to user by code-meli
     *
     * @param string $codeMeli
     * @param int    $transactionId
     *
     * @return bool|PoolPortException
     */
    public function sendOTP($codeMeli, $transactionId)
    {
        try {
            $this->authLogin();

            $this->setTransactionId($transactionId);

            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/auth/otp", [
                'http_errors' => false,
                "json"        => [
                    'code' => $codeMeli
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->authToken
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (isset($response->data->status) && $response->data->status === true) {
                $this->setMeta(['code_meli' => $codeMeli]);
                return true;
            }

            $errorCode = isset($response->code) ? $response->code : $statusCode;
            throw new AldyPayException(json_encode($response), $errorCode);

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refund user payment
     *
     * @return bool
     *
     * @throws AldyPayException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->authLogin();

            $this->setTransactionId($transaction->id);

            $meta = json_decode($transaction->meta, true);

            $invoiceNumber = $meta['invoice_number'];

            $refundedAmount = $meta['refunded_amount'];
            $amount = $transaction->price - $refundedAmount;
            if ($amount <= 0) {
                throw new AldyPayException('Refund amount is not valid', 400);
            }

            $client = new Client();

            $description = isset($params['description']) ? $params['description'] : '';

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/aldypay/refund", [
                'http_errors' => false,
                "json"        => [
                    "amount"         => (int)$amount,
                    "store_code"     => $this->config->get('aldypay.store-code'),
                    "description"    => $description,
                    "invoice_number" => "{$invoiceNumber}"
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->authToken
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (!isset($response->data->refundLedgerTransactionIds)) {
                throw new AldyPayException(json_encode($response), $statusCode);
            }

            $this->setMeta(['refunded_amount' => $amount + $refundedAmount]);

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Partial refund user payment
     *
     * @return bool
     *
     * @throws AldyPayException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $this->authLogin();

            $this->setTransactionId($transaction->id);

            $meta = json_decode($transaction->meta, true);

            $invoiceNumber = $meta['invoice_number'];

            $totalRefundedAmount = $amount + $meta['refunded_amount'];
            if ($totalRefundedAmount > $transaction->price) {
                throw new AldyPayException('Partial refund amount is not valid', 400);
            }

            $client = new Client();

            $description = isset($params['description']) ? $params['description'] : '';

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/aldypay/refund", [
                "json"    => [
                    "amount"         => (int)$amount,
                    "store_code"     => $this->config->get('aldypay.store-code'),
                    "description"    => $description,
                    "invoice_number" => "{$invoiceNumber}"
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if (!isset($response->data->refundLedgerTransactionIds)) {
                throw new AldyPayException(json_encode($response), $statusCode);
            }

            $this->setMeta(['refunded_amount' => $totalRefundedAmount]);

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get wallet assets of an user
     */
    public function getWalletAssets($code, $password)
    {
        $this->authLogin();

        $client = new Client();

        try {
            $response = $client->request('POST', "{$this->apiUrl}/api/v1/vendors/aldypay/assets", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken
                ],
                'json'    => [
                    'code'     => $code,
                    'password' => $password
                ]
            ]);

            $response = json_decode($response->getBody(), true);

            return $response;

        } catch (\Exception $e) {
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}