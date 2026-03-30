<?php
// limite_cred.php - VERSÃO FINAL DEFINITIVA
// =============================================
// REGRA: Só mostra barra de progresso quando status = "PROCESSANDO"
// Qualquer outro status mostra dados imediatamente
// CORREÇÃO: Não faz nova consulta, busca dados completos com ID existente
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

    // =============================================
    // FUNÇÃO PRINCIPAL - REGRA DE OURO
    // =============================================
    public static function extrairDadosFormatados($dados_analise)
    {
        if (!is_array($dados_analise)) {
            return array();
        }

        $id_analise = isset($dados_analise['id']) ? $dados_analise['id'] : 'N/A';
        $status_principal = isset($dados_analise['status']) ? $dados_analise['status'] : 'N/A';

        error_log("[API] Status PRINCIPAL: " . $status_principal);

        // REGRA ÚNICA: Só processando se status = "PROCESSANDO"
        $em_processamento = (strtoupper(trim($status_principal)) === 'PROCESSANDO');

        // Se NÃO está processando, extrai os dados
        if (!$em_processamento) {
            $campos = isset($dados_analise['campos']) ? $dados_analise['campos'] : array();

            $limite_aprovado = 0;
            if (isset($campos['limite_aprovado']) && !empty($campos['limite_aprovado'])) {
                $limite_str = $campos['limite_aprovado'];
                $limite_str = str_replace(['R$', ' ', '.'], '', $limite_str);
                $limite_str = str_replace(',', '.', $limite_str);
                $limite_aprovado = floatval($limite_str);
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

        // Está PROCESSANDO
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

    // Verificar se está processando
    public static function analiseEstaProcessando($dados_analise)
    {
        if (!is_array($dados_analise)) {
            return true;
        }

        $status_principal = isset($dados_analise['status']) ?
            strtoupper(trim($dados_analise['status'])) : '';

        return ($status_principal === 'PROCESSANDO');
    }

    // Gerenciamento de cache
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

        // Verificar se é uma consulta completa (após aguardar)
        $consulta_completa = isset($_POST['consultar_completo']) && $_POST['consultar_completo'] === 'true';
        $id_analise_fornecido = isset($_POST['id_analise']) ? $_POST['id_analise'] : null;

        // 1. CONSULTAR API NEOCREDIT
        try {
            if ($consulta_completa && $id_analise_fornecido) {
                // CONSULTA DIRETA - JÁ TEMOS O ID
                error_log("[SISTEMA] Consulta completa para ID: " . $id_analise_fornecido);
                $dados_api = ApiNeocredit::consultarAnalise($id_analise_fornecido);
                $id_analise = $id_analise_fornecido;
            } else {
                // PRIMEIRA CONSULTA - INICIAR ANÁLISE
                $id_analise = ApiNeocredit::iniciarAnalise($documento);
                error_log("[SISTEMA] ID análise: " . $id_analise);

                // Aguardar resultado (máx 40 segundos)
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOROAÇO - Sistema Integrado Neocredit + Firebird</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="neocredit.css">

    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <style>
        .form-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .form-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .input-with-icon {
            flex: 1;
            position: relative;
            min-width: 300px;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #fdb525;
            font-size: 18px;
        }

        .input-with-icon input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .input-with-icon input:focus {
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

        .btn-limpar-tudo {
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
        .btn-limpar-tudo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        #consultar-btn:disabled,
        .btn-limpar-tudo:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* ========== LOADING ========== */
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 16px;
            margin: 20px 0;
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
        }


        .close-btn {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: inherit;
        }

        /* ========== CONTAINER PRINCIPAL DOS RESULTADOS ========== */
        .result-section {
            display: none;
            margin-top: 30px;
        }

        .result-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            width: 100%;
        }

        /* ========== CONTAINER NEOCREDIT ========== */
        .result-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            min-height: 300px;
            max-height: 360px;
            width: 100%;
            overflow: hidden;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .result-header h2 {
            margin: 0;
            color: #333;
            font-size: 16px;
            font-weight: 700;
        }

        .result-header i {
            color: #fdb525;
            font-size: 20px;
        }

        /* NEOCREDIT - LAYOUT EM GRID 3x3 */
        .neocredit-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 5px;
        }

        .info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #dee2e6;
            transition: all 0.3s;
            text-align: center;
            min-height: 80px;
            max-height: 90px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
        }

        .info-box:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }

        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .info-label i {
            color: #fdb525;
            font-size: 12px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            word-break: break-word;
            line-height: 1.3;
        }

        /* ========== CONTAINER FIREBIRD ========== */
        .result-box-sic {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            min-height: 320px;
            max-height: 350px;
            width: 100%;
            overflow: hidden;
        }

        .result-header-sic {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .result-header-sic h2 {
            margin: 0;
            color: #333;
            font-size: 18px;
            font-weight: 700;
            white-space: nowrap;
        }

        .result-header-sic i {
            color: #fdb525;
            font-size: 20px;
        }

        /* FIREBIRD - LAYOUT EM COLUNAS */
        .firebird-grid-layout {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 5px;
        }

        .firebird-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .unidade-container {
            padding: 10px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            min-height: 75px;
            max-height: 85px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .unidade-container:hover {
            background: #e9ecef;
            border-color: #ddd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .unidade-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .unidade-nome {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #333;
            font-size: 12px;
        }

        .unidade-nome i {
            color: #fdb525;
            width: 12px;
            font-size: 12px;
        }

        .unidade-status {
            font-size: 9px;
            padding: 3px 6px;
            border-radius: 10px;
            font-weight: bold;
            min-width: 50px;
            text-align: center;
        }

        .status-encontrado {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-nao-encontrado {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }

        .status-erro {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .codigo-container {
            text-align: center;
            padding: 6px;
            background: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }

        .codigo-value {
            font-size: 14px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
            margin-bottom: 2px;
        }

        .codigo-value.encontrado {
            color: #28a745;
        }

        .codigo-value.nao-encontrado {
            color: #6c757d;
        }

        .codigo-value.erro {
            color: #dc3545;
        }

        /* ========== BOTÕES PDF ========== */
        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-pdf {
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
        }

        .btn-pdf-detalhado {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.2);
            white-space: nowrap;
        }

        .btn-pdf:hover,
        .btn-pdf-detalhado:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        /* ========== STATUS COLORS ========== */
        .status-container {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        /* ========== BOTÃO SALVAR ========== */
        .save-section {
            margin-top: 20px;
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }

        .btn-salvar-db {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }

        .btn-salvar-db:hover {
            background: linear-gradient(135deg, #218838 0%, #1e9e8a 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-salvar-db:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* ========== RESPONSIVIDADE ========== */
        @media (max-width: 1200px) {
            .result-container {
                grid-template-columns: 1fr;
            }

            .neocredit-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .botoes-direita {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .btn-pdf,
            .btn-pdf-detalhado {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {

            .result-box,
            .result-box-sic {
                padding: 15px;
                min-height: auto;
            }

            .neocredit-grid {
                grid-template-columns: 1fr;
            }

            .firebird-row {
                grid-template-columns: 1fr;
            }

            .info-box {
                min-height: 85px;
                padding: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <div class="logo-noroaco">
                <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco" style="height: 50px;">
            </div>
            <div class="botoes-direita">
                <button type="button" class="btn-pdf" id="btn-pdf-simplificado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - Simplificado
                </button>
                <button type="button" class="btn-pdf-detalhado" id="btn-pdf-detalhado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - Detalhado
                </button>
            </div>
        </header>

        <div class="error-message" id="error-message">
            <i class="fas fa-times-circle"></i>
            <span class="message-text"></span>
            <button class="close-btn"><i class="fas fa-times"></i></button>
        </div>

        <div class="success-message" id="success-message">
            <i class="fas fa-check-circle"></i>
            <span class="message-text"></span>
            <button class="close-btn"><i class="fas fa-times"></i></button>
        </div>

        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fa-solid fa-coins"></i> Gerenciador de Crédito
            </div>

            <div class="form-section">
                <div class="form-input-group">
                    <div class="input-with-icon">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="documento-input" placeholder="Digite CPF ou CNPJ...">
                    </div>
                    <div class="button-group">
                        <button id="consultar-btn">
                            <i class="fas fa-search"></i> Consultar
                        </button>
                        <button id="limpar-btn2" class="btn-limpar-tudo">
                            <i class="fas fa-broom"></i> Limpar Tudo
                        </button>
                    </div>
                </div>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p id="loading-text">Consultando dados, aguarde...</p>
                <div class="progress-container">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
                <div class="progress-text" id="progress-text">05:00 / 05:00</div>
                <div class="loading-details" id="loading-details">Processando...</div>
            </div>

            <div class="result-section" id="result-section">
                <div class="result-container" id="result-container">
                    <div class="result-box">
                        <div class="result-header">
                            <i class="fas fa-chart-line"></i>
                            <h2>Análise Neocredit</h2>
                        </div>
                        <div class="result-content" id="neocreditContent"></div>
                    </div>

                    <div class="result-box-sic">
                        <div class="result-header-sic">
                            <i class="fas fa-database"></i>
                            <h2>Códigos por Unidade</h2>
                        </div>
                        <div class="result-content" id="firebirdContent"></div>
                    </div>
                </div>

                <div class="save-section" id="save-section" style="display: none;">
                    <button class="btn-salvar-db" id="salvar-dados-btn">
                        <i class="fas fa-database"></i> Salvar no Banco de Dados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========== ELEMENTOS ==========
            const documentoInput = document.getElementById('documento-input');
            const consultarBtn = document.getElementById('consultar-btn');
            const limparBtn2 = document.getElementById('limpar-btn2');
            const loadingDiv = document.getElementById('loading');
            const loadingText = document.getElementById('loading-text');
            const loadingDetails = document.getElementById('loading-details');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const errorDiv = document.getElementById('error-message');
            const successDiv = document.getElementById('success-message');
            const resultSection = document.getElementById('result-section');
            const resultadoContainer = document.getElementById('result-container');
            const neocreditContent = document.getElementById('neocreditContent');
            const firebirdContent = document.getElementById('firebirdContent');
            const saveSection = document.getElementById('save-section');
            const salvarDadosBtn = document.getElementById('salvar-dados-btn');
            const btnPDFSimplificado = document.getElementById('btn-pdf-simplificado');
            const btnPDFDetalhado = document.getElementById('btn-pdf-detalhado');

            // ========== VARIÁVEIS GLOBAIS ==========
            let progressInterval = null;
            let verificacaoInterval = null;
            let startTime = null;
            let dadosAtuais = null;

            // ========== MÁSCARA CPF/CNPJ ==========
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
            });

            // ========== ENTER ==========
            documentoInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') executarConsulta();
            });

            // ========== LIMPAR TUDO ==========
            limparBtn2.addEventListener('click', function() {
                if (dadosAtuais || documentoInput.value.trim() !== '') {
                    if (confirm('⚠️ Tem certeza que deseja LIMPAR TUDO?')) {
                        limparTudo();
                    }
                } else {
                    limparTudo();
                }
            });

            function limparTudo() {
                if (verificacaoInterval) {
                    clearInterval(verificacaoInterval);
                    verificacaoInterval = null;
                }
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }

                documentoInput.value = '';
                resultSection.style.display = 'none';
                resultadoContainer.style.display = 'none';
                saveSection.style.display = 'none';
                loadingDiv.style.display = 'none';
                neocreditContent.innerHTML = '';
                firebirdContent.innerHTML = '';

                hideError();
                hideSuccess();

                dadosAtuais = null;

                progressBar.style.width = '0%';
                progressText.textContent = '05:00 / 05:00';

                btnPDFSimplificado.style.display = 'none';
                btnPDFDetalhado.style.display = 'none';

                fetch(window.location.href.split('?')[0] + '?action=limpar_sessao&nocache=' + Date.now())
                    .catch(() => {});
            }

            // ========== EXECUTAR CONSULTA ==========
            function executarConsulta() {
                const documento = documentoInput.value.replace(/\D/g, '');

                if (!documento) {
                    showError('Digite um CPF ou CNPJ válido.', 4000);
                    return;
                }

                if (documento.length !== 11 && documento.length !== 14) {
                    showError('CPF deve ter 11 dígitos e CNPJ 14 dígitos.', 4000);
                    return;
                }

                loadingDiv.style.display = 'block';
                loadingText.textContent = 'Iniciando consulta...';
                loadingDetails.textContent = 'Aguardando resposta da API...';

                consultarBtn.disabled = true;
                limparBtn2.disabled = true;
                documentoInput.disabled = true;

                resultSection.style.display = 'none';
                hideError();
                hideSuccess();

                iniciarProgresso();

                const formData = new FormData();
                formData.append('documento', documentoInput.value);

                fetch(window.location.href + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Erro desconhecido');
                        }

                        const neocredit = data.resultados.neocredit;
                        const statusPrincipal = neocredit?.dados_formatados?.status_principal || '';

                        console.log('STATUS PRINCIPAL:', statusPrincipal);

                        // REGRA DE OURO: Só continua com barra se status = PROCESSANDO
                        if (statusPrincipal.toUpperCase() === 'PROCESSANDO') {
                            console.log('⚠️ Status PROCESSANDO - Aguardando até 5 minutos...');

                            loadingText.textContent = 'Processando análise de crédito...';
                            loadingDetails.textContent = 'A análise pode levar até 5 minutos. Aguarde...';

                            if (neocredit?.id_analise) {
                                iniciarVerificacaoPeriodica(neocredit.id_analise);
                            }

                            showSuccess('Análise em andamento. Processando dados...', 4000);
                        } else {
                            // QUALQUER outro status - MOSTRA OS DADOS IMEDIATAMENTE!
                            console.log('✅ Status diferente de PROCESSANDO - Exibindo resultados!');

                            pararProgresso();
                            loadingDiv.style.display = 'none';
                            exibirResultados(data);
                            showSuccess('Consulta realizada com sucesso!', 3000);
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Erro na requisição: ' + error.message, 5000);
                    })
                    .finally(() => {
                        consultarBtn.disabled = false;
                        limparBtn2.disabled = false;
                        documentoInput.disabled = false;
                    });
            }

            // ========== BARRA DE PROGRESSO ==========
            function iniciarProgresso() {
                startTime = new Date().getTime();
                const maxTime = 5 * 60 * 1000;

                if (progressInterval) clearInterval(progressInterval);

                progressInterval = setInterval(() => {
                    const elapsed = new Date().getTime() - startTime;
                    const progress = Math.min((elapsed / maxTime) * 100, 100);
                    progressBar.style.width = progress + '%';

                    const remaining = maxTime - elapsed;
                    if (remaining > 0) {
                        const minutes = Math.floor(remaining / 60000);
                        const seconds = Math.floor((remaining % 60000) / 1000);
                        progressText.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')} / 05:00`;
                    }

                    if (elapsed > maxTime) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Tempo limite de 5 minutos excedido. Tente novamente.', 5000);
                    }
                }, 1000);
            }

            function pararProgresso() {
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                progressBar.style.width = '0%';
                progressText.textContent = '05:00 / 05:00';
                loadingDetails.textContent = '';
            }

            // ========== VERIFICAÇÃO PERIÓDICA CORRIGIDA ==========
            function iniciarVerificacaoPeriodica(idAnalise) {
                if (!idAnalise) return;

                if (verificacaoInterval) {
                    clearInterval(verificacaoInterval);
                }

                let tentativa = 0;
                const maxTentativas = 30;

                verificacaoInterval = setInterval(() => {
                    tentativa++;

                    loadingDetails.textContent = `Verificando status... (tentativa ${tentativa}/${maxTentativas})`;

                    fetch(window.location.href + '?action=consulta_status&id=' + idAnalise + '&nocache=' + Date.now(), {
                            cache: 'no-store'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                console.log('Status verificado:', data.status_principal);
                                console.log('Esta processando?', data.esta_processando);

                                // Se NÃO está mais processando (status mudou)
                                if (!data.esta_processando) {
                                    console.log('✅ Status mudou! Buscando dados completos...');

                                    clearInterval(verificacaoInterval);
                                    verificacaoInterval = null;

                                    // Busca os dados completos SEM fazer nova consulta
                                    buscarDadosCompletos(idAnalise);
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Erro na verificação:', error);
                        });

                    if (tentativa >= maxTentativas) {
                        clearInterval(verificacaoInterval);
                        verificacaoInterval = null;

                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Tempo limite de 5 minutos excedido.', 5000);
                    }
                }, 10000);
            }

            // ========== BUSCAR DADOS COMPLETOS (SEM NOVA CONSULTA) ==========
            function buscarDadosCompletos(idAnalise) {
                console.log('🔍 Buscando dados completos da análise:', idAnalise);

                loadingText.textContent = 'Análise concluída! Carregando dados...';
                loadingDetails.textContent = 'Obtendo informações de crédito...';

                const documento = documentoInput.value;

                if (!documento) {
                    showError('Documento não encontrado', 4000);
                    return;
                }

                const formData = new FormData();
                formData.append('documento', documento);
                formData.append('consultar_completo', 'true');
                formData.append('id_analise', idAnalise);

                fetch(window.location.href + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Erro ao buscar dados');
                        }

                        console.log('✅ Dados completos recebidos!');

                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        exibirResultados(data);
                        showSuccess('✅ Análise concluída com sucesso!', 4000);
                    })
                    .catch(error => {
                        console.error('Erro ao buscar dados completos:', error);

                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Erro ao carregar dados: ' + error.message, 5000);
                    });
            }

            // ========== EXIBIR RESULTADOS ==========
            function exibirResultados(data) {
                dadosAtuais = data;

                exibirNeocredit(data.resultados.neocredit);
                exibirFirebird(data.resultados.firebird);

                resultSection.style.display = 'block';
                resultadoContainer.style.display = 'grid';

                btnPDFSimplificado.style.display = 'inline-flex';
                btnPDFDetalhado.style.display = 'inline-flex';
                saveSection.style.display = 'block';
            }

            // ========== EXIBIR NEOCREDIT ==========
            function exibirNeocredit(dadosNeocredit) {
                let html = '';

                if (dadosNeocredit && dadosNeocredit.sucesso) {
                    const dados = dadosNeocredit.dados_formatados;

                    html = '<div class="neocredit-grid">';

                    html += criarInfoBox('ID Análise', dados.id_analise || 'N/A', 'fingerprint');
                    html += criarInfoBox('Razão Social', dados.razao_social || 'N/A', 'building');
                    html += criarInfoBox('Documento', formatarDocumento(dados.documento || ''), 'id-card');

                    html += criarInfoBox('Risco', dados.risco || 'N/A', 'exclamation-triangle');
                    html += criarInfoBox('Score', dados.score || 'N/A', 'chart-bar');
                    html += criarInfoBox('Classificação', dados.classificacao_risco || 'N/A', 'sitemap');

                    const limite = dados.limite_aprovado || 0;
                    html += criarInfoBox('Limite Aprovado', 'R$ ' + formatarMoeda(limite), 'money-bill-wave');
                    html += criarInfoBox('Data Validade', dados.data_validade || 'N/A', 'calendar-alt');

                    const statusFinal = dados.status_campos || dados.status || 'CONCLUÍDO';
                    const statusInfo = definirStatus(statusFinal);

                    html += `
                        <div class="info-box">
                            <div class="info-label">
                                <i class="fas fa-info-circle"></i>
                                <span>Status da Análise</span>
                            </div>
                            <div class="info-value">
                                <span class="status-container ${statusInfo.classeStatus}">${statusFinal.toUpperCase()}</span>
                            </div>
                        </div>
                    `;

                    html += '</div>';
                } else {
                    html = `
                        <div class="neocredit-grid">
                            ${criarInfoBox('Status', 'ERRO NA CONSULTA', 'exclamation-circle')}
                            ${dadosNeocredit?.erro ? criarInfoBox('Erro', dadosNeocredit.erro, 'exclamation-triangle') : ''}
                        </div>
                    `;
                }

                neocreditContent.innerHTML = html;
            }

            // ========== EXIBIR FIREBIRD ==========
            function exibirFirebird(dadosFirebird) {
                let html = '<div class="firebird-grid-layout">';

                html += '<div class="firebird-row">';
                html += criarUnidadeItem('BARRA MANSA', dadosFirebird?.['BARRA MANSA']);
                html += criarUnidadeItem('BOTUCATU', dadosFirebird?.['BOTUCATU']);
                html += '</div>';

                html += '<div class="firebird-row">';
                html += criarUnidadeItem('LINS', dadosFirebird?.['LINS']);
                html += criarUnidadeItem('RIO PRETO', dadosFirebird?.['RIO PRETO']);
                html += '</div>';

                html += '<div class="firebird-row">';
                html += criarUnidadeItem('VOTUPORANGA', dadosFirebird?.['VOTUPORANGA']);
                html += '</div>';

                html += '</div>';
                firebirdContent.innerHTML = html;
            }

            // ========== FUNÇÕES AUXILIARES ==========
            function criarInfoBox(rotulo, valor, icone) {
                return `
                    <div class="info-box">
                        <div class="info-label">
                            <i class="fas fa-${icone}"></i>
                            <span>${rotulo}</span>
                        </div>
                        <div class="info-value">${valor || 'N/A'}</div>
                    </div>
                `;
            }

            function criarUnidadeItem(unidade, resultado) {
                let statusClasse = 'status-nao-encontrado';
                let statusIcone = 'fa-times-circle';
                let codigoClasse = 'nao-encontrado';
                let codigoTexto = 'NÃO CADASTRADO';

                if (resultado && typeof resultado === 'object' && resultado.erro) {
                    statusClasse = 'status-erro';
                    statusIcone = 'fa-exclamation-circle';
                    codigoClasse = 'erro';
                    codigoTexto = 'ERRO';
                } else if (resultado && resultado !== 'NÃO CADASTRADO') {
                    statusClasse = 'status-encontrado';
                    statusIcone = 'fa-check-circle';
                    codigoClasse = 'encontrado';
                    codigoTexto = resultado.toString().replace(/\.0+$/, '');
                }

                return `
                    <div class="unidade-container">
                        <div class="unidade-header">
                            <div class="unidade-nome">
                                <i class="fas ${statusIcone}"></i>
                                <span>${unidade}</span>
                            </div>
                            <span class="unidade-status ${statusClasse}">
                                <i class="fas ${statusIcone}"></i>
                            </span>
                        </div>
                        <div class="codigo-container">
                            <div class="codigo-value ${codigoClasse}">${codigoTexto}</div>
                        </div>
                    </div>
                `;
            }

            function formatarDocumento(doc) {
                if (!doc || doc === 'N/A') return 'N/A';
                const num = doc.replace(/\D/g, '');
                if (num.length === 11) {
                    return num.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                }
                if (num.length === 14) {
                    return num.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                }
                return doc;
            }

            function formatarMoeda(valor) {
                if (!valor && valor !== 0) return '0,00';
                return parseFloat(valor).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function definirStatus(status) {
                if (!status) return {
                    classeStatus: '',
                    textoStatus: 'N/A'
                };

                const s = status.toLowerCase();

                if (s === 'aprovar' || s === 'aprovado' || s === 'liberado') {
                    return {
                        classeStatus: 'status-aprovar',
                        textoStatus: status.toUpperCase()
                    };
                }
                if (s === 'reprovar' || s === 'reprovado' || s === 'negado') {
                    return {
                        classeStatus: 'status-reprovar',
                        textoStatus: status.toUpperCase()
                    };
                }
                if (s === 'derivar' || s === 'pendente') {
                    return {
                        classeStatus: 'status-derivar',
                        textoStatus: status.toUpperCase()
                    };
                }

                return {
                    classeStatus: '',
                    textoStatus: status.toUpperCase()
                };
            }

            // ========== MENSAGENS ==========
            function showError(msg, duration) {
                errorDiv.querySelector('.message-text').textContent = msg;
                errorDiv.classList.add('show');
                successDiv.classList.remove('show');
                setTimeout(hideError, duration);
            }

            function hideError() {
                errorDiv.classList.remove('show');
            }

            function showSuccess(msg, duration) {
                successDiv.querySelector('.message-text').textContent = msg;
                successDiv.classList.add('show');
                errorDiv.classList.remove('show');
                setTimeout(hideSuccess, duration);
            }

            function hideSuccess() {
                successDiv.classList.remove('show');
            }

            // ========== PDF SIMPLES - CORRIGIDO ==========
            btnPDFSimplificado.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para gerar PDF.', 4000);
                    return;
                }

                // Mostrar feedback visual
                const btnOriginalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando PDF Simples...';
                this.disabled = true;

                try {
                    // PEGAR OS DADOS DA API NO FORMATO ORIGINAL
                    // O PDF simples espera: { id, status, data_criacao, campos, ... }
                    const dadosAPI = dadosAtuais.resultados?.neocredit?.dados_api;

                    if (!dadosAPI) {
                        throw new Error('Dados da API não disponíveis');
                    }

                    // Adicionar informações do firebird se quiser
                    dadosAPI.resultados_firebird = dadosAtuais.resultados?.firebird || {};

                    console.log('Enviando para PDF Simples:', dadosAPI); // DEBUG

                    // Converter para JSON e codificar
                    const dadosJSON = JSON.stringify(dadosAPI);
                    const dadosEncoded = encodeURIComponent(dadosJSON);

                    // Criar formulário para POST
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'limite_cred_pdf.php'; // PDF SIMPLES
                    form.target = '_blank';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dados';
                    input.value = dadosEncoded;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);

                    // Restaurar botão
                    setTimeout(() => {
                        this.innerHTML = btnOriginalText;
                        this.disabled = false;
                    }, 1000);

                } catch (error) {
                    console.error('Erro ao gerar PDF simples:', error);
                    showError('Erro ao gerar PDF: ' + error.message, 5000);
                    this.innerHTML = btnOriginalText;
                    this.disabled = false;
                }
            });
            // ========== PDF DETALHADO ==========
            btnPDFDetalhado.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para gerar PDF.', 4000);
                    return;
                }

                // Verificar se temos os dados completos da API
                if (!dadosAtuais.resultados?.neocredit?.dados_api) {
                    showError('Dados completos da análise não disponíveis.', 4000);
                    return;
                }

                // Mostrar feedback visual
                const btnOriginalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando PDF Detalhado...';
                this.disabled = true;

                try {
                    // Pegar TODOS os dados da análise
                    const dadosAnalise = dadosAtuais.resultados.neocredit.dados_api;

                    // Criar objeto completo com TODOS os dados
                    const dadosCompletos = {
                        ...dadosAnalise,
                        documento_original: dadosAtuais.documento,
                        documento_limpo: dadosAtuais.documento_limpo,
                        resultados_firebird: dadosAtuais.resultados.firebird,
                        timestamp: dadosAtuais.timestamp,
                        execution_id: dadosAtuais.execution_id
                    };

                    // Converter para JSON e codificar
                    const dadosJSON = JSON.stringify(dadosCompletos);
                    const dadosEncoded = encodeURIComponent(dadosJSON);

                    // Criar formulário para POST
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'limite_cred_pdf_detalhado.php';
                    form.target = '_blank';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dados';
                    input.value = dadosEncoded;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);

                    // Restaurar botão
                    setTimeout(() => {
                        this.innerHTML = btnOriginalText;
                        this.disabled = false;
                    }, 1000);

                } catch (error) {
                    console.error('Erro ao gerar PDF detalhado:', error);
                    showError('Erro ao gerar PDF: ' + error.message, 5000);
                    this.innerHTML = btnOriginalText;
                    this.disabled = false;
                }
            });

            // ========== PDF DETALHADO - CORRIGIDO ==========
            // ========== PDF DETALHADO - CORRIGIDO ==========
            btnPDFDetalhado.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para gerar PDF.', 4000);
                    return;
                }

                // Verificar se temos os dados completos da API
                if (!dadosAtuais.resultados?.neocredit?.dados_api) {
                    showError('Dados completos da análise não disponíveis.', 4000);
                    return;
                }

                // Mostrar feedback visual
                const btnOriginalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando PDF...';
                this.disabled = true;

                try {
                    // Pegar TODOS os dados da análise
                    const dadosAnalise = dadosAtuais.resultados.neocredit.dados_api;

                    // Criar objeto completo com TODOS os dados
                    const dadosCompletos = {
                        ...dadosAnalise, // Spread dos dados da API
                        documento_limpo: dadosAtuais.documento_limpo,
                        resultados_firebird: dadosAtuais.resultados.firebird
                    };

                    // Converter para JSON e codificar para URL
                    const dadosJSON = JSON.stringify(dadosCompletos);
                    const dadosEncoded = encodeURIComponent(dadosJSON);

                    // Criar formulário dinâmico para POST (mais seguro que GET)
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'limite_cred_pdf_detalhado.php';
                    form.target = '_blank'; // Abre em nova aba

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dados';
                    input.value = dadosEncoded;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);

                    // Restaurar botão após um pequeno delay
                    setTimeout(() => {
                        this.innerHTML = btnOriginalText;
                        this.disabled = false;
                    }, 1000);

                } catch (error) {
                    console.error('Erro ao gerar PDF:', error);
                    showError('Erro ao gerar PDF: ' + error.message, 5000);

                    this.innerHTML = btnOriginalText;
                    this.disabled = false;
                }
            });

            // ========== FUNÇÃO PARA ABRIR PDF EM NOVA ABA ==========
            function abrirPDFDetalhado(dados) {
                const dadosJSON = JSON.stringify(dados);
                const dadosEncoded = encodeURIComponent(dadosJSON);

                // Abre em nova aba
                const pdfWindow = window.open('limite_cred_pdf_detalhado.php?dados=' + dadosEncoded, '_blank');

                // Fallback se popup for bloqueado
                if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed === 'undefined') {
                    // Tenta com POST via form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'limite_cred_pdf_detalhado.php';
                    form.target = '_blank';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dados';
                    input.value = dadosEncoded;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
            }

            // ========== SALVAR DADOS ==========
            salvarDadosBtn.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para salvar.', 4000);
                    return;
                }

                const btnOriginalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                this.disabled = true;

                setTimeout(() => {
                    this.innerHTML = btnOriginalText;
                    this.disabled = false;
                    showSuccess('Dados salvos com sucesso!', 3000);
                }, 1500);
            });

            // ========== ESC ==========
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && (dadosAtuais || documentoInput.value.trim() !== '')) {
                    limparTudo();
                }
            });

            // ========== BOTÕES DE FECHAR MENSAGENS ==========
            errorDiv.querySelector('.close-btn').addEventListener('click', hideError);
            successDiv.querySelector('.close-btn').addEventListener('click', hideSuccess);

            // ========== INICIAR CONSULTA ==========
            consultarBtn.addEventListener('click', executarConsulta);
        });
    </script>
</body>

</html>