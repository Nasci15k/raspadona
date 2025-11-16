<?php

class BrazilPagamentos {
    private $pdo;
    private $url;
    private $publicKey;
    private $secretKey;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadCredentials();
    }

    private function loadCredentials() {
        $stmt = $this->pdo->query("SELECT url, public_key, secret_key FROM brazil_pagamentos LIMIT 1");
        $credentials = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$credentials) {
            throw new Exception('Credenciais Brazil Pagamentos não encontradas.');
        }

        $this->url = rtrim($credentials['url'], '/');
        $this->publicKey = $credentials['public_key'];
        $this->secretKey = $credentials['secret_key'];
    }

    public function createDeposit($amount, $cpf, $name, $email, $callbackUrl, $idempotencyKey) {
        // Converter valor para centavos (Brazil Pagamentos usa centavos)
        $amountInCents = intval($amount * 100);

        $payload = [
            'paymentMethod' => 'pix',
            'customer' => [
                'document' => [
                    'type' => 'cpf',
                    'number' => $cpf
                ],
                'name' => $name,
                'email' => $email
            ],
            'amount' => $amountInCents,
            'installments' => '1',
            'pix' => [
                'expiresInDays' => 1
            ],
            'items' => [
                [
                    'title' => 'deposito',
                    'unitPrice' => $amountInCents,
                    'quantity' => 1,
                    'tangible' => false
                ]
            ],
            'postbackUrl' => $callbackUrl,
            'externalRef' => $idempotencyKey
        ];

        // Autenticação Basic com secret key
        $authHeader = base64_encode($this->secretKey);

        $ch = curl_init($this->url . '/v1/transactions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                "authorization: Basic $authHeader",
                'content-type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception('Erro na API Brazil Pagamentos: ' . $response);
        }

        $data = json_decode($response, true);

        if (!isset($data['id'], $data['pix']['qrcode'])) {
            throw new Exception('Resposta inválida da API Brazil Pagamentos.');
        }

        return [
            'transactionId' => $data['id'],
            'qrcode' => $data['pix']['qrcode'],
            'idempotencyKey' => $idempotencyKey
        ];
    }

    public function createWithdraw($amount, $cpf, $name, $pixKey, $pixKeyType, $callbackUrl, $idempotencyKey) {
        // Converter valor para centavos (Brazil Pagamentos usa centavos)
        $amountInCents = intval($amount * 100);

        $payload = [
            'pixKeyType' => strtolower($pixKeyType),
            'amount' => $amountInCents,
            'netAmount' => false,
            'pixKey' => $pixKey,
            'postbackUrl' => $callbackUrl
        ];

        // Autenticação Basic com secret key (formato: CLIENT_SECRET:x)
        $authHeader = base64_encode($this->secretKey . ':x');

        $ch = curl_init($this->url . '/v1/transfers');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                "authorization: Basic $authHeader",
                'content-type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Erro cURL: ' . $curlError);
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception('Erro na API Brazil Pagamentos: HTTP ' . $httpCode . ' - ' . $response);
        }

        $data = json_decode($response, true);

        if (!$data) {
            throw new Exception('Resposta inválida da API Brazil Pagamentos.');
        }

        return [
            'transactionId' => $data['id'] ?? $idempotencyKey,
            'status' => $data['status'] ?? 'PROCESSING',
            'idempotencyKey' => $idempotencyKey,
            'response' => $data
        ];
    }
} 