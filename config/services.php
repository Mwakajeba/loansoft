<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'dcb' => [
        'enabled' => filter_var(env('DCB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('DCB_BASE_URL', 'https://gateway.smartsot.tz'),
        'business_id' => env('DCB_BUSINESS_ID'),
        'api_key' => env('DCB_API_KEY'),
        'api_secret' => env('DCB_API_SECRET', env('DCB_SECRET_KEY')),
        'secret_key' => env('DCB_SECRET_KEY'),
        'sender_name' => env('DCB_SENDER_NAME', env('APP_NAME', 'SmartFinance')),
        'callback_secret' => env('DCB_CALLBACK_SECRET'),
        'token_ttl' => (int) env('DCB_TOKEN_TTL', 300),
        'timeout' => (int) env('DCB_TIMEOUT', 30),
        'default_institution_code' => env('DCB_DEFAULT_INSTITUTION_CODE'),
        'collection_account' => env('DCB_COLLECTION_ACCOUNT'),
        'settlement_bank_account_id' => env('DCB_SETTLEMENT_BANK_ACCOUNT_ID'),
    ],

    'sms' => [
        // Beem Africa SMS credentials
        'provider' => env('SMS_PROVIDER', 'kilakona'),
        'senderid' => env('BEEM_SENDER_ID', env('SMS_SENDERID')),
        'token' => env('BEEM_SECRET_KEY', env('SMS_TOKEN')),
        'key' => env('BEEM_API_KEY', env('SMS_KEY')),
        'url' => env('BEEM_SMS_URL', env('SMS_URL', 'https://apisms.beem.africa/v1/send')),
        // Kilakona SMS credentials
        'api_key' => env('KILAKONA_API_KEY', env('SMS_API_KEY')),
        'api_secret' => env('KILAKONA_API_SECRET', env('SMS_API_SECRET')),
        'callback_url' => env('KILAKONA_CALLBACK_URL', env('SMS_CALLBACK_URL')),
        // When to send automatic SMS messages (feature toggles)
        'events' => [
            'otp_verification' => env('SMS_EVENT_OTP_VERIFICATION', true),
            'loan_disbursement' => env('SMS_EVENT_LOAN_DISBURSEMENT', true),
            'loan_repayment' => env('SMS_EVENT_LOAN_REPAYMENT', true),
            'loan_arrears_reminder' => env('SMS_EVENT_LOAN_ARREARS_REMINDER', true),
            'customer_notifications' => env('SMS_EVENT_CUSTOMER_NOTIFICATIONS', true),
            'group_notifications' => env('SMS_EVENT_GROUP_NOTIFICATIONS', true),
            'cash_collateral' => env('SMS_EVENT_CASH_COLLATERAL', true),
            'mature_interest' => env('SMS_EVENT_MATURE_INTEREST', true),
            'loan_penalty'   => env('SMS_EVENT_LOAN_PENALTY', true),
        ],
        // loan_disbursement / loan_repayment: customer | customer_and_company
        'loan_disbursement_recipients' => env('SMS_LOAN_DISBURSEMENT_RECIPIENTS', 'customer'),
        'loan_repayment_recipients' => env('SMS_LOAN_REPAYMENT_RECIPIENTS', 'customer'),
        // Custom message templates per event (empty = use system default)
        'templates' => [
            'otp_verification'     => env('SMS_TEMPLATE_OTP_VERIFICATION', ''),
            'loan_disbursement'    => env('SMS_TEMPLATE_LOAN_DISBURSEMENT', ''),
            'loan_disbursement_company' => env('SMS_TEMPLATE_LOAN_DISBURSEMENT_COMPANY', ''),
            'loan_repayment'       => env('SMS_TEMPLATE_LOAN_REPAYMENT', ''),
            'loan_repayment_company' => env('SMS_TEMPLATE_LOAN_REPAYMENT_COMPANY', ''),
            'loan_arrears_reminder'=> env('SMS_TEMPLATE_LOAN_ARREARS_REMINDER', ''),
            'customer_notifications' => env('SMS_TEMPLATE_CUSTOMER_NOTIFICATIONS', ''),
            'group_notifications'  => env('SMS_TEMPLATE_GROUP_NOTIFICATIONS', ''),
            'cash_collateral'      => env('SMS_TEMPLATE_CASH_COLLATERAL', ''),
            'mature_interest'      => env('SMS_TEMPLATE_MATURE_INTEREST', ''),
            'loan_penalty'        => env('SMS_TEMPLATE_LOAN_PENALTY', ''),
        ],
    ],

];
