<?php
// apenas_api_neocredit.php - Versão completa e funcional da API Neocredit

// Headers para prevenir cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Expires: 0');

// Configurações de segurança e debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors_neocredit.log');
ini_set('max_execution_time', 300);
ini_set('default_socket_timeout', 60);
ini_set('memory_limit', '512M');

// Gerar ID único para cada execução
$execution_id = uniqid('neocredit_', true);
error_log("=============================================");
error_log("EXECUÇÃO ID: " . $execution_id);
error_log("INICIANDO API NEOCREDIT - " . date('Y-m-d H:i:s'));
error_log("=============================================");

// Iniciar sessão se não estiver ativa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Conexão com PostgreSQL
$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Classe para integração com API Neocredit
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
        error_log("[NEOCREDIT] Fazendo requisição para: " . $url);
        error_log("[NEOCREDIT] Método: " . $method);

        if (!empty($data)) {
            error_log("[NEOCREDIT] Dados: " . json_encode($data));
        }

        // Rate limiting
        $now = microtime(true);
        if (($now - self::$last_request_time) < 0.5) {
            usleep(500000);
        }
        self::$last_request_time = $now;

        if (!self::curlDisponivel()) {
            throw new Exception("cURL não está disponível no servidor.");
        }

        $ch = curl_init();

        // Configurações básicas
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . self::$bearer_token,
                'Accept: application/json',
                'Content-Type: application/json',
                'Cache-Control: no-cache'
            )
        );

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $json_data = json_encode($data);
                $options[CURLOPT_POSTFIELDS] = $json_data;
            }
        } elseif ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);

        // Executar requisição
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        // Log detalhado
        error_log("[NEOCREDIT] HTTP Code: " . $http_code);
        error_log("[NEOCREDIT] cURL Error: " . $error);
        error_log("[NEOCREDIT] Response length: " . strlen($response));

        if ($response) {
            error_log("[NEOCREDIT] Response (início): " . substr($response, 0, 500));
        }

        curl_close($ch);

        // Tratar erros
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
                } elseif (isset($error_data['error'])) {
                    $error_msg .= " - " . $error_data['error'];
                }
            }
            throw new Exception($error_msg);
        }

        if (!$response) {
            throw new Exception("Resposta vazia da API");
        }

        $data = @json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[NEOCREDIT] Erro JSON: " . json_last_error_msg());
            error_log("[NEOCREDIT] Response raw: " . $response);
            throw new Exception("Resposta JSON inválida: " . json_last_error_msg());
        }

        error_log("[NEOCREDIT] Requisição bem-sucedida");
        return $data;
    }

    // Iniciar análise na API
    public static function iniciarAnalise($documento)
    {
        $documento_limpo = preg_replace('/[^0-9]/', '', $documento);

        error_log("[NEOCREDIT] Iniciando análise para documento: " . $documento_limpo);

        if (empty($documento_limpo)) {
            throw new Exception("Documento inválido");
        }

        // Verificar cache
        $cache_key = 'iniciar_' . $documento_limpo;
        if (isset(self::$cache_analises[$cache_key]) && (time() - self::$cache_analises[$cache_key]['time']) < 300) {
            error_log("[NEOCREDIT] Usando cache para documento: " . $documento_limpo);
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2722/integracao';

        try {
            $data = self::fazerRequisicao($url, 'POST', array('documento' => $documento_limpo), 60);
            error_log("[NEOCREDIT] Resposta iniciar análise: " . json_encode($data));

            if (!isset($data['id'])) {
                // Tentar encontrar ID em outra estrutura
                if (isset($data['data']) && isset($data['data']['id'])) {
                    $id = $data['data']['id'];
                } elseif (isset($data['solicitacao_id'])) {
                    $id = $data['solicitacao_id'];
                } else {
                    throw new Exception("ID da análise não retornado pela API. Resposta: " . json_encode($data));
                }
            } else {
                $id = $data['id'];
            }

            // Armazenar em cache
            self::$cache_analises[$cache_key] = array(
                'data' => $id,
                'time' => time()
            );

            error_log("[NEOCREDIT] Análise iniciada com ID: " . $id);
            return $id;
        } catch (Exception $e) {
            error_log("[NEOCREDIT] Erro ao iniciar análise: " . $e->getMessage());
            throw $e;
        }
    }

    // Consultar status da análise
    public static function consultarAnalise($id_analise)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        error_log("[NEOCREDIT] Consultando análise ID: " . $id_analise);

        // Verificar cache (cache reduzido para consultas)
        $cache_key = 'consulta_' . $id_analise;
        if (isset(self::$cache_analises[$cache_key]) && (time() - self::$cache_analises[$cache_key]['time']) < 5) {
            error_log("[NEOCREDIT] Usando cache para consulta ID: " . $id_analise);
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/' . $id_analise . '/simplificada';

        try {
            $data = self::fazerRequisicao($url, 'GET', null, 30);
            error_log("[NEOCREDIT] Dados consulta recebidos para ID: " . $id_analise);

            // Log dos principais campos
            if (isset($data['campos'])) {
                error_log("[NEOCREDIT] Campos disponíveis: " . implode(', ', array_keys($data['campos'])));
            }

            // Armazenar em cache
            self::$cache_analises[$cache_key] = array(
                'data' => $data,
                'time' => time()
            );

            return $data;
        } catch (Exception $e) {
            error_log("[NEOCREDIT] Erro ao consultar análise: " . $e->getMessage());
            throw $e;
        }
    }

    // Função para verificar se o status é PROCESSANDO
    public static function analiseEstaProcessando($dados_analise)
    {
        if (!is_array($dados_analise)) {
            return false;
        }
        
        error_log("[NEOCREDIT] Verificando se análise está em PROCESSANDO");
        
        // 1. Primeiro verificar status nos campos
        if (isset($dados_analise['campos']) && is_array($dados_analise['campos'])) {
            $campos = $dados_analise['campos'];
            
            // Status direto da API sem modificação
            if (isset($campos['status']) && !empty($campos['status'])) {
                $status = strtolower(trim($campos['status']));
                error_log("[NEOCREDIT] Status encontrado nos campos: " . $status);
                
                // Verificar se é PROCESSANDO
                if ($status === 'processando') {
                    error_log("[NEOCREDIT] Status é PROCESSANDO nos campos");
                    return true;
                }
            }
        }
        
        // 2. Verificar status principal
        $status_principal = isset($dados_analise['status']) ? strtolower(trim($dados_analise['status'])) : '';
        
        if ($status_principal === 'processando') {
            error_log("[NEOCREDIT] Status principal é PROCESSANDO");
            return true;
        }
        
        // 3. Verificar se campos importantes estão vazios
        if (isset($dados_analise['campos']) && is_array($dados_analise['campos'])) {
            $campos = $dados_analise['campos'];
            
            // Verificar se campos críticos estão vazios
            $campos_criticos = array('limite_aprovado', 'score', 'risco', 'classificacao_risco', 'razao');
            $campos_vazios = 0;
            
            foreach ($campos_criticos as $campo) {
                if (!isset($campos[$campo]) || empty($campos[$campo]) || $campos[$campo] === null || $campos[$campo] === 'N/A') {
                    $campos_vazios++;
                }
            }
            
            // Se todos os campos críticos estiverem vazios, provavelmente ainda está processando
            if ($campos_vazios >= 4) {
                error_log("[NEOCREDIT] Muitos campos críticos vazios, análise em PROCESSANDO");
                return true;
            }
        }
        
        error_log("[NEOCREDIT] Análise NÃO está em PROCESSANDO");
        return false;
    }

    // Verificar se análise está realmente pronta
    private static function analiseEstaPronta($dados)
    {
        if (!is_array($dados)) {
            return false;
        }

        error_log("[NEOCREDIT] Verificando se análise está pronta");

        // Primeiro verificar se está em processamento
        if (self::analiseEstaProcessando($dados)) {
            error_log("[NEOCREDIT] Análise ainda está em PROCESSANDO - não está pronta");
            return false;
        }

        // 1. Verificar status principal
        $status_principal = isset($dados['status']) ? strtolower(trim($dados['status'])) : '';
        $status_finais_principal = array('aprovado', 'reprovado', 'negado', 'liberado', 'concluido', 'finalizado', 'concluído');

        if (in_array($status_principal, $status_finais_principal)) {
            error_log("[NEOCREDIT] Status principal indica pronta: " . $status_principal);
            return true;
        }

        // 2. Verificar campos importantes
        if (isset($dados['campos']) && is_array($dados['campos'])) {
            $campos = $dados['campos'];

            // Verificar campos críticos
            $campos_criticos = array('limite_aprovado', 'score', 'risco', 'classificacao_risco', 'razao');
            foreach ($campos_criticos as $campo) {
                if (isset($campos[$campo]) && !empty($campos[$campo]) && $campos[$campo] !== null) {
                    error_log("[NEOCREDIT] Campo crítico preenchido: " . $campo . " = " . $campos[$campo]);
                    return true;
                }
            }

            // Verificar status nos campos
            if (isset($campos['status']) && !empty($campos['status'])) {
                $status_campos = strtolower(trim($campos['status']));
                $status_finais = array('derivar', 'aprovar', 'reprovar', 'negar', 'liberar', 'concluir', 'aprovado', 'reprovado', 'negado', 'liberado', 'concluido');

                if (in_array($status_campos, $status_finais)) {
                    error_log("[NEOCREDIT] Status nos campos indica pronta: " . $status_campos);
                    return true;
                }
            }
        }

        // 3. Verificar se há histórico de fases concluídas
        if (isset($dados['historico_fases']) && is_array($dados['historico_fases'])) {
            foreach ($dados['historico_fases'] as $fase) {
                if (isset($fase['data_saida']) && !empty($fase['data_saida'])) {
                    error_log("[NEOCREDIT] Fase concluída encontrada no histórico");
                    return true;
                }
            }
        }

        error_log("[NEOCREDIT] Análise ainda não está pronta");
        return false;
    }

    // Aguardar resultado
    public static function aguardarResultado($id_analise, $max_tentativas = 20, $intervalo_base = 5)
    {
        error_log("[NEOCREDIT] Aguardando resultado para ID: " . $id_analise);
        error_log("[NEOCREDIT] Max tentativas: " . $max_tentativas . ", Intervalo: " . $intervalo_base);

        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $tentativa = 0;
        $dados_completos = null;
        $max_tempo_total = 300;

        $start_time = time();

        while ($tentativa < $max_tentativas && (time() - $start_time) < $max_tempo_total) {
            $tentativa++;
            error_log("[NEOCREDIT] Tentativa " . $tentativa . " de " . $max_tentativas);

            try {
                $dados = self::consultarAnalise($id_analise);
                $dados_completos = $dados;

                // Verificar se está pronta (não está processando)
                if (!self::analiseEstaProcessando($dados) && self::analiseEstaPronta($dados)) {
                    error_log("[NEOCREDIT] Análise pronta na tentativa " . $tentativa);
                    return $dados;
                }

                // Aguardar próximo intervalo
                if ($tentativa < $max_tentativas) {
                    $wait_time = $intervalo_base;
                    if ($tentativa > 10) $wait_time = 10;
                    if ($tentativa > 15) $wait_time = 15;

                    error_log("[NEOCREDIT] Aguardando " . $wait_time . " segundos...");
                    sleep($wait_time);
                }
            } catch (Exception $e) {
                error_log("[NEOCREDIT] Erro na tentativa " . $tentativa . ": " . $e->getMessage());

                if ($tentativa >= $max_tentativas) {
                    if ($dados_completos) {
                        error_log("[NEOCREDIT] Retornando dados incompletos após erro");
                        return $dados_completos;
                    }
                    throw new Exception("Erro após {$max_tentativas} tentativas: " . $e->getMessage());
                }

                sleep($intervalo_base);
            }
        }

        // Tempo limite atingido
        if ($dados_completos) {
            error_log("[NEOCREDIT] Retornando dados após timeout");
            return $dados_completos;
        } else {
            $tempo_decorrido = round((time() - $start_time) / 60, 1);
            error_log("[NEOCREDIT] Timeout após " . $tempo_decorrido . " minutos");
            throw new Exception("Tempo limite de análise excedido (" . $tempo_decorrido . " minutos)");
        }
    }

    // Função para extrair dados formatados da análise - ATUALIZADA
    public static function extrairDadosFormatados($dados_analise)
    {
        error_log("[NEOCREDIT] Extraindo dados formatados");

        // Primeiro verificar se está em PROCESSANDO
        if (self::analiseEstaProcessando($dados_analise)) {
            error_log("[NEOCREDIT] Análise está em PROCESSANDO, retornando dados mínimos");
            return array(
                'razao_social' => 'N/A',
                'documento' => '',
                'risco' => 'N/A',
                'score' => 'N/A',
                'classificacao_risco' => 'N/A',
                'limite_aprovado' => 0,
                'data_validade' => 'N/A',
                'status' => 'PROCESSANDO',
                'status_detalhado' => 'Processando análise',
                'id_analise' => isset($dados_analise['id']) ? $dados_analise['id'] : 'N/A',
                'em_processamento' => true,
                'campos_vazios' => true
            );
        }

        // Se não está em PROCESSANDO, extrair dados normalmente
        $resultado = array(
            'razao_social' => 'N/A',
            'documento' => '',
            'risco' => 'N/A',
            'score' => 'N/A',
            'classificacao_risco' => 'N/A',
            'limite_aprovado' => 0,
            'data_validade' => 'N/A',
            'status' => 'EM ANÁLISE',
            'status_detalhado' => 'A',
            'id_analise' => isset($dados_analise['id']) ? $dados_analise['id'] : 'N/A',
            'em_processamento' => false,
            'campos_vazios' => false
        );

        if (!is_array($dados_analise)) {
            error_log("[NEOCREDIT] Dados da análise não são um array");
            return $resultado;
        }

        error_log("[NEOCREDIT] Estrutura dos dados: " . json_encode(array_keys($dados_analise)));

        // Extrair dos campos
        if (isset($dados_analise['campos']) && is_array($dados_analise['campos'])) {
            $campos = $dados_analise['campos'];

            // Log dos campos disponíveis
            error_log("[NEOCREDIT] Campos disponíveis: " . json_encode(array_keys($campos)));

            // Mapear campos da API para nossos campos
            $resultado['razao_social'] = self::extrairValorCampo($campos, array('razao', 'razao_social', 'nome', 'empresa'));
            $resultado['documento'] = self::extrairValorCampo($campos, array('documento', 'cpf', 'cnpj'));
            $resultado['risco'] = self::extrairValorCampo($campos, array('risco', 'nivel_risco', 'risco_credito'));
            $resultado['score'] = self::extrairValorCampo($campos, array('score', 'score_credito', 'pontuacao', 'pontuacao_score'));
            $resultado['classificacao_risco'] = self::extrairValorCampo($campos, array('classificacao_risco', 'classificacao', 'categoria_risco'));

            // Limite aprovado
            $limite = self::extrairValorCampo($campos, array('limite_aprovado', 'limite', 'limite_credito', 'valor_aprovado'));
            if ($limite !== 'N/A' && $limite !== null && $limite !== '') {
                $resultado['limite_aprovado'] = floatval(str_replace(array('R$', '.', ',', ' '), array('', '', '.', ''), $limite));
            }

            // Data validade
            $resultado['data_validade'] = self::extrairValorCampo($campos, array(
                'data_validade_limite_credito',
                'data_validade',
                'validade',
                'vencimento_limite'
            ));

            // Status - VEM DIRETO DA API SEM MODIFICAÇÃO
            $status_campos = self::extrairValorCampo($campos, array('status', 'situacao', 'estado'));
            if (!empty($status_campos) && $status_campos !== 'N/A') {
                $resultado['status'] = $status_campos; // Mantém exatamente como veio da API
                $resultado['status_detalhado'] = $status_campos;
            }
        }

        // Se não encontrou status nos campos, verificar no nível principal
        if ($resultado['status'] === 'EM ANÁLISE' && isset($dados_analise['status']) && !empty($dados_analise['status'])) {
            $resultado['status'] = $dados_analise['status']; // Mantém exatamente como veio da API
            $resultado['status_detalhado'] = $dados_analise['status'];
        }

        // Log dos dados extraídos
        error_log("[NEOCREDIT] Dados extraídos: " . json_encode($resultado));

        return $resultado;
    }

    // Função auxiliar para extrair valor de campo com múltiplas chaves possíveis
    private static function extrairValorCampo($campos, $chaves_possiveis)
    {
        foreach ($chaves_possiveis as $chave) {
            if (isset($campos[$chave]) && $campos[$chave] !== '' && $campos[$chave] !== null) {
                return $campos[$chave];
            }
        }
        return 'N/A';
    }

    // Função para verificar rapidamente se a análise foi concluída
    public static function verificarConclusaoRapida($id_analise)
    {
        try {
            $dados = self::consultarAnalise($id_analise);
            return self::analiseEstaPronta($dados);
        } catch (Exception $e) {
            error_log("[NEOCREDIT] Erro verificação rápida: " . $e->getMessage());
            return false;
        }
    }

    // Método para limpar cache
    public static function limparCache()
    {
        self::$cache_analises = array();
        self::$last_request_time = 0;
        error_log("[NEOCREDIT] Cache limpo manualmente");
        return true;
    }
}

