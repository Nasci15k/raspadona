<?php
// Script para verificar e corrigir permissões de admin
include 'conexao.php';

echo "<h2>Verificação de Permissões Admin</h2>";

try {
    // Verificar usuário ID 1
    $stmt = $pdo->prepare("SELECT id, nome, email, admin, banido FROM usuarios WHERE id = 1");
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuario) {
        echo "<h3>Usuário ID 1:</h3>";
        echo "Nome: " . htmlspecialchars($usuario['nome']) . "<br>";
        echo "Email: " . htmlspecialchars($usuario['email']) . "<br>";
        echo "Admin: " . ($usuario['admin'] ? 'SIM' : 'NÃO') . "<br>";
        echo "Banido: " . ($usuario['banido'] ? 'SIM' : 'NÃO') . "<br><br>";
        
        // Se não for admin, tornar admin
        if ($usuario['admin'] != 1) {
            echo "<p style='color: red;'>❌ Usuário não é admin. Corrigindo...</p>";
            $stmt = $pdo->prepare("UPDATE usuarios SET admin = 1 WHERE id = 1");
            if ($stmt->execute()) {
                echo "<p style='color: green;'>✅ Usuário ID 1 agora é administrador!</p>";
            } else {
                echo "<p style='color: red;'>❌ Erro ao atualizar permissões</p>";
            }
        } else {
            echo "<p style='color: green;'>✅ Usuário já é administrador</p>";
        }
        
        // Se estiver banido, desbanir
        if ($usuario['banido'] == 1) {
            echo "<p style='color: red;'>❌ Usuário está banido. Corrigindo...</p>";
            $stmt = $pdo->prepare("UPDATE usuarios SET banido = 0 WHERE id = 1");
            if ($stmt->execute()) {
                echo "<p style='color: green;'>✅ Usuário ID 1 foi desbanido!</p>";
            } else {
                echo "<p style='color: red;'>❌ Erro ao desbanir usuário</p>";
            }
        } else {
            echo "<p style='color: green;'>✅ Usuário não está banido</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Usuário com ID 1 não encontrado!</p>";
    }
    
    // Verificar todos os admins
    echo "<h3>Todos os Administradores:</h3>";
    $stmt = $pdo->prepare("SELECT id, nome, email, admin FROM usuarios WHERE admin = 1");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($admins) {
        foreach ($admins as $admin) {
            echo "ID: {$admin['id']} - Nome: " . htmlspecialchars($admin['nome']) . " - Email: " . htmlspecialchars($admin['email']) . "<br>";
        }
    } else {
        echo "<p style='color: red;'>❌ Nenhum administrador encontrado!</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Erro: " . $e->getMessage() . "</p>";
}

echo "<br><h3>Teste de Acesso:</h3>";
echo "<p>Depois de corrigir, tente acessar: <a href='/admin' target='_blank'>https://raspagreen.queminvestecresce.com.br/admin</a></p>";
echo "<p><a href='/' style='color: blue;'>← Voltar para o site</a></p>";
?>
