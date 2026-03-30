<?php
// limite_cred.php - VERSÃO COM DESIGN ESTILIZADO (ESTILO REFERÊNCIA)
// =============================================
// Coluna 1: Análise de Crédito (NeoCredit)
// Coluna 2: Códigos por Unidade (SIC - BARRA MANSA, BOTUCATU, LINS, RIO PRETO, VOTUPORANGA)
// =============================================

// Headers ANTI-CACHE
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Expires: 0');

// =============================================
// GERENCIAMENTO DE SESSÃO
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['last_regenerate']) || $_SESSION['last_regenerate'] < time() - 300) {
    session_regenerate_id(true);
    $_SESSION['last_regenerate'] = time();
}

$execution_id = uniqid('integrado_', true);
error_log("=============================================");
error_log("EXECUÇÃO ID: " . $execution_id);
error_log("INICIADA: " . date('Y-m-d H:i:s'));
error_log("=============================================");

// =============================================
// CONFIGURAÇÕES DAS UNIDADES FIREBIRD
// =============================================
$unidades_firebird = [
    'BARRA MANSA' => [
        'host' => '10.10.94.15',
        'path' => 'C:\SIC_BM\Arq01\ARQSIST.FDB',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ],
    'BOTUCATU' => [
        'host' => '10.10.94.15',
        'path' => 'C:\SIC_Botucatu\Arq01\ARQSIST.FDB',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ],
    'LINS' => [
        'host' => '10.10.94.15',
        'path' => 'C:\SIC_Lins\Arq01\ARQSIST.FDB',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ],
    'RIO PRETO' => [
        'host' => '10.10.94.15',
        'path' => 'C:\SIC_RP\Arq01\ARQSIST.FDB',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ],
    'VOTUPORANGA' => [
        'host' => '10.10.94.15',
        'path' => 'C:\SIC\Arq01\ARQSIST.FDB',
        'user' => 'SYSDBA',
        'pass' => 'masterkey'
    ]
];

// =============================================
// CONFIGURAÇÃO POSTGRESQL
// =============================================
try {
    $pdo_pgsql = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres",
        [PDO::ATTR_TIMEOUT => 5]
    );
    $pdo_pgsql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $pdo_pgsql = null;
    error_log("[POSTGRESQL] Erro: " . $e->getMessage());
}

// =============================================
// CLASSE PARA API NEOCREDIT
// =============================================
class ApiNeocredit
{
    private static $bearer_token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzbHVnIjoiOTg0ZmZlMTItNTM0Ni00YWVkLWEzYjgtOTQwOTA3YjNmNTRkIiwiZW1wcmVzYUlkIjoyNDYsIm5vbWUiOiJUb2tlbiBOb3JvYVx1MDBlN28ifQ._u50D_NAGRW3AawoTHJ5MLExI1QQUHc4NjxFAOMQpLs";

    private static $cache_analises = array();
    private static $cache_max_size = 50;
    private static $last_request_time = 0;

    public static function extrairDadosFormatados($dados_analise)
    {
        if (!is_array($dados_analise)) {
            return array();
        }

        $id_analise = isset($dados_analise['id']) ? $dados_analise['id'] : 'N/A';
        $status_principal = isset($dados_analise['status']) ? $dados_analise['status'] : 'N/A';

        $em_processamento = (strtoupper(trim($status_principal)) === 'PROCESSANDO');

        if (!$em_processamento) {
            $campos = isset($dados_analise['campos']) ? $dados_analise['campos'] : array();

            $limite_aprovado = 0;
            if (isset($campos['limite_aprovado']) && !empty($campos['limite_aprovado'])) {
                $limite_valor = $campos['limite_aprovado'];
                if (is_numeric($limite_valor)) {
                    $limite_aprovado = floatval($limite_valor);
                } else {
                    $limite_str = str_replace(['R$', ' ', '.'], '', $limite_valor);
                    $limite_str = str_replace(',', '.', $limite_str);
                    $limite_aprovado = floatval($limite_str);
                }
            }

            return array(
                'razao_social' => $campos['razao'] ?? $campos['razao_social'] ?? 'N/A',
                'documento' => $campos['documento'] ?? '',
                'risco' => $campos['risco'] ?? 'N/A',
                'score' => $campos['score'] ?? 'N/A',
                'classificacao_risco' => $campos['classificacao_risco'] ?? 'N/A',
                'limite_aprovado' => $limite_aprovado,
                'data_validade' => $campos['data_validade_limite_credito'] ?? $campos['data_validade'] ?? 'N/A',
                'status' => $status_principal,
                'status_principal' => $status_principal,
                'status_campos' => $campos['status'] ?? null,
                'id_analise' => $id_analise,
                'em_processamento' => false,
                'tem_dados' => true
            );
        }

        return array(
            'razao_social' => 'N/A',
            'documento' => '',
            'risco' => 'N/A',
            'score' => 'N/A',
            'classificacao_risco' => 'N/A',
            'limite_aprovado' => 0,
            'data_validade' => 'N/A',
            'status' => $status_principal,
            'status_principal' => $status_principal,
            'status_campos' => null,
            'id_analise' => $id_analise,
            'em_processamento' => true,
            'tem_dados' => false
        );
    }

    public static function analiseEstaProcessando($dados_analise)
    {
        if (!is_array($dados_analise)) {
            return true;
        }
        $status_principal = isset($dados_analise['status']) ?
            strtoupper(trim($dados_analise['status'])) : '';
        return ($status_principal === 'PROCESSANDO');
    }

    private static function adicionarCache($key, $data, $ttl = 300)
    {
        if (count(self::$cache_analises) >= self::$cache_max_size) {
            uasort(self::$cache_analises, function ($a, $b) {
                return $a['time'] <=> $b['time'];
            });
            array_shift(self::$cache_analises);
        }
        self::$cache_analises[$key] = [
            'data' => $data,
            'time' => time(),
            'ttl' => $ttl
        ];
    }

    private static function getCache($key)
    {
        if (isset(self::$cache_analises[$key])) {
            $cache = self::$cache_analises[$key];
            if ((time() - $cache['time']) < $cache['ttl']) {
                return $cache['data'];
            }
            unset(self::$cache_analises[$key]);
        }
        return null;
    }