// =============================================
// FUNÇÕES AUXILIARES
// =============================================

// Função para determinar situação (F ou A) - VERSÃO CORRIGIDA
function determinarSituacao($status, $status_detalhado = '')
{
    $status_upper = strtoupper(trim($status));
    $detalhado_upper = strtoupper(trim($status_detalhado));

    // Situações que devem ser 'F' (Finalizado/Negado/Reprovado)
    // APROVADO deve ser 'A' (aberto) para permitir futuras inserções
    $situacoes_f = array(
        'F',
        'FINALIZADO',
        'FINALIZADA',
        'FINAL',
        'FINISHED',
        'REPROVADO',
        'REPROVADA',
        'REPROVAR',
        'REJECTED',
        'DENIED',
        'NEGADO',
        'NEGADA',
        'NEGAR',
        'NEGATIVE',
        'CANCELADO',
        'CANCELADA',
        'CANCELAR',
        'CANCELLED',
        'EXPIRADO',
        'EXPIRADA',
        'EXPIRAR',
        'EXPIRED',
        'INATIVO',
        'INATIVA',
        'INACTIVE',
        'BLOQUEADO',
        'BLOQUEADA',
        'BLOCKED'
    );

    // NOTA IMPORTANTE: APROVADO, LIBERADO e CONCLUIDO devem ser 'A' 
    // para permitir que novas consultas sejam inseridas quando este 
    // registro for finalizado manualmente

    // Verificar se o status ou status detalhado contém alguma palavra-chave de 'F'
    foreach ($situacoes_f as $palavra_chave) {
        if (
            strpos($status_upper, $palavra_chave) !== false ||
            strpos($detalhado_upper, $palavra_chave) !== false
        ) {
            error_log("[SITUACAO] Status indica F: " . $status_upper . " / " . $detalhado_upper);
            return 'F';
        }
    }

    // Se não for 'F', então é 'A' (Ativo/Aberto)
    // Isso inclui: APROVADO, LIBERADO, EM ANÁLISE, PENDENTE, etc.
    error_log("[SITUACAO] Status indica A: " . $status_upper . " / " . $detalhado_upper);
    return 'A';
}

