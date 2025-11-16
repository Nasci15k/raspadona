<?php
require_once __DIR__ . '/../conexao.php';

// Log para debug
$logFile = __DIR__ . '/../logs/brazilpagamentos_withdraw_webhook.log';
$logData = date('Y-m-d H:i:s') . ' - Webhook de saque recebido: ' . file_get_contents('php://input') . "\n";
file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

try {
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // Obter o corpo da requisição
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('Dados JSON inválidos');
    }

    // Log dos dados recebidos
    $logData = date('Y-m-d H:i:s') . ' - Dados decodificados: ' . json_encode($data) . "\n";
    file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

    // Verificar se é uma notificação de transferência/saque
    if (!isset($data['type']) || $data['type'] !== 'transfer') {
        throw new Exception('Tipo de notificação não suportado: ' . ($data['type'] ?? 'não definido'));
    }

    // Verificar se os dados da transferência existem
    if (!isset($data['data']) || !is_array($data['data'])) {
        throw new Exception('Dados da transferência não encontrados');
    }

    $transferData = $data['data'];

    // Verificar campos obrigatórios
    if (!isset($transferData['id'], $transferData['status'])) {
        throw new Exception('Campos obrigatórios não encontrados');
    }

    $transferId = $transferData['id'];
    $status = $transferData['status'];
    
    // Log dos dados específicos da transferência
    $logData = date('Y-m-d H:i:s') . ' - Transfer ID: ' . $transferId . ' - Status: ' . $status . ' - Amount: ' . ($transferData['amount'] ?? 'N/A') . "\n";
    file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

    // Buscar o saque no banco usando o transaction_id_digitopay ou transaction_id
    $stmt = $pdo->prepare("SELECT * FROM saques WHERE (transaction_id_digitopay = ? OR transaction_id = ?) AND gateway = 'brazilpagamentos' LIMIT 1");
    $stmt->execute([$transferId, $transferId]);
    $saque = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$saque) {
        throw new Exception('Saque não encontrado: ' . $transferId);
    }

    // Verificar se já foi processado
    if ($saque['status'] === 'PAID' || $saque['status'] === 'CANCELLED') {
        $logData = date('Y-m-d H:i:s') . ' - Saque já processado: ' . $saque['status'] . "\n";
        file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
        http_response_code(200);
        exit('OK');
    }

    // Mapear status do Brazil Pagamentos para nosso sistema
    $newStatus = 'PROCESSING';
    switch (strtolower($status)) {
        case 'approved':
        case 'paid':
        case 'completed':
        case 'COMPLETED':
            $newStatus = 'PAID';
            break;
        case 'cancelled':
        case 'canceled':
        case 'refused':
        case 'failed':
        case 'CANCELLED':
        case 'FAILED':
            $newStatus = 'CANCELLED';
            break;
        case 'pending':
        case 'processing':
        case 'waiting':
        case 'PENDING':
        case 'PROCESSING':
            $newStatus = 'PROCESSING';
            break;
        default:
            $newStatus = 'PROCESSING';
    }

    // Atualizar status do saque
    $stmt = $pdo->prepare("UPDATE saques SET 
        status = ?, 
        webhook_data = ?,
        updated_at = NOW() 
        WHERE id = ?");
    
    $stmt->execute([
        $newStatus, 
        json_encode($transferData),
        $saque['id']
    ]);

    // Log do processamento
    $logData = date('Y-m-d H:i:s') . ' - Status atualizado: ' . $transferId . ' -> ' . $newStatus . "\n";
    file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $logData = date('Y-m-d H:i:s') . ' - Erro: ' . $e->getMessage() . "\n";
    file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
    
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?> 