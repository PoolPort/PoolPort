<?php

namespace PoolPort;

interface PortInterface
{
    /**
     * Initialize class
     *
     * @param Config $config
     * @param DataBaseManager $db
     * @param int $portId
     *
     */
    public function __construct(Config $config, DatabaseManager $db, $portId);

    /**
     * This method use for set price in Rial.
     *
     * @param int $amount in Rial
     *
     * @return $this
     */
    public function set($amount);

    /**
     * Some of the ports can be send additional data to port server.
     * This method for set this additional data.
     *
     * @param array $data
     *
     * @return $this
     */
    public function with(array $data);

    /**
     * This method use for done everything that necessary before redirect to port.
     *
     * @return $this
     */
    public function ready();

    /**
     * Get ref id, in some ports ref id has a different name such as authority
     *
     * @return int|string
     */
    public function refId();

    /**
     * Return tracking code
     *
     * @return int|string
     */
    public function trackingCode();

    /**
     * Get port id, $this->portId
     *
     * @return int
     */
    public function portId();

    /**
     * Get transaction id
     *
     * @return int|null
     */
    public function transactionId();

    /**
     * Return card number
     *
     * @return string
     */
    public function cardNumber();

    /**
     * This method use for redirect to port
     *
     * @return mixed
     */
    public function redirect();

    /**
     * Return result of payment
     * If result is done, return true, otherwise throws an related exception
     *
     * @param object $transaction row of transaction in database
     *
     * @return $this
     */
    public function verify($transaction);

    /**
     * Reset a config per request in poolport.php configuration file
     *
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function setConfig($key, $value);
}
