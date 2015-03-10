<?php namespace IPay\Zarinpal;

use IPay\IPayInterface;
use IPay\IPayAbstract;
use SoapClient;
use PDO;

/**
 * A class for zarinpal payments
 * //TODO: support for mobile gate
 * //TODO: support for communal settlement
 *
 * @author Mohsen Shafiee
 * @copyright MIT
 */
class IPayZarinpal extends IPayAbstract implements IPayInterface
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
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://de.zarinpal.com/pg/services/WebGate/wsdl';

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
     * Keep DB conection
     *
     * @var PDO
     */
    protected $dbh;

	/**
     * If true Exceptions executed
     *
     * @var bool
     */
    protected $debug = false;

	/**
     * Initialize of class
     *
     * @param string $merchantId
     * @return void
     */
	public function __construct($merchantId)
	{
		$this->merchantId = $merchantId;

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
    public function sendPayRequest($amount, $callBackUrl, $additionalData = null)
    {
		if (!is_array($additionalData))
			return -1;

        $soap = new SoapClient($this->serverUrl);

        $fields = array(
            'MerchantID' => $this->merchantId,
            'Amount' => $amount,
            'CallbackURL' => $callBackUrl,
			'Description' 	=> @$additionalData['description'],
			'Email' 	=> @$additionalData['email'],
			'Mobile' 	=> @$additionalData['mobile'],
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
	 * Set iran server for soap transfers data
	 *
	 * @return void
	 */
	public function setIranServer()
	{
		$this->serverUrl = 'https://ir.zarinpal.com/pg/services/WebGate/wsdl';
	}

	/**
	* Initialize database connection
	*
	* @param string $host
	* @param string $dbName
	* @param string $usermae
	* @param string $password
	* @return void
	*/
	public function setDB($host, $dbName, $username, $password)
	{
		$this->dbh = new PDO("mysql:host=$host;dbname=$dbName;", $username, $password);
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
}
