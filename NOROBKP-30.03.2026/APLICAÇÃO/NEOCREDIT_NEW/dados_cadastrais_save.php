<?php
// limite_cred_save.php - SALVAR DADOS NO BANCO DE DADOS
// =============================================
// Recebe os dados da consulta e insere na tabela neocredit.dados_cadastrais
// =============================================

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

// =============================================
// CONFIGURAÇÕES DO BANCO DE DADOS - USANDO SEU PDO
// =============================================
define('DB_HOST', '192.168.1.209');
define('DB_PORT', '5432');
define('DB_NAME', 'Intranet');
define('DB_USER', 'postgres');
define('DB_PASS', 'postgres');

// =============================================
// FUNÇÃO PARA OBTER DATA/HORA BRASÍLIA
// =============================================
function getDataHoraBrasilia() {
    // Criar timezone de Brasília (UTC-3)
    $brasilia = new DateTimeZone('America/Sao_Paulo');
    $dataHora = new DateTime('now', $brasilia);
    return $dataHora->format('Y-m-d H:i:s');
}

// =============================================
// FUNÇÃO PARA LIMPAR VALORES
// =============================================
function limparValor($valor, $tipo = 'string') {
    // Remove espaços extras e converte null para string vazia
    $valor = ($valor === null || $valor === 'null' || $valor === 'N/A') ? '' : trim($valor);
    
    if ($tipo === 'int') {
        return is_numeric($valor) ? (int)$valor : 0;
    } elseif ($tipo === 'bigint') {
        // Remove caracteres não numéricos para campos BIGINT
        $valor = preg_replace('/[^0-9]/', '', $valor);
        return !empty($valor) ? $valor : 0;
    } elseif ($tipo === 'decimal') {
        // Converte formato brasileiro para decimal do PostgreSQL
        $valor = str_replace(['.', ','], ['', '.'], $valor);
        return is_numeric($valor) ? (float)$valor : 0.00;
    }
    
    // Para strings, remove caracteres especiais se necessário
    return $valor;
}

// =============================================
// FUNÇÃO PARA CONECTAR AO BANCO COM PDO
// =============================================
function conectarBanco() {
    try {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Erro ao conectar ao banco de dados: " . $e->getMessage());
    }
}

// =============================================
// FUNÇÃO PARA VERIFICAR SE REGISTRO JÁ EXISTE
// =============================================
function registroExiste($pdo, $id_neocredit) {
    if (empty($id_neocredit)) {
        return false;
    }
    
    try {
        $sql = "SELECT codigo FROM neocredit.dados_cadastrais WHERE id_neocredit = :id_neocredit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_neocredit' => $id_neocredit]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Erro ao verificar existência: " . $e->getMessage());
        return false;
    }
}

// =============================================
// FUNÇÃO PARA INSERIR DADOS COM PDO
// =============================================
function inserirDadosCadastrais($pdo, $dados) {
    $sql = "INSERT INTO neocredit.dados_cadastrais (
        id_neocredit,
        razao,
        doc,
        endereco,
        num_end,
        bairro,
        complemento,
        cidade,
        cep,
        email,
        ddd,
        telefone,
        score,
        situ,
        dt_hr_consulta
    ) VALUES (
        :id_neocredit,
        :razao,
        :doc,
        :endereco,
        :num_end,
        :bairro,
        :complemento,
        :cidade,
        :cep,
        :email,
        :ddd,
        :telefone,
        :score,
        :situ,
        :dt_hr_consulta
    )";
    
    // Extrair DDD e telefone do campo telefone completo
    $ddd = 0;
    $telefone = 0;
    $telefone_completo = $dados['telefone'] ?? '';
    
    if (!empty($telefone_completo) && $telefone_completo !== 'N/A' && $telefone_completo !== '') {
        // Formato esperado: (17) 99269-2275 ou (17) 3221-1234
        if (preg_match('/\((\d{2})\)\s*(\d{4,5}[-\s]?\d{4})/', $telefone_completo, $matches)) {
            $ddd = (int)$matches[1];
            $numero = preg_replace('/[^0-9]/', '', $matches[2]);
            $telefone = (int)$numero;
        } else {
            // Tenta extrair apenas números se não estiver no formato esperado
            $numeros = preg_replace('/[^0-9]/', '', $telefone_completo);
            if (strlen($numeros) >= 10) {
                $ddd = (int)substr($numeros, 0, 2);
                $telefone = (int)substr($numeros, 2);
            }
        }
    }
    
    // Extrair apenas número do endereço
    $num_end = 0;
    if (!empty($dados['numero']) && $dados['numero'] !== 'N/A' && $dados['numero'] !== '') {
        $num_end = (int)preg_replace('/[^0-9]/', '', $dados['numero']);
    }
    
    // Extrair apenas números do CEP
    $cep = 0;
    if (!empty($dados['cep']) && $dados['cep'] !== 'N/A' && $dados['cep'] !== '') {
        $cep = (int)preg_replace('/[^0-9]/', '', $dados['cep']);
    }
    
    // Formatar cidade com UF se disponível
    $cidade = $dados['cidade'] ?? '';
    if (!empty($dados['uf']) && $dados['uf'] !== 'N/A' && !empty($cidade) && $cidade !== 'N/A') {
        $cidade = $cidade . '/' . $dados['uf'];
    }
    
    // Extrair CPF/CNPJ apenas números
    $doc = 0;
    if (!empty($dados['cpf']) && $dados['cpf'] !== 'N/A' && $dados['cpf'] !== '') {
        $doc = (int)preg_replace('/[^0-9]/', '', $dados['cpf']);
    }
    
    // Score como decimal
    $score = 0.00;
    if (!empty($dados['score']) && $dados['score'] !== 'N/A' && $dados['score'] !== '') {
        $score = floatval(str_replace(',', '.', $dados['score']));
    }
    
    // Se não tem score, tenta usar risco ou classificacao_risco
    if ($score == 0 && !empty($dados['risco']) && $dados['risco'] !== 'N/A') {
        $score = floatval(str_replace(',', '.', $dados['risco']));
    }
    
    // DATA/HORA BRASÍLIA
    $dataHoraBrasilia = getDataHoraBrasilia();
    
    $params = [
        ':id_neocredit' => limparValor($dados['id_analise'] ?? ''),
        ':razao' => limparValor($dados['razao_social'] ?? ''),
        ':doc' => $doc,
        ':endereco' => limparValor($dados['endereco'] ?? ''),
        ':num_end' => $num_end,
        ':bairro' => limparValor($dados['bairro'] ?? ''),
        ':complemento' => limparValor($dados['complemento'] ?? ''),
        ':cidade' => limparValor($cidade),
        ':cep' => $cep,
        ':email' => limparValor($dados['email'] ?? ''),
        ':ddd' => $ddd,
        ':telefone' => $telefone,
        ':score' => $score,
        ':situ' => 'A', // situ sempre como 'A'
        ':dt_hr_consulta' => $dataHoraBrasilia // Data/hora Brasília
    ];
    
    error_log("Parâmetros para inserção: " . print_r($params, true));
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Erro PDO na inserção: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        throw new Exception("Erro ao inserir dados: " . $e->getMessage());
    }
}

