<?php
// todas_conexoes.php - Gerenciador robusto de todas as conexões - VERSÃO ATUALIZADA COM SELECT DE USUÁRIO

// Configurações de segurança e estabilidade
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
ini_set('max_execution_time', 300);
ini_set('default_socket_timeout', 30);
ini_set('memory_limit', '512M');

// Verificar se a extensão Firebird está carregada
if (!extension_loaded('interbase') && !extension_loaded('firebird')) {
    error_log('CRÍTICO: Extensão Firebird não está instalada');
    header('HTTP/1.1 500 Internal Server Error');
    die(json_encode(array('error' => 'Extensão Firebird não está instalada no servidor')));
}

class TodasConexoes
{
    private static $configuracoes = array(
        'BARRA_MANSA' => array(
            'host' => '10.10.94.15:c:/SIC_BM/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        ),
        'BOTUCATU' => array(
            'host' => '10.10.94.15:c:/SIC_Botucatu/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        ),
        'NOROACO' => array(
            'host' => '10.10.94.15:c:/SIC/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 20
        ),
        'NOROMETAL' => array(
            'host' => '10.10.94.15:c:/SIC_Lins/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 20
        ),
        'RIO_PRETO' => array(
            'host' => '10.10.94.15:c:/SIC_RP/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        )
    );

    // Cache de conexões ativas
    private static $connections_pool = array();
    private static $last_connection_time = array();
    private static $connection_errors = array();

    // Estatísticas para monitoramento
    private static $stats = array(
        'total_connections' => 0,
        'failed_connections' => 0,
        'successful_queries' => 0,
        'failed_queries' => 0
    );

    // Mapeamento de nomes para exibição
    private static $nomes_unidades = array(
        'BARRA_MANSA' => 'BARRA MANSA',
        'BOTUCATU' => 'BOTUCATU',
        'NOROACO' => 'VOTUPORANGA',
        'NOROMETAL' => 'LINS',
        'RIO_PRETO' => 'S. J. RIO PRETO'
    );

    // Lista de usuários permitidos para alteração
    private static $usuarios_permitidos = array(
        'ANDREA', 'EFANECO', 'MARCUS', 'ISABELLY', 'GABRIELAF', 'EMANUELE'
    );

    // Função para obter estatísticas
    public static function getStats()
    {
        return self::$stats;
    }

    // Função para obter nomes das unidades
    public static function getNomesUnidades()
    {
        return self::$nomes_unidades;
    }

    // Função para obter nome amigável de uma unidade
    public static function getNomeUnidade($codigo_unidade)
    {
        return isset(self::$nomes_unidades[$codigo_unidade])
            ? self::$nomes_unidades[$codigo_unidade]
            : $codigo_unidade;
    }

    // Função para obter lista de usuários permitidos
    public static function getUsuariosPermitidos()
    {
        return self::$usuarios_permitidos;
    }

    // Função auxiliar para converter objeto em array
    public static function obj2arr($ob, $numeric = FALSE)
    {
        if (!is_object($ob)) {
            return $ob;
        }

        if ($numeric === TRUE) {
            $arr = get_object_vars($ob);
            $arr2 = array();
            for ($i = 0; $i < count($arr); $i++) {
                $arr2[$i] = $arr[key($arr)];
                next($arr);
            }
            return $arr2;
        } else {
            return get_object_vars($ob);
        }
    }

    // Verificar se conexão está ativa
    private static function isConnectionAlive($connection)
    {
        if (!$connection) return false;

        try {
            $test_query = @ibase_query($connection, "SELECT 1 FROM RDB\$DATABASE");
            if ($test_query) {
                ibase_free_result($test_query);
                return true;
            }
        } catch (Exception $e) {
            error_log("Teste de conexão falhou: " . $e->getMessage());
        }
        return false;
    }

    // Limpar conexões antigas do pool
    private static function cleanupOldConnections()
    {
        $now = time();
        foreach (self::$connections_pool as $unidade => $connection) {
            if (isset(self::$last_connection_time[$unidade])) {
                // Fechar conexões com mais de 5 minutos
                if (($now - self::$last_connection_time[$unidade]) > 300) {
                    @ibase_close($connection);
                    unset(self::$connections_pool[$unidade]);
                    unset(self::$last_connection_time[$unidade]);
                    error_log("Conexão limpa do pool: {$unidade}");
                }
            }
        }
    }

    // Conectar com retry automático e fallback
    public static function conectar_unidade($unidade, $max_retries = 2)
    {
        self::cleanupOldConnections();

        // Verificar se há erro recente (circuit breaker)
        if (isset(self::$connection_errors[$unidade])) {
            $last_error_time = self::$connection_errors[$unidade]['time'];
            $error_count = self::$connection_errors[$unidade]['count'];

            // Se muitos erros recentes, evitar tentar novamente muito rápido
            if ($error_count > 3 && (time() - $last_error_time) < 60) {
                throw new Exception("Unidade {$unidade} temporariamente indisponível devido a erros recentes");
            }
        }

        // Tentar usar conexão do pool primeiro
        if (
            isset(self::$connections_pool[$unidade]) &&
            self::isConnectionAlive(self::$connections_pool[$unidade])
        ) {
            self::$last_connection_time[$unidade] = time();
            return self::$connections_pool[$unidade];
        }

        if (!isset(self::$configuracoes[$unidade])) {
            throw new Exception("Unidade {$unidade} não encontrada na configuração");
        }

        $config = self::$configuracoes[$unidade];
        $retry_count = 0;

        while ($retry_count <= $max_retries) {
            try {
                error_log("Tentativa {$retry_count} de conexão com {$unidade}");

                // Configurar timeout específico
                ini_set('default_socket_timeout', $config['timeout']);

                $con = @ibase_connect(
                    $config['host'],
                    $config['user'],
                    $config['pass'],
                    $config['charset'],
                    0,
                    3
                );

                if ($con) {
                    // Adicionar ao pool
                    self::$connections_pool[$unidade] = $con;
                    self::$last_connection_time[$unidade] = time();

                    // Limpar contador de erros em caso de sucesso
                    if (isset(self::$connection_errors[$unidade])) {
                        unset(self::$connection_errors[$unidade]);
                    }

                    self::$stats['total_connections']++;
                    error_log("Conexão estabelecida com {$unidade}");
                    return $con;
                } else {
                    $error = ibase_errmsg() ?: 'Erro desconhecido de conexão';
                    throw new Exception($error);
                }
            } catch (Exception $e) {
                $retry_count++;
                $error_msg = "Tentativa {$retry_count} falhou para {$unidade}: " . $e->getMessage();
                error_log($error_msg);

                // Registrar erro para circuit breaker
                if (!isset(self::$connection_errors[$unidade])) {
                    self::$connection_errors[$unidade] = array(
                        'count' => 1,
                        'time' => time(),
                        'last_error' => $e->getMessage()
                    );
                } else {
                    self::$connection_errors[$unidade]['count']++;
                    self::$connection_errors[$unidade]['time'] = time();
                    self::$connection_errors[$unidade]['last_error'] = $e->getMessage();
                }

                self::$stats['failed_connections']++;

                if ($retry_count > $max_retries) {
                    $final_error = "Falha após {$max_retries} tentativas na unidade {$unidade}: " . $e->getMessage();
                    error_log($final_error);
                    throw new Exception($final_error);
                }

                // Esperar progressivamente entre tentativas
                usleep(500000 * $retry_count); // 0.5s, 1s, 1.5s
            }
        }

        throw new Exception("Erro crítico de conexão com {$unidade}");
    }

    // Consulta segura com timeout
    public static function consulta_unidade($unidade, $sql, $timeout = 30)
    {
        $con = null;
        $start_time = microtime(true);

        try {
            $con = self::conectar_unidade($unidade);

            // Configurar timeout da consulta
            ini_set('max_execution_time', $timeout);

            $tabela = array();
            $row = 0;

            $consulta = @ibase_query($con, $sql);

            if (!$consulta) {
                $error = ibase_errmsg() ?: 'Erro desconhecido na consulta';
                throw new Exception("Erro na consulta {$unidade}: " . $error);
            }

            while ($objeto = ibase_fetch_object($consulta)) {
                // Verificar timeout
                if ((microtime(true) - $start_time) > $timeout) {
                    throw new Exception("Timeout na consulta da unidade {$unidade}");
                }

                $linha = self::obj2arr($objeto, true);
                foreach ($linha as $indice => $valor) {
                    $tabela[$row][$indice] = ($valor === null) ? '' : (string)$valor;
                }
                $row++;

                // Limitar quantidade de registros para evitar estouro de memória
                if ($row > 10000) {
                    throw new Exception("Limite de registros excedido na unidade {$unidade}");
                }
            }

            ibase_free_result($consulta);
            self::$stats['successful_queries']++;

            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 3);
            error_log("Consulta {$unidade} concluída em {$duration}s - {$row} registros");

            return $tabela;
        } catch (Exception $e) {
            self::$stats['failed_queries']++;

            // Fechar conexão em caso de erro
            if ($con) {
                @ibase_close($con);
                unset(self::$connections_pool[$unidade]);
            }

            error_log("Erro consulta {$unidade}: " . $e->getMessage() . " - SQL: " . substr($sql, 0, 200));
            throw $e;
        }
    }

    // Função para buscar CODIC com tolerância a falhas
    public static function buscar_codic_por_documento($documento)
    {
        $documento_limpo = preg_replace('/[^0-9]/', '', $documento);

        if (strlen($documento_limpo) == 11) {
            $campo = 'ncpf';
            $formatos = self::gerarFormatosCpf($documento_limpo);
        } else if (strlen($documento_limpo) == 14) {
            $campo = 'ncgc';
            $formatos = self::gerarFormatosCnpj($documento_limpo);
        } else {
            throw new Exception("Documento inválido: deve ter 11 (CPF) ou 14 (CNPJ) dígitos");
        }

        $resultados = array();
        $unidades_processadas = 0;

        foreach (self::$configuracoes as $unidade => $config) {
            try {
                // Processar unidades com pequeno delay entre elas para evitar sobrecarga
                if ($unidades_processadas > 0) {
                    usleep(100000); // 0.1s entre unidades
                }

                $con = self::conectar_unidade($unidade);
                $encontrado = false;
                $codic_encontrado = null;
                $formato_encontrado = null;

                // Testar cada formato
                foreach ($formatos as $formato) {
                    $sql = "SELECT FIRST 1 c.codic FROM arqcad c 
                            WHERE c.tipoc = 'C' AND TRIM(c.{$campo}) = '" . trim($formato) . "'";

                    $consulta = @ibase_query($con, $sql);

                    if ($consulta) {
                        if ($objeto = ibase_fetch_object($consulta)) {
                            $linha = self::obj2arr($objeto, true);
                            $codic_encontrado = isset($linha[0]) ? (string)$linha[0] : null;
                            $encontrado = true;
                            $formato_encontrado = $formato;
                            ibase_free_result($consulta);
                            break;
                        }
                        ibase_free_result($consulta);
                    }

                    // Pequena pausa entre tentativas de formato
                    usleep(50000);
                }

                $resultados[$unidade] = array(
                    'codic' => $codic_encontrado,
                    'encontrado' => $encontrado,
                    'erro' => null,
                    'formato_encontrado' => $formato_encontrado,
                    'campo_busca' => $campo
                );

                $unidades_processadas++;
            } catch (Exception $e) {
                error_log("Erro buscar CODIC {$unidade}: " . $e->getMessage());
                $resultados[$unidade] = array(
                    'codic' => null,
                    'encontrado' => false,
                    'erro' => $e->getMessage(),
                    'formato_encontrado' => null,
                    'campo_busca' => $campo
                );
            }
        }

        return $resultados;
    }

    // Gerar todos os formatos possíveis para CPF
    private static function gerarFormatosCpf($cpf_limpo)
    {
        $formatos = array();

        // Formato 1: 217.997.208-37 (padrão)
        $formatos[] = substr($cpf_limpo, 0, 3) . '.' .
            substr($cpf_limpo, 3, 3) . '.' .
            substr($cpf_limpo, 6, 3) . '-' .
            substr($cpf_limpo, 9, 2);

        // Formato 2: 21799720837 (sem formatação)
        $formatos[] = $cpf_limpo;

        // Formato 3: Com espaços extras
        $formatos[] = ' ' . $formatos[0];
        $formatos[] = $formatos[0] . ' ';
        $formatos[] = ' ' . $formatos[0] . ' ';

        // Formato 4: Apenas com pontos
        $formatos[] = substr($cpf_limpo, 0, 3) . '.' .
            substr($cpf_limpo, 3, 3) . '.' .
            substr($cpf_limpo, 6, 3);

        // Remover duplicados
        $formatos = array_unique($formatos);

        return $formatos;
    }

    // Gerar todos os formatos possíveis para CNPJ
    private static function gerarFormatosCnpj($cnpj_limpo)
    {
        $formatos = array();

        // Formato 1: 10.518.468/0001-26 (padrão)
        $formatos[] = substr($cnpj_limpo, 0, 2) . '.' .
            substr($cnpj_limpo, 2, 3) . '.' .
            substr($cnpj_limpo, 5, 3) . '/' .
            substr($cnpj_limpo, 8, 4) . '-' .
            substr($cnpj_limpo, 12, 2);

        // Formato 2: 10518468000126 (sem formatação)
        $formatos[] = $cnpj_limpo;

        // Formato 3: Com espaços extras
        $formatos[] = ' ' . $formatos[0];
        $formatos[] = $formatos[0] . ' ';
        $formatos[] = ' ' . $formatos[0] . ' ';

        // Formato 4: Sem traço
        $formatos[] = substr($cnpj_limpo, 0, 2) . '.' .
            substr($cnpj_limpo, 2, 3) . '.' .
            substr($cnpj_limpo, 5, 3) . '/' .
            substr($cnpj_limpo, 8, 4);

        // Remover duplicados
        $formatos = array_unique($formatos);

        return $formatos;
    }

    // Função robusta para atualizar limite - VERSÃO MODIFICADA PARA ACEITAR USUÁRIO DO SELECT
    public static function atualizarLimiteUnidade($unidade, $documento, $limite, $usuario)
    {
        $con = null;

        try {
            $documento_limpo = preg_replace('/[^0-9]/', '', $documento);

            if (strlen($documento_limpo) == 11) {
                $campo = 'ncpf';
                $formatos = self::gerarFormatosCpf($documento_limpo);
            } else if (strlen($documento_limpo) == 14) {
                $campo = 'ncgc';
                $formatos = self::gerarFormatosCnpj($documento_limpo);
            } else {
                throw new Exception("Documento inválido");
            }

            // Validar usuário
            if (empty(trim($usuario))) {
                throw new Exception("Usuário não informado");
            }

            if (!in_array($usuario, self::$usuarios_permitidos)) {
                throw new Exception("Usuário '{$usuario}' não autorizado para realizar alterações");
            }

            $con = self::conectar_unidade($unidade);
            $encontrado = false;
            $codic_encontrado = null;

            // Buscar CODIC
            foreach ($formatos as $formato) {
                $sql_busca = "SELECT FIRST 1 c.codic FROM arqcad c 
                             WHERE c.tipoc = 'C' AND TRIM(c.{$campo}) = '" . trim($formato) . "'";
                $consulta = @ibase_query($con, $sql_busca);

                if ($consulta && $objeto = ibase_fetch_object($consulta)) {
                    $linha = self::obj2arr($objeto, true);
                    $codic_encontrado = isset($linha[0]) ? (string)$linha[0] : null;
                    $encontrado = true;
                    ibase_free_result($consulta);
                    break;
                }
                if ($consulta) ibase_free_result($consulta);
            }

            if (!$encontrado || !$codic_encontrado) {
                throw new Exception("Cliente não encontrado na unidade {$unidade}");
            }

            // Validar dados antes do UPDATE
            if (!is_numeric($limite) || $limite < 0) {
                throw new Exception("Limite inválido: deve ser um número positivo");
            }

            // Realizar UPDATE com transação
            ibase_trans($con);

            $sql_update = "UPDATE arqcad c 
                          SET c.lcred = {$limite}, 
                              c.usu_alterou = '{$usuario}',
                              c.dt_alteracao = CURRENT_DATE
                          WHERE c.codic = {$codic_encontrado} 
                          AND c.tipoc = 'C'";

            $resultado_update = @ibase_query($con, $sql_update);

            if (!$resultado_update) {
                ibase_rollback($con);
                $erro = ibase_errmsg() ?: 'Erro desconhecido no UPDATE';
                throw new Exception("Erro ao atualizar limite: {$erro}");
            }

            ibase_commit($con);

            return array(
                'success' => true,
                'unidade' => $unidade,
                'codic' => $codic_encontrado,
                'limite' => $limite,
                'usuario' => $usuario,
                'timestamp' => date('Y-m-d H:i:s')
            );
        } catch (Exception $e) {
            if ($con) {
                @ibase_rollback($con);
            }
            throw $e;
        }
    }

    // Função para validar usuário selecionado
    public static function validarUsuario($usuario)
    {
        if (empty(trim($usuario))) {
            return array('valido' => false, 'erro' => 'Usuário não informado');
        }

        if (!in_array($usuario, self::$usuarios_permitidos)) {
            return array('valido' => false, 'erro' => "Usuário '{$usuario}' não autorizado");
        }

        return array('valido' => true, 'erro' => null);
    }

    // Fechar todas as conexões (para shutdown)
    public static function fecharTodasConexoes()
    {
        foreach (self::$connections_pool as $unidade => $conexao) {
            @ibase_close($conexao);
            error_log("Conexão fechada: {$unidade}");
        }
        self::$connections_pool = array();
    }
}

// Registrar função de shutdown para limpeza
register_shutdown_function(function () {
    TodasConexoes::fecharTodasConexoes();
});

// Classe para integração com API Neocredit - VERSÃO ATUALIZADA
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
        // Rate limiting - evitar muitas requisições muito rápidas
        $now = microtime(true);
        if (($now - self::$last_request_time) < 0.5) { // 500ms entre requisições
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        );

        // Headers
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

        // Executar requisição
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        // Log da requisição
        error_log("API Request: {$method} {$url} - HTTP: {$http_code}");

        // Tolerância a erro 500 - tentar novamente
        if ($http_code == 500) {
            throw new Exception("Erro interno do servidor (500) - tentar novamente");
        }

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

        // Verificar cache
        $cache_key = 'iniciar_' . $documento_limpo;
        if (
            isset(self::$cache_analises[$cache_key]) &&
            (time() - self::$cache_analises[$cache_key]['time']) < 300
        ) { // 5 minutos
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2722/integracao';

        $data = self::fazerRequisicao($url, 'POST', array('documento' => $documento_limpo), 60);

        if (!isset($data['id'])) {
            throw new Exception("ID da análise não retornado pela API");
        }

        // Armazenar em cache
        self::$cache_analises[$cache_key] = array(
            'data' => $data['id'],
            'time' => time()
        );

        return $data['id'];
    }

    // Consultar status da análise
    public static function consultarAnalise($id_analise)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        // Verificar cache
        $cache_key = 'consulta_' . $id_analise;
        if (
            isset(self::$cache_analises[$cache_key]) &&
            (time() - self::$cache_analises[$cache_key]['time']) < 30
        ) { // 30 segundos
            return self::$cache_analises[$cache_key]['data'];
        }

        $url = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/' . $id_analise . '/simplificada';

        $data = self::fazerRequisicao($url, 'GET', null, 30);

        // Armazenar em cache
        self::$cache_analises[$cache_key] = array(
            'data' => $data,
            'time' => time()
        );

        return $data;
    }

