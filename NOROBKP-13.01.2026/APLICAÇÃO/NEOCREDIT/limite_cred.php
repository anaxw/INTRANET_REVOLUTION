<?php
// Configurações de segurança e estabilidade
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
ini_set('max_execution_time', 300);
ini_set('default_socket_timeout', 30);
ini_set('memory_limit', '512M');

class ApiNeocredit
{
    private static $bearer_token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzbHVnIjoiOTg0ZmZlMTItNTM0Ni00YWVkLWEzYjgtOTQwOTA3YjNmNTRkIiwiZW1wcmVzYUlkIjoyNDYsIm5vbWUiOiJUb2tlbiBOb3JvYVx1MDBlN28ifQ._u50D_NAGRW3AawoTHJ5MLExI1QQUHc4NjxFAOMQpLs";

    // Cache para evitar consultas repetidas
    private static $cache_analises = array();
    private static $last_request_time = 0;

    // Verificar se cURL está disponível
    public static function curlDisponivel()
    {
        return function_exists('curl_init');
    }

    // Função robusta de requisição HTTP
    private static function fazerRequisicao($url, $method = 'GET', $data = null, $timeout = 30)
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
            CURLOPT_CONNECTTIMEOUT => 10,
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
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Erro ao codificar JSON: " . json_last_error_msg());
                }
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

        error_log("API Request: {$method} {$url} - HTTP: {$http_code} - Response: " . substr($response, 0, 500));

        if ($curl_errno) {
            throw new Exception("Erro cURL ({$curl_errno}): {$error}");
        }

        if ($http_code >= 400) {
            $error_msg = "HTTP {$http_code}";
            if ($response) {
                $error_data = @json_decode($response, true);
                if (isset($error_data['message'])) {
                    $error_msg .= " - " . $error_data['message'];
                } elseif (isset($error_data['detail'])) {
                    $error_msg .= " - " . $error_data['detail'];
                }
            }
            throw new Exception($error_msg);
        }

        if (!$response) {
            throw new Exception("Resposta vazia da API");
        }

        $data = @json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Resposta JSON inválida: " . json_last_error_msg());
        }

        return $data;
    }

    // Iniciar análise na API
    public static function iniciarAnalise($documento)
    {
        $documento_limpo = preg_replace('/[^0-9]/', '', $documento);

        if (empty($documento_limpo)) {
            throw new Exception("Documento inválido");
        }

        $cache_key = 'iniciar_' . $documento_limpo;
        if (
            isset(self::$cache_analises[$cache_key]) &&
            (time() - self::$cache_analises[$cache_key]['time']) < 300
        ) {
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2722/integracao';

        try {
            $data = self::fazerRequisicao($url, 'POST', array('documento' => $documento_limpo), 60);
            
            if (!isset($data['id'])) {
                error_log("Resposta da API ao iniciar análise: " . print_r($data, true));
                throw new Exception("ID da análise não retornado pela API");
            }

            self::$cache_analises[$cache_key] = array(
                'data' => $data['id'],
                'time' => time()
            );

            return $data['id'];
        } catch (Exception $e) {
            error_log("Erro ao iniciar análise para documento {$documento_limpo}: " . $e->getMessage());
            throw $e;
        }
    }

    // Consultar status da análise
    public static function consultarAnalise($id_analise)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $cache_key = 'consulta_' . $id_analise;
        if (
            isset(self::$cache_analises[$cache_key]) &&
            (time() - self::$cache_analises[$cache_key]['time']) < 30
        ) {
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/' . $id_analise . '/simplificada';

        try {
            $data = self::fazerRequisicao($url, 'GET', null, 30);
            
            self::$cache_analises[$cache_key] = array(
                'data' => $data,
                'time' => time()
            );

            return $data;
        } catch (Exception $e) {
            error_log("Erro ao consultar análise {$id_analise}: " . $e->getMessage());
            throw $e;
        }
    }

    // Aguardar resultado
    public static function aguardarResultado($id_analise, $max_tentativas = 8, $intervalo_base = 10)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $tentativa = 0;
        $ultima_resposta = null;

        while ($tentativa < $max_tentativas) {
            try {
                $tentativa++;
                error_log("Tentativa {$tentativa}/{$max_tentativas} para análise {$id_analise}");

                $dados = self::consultarAnalise($id_analise);
                $ultima_resposta = $dados;

                // Verificar se tem dados importantes
                if (isset($dados['campos']['limite_aprovado']) && !empty($dados['campos']['limite_aprovado'])) {
                    error_log("Limite encontrado na tentativa {$tentativa}: " . $dados['campos']['limite_aprovado']);
                    return $dados;
                }

                // Verificar se há algum status que indique conclusão
                if (isset($dados['campos']['status']) && !empty($dados['campos']['status'])) {
                    $status = strtolower($dados['campos']['status']);
                    if (in_array($status, ['derivar', 'aprovar', 'reprovar', 'liberado', 'negado'])) {
                        error_log("Status final encontrado na tentativa {$tentativa}: " . $status);
                        return $dados;
                    }
                }

                if ($tentativa < $max_tentativas) {
                    $espera = $intervalo_base * $tentativa;
                    error_log("Aguardando {$espera} segundos para próxima tentativa...");
                    sleep($espera);
                }
            } catch (Exception $e) {
                error_log("Erro na tentativa {$tentativa}: " . $e->getMessage());
                if ($tentativa >= $max_tentativas) {
                    if ($ultima_resposta) {
                        return $ultima_resposta;
                    }
                    throw $e;
                }
                sleep($intervalo_base);
            }
        }

        // Se chegou aqui, retorna a última resposta mesmo sem limite
        error_log("Retornando última resposta após {$max_tentativas} tentativas");
        return $ultima_resposta ?: self::consultarAnalise($id_analise);
    }

    // Função para extrair dados formatados da análise
    public static function extrairDadosFormatados($dados_analise)
    {
        if (!is_array($dados_analise) || !isset($dados_analise['campos'])) {
            error_log("Dados de análise inválidos para extração: " . print_r($dados_analise, true));
            return array();
        }

        $campos = $dados_analise['campos'];

        return array(
            'razao_social' => isset($campos['razao']) ? $campos['razao'] : (isset($campos['razao_social']) ? $campos['razao_social'] : ''),
            'documento' => isset($campos['documento']) ? $campos['documento'] : '',
            'risco' => isset($campos['risco']) ? $campos['risco'] : '',
            'score' => isset($campos['score']) ? $campos['score'] : '',
            'classificacao_risco' => isset($campos['classificacao_risco']) ? $campos['classificacao_risco'] : '',
            'limite_aprovado' => isset($campos['limite_aprovado']) ? floatval(str_replace(['R$', '.', ',', ' '], ['', '', '.', ''], $campos['limite_aprovado'])) : 0,
            'data_validade' => isset($campos['data_validade_limite_credito']) ? $campos['data_validade_limite_credito'] : (isset($campos['data_validade']) ? $campos['data_validade'] : ''),
            'status' => isset($campos['status']) ? $campos['status'] : (isset($dados_analise['status']) ? $dados_analise['status'] : 'N/A')
        );
    }
}