    public static function curlDisponivel()
    {
        return function_exists('curl_init');
    }

    private static function fazerRequisicao($url, $method = 'GET', $data = null, $timeout = 20)
    {
        $now = microtime(true);
        if (($now - self::$last_request_time) < 0.5) {
            usleep(500000);
        }
        self::$last_request_time = $now;

        if (!self::curlDisponivel()) {
            throw new Exception("cURL não está disponível no servidor.");
        }

        $ch = curl_init();

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        );

        $headers = array(
            'Authorization: Bearer ' . self::$bearer_token,
            'Accept: application/json',
            'Cache-Control: no-cache'
        );

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $json_data = json_encode($data);
                $options[CURLOPT_POSTFIELDS] = $json_data;
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($json_data);
            }
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        if ($curl_errno) {
            throw new Exception("Erro cURL ({$curl_errno}): {$error}");
        }

        if ($http_code >= 400) {
            $error_msg = "HTTP {$http_code}";
            if ($response) {
                $error_data = @json_decode($response, true);
                if (isset($error_data['message'])) {
                    $error_msg .= " - " . $error_data['message'];
                }
            }
            throw new Exception($error_msg);
        }

        if (!$response) {
            throw new Exception("Resposta vazia da API");
        }

        $data = @json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Resposta JSON inválida");
        }

        return $data;
    }

    public static function iniciarAnalise($documento)
    {
        $documento_limpo = preg_replace('/[^0-9]/', '', $documento);

        if (empty($documento_limpo)) {
            throw new Exception("Documento inválido");
        }

        $cache_key = 'iniciar_' . $documento_limpo;
        $cached = self::getCache($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2722/integracao';

        try {
            $data = self::fazerRequisicao($url, 'POST', ['documento' => $documento_limpo], 30);

            if (!isset($data['id'])) {
                throw new Exception("ID da análise não retornado");
            }

            self::adicionarCache($cache_key, $data['id'], 300);
            return $data['id'];
        } catch (Exception $e) {
            error_log("[API] Erro iniciar: " . $e->getMessage());
            throw $e;
        }
    }

    public static function consultarAnalise($id_analise)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $cache_key = 'consulta_' . $id_analise;
        $cached = self::getCache($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/' . $id_analise . '/simplificada';

        try {
            $data = self::fazerRequisicao($url, 'GET', null, 15);
            self::adicionarCache($cache_key, $data, 30);
            return $data;
        } catch (Exception $e) {
            error_log("[API] Erro consultar: " . $e->getMessage());
            throw $e;
        }
    }

    public static function aguardarResultado($id_analise, $max_tentativas = 12, $intervalo_base = 3)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $tentativa = 0;
        $ultima_resposta = null;
        $tempo_inicio = time();

        while ($tentativa < $max_tentativas) {
            if (time() - $tempo_inicio > 40) {
                error_log("[API] Timeout global");
                break;
            }

            try {
                $tentativa++;
                $dados = self::consultarAnalise($id_analise);
                $ultima_resposta = $dados;

                if (!self::analiseEstaProcessando($dados)) {
                    error_log("[API] Status mudou! Retornando dados.");
                    return $dados;
                }

                if ($tentativa < $max_tentativas) {
                    sleep($intervalo_base);
                }
            } catch (Exception $e) {
                error_log("[API] Tentativa {$tentativa}: " . $e->getMessage());
                if ($tentativa >= $max_tentativas && $ultima_resposta) {
                    return $ultima_resposta;
                }
                sleep($intervalo_base);
            }
        }

        return $ultima_resposta;
    }

    public static function limparCache()
    {
        self::$cache_analises = array();
        self::$last_request_time = 0;
        error_log("[API] Cache limpo");
        return true;
    }
}

// =============================================
// FUNÇÕES FIREBIRD
// =============================================
function prepararDocumentoParaFirebird($documento)
{
    $documentoNumeros = preg_replace('/[^0-9]/', '', $documento);

    if (strlen($documentoNumeros) == 11) {
        $documentoFormatado = sprintf(
            '%03d.%03d.%03d-%02d',
            substr($documentoNumeros, 0, 3),
            substr($documentoNumeros, 3, 3),
            substr($documentoNumeros, 6, 3),
            substr($documentoNumeros, 9, 2)
        );
        return [
            'tipo' => 'CPF',
            'valor' => $documentoFormatado,
            'coluna' => 'c.ncpf'
        ];
    } elseif (strlen($documentoNumeros) == 14) {
        $documentoFormatado = sprintf(
            '%02d.%03d.%03d/%04d-%02d',
            substr($documentoNumeros, 0, 2),
            substr($documentoNumeros, 2, 3),
            substr($documentoNumeros, 5, 3),
            substr($documentoNumeros, 8, 4),
            substr($documentoNumeros, 12, 2)
        );
        return [
            'tipo' => 'CNPJ',
            'valor' => $documentoFormatado,
            'coluna' => 'c.ncgc'
        ];
    }

    return false;
}

function conectarFirebird($unidade)
{
    $dsn = "firebird:dbname={$unidade['host']}:{$unidade['path']};charset=UTF8";

    try {
        $conn = new PDO($dsn, $unidade['user'], $unidade['pass']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_TIMEOUT, 5);
        return $conn;
    } catch (PDOException $e) {
        error_log("[FIREBIRD] Erro " . $unidade['path'] . ": " . $e->getMessage());
        return null;
    }
}

function consultarFirebirdUnidade($conn, $documento)
{
    $info = prepararDocumentoParaFirebird($documento);

    if (!$info) {
        return ['erro' => 'Documento inválido'];
    }

    $sql = "SELECT FIRST 1 c.codic as cod_cliente 
        FROM arqcad c 
        WHERE c.tipoc = 'C' 
        AND c.situ IN ('A', 'B') 
        AND {$info['coluna']} = :documento";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':documento' => $info['valor']]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        return $resultado ? $resultado['COD_CLIENTE'] : 'NÃO CADASTRADO';
    } catch (PDOException $e) {
        error_log("[FIREBIRD] Erro consulta: " . $e->getMessage());
        return ['erro' => $e->getMessage()];
    }
}