    // Verificar se análise está realmente pronta
    private static function analiseEstaPronta($dados)
    {
        if (!is_array($dados)) {
            return false;
        }

        // 1. Verificar se tem campos preenchidos com dados importantes
        $campos_importantes = array('limite_aprovado', 'score', 'risco', 'classificacao_risco', 'razao');
        $campos_preenchidos = 0;

        if (isset($dados['campos']) && is_array($dados['campos'])) {
            foreach ($campos_importantes as $campo) {
                if (isset($dados['campos'][$campo]) && !empty($dados['campos'][$campo])) {
                    $campos_preenchidos++;
                }
            }
        }

        // Se tem pelo menos 3 campos importantes preenchidos, considera pronto
        if ($campos_preenchidos >= 3) {
            error_log("✅ Análise considerada PRONTA - {$campos_preenchidos} campos importantes preenchidos");
            return true;
        }

        // 2. Verificar status dentro de campos
        if (isset($dados['campos']['status']) && !empty($dados['campos']['status'])) {
            $status_campos = strtoupper($dados['campos']['status']);
            $status_finais = array('DERIVAR', 'APROVAR', 'REPROVAR', 'NEGAR', 'LIBERAR', 'CONCLUIR');

            if (in_array($status_campos, $status_finais)) {
                error_log("✅ Análise considerada PRONTA - status em campos: {$dados['campos']['status']}");
                return true;
            }
        }

        // 3. Verificar histórico de fases - se alguma fase já foi concluída
        if (isset($dados['historico_fases']) && is_array($dados['historico_fases'])) {
            foreach ($dados['historico_fases'] as $fase) {
                if (isset($fase['data_saida']) && !empty($fase['data_saida'])) {
                    error_log("✅ Análise considerada PRONTA - fase concluída: " . (isset($fase['nome']) ? $fase['nome'] : 'N/A'));
                    return true;
                }
            }
        }

        return false;
    }

