<?php
require_once __DIR__ . '/../conexao.php';

// Log para debug
$logFile = __DIR__ . '/../logs/brazilpagamentos_webhook.log';
$logData = date('Y-m-d H:i:s') . ' - Webhook recebido: ' . file_get_contents('php://input') . "\n";
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

    // Verificar se é uma notificação de transação
    if (!isset($data['type']) || $data['type'] !== 'transaction') {
        throw new Exception('Tipo de notificação não suportado');
    }

    // Verificar se os dados da transação existem
    if (!isset($data['data']) || !is_array($data['data'])) {
        throw new Exception('Dados da transação não encontrados');
    }

    $transactionData = $data['data'];

    // Verificar campos obrigatórios
    if (!isset($transactionData['id'], $transactionData['status'])) {
        throw new Exception('Campos obrigatórios não encontrados');
    }

    $transactionId = $transactionData['id'];
    $status = $transactionData['status'];

    // Buscar o depósito no banco usando o transactionId
    $stmt = $pdo->prepare("SELECT * FROM depositos WHERE transactionId = ? AND gateway = 'brazilpagamentos' LIMIT 1");
    $stmt->execute([$transactionId]);
    $deposito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$deposito) {
        throw new Exception('Depósito não encontrado: ' . $transactionId);
    }

    // Verificar se já foi processado
    if ($deposito['status'] === 'PAID') {
        $logData = date('Y-m-d H:i:s') . ' - Depósito já processado: ' . $deposito['status'] . "\n";
        file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
        http_response_code(200);
        exit('OK');
    }

    // Mapear status do Brazil Pagamentos para nosso sistema
    $newStatus = 'PENDING';
    switch (strtolower($status)) {
        case 'approved':
        case 'paid':
            $newStatus = 'PAID';
            break;
        case 'cancelled':
        case 'canceled':
        case 'refused':
            $newStatus = 'PENDING'; // Como não temos CANCELLED no enum, mantemos PENDING
            break;
        case 'pending':
        case 'waiting_payment':
            $newStatus = 'PENDING';
            break;
        default:
            $newStatus = 'PENDING';
    }

    // Atualizar status do depósito
    $stmt = $pdo->prepare("UPDATE depositos SET status = ? WHERE transactionId = ?");
    $stmt->execute([$newStatus, $transactionId]);

    // Se foi aprovado, adicionar saldo ao usuário
    if ($newStatus === 'PAID') {
        $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
        $stmt->execute([$deposito['valor'], $deposito['user_id']]);

        // Log da aprovação
        $logData = date('Y-m-d H:i:s') . ' - Depósito aprovado: ' . $transactionId . ' - Valor: ' . $deposito['valor'] . "\n";
        file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

        // VERIFICAÇÃO PARA CPA (PORCENTAGEM DO DEPÓSITO - SEMPRE PAGO)
        $stmt = $pdo->prepare("SELECT indicacao FROM usuarios WHERE id = :uid");
        $stmt->execute([':uid' => $deposito['user_id']]);
        $usuario = $stmt->fetch();

        $logData = date('Y-m-d H:i:s') . ' - USUÁRIO DATA: ' . print_r($usuario, true) . "\n";
        file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

        if ($usuario && !empty($usuario['indicacao'])) {
            $logData = date('Y-m-d H:i:s') . ' - USUÁRIO TEM INDICAÇÃO: ' . $usuario['indicacao'] . "\n";
            file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

            $stmt = $pdo->prepare("SELECT id, comissao_cpa, banido FROM usuarios WHERE id = :afiliado_id");
            $stmt->execute([':afiliado_id' => $usuario['indicacao']]);
            $afiliado = $stmt->fetch();

            $logData = date('Y-m-d H:i:s') . ' - AFILIADO DATA: ' . print_r($afiliado, true) . "\n";
            file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);

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
                    $logData = date('Y-m-d H:i:s') . ' - ERRO AO INSERIR TRANSAÇÃO AFILIADO: ' . $insertError->getMessage() . "\n";
                    file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
                }

                $logData = date('Y-m-d H:i:s') . ' - CPA PAGO: Afiliado ' . $afiliado['id'] . ' recebeu R$ ' . $comissao . ' (' . $afiliado['comissao_cpa'] . '% do depósito de R$ ' . $deposito['valor'] . ') do usuário ' . $deposito['user_id'] . "\n";
                file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
            } else {
                $logData = date('Y-m-d H:i:s') . ' - CPA NÃO PAGO: Afiliado inválido ou sem comissão' . "\n";
                file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
            }
        } else {
            $logData = date('Y-m-d H:i:s') . ' - USUÁRIO SEM INDICAÇÃO' . "\n";
            file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
        }
    }

    // Log do processamento
    $logData = date('Y-m-d H:i:s') . ' - Status atualizado: ' . $transactionId . ' -> ' . $newStatus . "\n";
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