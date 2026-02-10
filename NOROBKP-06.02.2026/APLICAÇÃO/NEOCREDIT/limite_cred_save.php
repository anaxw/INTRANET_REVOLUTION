<?php
// limite_cred_save.php

// Configurações de segurança
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
header('Content-Type: application/json; charset=utf-8');

// Conexão com o banco de dados
try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    error_log("Erro de conexão com o banco: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erro de conexão com o banco de dados'
    ]);
    exit;
}

$response = ['success' => false, 'message' => ''];

try {
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método não permitido");
    }

    // Verificar se a ação é salvar no banco
    if (!isset($_POST['action']) || $_POST['action'] !== 'save_to_db') {
        throw new Exception("Ação inválida");
    }

    // Validar dados obrigatórios
    if (empty($_POST['documento'])) {
        throw new Exception("Documento é obrigatório");
    }
    
    if (empty($_POST['razao_social'])) {
        throw new Exception("Razão social é obrigatória");
    }

    // Preparar dados para inserção/atualização
    $id_neocredit = !empty($_POST['id_analise']) ? (int)$_POST['id_analise'] : null;
    $razao_social = trim($_POST['razao_social']);
    $documento = preg_replace('/[^0-9]/', '', $_POST['documento']);
    $risco = !empty($_POST['risco']) ? trim($_POST['risco']) : null;
    $classificacao_risco = !empty($_POST['classificacao_risco']) ? trim($_POST['classificacao_risco']) : null;
    $score = !empty($_POST['score']) ? trim($_POST['score']) : null; // NOVO: campo score
    
    // Processar valor do limite aprovado
    $lcred_aprovado = 0;
    if (!empty($_POST['lcred_aprovado'])) {
        $valor = $_POST['lcred_aprovado'];
        // Remover formatação
        $valor_limpo = preg_replace('/[^\d,\.]/', '', $valor);
        // Substituir vírgula por ponto para formato decimal
        $valor_limpo = str_replace(',', '.', $valor_limpo);
        $lcred_aprovado = (float)$valor_limpo;
    }
    
    // Processar data de validade
    $validade_cred = null;
    if (!empty($_POST['validade_cred'])) {
        $data_input = trim($_POST['validade_cred']);
        
        // Tentar diferentes formatos de data
        $formatos = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'];
        
        foreach ($formatos as $formato) {
            $date = DateTime::createFromFormat($formato, $data_input);
            if ($date && $date->format($formato) === $data_input) {
                $validade_cred = $date->format('Y-m-d');
                break;
            }
        }
        
        // Se não conseguiu parsear, tenta usar strtotime
        if (!$validade_cred && strtotime($data_input)) {
            $validade_cred = date('Y-m-d', strtotime($data_input));
        }
    }
    
    $status = !empty($_POST['status']) ? trim($_POST['status']) : 'CONSULTADO';
    
    // Log dos dados recebidos
    error_log("=== TENTATIVA DE SALVAR DADOS ===");
    error_log("Documento: " . $documento);
    error_log("Razão Social: " . $razao_social);
    error_log("Limite Aprovado: " . $lcred_aprovado);
    error_log("Validade: " . $validade_cred);
    error_log("Status: " . $status);
    error_log("Score: " . $score); // NOVO: log do score
    error_log("ID Neocredit: " . $id_neocredit);

    // Verificar se o documento já existe no banco
    $check_sql = "SELECT codigo FROM lcred_neocredit WHERE documento = :documento";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([':documento' => $documento]);
    $registro_existente = $check_stmt->fetch();

    if ($registro_existente) {
        // Atualizar registro existente
        $sql = "UPDATE lcred_neocredit SET 
                id_neocredit = :id_neocredit,
                razao_social = :razao_social,
                risco = :risco,
                classificacao_risco = :classificacao_risco,
                score = :score, -- NOVO: incluir score
                lcred_aprovado = :lcred_aprovado,
                validade_cred = :validade_cred,
                status = :status
                WHERE documento = :documento";
        
        $message = "✅ Dados atualizados com sucesso!";
        $action = 'updated';
        $codigo_registro = $registro_existente['codigo'];
    } else {
        // Inserir novo registro
        $sql = "INSERT INTO lcred_neocredit 
                (id_neocredit, razao_social, documento, risco, classificacao_risco, 
                 score, lcred_aprovado, validade_cred, status) -- NOVO: incluir score
                VALUES 
                (:id_neocredit, :razao_social, :documento, :risco, :classificacao_risco, 
                 :score, :lcred_aprovado, :validade_cred, :status)"; -- NOVO: incluir score
        
        $message = "✅ Dados salvos com sucesso!";
        $action = 'inserted';
        $codigo_registro = null;
    }

    // Preparar e executar a query
    $stmt = $pdo->prepare($sql);
    
    $params = [
        ':id_neocredit' => $id_neocredit,
        ':razao_social' => $razao_social,
        ':documento' => $documento,
        ':risco' => $risco,
        ':classificacao_risco' => $classificacao_risco,
        ':score' => $score, // NOVO: parâmetro score
        ':lcred_aprovado' => $lcred_aprovado,
        ':validade_cred' => $validade_cred,
        ':status' => $status
    ];

    error_log("SQL a ser executado: " . $sql);
    error_log("Parâmetros: " . print_r($params, true));

    if ($stmt->execute($params)) {
        // Se foi uma inserção, obter o código gerado
        if ($action === 'inserted') {
            $codigo_registro = $pdo->lastInsertId('lcred_neocredit_codigo_seq');
        }
        
        error_log("✅ Dados salvos com sucesso! Código: " . $codigo_registro);
        
        $response['success'] = true;
        $response['message'] = $message;
        $response['action'] = $action;
        $response['codigo'] = $codigo_registro;
        $response['documento'] = $documento;
        $response['razao_social'] = $razao_social;
        $response['lcred_aprovado'] = $lcred_aprovado;
        $response['score'] = $score; // NOVO: retornar score
        $response['timestamp'] = date('Y-m-d H:i:s');
        
    } else {
        $errorInfo = $stmt->errorInfo();
        error_log("❌ Erro ao executar SQL: " . print_r($errorInfo, true));
        throw new Exception("Erro no banco de dados: " . $errorInfo[2]);
    }

} catch (Exception $e) {
    error_log("❌ Erro ao salvar dados: " . $e->getMessage());
    $response['success'] = false;
    $response['message'] = "❌ Erro: " . $e->getMessage();
}

error_log("=== FIM DO PROCESSAMENTO ===");
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>