    // Aguardar resultado
    public static function aguardarResultado($id_analise, $max_tentativas = 12, $intervalo_base = 10)
    {
        if (empty($id_analise)) {
            throw new Exception("ID da análise inválido");
        }

        $tentativa = 0;
        $ultimo_status = '';

        error_log("🔍 Iniciando monitoramento da análise: {$id_analise}");

        while ($tentativa < $max_tentativas) {
            try {
                $tentativa++;
                error_log("📊 Tentativa {$tentativa}/{$max_tentativas} para análise {$id_analise}");

                $dados = self::consultarAnalise($id_analise);

                // VERIFICAÇÃO PRINCIPAL CORRIGIDA
                $status_principal = isset($dados['status']) ? strtoupper(trim($dados['status'])) : '';

                // Log do status
                $status_campos = isset($dados['campos']['status']) ? $dados['campos']['status'] : 'N/A';
                error_log("📋 Status principal: {$status_principal} | Status em campos: {$status_campos}");

                // ✅ CRITÉRIO PRINCIPAL: Se a análise está realmente pronta (campos preenchidos)
                if (self::analiseEstaPronta($dados)) {
                    error_log("✅ Análise {$id_analise} PRONTA - retornando dados completos");
                    return $dados;
                }

                // ✅ CRITÉRIO SECUNDÁRIO: Status final no nível principal
                $status_finais_principal = array('APROVADO', 'REPROVADO', 'NEGADO', 'LIBERADO', 'CONCLUIDO', 'FINALIZADO');
                if (in_array($status_principal, $status_finais_principal)) {
                    error_log("✅ Análise {$id_analise} CONCLUÍDA - status principal: {$status_principal}");
                    return $dados;
                }

                // Aguardar próximo intervalo
                if ($tentativa < $max_tentativas) {
                    $wait_time = $intervalo_base;
                    // Aumentar gradualmente o wait time
                    if ($tentativa > 5) {
                        $wait_time = min($intervalo_base * 2, 30);
                    }
                    if ($tentativa > 8) {
                        $wait_time = min($intervalo_base * 3, 45);
                    }

                    error_log("⏰ Aguardando {$wait_time}s para próxima tentativa");
                    sleep($wait_time);
                }
            } catch (Exception $e) {
                error_log("❌ Erro na tentativa {$tentativa}: " . $e->getMessage());

                if ($tentativa >= $max_tentativas) {
                    // Na última tentativa com erro, tenta uma última consulta
                    try {
                        $dados_finais = self::consultarAnalise($id_analise);
                        error_log("⚠️ Retornando dados finais mesmo com erro anterior");
                        return $dados_finais;
                    } catch (Exception $e_final) {
                        throw new Exception("Erro após {$max_tentativas} tentativas: " . $e->getMessage());
                    }
                }

                // Espera fixa em caso de erro
                sleep($intervalo_base);
            }
        }

        // Última tentativa - retorna o que tiver
        error_log("⏰ Tempo limite atingido - retornando último status disponível");
        $dados_finais = self::consultarAnalise($id_analise);
        return $dados_finais;
    }

