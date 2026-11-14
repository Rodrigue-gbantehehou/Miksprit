<?php

declare(strict_types=1);

namespace App\Service\Provider\Payment;

final class CurlJsonHttpClient implements JsonHttpClient
{
    public function request(string $method, string $url, array $headers = [], ?array $json = null): JsonHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new PaymentProviderUnavailable('The cURL extension is required for payment processing.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            throw new PaymentProviderUnavailable('Payment provider URL must use HTTPS.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new PaymentProviderUnavailable('Unable to initialize secure payment transport.');
        }
        $requestHeaders = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) !== 1 || str_contains($value, "\r") || str_contains($value, "\n")) {
                curl_close($handle);
                throw new \InvalidArgumentException('Invalid outbound HTTP header.');
            }
            $requestHeaders[] = $name.': '.$value;
        }
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => 1_048_576,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($responseBody) || strlen($responseBody) > 1_048_576) {
            throw new PaymentProviderUnavailable($error !== '' ? 'Payment provider network request failed.' : 'Payment provider response is too large.');
        }
        try {
            $data = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PaymentProviderUnavailable('Payment provider returned an invalid response.', previous: $exception);
        }
        if (!is_array($data)) {
            throw new PaymentProviderUnavailable('Payment provider returned an invalid response.');
        }
        return new JsonHttpResponse($status, $data);
    }
}
