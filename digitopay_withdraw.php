<?php
/**
 * DigitoPay Withdraw Webhook - Versão Final
 * callback/digitopay_withdraw.php
 * 
 * Este webhook recebe notificações da DigitoPay sobre o status dos saques
 * e atualiza automaticamente o status no banco de dados
 */

header('Content-Type: application/json');

// Log detalhado para debug (opcional - remover em produção se não precisar)
$enableDebugLog = true;
$logFile = __DIR__ . '/../logs/digitopay_webhook.log';

function writeLog($message) {
    global $logFile, $enableDebugLog;
    if (!$enableDebugLog) return;
    
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Log do acesso
writeLog("=== WEBHOOK ACCESSED ===");
writeLog("Method: " . $_SERVER['REQUEST_METHOD']);
writeLog("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));
writeLog("Raw Input: " . file_get_contents('php://input'));

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response = ['error' => 'Método não permitido', 'allowed' => 'POST'];
    writeLog("ERROR: Método inválido - " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    exit;
}

try {
    require_once __DIR__ . '/../conexao.php';
    
    // Receber e validar dados do webhook
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    writeLog("Parsed webhook data: " . json_encode($data));
    
    if (!$data) {
        throw new Exception('Dados JSON inválidos recebidos');
    }
    
    // Verificar campos obrigatórios
    if (!isset($data['id']) || !isset($data['status'])) {
        throw new Exception('Campos obrigatórios não encontrados (id, status)');
    }
    
    $transactionId = $data['id'];
    $status = strtoupper(trim($data['status']));
    $idempotencyKey = $data['idempotencyKey'] ?? null;
    
    writeLog("Processing - Transaction ID: $transactionId, Status: $status, Idempotency: $idempotencyKey");
    
    // Mapeamento completo de status DigitoPay para sistema interno
    $statusMap = [
        'REALIZADO' => 'PAID',
        'CANCELADO' => 'CANCELLED',
        'ERRO' => 'FAILED',
        'PENDENTE' => 'PENDING',
        'EM PROCESSAMENTO' => 'PROCESSING',
        'ANALISE' => 'PROCESSING',
        'APPROVED' => 'PAID',
        'REJECTED' => 'CANCELLED',
        'COMPLETED' => 'PAID',
        'FAILED' => 'FAILED',
        'PROCESSING' => 'PROCESSING',
        'SUCCESS' => 'PAID',
        'CONFIRMED' => 'PAID'
    ];
    
    $newStatus = $statusMap[$status] ?? 'PROCESSING';
    writeLog("Status mapping: $status -> $newStatus");
    
    // Buscar o saque no banco usando múltiplos critérios
    $saque = null;
    $searchMethod = '';
    
    // 1. Buscar por transaction_id_digitopay (mais confiável)
    if ($transactionId) {
        $stmt = $pdo->prepare("
            SELECT id, user_id, valor, status, gateway 
            FROM saques 
            WHERE transaction_id_digitopay = :transaction_id
            AND gateway = 'digitopay'
            LIMIT 1
        ");
        $stmt->execute([':transaction_id' => $transactionId]);
        $saque = $stmt->fetch();
        if ($saque) $searchMethod = 'transaction_id_digitopay';
    }
    
    // 2. Buscar por idempotency_key se não encontrou
    if (!$saque && $idempotencyKey) {
        $stmt = $pdo->prepare("
            SELECT id, user_id, valor, status, gateway 
            FROM saques 
            WHERE digitopay_idempotency_key = :idempotency_key
            AND gateway = 'digitopay'
            LIMIT 1
        ");
        $stmt->execute([':idempotency_key' => $idempotencyKey]);
        $saque = $stmt->fetch();
        if ($saque) $searchMethod = 'idempotency_key';
    }
    
    // 3. Último recurso: saque mais recente em processamento (últimas 2 horas)
    if (!$saque) {
        $stmt = $pdo->prepare("
            SELECT id, user_id, valor, status, gateway 
            FROM saques 
            WHERE gateway = 'digitopay' 
            AND status IN ('PROCESSING', 'EM PROCESSAMENTO', 'PENDING')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $stmt->execute();
        $saque = $stmt->fetch();
        if ($saque) $searchMethod = 'recent_processing';
    }
    
    writeLog("Search result: " . ($saque ? "Found via $searchMethod" : "Not found"));
    writeLog("Saque data: " . json_encode($saque));
    
    if (!$saque) {
        $error = "Saque não encontrado para transactionId: $transactionId";
        writeLog("ERROR: $error");
        
        // Retornar sucesso mesmo assim para evitar reenvios desnecessários
        echo json_encode([
            'status' => 'success',
            'message' => 'Webhook recebido mas saque não encontrado',
            'transaction_id' => $transactionId
        ]);
        exit;
    }
    
    // Verificar se já está no status final
    if ($saque['status'] === $newStatus) {
        writeLog("Status já atualizado: {$saque['status']}");
        echo json_encode([
            'status' => 'success', 
            'message' => 'Status já processado',
            'saque_id' => $saque['id'],
            'current_status' => $saque['status']
        ]);
        exit;
    }
    
    // Iniciar transação para atualização
    $pdo->beginTransaction();
    
    try {
        // Atualizar status do saque
        $stmt = $pdo->prepare("
            UPDATE saques 
            SET status = :status,
                transaction_id_digitopay = COALESCE(transaction_id_digitopay, :transaction_id),
                digitopay_idempotency_key = COALESCE(digitopay_idempotency_key, :idempotency_key),
                webhook_data = :webhook_data,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $updateResult = $stmt->execute([
            ':status' => $newStatus,
            ':transaction_id' => $transactionId,
            ':idempotency_key' => $idempotencyKey,
            ':webhook_data' => $input,
            ':id' => $saque['id']
        ]);
        
        if (!$updateResult) {
            throw new Exception('Falha ao atualizar status do saque no banco');
        }
        
        writeLog("Status updated: {$saque['status']} -> $newStatus for saque ID {$saque['id']}");
        
        // Processar estorno se saque foi cancelado ou falhou
        if (in_array($newStatus, ['CANCELLED', 'FAILED'])) {
            writeLog("Processing refund for failed/cancelled withdrawal");
            
            // Verificar se estorno já foi processado
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM transacoes 
                WHERE tipo = 'REFUND' 
                AND referencia = :transaction_id 
                AND status = 'COMPLETED'
            ");
            $stmt->execute([':transaction_id' => $transactionId]);
            $jaEstornado = $stmt->fetchColumn();
            
            if ($jaEstornado == 0) {
                // Buscar saldo atual do usuário
                $stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = :user_id FOR UPDATE");
                $stmt->execute([':user_id' => $saque['user_id']]);
                $saldoAtual = $stmt->fetchColumn();
                
                if ($saldoAtual === false) {
                    throw new Exception('Usuário não encontrado para estorno');
                }
                
                // Devolver valor para o usuário
                $novoSaldo = $saldoAtual + $saque['valor'];
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET saldo = :novo_saldo, updated_at = NOW()
                    WHERE id = :user_id
                ");
                $stmt->execute([
                    ':novo_saldo' => $novoSaldo,
                    ':user_id' => $saque['user_id']
                ]);
                
                // Registrar transação de estorno
                $stmt = $pdo->prepare("
                    INSERT INTO transacoes (
                        user_id, tipo, valor, saldo_anterior, saldo_posterior, 
                        status, referencia, gateway, descricao, created_at
                    ) VALUES (
                        :user_id, 'REFUND', :valor, :saldo_anterior, :saldo_posterior,
                        'COMPLETED', :referencia, 'digitopay', :descricao, NOW()
                    )
                ");
                
                $descricao = 'Estorno automático - Saque DigitoPay ' . 
                           ($newStatus === 'CANCELLED' ? 'cancelado' : 'falhou') . 
                           ' - ' . $transactionId;
                
                $stmt->execute([
                    ':user_id' => $saque['user_id'],
                    ':valor' => $saque['valor'],
                    ':saldo_anterior' => $saldoAtual,
                    ':saldo_posterior' => $novoSaldo,
                    ':referencia' => $transactionId,
                    ':descricao' => $descricao
                ]);
                
                writeLog("Refund processed: R$ {$saque['valor']} returned to user {$saque['user_id']}");
            } else {
                writeLog("Refund already processed for transaction $transactionId");
            }
        }
        
        $pdo->commit();
        writeLog("Transaction committed successfully");
        
    } catch (Exception $e) {
        $pdo->rollback();
        writeLog("Transaction rolled back: " . $e->getMessage());
        throw new Exception('Erro ao processar webhook: ' . $e->getMessage());
    }
    
    // Resposta de sucesso
    $response = [
        'status' => 'success', 
        'message' => 'Webhook processado com sucesso',
        'transaction_id' => $transactionId,
        'saque_id' => $saque['id'],
        'old_status' => $saque['status'],
        'new_status' => $newStatus,
        'search_method' => $searchMethod,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    writeLog("SUCCESS: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    
    $error = [
        'status' => 'error', 
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    writeLog("ERROR: " . json_encode($error));
    echo json_encode($error);
}
?>