<?php

namespace PoolPort\MellatStaff;

use PoolPort\Config;
use GuzzleHttp\Client;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use GuzzleHttp\Exception\ClientException;
use PoolPort\Exceptions\PoolPortException;

class MellatStaff extends PortAbstract implements PortInterface
{

    const CANCELED = 'canceled';
    const PENDING  = 'pending';
    const DONE     = 'done';
    const ERROR    = 'error';

    protected $apiUrl = 'https://refahiid.tajan.ir/Api';

    private $authToken;

    private $creditToken;

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
     * @throws MellatStaffException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $this->buildRedirectUrl($this->config->get('mellatStaff.callback-url'));

            $this->authLogin();

            $this->validateMobile();

            $userCredit = $this->getCredit();

            if ($userCredit < $this->amount) {
                throw new MellatStaffException('credit not enough', -1);
            }

            $client = new Client();
            $mobile = $this->config->get('mellatStaff.user-mobile');

            $response = $client->request("GET", "{$this->apiUrl}/Validate/Otp", [
                "query"   => [
                    'mobile' => $mobile
                ],
                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            $this->refId = uniqid();
            $this->transactionSetRefId();

            $this->setMeta([
                'amount'     => $this->amount,
                'mobile'     => $mobile,
                'status'     => self::PENDING,
                'items'      => !empty($this->items) ? $this->items : [],
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from mellat staff server
     *
     * @return bool
     *
     * @throws MellatStaffException
     */
    protected function verifyPayment()
    {
        try {
            $this->authLogin();

            $this->reduceCredit();

            $this->finalizeCredit();

            $this->markStatus(self::DONE);

            $this->trackingCode = $this->refId();

            $this->transactionSucceed();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->markStatus(self::ERROR);
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refund user payment
     *
     * @return bool
     *
     * @throws MellatStaffException
     */
    public function refundPayment($transaction, $params = [])
    {
        try {
            $this->authLogin();

            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/Credit/FullRollBackCredit", [
                "json" => [
                    'token' => $meta['creditToken'],
                ],

                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            $this->newLog('Refunded', json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function authLogin()
    {
        try {
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/Authentication/Login", [
                "json" => [
                    'username' => $this->config->get('mellatStaff.username'),
                    'password' => $this->config->get('mellatStaff.password'),
                ],

                "headers" => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            $this->authToken = $response->authenticationToken;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function validateMobile()
    {
        try {
            $client = new Client();

            $response = $client->request("GET", "{$this->apiUrl}/Validate/Validate", [
                'query'   => [
                    'mobile' => $this->config->get('mellatStaff.user-mobile'),
                ],
                'headers' => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function reduceCredit()
    {
        try {
            $client = new Client();
            $meta = $this->getMeta();

            $response = $client->request("POST", "{$this->apiUrl}/Credit/ReduceCredit", [
                "json"    => [
                    'mobile' => $meta['mobile'],
                    'otp'    => $_POST['otp_code'],
                    'credit' => $meta['amount'],
                    'schema' => $meta['items']
                ],
                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            $this->creditToken = $response->data->token;
            $this->setMeta(['creditToken' => $this->creditToken]);

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->markStatus(self::ERROR);
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function finalizeCredit()
    {
        try {
            $client = new Client();
            $meta = $this->getMeta();

            $response = $client->request("POST", "{$this->apiUrl}/Credit/FinalizeCredit", [
                "json"    => [
                    'mobile'            => $meta['mobile'],
                    'token'             => $this->creditToken,
                    'transactionNumber' => $this->refId(),
                    'transactionDate'   => now()->format('Y-m-d'),
                ],
                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            return true;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->markStatus(self::ERROR);
            $this->newLog('Error', $e->getMessage());

            // refund to user wallet
            $transaction = $this->db->findByTransactionId($this->transactionId);
            $this->refundPayment($transaction);

            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getCredit()
    {
        try {
            $client = new Client();

            $response = $client->request("GET", "{$this->apiUrl}/Credit/GetCredit", [
                "query"   => [
                    'mobile' => $this->config->get('mellatStaff.user-mobile'),
                ],
                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            return $response->data->credit;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Partial refund user payment
     *
     * @return bool
     *
     * @throws MellatStaffException
     */
    public function partialRefundPayment($transaction, $amount, $params = [])
    {
        try {
            $this->authLogin();

            $meta = json_decode($transaction->meta, true);
            $client = new Client();

            $response = $client->request("POST", "{$this->apiUrl}/Credit/RollBackCredit", [
                "json" => [
                    'token'       => $meta['creditToken'],
                    'credit'      => $amount,
                    'description' => !empty($params['description']) ? $params['description'] : '',
                ],

                "headers" => [
                    'Authorization' => "Bearer {$this->authToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->resultCode != 0) {
                $this->newLog($response->resultCode, $response->message);
                throw new MellatStaffException($response->message, $response->resultCode);
            }

            $this->newLog('Refunded', json_encode($response));

            return $response;

        } catch (\Exception $e) {
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function addItem($items)
    {
        $this->items = $items;

        return $this;
    }

    public function markStatus($status)
    {
        if (!in_array($status, [self::ERROR, self::PENDING, self::DONE, self::CANCELED])) {
            $status = self::ERROR;
        }

        $this->setMeta([
            'status' => $status
        ]);
    }
}