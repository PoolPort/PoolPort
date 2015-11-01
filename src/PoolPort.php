<?php

namespace PoolPort;

use PoolPort\Sadad\Sadad;
use PoolPort\Mellat\Mellat;
use PoolPort\Payline\Payline;
use PoolPort\Zarinpal\Zarinpal;
use PoolPort\Exceptions\RetryException;
use PoolPort\Exceptions\PortNotFoundException;
use PoolPort\Exceptions\InvalidRequestException;
use PoolPort\Exceptions\NotFoundTransactionException;

class PoolPort
{
    const P_MELLAT = 1;

    const P_SADAD = 2;

    const P_ZARINPAL = 3;

    const P_PAYLINE = 4;

    /**
     * @var Config
     */
    public $config;

    /**
     * @var DataBaseManager
     */
    protected $db;

    /**
     * Keep current port driver
     *
     * @var Mellat|Sadad|Zarinpal|Payline
     */
    protected $portClass;

    /**
     * Path of config file
     *
     * @var null|string
     */
    private $configFilePath = null;

    /**
     * @param null|string $port
     * @param null|string $configFile
     */
    public function __construct($port = null, $configFile = null)
    {
        $this->configFilePath = $configFile;

        $this->config = new Config($this->configFilePath);
        $this->db = new DataBaseManager($this->config);

        if (!empty($this->config->get('timezone')))
            date_default_timezone_set($this->config->get('timezone'));

        if (!is_null($port)) $this->buildPort($port);
    }

    /**
     * Get supported ports
     *
     * @return array
     */
    public function getSupportedPorts()
    {
        return array(self::P_MELLAT, self::P_SADAD, self::P_ZARINPAL, self::P_PAYLINE);
    }

    /**
     * Call methods of current driver
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->portClass, $name], $arguments);
    }

    /**
     * Callback
     *
     * @return $this->portClass
     *
     * @throws InvalidRequestException
     * @throws NotFoundTransactionException
     * @throws PortNotFoundException
     * @throws RetryException
     */
    public function verify()
    {
        if (!isset($_GET['transaction_id']))
            throw new InvalidRequestException;

        $transactionId = intval($_GET['transaction_id']);
        $transaction = $this->db->find($transactionId);

        if (!$transaction)
            throw new NotFoundTransactionException;

        if ($transaction->status == PortAbstract::TRANSACTION_SUCCEED || $transaction->status == PortAbstract::TRANSACTION_FAILED)
            throw new RetryException;

        $this->buildPort($transaction->port_id);

        return $this->portClass->verify($transaction);
    }

    /**
     * Create new object from port class
     *
     * @param int $port
     * @throws PortNotFoundException
     */
    private function buildPort($port)
    {
        switch ($port) {
            case self::P_MELLAT:
                $this->portClass = new Mellat($this->config, $this->db, self::P_MELLAT);
                break;

            case self::P_SADAD:
                $this->portClass = new Sadad($this->config, $this->db, self::P_SADAD);
                break;

            case self::P_ZARINPAL:
                $this->portClass = new Zarinpal($this->config, $this->db, self::P_ZARINPAL);
                break;

            case self::P_PAYLINE:
                $this->portClass = new Payline($this->config, $this->db, self::P_PAYLINE);
                break;

            default:
                throw new PortNotFoundException;
                break;
        }
    }
}
