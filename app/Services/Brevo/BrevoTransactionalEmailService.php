<?php

namespace App\Services\Brevo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrevoTransactionalEmailService
{
    public function sendTransactionalEmail(
        string $toEmail,
        string $subject,
        string $htmlContent,
        ?string $textContent = null,
    ): void {
        $apiKey = (string) config('services.brevo.api_key');
        $apiUrl = rtrim((string) config('services.brevo.api_url', 'https://api.brevo.com/v3'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('BREVO_API_KEY is not set.');
        }

        $senderEmail = (string) config('services.brevo.sender_email');
        $senderName = (string) config('services.brevo.sender_name');

        if ($senderEmail === '') {
            throw new RuntimeException('BREVO_SENDER_EMAIL (or MAIL_FROM_ADDRESS) is not set.');
        }

        $payload = [
            'sender' => [
                'email' => $senderEmail,
                'name' => $senderName !== '' ? $senderName : $senderEmail,
            ],
            'to' => [
                ['email' => $toEmail],
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];

        if ($textContent !== null) {
            $payload['textContent'] = $textContent;
        }

        $response = Http::withHeaders([
            'api-key' => $apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post($apiUrl . '/smtp/email', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Brevo API email send failed: ' . $response->status() . ' ' . $response->body());
        }
    }
}
