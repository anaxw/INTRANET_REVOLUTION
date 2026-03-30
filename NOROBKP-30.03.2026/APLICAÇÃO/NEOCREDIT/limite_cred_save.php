<?php
// limite_cred_save.php

// Configurar fuso horário para Brasília
date_default_timezone_set('America/Sao_Paulo');

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
    
    // Configurar fuso horário da conexão para Brasília
    $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");
    
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
    $score = !empty($_POST['score']) ? trim($_POST['score']) : null;
    
    // Função para processar código da unidade (remove ".000000" e converte para inteiro)
    function processarCodigoUnidade($valor) {
        if (empty($valor) || $valor === 'NÃO CADASTRADO' || $valor === 'ERRO') {
            return null;
        }
        
        // Remover "N/A" ou strings inválidas
        if (strtoupper($valor) === 'N/A') {
            return null;
        }
        
        // Se já for um número inteiro, retornar como inteiro
        if (is_numeric($valor) && floor($valor) == $valor) {
            return (int)$valor;
        }
        
        // Remover ".000000" do final
        $valor_limpo = preg_replace('/\.0+$/', '', $valor);
        
        // Se houver ponto decimal, pegar apenas a parte inteira
        if (strpos($valor_limpo, '.') !== false) {
            $partes = explode('.', $valor_limpo);
            $valor_limpo = $partes[0];
        }
        
        // Converter para inteiro se for numérico
        if (is_numeric($valor_limpo)) {
            return (int)$valor_limpo;
        }
        
        // Se não for numérico, tentar extrair números
        $numeros = preg_replace('/[^0-9]/', '', $valor_limpo);
        if (!empty($numeros) && is_numeric($numeros)) {
            return (int)$numeros;
        }
        
        return null;
    }
    
    // Processar códigos por unidade
    $codic_bm = isset($_POST['codic_bm']) ? processarCodigoUnidade($_POST['codic_bm']) : null;
    $codic_bot = isset($_POST['codic_bot']) ? processarCodigoUnidade($_POST['codic_bot']) : null;
    $codic_ls = isset($_POST['codic_ls']) ? processarCodigoUnidade($_POST['codic_ls']) : null;
    $codic_rp = isset($_POST['codic_rp']) ? processarCodigoUnidade($_POST['codic_rp']) : null;
    $codic_vt_rnd = isset($_POST['codic_vt_rnd']) ? processarCodigoUnidade($_POST['codic_vt_rnd']) : null;
    
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
    
    // CAPTURAR O STATUS DA CHAVE 'campos'
    $status = !empty($_POST['status']) ? trim($_POST['status']) : 'CONSULTADO';
    
    // Obter data/hora atual de Brasília
    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $data_consulta = $agora->format('Y-m-d H:i:s');
    
    // Log dos dados recebidos - DESTAQUE PARA O STATUS
    error_log("=== TENTATIVA DE SALVAR DADOS ===");
    error_log("Data/Hora Brasília: " . $data_consulta);
    error_log("Documento: " . $documento);
    error_log("Razão Social: " . $razao_social);
    error_log("STATUS DOS CAMPOS: " . $status); // ← LOG ESPECÍFICO
    error_log("Limite Aprovado: " . $lcred_aprovado);
    error_log("Validade: " . $validade_cred);
    error_log("Score: " . $score);
    error_log("ID Neocredit: " . $id_neocredit);

    // Verificar se o documento já existe no banco
    $check_sql = "SELECT codigo, situ FROM lcred_neocredit WHERE documento = :documento ORDER BY codigo DESC";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([':documento' => $documento]);
    $registros_existentes = $check_stmt->fetchAll();
    
    $registro_existente = null;
    if (!empty($registros_existentes)) {
        $registro_existente = $registros_existentes[0];
    }

    // Lógica de validação
    if ($registro_existente) {
        if ($registro_existente['situ'] === 'A') {
            throw new Exception("❌ Já existe um registro em ABERTO para este documento. Finalize o registro atual antes de criar um novo.");
        } elseif ($registro_existente['situ'] === 'F') {
            $situ = 'A';
            $sql = "INSERT INTO lcred_neocredit 
                    (id_neocredit, razao_social, documento, risco, classificacao_risco, 
                     score, situ, lcred_aprovado, validade_cred, status,
                     codic_bm, codic_bot, codic_ls, codic_rp, codic_vt_rnd,
                     data_consulta)
                    VALUES 
                    (:id_neocredit, :razao_social, :documento, :risco, :classificacao_risco, 
                     :score, :situ, :lcred_aprovado, :validade_cred, :status,
                     :codic_bm, :codic_bot, :codic_ls, :codic_rp, :codic_vt_rnd,
                     :data_consulta)";
            
            $message = "✅ Novo registro criado com sucesso! (Registro anterior estava finalizado)";
            $action = 'inserted';
            $codigo_registro = null;
        }
    } else {
        $situ = 'A';
        $sql = "INSERT INTO lcred_neocredit 
                (id_neocredit, razao_social, documento, risco, classificacao_risco, 
                 score, situ, lcred_aprovado, validade_cred, status,
                 codic_bm, codic_bot, codic_ls, codic_rp, codic_vt_rnd,
                 data_consulta)
                VALUES 
                (:id_neocredit, :razao_social, :documento, :risco, :classificacao_risco, 
                 :score, :situ, :lcred_aprovado, :validade_cred, :status,
                 :codic_bm, :codic_bot, :codic_ls, :codic_rp, :codic_vt_rnd,
                 :data_consulta)";
        
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
        ':score' => $score,
        ':situ' => $situ,
        ':lcred_aprovado' => $lcred_aprovado,
        ':validade_cred' => $validade_cred,
        ':status' => $status, // ← AGORA É O STATUS DOS CAMPOS
        ':codic_bm' => $codic_bm,
        ':codic_bot' => $codic_bot,
        ':codic_ls' => $codic_ls,
        ':codic_rp' => $codic_rp,
        ':codic_vt_rnd' => $codic_vt_rnd,
        ':data_consulta' => $data_consulta
    ];

    error_log("SQL a ser executado: " . $sql);
    error_log("Parâmetros: " . print_r($params, true));
    error_log("STATUS SENDO SALVO: " . $status); // Log final do status

    if ($stmt->execute($params)) {
        if ($action === 'inserted') {
            $codigo_registro = $pdo->lastInsertId('lcred_neocredit_codigo_seq');
        }
        
        error_log("✅ Dados salvos com sucesso! Código: " . $codigo_registro);
        error_log("✅ STATUS SALVO: " . $status); // Confirmação do status salvo
        
        $response['success'] = true;
        $response['message'] = $message;
        $response['action'] = $action;
        $response['codigo'] = $codigo_registro;
        $response['documento'] = $documento;
        $response['razao_social'] = $razao_social;
        $response['status_salvo'] = $status; // ← Retorna o status salvo
        $response['lcred_aprovado'] = $lcred_aprovado;
        $response['score'] = $score;
        $response['situ'] = $situ;
        $response['data_consulta'] = $data_consulta;
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