    // Função para extrair dados formatados da análise
    public static function extrairDadosFormatados($dados_analise)
    {
        if (!isset($dados_analise['campos'])) {
            return array();
        }

        $campos = $dados_analise['campos'];

        return array(
            'razao_social' => isset($campos['razao']) ? $campos['razao'] : '',
            'documento' => isset($campos['documento']) ? $campos['documento'] : '',
            'risco' => isset($campos['risco']) ? $campos['risco'] : '',
            'score' => isset($campos['score']) ? $campos['score'] : '',
            'classificacao_risco' => isset($campos['classificacao_risco']) ? $campos['classificacao_risco'] : '',
            'limite_aprovado' => isset($campos['limite_aprovado']) ? floatval($campos['limite_aprovado']) : 0,
            'data_validade' => isset($campos['data_validade_limite_credito']) ? $campos['data_validade_limite_credito'] : '',
            'status' => isset($campos['status']) ? $campos['status'] : (isset($dados_analise['status']) ? $dados_analise['status'] : 'N/A')
        );
    }
}

// Processador principal de requisições - MODIFICADO PARA ACEITAR USUÁRIO DO SELECT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Buffer de saída para capturar erros
    ob_start();

    try {
        // Verificar se é uma atualização de limite
        if (isset($_POST['action']) && $_POST['action'] === 'atualizar_limites') {
            if (!isset($_POST['documento']) || empty($_POST['documento'])) {
                throw new Exception("Documento não informado");
            }

            if (!isset($_POST['limites']) || empty($_POST['limites'])) {
                throw new Exception("Limites não informados");
            }

            if (!isset($_POST['usuario']) || empty($_POST['usuario'])) {
                throw new Exception("Usuário não selecionado");
            }

            $documento = $_POST['documento'];
            $limites_por_unidade = json_decode($_POST['limites'], true);
            $usuario = $_POST['usuario'];

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Erro ao decodificar limites: " . json_last_error_msg());
            }

            // Validar usuário
            $validacao_usuario = TodasConexoes::validarUsuario($usuario);
            if (!$validacao_usuario['valido']) {
                throw new Exception($validacao_usuario['erro']);
            }

            $resultados = array();
            $atualizacoes_realizadas = 0;

            foreach ($limites_por_unidade as $unidade => $limite) {
                if ($limite > 0) {
                    try {
                        $resultado = TodasConexoes::atualizarLimiteUnidade($unidade, $documento, $limite, $usuario);
                        $resultados[$unidade] = $resultado;
                        $atualizacoes_realizadas++;

                        // Pequena pausa entre atualizações
                        if ($atualizacoes_realizadas < count($limites_por_unidade)) {
                            usleep(100000); // 0.1s
                        }
                    } catch (Exception $e) {
                        $resultados[$unidade] = array(
                            'success' => false,
                            'error' => $e->getMessage()
                        );
                    }
                }
            }

            echo json_encode(array(
                'success' => true,
                'message' => "Limites atualizados em {$atualizacoes_realizadas} unidade(s) pelo usuário {$usuario}!",
                'resultados' => $resultados,
                'stats' => TodasConexoes::getStats()
            ));

            exit;
        }

        // Consulta normal
        $documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';

        if (empty($documento)) {
            throw new Exception('Documento não informado');
        }

        // Consulta completa com tratamento de erro granular
        $start_time = microtime(true);
        $etapas = array();

        // Inicializar variáveis para evitar undefined
        $id_analise = null;
        $dados_api = null;
        $resultados_codic = null;

        try {
            $etapas[] = 'Iniciando análise Neocredit';
            $id_analise = ApiNeocredit::iniciarAnalise($documento);
            $etapas[] = 'Análise Neocredit iniciada: ' . $id_analise;

            $etapas[] = 'Aguardando resultado da análise';
            $dados_api = ApiNeocredit::aguardarResultado($id_analise, 10, 8); // Menos tentativas, intervalo menor
            $etapas[] = 'Análise Neocredit concluída';

            // Extrair dados formatados
            $dados_formatados = ApiNeocredit::extrairDadosFormatados($dados_api);
            $etapas[] = 'Dados extraídos e formatados';

            $etapas[] = 'Buscando CODIC nas unidades';
            $resultados_codic = TodasConexoes::buscar_codic_por_documento($documento);
            $etapas[] = 'Busca de CODIC concluída';
        } catch (Exception $e) {
            // Se uma etapa falhar, tentar continuar com as outras
            error_log("Erro em etapa específica: " . $e->getMessage());

            if (!$id_analise) {
                throw new Exception("Falha na análise Neocredit: " . $e->getMessage());
            }

            if (!$dados_api) {
                // Tentar pelo menos uma consulta final
                try {
                    $dados_api = ApiNeocredit::consultarAnalise($id_analise);
                    $etapas[] = 'Consulta de recuperação realizada';
                } catch (Exception $e2) {
                    $dados_api = array('error' => $e->getMessage());
                }
            }

            if (!$resultados_codic) {
                $resultados_codic = array();
            }
        }

        $total_time = round(microtime(true) - $start_time, 2);

        // Preparar resposta com dados formatados
        $resposta = array(
            'documento' => $documento,
            'documento_limpo' => preg_replace('/[^0-9]/', '', $documento),
            'id_analise' => $id_analise,
            'dados_api' => $dados_api,
            'dados_formatados' => ApiNeocredit::extrairDadosFormatados($dados_api),
            'resultados_unidades' => $resultados_codic,
            'nomes_unidades' => TodasConexoes::getNomesUnidades(),
            'usuarios_permitidos' => TodasConexoes::getUsuariosPermitidos(),
            'tempo_total_segundos' => $total_time,
            'etapas' => $etapas,
            'stats' => TodasConexoes::getStats(),
            'timestamp' => date('Y-m-d H:i:s')
        );

        echo json_encode($resposta);
    } catch (Exception $e) {
        // Limpar buffer de saída
        ob_end_clean();

        http_response_code(500);
        error_log("ERRO GERAL: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());

        echo json_encode(array(
            'error' => $e->getMessage(),
            'stats' => TodasConexoes::getStats()
        ));
    }

    // Enviar buffer
    ob_end_flush();
    exit;
}

