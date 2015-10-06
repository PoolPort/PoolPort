<?php

namespace IPay;

use IPay\Config;
use ReflectionClass;
use IPay\DataBaseManager;
use IPay\Sadad\IPaySadad;
use IPay\Mellat\IPayMellat;
use IPay\Zarinpal\IPayZarinpal;
use IPay\Exceptions\PortNotFoundException;

class IPay
{
    const P_MELLAT = '1';

    const P_SADAD = '2';

    const P_ZARINPAL = '3';

    /**
     * @var IPay\Config
     */
    public $config;

    /**
     * @var IPay\DataBaseManager
     */
    protected $db;

    /**
     * Keep current port driver
     *
     * @var mixed
     */
    protected $portClass;

    /**
     * @param string $port
     * @param string $configFile
     *
     * @return void
     */
    public function __construct($port, $configFile = null)
    {
        if (!in_array($port, $this->getSuportedPorts()))
            throw new PortNotFoundException;

        $this->config = new Config($configFile);
        $this->db = new DataBaseManager($this->config);

        switch ($port) {
            case self::P_MELLAT:
                $this->portClass = new IPayMellat($this->config, $this->db, self::P_MELLAT);
                break;

            case self::P_SADAD:
                $this->portCLass = new IPaySadad($this->config, $this->db, self::P_SADAD);
                break;

            case self::P_ZARINPAL:
                $this->portCLass = new IPayZarinpal($this->config, $this->db, self::P_ZARINPAL);
                break;
        }

    }

    /**
     * Get suported ports
     *
     * @return array
     */
    public function getSuportedPorts()
    {
        return array(self::P_MELLAT, self::P_SADAD, self::P_ZARINPAL);
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
}