// =============================================
// ENDPOINTS ESPECIAIS
// =============================================

if (isset($_GET['action']) && $_GET['action'] === 'limpar_sessao') {
    header('Content-Type: application/json');
    ApiNeocredit::limparCache();
    echo json_encode(['success' => true, 'message' => 'Cache limpo']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');

    $unidades_status = [];
    foreach ($GLOBALS['unidades_firebird'] as $nomeUnidade => $config) {
        $conn = conectarFirebird($config);
        $unidades_status[$nomeUnidade] = $conn ? 'online' : 'offline';
        if ($conn) $conn = null;
    }

    echo json_encode([
        'status' => 'online',
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'api_neocredit_available' => ApiNeocredit::curlDisponivel(),
        'postgresql_connected' => isset($pdo_pgsql) && $pdo_pgsql !== null,
        'firebird_unidades' => $unidades_status,
        'execution_id' => $GLOBALS['execution_id']
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'consulta_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    try {
        $dados = ApiNeocredit::consultarAnalise($_GET['id']);
        $esta_processando = ApiNeocredit::analiseEstaProcessando($dados);

        echo json_encode([
            'success' => true,
            'status_principal' => $dados['status'] ?? 'N/A',
            'status_campos' => $dados['campos']['status'] ?? null,
            'esta_processando' => $esta_processando,
            'em_processamento' => $esta_processando,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// =============================================
// PROCESSADOR PRINCIPAL
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(60);

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    try {
        $documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';

        if (empty($documento)) {
            throw new Exception('Documento não informado');
        }

        $start_time = microtime(true);
        $resultados = [
            'neocredit' => null,
            'firebird' => []
        ];

        $consulta_completa = isset($_POST['consultar_completo']) && $_POST['consultar_completo'] === 'true';
        $id_analise_fornecido = isset($_POST['id_analise']) ? $_POST['id_analise'] : null;

        // 1. CONSULTAR API NEOCREDIT
        try {
            if ($consulta_completa && $id_analise_fornecido) {
                error_log("[SISTEMA] Consulta completa para ID: " . $id_analise_fornecido);
                $dados_api = ApiNeocredit::consultarAnalise($id_analise_fornecido);
                $id_analise = $id_analise_fornecido;
            } else {
                $id_analise = ApiNeocredit::iniciarAnalise($documento);
                error_log("[SISTEMA] ID análise: " . $id_analise);
                $dados_api = ApiNeocredit::aguardarResultado($id_analise, 10, 3);
            }

            $dados_formatados = ApiNeocredit::extrairDadosFormatados($dados_api);

            $resultados['neocredit'] = [
                'id_analise' => $id_analise,
                'dados_api' => $dados_api,
                'dados_formatados' => $dados_formatados,
                'sucesso' => true
            ];
        } catch (Exception $e) {
            error_log("[SISTEMA] Erro Neocredit: " . $e->getMessage());

            $resultados['neocredit'] = [
                'sucesso' => false,
                'erro' => $e->getMessage(),
                'dados_formatados' => [
                    'razao_social' => 'N/A',
                    'documento' => $documento,
                    'status' => 'ERRO',
                    'status_principal' => 'ERRO',
                    'status_campos' => null,
                    'em_processamento' => false
                ]
            ];
        }

        // 2. CONSULTAR FIREBIRD
        foreach ($GLOBALS['unidades_firebird'] as $nomeUnidade => $config) {
            $conn = conectarFirebird($config);
            if ($conn) {
                $codigo = consultarFirebirdUnidade($conn, $documento);
                $resultados['firebird'][$nomeUnidade] = $codigo;
                $conn = null;
            } else {
                $resultados['firebird'][$nomeUnidade] = ['erro' => 'Erro na conexão'];
            }
        }

        $total_time = round(microtime(true) - $start_time, 2);
        error_log("[SISTEMA] Tempo total: " . $total_time . "s");

        $resposta = array(
            'success' => true,
            'documento' => $documento,
            'documento_limpo' => preg_replace('/[^0-9]/', '', $documento),
            'resultados' => $resultados,
            'tempo_total_segundos' => $total_time,
            'timestamp' => date('Y-m-d H:i:s'),
            'execution_id' => $execution_id
        );

        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("[SISTEMA] ERRO FINAL: " . $e->getMessage());
        http_response_code(500);

        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
            'execution_id' => $execution_id
        ));
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>NOROAÇO - Análise de Crédito</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <style>
        /* ========== CSS ESTILO REFERÊNCIA ========== */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-size: 14px;
            overflow-y: hidden;
        }

        /* ========== LAYOUT PRINCIPAL ========== */
        .container-principal {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: white;
            border-radius: 0;
            overflow: hidden;
        }

        /* ========== HEADER ========== */
        .header-principal {
            background: #333;
            height: 70px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            position: relative;
            border-bottom: 3px solid #fdb525;
            gap: 15px;
            flex-shrink: 0;
        }

        .logo-noroaco {
            height: 45px;
            width: auto;
        }

        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
        }

        /* ========== BOTÕES PDF ========== */
        .btn-pdf,
        .btn-pdf-detalhado {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
            box-shadow: 0 4px 6px rgba(253, 181, 37, 0.2);
            white-space: nowrap;
            height: 45px;
        }

        .btn-pdf-detalhado {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.2);
        }

        .btn-pdf:hover,
        .btn-pdf-detalhado:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        /* ========== TÍTULO DA TABELA ========== */
        .titulo-tabela {
            color: #2c3e50;
            padding: 12px 15px;
            margin: 15px 15px 0;
            background: white;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border-left: 4px solid #fdb525;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            flex-shrink: 0;
        }

        .titulo-tabela i {
            margin-right: 8px;
            color: #fdb525;
        }

        /* ========== FORMULÁRIO ========== */
        .form-section {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            margin: 15px 15px 0 15px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .form-section.hidden {
            display: none;
            margin: 0;
            padding: 0;
            height: 0;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .form-section label {
            display: block;
            margin-bottom: 15px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 16px;
            text-align: center;
        }

        .form-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .input-with-icon {
            flex: 1;
            position: relative;
            min-width: 300px;
            max-width: 400px;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #fdb525;
            font-size: 18px;
        }

        #documento-input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }

        #documento-input:focus {
            border-color: #fdb525;
            outline: none;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .button-group {
            display: flex;
            gap: 12px;
        }

        #consultar-btn {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        #limpar-btn {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        #consultar-btn:hover,
        #limpar-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        #consultar-btn:disabled,
        #limpar-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* ========== CONTEÚDO SCROLLÁVEL ========== */
        .conteudo-scrollavel {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
            padding: 15px;
            margin-top: 0;
        }

        /* ========== LOADING ========== */
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 16px;
            margin: 0;
            border: 1px solid #e0e0e0;
        }

        .spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid #fdb525;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .progress-container {
            width: 100%;
            background: #f0f0f0;
            border-radius: 10px;
            margin: 20px 0;
            height: 12px;
            overflow: hidden;
        }

        .progress-bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #fdb525, #ffc64d);
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        .progress-text {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin: 15px 0 5px;
        }

        .loading-details {
            color: #666;
            font-size: 14px;
            margin-top: 10px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #fdb525;
            text-align: left;
        }

        .loading-details div {
            margin: 5px 0;
        }

        /* ========== MENSAGENS ========== */
        .error-message,
        .success-message {
            position: fixed;
            top: 90px;
            right: 20px;
            z-index: 1000;
            padding: 15px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            max-width: 400px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: opacity 0.4s ease, transform 0.4s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .error-message.show,
        .success-message.show {
            opacity: 1;
            transform: translateX(0);
        }

        .error-message.hiding,
        .success-message.hiding {
            opacity: 0;
            transform: translateX(100%);
        }

        .error-message {
            background: linear-gradient(135deg, #ff4444 0%, #cc0000 100%);
            color: white;
            border-left: 5px solid #ff0000;
        }

        .success-message {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
            color: white;
            border-left: 5px solid #1B5E20;
        }

        .error-message i,
        .success-message i {
            font-size: 20px;
            flex-shrink: 0;
        }

        .error-message .close-btn,
        .success-message .close-btn {
            margin-left: auto;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            min-width: auto;
            height: auto;
            opacity: 0.8;
            transition: opacity 0.2s;
        }

        .error-message .close-btn:hover,
        .success-message .close-btn:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        /* ========== RESULTADOS - CONTAINER FIXO ========== */
        .result-section {
            display: none;
            margin: 0;
            height: 100%;
        }

        .api-section {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }

        /* ========== HEADER DO RESULTADO FIXO ========== */
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            flex-shrink: 0;
            background: white;
            z-index: 2;
        }

        .result-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #333;
            font-size: 18px;
            font-weight: 700;
            overflow: hidden;
        }

        .result-title i {
            color: #fdb525;
        }

        .btn-salvar-topo {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-salvar-topo:hover {
            background: linear-gradient(135deg, #218838 0%, #1e9e8a 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-salvar-topo:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            opacity: 0.6;
        }

        /* ========== CONTAINER DOS CAMPOS COM ROLAGEM ========== */
        #resultContent {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding-right: 5px;
        }

        #resultContent::-webkit-scrollbar {
            width: 6px;
        }

        #resultContent::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        #resultContent::-webkit-scrollbar-thumb {
            background: #fdb525;
            border-radius: 10px;
        }

        #resultContent::-webkit-scrollbar-thumb:hover {
            background: #e0a31e;
        }

        /* ========== GRADE DE DUAS COLUNAS ========== */
        .duas-colunas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            height: 100%;
        }

        .coluna {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e9ecef;
            height: 100%;
            overflow: visible;
        }

        .coluna h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid #fdb525;
        }

        .coluna h4 i {
            color: #fdb525;
        }

        .linha-info {
            display: flex;
            margin-bottom: 10px;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            align-items: center;
            min-height: 45px;
            gap: 15px;
            /* ESPAÇAMENTO ENTRE TÍTULO E VALOR */
        }

        .linha-info:hover {
            background: #f8f9fa;
            transform: translateX(2px);
            border-color: #fdb525;
        }

        .info-rotulo {
            width: 130px;
            font-weight: 600;
            color: #666;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            flex-shrink: 0;
        }

        .info-rotulo i {
            color: #fdb525;
            width: 16px;
            font-size: 12px;
        }

        .info-valor {
            flex: 1;
            color: #333;
            font-weight: 500;
            word-break: break-word;
            line-height: 1.4;
            font-size: 13px;
        }

        .info-valor.destaque {
            color: #fdb525;
            font-weight: 700;
        }

        /* Estilo para linha de dois itens lado a lado */
        .linha-dupla {
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
        }

        .item-metade {
            flex: 1;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            /* ESPAÇAMENTO ENTRE TÍTULO E VALOR NOS ITENS LADO A LADO */
            transition: all 0.2s;
        }

        .item-metade:hover {
            background: #f8f9fa;
            transform: translateX(2px);
            border-color: #fdb525;
        }

        .item-metade .info-rotulo {
            width: auto;
            min-width: 80px;
        }

        .status-container {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-aprovar {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-reprovar {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-derivar {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-analise {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .document-type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
            display: inline-block;
            margin-left: 10px;
        }

        .badge-cpf {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .badge-cnpj {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .btn-nova-consulta {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            margin-left: 15px;
            flex-shrink: 0;
        }

        .btn-nova-consulta:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(253, 181, 37, 0.3);
        }

        .codigo-text {
            font-family: monospace;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
        }

        .codigo-text.encontrado {
            background: rgba(40, 167, 69, 0.12);
            color: #2b6e3c;
            border: 1px solid #c3e6cb;
        }

        .codigo-text.nao-encontrado {
            background: rgba(220, 53, 69, 0.08);
            color: #b91c1c;
            border: 1px solid #f5c6cb;
        }

        /* ========== RESPONSIVIDADE ========== */
        @media (max-width: 992px) {
            .duas-colunas {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        @media (max-width: 768px) {
            .header-principal {
                flex-direction: column;
                height: auto;
                padding: 15px;
            }

            .form-input-group {
                flex-direction: column;
                align-items: stretch;
            }

            .input-with-icon {
                max-width: 100%;
            }

            .button-group {
                width: 100%;
                justify-content: center;
            }

            button {
                flex: 1;
            }

            .linha-info {
                flex-direction: column;
                gap: 5px;
                padding: 8px;
                min-height: auto;
            }

            .linha-dupla {
                flex-direction: column;
                gap: 10px;
            }

            .info-rotulo {
                width: 100%;
                font-size: 12px;
            }

            .info-valor {
                padding-left: 22px;
            }

            .error-message,
            .success-message {
                top: 70px;
                right: 10px;
                left: 10px;
                max-width: none;
            }

            .result-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .btn-salvar-topo {
                width: 100%;
                justify-content: center;
            }

            .form-section {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .header-principal {
                padding: 10px;
            }

            .logo-noroaco {
                height: 35px;
            }

            .form-section {
                padding: 12px;
                margin: 10px 10px 0 10px;
            }

            #documento-input {
                padding: 12px 12px 12px 40px;
                font-size: 14px;
            }

            button {
                padding: 12px 20px;
                font-size: 14px;
            }

            .conteudo-scrollavel {
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <!-- HEADER -->
        <header class="header-principal">
            <div class="logo-noroaco">
                <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco" style="height: 50px;">
            </div>
            <div class="botoes-direita">
                <button type="button" class="btn-pdf" id="btn-pdf-simplificado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PF
                </button>
                <button type="button" class="btn-pdf-detalhado" id="btn-pdf-detalhado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PJ
                </button>
                <button type="button" class="btn-nova-consulta" id="btn-nova-consulta" style="display: none;">
                    <i class="fas fa-search"></i> Nova Consulta
                </button>
            </div>
        </header>

        <!-- TÍTULO -->
        <div class="titulo-tabela">
            <i class="fa-solid fa-coins"></i> Gerenciador - Limite Crédito
        </div>

        <!-- FORMULÁRIO -->
        <div class="form-section" id="form-section">
            <div class="form-input-group">
                <div class="input-with-icon">
                    <i class="fas fa-search"></i>
                    <input type="text"
                        id="documento-input"
                        placeholder="Digite CPF ou CNPJ"
                        maxlength="18"
                        autocomplete="off">
                </div>

                <div class="button-group">
                    <button type="button" id="consultar-btn">
                        <i class="fas fa-play"></i> Consultar
                    </button>
                    <button type="button" id="limpar-btn">
                        <i class="fas fa-eraser"></i> Limpar
                    </button>
                </div>
            </div>
        </div>

        <!-- CONTEÚDO SCROLLÁVEL (LOADING E RESULTADOS) -->
        <div class="conteudo-scrollavel">
            <!-- LOADING -->
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <div id="loading-text">Processando consulta...</div>
                <div class="progress-container">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                <div class="progress-text" id="loadingStatus">Iniciando consulta...</div>
                <div class="loading-details" id="loadingDetails">
                    <div><i class="fas fa-hourglass-half"></i> <span id="attemptCount">Tentativa: 1/30</span></div>
                    <div><i class="fas fa-clock"></i> <span id="loadingTime">Tempo restante: 5:00</span></div>
                    <div><i class="fas fa-tag"></i> <span id="currentStatus">Status: PROCESSANDO</span></div>
                </div>
            </div>

            <!-- RESULTADOS -->
            <div class="result-section" id="resultSection">
                <div class="api-section">
                    <div class="result-header">
                        <h3 class="result-title">
                            <i class="fas fa-check-circle"></i>
                            Resultado da Consulta - <span id="resultBadge" class="document-type-badge badge-cpf">CPF/CNPJ</span>
                        </h3>
                        <button id="salvar-dados-topo" class="btn-salvar-topo" style="display: none;">
                            <i class="fas fa-save"></i> Salvar Dados
                        </button>
                    </div>

                    <div id="resultContent">
                        <!-- Resultados serão inseridos aqui -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MENSAGENS TEMPORÁRIAS -->
    <div id="errorMessage" class="error-message">
        <i class="fas fa-exclamation-circle"></i>
        <span id="errorText"></span>
        <button class="close-btn" onclick="hideMessage('errorMessage')">×</button>
    </div>

    <div id="successMessage" class="success-message">
        <i class="fas fa-check-circle"></i>
        <span id="successText"></span>
        <button class="close-btn" onclick="hideMessage('successMessage')">×</button>
    </div>

    <script>
        // Elementos do DOM
        const documentoInput = document.getElementById('documento-input');
        const formSection = document.getElementById('form-section');
        const loading = document.getElementById('loading');
        const progressBar = document.getElementById('progressBar');
        const loadingStatus = document.getElementById('loadingStatus');
        const loadingTime = document.getElementById('loadingTime');
        const attemptCount = document.getElementById('attemptCount');
        const currentStatus = document.getElementById('currentStatus');
        const resultSection = document.getElementById('resultSection');
        const resultContent = document.getElementById('resultContent');
        const resultBadge = document.getElementById('resultBadge');
        const errorMessage = document.getElementById('errorMessage');
        const errorText = document.getElementById('errorText');
        const successMessage = document.getElementById('successMessage');
        const successText = document.getElementById('successText');
        const consultarBtn = document.getElementById('consultar-btn');
        const limparBtn = document.getElementById('limpar-btn');
        const btnPDFSimplificado = document.getElementById('btn-pdf-simplificado');
        const btnPDFDetalhado = document.getElementById('btn-pdf-detalhado');
        const btnNovaConsulta = document.getElementById('btn-nova-consulta');
        const salvarDadosTopo = document.getElementById('salvar-dados-topo');

        // Variáveis globais
        let progressInterval = null;
        let verificacaoInterval = null;
        let dadosAtuais = null;
        let idAnaliseAtual = null;

        // Máscara CPF/CNPJ
        documentoInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 14) value = value.slice(0, 14);

            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            }
            e.target.value = value;

            const nums = value.replace(/\D/g, '');
            if (nums.length === 11) {
                resultBadge.textContent = 'CPF';
                resultBadge.className = 'document-type-badge badge-cpf';
            } else if (nums.length === 14) {
                resultBadge.textContent = 'CNPJ';
                resultBadge.className = 'document-type-badge badge-cnpj';
            }
        });

        // Enter
        documentoInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                consultarBtn.click();
            }
        });

        // Consultar
        consultarBtn.addEventListener('click', async function() {
            const documento = documentoInput.value.replace(/\D/g, '');

            if (documento.length !== 11 && documento.length !== 14) {
                showError('CPF deve ter 11 dígitos ou CNPJ 14 dígitos');
                documentoInput.style.borderColor = '#ff4444';
                return;
            }

            documentoInput.style.borderColor = '#e0e0e0';
            startLoading();
            consultarBtn.disabled = true;
            limparBtn.disabled = true;
            documentoInput.disabled = true;

            try {
                const formData = new FormData();
                formData.append('documento', documento);

                const response = await fetch(window.location.href + '?nocache=' + Date.now(), {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error('Erro na resposta do servidor');

                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Erro desconhecido');

                const neocredit = data.resultados?.neocredit;
                const statusPrincipal = neocredit?.dados_formatados?.status_principal || '';
                const emProc = neocredit?.dados_formatados?.em_processamento === true;

                if (statusPrincipal.toUpperCase() === 'PROCESSANDO' && emProc && neocredit?.id_analise) {
                    loadingStatus.textContent = 'Análise em andamento...';
                    currentStatus.innerHTML = '<i class="fas fa-tag"></i> Status: PROCESSANDO';
                    idAnaliseAtual = neocredit.id_analise;
                    iniciarVerificacaoPeriodica(neocredit.id_analise);
                    iniciarProgresso();
                } else {
                    stopLoading();
                    displayResult(data);
                    dadosAtuais = data;
                    formSection.classList.add('hidden');
                    btnPDFSimplificado.style.display = 'inline-flex';
                    btnPDFDetalhado.style.display = 'inline-flex';
                    btnNovaConsulta.style.display = 'inline-flex';
                    salvarDadosTopo.style.display = 'inline-flex';
                    showSuccess('Consulta realizada com sucesso!');
                }
            } catch (error) {
                console.error('Erro:', error);
                stopLoading();
                showError('Erro na consulta: ' + error.message);
                habilitarBotoes();
            }
        });

        function iniciarProgresso() {
            let progress = 0;
            const totalTime = 300;
            if (progressInterval) clearInterval(progressInterval);
            progressInterval = setInterval(() => {
                progress++;
                const percent = (progress / totalTime) * 100;
                progressBar.style.width = percent + '%';
                const remaining = totalTime - progress;
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                loadingTime.innerHTML = `<i class="fas fa-clock"></i> Tempo restante: ${minutes}:${seconds.toString().padStart(2, '0')}`;
                if (progress >= totalTime) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    loading.style.display = 'none';
                    showError('Tempo limite de 5 minutos excedido. Tente novamente.');
                    habilitarBotoes();
                }
            }, 1000);
        }

        function iniciarVerificacaoPeriodica(idAnalise) {
            if (!idAnalise) return;
            if (verificacaoInterval) clearInterval(verificacaoInterval);
            let tentativa = 0;
            const maxTentativas = 30;
            verificacaoInterval = setInterval(() => {
                tentativa++;
                attemptCount.innerHTML = `<i class="fas fa-hourglass-half"></i> Tentativa: ${tentativa}/${maxTentativas}`;
                fetch(window.location.href + '?action=consulta_status&id=' + idAnalise + '&nocache=' + Date.now())
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            currentStatus.innerHTML = `<i class="fas fa-tag"></i> Status: ${data.status_principal || 'PROCESSANDO'}`;
                            if (!data.esta_processando) {
                                clearInterval(verificacaoInterval);
                                clearInterval(progressInterval);
                                buscarDadosCompletos(idAnalise);
                            }
                        }
                    })
                    .catch(error => console.error('Erro na verificação:', error));
                if (tentativa >= maxTentativas) {
                    clearInterval(verificacaoInterval);
                    clearInterval(progressInterval);
                    loading.style.display = 'none';
                    showError('Tempo limite de 5 minutos excedido.');
                    habilitarBotoes();
                }
            }, 10000);
        }

        async function buscarDadosCompletos(idAnalise) {
            loadingStatus.textContent = 'Análise concluída! Carregando dados...';
            currentStatus.innerHTML = '<i class="fas fa-tag"></i> Status: CONCLUÍDO';
            const documento = documentoInput.value;
            try {
                const formData = new FormData();
                formData.append('documento', documento);
                formData.append('consultar_completo', 'true');
                formData.append('id_analise', idAnalise);
                const response = await fetch(window.location.href + '?nocache=' + Date.now(), {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Erro ao buscar dados');
                stopLoading();
                displayResult(data);
                dadosAtuais = data;
                formSection.classList.add('hidden');
                btnPDFSimplificado.style.display = 'inline-flex';
                btnPDFDetalhado.style.display = 'inline-flex';
                btnNovaConsulta.style.display = 'inline-flex';
                salvarDadosTopo.style.display = 'inline-flex';
                showSuccess('✅ Análise concluída com sucesso!');
            } catch (error) {
                stopLoading();
                showError('Erro ao carregar dados: ' + error.message);
                habilitarBotoes();
            }
        }

        function displayResult(data) {
            const neocredit = data.resultados?.neocredit;
            const firebird = data.resultados?.firebird || {};

            if (!neocredit || !neocredit.sucesso) {
                resultContent.innerHTML = `<div style="text-align: center; padding: 40px;"><i class="fas fa-exclamation-triangle"></i> ${neocredit?.erro || 'Erro na consulta'}</div>`;
                resultSection.style.display = 'block';
                return;
            }

            const dados = neocredit.dados_formatados;
            const documentoNumeros = (dados.documento || data.documento || '').replace(/\D/g, '');
            const tipoDocumento = documentoNumeros.length === 11 ? 'CPF' : 'CNPJ';

            resultBadge.textContent = tipoDocumento;
            resultBadge.className = `document-type-badge ${tipoDocumento === 'CPF' ? 'badge-cpf' : 'badge-cnpj'}`;

            let statusClass = 'status-analise';
            const status = dados.status_campos || dados.status || '';
            if (status.toLowerCase() === 'aprovar' || status.toLowerCase() === 'aprovado') statusClass = 'status-aprovar';
            else if (status.toLowerCase() === 'reprovar' || status.toLowerCase() === 'reprovado') statusClass = 'status-reprovar';
            else if (status.toLowerCase() === 'derivar') statusClass = 'status-derivar';

            let html = '<div class="duas-colunas">';

            // COLUNA 1: Análise de Crédito (NeoCredit)
            html += '<div class="coluna"><h4><i class="fas fa-chart-line"></i> Análise de Crédito - NeoCredit</h4>';

            // Linha dupla: ID da Análise e Documento (lado a lado)
            html += '<div class="linha-dupla">';
            html += criarItemMetade('ID Neocredit: ', dados.id_analise || 'N/A', 'fas fa-fingerprint');
            html += criarItemMetade('Documento: ', formatarDocumento(dados.documento || ''), 'fas fa-id-card');
            html += '</div>';

            // Razão Social (linha inteira)
            html += criarLinhaInfo('Razão Social: ', dados.razao_social || 'N/A', 'fas fa-building');

            // Linha dupla: Risco e Classificação (lado a lado)
            html += '<div class="linha-dupla">';
            html += criarItemMetade('Risco: ', dados.risco || 'N/A', 'fas fa-exclamation-triangle');
            html += criarItemMetade('Classificação: ', dados.classificacao_risco || 'N/A', 'fas fa-sitemap');
            html += '</div>';

            // Score (linha inteira)
            html += criarLinhaInfo('Score: ', dados.score || 'N/A', 'fas fa-chart-bar');

            // Linha dupla: Limite Aprovado e Data Validade (lado a lado)
            html += '<div class="linha-dupla">';
            html += criarItemMetade('Limite Aprovado: ', 'R$ ' + formatarMoeda(dados.limite_aprovado || 0), 'fas fa-money-bill-wave', true);
            html += criarItemMetade('Data Validade: ', dados.data_validade || 'N/A', 'fas fa-calendar-alt');
            html += '</div>';

            // Status da Análise (linha inteira)
            html += criarLinhaInfo('Status da Análise: ', `<span class="status-container ${statusClass}">${(dados.status_campos || dados.status || 'CONCLUÍDO').toUpperCase()}</span>`, 'fas fa-info-circle');
            html += '</div>';

            // COLUNA 2: Códigos por Unidade (SIC)
            html += '<div class="coluna"><h4><i class="fas fa-database"></i> Códigos por Unidade - SIC</h4>';

            const unidades = ['BARRA MANSA', 'BOTUCATU', 'LINS', 'RIO PRETO', 'VOTUPORANGA'];
            unidades.forEach(un => {
                let resultado = firebird[un];
                let statusIcon = 'fa-times-circle',
                    statusColor = '#dc3545',
                    codigoText = 'NÃO CADASTRADO',
                    codigoClass = 'nao-encontrado';

                if (resultado && typeof resultado === 'object' && resultado.erro) {
                    statusIcon = 'fa-exclamation-circle';
                    codigoText = 'ERRO';
                } else if (resultado && resultado !== 'NÃO CADASTRADO') {
                    statusIcon = 'fa-check-circle';
                    statusColor = '#28a745';
                    codigoText = resultado.toString().replace(/\.0+$/, '');
                    codigoClass = 'encontrado';
                }

                html += `<div class="linha-info">
                            <div class="info-rotulo">
                                <i class="fas ${statusIcon}" style="color: ${statusColor};"></i> ${un}
                            </div>
                            <div class="info-valor">
                                <span class="codigo-text ${codigoClass}">${codigoText}</span>
                            </div>
                         </div>`;
            });
            html += '</div></div>';

            resultContent.innerHTML = html;
            resultSection.style.display = 'block';
            resultSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        function criarLinhaInfo(rotulo, valor, icone, destaque = false) {
            const classeDestaque = destaque ? 'info-valor destaque' : 'info-valor';
            return `
                <div class="linha-info">
                    <div class="info-rotulo">
                        <i class="${icone}"></i> ${rotulo}
                    </div>
                    <div class="${classeDestaque}">${valor}</div>
                </div>
            `;
        }

        function criarItemMetade(rotulo, valor, icone, destaque = false) {
            const classeDestaque = destaque ? 'info-valor destaque' : 'info-valor';
            return `
                <div class="item-metade">
                    <div class="info-rotulo">
                        <i class="${icone}"></i> ${rotulo}
                    </div>
                    <div class="${classeDestaque}">${valor}</div>
                </div>
            `;
        }

        function formatarDocumento(doc) {
            if (!doc || doc === 'N/A') return 'N/A';
            const num = doc.replace(/\D/g, '');
            if (num.length === 11) return num.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            if (num.length === 14) return num.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
            return doc;
        }

        function formatarMoeda(valor) {
            if (!valor && valor !== 0) return '0,00';
            return parseFloat(valor).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        btnNovaConsulta.addEventListener('click', function() {
            resetForm();
        });

        limparBtn.addEventListener('click', function() {
            resetForm();
        });

        salvarDadosTopo.addEventListener('click', async function() {
            if (!dadosAtuais) {
                showError('Nenhum dado para salvar.');
                return;
            }

            // Desabilitar botão durante salvamento
            salvarDadosTopo.disabled = true;
            salvarDadosTopo.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            try {
                const neocredit = dadosAtuais.resultados?.neocredit;
                const firebird = dadosAtuais.resultados?.firebird || {};

                if (!neocredit || !neocredit.sucesso) {
                    throw new Error('Dados da análise não disponíveis');
                }

                const dados = neocredit.dados_formatados;

                // Extrair códigos do Firebird
                const codic_bm = firebird['BARRA MANSA'] && firebird['BARRA MANSA'] !== 'NÃO CADASTRADO' ? firebird['BARRA MANSA'] : null;
                const codic_bot = firebird['BOTUCATU'] && firebird['BOTUCATU'] !== 'NÃO CADASTRADO' ? firebird['BOTUCATU'] : null;
                const codic_ls = firebird['LINS'] && firebird['LINS'] !== 'NÃO CADASTRADO' ? firebird['LINS'] : null;
                const codic_rp = firebird['RIO PRETO'] && firebird['RIO PRETO'] !== 'NÃO CADASTRADO' ? firebird['RIO PRETO'] : null;
                const codic_vt_rnd = firebird['VOTUPORANGA'] && firebird['VOTUPORANGA'] !== 'NÃO CADASTRADO' ? firebird['VOTUPORANGA'] : null;

                // Preparar dados para envio
                const formData = new FormData();
                formData.append('action', 'save_to_db');
                formData.append('id_analise', dados.id_analise || '');
                formData.append('razao_social', dados.razao_social || '');
                formData.append('documento', dados.documento || dadosAtuais.documento || '');
                formData.append('risco', dados.risco || '');
                formData.append('classificacao_risco', dados.classificacao_risco || '');
                formData.append('score', dados.score || '');
                formData.append('lcred_aprovado', dados.limite_aprovado || 0);
                formData.append('validade_cred', dados.data_validade || '');
                formData.append('status', dados.status_campos || dados.status || 'CONSULTADO');

                // Códigos das unidades
                formData.append('codic_bm', codic_bm);
                formData.append('codic_bot', codic_bot);
                formData.append('codic_ls', codic_ls);
                formData.append('codic_rp', codic_rp);
                formData.append('codic_vt_rnd', codic_vt_rnd);

                // Enviar para o arquivo de salvamento
                const response = await fetch('limite_cred_save.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showSuccess(result.message);
                    // Opcional: atualizar dadosAtuais com o código gerado
                    if (result.codigo) {
                        dadosAtuais.codigo_salvo = result.codigo;
                    }
                } else {
                    throw new Error(result.message);
                }

            } catch (error) {
                console.error('Erro ao salvar:', error);
                showError('Erro ao salvar: ' + error.message);
            } finally {
                // Reabilitar botão
                salvarDadosTopo.disabled = false;
                salvarDadosTopo.innerHTML = '<i class="fas fa-save"></i> Salvar Dados';
            }
        });

        function startLoading() {
            loading.style.display = 'block';
            resultSection.style.display = 'none';
            progressBar.style.width = '0%';
            attemptCount.innerHTML = '<i class="fas fa-hourglass-half"></i> Tentativa: 1/30';
            loadingTime.innerHTML = '<i class="fas fa-clock"></i> Tempo restante: 5:00';
            currentStatus.innerHTML = '<i class="fas fa-tag"></i> Status: PROCESSANDO';
            loadingStatus.textContent = 'Iniciando consulta...';
        }

        function stopLoading() {
            loading.style.display = 'none';
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
            if (verificacaoInterval) {
                clearInterval(verificacaoInterval);
                verificacaoInterval = null;
            }
        }

        function habilitarBotoes() {
            consultarBtn.disabled = false;
            limparBtn.disabled = false;
            documentoInput.disabled = false;
        }

        function resetForm() {
            documentoInput.value = '';
            documentoInput.style.borderColor = '#e0e0e0';
            resultSection.style.display = 'none';
            resultContent.innerHTML = '';
            btnPDFSimplificado.style.display = 'none';
            btnPDFDetalhado.style.display = 'none';
            btnNovaConsulta.style.display = 'none';
            salvarDadosTopo.style.display = 'none';
            dadosAtuais = null;
            idAnaliseAtual = null;
            resultBadge.textContent = 'CPF/CNPJ';
            resultBadge.className = 'document-type-badge badge-cpf';
            stopLoading();
            habilitarBotoes();
            formSection.classList.remove('hidden');
        }

        function showError(message) {
            errorText.textContent = message;
            errorMessage.classList.add('show');
            setTimeout(() => errorMessage.classList.remove('show'), 5000);
        }

        function showSuccess(message) {
            successText.textContent = message;
            successMessage.classList.add('show');
            setTimeout(() => successMessage.classList.remove('show'), 5000);
        }

        function hideMessage(id) {
            const element = document.getElementById(id);
            if (element) {
                element.classList.add('hiding');
                setTimeout(() => {
                    element.classList.remove('show', 'hiding');
                }, 400);
            }
        }

        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const parent = this.closest('.error-message, .success-message');
                if (parent) parent.classList.remove('show');
            });
        });

        btnPDFSimplificado.addEventListener('click', function() {
            if (!dadosAtuais) {
                showError('Nenhum dado para gerar PDF.');
                return;
            }

            const documento = dadosAtuais.documento?.replace(/\D/g, '') || '';

            if (documento.length !== 11) {
                showError('Este relatório é apenas para Pessoa Física (CPF)');
                return;
            }

            // Capturar os dados da API para enviar
            const dadosAPI = dadosAtuais.resultados?.neocredit?.dados_api || {};

            // Enviar via POST para o arquivo PDF
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'limite_cred_pdf.php';
            form.target = '_blank';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dados';
            input.value = JSON.stringify(dadosAPI);
            form.appendChild(input);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        });

        btnPDFDetalhado.addEventListener('click', function() {
            if (!dadosAtuais) {
                showError('Nenhum dado para gerar PDF.');
                return;
            }
            const documento = dadosAtuais.documento?.replace(/\D/g, '') || '';

            // Verifica se é CNPJ (14 dígitos)
            if (documento.length !== 14) {
                showError('Este relatório é apenas para Pessoa Jurídica (CNPJ)');
                return;
            }

            // Envia via POST para garantir que os dados cheguem corretamente
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'limite_cred_pdf_detalhado.php';
            form.target = '_blank';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dados';
            input.value = JSON.stringify(dadosAtuais.resultados?.neocredit?.dados_api || {});
            form.appendChild(input);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && (dadosAtuais || documentoInput.value.trim() !== '')) resetForm();
        });
    </script>
</body>

</html>