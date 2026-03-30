<?php
session_start();

try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro na conexão: ' . $e->getMessage()
    ]);
    exit;
}

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

// Obter dados da requisição
$codigo_cliente = isset($_POST['codigo_cliente']) ? intval($_POST['codigo_cliente']) : 0;
$acao = isset($_POST['acao']) ? $_POST['acao'] : '';

if ($acao !== 'finalizar_simples') {
    echo json_encode([
        'success' => false,
        'error' => 'Ação inválida'
    ]);
    exit;
}

if ($codigo_cliente <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Código do cliente inválido'
    ]);
    exit;
}

try {
    // Apenas o UPDATE simples - sem transação, sem logs
    $situacao_final = 'F'; // Altere para 'A' se quiser reabrir
    
    $sql = "UPDATE lcred_neocredit SET situ = ? WHERE codigo = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$situacao_final, $codigo_cliente]);
    
    // Verificar se alguma linha foi afetada
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Crédito finalizado com sucesso!',
            'codigo' => $codigo_cliente,
            'situacao_nova' => $situacao_final,
            'data' => date('d/m/Y H:i:s')
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Nenhum registro foi atualizado. Verifique se o código existe.'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao executar a atualização: ' . $e->getMessage()
    ]);
}