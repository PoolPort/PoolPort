<?php

namespace PoolPort\PortSimulator;

use DateTime;
use PoolPort\Config;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;
use PoolPort\DataBaseManager;

class PortSimulator extends PortAbstract implements PortInterface
{
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
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $refId = $this->transactionId;
        $callbackUrl = $this->buildQuery($this->config->get('simulator.callback-url'), array('transaction_id' => $this->transactionId));
        $trackingCode = mt_rand(1000000, 9999999);

        require 'PortSimulatorRedirector.php';
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();

        return $this;
    }

    /**
     * Simulate pay request
     *
     * @return void
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $this->refId = $this->transactionId;
        $this->transactionSetRefId($this->transactionId);
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws MellatException
     */
    protected function userPayment()
    {
        $this->refId = @$_POST['RefId'];
        $this->trackingCode = @$_POST['trackingCode'];

        $this->transactionSucceed();
        $this->newLog(100, self::TRANSACTION_SUCCEED_TEXT);
        return true;
    }
}
