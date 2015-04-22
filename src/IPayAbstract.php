<?php namespace IPay;

use IPay\DB;
use PDO;

abstract class IPayAbstract
{
	/**
     * If true Exceptions executed
     *
     * @var bool
     */
    protected $debug = false;

	/**
	 * Language of Exceptions
	 *
	 * @var string
	 */
	protected $debugMessagesLanguage;

    /**
	 * Config class
	 *
	 * @var IPay\Config
	 */
	protected $config;

	/**
     * Keep DB conection
     *
     * @var PDO
     */
    protected $dbh;

    public function __construct()
    {
        date_default_timezone_set('Asia/Tehran');

        if ($this->config->get('database.create', false))
        {
            $db = new DB($this->config);
            $db->createTables();
        }
    }

	/**
	 * Initialize database connection
	 *
	 * @return void
	 */
	public function setDB()
	{
		$host = $this->config->get('database.host');
		$dbname = $this->config->get('database.dbname');
		$username = $this->config->get('database.username');
		$password = $this->config->get('database.password');
		$this->dbh = new PDO("mysql:host=$host;dbname=$dbname;", $username, $password);
	}

	/**
	 * Set mode for class
	 *
	 * @return void
	 */
	public function setMode()
	{
		$this->debug = $this->config->get('debug.status', false);
		$this->debugMessagesLanguage = $this->config->get('debug.lang') == 'en' ? 'en' : 'fa';
	}
}
