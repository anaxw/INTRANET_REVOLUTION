<?php
// metas_edit.php - Processa edições de registros
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Configuração do banco de dados
$config = [
    'OPERADOR' => [
        'dsn' => 'firebird:dbname=192.168.1.209:c:/BD/OPERADOR.FDB;charset=UTF8',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ]
];

// Função para obter conexão
function getConexao($banco = 'OPERADOR') {
    global $config;
    
    if (!isset($config[$banco])) {
        throw new Exception("Banco de dados '$banco' não configurado");
    }

    $configBanco = $config[$banco];

    try {
        $pdo = new PDO($configBanco['dsn'], $configBanco['user'], $configBanco['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Erro de conexão ($banco): " . $e->getMessage());
    }
}

// Função para converter caracteres especiais
function converterParaUTF8($string) {
    if (!is_string($string)) {
        return $string;
    }

    $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

    if ($encoding && $encoding != 'UTF-8') {
        return mb_convert_encoding($string, 'UTF-8', $encoding);
    }

    return $string;
}

// PHP 8: Adicionar verificação mais robusta
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
          (isset($_SERVER['HTTP_ACCEPT']) && 
           strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
          (isset($_SERVER['CONTENT_TYPE']) && 
           strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

if (!$isAjax && php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Acesso não autorizado'
    ]);
    exit;
}

// Processar atualização do registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar se tem ID
        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'ID do registro é obrigatório'
            ]);
            exit;
        }
        
        $id = intval($_POST['id']);
        
        // Validar campos obrigatórios
        $camposObrigatorios = ['diferenca', 'motivo_alteracao'];
        $dadosFaltando = [];
        
        foreach ($camposObrigatorios as $campo) {
            if (!isset($_POST[$campo]) || trim($_POST[$campo]) === '') {
                $dadosFaltando[] = $campo;
            }
        }
        
        if (!empty($dadosFaltando)) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Campos obrigatórios não preenchidos: ' . implode(', ', $dadosFaltando)
            ]);
            exit;
        }
        
        // Coletar e validar dados
        $diferenca = floatval(str_replace(',', '.', $_POST['diferenca']));
        $motivoAlteracao = converterParaUTF8(trim($_POST['motivo_alteracao']));
        
        // Atualizar no banco de dados APENAS os campos diferença e motivo
        $pdo = getConexao('OPERADOR');
        
        $sql = "UPDATE rob8_operador_maquina 
                SET diferenca = ?, 
                    observacao_diferenca = ?
                WHERE seq = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $diferenca,
            $motivoAlteracao,
            $id
        ]);
        
        // Verificar se atualizou alguma linha
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Registro atualizado com sucesso!'
            ]);
        } else {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Nenhum registro foi atualizado. Verifique se o ID existe.'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Erro ao atualizar registro: " . $e->getMessage());
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao atualizar registro: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Se não for POST
echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
?>