// =============================================
// FUNÇÃO PARA OBTER O ÚLTIMO ID INSERIDO
// =============================================
function getUltimoCodigo($pdo) {
    try {
        $stmt = $pdo->query("SELECT lastval()");
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
}

// =============================================
// FUNÇÃO PRINCIPAL
// =============================================
try {
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    // Verificar action
    if (!isset($_POST['action']) || $_POST['action'] !== 'save_to_db') {
        throw new Exception('Ação inválida');
    }
    
    error_log("=== INICIANDO SALVAMENTO NO BANCO ===");
    error_log("POST data: " . print_r($_POST, true));
    
    // Validar dados mínimos necessários
    if (empty($_POST['id_analise'])) {
        throw new Exception('ID da análise é obrigatório');
    }
    
    // Conectar ao banco com PDO
    $pdo = conectarBanco();
    
    // Verificar se registro já existe
    if (registroExiste($pdo, $_POST['id_analise'])) {
        throw new Exception('Registro com ID ' . $_POST['id_analise'] . ' já existe no banco de dados');
    }
    
    // Preparar array de dados
    $dados = [
        'id_analise' => $_POST['id_analise'] ?? '',
        'razao_social' => $_POST['razao_social'] ?? '',
        'cpf' => $_POST['cpf'] ?? '',
        'endereco' => $_POST['endereco'] ?? '',
        'numero' => $_POST['numero'] ?? '',
        'bairro' => $_POST['bairro'] ?? '',
        'complemento' => $_POST['complemento'] ?? '',
        'cidade' => $_POST['cidade'] ?? '',
        'uf' => $_POST['uf'] ?? '',
        'cep' => $_POST['cep'] ?? '',
        'email' => $_POST['email'] ?? '',
        'telefone' => $_POST['telefone'] ?? '',
        'score' => $_POST['score'] ?? '',
        'risco' => $_POST['risco'] ?? '',
        'classificacao_risco' => $_POST['classificacao_risco'] ?? '',
        'limite_aprovado' => $_POST['lcred_aprovado'] ?? '0',
        'data_validade' => $_POST['validade_cred'] ?? '',
        'status' => $_POST['status'] ?? 'CONSULTADO'
    ];
    
    // Iniciar transação
    $pdo->beginTransaction();
    
    try {
        // Inserir dados
        $linhas_afetadas = inserirDadosCadastrais($pdo, $dados);
        
        if ($linhas_afetadas > 0) {
            // Obter o código gerado (opcional)
            $codigo_gerado = getUltimoCodigo($pdo);
            
            // Commit da transação
            $pdo->commit();
            
            $response = [
                'success' => true,
                'message' => 'Dados salvos com sucesso!',
                'id_analise' => $dados['id_analise'],
                'codigo_gerado' => $codigo_gerado,
                'data_hora_brasilia' => getDataHoraBrasilia(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } else {
            throw new Exception('Nenhuma linha foi inserida');
        }
    } catch (Exception $e) {
        // Rollback em caso de erro
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("ERRO AO SALVAR: " . $e->getMessage());
    
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Retornar resposta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>