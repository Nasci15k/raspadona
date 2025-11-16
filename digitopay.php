<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// CONFIGURAÇÃO DE LOGS - ALTERE AQUI PARA ATIVAR/DESATIVAR
define('DEBUG_MODE', false); // true = logs ativos | false = logs desativados
define('LOG_FILE', 'logs_digitopay.txt');

// Função para gravar logs apenas se DEBUG_MODE estiver ativo
function writeLog($message) {
    if (DEBUG_MODE) {
        file_put_contents(LOG_FILE, date('d/m/Y H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../conexao.php';
    
    // Receber dados do webhook
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    writeLog("PAYLOAD DIGITOPAY: " . print_r($data, true));
    writeLog("----------------------------------------------------------");
    
    if (!$data) {
        throw new Exception('Dados inválidos recebidos');
    }
    
    // Verificar se possui os campos necessários
    if (!isset($data['id']) || !isset($data['status'])) {
        throw new Exception('Campos obrigatórios não encontrados');
    }
    
    $transactionId = $data['id'];
    $status = strtoupper($data['status']);
    $idempotencyKey = $data['idempotencyKey'] ?? null;
    
    // Mapear status da DigitoPay para o sistema
    $statusMap = [
        'REALIZADO' => 'PAID',
        'CANCELADO' => 'CANCELLED',
        'EXPIRADO' => 'CANCELLED',
        'PENDENTE' => 'PENDING',
        'EM PROCESSAMENTO' => 'PENDING',
        'ANALISE' => 'PENDING',
        'ERRO' => 'FAILED'
    ];
    
    $newStatus = $statusMap[$status] ?? 'PENDING';
    
    writeLog("INICIANDO PROCESSO PARA TXN: " . $transactionId . " | STATUS: " . $newStatus);
    
    // Buscar a transação no banco
    $stmt = $pdo->prepare("
        SELECT id, user_id, valor, status, gateway 
        FROM depositos 
        WHERE transactionId = :transactionId 
        OR idempotency_key = :idempotencyKey
        LIMIT 1
    ");
    
    $stmt->execute([
        ':transactionId' => $transactionId,
        ':idempotencyKey' => $idempotencyKey
    ]);
    
    $deposito = $stmt->fetch();
    
    if (!$deposito) {
        // Se não encontrou por transactionId ou idempotencyKey, tentar buscar apenas por transactionId
        $stmt = $pdo->prepare("
            SELECT id, user_id, valor, status, gateway 
            FROM depositos 
            WHERE transactionId = :transactionId 
            LIMIT 1
        ");
        
        $stmt->execute([':transactionId' => $transactionId]);
        $deposito = $stmt->fetch();
        
        if (!$deposito) {
            writeLog("ERRO: Depósito não encontrado para TXN: " . $transactionId);
            throw new Exception('Transação não encontrada: ' . $transactionId);
        }
    }
    
    writeLog("DEPÓSITO ENCONTRADO: " . print_r($deposito, true));
    
    // Verificar se é gateway DigitoPay
    if ($deposito['gateway'] !== 'digitopay') {
        throw new Exception('Gateway incorreto para esta transação');
    }
    
    // Se o status já foi processado, retornar sucesso
    if ($deposito['status'] === $newStatus) {
        echo json_encode(['status' => 'success', 'message' => 'Status já processado']);
        exit;
    }
    
    // Se a transação foi aprovada, processar com lógica completa incluindo CPA
    if ($newStatus === 'PAID') {
        // Verificar se o valor já não foi creditado
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM transacoes 
            WHERE tipo = 'DEPOSIT' 
            AND referencia = :transactionId 
            AND status = 'COMPLETED'
        ");
        
        $stmt->execute([':transactionId' => $transactionId]);
        $jaProcessado = $stmt->fetchColumn();
        
        if ($jaProcessado == 0) {
            try {
                $pdo->beginTransaction();
                
                // Atualizar status da transação
                $stmt = $pdo->prepare("
                    UPDATE depositos 
                    SET status = :status, 
                        updated_at = NOW(),
                        webhook_data = :webhook_data
                    WHERE id = :id
                ");
                
                $result = $stmt->execute([
                    ':status' => $newStatus,
                    ':webhook_data' => $input,
                    ':id' => $deposito['id']
                ]);
                
                if (!$result) {
                    throw new Exception('Erro ao atualizar status da transação');
                }
                
                writeLog("DEPÓSITO ATUALIZADO PARA PAID");
                
                // Buscar saldo atual do usuário
                $stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = :user_id");
                $stmt->execute([':user_id' => $deposito['user_id']]);
                $saldoAtual = $stmt->fetchColumn();
                
                if ($saldoAtual === false) {
                    throw new Exception('Usuário não encontrado');
                }
                
                // Atualizar saldo do usuário
                $novoSaldo = $saldoAtual + $deposito['valor'];
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET saldo = :novo_saldo 
                    WHERE id = :user_id
                ");
                
                $stmt->execute([
                    ':novo_saldo' => $novoSaldo,
                    ':user_id' => $deposito['user_id']
                ]);
                
                writeLog("SALDO CREDITADO: R$ " . $deposito['valor'] . " para usuário " . $deposito['user_id']);
                
                // Registrar a transação
                $stmt = $pdo->prepare("
                    INSERT INTO transacoes (
                        user_id, tipo, valor, saldo_anterior, saldo_posterior, 
                        status, referencia, gateway, descricao, created_at
                    ) VALUES (
                        :user_id, 'DEPOSIT', :valor, :saldo_anterior, :saldo_posterior,
                        'COMPLETED', :referencia, 'digitopay', :descricao, NOW()
                    )
                ");
                
                $stmt->execute([
                    ':user_id' => $deposito['user_id'],
                    ':valor' => $deposito['valor'],
                    ':saldo_anterior' => $saldoAtual,
                    ':saldo_posterior' => $novoSaldo,
                    ':referencia' => $transactionId,
                    ':descricao' => 'Depósito via DigitoPay - ' . $transactionId
                ]);
                
                // VERIFICAÇÃO PARA CPA (PORCENTAGEM DO DEPÓSITO - SEMPRE PAGO)
                $stmt = $pdo->prepare("SELECT indicacao FROM usuarios WHERE id = :uid");
                $stmt->execute([':uid' => $deposito['user_id']]);
                $usuario = $stmt->fetch();
                
                writeLog("USUÁRIO DATA: " . print_r($usuario, true));
                
                if ($usuario && !empty($usuario['indicacao'])) {
                    writeLog("USUÁRIO TEM INDICAÇÃO: " . $usuario['indicacao']);
                    
                    $stmt = $pdo->prepare("SELECT id, comissao_cpa, banido FROM usuarios WHERE id = :afiliado_id");
                    $stmt->execute([':afiliado_id' => $usuario['indicacao']]);
                    $afiliado = $stmt->fetch();
                    
                    writeLog("AFILIADO DATA: " . print_r($afiliado, true));
                    
                    if ($afiliado && $afiliado['banido'] != 1 && !empty($afiliado['comissao_cpa'])) {
                        // Calcula a comissão como porcentagem do valor do depósito
                        $comissao = ($deposito['valor'] * $afiliado['comissao_cpa']) / 100;
                        
                        // Credita a comissão CPA para o afiliado
                        $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + :comissao WHERE id = :afiliado_id");
                        $stmt->execute([
                            ':comissao' => $comissao,
                            ':afiliado_id' => $afiliado['id']
                        ]);
                        
                        // Tenta inserir na tabela transacoes_afiliados
                        try {
                            $stmt = $pdo->prepare("INSERT INTO transacoes_afiliados
                                                  (afiliado_id, usuario_id, deposito_id, valor, created_at)
                                                  VALUES (:afiliado_id, :usuario_id, :deposito_id, :valor, NOW())");
                            $stmt->execute([
                                ':afiliado_id' => $afiliado['id'],
                                ':usuario_id' => $deposito['user_id'],
                                ':deposito_id' => $deposito['id'],
                                ':valor' => $comissao
                            ]);
                        } catch (Exception $insertError) {
                            writeLog("ERRO AO INSERIR TRANSAÇÃO AFILIADO: " . $insertError->getMessage());
                        }
                        
                        writeLog("CPA PAGO: Afiliado {$afiliado['id']} recebeu R$ {$comissao} ({$afiliado['comissao_cpa']}% do depósito de R$ {$deposito['valor']}) do usuário {$deposito['user_id']}");
                    } else {
                        writeLog("CPA NÃO PAGO: Afiliado inválido ou sem comissão");
                    }
                } else {
                    writeLog("USUÁRIO SEM INDICAÇÃO");
                }
                
                $pdo->commit();
                writeLog("TRANSAÇÃO FINALIZADA COM SUCESSO");
                
            } catch (Exception $e) {
                $pdo->rollback();
                writeLog("ERRO GERAL: " . $e->getMessage());
                writeLog("STACK TRACE: " . $e->getTraceAsString());
                throw new Exception('Erro ao processar aprovação: ' . $e->getMessage());
            }
        } else {
            writeLog("TRANSAÇÃO JÁ PROCESSADA ANTERIORMENTE");
        }
    } else {
        // Para outros status que não são APPROVED, apenas atualizar o status
        $stmt = $pdo->prepare("
            UPDATE depositos 
            SET status = :status, 
                updated_at = NOW(),
                webhook_data = :webhook_data
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
            ':status' => $newStatus,
            ':webhook_data' => $input,
            ':id' => $deposito['id']
        ]);
        
        if (!$result) {
            throw new Exception('Erro ao atualizar status da transação');
        }
        
        writeLog("STATUS ATUALIZADO PARA: " . $newStatus);
    }
    
    // Resposta de sucesso
    echo json_encode([
        'status' => 'success', 
        'message' => 'Webhook processado com sucesso',
        'transaction_id' => $transactionId,
        'new_status' => $newStatus
    ]);
    
} catch (Exception $e) {
    writeLog("ERRO GERAL: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
}