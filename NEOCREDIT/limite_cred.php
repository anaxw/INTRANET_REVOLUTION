<?php
// apenas_api_neocredit.php - Versão completa e funcional da API Neocredit

// Configurações de segurança e debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors_neocredit.log');
ini_set('max_execution_time', 300);
ini_set('default_socket_timeout', 60);
ini_set('memory_limit', '512M');

// Log inicial
error_log("=============================================");
error_log("INICIANDO API NEOCREDIT - " . date('Y-m-d H:i:s'));
error_log("=============================================");

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

    // Verificar se análise está realmente pronta
    private static function analiseEstaPronta($dados)
    {
        if (!is_array($dados)) {
            return false;
        }

        error_log("[NEOCREDIT] Verificando se análise está pronta");

        // 1. Verificar status principal
        $status_principal = isset($dados['status']) ? strtoupper(trim($dados['status'])) : '';
        $status_finais_principal = array('APROVADO', 'REPROVADO', 'NEGADO', 'LIBERADO', 'CONCLUIDO', 'FINALIZADO', 'CONCLUÍDO');

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
                $status_campos = strtoupper(trim($campos['status']));
                $status_finais = array('DERIVAR', 'APROVAR', 'REPROVAR', 'NEGAR', 'LIBERAR', 'CONCLUIR', 'APROVADO', 'REPROVADO', 'NEGADO', 'LIBERADO', 'CONCLUIDO');

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

                // Verificar se está pronta
                if (self::analiseEstaPronta($dados)) {
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

    // Função para extrair dados formatados da análise
    public static function extrairDadosFormatados($dados_analise)
    {
        error_log("[NEOCREDIT] Extraindo dados formatados");

        // Valores padrão
        $resultado = array(
            'razao_social' => 'N/A',
            'documento' => '',
            'risco' => 'N/A',
            'score' => 'N/A',
            'classificacao_risco' => 'N/A',
            'limite_aprovado' => 0,
            'data_validade' => 'N/A',
            'status' => 'EM ANÁLISE',
            'status_detalhado' => 'Aguardando processamento',
            'id_analise' => isset($dados_analise['id']) ? $dados_analise['id'] : 'N/A'
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

            // Status - prioridade para status dos campos
            $status_campos = self::extrairValorCampo($campos, array('status', 'situacao', 'estado'));
            if (!empty($status_campos) && $status_campos !== 'N/A') {
                $resultado['status'] = strtoupper(trim($status_campos));
                $resultado['status_detalhado'] = $status_campos;
            }
        }

        // Se não encontrou status nos campos, verificar no nível principal
        if ($resultado['status'] === 'EM ANÁLISE' && isset($dados_analise['status']) && !empty($dados_analise['status'])) {
            $resultado['status'] = strtoupper(trim($dados_analise['status']));
            $resultado['status_detalhado'] = $dados_analise['status'];
        }

        // Determinar status final baseado no limite
        if ($resultado['limite_aprovado'] > 0 && $resultado['status'] === 'EM ANÁLISE') {
            $resultado['status'] = 'APROVADO';
            $resultado['status_detalhado'] = 'Limite aprovado: R$ ' . number_format($resultado['limite_aprovado'], 2, ',', '.');
        } elseif ($resultado['limite_aprovado'] == 0 && isset($dados_analise['campos']['limite_aprovado'])) {
            $resultado['status'] = 'REPROVADO';
            $resultado['status_detalhado'] = 'Limite não aprovado';
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
}

// =============================================
// PROCESSADOR DE REQUISIÇÕES
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

    error_log("=============================================");
    error_log("RECEBENDO REQUISIÇÃO POST");
    error_log("=============================================");

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
                        'status_detalhado' => $e->getMessage(),
                        'id_analise' => $id_analise
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
            'timestamp' => date('Y-m-d H:i:s')
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
            'timestamp' => date('Y-m-d H:i:s')
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    error_log("=============================================");
    error_log("FIM DA REQUISIÇÃO");
    error_log("=============================================");
    exit;
}

// Endpoint para status do sistema
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');

    echo json_encode(array(
        'status' => 'online',
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        'php_version' => PHP_VERSION,
        'api_neocredit_available' => ApiNeocredit::curlDisponivel(),
        'server_info' => $_SERVER['SERVER_SOFTWARE']
    ));
    exit;
}

// Endpoint para consulta rápida de status
if (isset($_GET['action']) && $_GET['action'] === 'consulta_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $dados = ApiNeocredit::consultarAnalise($_GET['id']);
        $pronto = ApiNeocredit::analiseEstaPronta($dados);

        echo json_encode(array(
            'success' => true,
            'status' => isset($dados['status']) ? $dados['status'] : 'N/A',
            'status_campos' => isset($dados['campos']['status']) ? $dados['campos']['status'] : 'N/A',
            'pronto' => $pronto,
            'limite_aprovado' => isset($dados['campos']['limite_aprovado']) ? $dados['campos']['limite_aprovado'] : 'N/A',
            'timestamp' => date('Y-m-d H:i:s'),
            'dados_completos' => $pronto ? ApiNeocredit::extrairDadosFormatados($dados) : null
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
    <link rel="stylesheet" type="text/css" href="limite_cred_style.css">
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
                        <button id="limpar-btn">
                            <i class="fas fa-broom"></i> Limpar
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
            var resultSection = document.getElementById('result-section');
            var apiGrid = document.getElementById('api-grid');
            var errorText = errorDiv.querySelector('.message-text');
            var successText = successDiv.querySelector('.message-text');
            var pdfResumoBtn = document.getElementById('pdf-resumo-btn');
            var pdfCompletoBtn = document.getElementById('pdf-completo-btn');

            var progressInterval;
            var startTime;
            var dadosAtuais = null;
            var salvarDadosBtn = null;

            // Timeouts para controle das mensagens
            var errorTimeout = null;
            var successTimeout = null;

            // URLs dos PDFs
            var pdfResumoUrl = 'limite_cred_pdf.php';
            var pdfCompletoUrl = 'limite_cred_pdf_detalhado.php';

            // ========== INICIALIZAÇÃO ==========
            inicializarSistema();

            function inicializarSistema() {
                inicializarEventListeners();
                desabilitarBotoesPDF(); // Desabilitar botões PDF inicialmente
                console.log('Sistema inicializado - Botões PDF desabilitados');
            }

            function inicializarEventListeners() {
                // Event listeners principais
                if (consultarBtn) consultarBtn.addEventListener('click', executarConsulta);
                if (limparBtn) limparBtn.addEventListener('click', limparConsulta);

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

                // Fechar mensagem ao clicar nela (exceto no botão de fechar)
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

            // ========== FUNÇÕES DE CONTROLE DOS BOTÕES PDF ==========
            function habilitarBotoesPDF() {
                if (pdfResumoBtn && pdfCompletoBtn && dadosAtuais) {
                    // Adicionar classe enabled e remover estilos de desabilitado
                    pdfResumoBtn.classList.add('enabled');
                    pdfCompletoBtn.classList.add('enabled');
                    
                    // Adicionar href com parâmetros
                    var documento = dadosAtuais.documento_limpo || '';
                    var idAnalise = dadosAtuais.id_analise || '';
                    var timestamp = Date.now();
                    
                    if (documento) {
                        pdfResumoBtn.href = pdfResumoUrl + '?doc=' + encodeURIComponent(documento) + 
                                           '&id=' + encodeURIComponent(idAnalise) + 
                                           '&tipo=resumo&timestamp=' + timestamp;
                        
                        pdfCompletoBtn.href = pdfCompletoUrl + '?doc=' + encodeURIComponent(documento) + 
                                             '&id=' + encodeURIComponent(idAnalise) + 
                                             '&tipo=completo&timestamp=' + timestamp;
                        
                        console.log('Botões PDF habilitados para documento:', documento);
                    } else {
                        console.warn('Não foi possível habilitar botões PDF: documento não disponível');
                    }
                }
            }

            function desabilitarBotoesPDF() {
                if (pdfResumoBtn && pdfCompletoBtn) {
                    // Remover classe enabled
                    pdfResumoBtn.classList.remove('enabled');
                    pdfCompletoBtn.classList.remove('enabled');
                    
                    // Remover href para evitar cliques acidentais
                    pdfResumoBtn.removeAttribute('href');
                    pdfCompletoBtn.removeAttribute('href');
                    
                    console.log('Botões PDF desabilitados');
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
                                           '&dados=' + encodeURIComponent(JSON.stringify(dadosAtuais.dados_formatados || {}));
                        
                        pdfCompletoBtn.href = pdfCompletoUrl + '?doc=' + encodeURIComponent(documento) + 
                                             '&id=' + encodeURIComponent(idAnalise) + 
                                             '&tipo=completo&timestamp=' + timestamp + 
                                             '&dados=' + encodeURIComponent(JSON.stringify(dadosAtuais.dados_formatados || {}));
                        
                        console.log('URLs PDF atualizadas');
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

                // Preparar interface
                documentoInput.disabled = true;
                consultarBtn.disabled = true;
                limparBtn.disabled = true;
                
                // Desabilitar botões PDF durante a consulta
                desabilitarBotoesPDF();
                
                loadingDiv.style.display = 'block';
                resultSection.style.display = 'none';

                // Esconder mensagens ativas
                hideError();
                hideSuccess();

                loadingText.textContent = 'Consultando dados, aguarde...';
                loadingDetails.innerHTML = '';

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
                        limparBtn.disabled = false;

                        if (!data.success) {
                            showError('Erro: ' + (data.error || 'Erro desconhecido'), 5000);
                            return;
                        }

                        exibirResultados(data);
                        showSuccess('Consulta realizada com sucesso!', 3000);
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

            function limparConsulta() {
                documentoInput.value = '';
                resultSection.style.display = 'none';
                hideError();
                hideSuccess();
                dadosAtuais = null;
                
                // Desabilitar botões PDF ao limpar
                desabilitarBotoesPDF();
                
                console.log('Consulta limpa - Botões PDF desabilitados');
            }

            // ========== FUNÇÕES DE MENSAGENS TEMPORÁRIAS ==========
            function showError(msg, duration = 5000) {
                if (!errorDiv || !errorText) return;

                // Cancelar timeout anterior se existir
                if (errorTimeout) {
                    clearTimeout(errorTimeout);
                    errorTimeout = null;
                }

                // Esconder mensagem de sucesso se estiver visível
                if (successDiv.classList.contains('show')) {
                    hideSuccess();
                }

                // Configurar e mostrar mensagem de erro
                errorText.textContent = msg;

                // Remover classe de esconder se estiver ativa
                errorDiv.classList.remove('hiding');

                // Forçar reflow para reiniciar animação
                void errorDiv.offsetWidth;

                // Mostrar mensagem
                errorDiv.classList.add('show');

                // Configurar timeout para esconder automaticamente
                errorTimeout = setTimeout(function() {
                    hideError();
                }, duration);

                console.error('Erro:', msg);
            }

            function hideError() {
                if (!errorDiv) return;

                // Cancelar timeout se existir
                if (errorTimeout) {
                    clearTimeout(errorTimeout);
                    errorTimeout = null;
                }

                // Iniciar animação de saída
                errorDiv.classList.add('hiding');

                // Remover após animação
                setTimeout(function() {
                    errorDiv.classList.remove('show', 'hiding');
                }, 400);
            }

            function showSuccess(msg, duration = 5000) {
                if (!successDiv || !successText) return;

                // Cancelar timeout anterior se existir
                if (successTimeout) {
                    clearTimeout(successTimeout);
                    successTimeout = null;
                }

                // Esconder mensagem de erro se estiver visível
                if (errorDiv.classList.contains('show')) {
                    hideError();
                }

                // Configurar e mostrar mensagem de sucesso
                successText.textContent = msg;

                // Remover classe de esconder se estiver ativa
                successDiv.classList.remove('hiding');

                // Forçar reflow para reiniciar animação
                void successDiv.offsetWidth;

                // Mostrar mensagem
                successDiv.classList.add('show');

                // Configurar timeout para esconder automaticamente
                successTimeout = setTimeout(function() {
                    hideSuccess();
                }, duration);

                console.log('Sucesso:', msg);
            }

            function hideSuccess() {
                if (!successDiv) return;

                // Cancelar timeout se existir
                if (successTimeout) {
                    clearTimeout(successTimeout);
                    successTimeout = null;
                }

                // Iniciar animação de saída
                successDiv.classList.add('hiding');

                // Remover após animação
                setTimeout(function() {
                    successDiv.classList.remove('show', 'hiding');
                }, 400);
            }

            // ========== FUNÇÕES DE SALVAR DADOS ==========
            function salvarDados() {
                if (!dadosAtuais) {
                    showError('Nenhum dado disponível para salvar. Por favor, realize uma consulta primeiro.', 4000);
                    return;
                }

                const btnOriginalText = salvarDadosBtn.innerHTML;
                salvarDadosBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                salvarDadosBtn.disabled = true;

                try {
                    // Preparar dados para salvar
                    const dadosParaSalvar = {
                        timestamp: new Date().toISOString(),
                        origem: 'API Neocredit - NOROAÇO',
                        sistema: 'Gerenciador de Crédito',
                        versao: '1.0',
                        consulta: dadosAtuais
                    };

                    // 1. Salvar no localStorage para histórico
                    try {
                        const historico = JSON.parse(localStorage.getItem('historico_consultas_neocredit') || '[]');
                        historico.unshift({
                            data: new Date().toLocaleString('pt-BR'),
                            documento: dadosAtuais.documento_limpo,
                            razao_social: dadosAtuais.dados_formatados?.razao_social || 'N/A',
                            limite_aprovado: dadosAtuais.dados_formatados?.limite_aprovado || 0,
                            status: dadosAtuais.dados_formatados?.status || 'N/A'
                        });

                        // Manter apenas os últimos 50 registros
                        if (historico.length > 50) historico.length = 50;

                        localStorage.setItem('historico_consultas_neocredit', JSON.stringify(historico));
                    } catch (e) {
                        console.warn('Não foi possível salvar no localStorage:', e.message);
                    }

                    // 2. Gerar arquivo JSON para download
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

                    // Limpar URL após download
                    setTimeout(() => URL.revokeObjectURL(url), 100);

                    // Feedback visual
                    setTimeout(() => {
                        showSuccess('Dados salvos com sucesso! O arquivo foi baixado.', 3000);

                        // Restaurar botão
                        salvarDadosBtn.innerHTML = btnOriginalText;
                        salvarDadosBtn.disabled = false;

                        // Efeito visual de confirmação
                        salvarDadosBtn.style.background = 'linear-gradient(135deg, #2E7D32 0%, #1B5E20 100%)';
                        setTimeout(() => {
                            salvarDadosBtn.style.background = 'linear-gradient(135deg, #4CAF50 0%, #45a049 100%)';
                        }, 1000);
                    }, 800);

                } catch (error) {
                    console.error('Erro ao salvar dados:', error);
                    showError('Erro ao salvar dados: ' + error.message, 5000);
                    salvarDadosBtn.innerHTML = btnOriginalText;
                    salvarDadosBtn.disabled = false;
                }
            }

            // ========== FUNÇÕES DE PROGRESSO ==========
            function iniciarProgresso() {
                startTime = new Date().getTime();
                var maxTime = 5 * 60 * 1000; // 5 minutos

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

                    // Mensagens informativas
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
                
                // Habilitar botões PDF após ter dados
                habilitarBotoesPDF();
                
                resultSection.style.display = 'block';
                apiGrid.innerHTML = '';

                // Usar dados formatados
                var dadosExibir = data.dados_formatados || {};

                // Dados da API Neocredit
                var apiCard = document.createElement('div');
                apiCard.className = 'api-section';

                var html = '<h3><i class="fas fa-chart-line"></i> Dados da Análise - Neocredit</h3>';

                // Se status for "EM ANÁLISE", mostrar mensagem especial
                if (dadosExibir.status === 'EM ANÁLISE' || dadosExibir.status === 'ERRO NA CONSULTA') {
                    html += '<div class="aviso-analise">';
                    html += '<div class="aviso-titulo"><i class="fas fa-clock"></i> Análise em Andamento</div>';
                    html += '<div class="aviso-texto">';
                    html += 'A análise ainda está sendo processada pela Neocredit. ';
                    html += 'Isso pode levar alguns minutos. ';
                    html += '<br><br>';
                    html += '<strong>Status atual:</strong> ' + (dadosExibir.status_detalhado || dadosExibir.status || 'Processando...');
                    html += '<br>';
                    html += '<strong>ID para consulta posterior:</strong> ' + data.id_analise;
                    html += '</div>';
                    html += '<div class="aviso-acoes">';
                    html += '<button onclick="consultarStatusAnalise(\'' + data.id_analise + '\')" class="btn-atualizar"><i class="fas fa-sync-alt"></i> Atualizar Status</button>';
                    html += '</div>';
                    html += '</div>';
                }

                // ============================================
                // 3 LINHAS DE 3 COLUNAS
                // ============================================

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

                // Limite Aprovado
                var limite = dadosExibir.limite_aprovado || 0;
                html += criarColunaResultado('Limite Aprovado', 'R$ ' + formatarMoeda(limite), 'money-bill-wave');

                // Data Validade
                html += criarColunaResultado('Data Validade Limite', dadosExibir.data_validade || 'N/A', 'calendar-alt');

                // Status
                var status = dadosExibir.status || '';
                var statusInfo = definirStatus(status);
                html += '<div class="coluna-resultado">';
                html += '<div class="info-label"><i class="fas fa-info-circle"></i> Status:</div>';
                html += '<div class="info-value"><span class="status-container ' + statusInfo.classeStatus + '">' + statusInfo.textoStatus + '</span>';
                if (dadosExibir.status_detalhado && dadosExibir.status_detalhado !== status) {
                    html += '<div class="status-detalhe">' + dadosExibir.status_detalhado + '</div>';
                }
                html += '</div></div>';

                html += '</div>'; // Fecha linha 3

                // Score se disponível e for numérico
                if (dadosExibir.score && dadosExibir.score !== 'N/A') {
                    var scoreNumero = extrairScoreNumerico(dadosExibir.score);
                    if (scoreNumero !== null) {
                        html += '<div class="score-container">' + criarVisualizacaoScore(scoreNumero) + '</div>';
                    }
                }

                // BOTÃO SALVAR DADOS
                html += '<div class="api-section-salvar-btn-container">';
                html += '<button id="salvar-dados-btn">';
                html += '<i class="fas fa-save"></i> Salvar';
                html += '</button>';
                html += '</div>';

                apiCard.innerHTML = html;
                apiGrid.appendChild(apiCard);

                // Configurar o botão após criar o HTML
                setTimeout(function() {
                    salvarDadosBtn = document.getElementById('salvar-dados-btn');
                    if (salvarDadosBtn) {
                        salvarDadosBtn.addEventListener('click', salvarDados);
                    }
                    
                    // Atualizar URLs dos PDFs com os dados mais recentes
                    atualizarURLsPDF();
                }, 100);
            }

            // Função auxiliar para criar cada coluna
            function criarColunaResultado(rotulo, valor, icone) {
                return '<div class="coluna-resultado">' +
                    '<div class="info-label"><i class="fas fa-' + icone + '"></i> ' + rotulo + ':</div>' +
                    '<div class="info-value">' + (valor || 'N/A') + '</div>' +
                    '</div>';
            }

            // ========== FUNÇÕES UTILITÁRIAS ==========
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
                if (!score || score === 'N/A') return null;

                // Tentar extrair números do score
                var matches = score.toString().match(/(\d+[\.,]?\d*)/);
                if (!matches) return null;

                var scoreNumero = parseFloat(matches[0].replace(',', '.'));
                if (isNaN(scoreNumero)) return null;

                // Se for maior que 1000, pode estar em escala 0-1000
                // Se for menor que 10, pode estar em escala 0-10
                if (scoreNumero > 1000) {
                    scoreNumero = 1000;
                } else if (scoreNumero <= 10) {
                    scoreNumero = scoreNumero * 100; // Converte de 0-10 para 0-1000
                }

                return Math.min(Math.round(scoreNumero), 1000);
            }

            function definirStatus(status) {
                if (!status) return {
                    classeStatus: 'status-analise',
                    textoStatus: 'N/A'
                };

                var s = status.toUpperCase();
                var classeStatus = 'status-analise';
                var textoStatus = status;

                if (s.includes('APROVAR') || s.includes('APROVADO') || s.includes('LIBERADO')) {
                    classeStatus = 'status-aprovar';
                    textoStatus = 'APROVADO';
                } else if (s.includes('REPROVAR') || s.includes('REPROVADO') || s.includes('NEGADO')) {
                    classeStatus = 'status-reprovar';
                    textoStatus = 'REPROVADO';
                } else if (s.includes('DERIVAR') || s.includes('ANALISE') || s.includes('PENDENTE')) {
                    classeStatus = 'status-derivar';
                    textoStatus = 'EM ANÁLISE';
                }

                return {
                    classeStatus: classeStatus,
                    textoStatus: textoStatus
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

                fetch(window.location.href + '?action=consulta_status&id=' + idAnalise)
                    .then(response => response.json())
                    .then(data => {
                        loadingDiv.style.display = 'none';

                        if (!data.success) {
                            showError('Erro ao consultar status: ' + data.error, 5000);
                            return;
                        }

                        if (data.pronto && data.dados_completos) {
                            showSuccess('Análise concluída! Atualizando dados...', 3000);
                            // Atualizar dados atuais
                            if (dadosAtuais) {
                                dadosAtuais.dados_formatados = data.dados_completos;
                                setTimeout(() => {
                                    exibirResultados(dadosAtuais);
                                    atualizarURLsPDF();
                                }, 500);
                            }
                        } else {
                            showError('A análise ainda está em processamento. Status: ' + data.status, 4000);
                        }
                    })
                    .catch(error => {
                        loadingDiv.style.display = 'none';
                        showError('Erro na consulta: ' + error.message, 5000);
                    });
            };

            // ========== EXPORTAÇÃO PARA OS SCRIPTS DE PDF ==========
            window.obterDadosConsulta = function() {
                return dadosAtuais;
            };
        });
    </script>
</body>

</html>