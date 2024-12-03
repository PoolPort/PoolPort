<?php

return array(

    //-------------------------------
    // Timezone for insert dates in database
    // If you want PoolPort not set timezone, just leave it empty
    //--------------------------------
    'timezone' => 'Asia/Tehran',

    'configuration' => array(),

    //--------------------------------
    // Soap configuration
    //--------------------------------
    'soap'          => array(
        'attempts' => 2 // Attempts if soap connection is fail
    ),

    //--------------------------------
    // Database configuration
    //--------------------------------
    'database'      => array(
        'host'     => '127.0.0.1',
        'dbname'   => '',
        'username' => '',
        'password' => '',
        'create'   => true             // For first time you must set this to true for create tables in database
    ),

    //--------------------------------
    // Zarinpal gateway
    //--------------------------------
    'zarinpal'      => array(
        'merchant-id'  => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'type'         => 'zarin-gate',                           // Types: [zarin-gate || normal]
        'callback-url' => 'http://www.example.org/result',
        'server'       => 'germany',                              // Servers: [germany || iran]
        'user-email'   => 'email@gmail.com',
        'user-mobile'  => '09xxxxxxxxx',
        'description'  => 'description',
    ),

    //--------------------------------
    // Mellat gateway
    //--------------------------------
    'mellat'        => array(
        'username'     => '',
        'password'     => '',
        'terminalId'   => 0000000,
        'callback-url' => 'http://www.example.org/result',
        'user-mobile'  => '989xxxxxxxx'
    ),

    //--------------------------------
    // Payline gateway
    //--------------------------------
    'payline'       => array(
        'api'          => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result'
    ),

    //--------------------------------
    // Sadad gateway
    //--------------------------------
    'sadad'         => array(
        'merchant'       => '',
        'transactionKey' => '',
        'terminalId'     => 000000000,
        'callback-url'   => 'http://example.org/result',
        'user-mobile'    => '09xxxxxxxxx'
    ),

    //--------------------------------
    // JahanPay gateway
    //--------------------------------
    'jahanpay'      => array(
        'api'          => 'xxxxxxxxxxx',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Parsian gateway
    //--------------------------------
    'parsian'       => array(
        'pin'          => 'xxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://example.org/result',
        'user-mobile'  => '09xxxxxxxxx',
    ),

    //--------------------------------
    // Pasargad gateway
    //--------------------------------
    'pasargad'      => array(
        'merchant-code' => '9999999',
        'terminal-code' => '999999',
        'certificate'   => '',
        'callback-url'  => 'http://example.org/result'
    ),

    //--------------------------------
    // Saderat gateway
    //--------------------------------
    'saderat'       => array(
        'merchant-id'  => '999999999999999',
        'terminal-id'  => '99999999',
        'public-key'   => __DIR__ . '/saderat-public-key.pem',
        'private-key'  => __DIR__ . '/saderat-private-key.pem',
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // IranKish gateway
    //--------------------------------
    'irankish'      => array(
        'terminal-id'  => 'xxxxxxxx',
        'acceptor-id'  => 'xxxxxxxxxxxxxxx',
        'pass-phrase'  => 'xxxxxxxxxxxxxxxx',
        'public-key'   => __DIR__ . '/irankish-public-key.pem',
        'callback-url' => 'http://example.org/result',
        'user-mobile'  => '989xxxxxxxxx',
    ),

    //--------------------------------
    // Simulator gateway
    //--------------------------------
    'simulator'     => array(
        'callback-url' => 'http://example.org/result'
    ),

    //--------------------------------
    // Saman gateway
    //--------------------------------
    'saman'         => array(
        'terminal-id'  => 'xxxxx',
        'callback-url' => 'http://example.org/result',
        'user-mobile'  => '09xxxxxxxxx',
    ),

    // Pay gateway
    //--------------------------------
    'pay'           => array(
        'api'          => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result'
    ),

    // JiBit gateway
    //--------------------------------
    'jibit'         => array(
        'merchant-id'  => 'xxxx',
        'password'     => 'xxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
        'user-mobile'  => '09xxxxxxxxx'
    ),

    // AP gateway
    //--------------------------------
    'ap'            => array(
        'merchant-config-id' => 'xxxx',
        'username'           => 'xxxxxxxxxx',
        'password'           => 'xxxxxxxxxx',
        'callback-url'       => 'http://www.example.org/result',
        'user-mobile'        => '09xxxxxxxxx'
    ),

    // BitPay gateway
    //--------------------------------
    'bitpay'        => array(
        'api'          => 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
        'name'         => 'xxxxxxxxxx',
        'email'        => 'email@gmail.com',
        'description'  => 'description',
        'user-mobile'  => '09xxxxxxxx'
    ),

    // IDPay gateway
    //--------------------------------
    'idpay'         => array(
        'api'          => 'x-x-x-x-x',
        'callback-url' => 'http://www.example.org/result',
        'sandbox'      => false,
        'name'         => 'name',
        'email'        => 'email',
        'description'  => 'description',
        'user-mobile'  => '09xxxxxxxx',
    ),

    // PayPing gateway
    //--------------------------------
    'payping'       => array(
        'token'        => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Vandar gateway
    //--------------------------------
    'payping'       => array(
        'api_key'      => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // PNA (Eghtesad Novin) gateway
    //--------------------------------
    'pna'           => array(
        'mid'          => 'xxxxxxxxx',
        'password'     => 'xxxxxx',
        'public-key'   => __DIR__ . '/pna-public-key.pem',
        'callback-url' => 'http://exmaple.org/result',
        'user-mobile'  => '09xxxxxxxx',
    ),

    // Azki gateway
    //--------------------------------
    'azki'          => array(
        'merchant-id'  => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'api_key'      => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Apsan gateway
    //--------------------------------
    'apsan'         => array(
        'username'     => 'xxxxx',
        'password'     => 'xxxxx',
        'terminalId'   => 'xxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Dara gateway
    //--------------------------------
    'dara'          => array(
        'merchant-id'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'terminal-key' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'terminal-id'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Keepa gateway
    //--------------------------------
    'keepa'         => array(
        'token'        => 'xxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Bazaar Pay gateway
    //--------------------------------
    'bazaarpay'     => array(
        'token'        => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'destination'  => 'xxxxx',
        'service_name' => 'xxxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // Tara gateway
    //--------------------------------
    'tara'          => array(
        'username'   => 'xxxx',
        'password'   => 'xxxx',
        'service-id' => 'xxxx',

        'refund' => [
            'username' => 'xxxx',
            'password' => 'xxxx',
        ],

        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),

    // DigiPay gateway
    //--------------------------------
    'digipay'           => array(
        'username'      => 'xxxx',
        'password'      => 'xxxx',
        'client-id'     => 'xxxx',
        'client-secret' => 'xxxx',
        'type'          => 'xxxx',
        'user-mobile'   => '09xxxxxxxx',
        'callback-url'  => 'http://www.example.org/result',
    ),

    // Pasargad gateway
    //--------------------------------
    'zibal'     => array(
        'merchant'     => 'xxxx',
        'user-mobile'  => '09xxxxxxxx',
        'callback-url' => 'http://www.example.org/result',
    ),
);
