<?php
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Conexão com o banco de dados
$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => ''];

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Agora apenas placa, motorista e empresa são obrigatórios
    $required_fields = ['placa', 'motorista', 'empresa'];

    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $response['message'] = 'Por favor, preencha a placa, motorista e empresa';
            echo json_encode($response);
            exit;
        }
    }

    // Coletar e sanitizar dados
    $placa = trim($_POST['placa']);
    $motorista = trim($_POST['motorista']);
    
    // PEGAR VALOR DA EMPRESA DO FORMULÁRIO
    $empresa = isset($_POST['empresa']) ? (int)$_POST['empresa'] : null;

    // Se estiver vazio, inserir como string vazia
    $nome_fornecedor = isset($_POST['nome_fornecedor']) && trim($_POST['nome_fornecedor']) !== '' 
                        ? trim($_POST['nome_fornecedor']) 
                        : '';

    $numero_nf = isset($_POST['numero_nf']) && trim($_POST['numero_nf']) !== '' 
                    ? (int)$_POST['numero_nf'] 
                    : 0;

    // ACEITAR PESO 0 SEM VALIDAÇÃO
    $peso_inicial = isset($_POST['peso_inicial']) ? (float)$_POST['peso_inicial'] : 0.000;
    
    $obs = isset($_POST['obs']) ? trim($_POST['obs']) : '';

    $status = 'A';

    // Valores padrão
    $peso_final = 0;
    $peso_liquido = 0;
    $data_hora_final = null;

    // VALIDAR SE EMPRESA É VÁLIDA
    if ($empresa === null || $empresa <= 0) {
        $response['message'] = 'Empresa inválida ou não especificada';
        echo json_encode($response);
        exit;
    }

    try {

        $sql = "INSERT INTO pesagem 
        (placa, motorista, nome_fornecedor, numero_nf, peso_inicial, obs, 
         data_hora_inicial, status, peso_final, peso_liquido, data_hora_final, empresa) 
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $placa,
            $motorista,
            $nome_fornecedor,
            $numero_nf,
            $peso_inicial,
            $obs,
            $status,
            $peso_final,
            $peso_liquido,
            $data_hora_final,
            $empresa
        ]);

        $response['success'] = true;
        $response['message'] = 'Pesagem salva com sucesso';
        $response['empresa'] = $empresa; // Para debug

    } catch (PDOException $e) {
        $response['message'] = 'Erro ao salvar: ' . $e->getMessage();
        error_log("Erro na inserção: " . $e->getMessage());
    }

} else {
    $response['message'] = 'Método não permitido';
}

echo json_encode($response);
?>