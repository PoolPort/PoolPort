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
     *  - dev:  https://devbo.aldypay.com
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
                'invoice_number'  => $this->transactionId(),
                'order_items'     => $this->items,
                'amount'          => $this->amount,
                'refunded_amount' => 0,
                'created_at'      => now()->format('Y-m-d H:i:s'),
                'is_transaction_created' => false,
                'is_invoice_attached'    => false,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
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
     * @param int $transactionId
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
                "json"    => [
                    'code' => $codeMeli,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $response = json_decode($response->getBody()->getContents());

            if ($this->isSuccessResponse($response)) {
                // Save code-meli in meta for verify payment
                $this->setMeta(['code_meli' => $codeMeli]);
                return true;
            }

            throw new AldyPayException(json_encode($response), $statusCode);

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
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

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/poolticket/transactions", [
                'http_errors' => false, // prevent throwing exceptions on 4xx/5xx
                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                ],
                "json" => [
                    'code'          => $meta['code_meli'],
                    'password'      => $_POST['otp_code'],
                    'amount'        => $meta['amount'],
                    'store_code'    => $this->config->get('aldypay.store-code'),
                    'order_items'   => $meta['order_items']
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($this->isErrorResponse($response)) {
                throw new AldyPayException(json_encode($response), $response->code);
            }

            $this->setMeta(['is_transaction_created' => true]);

            $this->refId = $response->data->transaction_number;
            $this->transactionSetRefId();

            $this->attachInvoiceToTransaction($meta['invoice_number'], $meta);

            $this->trackingCode = $response->data->transaction_number;
            $this->transactionSucceed();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Attach invoice to transaction is required for refund and partial refund
     * - This attach have to run immediately after transaction is created
     * - invoice_number is PoolPort transaction id that will be attach to transaction_number in aldyPay system
     *
     * @param int $invoiceNumber
     * @param array $meta
     * @return bool
     * @throws AldyPayException
     */
    public function attachInvoiceToTransaction($invoiceNumber, $meta)
    {
        try {
            if ($meta['is_invoice_attached'] === true) {
                return;
            }

            $refId = $this->refId();
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/poolticket/invoice", [
                "json"    => [
                    "transaction_number" => "{$refId}",
                    "invoice_number" => "{$invoiceNumber}",
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($this->isSuccessResponse($response)) {
                $this->setMeta(['is_invoice_attached' => true]);
                return true;
            }

            throw new AldyPayException(json_encode($response), $response->code);

        } catch (\Exception $e) {
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
                    'password' => $this->config->get('aldypay.auth-password'),
                ],
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($this->isErrorResponse($response)) {
                throw new AldyPayException(json_encode($response), $response->code);
            }

            $this->authToken = $response->data->access_token;

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

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/poolticket/refund", [
                'http_errors' => false, // prevent throwing exceptions on 4xx/5xx
                "json"    => [
                    "amount"      => (int) $amount,
                    "store_code"  => $this->config->get('aldypay.store-code'),
                    "description" => $params['description'] ?? '',
                    "invoice_number" => "{$invoiceNumber}",
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($this->isErrorResponse($response)) {
                throw new AldyPayException(json_encode($response), $response->code);
            }

            // Update refunded_amount in meta
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

            $response = $client->request("POST", "{$this->apiUrl}/api/v1/vendors/poolticket/refund", [
                "json"    => [
                    "amount" => (int) $amount,
                    "store_code" => $this->config->get('aldypay.store-code'),
                    "description" => $params['description'] ?? '',
                    "invoice_number" => "{$invoiceNumber}",
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($this->isErrorResponse($response)) {
                throw new AldyPayException(json_encode($response), $response->code);
            }

            // Update refunded_amount in meta
            $this->setMeta(['refunded_amount' => $totalRefundedAmount]);

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check if response is success
     *
     * @param object $response
     * @return bool
     */
    protected function isSuccessResponse($response)
    {
        return isset($response->status) && $response->status === true;
    }

    /**
     * Check if response is error
     *
     * @param object $response
     * @return bool
     */
    protected function isErrorResponse($response)
    {
        return !isset($response->status) || $response->status !== true;
    }

    /**
     * Get transactions of an user
     */
    public function fetchTransaction($code = null)
    {
        $this->authLogin();

        $client = new Client();

        try {
            $response = $client->request('GET', "{$this->apiUrl}/api/v1/vendors/poolticket/transactions", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'per_page' => 5,
                    'page'     => 1,
                    'code' => $code ?? $this->config->get('aldypay.code'),
                ],
            ]);

            $response = json_decode($response->getBody(), true);

            return $response;

        } catch (\Exception $e) {
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get all transactions
     */
    public function exportTransactions()
    {
        $this->authLogin();

        $client = new Client();

        try {
            $response = $client->request('GET', "{$this->apiUrl}/api/v1/vendors/poolticket/transactions", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'per_page' => 10,
                    'page'     => 1,
                ],
            ]);

            $response = json_decode($response->getBody(), true);

            return $response;

        } catch (\Exception $e) {
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get wallet assets of an user
     */
    public function getWalletAssets($code = null, $password = null)
    {
        $this->authLogin();

        $client = new Client();

        try {
            $response = $client->request('GET', "{$this->apiUrl}/api/v1/vendors/poolticket/assets", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'code' => $code ?? $this->config->get('aldypay.code'),
                    'password' => $password ?? $this->config->get('aldypay.password'),
                ],
            ]);

            $response = json_decode($response->getBody(), true);

            return $response;

        } catch (\Exception $e) {
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}