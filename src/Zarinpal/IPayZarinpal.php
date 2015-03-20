<?php namespace IPay\Zarinpal;

use IPay\IPayAbstract;
use IPay\Config;
use SoapClient;

/**
 * A class for zarinpal payments
 * //TODO: support for mobile gate
 * //TODO: support for communal settlement
 *
 * @author Mohsen Shafiee
 * @copyright MIT
 */
class IPayZarinpal extends IPayAbstract
{
	/**
	 * Merchant id
	 * Unique 36 character string for every payment gate
	 *
	 * @var string
	 */
	protected $merchantId;

	/**
	* Refer id for follow up
	*
	* @var string
	*/
	protected $refId;

	/**
	 * Authority
	 * Unique 36 character string for every payment request
	 *
	 * @var string
	 */
	protected $authority;

	/**
     * Address of germany SOAP server
     *
     * @var string
     */
    protected $germanyServer = 'https://de.zarinpal.com/pg/services/WebGate/wsdl';

	/**
     * Address of iran SOAP server
     *
     * @var string
     */
    protected $iranServer = 'https://ir.zarinpal.com/pg/services/WebGate/wsdl';

	/**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl;

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://www.zarinpal.com/pg/StartPay/';

	/**
     * Determine request passes
     *
     * @var bool
     */
    protected $requestPass = false;

	/**
     * Initialize of class
     *
     * @param string $configFile
     * @return void
     */
	public function __construct($configFile = null)
	{
		$this->config = new Config($configFile);

		$this->merchantId = $this->config->get('zarinpal.merchant-id');

		$this->setDB();
		$this->setMode();
		$this->setServer();

		parent::__construct();
	}

	/**
     * Send pay request to server
     *
     * @param int $amount
     * @param string $callBackUrl
     * @param string $additionalData
     * @return mixed
     */
    public function sendPayRequest($amount, $callBackUrl = null)
    {
		if (is_null($callBackUrl))
			$callBackUrl = $this->config->get('zarinpal.callback-url');

        $soap = new SoapClient($this->serverUrl);

        $fields = array(
            'MerchantID' => $this->merchantId,
            'Amount' => $amount,
            'CallbackURL' => $callBackUrl,
			'Description' 	=> $this->config->get('zarinpal.description', ''),
			'Email' 	=> $this->config->get('zarinpal.email', ''),
			'Mobile' 	=> $this->config->get('zarinpal.mobile', ''),
        );

        $response = $soap->PaymentRequest($fields);
		$this->status = $response->Status;

        if ($this->status != 100)
            if ($this->debug)
                throw new IPayZarinpalException($response->Status, $this->debugMessagesLanguage);
            else
                return $response;

        $this->authority = $response->Authority;

		$this->newLog($this->authority, '', '', $amount);

        $this->requestPass = true;
        return true;
    }

	/**
     * Redirect to bank for deposit money
     *
     * @return void
     */
    public function redirectToBank()
    {
        if ($this->requestPass)
        {
            Header('Location: '.$this->gateUrl.$this->authority);
        }
    }

	/**
     * Check request passes
     *
     * @return bool
     */
    public function passPayRequest()
    {
        if ($this->requestPass)
            return true;
        else
            return false;
    }

	/**
     * Check user payment with GET data
     *
     * @return bool
     */
    public function userPayment()
    {
        $this->authority = @$_GET['Authority'];
        $status = @$_GET['Status'];

        if ($status == 'OK')
        {
            return true;
        }
        return false;
    }

	/**
	* Verify user payment from zarinpal server
	*
	* @return bool
	*/
	public function verifyPayment()
	{
		$soap = new SoapClient($this->serverUrl);

		$amount = $this->getAmount();

		$fields = array(
			'MerchantID' => $this->merchantId,
			'Authority' => $this->authority,
			'Amount' => $amount,
		);

		$response = $soap->PaymentVerification($fields);
		$this->status = $response->Status;

		if ($this->status == 100)
		{
			$this->refId = $response->RefID;
			return true;
		}
		return false;
	}

	/**
     * Get refId for used after verifyPayment method (if succeed)
     *
     * @return null|string
     */
    public function getRefId()
    {
        return $this->refId;
    }

	/**
	 * Get status of actions for used after verifyPayment or sendPayRequest methods
	 *
	 * @return null|string
	 */
	public function getStatusCode()
	{
		return $this->status;
	}

	/**
	 * Get authority code
	 *
	 * @return string
	 */
	public function getAuthority()
	{
		return $this->authority;
	}

	/**
	 * Set server for soap transfers data
	 *
	 * @return void
	 */
	public function setServer()
	{
		$server = $this->config->get('zarinpal.server', 'germany');
		switch ($server)
		{
			case 'iran':
				$this->serverUrl = $this->iranServer;
			break;

			case 'germany':
			default:
				$this->serverUrl = $this->germanyServer;
			break;
		}
	}

	/**
	* Insert new log to table
	*
	* @param string $refId
	* @param string $saleOrderId
	* @param string $saleRefrencesId
	* @param string $AdditionalData
	* @param string $message
	* @return int last inserted id
	*/
	public function newLog($refId = '', $saleOrderId = '', $saleRefrencesId = '', $AdditionalData = '', $message = '')
	{
		$date = new DateTime;
		$date = $date->format('Y/m/d H:i:s');

		$stmt = $this->dbh->prepare("INSERT INTO mellat_orders_log (ref_id, sale_order_id, sale_refrences_id, additional_data, message, timestamp) VALUES (:ref_id, :sale_order_id, :sale_refrences_id, :additional_data, :message, :timestamp)");
		$stmt->bindParam(':ref_id', $refId);
		$stmt->bindParam(':sale_order_id', $saleOrderId);
		$stmt->bindParam(':sale_refrences_id', $saleRefrencesId);
		$stmt->bindParam(':additional_data', $AdditionalData);
		$stmt->bindParam(':message', $message);
		$stmt->bindParam(':timestamp', $date);
		$stmt->execute();

		return $this->dbh->lastInsertId();
	}

	/**
	 * Get amount of authority from db
	 *
	 * @return int
	 */
	public function getAmount()
	{
		$sql = "SELECT additional_data FROM mellat_orders_log WHERE ref_id=$this->authority LIMIT 1";
		$stmt = $this->dbh->prepare($sql);
		$stmt->execute();
		$row = $stmt->fetch();
		return $row['additional_data'];
	}
}
