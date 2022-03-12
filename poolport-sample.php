<?php

return array(

    //-------------------------------
    // Timezone for insert dates in database
    // If you want PoolPort not set timezone, just leave it empty
    //--------------------------------
    'timezone' => 'Asia/Tehran',

    'configuration' => array(
        // Use uniqe id in addition of transaction id for increase security
        'use_uniqeid' => true
    ),

    //--------------------------------
    // Soap configuration
    //--------------------------------
    'soap' => array(
        'attempts' => 2 // Attempts if soap connection is fail
    ),

    //--------------------------------
    // Database configuration
    //--------------------------------
    'database' => array(
        'host'     => '127.0.0.1',
        'dbname'   => '',
        'username' => '',
        'password' => '',
        'create' => true             // For first time you must set this to true for create tables in database
    ),

    //--------------------------------
    // Zarinpal gateway
    //--------------------------------
    'zarinpal' => array(
        'merchant-id'  => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'type'         => 'zarin-gate',                           // Types: [zarin-gate || normal]
        'callback-url' => 'http://www.example.org/result',
        'server'       => 'germany',                              // Servers: [germany || iran]
        'user-email'        => 'email@gmail.com',
        'user-mobile'       => '09xxxxxxxxx',
        'description'  => 'description',
    ),

    //--------------------------------
    // Mellat gateway
    //--------------------------------
    'mellat' => array(
        'username'     => '',
        'password'     => '',
        'terminalId'   => 0000000,
        'callback-url' => 'http://www.example.org/result',
        'user-mobile'  => '989xxxxxxxx'
    ),

    //--------------------------------
    // Payline gateway
    //--------------------------------
    'payline' => array(
        'api' => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result'
    ),

    //--------------------------------
    // Sadad gateway
    //--------------------------------
    'sadad' => array(
        'merchant'      => '',
        'transactionKey'=> '',
        'terminalId'    => 000000000,
        'callback-url'  => 'http://example.org/result',
        'user-mobile'   => '09xxxxxxxxx'
    ),

    //--------------------------------
    // JahanPay gateway
    //--------------------------------
    'jahanpay' => array(
        'api' => 'xxxxxxxxxxx',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Parsian gateway
    //--------------------------------
    'parsian' => array(
        'pin'          => 'xxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Pasargad gateway
    //--------------------------------
    'pasargad' => array(
        'merchant-code' => '9999999',
        'terminal-code' => '999999',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Saderat gateway
    //--------------------------------
    'saderat' => array(
        'merchant-id' => '999999999999999',
        'terminal-id' => '99999999',
        'public-key' => __DIR__.'/saderat-public-key.pem',
        'private-key' => __DIR__.'/saderat-private-key.pem',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // IranKish gateway
    //--------------------------------
    'irankish' => array(
        'merchant-id' => 'xxxx',
        'sha1-key' => 'xxxxxxxxxxxxxxxxxxxx',
        'description' => 'description',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Simulator gateway
    //--------------------------------
    'simulator' => array(
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Saman gateway
    //--------------------------------
    'saman' => array(
        'terminal-id' => 'xxxxx',
        'callback-url' => 'http://example.org/result',
        'user-mobile' => '09xxxxxxxxx',
    ),

    // Pay gateway
    //--------------------------------
    'pay' => array(
        'api' => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result'
    ),

    // JiBit gateway
    //--------------------------------
    'jibit' => array(
        'merchant-id' => 'xxxx',
        'password' => 'xxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
        'user-mobile' => '09xxxxxxxxx'
    ),

    // AP gateway
    //--------------------------------
    'ap' => array(
        'merchant-config-id' => 'xxxx',
        'username' => 'xxxxxxxxxx',
        'password' => 'xxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
        'encryption-key' => 'xxxxxxxxxx',
        'encryption-vector' => 'xxxxxxxxxx',
        'sync-time' => false,
        'user-mobile' => '09xxxxxxxxx'
    ),

	// BitPay gateway
	//--------------------------------
    'bitpay' => array(
	    'api' => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
	    'callback-url' => 'http://www.example.org/result',
	    'name' => 'xxxxxxxxxx',
	    'email' => 'email@gmail.com',
	    'description' => 'description',
        'user-mobile' => '09xxxxxxxx'
    ),

    // IDPay gateway
    //--------------------------------
    'idpay' => array(
        'api' => 'x-x-x-x-x',
        'callback-url' => 'http://www.example.org/result',
        'sandbox'=> false,
        'name' => 'name',
        'email' => 'email',
        'description' => 'description',
        'user-mobile' => '09xxxxxxxx',
    ),

    // PayPing gateway
    //--------------------------------
    'payping' => array(
        'token' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile' => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Vandar gateway
    //--------------------------------
    'payping' => array(
        'api_key' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile' => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),
);
