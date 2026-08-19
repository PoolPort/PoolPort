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
        $this->newTransaction();
        $this->authenticate();
        $this->sendPayRequest();

        return $this;
    }

    protected function authenticate()
    {
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
            $username = $this->config->get('smartispay.user-mobile');
            $callbackUrl = $this->buildRedirectUrl($this->config->get('smartispay.callback-url'));
            $useIpg = 'true';
            $terminalId = strval($this->config->get('smartispay.terminal-id'));
            $amount = strval($this->amount);
            $referenceId = strval($this->transactionId());

            $message = $this->buildHmacMessage([
                $username,
                $callbackUrl,
                $useIpg,
                $terminalId,
                $amount,
                $referenceId,
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/legal-ipg/get-payment-url-foreign", [
                'json'        => [
                    'username'    => $username,
                    'callback'    => $callbackUrl,
                    'useIpg'      => true,
                    'terminalId'  => intval($terminalId),
                    'amount'      => intval($amount),
                    'referenceId' => $referenceId,
                ],
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
            $accessToken = $this->getMeta('accessToken');

            if (!$accessToken) {
                throw new SmrtisPayException('Access token not found', -1);
            }

            $response = $this->client->request('GET', "{$this->baseUrl}/wallet-service/v1.0/verify-payment", [
                'query'       => [
                    'uuid' => $this->refId(),
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if (!$response) {
                throw new SmrtisPayException('Invalid response from server', -1);
            }

            if ($response->statusCode != 0) {
                throw new SmrtisPayException(isset($response->message) ? $response->message : 'Verify failed', $response->statusCode);
            }

            if ($response->target !== true) {
                throw new SmrtisPayException('Payment verification failed', -1);
            }

            $this->trackingCode = $this->refId();
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
                $traceId = $this->getTraceIdFromApi($uuid);
            }

            if (!$traceId) {
                throw new SmrtisPayException('TraceId not found in transaction meta', 1001);
            }

            $amount = intval($transaction->price);
            $description = !empty($params['description']) ? $params['description'] : 'استرداد وجه';
            $referenceId = strval($transaction->id);

            $message = $this->buildHmacMessage([
                strval($uuid),
                strval($traceId),
                strval($amount),
                $description,
                $referenceId,
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/refund", [
                'json'        => [
                    'uuid'        => $uuid,
                    'traceId'     => strval($traceId),
                    'amount'      => $amount,
                    'desc'        => $description,
                    'referenceId' => $referenceId,
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
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', $response ? json_encode($response) : 'Empty response');
                throw new SmrtisPayException($response ? $response->message : 'Refund failed', $response ? $response->statusCode : -1);
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

    private function getTraceIdFromApi($uuid)
    {
        try {
            $response = $this->client->request('GET', "{$this->baseUrl}/wallet-service/v1.0/status-personal-payment", [
                'query'       => [
                    'uuid' => $uuid,
                ],
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->getMeta('accessToken'),
                    'Accept'        => '*/*',
                ],
                'http_errors' => false,
            ]);

            $body = $response->getBody()->getContents();
            $response = json_decode($body);

            if ($response && $response->statusCode == 0 && !empty($response->target->traceId)) {
                return $response->target->traceId;
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $this->refundLogin();

            $meta = json_decode($transaction->meta, true);
            $uuid = $transaction->ref_id;
            $traceId = !empty($meta['traceId']) ? $meta['traceId'] : null;

            if (!$traceId) {
                $traceId = $this->getTraceIdFromApi($uuid);
            }

            if (!$traceId) {
                throw new SmrtisPayException('TraceId not found in transaction meta', 1001);
            }

            $amount = intval($amount);
            $description = !empty($params['description']) ? $params['description'] : 'استرداد وجه';
            $referenceId = strval($transaction->id);

            $message = $this->buildHmacMessage([
                strval($uuid),
                strval($traceId),
                strval($amount),
                $description,
                $referenceId,
            ]);

            $xHash = $this->generateHmac($message, $this->config->get('smartispay.secret-key'));

            $response = $this->client->request('POST', "{$this->baseUrl}/wallet-service/v1.0/refund", [
                'json'        => [
                    'uuid'        => $uuid,
                    'traceId'     => strval($traceId),
                    'amount'      => $amount,
                    'desc'        => $description,
                    'referenceId' => $referenceId,
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
                $this->newLog($response ? $response->statusCode : 'UNKNOWN', $response ? json_encode($response) : 'Empty response');
                throw new SmrtisPayException($response ? $response->message : 'Refund failed', $response ? $response->statusCode : -1);
            }

            $this->newLog('Refunded', json_encode($response));

            return true;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
