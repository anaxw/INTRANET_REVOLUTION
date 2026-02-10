<?php
// metas_insert.php - Processa apenas o INSERT via AJAX

// Configurar headers primeiro
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
mb_internal_encoding('UTF-8');

// Função para converter número brasileiro para float (com vírgula)
// Versão PHP 8 mais segura:
function converterNumeroBrasileiroParaFloat($valor)
{
    if (empty($valor) || $valor === '' || $valor === null) {
        return 0.0;
    }

    // Se já for número (float ou int), retornar
    if (is_numeric($valor) && !is_string($valor)) {
        return (float) $valor;
    }

    $valor = trim((string) $valor);

    // Verificar se já está no formato correto (com ponto)
    if (is_numeric($valor)) {
        return (float) $valor;
    }

    // Remover caracteres não numéricos, exceto vírgula, ponto e sinal
    $valor = preg_replace('/[^\d,\-\.]/', '', $valor);

    // Verificar se tem vírgula e ponto
    $temVirgula = strpos($valor, ',') !== false;
    $temPonto = strpos($valor, '.') !== false;

    if ($temVirgula && $temPonto) {
        // Se vírgula está depois do ponto: 1.234,56 -> ponto é milhar
        if (strrpos($valor, ',') > strrpos($valor, '.')) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            // Vírgula antes do ponto: provavelmente vírgula é milhar
            $valor = str_replace(',', '', $valor);
        }
    } elseif ($temVirgula && !$temPonto) {
        // Apenas vírgula
        $partes = explode(',', $valor);
        if (count($partes) === 2 && strlen($partes[1]) <= 2) {
            // É separador decimal (ex: 1234,56)
            $valor = str_replace(',', '.', $valor);
        } else {
            // Pode ser milhar (ex: 1,234 -> 1234)
            $valor = str_replace(',', '', $valor);
        }
    }

    // Remover múltiplos pontos (caso tenha sobrado)
    if (substr_count($valor, '.') > 1) {
        $pos = strrpos($valor, '.'); // mantém o último ponto
        $parteInteira = str_replace('.', '', substr($valor, 0, $pos));
        $valor = $parteInteira . substr($valor, $pos);
    }

    return (float) $valor;
}

// Inicializar resposta
$resposta = [
    'sucesso' => false,
    'mensagem' => '',
    'dados' => []
];

