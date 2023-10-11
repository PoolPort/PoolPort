<?php

namespace PoolPort\Pasargad;

use DateTime;
use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class Pasargad extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $settleUrl = 'https://pep.shaparak.ir/VerifyPayment.aspx';
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://pep.shaparak.ir/CheckTransactionResult.aspx';

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
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $fields = array(
            'terminalCode' => $this->config->get('pasargad.terminal-code'),
            'merchantCode' => $this->config->get('pasargad.merchant-code'),
            'redirectAddress' => $this->buildRedirectUrl($this->config->get('pasargad.callback-url')),
            'timeStamp' => $dateTime->format('Ymd'),
            'invoiceDate' => $dateTime->format('Ymd'),
            'action' => 1003,
            'amount' => $this->amount,
            'invoiceNumber' => $this->transactionId(),
        );

        $fields['sign'] = $this->generateSign($fields);

        require 'PasargadRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->verifyPayment();
        $this->settleRequest();

        return $this;
    }

    /**
     * Generate sign for redirect form
     *
     * @param array $fields
     * @return string
     */
    protected function generateSign(array $fields)
    {
        $data = "#".$fields['merchantCode']."#".$fields['terminalCode']."#".$fields['invoiceNumber']."#".
            $fields['invoiceDate']."#".$fields['amount']."#".$fields['redirectAddress']."#".
            $fields['action']."#".$fields['timeStamp']."#";
        $data = sha1($data, true);

        $processor = new RsaProcessor($this->config->get('pasargad.certificate'), RsaProcessor::XMLString);
        return base64_encode($processor->sign($data));
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws PasargadException
     */
    protected function verifyPayment()
    {
        $this->refId = @$_GET['tref'];
        $this->transactionSetRefId();

        $fields = array(
            'invoiceUID' => $this->refId()
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $response = simplexml_load_string($response);
        if ($response->result == 'False') {
            $this->transactionFailed();
            $this->newLog(0, PasargadException::getError($response->action));
            throw new PasargadException($response->action);
        }

        return true;
    }
    /**
     * settle user payment from bank server
     *
     * @return bool
     *
     * @throws PasargadException
     */
    protected function settleRequest()
    {
        $dateTime = new DateTime();
        $fields = array(
            'MerchantCode' => $this->config->get('pasargad.merchant-code'),
            'TerminalCode' => $this->config->get('pasargad.terminal-code'),
            'InvoiceNumber' => $this->transactionId(),
            'InvoiceDate' => $dateTime->format('Ymd'),
            'amount' => $this->amount,
            'TimeStamp' => date("Y/m/d H:i:s"),
            'sign' => ''
        );

        $processor = new RsaProcessor($this->config->get('pasargad.certificate'), RsaProcessor::XMLString);

        $data = "#". $fields['MerchantCode'] ."#". $fields['TerminalCode'] ."#". $fields['InvoiceNumber'] ."#". $fields['InvoiceDate'] ."#". $fields['amount'] ."#". $fields['TimeStamp'] ."#";
        $data = sha1($data,true);
        $data =  $processor->sign($data);
        $fields['sign'] =  base64_encode($data);

        $text = "InvoiceNumber=" . $this->transactionId() ."&InvoiceDate=" .
            $dateTime->format('Ymd') ."&MerchantCode=" . $this->config->get('pasargad.merchant-code') ."&TerminalCode=" .
            $this->config->get('pasargad.terminal-code') ."&Amount=" . $this->amount ."&TimeStamp=" . $fields['TimeStamp'] .
            "&Sign=" . $fields['sign'];
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$this->settleUrl);
        curl_setopt($ch,CURLOPT_POST,1);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$text);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        $result2 = curl_exec($ch);
        curl_close($ch);
        $response = simplexml_load_string($result2);
        if(!isset($response->result) and strtolower($response->result)=='false'){
            $this->transactionFailed();
            $this->newLog(0, PasargadException::getError($response->action));
            throw new PasargadException($response->action);
        }
        return true;
    }
}
