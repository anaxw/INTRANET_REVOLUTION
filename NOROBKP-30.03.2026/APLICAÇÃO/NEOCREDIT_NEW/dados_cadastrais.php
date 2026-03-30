<?php
// limite_cred.php - VERSÃO COMPLETA COM TELEFONE UNIFICADO
// =============================================
// REGRA: Só mostra barra de progresso quando status = "PROCESSANDO"
// Qualquer outro status mostra dados imediatamente
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
// CLASSE PARA API NEOCREDIT
// =============================================
class ApiNeocredit
{
    private static $bearer_token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzbHVnIjoiNmUzOTg2OGQtODUzYy00Zjg4LTgxMWUtMDg4NTI2Njk4MDNhIiwiZW1wcmVzYUlkIjoyNDYsIm5vbWUiOiJFc3RlaXJhIE5vdmEifQ.p0_PAIVn7SmnZDqI0atppDY1Z9V1ehSi2nrkZRp6XEI";

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
    echo json_encode([
        'status' => 'online',
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'api_neocredit_available' => ApiNeocredit::curlDisponivel(),
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
            'neocredit' => null
        ];

        // Verificar se é uma consulta completa (após aguardar)
        $consulta_completa = isset($_POST['consultar_completo']) && $_POST['consultar_completo'] === 'true';
        $id_analise_fornecido = isset($_POST['id_analise']) ? $_POST['id_analise'] : null;

        // CONSULTAR API NEOCREDIT
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

            // Extrair dados detalhados dos campos solicitados
            $dados_detalhados = [
                'id_analise' => $id_analise,
                'razao_social' => 'N/A',
                'cpf' => 'N/A',
                'endereco' => 'N/A',
                'numero' => 'N/A',
                'bairro' => 'N/A',
                'complemento' => 'N/A',
                'cidade' => 'N/A',
                'uf' => 'N/A',
                'cep' => 'N/A',
                'email' => 'N/A',
                'telefone' => 'N/A' // Campo único para telefone com DDD
            ];

            // Extrair RAZÃO SOCIAL do resultado_completo_rf
            if (isset($dados_api['campos']['resultado_completo_rf'])) {
                $rf_json = $dados_api['campos']['resultado_completo_rf'];
                $rf = json_decode($rf_json, true);
                if ($rf && isset($rf['Name'])) {
                    $dados_detalhados['razao_social'] = $rf['Name'];
                }
            }

            // Extrair TODOS os outros dados do resultado_completo_spc
            if (isset($dados_api['campos']['resultado_completo_spc'])) {
                $spc_json = $dados_api['campos']['resultado_completo_spc'];
                $spc = json_decode($spc_json, true);

                if ($spc && isset($spc['consumidor']['consumidor_pessoa_fisica'])) {
                    $consumidor = $spc['consumidor']['consumidor_pessoa_fisica'];

                    // CPF
                    if (isset($consumidor['cpf']['numero'])) {
                        $dados_detalhados['cpf'] = $consumidor['cpf']['numero'];
                    }

                    // ENDEREÇO
                    if (isset($consumidor['endereco'])) {
                        $endereco = $consumidor['endereco'];
                        $dados_detalhados['endereco'] = $endereco['logradouro'] ?? 'N/A';
                        $dados_detalhados['numero'] = $endereco['numero'] ?? 'N/A';
                        $dados_detalhados['bairro'] = $endereco['bairro'] ?? 'N/A';
                        $dados_detalhados['complemento'] = $endereco['complemento'] ?? 'N/A';

                        // CEP (raw para formatar depois)
                        if (isset($endereco['cep'])) {
                            $cep_raw = $endereco['cep'];
                            // Formatar CEP: 88311328 -> 88311-328
                            if (strlen($cep_raw) === 8) {
                                $dados_detalhados['cep'] = substr($cep_raw, 0, 5) . '-' . substr($cep_raw, 5, 3);
                            } else {
                                $dados_detalhados['cep'] = $cep_raw;
                            }
                        }

                        // CIDADE
                        if (isset($endereco['cidade'])) {
                            $dados_detalhados['cidade'] = $endereco['cidade']['nome'] ?? 'N/A';
                            $dados_detalhados['uf'] = $endereco['cidade']['estado']['sigla_uf'] ?? 'N/A';
                        }
                    }

                    // EMAIL
                    if (isset($consumidor['email'])) {
                        $dados_detalhados['email'] = $consumidor['email'];
                    }

                    // TELEFONE (formatado completo com DDD)
                    if (isset($consumidor['telefone_celular'])) {
                        $tel = $consumidor['telefone_celular'];
                        $ddd = $tel['numero_ddd'] ?? '';
                        $numero = $tel['numero'] ?? '';

                        // Formatar telefone completo: (17) 99269-2275
                        if (!empty($ddd) && !empty($numero)) {
                            $num_limpo = preg_replace('/\D/', '', $numero);
                            if (strlen($num_limpo) === 9) { // Celular com 9 dígitos
                                $dados_detalhados['telefone'] = '(' . $ddd . ') ' . substr($num_limpo, 0, 5) . '-' . substr($num_limpo, 5);
                            } elseif (strlen($num_limpo) === 8) { // Telefone fixo
                                $dados_detalhados['telefone'] = '(' . $ddd . ') ' . substr($num_limpo, 0, 4) . '-' . substr($num_limpo, 4);
                            } else {
                                $dados_detalhados['telefone'] = '(' . $ddd . ') ' . $numero;
                            }
                        } else {
                            $dados_detalhados['telefone'] = 'N/A';
                        }
                    } else {
                        $dados_detalhados['telefone'] = 'N/A';
                    }
                }
            }

            $resultados['neocredit'] = [
                'id_analise' => $id_analise,
                'dados_api' => $dados_api,
                'dados_formatados' => $dados_formatados,
                'dados_detalhados' => $dados_detalhados,
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
                ],
                'dados_detalhados' => [
                    'id_analise' => 'N/A',
                    'razao_social' => 'N/A',
                    'cpf' => $documento,
                    'endereco' => 'N/A',
                    'numero' => 'N/A',
                    'bairro' => 'N/A',
                    'complemento' => 'N/A',
                    'cidade' => 'N/A',
                    'uf' => 'N/A',
                    'cep' => 'N/A',
                    'email' => 'N/A',
                    'telefone' => 'N/A'
                ]
            ];
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
    <title>NOROAÇO - Consulta Neocredit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="neocredit.css">
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <div class="logo-noroaco">
                <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco" style="height: 50px;">
            </div>
            <div class="botoes-direita">
                <button type="button" class="btn-completo" id="btn-pdf-simplificado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PF
                </button>
                <button type="button" class="btn-resumo" id="btn-pdf-detalhado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PJ
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
                <i class="fa-solid fa-coins"></i> Consulta de Dados Cadastrais - Neocredit
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
                        <button id="limpar-btn2" class="btn-header">
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
                <div class="api-section" id="result-container">
                    <div class="result-box">
                        <div class="result-header">
                            <i class="fas fa-chart-line"></i>
                            <h3>Dados do Cliente - Neocredit</h3>
                        </div>
                        <div class="result-content" id="neocreditContent"></div>
                    </div>
                </div>