try {
    // Conexão com o banco
    $dsn = 'firebird:dbname=192.168.1.209:c:/BD/OPERADOR.FDB;charset=UTF8';
    $user = 'SYSDBA';
    $pass = 'masterkey';

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
        PDO::ATTR_PERSISTENT => false
    ]);

    // ====================================================================
    // VERIFICAÇÃO DE DUPLICIDADE EM TEMPO REAL
    // ====================================================================
    if (isset($_POST['verificar_duplicidade']) && $_POST['verificar_duplicidade'] === 'true') {
        if (isset($_POST['data'], $_POST['operador'])) {
            $dataVerificar = trim($_POST['data']);
            $operadorVerificar = trim($_POST['operador']);

            if (empty($dataVerificar) || empty($operadorVerificar)) {
                echo json_encode(['existe' => false], JSON_UNESCAPED_UNICODE);
                exit();
            }

            // Converter data para formato do Firebird
            $dataFormatadaVerificar = date('Y-m-d', strtotime($dataVerificar));

            // Verificar se já existe registro
            $sqlVerificar = "SELECT COUNT(*) as total 
                            FROM ROB8_OPERADOR_MAQUINA 
                            WHERE DESCRICAO = ? 
                              AND DATA = ?";

            $stmtVerificar = $pdo->prepare($sqlVerificar);
            $stmtVerificar->execute([$operadorVerificar, $dataFormatadaVerificar]);
            $resultadoVerificar = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

            $existe = ($resultadoVerificar && $resultadoVerificar['TOTAL'] > 0);

            echo json_encode(['existe' => $existe], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    // ====================================================================
    // PROCESSAMENTO DO INSERT
    // ====================================================================

    // Verificar se é POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $resposta['mensagem'] = 'Método não permitido. Use POST.';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Validar campos obrigatórios
    $campos_obrigatorios = ['data', 'tipo', 'operador', 'meta'];
    $erros = [];

    foreach ($campos_obrigatorios as $campo) {
        if (!isset($_POST[$campo]) || trim($_POST[$campo]) === '') {
            $erros[] = $campo;
        }
    }

    if (!empty($erros)) {
        $resposta['mensagem'] = 'Campos obrigatórios faltando: ' . implode(', ', $erros);
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Preparar dados
    $data = trim($_POST['data']);
    $tipo = trim($_POST['tipo']);
    $operador = trim($_POST['operador']);

    // Converter data para formato do Firebird
    $dataFormatada = date('Y-m-d', strtotime($data));

    // ====================================================================
    // VERIFICAR SE JÁ EXISTE REGISTRO PARA ESTE OPERADOR NA MESMA DATA
    // ====================================================================
    $sqlVerificar = "SELECT COUNT(*) as total, 
                            MAX(seq) as ultimo_id,
                            MAX(meta) as ultima_meta,
                            MAX(produtividade) as ultima_produtividade
                     FROM ROB8_OPERADOR_MAQUINA 
                     WHERE DESCRICAO = ? 
                       AND DATA = ?";

    $stmtVerificar = $pdo->prepare($sqlVerificar);
    $stmtVerificar->execute([$operador, $dataFormatada]);
    $resultadoVerificar = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

    if ($resultadoVerificar && $resultadoVerificar['TOTAL'] > 0) {
        $dataFormatadaBR = date('d/m/Y', strtotime($dataFormatada));

        // Montar mensagem detalhada
        $mensagem = "Operador '{$operador}' já possui um registro na data {$dataFormatadaBR}. ";
        $mensagem .= "Não é permitido cadastrar o mesmo operador no mesmo dia. ";

        if ($resultadoVerificar['ULTIMA_META']) {
            $mensagem .= "Última meta registrada: " . number_format($resultadoVerificar['ULTIMA_META'], 2, ',', '.') . ". ";
        }

        $resposta['mensagem'] = $mensagem;
        $resposta['dados']['duplicado'] = true;
        $resposta['dados']['operador'] = $operador;
        $resposta['dados']['data'] = $dataFormatadaBR;
        $resposta['dados']['id_existente'] = $resultadoVerificar['ULTIMO_ID'];

        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // CONVERTER VALORES NUMÉRICOS USANDO A FUNÇÃO COM VÍRGULA
    $meta = isset($_POST['meta']) ? converterNumeroBrasileiroParaFloat($_POST['meta']) : 0.0;
    $produtividade = isset($_POST['produtividade']) && $_POST['produtividade'] !== '' ?
        converterNumeroBrasileiroParaFloat($_POST['produtividade']) : 0.0;
    $horas = isset($_POST['horas']) && $_POST['horas'] !== '' ?
        converterNumeroBrasileiroParaFloat($_POST['horas']) : 0.0;

    $observacao = isset($_POST['observacao']) ? trim($_POST['observacao']) : '';

    // Debug: Log dos valores recebidos
    error_log("Valores recebidos - Meta: {$_POST['meta']} -> {$meta}");
    error_log("Valores recebidos - Produtividade: {$_POST['produtividade']} -> {$produtividade}");
    error_log("Valores recebidos - Horas: {$_POST['horas']} -> {$horas}");

    // Validar valores
    if ($meta < 0) {
        $resposta['mensagem'] = 'Meta não pode ser negativa';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($produtividade < 0) {
        $resposta['mensagem'] = 'Produtividade não pode ser negativa';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($horas < 0) {
        $resposta['mensagem'] = 'Horas não podem ser negativas';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // SQL de INSERT
    $sql = "INSERT INTO ROB8_OPERADOR_MAQUINA 
            (DATA, TIPO, DESCRICAO, META, PRODUTIVIDADE, HORAS_TRABALHADAS, OBSERVACAO)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    // Preparar e executar
    $stmt = $pdo->prepare($sql);
    $resultado = $stmt->execute([
        $dataFormatada,
        $tipo,
        $operador,
        $meta,
        $produtividade,
        $horas,
        $observacao
    ]);

    if ($resultado) {
        $resposta['sucesso'] = true;
        $resposta['mensagem'] = 'Registro inserido com sucesso!';

        // Obter o ID do registro inserido (se possível)
        try {
            $sqlId = "SELECT MAX(seq) as ultimo_id FROM ROB8_OPERADOR_MAQUINA";
            $stmtId = $pdo->query($sqlId);
            $ultimoId = $stmtId->fetch(PDO::FETCH_ASSOC);

            if ($ultimoId && isset($ultimoId['ULTIMO_ID'])) {
                $resposta['dados']['id'] = $ultimoId['ULTIMO_ID'];
            }
        } catch (Exception $e) {
            // Ignorar erro na obtenção do ID
        }
    } else {
        $resposta['mensagem'] = 'Erro ao inserir registro no banco de dados';
    }
} catch (PDOException $e) {
    // Verificar se é erro de duplicidade (caso a verificação anterior tenha falhado)
    if (
        strpos($e->getMessage(), 'duplicate') !== false ||
        strpos($e->getMessage(), 'unique constraint') !== false
    ) {

        $resposta['mensagem'] = 'Erro: Registro duplicado. Este operador já possui dados nesta data.';
        $resposta['dados']['duplicado'] = true;
    } else {
        $resposta['mensagem'] = 'Erro no banco de dados: ' . $e->getMessage();
    }

    error_log('Erro PDO em metas_insert.php: ' . $e->getMessage());
} catch (Exception $e) {
    $resposta['mensagem'] = 'Erro: ' . $e->getMessage();
    error_log('Erro em metas_insert.php: ' . $e->getMessage());
}

// Limpar qualquer buffer de saída
while (ob_get_level()) {
    ob_end_clean();
}

// Retornar JSON
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
exit();
