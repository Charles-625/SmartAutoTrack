<?php
/**
 * Requête HTTP JSON sortante (CamPay, Hugging Face).
 *
 * @return array{status:int, data:mixed, raw:string}
 * @throws RuntimeException si le serveur distant est injoignable.
 */
function httpJsonRequest(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $httpHeaders = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $httpHeaders[] = $name . ': ' . $value;
    }
    if ($body !== null) {
        $httpHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Requête HTTP impossible vers ' . parse_url($url, PHP_URL_HOST) . ' : ' . $error);
    }

    return ['status' => $status, 'data' => json_decode($raw, true), 'raw' => (string)$raw];
}