// Processar requisições AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== INÍCIO PROCESSAMENTO POST ===");
    error_log("POST Data: " . print_r($_POST, true));
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        if (isset($_POST['documento']) && !empty($_POST['documento'])) {
            // Limpar e validar documento
            $documento = preg_replace('/[^0-9]/', '', $_POST['documento']);
            error_log("Documento processado: {$documento}");
            
            if (empty($documento) || (strlen($documento) !== 11 && strlen($documento) !== 14)) {
                throw new Exception("Documento inválido. CPF precisa ter 11 dígitos, CNPJ 14 dígitos.");
            }
            
            // Verificar se cURL está disponível
            if (!function_exists('curl_init')) {
                throw new Exception("cURL não está disponível no servidor. Contate o administrador.");
            }
            
            // Testar conexão com a API
            $test_connection = @fsockopen('ssl://app-api.neocredit.com.br', 443, $errno, $errstr, 10);
            if (!$test_connection) {
                error_log("Erro de conexão com API: $errstr ($errno)");
            } else {
                fclose($test_connection);
                error_log("Conexão com API testada com sucesso");
            }
            
            // Iniciar análise na API
            error_log("Iniciando análise para documento: {$documento}");
            $id_analise = ApiNeocredit::iniciarAnalise($documento);
            error_log("ID da análise obtido: {$id_analise}");
            
            if (empty($id_analise)) {
                throw new Exception("Não foi possível iniciar a análise. ID não retornado.");
            }
            
            // Aguardar e obter resultado
            error_log("Aguardando resultado da análise...");
            $dados_analise = ApiNeocredit::aguardarResultado($id_analise);
            error_log("Dados da análise recebidos: " . (!empty($dados_analise) ? 'SIM' : 'NÃO'));
            
            if (!$dados_analise) {
                throw new Exception("Não foi possível obter resultado da análise após múltiplas tentativas.");
            }
            
            // Extrair dados formatados
            $dados_formatados = ApiNeocredit::extrairDadosFormatados($dados_analise);
            error_log("Dados formatados: " . print_r($dados_formatados, true));
            
            echo json_encode([
                'success' => true,
                'id_analise' => $id_analise,
                'dados_api' => $dados_analise,
                'dados_formatados' => $dados_formatados,
                'documento' => $documento,
                'mensagem' => 'Consulta realizada com sucesso'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
        } else {
            throw new Exception("Documento não informado");
        }
        
    } catch (Exception $e) {
        error_log("ERRO no processamento: " . $e->getMessage());
        echo json_encode([
            'error' => $e->getMessage(),
            'success' => false,
            'trace' => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE);
    }
    
    error_log("=== FIM PROCESSAMENTO POST ===");
    exit;
}

// Se não for POST, continuar exibindo a página HTML normalmente
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOROAÇO - Consulta de Limite de Crédito</title>
    <link rel="stylesheet" type="text/css" href="limite_cred_style.css">
    <style>
        /* Estilos para o botão salvar e mensagens */
        .save-section {
            margin: 25px 0;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            text-align: center;
        }

        .save-btn {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }

        .save-btn:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(40, 167, 69, 0.3);
        }

        .save-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .save-btn:active:not(:disabled) {
            transform: translateY(0);
        }

        .save-message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .save-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .save-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .save-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="download-btn-container">
            <button class="download-btn" id="download-btn" title="Baixar relatório da consulta" disabled>
                📥
            </button>
        </div>

        <header>
            <img src="imgs/logo.png" alt="Logo" class="logo">
            <h1>Consulta Limite de Crédito - Neocredit</h1>
        </header>

        <div class="content">
            <div class="form-section">
                <label for="documento">CPF/CNPJ:</label>
                <input type="text" id="documento" placeholder="Digite o CPF ou CNPJ (com ou sem formatação)">
                <div class="button-group">
                    <button id="consultar-btn">🔍 Consultar</button>
                </div>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p id="loading-text">Consultando dados, aguarde...</p>
                <div class="progress-container">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
                <div class="progress-text" id="progress-text">Processando...</div>
            </div>

            <div class="error-message" id="error-message"></div>
            <div class="success-message" id="success-message"></div>

            <div class="result-section" id="result-section">
                <div id="api-grid"></div>
                <!-- Botão para salvar dados -->
                <div class="save-section" id="save-section" style="display: none;">
                    <button id="save-btn" class="save-btn" disabled>
                        💾 Salvar Dados no Banco
                    </button>
                    <div id="save-message" class="save-message"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========== VARIÁVEIS GLOBAIS ==========
            var documentoInput = document.getElementById('documento');
            var consultarBtn = document.getElementById('consultar-btn');
            var loadingDiv = document.getElementById('loading');
            var loadingText = document.getElementById('loading-text');
            var progressBar = document.getElementById('progress-bar');
            var progressText = document.getElementById('progress-text');
            var errorDiv = document.getElementById('error-message');
            var successDiv = document.getElementById('success-message');
            var resultSection = document.getElementById('result-section');
            var apiGrid = document.getElementById('api-grid');
            var downloadBtn = document.getElementById('download-btn');
            var saveBtn = document.getElementById('save-btn');
            var saveSection = document.getElementById('save-section');
            var saveMessage = document.getElementById('save-message');

            var progressInterval;
            var startTime;
            var dadosAtuais = null;

            // ========== FUNÇÕES DE INICIALIZAÇÃO ==========
            inicializarEventListeners();

            function inicializarEventListeners() {
                if (consultarBtn) consultarBtn.addEventListener('click', executarConsulta);
                if (documentoInput) documentoInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') executarConsulta();
                });
                if (downloadBtn) downloadBtn.addEventListener('click', baixarRelatorio);
                if (saveBtn) saveBtn.addEventListener('click', salvarNoBanco);

                // Máscara CPF/CNPJ
                if (documentoInput) {
                    documentoInput.addEventListener('input', function(e) {
                        var value = e.target.value.replace(/\D/g, '');
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
                }
            }

            // ========== FUNÇÃO EXECUTAR CONSULTA ==========
            function executarConsulta() {
                var documento = documentoInput.value.replace(/\D/g, '');
                if (!documento) return showError('Digite um CPF ou CNPJ válido.');
                if (documento.length !== 11 && documento.length !== 14)
                    return showError('CPF deve ter 11 dígitos e CNPJ 14 dígitos.');

                documentoInput.disabled = true;
                consultarBtn.disabled = true;
                downloadBtn.disabled = true;
                saveBtn.disabled = true;
                loadingDiv.style.display = 'flex';
                resultSection.style.display = 'none';
                saveSection.style.display = 'none';
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                saveMessage.innerHTML = '';
                saveMessage.className = '';
                loadingText.textContent = 'Consultando dados, aguarde...';

                iniciarProgresso();

                var formData = new FormData();
                formData.append('documento', documentoInput.value);

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Erro na rede: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;

                        if (data.error) {
                            showError('Erro: ' + data.error);
                            return;
                        }

                        if (!data.success) {
                            showError('Consulta não foi bem sucedida: ' + (data.message || 'Erro desconhecido'));
                            return;
                        }

                        dadosAtuais = data;
                        exibirResultados(data);
                        downloadBtn.disabled = false;
                        saveBtn.disabled = false;
                        saveSection.style.display = 'block';
                    })
                    .catch(function(error) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;
                        showError('Erro na requisição: ' + error.message);
                        console.error('Erro detalhado:', error);
                    });
            }

            function iniciarProgresso() {
                startTime = new Date().getTime();
                var maxTime = 2 * 60 * 1000;

                progressInterval = setInterval(function() {
                    var currentTime = new Date().getTime();
                    var elapsed = currentTime - startTime;
                    var progress = Math.min((elapsed / maxTime) * 100, 100);

                    progressBar.style.width = progress + '%';

                    var minutes = Math.floor(elapsed / 60000);
                    var seconds = Math.floor((elapsed % 60000) / 1000);

                    progressText.textContent = 'Processando... ' +
                        minutes.toString().padStart(2, '0') + ':' +
                        seconds.toString().padStart(2, '0') + ' decorridos';

                    if (elapsed > maxTime - 10000) {
                        loadingText.textContent = 'Processo está demorando mais que o normal...';
                    }

                }, 1000);
            }

            function pararProgresso() {
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                progressBar.style.width = '0%';
                progressText.textContent = 'Processando...';
            }

            // ========== EXIBIR RESULTADOS ==========
            function exibirResultados(data) {
                resultSection.style.display = 'block';
                apiGrid.innerHTML = '';

                // Dados da API
                var dadosExibir = data.dados_formatados || (data.dados_api && data.dados_api.campos) || {};

                // Card API
                var apiCard = document.createElement('div');
                apiCard.className = 'api-section';

                var html = '<h3> Resultado da Análise - Neocredit <br></h3>';
                html += '<div class="info-grid">';

                html += '<div class="info-item"><span class="info-label">ID Análise:</span><span class="info-value">' + (data.id_analise || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Razão Social:</span><span class="info-value">' + (dadosExibir.razao_social || dadosExibir.razao || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Documento:</span><span class="info-value">' + formatarDocumento(dadosExibir.documento || '') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Risco:</span><span class="info-value">' + (dadosExibir.risco || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Classificação de Risco:</span><span class="info-value">' + (dadosExibir.classificacao_risco || 'N/A') + '</span></div>';

                var limiteTotal = parseFloat(dadosExibir.limite_aprovado || 0);
                html += '<div class="info-item"><span class="info-label">Limite Aprovado:</span><span class="info-value">R$ ' + formatarMoeda(limiteTotal) + '</span></div>';

                html += '<div class="info-item"><span class="info-label">Data Validade:</span><span class="info-value">' + (dadosExibir.data_validade || 'N/A') + '</span></div>';

                var status = dadosExibir.status || '';
                var statusInfo = definirStatus(status);
                html += '<div class="info-item"><span class="info-label">Status:</span><span class="info-value"><span class="status-container ' + statusInfo.classeStatus + '">' + statusInfo.textoStatus + '</span></span></div>';

                if (dadosExibir.score) {
                    var scoreNumero = extrairScoreNumerico(dadosExibir.score);
                    if (scoreNumero !== null) {
                        html += '</div>';
                        html += '<div class="score-container">' + criarVisualizacaoScore(scoreNumero) + '</div>';
                    } else {
                        html += '</div>';
                    }
                } else {
                    html += '</div>';
                }

                apiCard.innerHTML = html;
                apiGrid.appendChild(apiCard);

                showSuccess('Consulta realizada com sucesso! Clique em "Salvar Dados no Banco" para armazenar os resultados.');
            }

            // ========== FUNÇÃO SALVAR NO BANCO ==========
            function salvarNoBanco() {
                if (!dadosAtuais || !dadosAtuais.dados_formatados) {
                    showError('Nenhum dado disponível para salvar.');
                    return;
                }
                
                saveBtn.disabled = true;
                saveMessage.innerHTML = '<span style="display: flex; align-items: center; gap: 8px;">⏳ Salvando dados no banco...</span>';
                saveMessage.className = 'save-info';
                
                var dados = dadosAtuais.dados_formatados;
                
                // Preparar os dados para envio
                var formData = new FormData();
                formData.append('action', 'save_to_db');
                formData.append('id_analise', dadosAtuais.id_analise || '');
                formData.append('razao_social', dados.razao_social || '');
                formData.append('documento', dados.documento || '');
                formData.append('risco', dados.risco || '');
                formData.append('classificacao_risco', dados.classificacao_risco || '');
                formData.append('lcred_aprovado', dados.limite_aprovado || 0);
                formData.append('validade_cred', dados.data_validade || '');
                formData.append('status', dados.status || 'CONSULTADO');
                
                // Enviar para o script PHP
                fetch('limite_cred_save.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Erro na rede: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        saveMessage.innerHTML = '<span style="display: flex; align-items: center; gap: 8px;">✅ ' + data.message + '</span>';
                        saveMessage.className = 'save-success';
                        
                        // Atualizar o botão para indicar que já foi salvo
                        saveBtn.innerHTML = '💾 Dados Salvos';
                        saveBtn.style.backgroundColor = '#6c757d';
                        
                        // Opcional: desabilitar completamente o botão após salvar
                        setTimeout(function() {
                            saveBtn.disabled = true;
                        }, 3000);
                    } else {
                        saveMessage.innerHTML = '<span style="display: flex; align-items: center; gap: 8px;">❌ ' + data.message + '</span>';
                        saveMessage.className = 'save-error';
                        saveBtn.disabled = false;
                    }
                })
                .catch(function(error) {
                    saveMessage.innerHTML = '<span style="display: flex; align-items: center; gap: 8px;">❌ Erro ao conectar com o servidor: ' + error.message + '</span>';
                    saveMessage.className = 'save-error';
                    saveBtn.disabled = false;
                    console.error('Erro detalhado:', error);
                });
            }

            // ========== FUNÇÃO BAIXAR RELATÓRIO ==========
            function baixarRelatorio() {
                if (!dadosAtuais) return;
                
                var dados = dadosAtuais.dados_formatados || {};
                
                // Criar conteúdo do relatório
                var conteudo = 'RELATÓRIO DE CONSULTA DE CRÉDITO - NOROAÇO\n';
                conteudo += '============================================\n\n';
                conteudo += 'DATA: ' + new Date().toLocaleString('pt-BR') + '\n';
                conteudo += 'ID ANÁLISE: ' + (dadosAtuais.id_analise || 'N/A') + '\n';
                conteudo += 'DOCUMENTO: ' + formatarDocumento(dados.documento || '') + '\n';
                conteudo += 'RAZÃO SOCIAL: ' + (dados.razao_social || 'N/A') + '\n';
                conteudo += 'LIMITE APROVADO: R$ ' + formatarMoeda(dados.limite_aprovado || 0) + '\n';
                conteudo += 'DATA VALIDADE: ' + (dados.data_validade || 'N/A') + '\n';
                conteudo += 'STATUS: ' + (dados.status || 'N/A') + '\n';
                conteudo += 'RISCO: ' + (dados.risco || 'N/A') + '\n';
                conteudo += 'SCORE: ' + (dados.score || 'N/A') + '\n';
                conteudo += 'CLASSIFICAÇÃO DE RISCO: ' + (dados.classificacao_risco || 'N/A') + '\n\n';
                
                conteudo += 'Consulta realizada via API Neocredit\n';
                conteudo += 'Sistema NOROAÇO - ' + new Date().getFullYear();
                
                // Criar arquivo e baixar
                var blob = new Blob([conteudo], { type: 'text/plain;charset=utf-8' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'relatorio_credito_' + (dados.documento || 'consulta') + '_' + new Date().toISOString().slice(0, 10) + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                showSuccess('Relatório baixado com sucesso!');
            }

            // ========== FUNÇÕES AUXILIARES ==========
            function criarVisualizacaoScore(score) {
                var percentual = (score / 1000) * 100;
                return '<div class="score-header">' +
                    '<div class="score-title">Score</div>' +
                    '<div class="score-value">' + score + '/1000</div>' +
                    '</div>' +
                    '<div class="score-bar-container">' +
                    '<div class="score-bar-fill" style="width: ' + percentual + '%"></div>' +
                    '</div>' +
                    '<div class="score-bar-labels">' +
                    '<span>0</span>' +
                    '<span>250</span>' +
                    '<span>500</span>' +
                    '<span>750</span>' +
                    '<span>1000</span>' +
                    '</div>';
            }

            function extrairScoreNumerico(score) {
                if (!score) return null;
                var scoreNumero = parseFloat(score.toString().replace(/[^\d.,]/g, '').replace(',', '.'));
                if (isNaN(scoreNumero)) return null;
                return Math.min(Math.round(scoreNumero), 1000);
            }

            function definirStatus(status) {
                if (!status) return {
                    classeStatus: '',
                    textoStatus: 'N/A'
                };

                var s = status.toLowerCase();
                var classeStatus = '';
                var textoStatus = status.toUpperCase();

                if (s === 'derivar' || s.includes('deriv')) {
                    classeStatus = 'status-derivar';
                    textoStatus = 'DERIVAR';
                } else if (s === 'aprovar' || s === 'liberado' || s.includes('aprov')) {
                    classeStatus = 'status-aprovar';
                    textoStatus = 'APROVADO';
                } else if (s === 'reprovar' || s === 'negado' || s.includes('reprov')) {
                    classeStatus = 'status-reprovar';
                    textoStatus = 'REPROVADO';
                } else if (['pendente', 'analisar', 'aguardar', 'processando'].indexOf(s) !== -1) {
                    classeStatus = 'status-derivar';
                    textoStatus = 'EM ANÁLISE';
                }

                return {
                    classeStatus: classeStatus,
                    textoStatus: textoStatus
                };
            }

            function showError(msg) {
                errorDiv.innerHTML = '<div style="display: flex; align-items: center; gap: 10px;">❌ ' + msg + '</div>';
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                errorDiv.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }

            function showSuccess(msg) {
                successDiv.innerHTML = '<div style="display: flex; align-items: center; gap: 10px;">✅ ' + msg + '</div>';
                successDiv.style.display = 'block';
                errorDiv.style.display = 'none';
                successDiv.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }

            function formatarDocumento(doc) {
                if (!doc) return '';
                var num = doc.replace(/\D/g, '');
                if (num.length === 11)
                    return num.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                if (num.length === 14)
                    return num.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                return doc;
            }

            function formatarMoeda(v) {
                if (!v && v !== 0) return '0,00';
                return parseFloat(v).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        });
    </script>
</body>

</html>