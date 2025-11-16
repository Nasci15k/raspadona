<?php
// Carregar variáveis de ambiente
require_once __DIR__ . '/vendor/autoload.php';

// Usar variáveis de ambiente ou valores padrão
$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'seu_banco';
$user = getenv('DB_USER') ?: 'seu_usuario';
$pass = getenv('DB_PASSWORD') ?: 'sua_senha';
$charset = 'utf8mb4';  

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

$site = $pdo->query("SELECT nome_site, logo, deposito_min, saque_min, cpa_padrao, revshare_padrao FROM config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$nomeSite = $site['nome_site'] ?? getenv('SITE_NAME') ?? 'Nasci15k Raspadinhas'; 
$logoSite = $site['logo'] ?? getenv('SITE_LOGO') ?? '';
$depositoMin = $site['deposito_min'] ?? getenv('DEPOSITO_MIN') ?? 10;
$saqueMin = $site['saque_min'] ?? getenv('SAQUE_MIN') ?? 50;
$cpaPadrao = $site['cpa_padrao'] ?? getenv('CPA_PADRAO') ?? 10;
$revshare_padrao = $site['revshare_padrao'] ?? getenv('REVSHARE_PADRAO') ?? 10;
