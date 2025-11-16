<?php

class GatewayProprio {
    private $pdo;
    private $apiKey;
    private $baseUrl;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadCredentials();
    }

    private function loadCredentials() {
        $stmt = $this->pdo->query("SELECT url, api_key FROM gatewayproprio LIMIT 1");
        $credentials = $stmt->fetch();

        if (!$credentials) {
            throw new Exception('Credenciais do Gateway Próprio não encontradas.');
        }

        $this->baseUrl = rtrim($credentials['url'], '/');
        $this->apiKey = $credentials['api_key'];
    }

    public function createDeposit($amount, $cpf, $nome, $email, $callbackUrl, $idempotencyKey) {
        $url = $this->baseUrl . '/api/v1/cashin';

        $payload = [
            'nome' => $nome,
            'cpf' => $cpf,
            'valor' => number_format($amount, 2, '.', ''),
            'descricao' => 'Pagamento Raspadinha',
            'postback' => $callbackUrl
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Apikey: ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Erro na requisição cURL: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception('Erro HTTP ' . $httpCode . ' na requisição para o Gateway Próprio.');
        }

        $responseData = json_decode($response, true);

        if (!$responseData) {
            throw new Exception('Resposta inválida do Gateway Próprio.');
        }

        if (!isset($responseData['id'], $responseData['pix'])) {
            throw new Exception('Resposta do Gateway Próprio não contém os dados necessários.');
        }

        return [
            'transactionId' => $responseData['id'],
            'qrcode' => $responseData['pix'],
            'idempotencyKey' => $idempotencyKey,
            'status' => $responseData['status'] ?? 'PENDING',
            'value' => $responseData['value'] ?? $amount
        ];
    }

    public function checkTransactionStatus($transactionId) {
        $url = $this->baseUrl . '/api/v1/transaction/' . $transactionId;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Apikey: ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Erro ao consultar status da transação.');
        }

        return json_decode($response, true);
    }
}

?>