                <div class="api-section-salvar-btn-container" id="save-section" style="display: none;">
                    <button class="btn-salvar-db" id="salvar-dados-btn">
                        <i class="fas fa-database"></i> Salvar no Banco de Dados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // [SEU CÓDIGO JAVASCRIPT PERMANECE O MESMO]
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

                fetch(window.location.href.split('?')[0] + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store',
                        headers: {
                            'Cache-Control': 'no-cache'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Erro desconhecido');
                        }

                        const neocredit = data.resultados?.neocredit;

                        if (!neocredit) {
                            throw new Error('Estrutura de dados inválida');
                        }

                        const statusPrincipal = neocredit?.dados_formatados?.status_principal || '';

                        // REGRA DE OURO: Só continua com barra se status = PROCESSANDO
                        if (statusPrincipal.toUpperCase() === 'PROCESSANDO') {
                            loadingText.textContent = 'Processando análise de crédito...';
                            loadingDetails.textContent = 'A análise pode levar até 5 minutos. Aguarde...';

                            if (neocredit?.id_analise) {
                                iniciarVerificacaoPeriodica(neocredit.id_analise);
                            }
                        } else {
                            // QUALQUER outro status - MOSTRA OS DADOS IMEDIATAMENTE!
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

            // ========== VERIFICAÇÃO PERIÓDICA ==========
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

                    fetch(window.location.href.split('?')[0] + '?action=consulta_status&id=' + idAnalise + '&nocache=' + Date.now(), {
                            cache: 'no-store'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Se NÃO está mais processando (status mudou)
                                if (!data.esta_processando) {
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

            // ========== BUSCAR DADOS COMPLETOS ==========
            function buscarDadosCompletos(idAnalise) {
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

                fetch(window.location.href.split('?')[0] + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Erro ao buscar dados');
                        }

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

                resultSection.style.display = 'block';
                resultadoContainer.style.display = 'block';

                btnPDFSimplificado.style.display = 'inline-flex';
                btnPDFDetalhado.style.display = 'inline-flex';
                saveSection.style.display = 'block';

                // Habilitar os botões PDF
                btnPDFSimplificado.classList.add('enabled');
                btnPDFDetalhado.classList.add('enabled');
            }
            // ========== EXIBIR NEOCREDIT COM OS 12 CAMPOS (SEM DDD SEPARADO E SEM STATUS) ==========
            function exibirNeocredit(dadosNeocredit) {
                let html = '';

                if (dadosNeocredit && dadosNeocredit.sucesso) {
                    // Pegar os dados detalhados extraídos no PHP
                    const dados = dadosNeocredit.dados_detalhados || {};
                    const dadosFormatados = dadosNeocredit.dados_formatados || {};

                    html = '<div class="linha-resultados">';

                    // LINHA 1: ID ANÁLISE | RAZÃO SOCIAL | CPF
                    html += criarColunaResultado('ID ANÁLISE', dados.id_analise || 'N/A', 'fingerprint');
                    html += criarColunaResultado('RAZÃO SOCIAL', dados.razao_social || 'N/A', 'building');
                    html += criarColunaResultado('CPF', formatarDocumento(dados.cpf), 'id-card');
                    html += '</div><div class="linha-resultados">';

                    // LINHA 2: ENDEREÇO | Nº | BAIRRO
                    html += criarColunaResultado('ENDEREÇO', dados.endereco || 'N/A', 'map-marker-alt');
                    html += criarColunaResultado('Nº', dados.numero || 'N/A', 'hashtag');
                    html += criarColunaResultado('BAIRRO', dados.bairro || 'N/A', 'map-pin');
                    html += '</div><div class="linha-resultados">';

                    // LINHA 3: COMPLEMENTO | CIDADE/UF | CEP
                    html += criarColunaResultado('COMPLEMENTO', dados.complemento || 'N/A', 'home');

                    // Cidade e UF juntos
                    const cidadeUF = dados.cidade !== 'N/A' ? dados.cidade + (dados.uf !== 'N/A' ? ' - ' + dados.uf : '') : 'N/A';
                    html += criarColunaResultado('CIDADE', cidadeUF, 'city');

                    html += criarColunaResultado('CEP', dados.cep || 'N/A', 'envelope');
                    html += '</div><div class="linha-resultados">';

                    // LINHA 4: EMAIL | TELEFONE (já com DDD) | SCORE/RISCO
                    html += criarColunaResultado('EMAIL', dados.email || 'N/A', 'at');
                    html += criarColunaResultado('TELEFONE', dados.telefone || 'N/A', 'phone-alt');

                    // Mostrar Score ou Risco
                    let valorScoreRisco = 'N/A';
                    if (dadosFormatados.score && dadosFormatados.score !== 'N/A') {
                        valorScoreRisco = dadosFormatados.score;
                    } else if (dadosFormatados.risco && dadosFormatados.risco !== 'N/A') {
                        valorScoreRisco = dadosFormatados.risco;
                    } else if (dadosFormatados.classificacao_risco && dadosFormatados.classificacao_risco !== 'N/A') {
                        valorScoreRisco = dadosFormatados.classificacao_risco;
                    }

                    html += criarColunaResultado('SCORE/RISCO', valorScoreRisco, 'chart-line');
                    html += '</div>';

                    // NÃO INCLUIR MAIS O STATUS - REMOVIDO COMPLETAMENTE

                } else {
                    html = `
            <div class="linha-resultados">
                ${criarColunaResultado('Status', 'ERRO NA CONSULTA', 'exclamation-circle')}
            </div>
            <div class="linha-resultados">
                ${dadosNeocredit?.erro ? criarColunaResultado('Erro', dadosNeocredit.erro, 'exclamation-triangle') : ''}
            </div>
        `;
                }

                neocreditContent.innerHTML = html;
            }

            // ========== FUNÇÕES AUXILIARES ==========
            function criarColunaResultado(rotulo, valor, icone) {
                // Tratar valores nulos ou undefined
                const valorExibido = (valor === null || valor === undefined || valor === '') ? 'N/A' : valor;

                return `
                    <div class="coluna-resultado">
                        <div class="info-label">
                            <i class="fas fa-${icone}"></i>
                            <span>${rotulo}</span>
                        </div>
                        <div class="info-value">${valorExibido}</div>
                    </div>
                `;
            }

            function formatarDocumento(doc) {
                if (!doc || doc === 'N/A' || doc === '') return 'N/A';
                const num = doc.replace(/\D/g, '');
                if (num.length === 11) {
                    return num.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                }
                if (num.length === 14) {
                    return num.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                }
                return doc;
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

            // ========== PDF SIMPLES ==========
            btnPDFSimplificado.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para gerar PDF.', 4000);
                    return;
                }

                try {
                    const dadosAPI = dadosAtuais.resultados?.neocredit?.dados_api;

                    if (!dadosAPI) {
                        throw new Error('Dados da API não disponíveis');
                    }

                    const dadosJSON = JSON.stringify(dadosAPI);
                    const dadosEncoded = encodeURIComponent(dadosJSON);

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'limite_cred_pdf.php';
                    form.target = '_blank';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dados';
                    input.value = dadosEncoded;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);

                } catch (error) {
                    console.error('Erro ao gerar PDF simples:', error);
                    showError('Erro ao gerar PDF: ' + error.message, 5000);
                }
            });

            // ========== PDF DETALHADO ==========
            btnPDFDetalhado.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para gerar PDF.', 4000);
                    return;
                }

                if (!dadosAtuais.resultados?.neocredit?.dados_api) {
                    showError('Dados completos da análise não disponíveis.', 4000);
                    return;
                }

                try {
                    const dadosAnalise = dadosAtuais.resultados.neocredit.dados_api;

                    const dadosCompletos = {
                        ...dadosAnalise,
                        documento_original: dadosAtuais.documento,
                        documento_limpo: dadosAtuais.documento_limpo,
                        timestamp: dadosAtuais.timestamp,
                        execution_id: dadosAtuais.execution_id,
                        dados_detalhados: dadosAtuais.resultados.neocredit.dados_detalhados
                    };

                    const dadosJSON = JSON.stringify(dadosCompletos);
                    const dadosEncoded = encodeURIComponent(dadosJSON);

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

                } catch (error) {
                    console.error('Erro ao gerar PDF detalhado:', error);
                    showError('Erro ao gerar PDF: ' + error.message, 5000);
                }
            });

            // ========== SALVAR DADOS NO BANCO ==========
            salvarDadosBtn.addEventListener('click', function() {
                if (!dadosAtuais) {
                    showError('Nenhum dado para salvar.', 4000);
                    return;
                }

                const neocredit = dadosAtuais.resultados?.neocredit;
                const dadosAPI = neocredit?.dados_api;
                const dadosFormatados = neocredit?.dados_formatados;
                const dadosDetalhados = neocredit?.dados_detalhados || {};

                if (!dadosFormatados || !dadosAPI) {
                    showError('Dados do Neocredit não disponíveis.', 4000);
                    return;
                }

                // CAPTURAR O STATUS DA CHAVE 'campos'
                const statusCampos = dadosAPI?.campos?.status ||
                    dadosAPI?.status ||
                    dadosFormatados.status ||
                    'CONSULTADO';

                // Mostrar loading
                const btnOriginalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando no banco...';
                this.disabled = true;

                // Preparar dados para envio
                const formData = new FormData();
                formData.append('action', 'save_to_db');
                formData.append('documento', dadosAtuais.documento || '');
                formData.append('id_analise', neocredit?.id_analise || '');
                formData.append('razao_social', dadosDetalhados.razao_social || dadosFormatados.razao_social || '');
                formData.append('cpf', dadosDetalhados.cpf || dadosFormatados.documento || '');
                formData.append('endereco', dadosDetalhados.endereco || '');
                formData.append('numero', dadosDetalhados.numero || '');
                formData.append('bairro', dadosDetalhados.bairro || '');
                formData.append('complemento', dadosDetalhados.complemento || '');
                formData.append('cidade', dadosDetalhados.cidade || '');
                formData.append('uf', dadosDetalhados.uf || '');
                formData.append('cep', dadosDetalhados.cep || '');
                formData.append('email', dadosDetalhados.email || '');
                formData.append('telefone', dadosDetalhados.telefone || '');

                // Campos de crédito
                formData.append('risco', dadosFormatados.risco || '');
                formData.append('score', dadosFormatados.score || '');
                formData.append('classificacao_risco', dadosFormatados.classificacao_risco || '');
                formData.append('lcred_aprovado', dadosFormatados.limite_aprovado || '0');
                formData.append('validade_cred', dadosFormatados.data_validade || '');
                formData.append('status', statusCampos);

                // Valores padrão para campos do Firebird
                formData.append('codic_bm', '');
                formData.append('codic_bot', '');
                formData.append('codic_ls', '');
                formData.append('codic_rp', '');
                formData.append('codic_vt_rnd', '');

                // Enviar para o backend
                fetch('dados_cadastrais_save.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message || '✅ Dados salvos com sucesso!', 4000);
                        } else {
                            showError('❌ ' + (data.message || 'Erro ao salvar dados'), 5000);
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao salvar:', error);
                        showError('❌ Erro na comunicação com o servidor', 5000);
                    })
                    .finally(() => {
                        // Restaurar botão
                        this.innerHTML = btnOriginalText;
                        this.disabled = false;
                    });
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