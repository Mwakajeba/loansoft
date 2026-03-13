<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SmsHelper
{
    /**
     * Send SMS using configured provider (Kilakona or Beem Africa)
     * 
     * @param string $phone Phone number(s) - single or comma-separated
     * @param string $message Message content
     * @return array Response data
     */
    public static function send($phone, $message, string $event = null)
    {
        if ($event && !self::isEventEnabled($event)) {
            Log::info('SMS sending skipped - event disabled', [
                'event' => $event,
                'phone' => $phone,
            ]);

            return [
                'success' => true,
                'skipped' => true,
                'event' => $event,
            ];
        }

        $provider = config('services.sms.provider', 'kilakona');
        
        if ($provider === 'kilakona') {
            return self::sendViaKilakona($phone, $message);
        } else {
            return self::sendViaBeem($phone, $message);
        }
    }

    /**
     * Send a test SMS to verify the current SMS configuration.
     * Bypasses event-enable checks so it always attempts delivery.
     *
     * @param string $phone
     * @return array
     */
    public static function test(string $phone): array
    {
        $message = 'SmartFinance SMS test — if you received this, your SMS configuration is working correctly.';

        $provider = config('services.sms.provider', 'kilakona');

        if ($provider === 'kilakona') {
            return self::sendViaKilakona($phone, $message);
        }

        return self::sendViaBeem($phone, $message);
    }

    /**
     * Resolve a custom message template for the given event.
     * Returns the template with all {variable} placeholders replaced,
     * or null if no custom template is configured (caller should use default).
     *
     * @param string $event
     * @param array<string, mixed> $variables  Keys are variable names (without braces)
     * @return string|null
     */
    public static function resolveTemplate(string $event, array $variables): ?string
    {
        $template = config("services.sms.templates.{$event}", '');

        if (empty($template)) {
            return null;
        }

        foreach ($variables as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * Check if a given SMS event is enabled in configuration.
     *
     * @param string $event
     * @return bool
     */
    protected static function isEventEnabled(string $event): bool
    {
        $value = config("services.sms.events.$event", true);

        // Use FILTER_VALIDATE_BOOLEAN to correctly parse "true"/"false", "1"/"0", etc.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Send SMS via Kilakona API
     */
    protected static function sendViaKilakona($phone, $message)
    {
        $senderId = trim((string) config('services.sms.senderid'));
        $apiKey = trim((string) config('services.sms.api_key'));
        $apiSecret = trim((string) config('services.sms.api_secret'));
        $url = trim((string) config('services.sms.url'));
        $callbackUrl = config('services.sms.callback_url');

        if (empty($senderId) || empty($apiKey) || empty($apiSecret) || empty($url)) {
            $error = 'Kilakona SMS is not properly configured. Please set sender ID, API key, API secret, and URL.';
            Log::error('SMS sending failed (Kilakona) - Missing config', [
                'senderid' => $senderId,
                'api_key' => $apiKey ? 'set' : 'missing',
                'api_secret' => $apiSecret ? 'set' : 'missing',
                'url' => $url
            ]);
            return [
                'success' => false,
                'error' => $error,
                'http_code' => 0,
                'response' => null
            ];
        }

        $data = [
            'senderId' => $senderId,
            'messageType' => 'text',
            'message' => $message,
            'contacts' => $phone,
        ];

        // Add callback URL if configured
        if ($callbackUrl) {
            $data['deliveryReportUrl'] = $callbackUrl;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api_key: ' . $apiKey,
            'api_secret: ' . $apiSecret
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            
            Log::error('SMS sending failed (Kilakona) - cURL Error', [
                'error' => $error,
                'phone' => $phone,
                'message' => $message
            ]);
            
            return [
                'success' => false,
                'error' => $error,
                'http_code' => 0,
                'response' => null
            ];
        }

        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        // Log based on success
        if ($httpCode >= 200 && $httpCode < 300) {
            Log::info('SMS sent successfully (Kilakona)', [
                'phone' => $phone,
                'http_code' => $httpCode,
                'response' => $responseData
            ]);
        } else {
            Log::error('SMS sending failed (Kilakona) - API Error', [
                'phone' => $phone,
                'http_code' => $httpCode,
                'raw_response' => $response,
                'response' => $responseData,
                'message' => $message
            ]);
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $responseData,
            'raw_response' => $response,
            'error' => $httpCode >= 400 ? ($responseData['message'] ?? 'API request failed') : null
        ];
    }

    /**
     * Send SMS via Beem Africa API (legacy support)
     */
    protected static function sendViaBeem($phone, $message)
    {
        $sid = trim((string) config('services.sms.senderid'));
        $token = trim((string) config('services.sms.token'));
        $key = trim((string) config('services.sms.key'));
        $url = trim((string) config('services.sms.url', 'https://apisms.beem.africa/v1/send'));

        $postData = [
            'source_addr' => $sid,
            'encoding' => 0,
            'schedule_time' => '',
            'message' => $message,
            'recipients' => [
                [
                    'recipient_id' => '1',
                    'dest_addr' => $phone
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt_array($ch, [
            CURLOPT_POST => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => [
                'Authorization:Basic ' . base64_encode("$key:$token"),
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($postData)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            
            Log::error('SMS sending failed (Beem)', [
                'error' => $error,
                'phone' => $phone
            ]);
            
            return [
                'success' => false,
                'error' => $error,
                'response' => null
            ];
        }

        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        Log::info('SMS sent (Beem)', [
            'phone' => $phone,
            'http_code' => $httpCode,
            'response' => $responseData
        ]);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }
}