<?php
class DigitoPay {
    private $url;
    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadCredentials();
    }

    private function loadCredentials() {
        $stmt = $this->pdo->query("SELECT url, client_id, client_secret FROM digitopay LIMIT 1");
        $credentials = $stmt->fetch();

        if (!$credentials) {
            throw new Exception('Credenciais DigitoPay não encontradas.');
        }

        $this->url = rtrim($credentials['url'], '/');
        $this->clientId = $credentials['client_id'];
        $this->clientSecret = $credentials['client_secret'];
    }

    private function authenticate() {
        $ch = curl_init($this->url . '/api/token/api');
        
        $payload = [
            'clientId' => $this->clientId,
            'secret' => $this->clientSecret
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Falha na autenticação DigitoPay: ' . $response);
        }

        $authData = json_decode($response, true);
        
        // Log da resposta para debug
        error_log("DigitoPay Auth Response: " . $response);
        
        if (!$authData) {
            throw new Exception('Resposta inválida da DigitoPay: ' . $response);
        }
        
        // A DigitoPay pode retornar diferentes formatos de resposta
        // Verificar os possíveis campos onde o token pode estar
        $token = null;
        
        if (isset($authData['token'])) {
            $token = $authData['token'];
        } elseif (isset($authData['access_token'])) {
            $token = $authData['access_token'];
        } elseif (isset($authData['accessToken'])) {
            $token = $authData['accessToken'];
        } elseif (isset($authData['data']['token'])) {
            $token = $authData['data']['token'];
        } elseif (isset($authData['data']['access_token'])) {
            $token = $authData['data']['access_token'];
        }
        
        if (!$token) {
            throw new Exception('Token não encontrado na resposta da DigitoPay. Resposta: ' . $response);
        }

        $this->accessToken = $token;
        return $this->accessToken;
    }

    public function createDeposit($amount, $cpf, $nome, $email, $callbackUrl, $idempotencyKey = null) {
        // Autenticar primeiro
        $this->authenticate();

        // Gerar idempotency key se não fornecida
        if (!$idempotencyKey) {
            $idempotencyKey = uniqid() . '-' . time();
        }

        // Data de vencimento (24 horas a partir de agora)
        $dueDate = date('c', strtotime('+24 hours'));

        $payload = [
            'dueDate' => $dueDate,
            'paymentOptions' => ['PIX'],
            'person' => [ 'cpf' => $cpf, 'name' => $nome ],
            'value' => floatval($amount),
            'callbackUrl' => $callbackUrl,
            'idempotencyKey' => $idempotencyKey,
        ];

        //var_dump($payload);

        $ch = curl_init($this->url . '/api/deposit');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log da resposta para debug
        error_log("DigitoPay Deposit Response (HTTP $httpCode): " . $response);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception('Erro ao criar depósito DigitoPay (HTTP ' . $httpCode . '): ' . $response);
        }

        $depositData = json_decode($response, true);

        if (!$depositData) {
            throw new Exception('Resposta inválida da DigitoPay: ' . $response);
        }

        // CORREÇÃO: Baseado no erro, a resposta tem esta estrutura:
        // {"id":"dffcebc5-364b-4ae5-8413-5704fc1a1167","pixCopiaECola":"...","qrCodeBase64":"...","success":true,"message":"..."}
        
        $transactionId = null;
        $qrcode = null;
        
        // Verificar se existe o campo 'id' diretamente
        if (isset($depositData['id'])) {
            $transactionId = $depositData['id'];
        }
        
        // Verificar diferentes campos para o QR Code
        if (isset($depositData['pixCopiaECola'])) {
            $qrcode = $depositData['pixCopiaECola'];
        } elseif (isset($depositData['qrCode'])) {
            $qrcode = $depositData['qrCode'];
        } elseif (isset($depositData['qrcode'])) {
            $qrcode = $depositData['qrcode'];
        } elseif (isset($depositData['qrCodeBase64'])) {
            // Se for base64, vamos usar o pixCopiaECola que é mais útil
            $qrcode = $depositData['pixCopiaECola'] ?? $depositData['qrCodeBase64'];
        }

        // Verificar se encontramos os dados necessários
        if (!$transactionId) {
            throw new Exception('ID da transação não encontrado na resposta da DigitoPay. Resposta: ' . $response);
        }
        
        if (!$qrcode) {
            throw new Exception('QR Code não encontrado na resposta da DigitoPay. Resposta: ' . $response);
        }

        // Log para debug
        error_log("DigitoPay Transaction ID: " . $transactionId);
        error_log("DigitoPay QR Code: " . substr($qrcode, 0, 50) . "...");

        return [
            'transactionId' => $transactionId,
            'qrcode' => $qrcode,
            'idempotencyKey' => $idempotencyKey,
            'dueDate' => $dueDate,
            'fullResponse' => $depositData
        ];
    }

    public function createWithdraw($amount, $cpf, $nome, $pixKey, $pixKeyType, $callbackUrl, $idempotencyKey = null) {
        // Autenticar primeiro
        $this->authenticate();

        // Gerar idempotency key se não fornecida
        if (!$idempotencyKey) {
            $idempotencyKey = uniqid() . '-' . time();
        }

        $payload = [
            'paymentOptions' => ['PIX'],
            'person' => [
                'pixKeyTypes' => $pixKeyType,
                'pixKey' => $pixKey,
                'name' => $nome,
                'cpf' => $cpf
            ],
            'value' => floatval($amount),
            'callbackUrl' => $callbackUrl,
            'idempotencyKey' => $idempotencyKey
        ];

        $ch = curl_init($this->url . '/api/withdraw');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception('Erro ao criar saque DigitoPay: ' . $response);
        }

        $withdrawData = json_decode($response, true);

        return [
            'transactionId' => $withdrawData['id'] ?? null,
            'idempotencyKey' => $idempotencyKey,
            'status' => $withdrawData['status'] ?? 'EM PROCESSAMENTO'
        ];
    }

    public function consultTransaction($idempotencyKey = null, $transactionId = null) {
        $this->authenticate();

        $queryParams = [];
        if ($idempotencyKey) {
            $queryParams['idempotencyKey'] = $idempotencyKey;
        }
        if ($transactionId) {
            $queryParams['id'] = $transactionId;
        }

        $url = $this->url . '/api/getTransaction';
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Erro ao consultar transação DigitoPay: ' . $response);
        }

        return json_decode($response, true);
    }

    public function refundTransaction($transactionId) {
        $this->authenticate();

        $payload = [
            'id' => $transactionId
        ];

        $ch = curl_init($this->url . '/api/refund');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Erro ao estornar transação DigitoPay: ' . $response);
        }

        return json_decode($response, true);
    }

    public function consultPixKey($pixKey, $pixType) {
        $this->authenticate();

        $queryParams = [
            'pixKey' => $pixKey,
            'pixType' => $pixType
        ];

        $url = $this->url . '/api/getPixKey?' . http_build_query($queryParams);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Erro ao consultar chave PIX DigitoPay: ' . $response);
        }

        return json_decode($response, true);
    }
}