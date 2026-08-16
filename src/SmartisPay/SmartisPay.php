<?php

namespace PoolPort\SmartisPay;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class SmartisPay extends PortAbstract implements PortInterface
{
    protected $baseUrl = 'https://api.smartispay.app';

    protected $wpgUrl = 'https://wpg.smartispay.app';

    private $accessToken;

    private $client;

    public function __construct(Config $config, DataBaseManager $db, $portId)
    {
        parent::__construct($config, $db, $portId);

        $clientOptions = [
            'timeout'         => 30,
            'connect_timeout' => 10,
        ];

        if (defined('CURL_CA_BUNDLE_PATH')) {
            $clientOptions['verify'] = CURL_CA_BUNDLE_PATH;
        }

        $this->client = new Client($clientOptions);
    }

    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    public function ready()
    {
        $this->authenticate();
        $this->sendPayRequest();

        return $this;
    }

    protected function authenticate()
    {
        $this->newTransaction();

        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/auth-service/v1.0/get-token-ipg", [
                'json'        => [
                    'username' => $this->config->get('smartispay.username'),
                    'password' => $this->config->get('smartispay.password'),
                ],
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response || $response->statusCode != 0) {
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', json_encode($response));
                throw new SmrtisPayException(json_encode($response), $response ? $response->statusCode : -1);
            }

            $this->accessToken = $response->target->accessToken;
            $this->setMeta([
                'accessToken' => $this->accessToken,
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function sendPayRequest()
    {
        try {
            $message = $this->buildHmacMessage([
                $this->config->get('smartispay.username'),
                $this->buildRedirectUrl($this->config->get('smartispay.callback-url')),
                false,
                $this->config->get('smartispay.terminal-id'),
                $this->amount,
                strval($this->transactionId()),
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $payload = [
                'username'    => $this->config->get('smartispay.username'),
                'callback'    => $this->buildRedirectUrl($this->config->get('smartispay.callback-url')),
                'useIpg'      => false,
                'terminalId'  => intval($this->config->get('smartispay.terminal-id')),
                'amount'      => intval($this->amount),
                'referenceId' => strval($this->transactionId()),
            ];

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/legal-ipg/get-payment-url-foreign", [
                'json'        => $payload,
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'x-hash'        => $xHash,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response || $response->statusCode != 0) {
                $this->transactionFailed();
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', json_encode($response));
                throw new SmrtisPayException(json_encode($response), $response ? $response->statusCode : -1);
            }

            $this->refId = $response->target->uuid;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function buildHmacMessage(array $parts)
    {
        return implode(':', $parts);
    }

    private function generateHmac($message, $secret)
    {
        return hash_hmac('sha256', $message, $secret);
    }

    public function redirect()
    {
        $redirectUrl = "{$this->wpgUrl}?uuid=" . urlencode($this->refId());
        header("Location: $redirectUrl");
        exit;
    }

    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->verifyPayment();

        return $this;
    }

    protected function verifyPayment()
    {
        try {
            $response = $this->client->request('GET', "{$this->baseUrl}/wallet-service/v1.0/status-personal-payment", [
                'query'       => [
                    'uuid' => $this->refId(),
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->getMeta('accessToken'),
                    'Accept'        => '*/*',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response || $response->statusCode != 0 || $response->target->status !== 'TRUE') {
                $this->transactionFailed();
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', json_encode($response));
                throw new SmrtisPayException(json_encode($response), $response ? $response->statusCode : -1);
            }

            $this->trackingCode = $response->target->traceId;
            $this->setMeta([
                'traceId'    => $response->target->traceId,
                'name'       => $response->target->name,
                'family'     => $response->target->family,
                'nationalId' => $response->target->nationalId,
                'authLevel'  => $response->target->authLevel,
            ]);

            $this->transactionSucceed();

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->refundLogin();

            $meta = json_decode($transaction->meta, true);
            $uuid = $transaction->ref_id;
            $traceId = !empty($meta['traceId']) ? $meta['traceId'] : null;

            if (!$traceId) {
                throw new SmrtisPayException('TraceId not found in transaction meta', 1001);
            }

            $message = $this->buildHmacMessage([
                $uuid,
                $traceId,
                $transaction->price,
                !empty($params['description']) ? $params['description'] : '',
                $transaction->id,
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/refund", [
                'json'        => [
                    'uuid'        => $uuid,
                    'traceId'     => $traceId,
                    'amount'      => intval($transaction->price),
                    'desc'        => !empty($params['description']) ? $params['description'] : '',
                    'referenceId' => strval($transaction->id),
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'x-hash'        => $xHash,
                    'Content-Type'  => 'application/json',
                    'Accept'        => '*/*',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response || $response->statusCode != 0) {
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', json_encode($response));
                throw new SmrtisPayException(json_encode($response), $response ? $response->statusCode : -1);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function refundLogin()
    {
        if ($this->accessToken) {
            return;
        }

        $this->authenticate();
    }

    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $this->refundLogin();

            $meta = json_decode($transaction->meta, true);
            $uuid = $transaction->ref_id;
            $traceId = !empty($meta['traceId']) ? $meta['traceId'] : null;

            if (!$traceId) {
                throw new SmrtisPayException('TraceId not found in transaction meta', 1001);
            }

            $message = $this->buildHmacMessage([
                $uuid,
                $traceId,
                $amount,
                !empty($params['description']) ? $params['description'] : '',
                $transaction->id,
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/refund", [
                'json'        => [
                    'uuid'        => $uuid,
                    'traceId'     => $traceId,
                    'amount'      => intval($amount),
                    'desc'        => !empty($params['description']) ? $params['description'] : '',
                    'referenceId' => strval($transaction->id),
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'x-hash'        => $xHash,
                    'Content-Type'  => 'application/json',
                    'Accept'        => '*/*',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response || $response->statusCode != 0) {
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', json_encode($response));
                throw new SmrtisPayException(json_encode($response), $response ? $response->statusCode : -1);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
