<?php
// metas_delete.php - Processa exclusão de registros via AJAX
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Habilitar CORS para requisições AJAX
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Use POST.'
    ]);
    exit;
}

try {
    // Validar se o ID foi enviado
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'ID do registro não informado ou inválido.'
        ]);
        exit;
    }
    
    $id = intval($_POST['id']);
    
    if ($id <= 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'ID inválido.'
        ]);
        exit;
    }
    
    // Conectar ao banco OPERADOR
    $dsn = 'firebird:dbname=192.168.1.209:c:/BD/OPERADOR.FDB;charset=UTF8';
    $user = 'SYSDBA';
    $pass = 'masterkey';
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Primeiro, buscar informações do registro para retornar na resposta
    $sqlBuscar = "SELECT seq, data, descricao, meta 
                  FROM rob8_operador_maquina 
                  WHERE seq = ?";
    
    $stmtBuscar = $pdo->prepare($sqlBuscar);
    $stmtBuscar->execute([$id]);
    $registro = $stmtBuscar->fetch();
    
    if (!$registro) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Registro não encontrado.'
        ]);
        exit;
    }
    
    // Converter para UTF-8
    function converterParaUTF8($string) {
        if (!is_string($string)) return $string;
        
        $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding != 'UTF-8') {
            return mb_convert_encoding($string, 'UTF-8', $encoding);
        }
        return $string;
    }
    
    $registro['DESCRICAO'] = converterParaUTF8($registro['DESCRICAO']);
    
    // Agora excluir o registro
    $sqlExcluir = "DELETE FROM rob8_operador_maquina WHERE seq = ?";
    $stmtExcluir = $pdo->prepare($sqlExcluir);
    $stmtExcluir->execute([$id]);
    
    $linhasAfetadas = $stmtExcluir->rowCount();
    
    if ($linhasAfetadas > 0) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Registro excluído com sucesso!',
            'dados' => [
                'id' => $id,
                'operador' => $registro['DESCRICAO'],
                'data' => $registro['DATA'],
                'meta' => $registro['META']
            ]
        ]);
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Nenhum registro foi excluído. Talvez já tenha sido removido.'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Erro PDO em metas_delete.php: " . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro de banco de dados: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Erro geral em metas_delete.php: " . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no sistema: ' . $e->getMessage()
    ]);
}
?>