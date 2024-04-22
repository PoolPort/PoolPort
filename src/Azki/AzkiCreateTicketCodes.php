<?php

namespace PoolPort\Azki;

class AzkiCreateTicketCodes
{
    const SUCCESS                              = 0;
    const INTERNAL_SERVER_ERROR                = 1;
    const RESOURCE_NOT_FOUND                   = 2;
    const MALFORMED_DATA                       = 4;
    const DATA_NOT_FOUND                       = 5;
    const ACCESS_DENIED                        = 15;
    const TRANSACTION_REVERSED                 = 16;
    const TICKET_EXPIRED                       = 17;
    const SIGNATURE_INVALID                    = 18;
    const TICKET_UNPAYABLE                     = 19;
    const TICKET_CUSTOMER_MISMATCH             = 20;
    const INSUFFICIENT_CREDIT                  = 21;
    const UNVERIFIED_TICKET                    = 28;
    const INVALID_INVOICE_DATA                 = 32;
    const CONTRACT_NOT_STARTED                 = 33;
    const CONTRACT_EXPIRED                     = 34;
    const VALIDATION_EXCEPTION                 = 44;
    const REQUEST_NOT_VALID                    = 51;
    const TRANSACTION_NOT_REVERSIBLE           = 59;
    const TRANSACTION_MUST_BE_IN_VERFIED_STATE = 60;

    public static function getMessage(int $code)
    {
        return [
                   self::SUCCESS                              => 'Request finished successfully',
                   self::INTERNAL_SERVER_ERROR                => 'Internal Server Error',
                   self::RESOURCE_NOT_FOUND                   => 'Resource Not Found',
                   self::MALFORMED_DATA                       => 'Malformed Data',
                   self::DATA_NOT_FOUND                       => 'Data Not Found',
                   self::ACCESS_DENIED                        => 'Access Denied',
                   self::TRANSACTION_REVERSED                 => 'Transaction already reversed',
                   self::TICKET_EXPIRED                       => 'Ticket Expired',
                   self::SIGNATURE_INVALID                    => 'Signature Invalid',
                   self::TICKET_UNPAYABLE                     => 'Ticket unpayable',
                   self::TICKET_CUSTOMER_MISMATCH             => 'Ticket customer mismatch',
                   self::INSUFFICIENT_CREDIT                  => 'Insufficient Credit',
                   self::UNVERIFIED_TICKET                    => 'Unverifiable ticket due to status',
                   self::INVALID_INVOICE_DATA                 => 'Invalid Invoice Data',
                   self::CONTRACT_NOT_STARTED                 => 'Contract is not started',
                   self::CONTRACT_EXPIRED                     => 'Contract is expired',
                   self::VALIDATION_EXCEPTION                 => 'Validation exception',
                   self::REQUEST_NOT_VALID                    => 'Request data is not valid',
                   self::TRANSACTION_NOT_REVERSIBLE           => 'Transaction not reversible',
                   self::TRANSACTION_MUST_BE_IN_VERFIED_STATE => 'Transaction must be in verified state',
               ][$code];
    }
}