<?php

namespace PoolPort\Melli;

use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;

class Melli extends PortAbstract implements PortInterface
{
    /**
     * Url of melli gateway web service
     *
     * @var string
     */
    protected $gateUrl = 'https://sadad.shaparak.ir/vpg/api/v0';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $paymentUrl = 'https://sadad.shaparak.ir/VPG/Purchase?Token=';

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
     * Send pay request to server
     *
     * @return void
     *
     * @throws PoolPortException
     * @throws MelliException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $key = $this->config->get('melli.key');
            $MerchantId = $this->config->get('melli.merchant-id');
            $TerminalId = $this->config->get('melli.terminal-id');
            $Amount = $this->amount;
            $OrderId = $this->transactionId();
            $LocalDateTime = date("m/d/Y g:i:s a");
            $ReturnUrl = $this->buildRedirectUrl($this->config->get('melli.callback-url'));
            $SignData = $this->createSignature("$TerminalId;$OrderId;$Amount", "$key");

            $data = array(
                'TerminalId'    => $TerminalId,
                'MerchantId'    => $MerchantId,
                'Amount'        => $Amount,
                'SignData'      => $SignData,
                'ReturnUrl'     => $ReturnUrl,
                'LocalDateTime' => $LocalDateTime,
                'OrderId'       => $OrderId
            );

            $result = $this->CallAPI("{$this->gateUrl}/Request/PaymentRequest", $data);

            if (!$result || !isset($result->ResCode) || intval($result->ResCode) !== 0) {
                $resCode = is_object($result) && isset($result->ResCode) ? $result->ResCode : 'Error';
                $description = is_object($result) && isset($result->Description) ? $result->Description : 'Melli payment request failed';

                throw new MelliException($description, intval($resCode));
            }

            $this->refId = $result->Token;
            $this->transactionSetRefId();

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function createSignature($str, $key)
    {
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($str, "DES-EDE3", $key, OPENSSL_RAW_DATA);

        return base64_encode($ciphertext);
    }

    private function CallAPI($url, $data = false)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_POST, 1);

            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $result = curl_exec($ch);
            curl_close($ch);

            return !empty($result)
                ? json_decode($result)
                : false;

        } catch (\Exception $ex) {
            return false;
        }
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
     * Verify user payment from bank server
     *
     * @throws PoolPortException
     * @throws MelliException
     */
    protected function verifyPayment()
    {
        try {
            $key = $this->config->get('melli.key');
            $Token = isset($_POST["token"]) ? $_POST["token"] : null;
            $ResCode = isset($_POST["ResCode"]) ? $_POST["ResCode"] : null;

            if (intval($ResCode) !== 0) {
                throw new MelliException('User payment failed', intval($ResCode));
            }

            $verifyData = array(
                'Token'    => $Token,
                'SignData' => $this->createSignature($Token, $key)
            );

            $result = $this->CallAPI("{$this->gateUrl}/Advice/Verify", $verifyData);

            if (!$result || !isset($result->ResCode) || intval($result->ResCode) == -1 || intval($result->ResCode) !== 0) {
                $resCode = is_object($result) && isset($result->ResCode) ? $result->ResCode : 'Error';
                $description = is_object($result) && isset($result->Description) ? $result->Description : 'Melli verify failed';

                throw new MelliException($description, intval($resCode));
            }

            $this->refId = $result->RetrivalRefNo;
            $this->transactionSetRefId();

            $this->trackingCode = $result->SystemTraceNo;
            $this->transactionSucceed();

            return true;

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
        header("Location:" . $this->paymentUrl . $this->refId());
    }
}