function formatarDataParaBanco($data)
{
    if ($data === 'N/A' || empty($data)) {
        return null;
    }

    // Tentar formatar a data para o formato do PostgreSQL
    try {
        // Converter várias formatos possíveis
        $timestamp = strtotime($data);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    } catch (Exception $e) {
        return null;
    }
}

// =============================================
// FUNÇÃO PARA SALVAR NO BANCO DE DADOS - VERSÃO FINAL CORRIGIDA
// =============================================

function salvarDadosNeocredit($dados_formatados, $id_analise, $pdo)
{
    try {
        error_log("[BANCO DE DADOS] Iniciando salvamento dos dados na tabela lcred_neocredit");
        error_log("[BANCO DE DADOS] Documento: " . $dados_formatados['documento']);
        error_log("[BANCO DE DADOS] ID Análise: " . $id_analise);

        // Não salvar se estiver em PROCESSANDO
        if ($dados_formatados['status'] === 'PROCESSANDO' || $dados_formatados['em_processamento'] === true) {
            error_log("[BANCO DE DADOS] BLOQUEADO: Análise ainda em PROCESSANDO, não pode salvar");
            return [
                'success' => false,
                'error' => 'Análise ainda está em PROCESSANDO. Aguarde até o status mudar para salvar no banco.',
                'bloqueado' => true,
                'tipo_bloqueio' => 'em_processamento'
            ];
        }

        // 1. VERIFICAR SE JÁ EXISTE REGISTRO COM ESTE DOCUMENTO
        $stmt = $pdo->prepare("SELECT codigo, situ FROM lcred_neocredit WHERE documento = ? ORDER BY codigo DESC");
        $stmt->execute([$dados_formatados['documento']]);
        $registros_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Verificar se existe algum registro com situação = 'A' (em aberto)
        $existe_em_aberto = false;
        $todos_finalizados = true;
        $codigo_em_aberto = null;

        foreach ($registros_existentes as $registro) {
            $situ_registro = isset($registro['situ']) ? trim(strtoupper($registro['situ'])) : '';

            if ($situ_registro === 'A') {
                $existe_em_aberto = true;
                $codigo_em_aberto = $registro['codigo'];
                error_log("[BANCO DE DADOS] Documento já existe com situação = 'A' (em aberto)");
            }

            // Verificar se este registro NÃO está finalizado
            if ($situ_registro !== 'F') {
                $todos_finalizados = false;
            }
        }

        // REGRA 1: Se existe registro em aberto, NÃO PODE INSERIR NOVO
        if ($existe_em_aberto) {
            error_log("[BANCO DE DADOS] BLOQUEADO: Já existe registro em aberto para este documento");
            return [
                'success' => false,
                'error' => 'Já existe um registro em aberto (situação = "A") para este documento.',
                'codigo_existente' => $codigo_em_aberto,
                'bloqueado' => true,
                'tipo_bloqueio' => 'em_aberto'
            ];
        }

        // REGRA PRINCIPAL: NOVA INSERÇÃO SEMPRE COM 'A'
        // Independente do status da análise, nova inserção sempre é 'A'
        $situ = 'A';

        error_log("[BANCO DE DADOS] NOVA INSERÇÃO: Situação forçada para A (aberto)");

        // 2. VERIFICAR SE JÁ EXISTE REGISTRO COM ESTE ID NEOCREDIT
        $stmt = $pdo->prepare("SELECT codigo FROM lcred_neocredit WHERE id_neocredit = ?");
        $stmt->execute([$id_analise]);
        $existe_id = $stmt->fetch();

        $acao = '';
        $result = false;

        if ($existe_id) {
            // Se já existe registro com este ID, atualizar (mantém situação existente)
            $sql = "UPDATE lcred_neocredit SET 
                    razao_social = ?,
                    documento = ?,
                    risco = ?,
                    classificacao_risco = ?,
                    lcred_aprovado = ?,
                    validade_cred = ?,
                    status = ?,
                    score = ?,
                    data_consulta = ?
                    WHERE id_neocredit = ?";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $dados_formatados['razao_social'],
                $dados_formatados['documento'],
                $dados_formatados['risco'],
                $dados_formatados['classificacao_risco'],
                $dados_formatados['limite_aprovado'],
                formatarDataParaBanco($dados_formatados['data_validade']),
                $dados_formatados['status'],
                $dados_formatados['score'],
                date('Y-m-d H:i:s'),
                $id_analise
            ]);

            $acao = 'atualizado';
        } else {
            // Inserir NOVO registro - SEMPRE com 'A'
            $sql = "INSERT INTO lcred_neocredit 
                    (id_neocredit, razao_social, documento, risco, classificacao_risco, 
                     lcred_aprovado, validade_cred, status, score, situ, data_consulta) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $id_analise,
                $dados_formatados['razao_social'],
                $dados_formatados['documento'],
                $dados_formatados['risco'],
                $dados_formatados['classificacao_risco'],
                $dados_formatados['limite_aprovado'],
                formatarDataParaBanco($dados_formatados['data_validade']),
                $dados_formatados['status'],
                $dados_formatados['score'],
                $situ, // SEMPRE 'A' para nova inserção
                date('Y-m-d H:i:s')
            ]);

            $acao = 'inserido';
        }

        if ($result) {
            error_log("[BANCO DE DADOS] Dados $acao com sucesso na tabela lcred_neocredit");
            return [
                'success' => true,
                'acao' => $acao,
                'id_analise' => $id_analise,
                'documento' => $dados_formatados['documento'],
                'situ' => $situ,
                'todos_finalizados' => $todos_finalizados,
                'registros_existentes' => count($registros_existentes),
                'mensagem' => $existe_em_aberto ?
                    'Atualizado registro existente' :
                    'Nova análise inserida com situação A (aberto)'
            ];
        } else {
            error_log("[BANCO DE DADOS] Erro ao salvar dados no banco");
            return [
                'success' => false,
                'error' => 'Erro ao executar query no banco de dados'
            ];
        }
    } catch (Exception $e) {
        error_log("[BANCO DE DADOS] Erro: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// =============================================
// PROCESSADOR DE REQUISIÇÕES
// =============================================

// Endpoint para limpar sessão e cache
if (isset($_GET['action']) && $_GET['action'] === 'limpar_sessao') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        // Limpar cache da classe ApiNeocredit
        ApiNeocredit::limparCache();

        // Limpar variáveis de sessão
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        // Iniciar nova sessão limpa
        session_start();

        // Gerar nova execução ID
        $nova_execucao_id = uniqid('neocredit_', true);

        echo json_encode(array(
            'success' => true,
            'message' => 'Sessão limpa com sucesso',
            'timestamp' => date('Y-m-d H:i:s'),
            'cache_cleared' => true,
            'new_execution_id' => $nova_execucao_id,
            'session_destroyed' => true
        ));
    } catch (Exception $e) {
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    error_log("=============================================");
    error_log("RECEBENDO REQUISIÇÃO POST");
    error_log("=============================================");

    // Verificar se é uma requisição para salvar dados
    if (isset($_POST['action']) && $_POST['action'] === 'salvar_dados') {
        try {
            $dados = json_decode($_POST['dados'], true);

            if (!$dados || !isset($dados['dados_formatados']) || !isset($dados['id_analise'])) {
                throw new Exception('Dados inválidos para salvar');
            }

            $resultado = salvarDadosNeocredit($dados['dados_formatados'], $dados['id_analise'], $pdo);

            echo json_encode($resultado);
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    try {
        // Capturar documento
        $documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';
        error_log("Documento recebido: " . $documento);

        if (empty($documento)) {
            throw new Exception('Documento não informado');
        }

        $start_time = microtime(true);
        $etapas = array();

        // Inicializar variáveis
        $id_analise = null;
        $dados_api = null;
        $dados_formatados = null;

        try {
            // Etapa 1: Iniciar análise
            $etapas[] = 'Iniciando análise Neocredit';
            error_log("Etapa 1: Iniciando análise");

            $id_analise = ApiNeocredit::iniciarAnalise($documento);
            $etapas[] = 'Análise Neocredit iniciada: ' . $id_analise;
            error_log("ID da análise: " . $id_analise);

            // Etapa 2: Aguardar resultado
            $etapas[] = 'Aguardando resultado da análise';
            error_log("Etapa 2: Aguardando resultado");

            $dados_api = ApiNeocredit::aguardarResultado($id_analise, 15, 8);
            $etapas[] = 'Análise Neocredit processada';
            error_log("Dados API recebidos");

            // Etapa 3: Extrair dados
            $etapas[] = 'Extraindo dados formatados';
            error_log("Etapa 3: Extraindo dados formatados");

            $dados_formatados = ApiNeocredit::extrairDadosFormatados($dados_api);
            $etapas[] = 'Dados extraídos com sucesso';
        } catch (Exception $e) {
            error_log("Erro durante o processamento: " . $e->getMessage());

            // Tentar recuperação parcial
            if (!$id_analise) {
                throw new Exception("Falha na análise Neocredit: " . $e->getMessage());
            }

            // Tentar pelo menos uma consulta final
            if (!$dados_api) {
                try {
                    error_log("Tentando recuperação com consulta final");
                    $dados_api = ApiNeocredit::consultarAnalise($id_analise);
                    $dados_formatados = ApiNeocredit::extrairDadosFormatados($dados_api);
                    $etapas[] = 'Consulta de recuperação realizada';
                } catch (Exception $e2) {
                    error_log("Falha na recuperação: " . $e2->getMessage());
                    $dados_formatados = array(
                        'razao_social' => 'N/A',
                        'documento' => $documento,
                        'risco' => 'N/A',
                        'score' => 'N/A',
                        'classificacao_risco' => 'N/A',
                        'limite_aprovado' => 0,
                        'data_validade' => 'N/A',
                        'status' => 'ERRO NA CONSULTA',
                        'status_detalhado' => 'ERRO',
                        'id_analise' => $id_analise,
                        'em_processamento' => false
                    );
                }
            }
        }

        $total_time = round(microtime(true) - $start_time, 2);
        error_log("Tempo total de processing: " . $total_time . " segundos");

        // Preparar resposta
        $resposta = array(
            'success' => true,
            'documento' => $documento,
            'documento_limpo' => preg_replace('/[^0-9]/', '', $documento),
            'id_analise' => $id_analise,
            'dados_api' => $dados_api,
            'dados_formatados' => $dados_formatados,
            'tempo_total_segundos' => $total_time,
            'etapas' => $etapas,
            'timestamp' => date('Y-m-d H:i:s'),
            'execution_id' => $execution_id
        );

        error_log("Resposta preparada com sucesso");
        echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("ERRO FINAL: " . $e->getMessage());
        http_response_code(500);

        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
            'execution_id' => $execution_id
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    error_log("=============================================");
    error_log("FIM DA REQUISIÇÃO");
    error_log("=============================================");
    exit;
}

// Endpoint para salvar dados
if (isset($_GET['action']) && $_GET['action'] === 'salvar_dados' && isset($_GET['dados'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        $dados = json_decode(urldecode($_GET['dados']), true);

        if (!$dados || !isset($dados['dados_formatados']) || !isset($dados['id_analise'])) {
            throw new Exception('Dados inválidos para salvar');
        }

        $resultado = salvarDadosNeocredit($dados['dados_formatados'], $dados['id_analise'], $pdo);

        echo json_encode($resultado);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Endpoint para status do sistema
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo json_encode(array(
        'status' => 'online',
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        'php_version' => PHP_VERSION,
        'api_neocredit_available' => ApiNeocredit::curlDisponivel(),
        'server_info' => $_SERVER['SERVER_SOFTWARE'],
        'database_connected' => true,
        'execution_id' => $execution_id,
        'session_status' => session_status()
    ));
    exit;
}

// Endpoint para consulta rápida de status
if (isset($_GET['action']) && $_GET['action'] === 'consulta_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        $dados = ApiNeocredit::consultarAnalise($_GET['id']);
        $esta_processando = ApiNeocredit::analiseEstaProcessando($dados);
        $pronto = ApiNeocredit::analiseEstaPronta($dados);

        echo json_encode(array(
            'success' => true,
            'status' => isset($dados['status']) ? $dados['status'] : 'N/A',
            'status_campos' => isset($dados['campos']['status']) ? $dados['campos']['status'] : 'N/A',
            'esta_processando' => $esta_processando,
            'pronto' => $pronto,
            'limite_aprovado' => isset($dados['campos']['limite_aprovado']) ? $dados['campos']['limite_aprovado'] : 'N/A',
            'timestamp' => date('Y-m-d H:i:s'),
            'dados_completos' => (!$esta_processando && $pronto) ? ApiNeocredit::extrairDadosFormatados($dados) : null
        ));
    } catch (Exception $e) {
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ));
    }
    exit;
}

// Endpoint de teste direto
if (isset($_GET['teste']) && $_GET['teste'] === 'simples') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        // Teste de conexão básica
        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2722/integracao';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ApiNeocredit::$bearer_token,
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['documento' => '12345678901'])
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        echo json_encode([
            'success' => true,
            'http_code' => $http_code,
            'response' => $response,
            'curl_error' => $curl_error,
            'token_valido' => $http_code != 401 && $http_code != 403
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOROAÇO - Consulta de Crédito Neocredit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="neocredit.css">

    <!-- Meta tags para prevenir cache -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <style>
        /* Estilos adicionais para o botão Limpar */
        .btn-limpar-tudo {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 6px rgba(255, 107, 107, 0.2);
        }

        .btn-limpar-tudo:hover {
            background: linear-gradient(135deg, #ff5252 0%, #e53935 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 107, 107, 0.3);
        }

        .btn-limpar-tudo:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(255, 107, 107, 0.2);
        }

        .btn-limpar-tudo:disabled {
            background: #cccccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-limpar-tudo i {
            font-size: 16px;
        }

        /* Estilo para mensagem de limpeza */
        .cleanup-message {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            display: none;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        .cleanup-message.show {
            display: flex;
        }

        .cleanup-message i {
            font-size: 24px;
        }

        .cleanup-message .message-content {
            flex: 1;
        }

        .cleanup-message .close-cleanup {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .cleanup-message .close-cleanup:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Estilos para mensagens de resultado */
        .salvar-container {
            margin-top: 10px;
            text-align: center;
            padding: 15px;
            border-radius: 8px;
        }

        .btn-salvar-db {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            border: none;
            color: white;
            padding: 14px 32px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }

        .btn-salvar-db:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-salvar-db:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }

        .btn-salvar-db:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-salvar-db i {
            font-size: 18px;
        }

        /* Status styles */
        .status-container {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
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

        .status-processando {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }

        .status-analise {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .status-detalhe {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
            font-style: italic;
        }

        /* Aviso de processamento */
        .aviso-processando {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 25px;
            margin: 20px 0;
            text-align: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(33, 150, 243, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(33, 150, 243, 0); }
            100% { box-shadow: 0 0 0 0 rgba(33, 150, 243, 0); }
        }

        .aviso-processando-titulo {
            color: #0d47a1;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .aviso-processando-texto {
            color: #333;
            line-height: 1.6;
            margin-bottom: 20px;
            text-align: left;
            font-size: 14px;
        }

        .aviso-processando-acoes {
            margin-top: 20px;
        }

        .btn-verificar-status {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-verificar-status:hover {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
            transform: translateY(-2px);
        }

        .dados-ocultos {
            display: none !important;
        }

        .dados-visiveis {
            display: block !important;
        }

        /* Contador de verificação */
        .contador-verificacao {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            padding: 10px 15px;
            margin: 10px 0;
            text-align: center;
            font-size: 13px;
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }

        .spinner-processando {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border-left-color: #2196f3;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Estilo para quando mostrar os dados */
        .api-section.com-dados {
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo NOROAÇO" class="logo-noroaco">

            <div class="botoes-direita">
                <a href="#" class="btn-resumo" id="pdf-resumo-btn">
                    <i class="fa-solid fa-file-pdf"></i> PDF Resumo
                </a>

                <a href="#" class="btn-completo" id="pdf-completo-btn">
                    <i class="fa-solid fa-file-pdf"></i> PDF Completo
                </a>
            </div>
        </header>

        <!-- MENSAGENS TEMPORÁRIAS -->
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
                <i class="fa-solid fa-coins"></i> Gerenciador de Crédito - Neocredit (Análise)
            </div>

            <!-- FORMULÁRIO -->
            <div class="form-section">
                <div class="form-input-group">
                    <div class="input-with-icon">
                        <i class="fas fa-search"></i>
                        <input type="text" id="documento-input" placeholder="Digite o CPF ou CNPJ...">
                    </div>
                    <div class="button-group">
                        <button id="consultar-btn">
                            <i class="fas fa-search"></i> Consultar
                        </button>
                        <button id="limpar-btn" class="btn-limpar-tudo">
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
                <div class="progress-text" id="progress-text">Processando...</div>
                <div class="loading-details" id="loading-details"></div>
            </div>

            <div class="result-section" id="result-section">
                <div id="api-grid"></div>
                <div id="db-message"></div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========== VARIÁVEIS GLOBAIS ==========
            var documentoInput = document.getElementById('documento-input');
            var consultarBtn = document.getElementById('consultar-btn');
            var limparBtn = document.getElementById('limpar-btn');
            var loadingDiv = document.getElementById('loading');
            var loadingText = document.getElementById('loading-text');
            var loadingDetails = document.getElementById('loading-details');
            var progressBar = document.getElementById('progress-bar');
            var progressText = document.getElementById('progress-text');
            var errorDiv = document.getElementById('error-message');
            var successDiv = document.getElementById('success-message');
            var cleanupMessage = document.getElementById('cleanup-message');
            var closeCleanupBtn = document.getElementById('close-cleanup-btn');
            var resultSection = document.getElementById('result-section');
            var apiGrid = document.getElementById('api-grid');
            var errorText = errorDiv.querySelector('.message-text');
            var successText = successDiv.querySelector('.message-text');
            var pdfResumoBtn = document.getElementById('pdf-resumo-btn');
            var pdfCompletoBtn = document.getElementById('pdf-completo-btn');

            var progressInterval;
            var verificacaoInterval;
            var startTime;
            var dadosAtuais = null;
            var salvarDadosBtn = null;
            var verificacaoAtiva = false;

            // Timeouts para controle das mensagens
            var errorTimeout = null;
            var successTimeout = null;
            var cleanupTimeout = null;

            // URLs dos PDFs
            var pdfResumoUrl = 'limite_cred_pdf.php';
            var pdfCompletoUrl = 'limite_cred_pdf_detalhado.php';

            // ========== INICIALIZAÇÃO ==========
            inicializarSistema();

            function inicializarSistema() {
                inicializarEventListeners();
                desabilitarBotoesPDF();
                console.log('Sistema inicializado - Sessão limpa');
            }

            function inicializarEventListeners() {
                if (consultarBtn) consultarBtn.addEventListener('click', executarConsulta);
                if (limparBtn) limparBtn.addEventListener('click', confirmarLimpeza);

                if (closeCleanupBtn) {
                    closeCleanupBtn.addEventListener('click', function() {
                        cleanupMessage.classList.remove('show');
                    });
                }

                if (documentoInput) {
                    documentoInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') executarConsulta();
                    });

                    // Máscara CPF/CNPJ
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

                // Botões de fechar nas mensagens
                var closeErrorBtn = errorDiv.querySelector('.close-btn');
                var closeSuccessBtn = successDiv.querySelector('.close-btn');

                if (closeErrorBtn) {
                    closeErrorBtn.addEventListener('click', function() {
                        hideError();
                    });
                }

                if (closeSuccessBtn) {
                    closeSuccessBtn.addEventListener('click', function() {
                        hideSuccess();
                    });
                }

                // Fechar mensagem ao clicar nela
                errorDiv.addEventListener('click', function(e) {
                    if (!e.target.closest('.close-btn')) {
                        hideError();
                    }
                });

                successDiv.addEventListener('click', function(e) {
                    if (!e.target.closest('.close-btn')) {
                        hideSuccess();
                    }
                });

                // Prevenir clique nos botões PDF quando desabilitados
                if (pdfResumoBtn) {
                    pdfResumoBtn.addEventListener('click', function(e) {
                        if (!this.classList.contains('enabled')) {
                            e.preventDefault();
                            e.stopPropagation();
                            showError('Realize uma consulta primeiro para gerar o PDF.', 3000);
                        }
                    });
                }

                if (pdfCompletoBtn) {
                    pdfCompletoBtn.addEventListener('click', function(e) {
                        if (!this.classList.contains('enabled')) {
                            e.preventDefault();
                            e.stopPropagation();
                            showError('Realize uma consulta primeiro para gerar o PDF.', 3000);
                        }
                    });
                }
            }

            // ========== FUNÇÃO DE CONFIRMAÇÃO DE LIMPEZA ==========
            function confirmarLimpeza() {
                if (dadosAtuais || documentoInput.value.trim() !== '') {
                    if (confirm('⚠️  ATENÇÃO: Tem certeza que deseja LIMPAR TUDO?\n\n✅ TODOS os dados da consulta atual serão apagados\n✅ A sessão será completamente encerrada\n✅ Cache será limpo\n✅ A próxima consulta será uma NOVA SESSÃO\n\nIsso não pode ser desfeito.')) {
                        limparConsulta();
                    }
                } else {
                    limparConsulta();
                }
            }

            // ========== FUNÇÃO PRINCIPAL DE LIMPEZA ==========
            function limparConsulta() {
                console.log('Iniciando limpeza completa da sessão...');

                // Parar qualquer verificação em andamento
                if (verificacaoAtiva && verificacaoInterval) {
                    clearInterval(verificacaoInterval);
                    verificacaoAtiva = false;
                    verificacaoInterval = null;
                }

                // 1. Limpar campo de entrada
                documentoInput.value = '';

                // 2. Limpar resultados visíveis
                resultSection.style.display = 'none';
                apiGrid.innerHTML = '';

                // 3. Ocultar mensagens
                hideError();
                hideSuccess();

                // 4. Limpar variáveis de estado
                dadosAtuais = null;

                // 5. Desabilitar botões PDF
                desabilitarBotoesPDF();

                // 6. Parar qualquer processo em andamento
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }

                // 7. Resetar barra de progresso
                progressBar.style.width = '0%';
                progressText.textContent = 'Processando...';
                loadingDiv.style.display = 'none';

                // 8. Mostrar mensagem de limpeza
                cleanupMessage.classList.add('show');
                setTimeout(() => {
                    cleanupMessage.classList.remove('show');
                }, 5000);

                // 9. Fazer requisição ao servidor para limpar sessão e cache
                fetch(window.location.href + '?action=limpar_sessao&timestamp=' + Date.now(), {
                        method: 'GET',
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache',
                            'Expires': '0'
                        },
                        cache: 'no-store'
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Erro ao limpar sessão: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Resposta da limpeza:', data);

                        // Adicionar ID da nova execução ao console
                        if (data.new_execution_id) {
                            console.log('Nova execução ID:', data.new_execution_id);
                        }

                        // Resetar estado do botão Salvar se existir
                        if (salvarDadosBtn) {
                            salvarDadosBtn.disabled = false;
                            salvarDadosBtn.innerHTML = '<i class="fas fa-database"></i> Salvar no Banco de Dados';
                            salvarDadosBtn = null;
                        }

                        // Forçar recarga de alguns elementos
                        document.activeElement.blur();

                        // Adicionar timestamp ao URL para evitar cache
                        if (window.history.replaceState) {
                            var newUrl = window.location.href.split('?')[0] + '?nocache=' + Date.now();
                            window.history.replaceState({}, document.title, newUrl);
                        }
                    })
                    .catch(error => {
                        console.warn('Aviso na limpeza (pode ser normal):', error.message);
                    })
                    .finally(() => {
                        console.log('Limpeza completa - Pronto para nova consulta');

                        // Forçar garbage collection
                        if (window.gc) {
                            window.gc();
                        }
                    });
            }

            // ========== FUNÇÕES DE CONTROLE DOS BOTÕES PDF ==========
            function habilitarBotoesPDF() {
                if (pdfResumoBtn && pdfCompletoBtn && dadosAtuais) {
                    // Só habilita se não estiver em PROCESSANDO
                    if (dadosAtuais.dados_formatados && dadosAtuais.dados_formatados.status === 'PROCESSANDO') {
                        desabilitarBotoesPDF();
                        return;
                    }
                    
                    pdfResumoBtn.classList.add('enabled');
                    pdfCompletoBtn.classList.add('enabled');

                    var documento = dadosAtuais.documento_limpo || '';
                    var idAnalise = dadosAtuais.id_analise || '';
                    var timestamp = Date.now();

                    if (documento) {
                        pdfResumoBtn.href = pdfResumoUrl + '?doc=' + encodeURIComponent(documento) +
                            '&id=' + encodeURIComponent(idAnalise) +
                            '&tipo=resumo&timestamp=' + timestamp +
                            '&nocache=' + timestamp;

                        pdfCompletoBtn.href = pdfCompletoUrl + '?doc=' + encodeURIComponent(documento) +
                            '&id=' + encodeURIComponent(idAnalise) +
                            '&tipo=completo&timestamp=' + timestamp +
                            '&nocache=' + timestamp;

                        console.log('Botões PDF habilitados para documento:', documento);
                    }
                }
            }

            function desabilitarBotoesPDF() {
                if (pdfResumoBtn && pdfCompletoBtn) {
                    pdfResumoBtn.classList.remove('enabled');
                    pdfCompletoBtn.classList.remove('enabled');
                    pdfResumoBtn.removeAttribute('href');
                    pdfCompletoBtn.removeAttribute('href');
                }
            }

            function atualizarURLsPDF() {
                if (dadosAtuais && pdfResumoBtn && pdfCompletoBtn &&
                    pdfResumoBtn.classList.contains('enabled')) {

                    var documento = dadosAtuais.documento_limpo || '';
                    var idAnalise = dadosAtuais.id_analise || '';
                    var timestamp = Date.now();

                    if (documento) {
                        pdfResumoBtn.href = pdfResumoUrl + '?doc=' + encodeURIComponent(documento) +
                            '&id=' + encodeURIComponent(idAnalise) +
                            '&tipo=resumo&timestamp=' + timestamp +
                            '&nocache=' + timestamp +
                            '&dados=' + encodeURIComponent(JSON.stringify(dadosAtuais.dados_formatados || {}));

                        pdfCompletoBtn.href = pdfCompletoUrl + '?doc=' + encodeURIComponent(documento) +
                            '&id=' + encodeURIComponent(idAnalise) +
                            '&tipo=completo&timestamp=' + timestamp +
                            '&nocache=' + timestamp +
                            '&dados=' + encodeURIComponent(JSON.stringify(dadosAtuais.dados_formatados || {}));
                    }
                }
            }

            // ========== FUNÇÕES PRINCIPAIS ==========
            function executarConsulta() {
                var documento = documentoInput.value.replace(/\D/g, '');

                if (!documento) {
                    showError('Digite um CPF ou CNPJ válido.', 4000);
                    return;
                }

                if (documento.length !== 11 && documento.length !== 14) {
                    showError('CPF deve ter 11 dígitos e CNPJ 14 dígitos.', 4000);
                    return;
                }

                // Limpar cache anterior antes de nova consulta
                fetch(window.location.href + '?action=limpar_sessao&pre_consult=' + Date.now(), {
                    method: 'GET',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache'
                    },
                    cache: 'no-store'
                }).catch(() => {
                    console.log('Pré-limpeza opcional');
                });

                documentoInput.disabled = true;
                consultarBtn.disabled = true;
                limparBtn.disabled = true;
                desabilitarBotoesPDF();
                loadingDiv.style.display = 'block';
                resultSection.style.display = 'none';

                hideError();
                hideSuccess();
                cleanupMessage.classList.remove('show');

                loadingText.textContent = 'Consultando dados, aguarde...';
                loadingDetails.innerHTML = '';

                iniciarProgresso();

                var formData = new FormData();
                formData.append('documento', documentoInput.value);
                formData.append('timestamp', Date.now());

                fetch(window.location.href + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache',
                            'Expires': '0'
                        },
                        cache: 'no-store'
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
                        limparBtn.disabled = false;

                        if (!data.success) {
                            showError('Erro: ' + (data.error || 'Erro desconhecido'), 5000);
                            return;
                        }

                        exibirResultados(data);
                        
                        // Se estiver PROCESSANDO, iniciar verificação periódica
                        if (data.dados_formatados && data.dados_formatados.status === 'PROCESSANDO') {
                            iniciarVerificacaoPeriodica(data.id_analise);
                            showSuccess('Análise iniciada! Aguardando processamento...', 3000);
                        } else {
                            showSuccess('Consulta realizada com sucesso!', 3000);
                        }
                    })
                    .catch(function(error) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;
                        limparBtn.disabled = false;
                        showError('Erro na requisição: ' + error.message, 5000);
                        console.error('Erro detalhado:', error);
                    });
            }

            // ========== FUNÇÃO PARA SALVAR NO BANCO DE DADOS (VERSÃO CORRIGIDA) ==========
            function salvarNoBancoDeDados() {
                if (!dadosAtuais) {
                    showError('Nenhum dado disponível para salvar. Por favor, realize uma consulta primeiro.', 4000);
                    return;
                }

                if (!salvarDadosBtn) {
                    showError('Botão de salvar não encontrado.', 4000);
                    return;
                }

                // Verificar se está em PROCESSANDO
                if (dadosAtuais.dados_formatados && dadosAtuais.dados_formatados.status === 'PROCESSANDO') {
                    showError('Não é possível salvar enquanto a análise está em PROCESSANDO. Aguarde até o status mudar.', 5000);
                    return;
                }

                const btnOriginalText = salvarDadosBtn.innerHTML;
                const btnOriginalDisabled = salvarDadosBtn.disabled;

                salvarDadosBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando no Banco...';
                salvarDadosBtn.disabled = true;

                // Preparar dados para envio
                const dadosParaSalvar = {
                    dados_formatados: dadosAtuais.dados_formatados,
                    id_analise: dadosAtuais.id_analise,
                    documento: dadosAtuais.documento_limpo,
                    timestamp: new Date().toISOString()
                };

                // Enviar via POST
                const formData = new FormData();
                formData.append('action', 'salvar_dados');
                formData.append('dados', JSON.stringify(dadosParaSalvar));

                fetch(window.location.href + '?nocache=' + Date.now(), {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Erro HTTP: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Resposta do servidor:', data);

                        if (data.success) {
                            // Exibir mensagem de sucesso TEMPORÁRIA no topo
                            const situacaoTexto = data.situ === 'F' ? 'Finalizado (F)' : 'Ativo (A)';
                            let mensagemSucesso;

                            if (data.acao && data.acao.includes('inserido')) {
                                mensagemSucesso = `✅ Dados INSERIDOS com sucesso! Documento: ${data.documento}. Situação: ${situacaoTexto}`;
                            } else if (data.acao && data.acao.includes('atualizado')) {
                                mensagemSucesso = `✏️ Dados ATUALIZADOS com sucesso! Documento: ${data.documento}. Situação: ${situacaoTexto}`;
                            } else {
                                mensagemSucesso = `✅ Dados salvos com sucesso! Documento: ${data.documento}. Situação: ${situacaoTexto}`;
                            }

                            showSuccess(mensagemSucesso, 5000);

                            // Se foi uma inserção com situação 'A', manter o botão habilitado
                            // Se foi inserido como 'F' ou atualizado, o usuário pode tentar inserir novo
                            if (data.situ === 'A' && data.acao && data.acao.includes('inserido')) {
                                // Documento agora está em aberto, não pode inserir novo até finalizar
                                salvarDadosBtn.disabled = true;
                                salvarDadosBtn.innerHTML = '<i class="fas fa-lock"></i> Registro em aberto - Não pode inserir novo';
                            } else {
                                // Restaurar botão para permitir nova tentativa
                                salvarDadosBtn.innerHTML = btnOriginalText;
                                salvarDadosBtn.disabled = btnOriginalDisabled;
                            }

                        } else if (data.bloqueado && data.tipo_bloqueio === 'em_aberto') {
                            // Documento bloqueado - já existe registro em aberto
                            showError('❌ Este documento já possui um registro em aberto no sistema (situação = A). Não é possível inserir nova análise.', 5000);

                            // Desabilitar o botão para evitar novas tentativas
                            salvarDadosBtn.disabled = true;
                            salvarDadosBtn.innerHTML = '<i class="fas fa-lock"></i> Registro em aberto - Bloqueado';

                        } else if (data.bloqueado && data.tipo_bloqueio === 'em_processamento') {
                            // Análise em processamento
                            showError('❌ Análise ainda está em PROCESSANDO. Aguarde até o status mudar para salvar.', 5000);

                            // Restaurar botão
                            salvarDadosBtn.innerHTML = btnOriginalText;
                            salvarDadosBtn.disabled = false;

                        } else {
                            // Outro tipo de erro
                            showError('❌ Erro ao salvar no banco: ' + (data.error || 'Erro desconhecido'), 5000);

                            // Restaurar botão para permitir nova tentativa
                            salvarDadosBtn.innerHTML = btnOriginalText;
                            salvarDadosBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Erro na requisição:', error);
                        showError('❌ Erro de conexão: ' + error.message, 5000);

                        // Restaurar botão para permitir nova tentativa
                        salvarDadosBtn.innerHTML = btnOriginalText;
                        salvarDadosBtn.disabled = false;
                    });
            }

            // ========== FUNÇÕES DE MENSAGENS TEMPORÁRIAS ==========
            function showError(msg, duration = 5000) {
                if (!errorDiv || !errorText) return;

                if (errorTimeout) {
                    clearTimeout(errorTimeout);
                    errorTimeout = null;
                }

                if (successDiv.classList.contains('show')) {
                    successDiv.classList.remove('show');
                }

                errorText.textContent = msg;
                errorDiv.classList.remove('hiding');
                void errorDiv.offsetWidth;
                errorDiv.classList.add('show');

                errorTimeout = setTimeout(function() {
                    hideError();
                }, duration);

                console.error('Erro:', msg);
            }

            function hideError() {
                if (!errorDiv) return;

                if (errorTimeout) {
                    clearTimeout(errorTimeout);
                    errorTimeout = null;
                }

                errorDiv.classList.add('hiding');
                setTimeout(function() {
                    errorDiv.classList.remove('show', 'hiding');
                }, 400);
            }

            function showSuccess(msg, duration = 5000) {
                if (!successDiv || !successText) return;

                if (successTimeout) {
                    clearTimeout(successTimeout);
                    successTimeout = null;
                }

                if (errorDiv.classList.contains('show')) {
                    errorDiv.classList.remove('show');
                }

                successText.textContent = msg;
                successDiv.classList.remove('hiding');
                void successDiv.offsetWidth;
                successDiv.classList.add('show');

                successTimeout = setTimeout(function() {
                    hideSuccess();
                }, duration);

                console.log('Sucesso:', msg);
            }

            function hideSuccess() {
                if (!successDiv) return;

                if (successTimeout) {
                    clearTimeout(successTimeout);
                    successTimeout = null;
                }

                successDiv.classList.add('hiding');
                setTimeout(function() {
                    successDiv.classList.remove('show', 'hiding');
                }, 400);
            }

            // ========== FUNÇÕES DE PROGRESSO ==========
            function iniciarProgresso() {
                startTime = new Date().getTime();
                var maxTime = 5 * 60 * 1000;

                progressInterval = setInterval(function() {
                    var currentTime = new Date().getTime();
                    var elapsed = currentTime - startTime;
                    var progress = Math.min((elapsed / maxTime) * 100, 100);

                    progressBar.style.width = progress + '%';

                    var minutes = Math.floor(elapsed / 60000);
                    var seconds = Math.floor((elapsed % 60000) / 1000);

                    var tempoTotal = Math.floor(maxTime / 60000);
                    progressText.textContent =
                        minutes.toString().padStart(2, '0') + ':' +
                        seconds.toString().padStart(2, '0') + ' / ' +
                        tempoTotal.toString().padStart(2, '0') + ':00';

                    if (minutes < 1) {
                        loadingDetails.textContent = 'Iniciando análise Neocredit...';
                    } else if (minutes < 2) {
                        loadingDetails.textContent = 'Processando dados da análise...';
                    } else if (minutes < 3) {
                        loadingDetails.textContent = 'Consultando informações de crédito...';
                    } else {
                        loadingDetails.textContent = 'Finalizando processamento...';
                    }

                    if (elapsed > 4.5 * 60 * 1000) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Tempo limite excedido. A análise está demorando mais que o esperado.', 5000);
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;
                        limparBtn.disabled = false;
                        desabilitarBotoesPDF();
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
                loadingDetails.textContent = '';
            }

            // ========== EXIBIÇÃO DE RESULTADOS ==========
            function exibirResultados(data) {
                dadosAtuais = data;
                
                // Só habilita PDF se não estiver em PROCESSANDO
                if (data.dados_formatados && data.dados_formatados.status !== 'PROCESSANDO') {
                    habilitarBotoesPDF();
                } else {
                    desabilitarBotoesPDF();
                }
                
                resultSection.style.display = 'block';
                apiGrid.innerHTML = '';
                
                var dadosExibir = data.dados_formatados || {};
                
                var apiCard = document.createElement('div');
                apiCard.className = 'api-section';
                
                // VERIFICAR SE ESTÁ EM PROCESSANDO
                if (dadosExibir.status === 'PROCESSANDO' || dadosExibir.em_processamento === true) {
                    // NÃO mostrar os dados detalhados, apenas mensagem de processamento
                    var html = '<h3><i class="fas fa-sync-alt fa-spin"></i> Análise em Processamento</h3>';
                    html += '<div class="aviso-processando">';
                    html += '<div class="spinner-processando"></div>';
                    html += '<div class="aviso-processando-titulo"><i class="fas fa-clock"></i> Análise em Andamento</div>';
                    html += '<div class="aviso-processando-texto">';
                    html += '<strong>Status atual:</strong> <span class="status-container status-processando">PROCESSANDO</span><br><br>';
                    html += '<strong>Atenção:</strong> A esteira ainda está processando os dados. Os resultados ainda não estão disponíveis.<br><br>';
                    html += '<strong>O que está acontecendo?</strong><br>';
                    html += '• A análise de crédito está sendo processada<br>';
                    html += '• Os dados ainda não foram calculados<br>';
                    html += '• Aguarde até o status mudar para APROVAR, DERIVAR ou NEGAR<br><br>';
                    html += '<strong>ID da análise:</strong> ' + (data.id_analise || 'N/A') + '<br>';
                    html += '<strong>Documento consultado:</strong> ' + formatarDocumento(data.documento_limpo || '');
                    html += '</div>';
                    html += '<div class="contador-verificacao" id="contador-verificacao">';
                    html += 'Verificando status automaticamente...';
                    html += '</div>';
                    html += '<div class="aviso-processando-acoes">';
                    html += '<button onclick="consultarStatusAnalise(\'' + data.id_analise + '\')" class="btn-verificar-status"><i class="fas fa-sync-alt"></i> Verificar Status Agora</button>';
                    html += '</div>';
                    html += '</div>';
                    
                    // Ocultar botão de salvar
                    html += '<div class="salvar-container" style="display:none;">';
                    html += '<button class="btn-salvar-db" id="salvar-dados-btn">';
                    html += '<i class="fas fa-database"></i> Salvar no Banco de Dados';
                    html += '</button>';
                    html += '</div>';
                    
                } else {
                    // SE NÃO ESTIVER PROCESSANDO, mostrar os dados normalmente
                    apiCard.classList.add('com-dados');
                    
                    var html = '<h3><i class="fas fa-chart-line"></i> Dados da Análise - Neocredit</h3>';
                    
                    // LINHA 1 - Identificação
                    html += '<div class="linha-resultados">';
                    html += criarColunaResultado('ID Análise', data.id_analise || 'N/A', 'fingerprint');
                    html += criarColunaResultado('Razão Social', dadosExibir.razao_social || 'N/A', 'building');
                    html += criarColunaResultado('Documento', formatarDocumento(dadosExibir.documento || ''), 'id-card');
                    html += '</div>';

                    // LINHA 2 - Avaliação de Risco
                    html += '<div class="linha-resultados">';
                    html += criarColunaResultado('Risco', dadosExibir.risco || 'N/A', 'exclamation-triangle');
                    html += criarColunaResultado('Score', dadosExibir.score || 'N/A', 'chart-bar');
                    html += criarColunaResultado('Classificação de Risco', dadosExibir.classificacao_risco || 'N/A', 'sitemap');
                    html += '</div>';

                    // LINHA 3 - Resultado/Condições
                    html += '<div class="linha-resultados">';
                    var limite = dadosExibir.limite_aprovado || 0;
                    html += criarColunaResultado('Limite Aprovado', 'R$ ' + formatarMoeda(limite), 'money-bill-wave');
                    html += criarColunaResultado('Data Validade Limite', dadosExibir.data_validade || 'N/A', 'calendar-alt');

                    var status = dadosExibir.status || '';
                    var statusInfo = definirStatus(status);
                    html += '<div class="coluna-resultado">';
                    html += '<div class="info-label"><i class="fas fa-info-circle"></i> Status:</div>';
                    html += '<div class="info-value"><span class="status-container ' + statusInfo.classeStatus + '">' + statusInfo.textoStatus + '</span>';
                    if (dadosExibir.status_detalhado && dadosExibir.status_detalhado !== status) {
                        html += '<div class="status-detalhe">' + dadosExibir.status_detalhado + '</div>';
                    }
                    html += '</div></div>';
                    html += '</div>';

                    // BOTÃO SALVAR NO BANCO DE DADOS
                    html += '<div class="salvar-container">';
                    html += '<button class="btn-salvar-db" id="salvar-dados-btn">';
                    html += '<i class="fas fa-database"></i> Salvar no Banco de Dados';
                    html += '</button>';
                    html += '</div>';
                }
                
                apiCard.innerHTML = html;
                apiGrid.appendChild(apiCard);

                // Configurar os botões após criar o HTML
                setTimeout(function() {
                    salvarDadosBtn = document.getElementById('salvar-dados-btn');
                    if (salvarDadosBtn) {
                        salvarDadosBtn.addEventListener('click', salvarNoBancoDeDados);
                    }

                    var baixarJsonBtn = document.getElementById('baixar-json-btn');
                    if (baixarJsonBtn) {
                        baixarJsonBtn.addEventListener('click', function() {
                            salvarComoJSON();
                        });
                    }

                    atualizarURLsPDF();
                }, 100);
            }

            function criarColunaResultado(rotulo, valor, icone) {
                return '<div class="coluna-resultado">' +
                    '<div class="info-label"><i class="fas fa-' + icone + '"></i> ' + rotulo + ':</div>' +
                    '<div class="info-value">' + (valor || 'N/A') + '</div>' +
                    '</div>';
            }

            // ========== VERIFICAÇÃO PERIÓDICA ==========
            function iniciarVerificacaoPeriodica(idAnalise) {
                if (!idAnalise) return;
                
                verificacaoAtiva = true;
                var tentativa = 0;
                var maxTentativas = 60; // 10 minutos (60 tentativas x 10 segundos)
                
                // Parar qualquer verificação anterior
                if (verificacaoInterval) {
                    clearInterval(verificacaoInterval);
                }
                
                verificacaoInterval = setInterval(() => {
                    tentativa++;
                    
                    if (tentativa > maxTentativas) {
                        clearInterval(verificacaoInterval);
                        verificacaoAtiva = false;
                        document.getElementById('contador-verificacao').innerHTML = 
                            '❌ Verificação automática encerrada após 10 minutos.';
                        showError('Tempo máximo de verificação excedido. A análise ainda está em processamento.', 5000);
                        return;
                    }
                    
                    // Atualizar contador
                    var contadorElement = document.getElementById('contador-verificacao');
                    if (contadorElement) {
                        contadorElement.innerHTML = 
                            '🔄 Verificando status... (Tentativa ' + tentativa + ' de ' + maxTentativas + ')';
                    }
                    
                    // Fazer consulta de status
                    fetch(window.location.href + '?action=consulta_status&id=' + idAnalise + '&nocache=' + Date.now(), {
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache'
                        },
                        cache: 'no-store'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            console.log('Erro ao verificar status:', data.error);
                            return;
                        }
                        
                        console.log('Status verificado:', data.status_campos, 'Processando:', data.esta_processando);
                        
                        // Verificar se ainda está processando
                        if (data.esta_processando) {
                            console.log('Ainda processando... tentativa', tentativa);
                        } else {
                            // Status mudou! Parar verificação e atualizar dados
                            clearInterval(verificacaoInterval);
                            verificacaoAtiva = false;
                            
                            // Buscar dados completos
                            fetch(window.location.href + '?nocache=' + Date.now(), {
                                method: 'POST',
                                body: new FormData(),
                                headers: {
                                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                                    'Pragma': 'no-cache'
                                }
                            })
                            .then(response => response.json())
                            .then(novosDados => {
                                if (novosDados.success) {
                                    showSuccess('✅ Análise concluída! Status atualizado para: ' + 
                                        (novosDados.dados_formatados?.status || 'CONCLUÍDO'), 4000);
                                    
                                    // Atualizar a exibição
                                    dadosAtuais = novosDados;
                                    setTimeout(() => {
                                        exibirResultados(novosDados);
                                        atualizarURLsPDF();
                                    }, 1000);
                                }
                            })
                            .catch(error => {
                                console.error('Erro ao buscar dados atualizados:', error);
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Erro na verificação:', error);
                    });
                }, 10000); // Verificar a cada 10 segundos
            }

            // ========== FUNÇÃO PARA BAIXAR JSON ==========
            function salvarComoJSON() {
                if (!dadosAtuais) {
                    showError('Nenhum dado disponível para salvar. Por favor, realize uma consulta primeiro.', 4000);
                    return;
                }

                try {
                    const dadosParaSalvar = {
                        timestamp: new Date().toISOString(),
                        origem: 'API Neocredit - NOROAÇO',
                        sistema: 'Gerenciador de Crédito',
                        versao: '1.0',
                        consulta: dadosAtuais
                    };

                    const dataStr = JSON.stringify(dadosParaSalvar, null, 2);
                    const dataBlob = new Blob([dataStr], {
                        type: 'application/json;charset=utf-8'
                    });
                    const url = URL.createObjectURL(dataBlob);

                    const link = document.createElement('a');
                    link.href = url;
                    link.download = `consulta_neocredit_${dadosAtuais.documento_limpo}_${formatarDataParaArquivo()}.json`;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);

                    setTimeout(() => URL.revokeObjectURL(url), 100);
                    showSuccess('JSON baixado com sucesso!', 3000);
                } catch (error) {
                    console.error('Erro ao salvar JSON:', error);
                    showError('Erro ao salvar JSON: ' + error.message, 5000);
                }
            }

            // ========== FUNÇÕES UTILITÁRIAS ==========
            function definirStatus(status) {
                if (!status) return {
                    classeStatus: 'status-analise',
                    textoStatus: 'N/A'
                };

                var s = status.toLowerCase();
                var classeStatus = 'status-analise';
                var textoStatus = status;

                // Mantém exatamente o status que veio da API
                if (s === 'aprovar' || s === 'aprovado' || s === 'liberado') {
                    classeStatus = 'status-aprovar';
                } else if (s === 'reprovar' || s === 'reprovado' || s === 'negado') {
                    classeStatus = 'status-reprovar';
                } else if (s === 'derivar' || s === 'em análise' || s === 'pendente') {
                    classeStatus = 'status-derivar';
                } else if (s === 'processando') {
                    classeStatus = 'status-processando';
                }

                return {
                    classeStatus: classeStatus,
                    textoStatus: textoStatus.toUpperCase()
                };
            }

            function formatarDocumento(doc) {
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

            function formatarDataParaArquivo() {
                const now = new Date();
                return now.getFullYear() +
                    ('0' + (now.getMonth() + 1)).slice(-2) +
                    ('0' + now.getDate()).slice(-2) + '_' +
                    ('0' + now.getHours()).slice(-2) +
                    ('0' + now.getMinutes()).slice(-2) +
                    ('0' + now.getSeconds()).slice(-2);
            }

            // Função global para consulta de status
            window.consultarStatusAnalise = function(idAnalise) {
                if (!idAnalise) return;

                loadingDiv.style.display = 'block';
                loadingText.textContent = 'Consultando status da análise...';
                hideError();
                hideSuccess();

                fetch(window.location.href + '?action=consulta_status&id=' + idAnalise + '&nocache=' + Date.now(), {
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache'
                        },
                        cache: 'no-store'
                    })
                    .then(response => response.json())
                    .then(data => {
                        loadingDiv.style.display = 'none';

                        if (!data.success) {
                            showError('Erro ao consultar status: ' + data.error, 5000);
                            return;
                        }

                        if (data.esta_processando) {
                            showError('A análise ainda está em PROCESSANDO. Status atual: ' + (data.status_campos || 'PROCESSANDO'), 4000);
                        } else if (data.pronto && data.dados_completos) {
                            showSuccess('Análise concluída! Atualizando dados...', 3000);
                            // Atualizar dados locais
                            dadosAtuais.dados_formatados = data.dados_completos;
                            setTimeout(() => {
                                exibirResultados(dadosAtuais);
                                atualizarURLsPDF();
                            }, 500);
                        } else {
                            showError('Status atual: ' + (data.status_campos || 'N/A'), 4000);
                        }
                    })
                    .catch(error => {
                        loadingDiv.style.display = 'none';
                        showError('Erro na consulta: '.error.message, 5000);
                    });
            };

            // ========== EXPORTAÇÃO PARA OS SCRIPTS DE PDF ==========
            window.obterDadosConsulta = function() {
                return dadosAtuais;
            };

            // Adicionar evento para limpar ao pressionar Esc
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && (dadosAtuais || documentoInput.value.trim() !== '')) {
                    confirmarLimpeza();
                }
            });
        });
    </script>
</body>

</html>