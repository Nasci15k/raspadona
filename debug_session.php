<?php
@session_start();
include 'conexao.php';

echo "<h2>Debug da Sessão Atual</h2>";

echo "<h3>Dados da Sessão:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Verificação de Login:</h3>";

if (isset($_SESSION['usuario_id'])) {
    $usuarioId = $_SESSION['usuario_id'];
    echo "✅ Usuário logado com ID: " . $usuarioId . "<br><br>";
    
    try {
        // Verificar dados do usuário logado
        $stmt = $pdo->prepare("SELECT id, nome, email, admin, banido FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            echo "<h4>Dados do Usuário Logado:</h4>";
            echo "ID: " . $usuario['id'] . "<br>";
            echo "Nome: " . htmlspecialchars($usuario['nome']) . "<br>";
            echo "Email: " . htmlspecialchars($usuario['email']) . "<br>";
            echo "Admin: " . ($usuario['admin'] ? '<span style="color: green;">SIM</span>' : '<span style="color: red;">NÃO</span>') . "<br>";
            echo "Banido: " . ($usuario['banido'] ? '<span style="color: red;">SIM</span>' : '<span style="color: green;">NÃO</span>') . "<br><br>";
            
            // Simular a verificação do admin/index.php
            echo "<h4>Simulação da Verificação Admin:</h4>";
            $admin = ($stmt = $pdo->prepare("SELECT admin FROM usuarios WHERE id = ?"))->execute([$usuarioId]) ? $stmt->fetchColumn() : null;
            echo "Resultado da query admin: " . ($admin ? '<span style="color: green;">' . $admin . '</span>' : '<span style="color: red;">NULL ou 0</span>') . "<br>";
            
            if ($admin != 1) {
                echo "<p style='color: red;'>❌ PROBLEMA: A verificação retorna que não é admin!</p>";
                echo "<p>Isso explica o redirecionamento para a página inicial.</p>";
            } else {
                echo "<p style='color: green;'>✅ Verificação OK: Usuário é admin</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Usuário não encontrado no banco!</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Erro na consulta: " . $e->getMessage() . "</p>";
    }
    
} else {
    echo "<p style='color: red;'>❌ Usuário NÃO está logado!</p>";
    echo "<p>Você precisa fazer login primeiro em: <a href='/login'>https://raspagreen.queminvestecresce.com.br/login</a></p>";
}

echo "<br><h3>Ações:</h3>";
echo "<p><a href='/login' style='color: blue;'>🔑 Fazer Login</a></p>";
echo "<p><a href='/admin' style='color: green;'>🔧 Tentar Acessar Admin</a></p>";
echo "<p><a href='/' style='color: gray;'>🏠 Voltar para o Site</a></p>";

// Botão para forçar login como admin
if (isset($_GET['force_admin']) && $_GET['force_admin'] == '1') {
    $_SESSION['usuario_id'] = 1;
    echo "<p style='color: green;'>✅ Sessão forçada para usuário ID 1!</p>";
    echo "<script>setTimeout(() => window.location.href = '/admin', 2000);</script>";
}

echo "<br><p><a href='?force_admin=1' style='background: #22c55e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px;'>🚀 Forçar Login como Admin ID 1</a></p>";
?>
