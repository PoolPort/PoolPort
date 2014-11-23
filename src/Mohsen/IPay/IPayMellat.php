<?php namespace Mohsen\IPay;

use SoapClient;

/**
 * A class for mellat bank paymets
 *
 * @author Mohsen Shafiee
 * @copyright MIT
 */
class IPayMellat extends IPayAbstract implements IPayInterface
{
    /**
     * If true Exceptions executed
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * Mode of payment, test or actual
     *
     * @var string
     */
    public $mode = 'actual';

    /**
     * Address of main SOAP server
     *
     * @var string
     */
    public $serverMainUrl = 'https://pgw.bpm.bankmellat.ir/pgwchannel/services/pgw?wsdl';

    /**
     * Address of test SOAP server
     *
     * @var string
     */
    public $serverTestUrl = 'https://pgwstest.bpm.bankmellat.ir/pgwchannel/services/pgw?wsdl';

    /**
     * Initialize of class
     *
     * @param string $username
     * @param string $password
     * @param int $termId
     * @return void
     */
    public function __construct($username, $password, $termId, $mode = 'actual')
    {
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->termId = (int) $termId;
        $this->mode = $mode;

        $this->setMode();
    }

    /**
     * Send pay request to server
     *
     * @return bool|IPayMellatException
     */
    public function sendPayRequest()
    {
        $soap = new SoapClient($this->serverUrl);

        $fields = array(
            'terminalId' => $this->termId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => 1,
            'amount' => 45800,
            'localDate' => '20151123',
            'localTime' => '102003',
            'additionalData' => '',
            'callBackUrl' => 'http://google.com',
            'payerId' => 0,
        );

        $response = $soap->bpPayRequest($fields);

        if ($response->return != 0)
            if ($this->debug)
                throw new IPayMellatException($response->return, $this->debugMessagesLanguage);
            else
                return false;
    }

    /**
     * Set debug mode for class
     *
     * @param string $messagesLanguage in en and fa
     * @return void
     */
    public function setDebugMode($messagesLanguage = 'en')
    {
        $this->debug = true;
        $this->debugMessagesLanguage = $messagesLanguage == 'en' ? 'en' : 'fa';
    }

    /**
     * Set mode of requests
     *
     * @return void
     */
    public function setMode()
    {
        if ($this->mode == 'test')
            $this->serverUrl = $this->serverTestUrl;
        else
            $this->serverUrl = $this->serverMainUrl;
    }
}