// Endpoint para status do sistema
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => 'online',
        'timestamp' => date('Y-m-d H:i:s'),
        'stats' => TodasConexoes::getStats(),
        'usuarios_permitidos' => TodasConexoes::getUsuariosPermitidos(),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
    ));
    exit;
}

// Endpoint para consulta rápida de status
if (isset($_GET['action']) && $_GET['action'] === 'consulta_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $dados = ApiNeocredit::consultarAnalise($_GET['id']);
        echo json_encode(array(
            'status' => isset($dados['status']) ? $dados['status'] : 'N/A',
            'status_campos' => isset($dados['campos']['status']) ? $dados['campos']['status'] : 'N/A',
            'pronto' => ApiNeocredit::analiseEstaPronta($dados),
            'timestamp' => date('Y-m-d H:i:s')
        ));
    } catch (Exception $e) {
        echo json_encode(array(
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
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
    <title>NOROAÇO - Gerenciador de Crédito</title>
    <link rel="stylesheet" type="text/css" href="limite_cred_style.css">
    <style>
        /* Estilos adicionais para o select de usuário */
        .usuario-select-container {
            margin: 20px 0;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        .usuario-select-container label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }

        .usuario-select {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            border: 2px solid #3498db;
            border-radius: 5px;
            background-color: white;
            transition: all 0.3s;
        }

        .usuario-select:focus {
            outline: none;
            border-color: #2980b9;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
        }

        .usuario-select option {
            padding: 10px;
        }

        /* Ajustes no modal para acomodar o select */
        .modal-body {
            max-height: 80vh;
            overflow-y: auto;
        }

        /* Estilo para o resumo da atualização */
        .resumo-table {
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }

        .resumo-header {
            display: grid;
            grid-template-columns: 2fr 1fr;
            background-color: #f8f9fa;
            font-weight: bold;
            padding: 12px 15px;
            border-bottom: 2px solid #3498db;
        }

        .resumo-header span {
            color: #2c3e50;
        }

        .resumo-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }

        .resumo-row:hover {
            background-color: #f9f9f9;
        }

        .resumo-row:last-child {
            border-bottom: none;
        }

        .unidade-nome {
            font-weight: 500;
        }

        .limite-valor {
            text-align: right;
            font-weight: bold;
            color: #27ae60;
        }

        .modal-actions {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .btn-confirmar {
            background-color: #27ae60;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            flex: 1;
            transition: background-color 0.3s;
        }

        .btn-confirmar:hover {
            background-color: #219653;
        }

        .btn-cancelar {
            background-color: #e74c3c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            flex: 1;
            transition: background-color 0.3s;
        }

        .btn-cancelar:hover {
            background-color: #c0392b;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Botão de Download DENTRO do container mas FORA do content -->
        <div class="download-btn-container">
            <button class="download-btn" id="download-btn" title="Faça uma consulta primeiro para gerar relatórios">
                📥
            </button>
        </div>

        <header>
            <img src="imgs/logo.png" alt="Logo" class="logo">
            <h1>Consulta Limite de Crédito</h1>
        </header>

        <div class="content">
            <div class="form-section">
                <label for="documento">CPF/CNPJ:</label>
                <input type="text" id="documento" placeholder="Digite o CPF ou CNPJ (com ou sem formatação)">

                <div class="button-group">
                    <button id="consultar-btn">Consultar</button>
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
                <div id="unidades-grid" class="unidades-section"></div>

                <!-- Botão de Atualizar Limites -->
                <div class="update-section" id="update-section" style="display: none;">
                    <button id="atualizar-btn" class="atualizar-btn">Atualizar Limites nas Unidades</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmação ATUALIZADO COM SELECT DE USUÁRIO -->
    <div id="modal-confirmacao" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirmar Atualização de Limites</h3>
                <span class="close-modal">&times;</span>
            </div>
            <div class="modal-body">
                <p>Tem certeza que deseja atualizar os limites de crédito conforme distribuído abaixo?</p>

                <!-- Select de usuário -->
                <div class="usuario-select-container">
                    <label for="usuario-select">Selecione o usuário que está realizando a alteração:</label>
                    <select id="usuario-select" class="usuario-select">
                        <option value="">Selecione um usuário</option>
                        <option value="ANDREA">ANDREA</option>
                        <option value="EFANECO">EFANECO</option>
                        <option value="MARCUS">MARCUS</option>
                        <option value="ISABELLY">ISABELLY</option>
                        <option value="GABRIELAF">GABRIELAF</option>
                        <option value="EMANUELE">EMANUELE</option>
                    </select>
                </div>

                <div class="resumo-limites">
                    <div id="resumo-limites-container"></div>
                </div>


                <div class="modal-actions">
                    <button id="confirmar-atualizacao" class="btn-confirmar">Confirmar Atualização</button>
                    <button id="cancelar-atualizacao" class="btn-cancelar">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Download -->
    <div id="modal-download" class="modal-download">
        <div class="modal-download-content">
            <div class="modal-download-header">
                <h3>Gerar Relatório</h3>
                <button class="close-download">&times;</button>
            </div>

            <div class="relatorio-options">
                <div class="relatorio-option" data-relatorio="limite-credito">
                    <div class="relatorio-title">1 - Limite de Crédito</div>
                    <div class="relatorio-desc">Relatório completo da consulta atual em PDF</div>
                </div>

                <div class="relatorio-option disabled" data-relatorio="detalhado">
                    <div class="relatorio-title">2 - Relatório Detalhado</div>
                    <div class="relatorio-desc">Relatório completo com histórico (Em breve)</div>
                </div>
            </div>

            <button class="gerar-relatorio-btn" id="gerar-relatorio-btn" disabled>
                Gerar Relatório PDF
            </button>
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
            var unidadesGrid = document.getElementById('unidades-grid');
            var atualizarBtn = document.getElementById('atualizar-btn');
            var modalConfirmacao = document.getElementById('modal-confirmacao');
            var closeModal = document.querySelector('.close-modal');
            var cancelarAtualizacao = document.getElementById('cancelar-atualizacao');
            var confirmarAtualizacao = document.getElementById('confirmar-atualizacao');
            var resumoLimitesContainer = document.getElementById('resumo-limites-container');
            var updateSection = document.getElementById('update-section');
            var usuarioSelect = document.getElementById('usuario-select');

            // Variáveis para download
            var downloadBtn = document.getElementById('download-btn');
            var modalDownload = document.getElementById('modal-download');
            var closeDownloadModal = document.querySelector('.close-download');
            var gerarRelatorioBtn = document.getElementById('gerar-relatorio-btn');
            var relatorioOptions = document.querySelectorAll('.relatorio-option');

            var progressInterval;
            var startTime;
            var limiteTotal = 0;
            var limiteDistribuido = 0;
            var dadosAtuais = null;
            var relatorioSelecionado = null;

            // ========== FUNÇÕES DE INICIALIZAÇÃO ==========
            inicializarEventListeners();
            atualizarEstadoDownloadBtn();

            function inicializarEventListeners() {
                // Event listeners existentes
                if (consultarBtn) consultarBtn.addEventListener('click', executarConsulta);
                if (documentoInput) documentoInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') executarConsulta();
                });
                if (atualizarBtn) atualizarBtn.addEventListener('click', abrirModalConfirmacao);
                if (closeModal) closeModal.addEventListener('click', fecharModalConfirmacao);
                if (cancelarAtualizacao) cancelarAtualizacao.addEventListener('click', fecharModalConfirmacao);
                if (confirmarAtualizacao) confirmarAtualizacao.addEventListener('click', realizarAtualizacaoLimites);

                // Event listeners para download
                if (downloadBtn) downloadBtn.addEventListener('click', abrirModalDownload);
                if (closeDownloadModal) closeDownloadModal.addEventListener('click', fecharModalDownload);
                if (gerarRelatorioBtn) gerarRelatorioBtn.addEventListener('click', gerarRelatorioSelecionado);

                // Event listeners para opções de relatório
                relatorioOptions.forEach(option => {
                    option.addEventListener('click', function() {
                        selecionarOpcaoRelatorio(this);
                    });
                });

                // Fechar modal ao clicar fora
                window.addEventListener('click', function(event) {
                    if (event.target === modalDownload) {
                        fecharModalDownload();
                    }
                    if (event.target === modalConfirmacao) {
                        fecharModalConfirmacao();
                    }
                });

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

            // ========== FUNÇÕES DE DOWNLOAD ==========
            function abrirModalDownload() {
                if (!downloadBtn.classList.contains('active')) {
                    showError('Faça uma consulta primeiro para gerar relatórios.');
                    return;
                }

                modalDownload.style.display = 'block';
                relatorioSelecionado = null;
                gerarRelatorioBtn.disabled = true;
                gerarRelatorioBtn.textContent = 'Gerar Relatório PDF';

                // Remover seleção anterior
                relatorioOptions.forEach(option => {
                    option.classList.remove('selected');
                });
            }

            function fecharModalDownload() {
                modalDownload.style.display = 'none';
            }

            function selecionarOpcaoRelatorio(option) {
                if (option.classList.contains('disabled')) {
                    return;
                }

                // Remover seleção anterior
                relatorioOptions.forEach(opt => {
                    opt.classList.remove('selected');
                });

                // Selecionar atual
                option.classList.add('selected');
                relatorioSelecionado = option.getAttribute('data-relatorio');
                gerarRelatorioBtn.disabled = false;

                // Atualizar texto do botão
                var titulo = option.querySelector('.relatorio-title').textContent;
                gerarRelatorioBtn.textContent = 'Gerar ' + titulo.split(' - ')[1] + ' PDF';
            }

            function gerarRelatorioSelecionado() {
                if (!relatorioSelecionado) return;

                switch (relatorioSelecionado) {
                    case 'limite-credito':
                        gerarRelatorioLimiteCredito();
                        break;
                    case 'detalhado':
                        showError('Relatório detalhado em desenvolvimento');
                        break;
                }

                fecharModalDownload();
            }

            function gerarRelatorioLimiteCredito() {
                if (!dadosAtuais) {
                    showError('Nenhuma consulta realizada. Faça uma consulta primeiro.');
                    return;
                }

                if (!verificarDadosParaRelatorio()) {
                    showError('Dados insuficientes para gerar relatório. Realize uma consulta completa primeiro.');
                    return;
                }

                // Mostrar loading
                loadingDiv.style.display = 'block';
                loadingText.textContent = 'Gerando relatório PDF...';

                try {
                    // Preparar dados para o PDF - mesma estrutura que sua página PDF espera
                    var dadosParaPDF = {
                        documento: dadosAtuais.documento,
                        documento_limpo: dadosAtuais.documento_limpo,
                        id_analise: dadosAtuais.id_analise,
                        dados_api: dadosAtuais.dados_api,
                        dados_formatados: dadosAtuais.dados_formatados,
                        resultados_unidades: dadosAtuais.resultados_unidades,
                        nomes_unidades: dadosAtuais.nomes_unidades,
                        timestamp: dadosAtuais.timestamp,
                        tempo_total_segundos: dadosAtuais.tempo_total_segundos,
                        stats: dadosAtuais.stats
                    };

                    // Codificar dados para URL
                    var dadosCodificados = encodeURIComponent(JSON.stringify(dadosParaPDF));

                    // Redirecionar para sua página PDF existente
                    var urlPDF = 'limite_cred_pdf.php?dados=' + dadosCodificados;

                    // Abrir em nova aba para download do PDF
                    window.open(urlPDF, '_blank');

                } catch (error) {
                    console.error('Erro ao gerar relatório:', error);
                    showError('Erro ao preparar dados para o relatório: ' + error.message);
                } finally {
                    loadingDiv.style.display = 'none';
                }
            }

            function verificarDadosParaRelatorio() {
                return dadosAtuais !== null &&
                    dadosAtuais.dados_api &&
                    Object.keys(dadosAtuais.dados_api).length > 0 &&
                    dadosAtuais.resultados_unidades &&
                    Object.keys(dadosAtuais.resultados_unidades).length > 0;
            }

            function atualizarEstadoDownloadBtn() {
                var temDados = verificarDadosParaRelatorio();

                if (downloadBtn) {
                    if (temDados) {
                        downloadBtn.classList.add('active');
                        downloadBtn.title = 'Gerar Relatório da Consulta Atual';
                    } else {
                        downloadBtn.classList.remove('active');
                        downloadBtn.title = 'Faça uma consulta primeiro para gerar relatórios';
                    }
                }
            }

            // ========== FUNÇÕES EXISTENTES ==========
            function executarConsulta() {
                var documento = documentoInput.value.replace(/\D/g, '');
                if (!documento) return showError('Digite um CPF ou CNPJ válido.');
                if (documento.length !== 11 && documento.length !== 14)
                    return showError('CPF deve ter 11 dígitos e CNPJ 14 dígitos.');

                documentoInput.disabled = true;
                consultarBtn.disabled = true;
                loadingDiv.style.display = 'block';
                resultSection.style.display = 'none';
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                updateSection.style.display = 'none';
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

                        exibirResultadosCompletos(data);
                    })
                    .catch(function(error) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;
                        showError('Erro na requisição: ' + error.message);
                    });
            }

            function iniciarProgresso() {
                startTime = new Date().getTime();
                var maxTime = 3 * 60 * 1000;

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

                    if (elapsed > 2.5 * 60 * 1000) {
                        pararProgresso();
                        loadingDiv.style.display = 'none';
                        showError('Tempo limite excedido. A consulta está demorando mais que o esperado. Tente novamente.');
                        documentoInput.disabled = false;
                        consultarBtn.disabled = false;
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

            function exibirResultadosCompletos(data) {
                dadosAtuais = data;

                resultSection.style.display = 'block';
                apiGrid.innerHTML = '';
                unidadesGrid.innerHTML = '';
                updateSection.style.display = 'none';

                // Usar dados formatados se disponíveis, senão usar dados brutos
                var dadosExibir = data.dados_formatados || (data.dados_api && data.dados_api.campos) || {};

                // Dados da API Neocredit
                var apiCard = document.createElement('div');
                apiCard.className = 'api-section';

                var html = '<h3>Dados da Análise - Neocredit</h3>';
                html += '<div class="info-grid">';

                html += '<div class="info-item"><span class="info-label">ID Análise:</span><span class="info-value">' + (data.id_analise || 'N/A') + '</span></div>';

                // Razão Social
                html += '<div class="info-item"><span class="info-label">Razão Social:</span><span class="info-value">' + (dadosExibir.razao_social || dadosExibir.razao || 'N/A') + '</span></div>';

                // Documento
                html += '<div class="info-item"><span class="info-label">Documento:</span><span class="info-value">' + formatarDocumento(dadosExibir.documento || '') + '</span></div>';

                // Risco
                html += '<div class="info-item"><span class="info-label">Risco:</span><span class="info-value">' + (dadosExibir.risco || 'N/A') + '</span></div>';

                // Classificação de Risco
                html += '<div class="info-item"><span class="info-label">Classificação de Risco:</span><span class="info-value">' + (dadosExibir.classificacao_risco || 'N/A') + '</span></div>';

                // Capturar o limite total para distribuição
                limiteTotal = parseFloat(dadosExibir.limite_aprovado || 0);
                html += '<div class="info-item"><span class="info-label">Limite Aprovado:</span><span class="info-value">R$ ' + formatarMoeda(limiteTotal) + '</span></div>';

                // Data de Validade
                html += '<div class="info-item"><span class="info-label">Data Validade Limite:</span><span class="info-value">' + (dadosExibir.data_validade || 'N/A') + '</span></div>';

                // Status com formatação especial
                var status = dadosExibir.status || '';
                var statusInfo = definirStatus(status);
                html += '<div class="info-item"><span class="info-label">Status:</span><span class="info-value"><span class="status-container ' + statusInfo.classeStatus + '">' + statusInfo.textoStatus + '</span></span></div>';

                // Score se disponível
                if (dadosExibir.score) {
                    var scoreNumero = extrairScoreNumerico(dadosExibir.score);
                    if (scoreNumero !== null) {
                        html += '</div>'; // Fecha info-grid
                        html += '<div class="score-container">' + criarVisualizacaoScore(scoreNumero) + '</div>';
                    } else {
                        html += '</div>'; // Fecha info-grid se não tiver score
                    }
                } else {
                    html += '</div>'; // Fecha info-grid
                }

                apiCard.innerHTML = html;
                apiGrid.appendChild(apiCard);

                // Container para resultados por unidade
                var unidadesContainer = document.createElement('div');
                unidadesContainer.className = 'unidades-container';

                var unidadesEncontradas = Object.keys(data.resultados_unidades).filter(function(unidade) {
                    var dados = data.resultados_unidades[unidade];
                    return dados.encontrado && !dados.erro;
                });

                var headerHtml = '<div class="unidades-header">';
                headerHtml += '<h3 class="unidades-title">Unidades com Cadastro Encontrado</h3>';
                headerHtml += '<span class="unidades-count">' + unidadesEncontradas.length + ' unidade(s) encontrada(s)</span>';
                headerHtml += '</div>';

                var gridHtml = '<div class="unidade-grid">';

                if (unidadesEncontradas.length > 0) {
                    // Adicionar cards das unidades encontradas
                    unidadesEncontradas.forEach(function(unidade) {
                        var dados = data.resultados_unidades[unidade];
                        var nomeAmigavel = data.nomes_unidades && data.nomes_unidades[unidade] ?
                            data.nomes_unidades[unidade] : unidade;

                        gridHtml += '<div class="unidade-card encontrada">';
                        gridHtml += '<div class="unidade-header">';
                        gridHtml += '<h4 class="unidade-nome">' + nomeAmigavel + '</h4>';
                        gridHtml += '</div>';
                        gridHtml += '<div class="unidade-details">';

                        gridHtml += '<div class="detail-item">';
                        gridHtml += '<span class="detail-label">CODIC:</span>';
                        gridHtml += '<span class="codic-value">' + dados.codic + '</span>';
                        gridHtml += '</div>';

                        // Campo para distribuição do limite
                        gridHtml += '<div class="limite-input-container">';
                        gridHtml += '<label class="limite-label">Limite da Unidade:</label>';
                        gridHtml += '<input type="text" class="limite-input" data-unidade="' + unidade + '" placeholder="R$ 0,00" onfocus="this.select()">';
                        gridHtml += '</div>';

                        gridHtml += '</div>';
                        gridHtml += '</div>';
                    });

                    // Adicionar cards vazios para completar até 5
                    var cardsVazios = 5 - unidadesEncontradas.length;
                    for (var i = 0; i < cardsVazios; i++) {
                        gridHtml += '<div class="unidade-card vazio">';
                        gridHtml += '<span>—</span>';
                        gridHtml += '</div>';
                    }
                } else {
                    // Quando não há unidades, mostrar mensagem ocupando todas as 5 colunas
                    gridHtml += '<div class="nenhuma-unidade">';
                    gridHtml += 'Nenhum cadastro encontrado nas unidades consultadas.';
                    gridHtml += '</div>';
                }
                gridHtml += '</div>';

                // Container para informações do limite total e distribuição
                var limiteInfoHtml = '<div class="limite-total-container">';
                limiteInfoHtml += '<div class="limite-total-info">';
                limiteInfoHtml += '<span class="limite-total-label">Limite Total Disponível:</span>';
                limiteInfoHtml += '<span class="limite-total-value">R$ ' + formatarMoeda(limiteTotal) + '</span>';
                limiteInfoHtml += '</div>';
                limiteInfoHtml += '<div class="limite-distribuido-info">';
                limiteInfoHtml += '<span>Distribuído: <span id="limite-distribuido">R$ 0,00</span></span>';
                limiteInfoHtml += '<span>Restante: <span id="limite-restante" class="limite-restante">R$ ' + formatarMoeda(limiteTotal) + '</span></span>';
                limiteInfoHtml += '</div>';
                limiteInfoHtml += '</div>';

                unidadesContainer.innerHTML = headerHtml + gridHtml + limiteInfoHtml;
                unidadesGrid.appendChild(unidadesContainer);

                // Inicializar os campos de limite com máscara de moeda
                inicializarCamposLimite();

                // Mostrar botão de atualização apenas se há unidades encontradas E limite > 0
                if (unidadesEncontradas.length > 0 && limiteTotal > 0) {
                    updateSection.style.display = 'block';
                }

                // ATUALIZAR BOTÃO DE DOWNLOAD
                atualizarEstadoDownloadBtn();
            }

            function inicializarCamposLimite() {
                var inputsLimite = document.querySelectorAll('.limite-input');

                inputsLimite.forEach(function(input) {
                    // Aplicar máscara de moeda
                    input.addEventListener('input', function(e) {
                        aplicarMascaraMoeda(e.target);
                        calcularLimiteDistribuido();
                    });

                    // Formatar ao ganhar foco
                    input.addEventListener('focus', function(e) {
                        if (!e.target.value) {
                            e.target.value = 'R$ 0,00';
                        }
                        e.target.select();
                    });

                    // Formatar ao perder foco
                    input.addEventListener('blur', function(e) {
                        if (e.target.value === 'R$ 0,00') {
                            e.target.value = '';
                        }
                    });
                });
            }

            function aplicarMascaraMoeda(input) {
                var value = input.value.replace(/\D/g, '');
                value = (value / 100).toFixed(2) + '';
                value = value.replace(".", ",");
                value = value.replace(/(\d)(\d{3})(\d{3}),/g, "$1.$2.$3,");
                value = value.replace(/(\d)(\d{3}),/g, "$1.$2,");
                input.value = 'R$ ' + value;
            }

            function calcularLimiteDistribuido() {
                var inputsLimite = document.querySelectorAll('.limite-input');
                limiteDistribuido = 0;

                inputsLimite.forEach(function(input) {
                    if (input.value) {
                        var valor = parseFloat(input.value.replace(/[^\d,]/g, '').replace(',', '.'));
                        if (!isNaN(valor)) {
                            limiteDistribuido += valor;
                        }
                    }
                });

                var limiteRestante = limiteTotal - limiteDistribuido;

                // Atualizar display
                document.getElementById('limite-distribuido').textContent = 'R$ ' + formatarMoeda(limiteDistribuido);
                var elementoRestante = document.getElementById('limite-restante');
                elementoRestante.textContent = 'R$ ' + formatarMoeda(Math.max(0, limiteRestante));

                // Destacar se excedeu o limite
                if (limiteRestante < 0) {
                    elementoRestante.className = 'limite-excedido';
                } else {
                    elementoRestante.className = 'limite-restante';
                }
            }

            function abrirModalConfirmacao() {
                if (!dadosAtuais) return;

                // Coletar limites digitados
                var limitesPorUnidade = {};
                var inputsLimite = document.querySelectorAll('.limite-input');
                var haLimitesParaAtualizar = false;

                inputsLimite.forEach(function(input) {
                    var unidade = input.getAttribute('data-unidade');
                    var valor = input.value ? parseFloat(input.value.replace(/[^\d,]/g, '').replace(',', '.')) : 0;

                    if (valor > 0) {
                        haLimitesParaAtualizar = true;
                    }

                    limitesPorUnidade[unidade] = valor;
                });

                if (!haLimitesParaAtualizar) {
                    showError('Digite pelo menos um limite maior que zero para atualizar.');
                    return;
                }

                // Verificar se não excedeu o limite total
                if (limiteDistribuido > limiteTotal) {
                    showError('Limite distribuído (R$ ' + formatarMoeda(limiteDistribuido) + ') excede o limite total disponível (R$ ' + formatarMoeda(limiteTotal) + ').');
                    return;
                }

                // Gerar resumo para confirmação
                var resumoHtml = '<div class="resumo-table">';
                resumoHtml += '<div class="resumo-header">';
                resumoHtml += '<span>Unidade</span>';
                resumoHtml += '<span>Limite</span>';
                resumoHtml += '</div>';

                Object.keys(limitesPorUnidade).forEach(function(unidade) {
                    var limite = limitesPorUnidade[unidade];
                    if (limite > 0) {
                        var nomeAmigavel = dadosAtuais.nomes_unidades && dadosAtuais.nomes_unidades[unidade] ?
                            dadosAtuais.nomes_unidades[unidade] : unidade;

                        resumoHtml += '<div class="resumo-row">';
                        resumoHtml += '<span class="unidade-nome">' + nomeAmigavel + '</span>';
                        resumoHtml += '<span class="limite-valor">R$ ' + formatarMoeda(limite) + '</span>';
                        resumoHtml += '</div>';
                    }
                });

                resumoHtml += '</div>';
                resumoLimitesContainer.innerHTML = resumoHtml;

                // Resetar o select de usuário
                if (usuarioSelect) {
                    usuarioSelect.value = '';
                }

                modalConfirmacao.style.display = 'block';
            }

            function fecharModalConfirmacao() {
                modalConfirmacao.style.display = 'none';
            }

            function realizarAtualizacaoLimites() {
                if (!dadosAtuais) return;

                // Verificar se usuário foi selecionado
                var usuario = usuarioSelect ? usuarioSelect.value : '';
                
                if (!usuario) {
                    showError('Por favor, selecione um usuário antes de confirmar a atualização.');
                    return;
                }

                var limitesPorUnidade = {};
                var inputsLimite = document.querySelectorAll('.limite-input');

                inputsLimite.forEach(function(input) {
                    var unidade = input.getAttribute('data-unidade');
                    var valor = input.value ? parseFloat(input.value.replace(/[^\d,]/g, '').replace(',', '.')) : 0;

                    limitesPorUnidade[unidade] = valor;
                });

                // Mostrar loading
                loadingDiv.style.display = 'block';
                loadingText.textContent = 'Atualizando limites nas unidades...';
                modalConfirmacao.style.display = 'none';

                var formData = new FormData();
                formData.append('action', 'atualizar_limites');
                formData.append('documento', dadosAtuais.documento);
                formData.append('limites', JSON.stringify(limitesPorUnidade));
                formData.append('usuario', usuario); // Adicionar o usuário selecionado

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        loadingDiv.style.display = 'none';

                        if (data.error) {
                            showError('Erro na atualização: ' + data.error);
                            return;
                        }

                        showSuccess(data.message || 'Limites atualizados com sucesso!');

                        // Exibir resultados detalhados da atualização
                        if (data.resultados) {
                            var resultadosHtml = '<div class="resultados-atualizacao">';
                            resultadosHtml += '<h4>Resultados da Atualização:</h4>';

                            Object.keys(data.resultados).forEach(function(unidade) {
                                var resultado = data.resultados[unidade];
                                if (resultado.success) {
                                    var nomeAmigavel = dadosAtuais.nomes_unidades && dadosAtuais.nomes_unidades[unidade] ?
                                        dadosAtuais.nomes_unidades[unidade] : unidade;

                                    resultadosHtml += '<div class="resultado-sucesso">';
                                    resultadosHtml += nomeAmigavel + ' - Limite: R$ ' + formatarMoeda(resultado.limite) + 
                                                     ' (CODIC: ' + resultado.codic + ') - Atualizado por: ' + usuario;
                                    resultadosHtml += '</div>';
                                }
                            });

                            resultadosHtml += '</div>';
                            successDiv.innerHTML += resultadosHtml;
                        }
                    })
                    .catch(function(error) {
                        loadingDiv.style.display = 'none';
                        showError('Erro na requisição: ' + error.message);
                    });
            }

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

                if (s === 'derivar') {
                    classeStatus = 'status-derivar';
                    textoStatus = 'DERIVAR';
                } else if (s === 'aprovar' || s === 'liberado') {
                    classeStatus = 'status-aprovar';
                    textoStatus = 'APROVADO';
                } else if (s === 'reprovar' || s === 'negado') {
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
                errorDiv.innerHTML = msg;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
            }

            function showSuccess(msg) {
                successDiv.innerHTML = msg;
                successDiv.style.display = 'block';
                errorDiv.style.display = 'none';
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
                if (!v) return '0,00';
                return parseFloat(v).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2
                });
            }
        });
    </script>
</body>

</html>