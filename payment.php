<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

sleep(2);

$amount = isset($_POST['amount']) ? floatval(str_replace(',', '.', $_POST['amount'])) : 0;
$cpf = isset($_POST['cpf']) ? preg_replace('/\D/', '', $_POST['cpf']) : '';

if ($amount <= 0 || strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}

require_once __DIR__ . '/../conexao.php';

try {
    // Verificar gateway ativo
    $stmt = $pdo->query("SELECT active FROM gateway LIMIT 1");
    $activeGateway = $stmt->fetchColumn();

    if (!in_array($activeGateway, ['ondapay'])) {
        throw new Exception('Gateway não configurado ou não suportado.');
    }

    // Verificar autenticação do usuário
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Usuário não autenticado.');
    }

    $usuario_id = $_SESSION['usuario_id'];

    // Buscar dados do usuário
    $stmt = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
    $stmt->execute();
    $usuario = $stmt->fetch();

    if (!$usuario) {
        throw new Exception('Usuário não encontrado.');
    }

    // Configurar URLs base
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $base = $protocol . $host;

    $external_id = uniqid();
    $idempotencyKey = uniqid() . '-' . time();

    if ($activeGateway === 'ondapay') {
        // ===== PROCESSAR COM OndaPay =====
        $stmt = $pdo->query("SELECT url, client_id, client_secret FROM ondapay LIMIT 1");
        $ondapay = $stmt->fetch();

        if (!$ondapay) {
            throw new Exception('Credenciais OndaPay não encontradas.');
        }

        $url = rtrim($ondapay['url'], '/');
        $ci = $ondapay['client_id'];
        $cs = $ondapay['client_secret'];

        $ch = curl_init("$url/api/v1/login");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "client_id: $ci",
              	"client_secret: $cs",
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $authData = json_decode($response, true);
        if (!isset($authData['token'])) {
            throw new Exception('Falha ao obter access_token da OndaPay.');
        }

        $accessToken = $authData['token'];
        $postbackUrl = $base . '/callback/ondapay.php';

        $payload = [
            'amount' => (float)$amount,
            'external_id' => $external_id,
            'webhook' => $postbackUrl,
            'description' => 'Pagamento Raspadinha',
            'payer' => [
                'name' => $usuario['nome'],
                'document' => $cpf,
                'email' => $usuario['email']
            ],
          	'dueDate' => date('Y-m-d H:i:s', strtotime('+1 day'))
        ];

        $ch = curl_init("$url/api/v1/deposit/pix");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $pixData = json_decode($response, true);

        if (!isset($pixData['id_transaction'], $pixData['qrcode'])) {
            throw new Exception('Falha ao gerar QR Code.');
        }

        // Salvar no banco
        $stmt = $pdo->prepare("
            INSERT INTO depositos (transactionId, user_id, nome, cpf, valor, status, qrcode, gateway, idempotency_key)
            VALUES (:transactionId, :user_id, :nome, :cpf, :valor, 'PENDING', :qrcode, 'ondapay', :idempotency_key)
        ");

        $stmt->execute([
            ':transactionId' => $pixData['id_transaction'],
            ':user_id' => $usuario_id,
            ':nome' => $usuario['nome'],
            ':cpf' => $cpf,
            ':valor' => $amount,
            ':qrcode' => $pixData['qrcode'],
            ':idempotency_key' => $external_id
        ]);

        $_SESSION['transactionId'] = $pixData['transactionId'];

        echo json_encode([
            'qrcode' => $pixData['qrcode'],
            'gateway' => 'ondapay'
        ]);

    } 

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>