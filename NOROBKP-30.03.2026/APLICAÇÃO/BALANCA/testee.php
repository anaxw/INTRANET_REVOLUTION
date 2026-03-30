<?php
// ============================================
// SISTEMA DE PESAGEM NOROÇO - VERSÃO 24/7
// ============================================

// CONFIGURAÇÕES CRÍTICAS
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

// CONFIGURAR TIMEZONE CORRETO (Brasil)
date_default_timezone_set('America/Sao_Paulo');

// Desabilitar cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Configurar locale para português Brasil
setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');

// Habilitar relatório de erros (para desenvolvimento)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Sessão para controle de erros
session_start();

// Conexão com banco de dados
try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão com banco: " . $e->getMessage());
}

// Configurações da balança
$balanca_ip = '192.168.1.220';
$balanca_porta = 23;
$timeout = 2; // timeout em segundos

// ============================================
// FUNÇÃO MELHORADA DE LEITURA DA BALANÇA
// ============================================
function lerPesoBalança() {
    global $balanca_ip, $balanca_porta, $timeout;
    
    // Limite máximo de execução
    set_time_limit($timeout + 5);
    
    // WATCHDOG: Se houver muitos erros consecutivos, força delay
    if (isset($_SESSION['erros_balanca']) && $_SESSION['erros_balanca'] > 10) {
        error_log("WATCHDOG: Muitos erros consecutivos (" . $_SESSION['erros_balanca'] . "), pausa de 2s");
        sleep(2);
    }
    
    // Tenta conectar (com tratamento de erro melhorado)
    $socket = @fsockopen($balanca_ip, $balanca_porta, $errno, $errstr, $timeout);
    
    if (!$socket) {
        error_log("Falha conexão balança: $errstr ($errno)");
        return "ERRO_CONEXAO:$errno";
    }
    
    // Configurações do socket
    stream_set_timeout($socket, $timeout);
    stream_set_blocking($socket, false); // Modo não bloqueante
    
    // ===== LIMPEZA DO BUFFER =====
    // Descarta qualquer dado "lixo" acumulado no buffer da balança
    $lixo = '';
    $info = stream_get_meta_data($socket);
    $inicio_limpeza = microtime(true);
    
    while (!feof($socket) && !$info['timed_out'] && (microtime(true) - $inicio_limpeza) < 0.5) {
        $chunk = fread($socket, 16);
        if ($chunk !== false && !empty($chunk)) {
            $lixo .= $chunk;
        }
        $info = stream_get_meta_data($socket);
    }
    
    // Se descartou algo, registra para debug
    if (!empty($lixo)) {
        error_log("BUFFER LIMPO: " . strlen($lixo) . " bytes descartados - Hex: " . bin2hex($lixo));
    }
    
    // ===== LEITURA REAL =====
    $dados = '';
    $tamanho_maximo = 16;
    $inicio_leitura = microtime(true);
    $info = stream_get_meta_data($socket);
    
    while (!feof($socket) && !$info['timed_out'] && strlen($dados) < $tamanho_maximo) {
        $chunk = fread($socket, 16);
        if ($chunk !== false) {
            $dados .= $chunk;
        }
        $info = stream_get_meta_data($socket);
        
        // Prevenção de loop infinito
        if ((microtime(true) - $inicio_leitura) > $timeout) {
            error_log("TIMEOUT na leitura da balança");
            break;
        }
    }
    
    fclose($socket);
    $dados = trim($dados);
    
    // ===== VALIDAÇÃO DOS DADOS =====
    // Verifica se recebeu algo
    if (empty($dados)) {
        error_log("ERRO: Nenhum dado recebido da balança");
        return "ERRO_DADOS_VAZIOS";
    }
    
    // Verifica se os dados contêm caracteres de controle (possível corrupção)
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $dados)) {
        error_log("ERRO: Dados corrompidos recebidos - Hex: " . bin2hex($dados));
        return "ERRO_DADOS_CORROMPIDOS";
    }
    
    // Verifica se parece um número válido
    if (!preg_match('/^[\d\.,\s-]+$/', $dados)) {
        error_log("ERRO: Dados não numéricos - Hex: " . bin2hex($dados));
        return "ERRO_DADOS_INVALIDOS";
    }
    
    return $dados;
}

// ============================================
// HANDLER AJAX PARA LEITURA DA BALANÇA
// ============================================
if (isset($_GET['ajax_balanca'])) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/json; charset=UTF-8');
    
    $start_time = microtime(true);
    $peso_raw = lerPesoBalança();
    $end_time = microtime(true);
    $response_time = round(($end_time - $start_time) * 1000, 2);
    
    // Log para debug (opcional)
    error_log("=== LEITURA BALANÇA ===");
    error_log("RAW: " . $peso_raw);
    error_log("RAW Hex: " . bin2hex($peso_raw));
    error_log("Tempo: " . $response_time . "ms");
    
    $valor_numerico = 0;
    $sucesso = false;
    $display_text = '';
    $mensagem_erro = '';
    
    // Verifica se é erro explícito
    if (strpos($peso_raw, 'ERRO_') === 0) {
        // Incrementa contador de erros na sessão
        $_SESSION['erros_balanca'] = isset($_SESSION['erros_balanca']) ? $_SESSION['erros_balanca'] + 1 : 1;
        
        $sucesso = false;
        $mensagem_erro = $peso_raw;
        $display_text = '⚠️ Erro balança';
        
        // Log do erro
        error_log("ERRO BALANÇA #" . $_SESSION['erros_balanca'] . ": " . $peso_raw);
    } else {
        // Extração do valor numérico
        preg_match('/[-]?[\d,\.]+/', $peso_raw, $matches);
        
        if (isset($matches[0])) {
            $valor_string = $matches[0];
            $valor_string = str_replace(',', '.', $valor_string);
            $valor_numerico = (float)$valor_string;
            $valor_numerico = $valor_numerico / 1000000; // Dividir por 1.000.000
            
            $sucesso = true;
            
            // Reset contador de erros no sucesso
            $_SESSION['erros_balanca'] = 0;
            
            if ($valor_numerico == 0) {
                $display_text = '0 ';
            } else {
                $display_text = number_format($valor_numerico, 3, ',', '.') . ' ';
            }
            
            // Verifica estabilidade (opcional - se a balança enviar status)
            if (strpos($peso_raw, 'estável') !== false || strpos($peso_raw, 'ST') !== false) {
                $status_balanca = 'estavel';
            } else {
                $status_balanca = 'instavel';
            }
        } else {
            $sucesso = false;
            $display_text = '⚠️ Formato inválido';
            $_SESSION['erros_balanca'] = isset($_SESSION['erros_balanca']) ? $_SESSION['erros_balanca'] + 1 : 1;
        }
    }
    
    $response = [
        'peso' => $peso_raw,
        'valor_numerico' => $valor_numerico,
        'display' => $display_text,
        'raw' => $peso_raw,
        'raw_hex' => bin2hex($peso_raw),
        'success' => $sucesso,
        'response_time' => $response_time,
        'is_zero' => ($valor_numerico == 0),
        'timestamp' => date('H:i:s'),
        'erros_consecutivos' => $_SESSION['erros_balanca'] ?? 0,
        'mensagem_erro' => $mensagem_erro,
        'status_balanca' => $status_balanca ?? 'desconhecido'
    ];
    
    echo json_encode($response);
    exit;
}

// ============================================
// HANDLER PARA BUSCAR DADOS DA PESAGEM
// ============================================
if (isset($_GET['ajax_get_pesagem']) && isset($_GET['codigo'])) {
    $codigo = $_GET['codigo'];
    
    try {
        $sql = "SELECT * FROM pesagem WHERE codigo = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$codigo]);
        $pesagem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pesagem) {
            $pesagem_formatada = [
                'codigo' => $pesagem['codigo'],
                'placa' => $pesagem['placa'] ?? '-',
                'motorista' => $pesagem['motorista'] ?? '-',
                'nome_fornecedor' => $pesagem['nome_fornecedor'] ?? '-',
                'numero_nf' => $pesagem['numero_nf'] ?? '-',
                'peso_inicial' => !empty($pesagem['peso_inicial']) ? number_format((float)$pesagem['peso_inicial'], 3, ',', '.') : '0,000',
                'peso_final' => !empty($pesagem['peso_final']) ? number_format((float)$pesagem['peso_final'], 3, ',', '.') : '0,000',
                'peso_liquido' => !empty($pesagem['peso_liquido']) ? number_format((float)$pesagem['peso_liquido'], 3, ',', '.') : '0,000',
                'data_hora_inicial' => !empty($pesagem['data_hora_inicial']) && $pesagem['data_hora_inicial'] !== '0000-00-00 00:00:00'
                    ? date('d/m/Y H:i', strtotime($pesagem['data_hora_inicial']))
                    : '-',
                'data_hora_final' => !empty($pesagem['data_hora_final']) && $pesagem['data_hora_final'] !== '0000-00-00 00:00:00'
                    ? date('d/m/Y H:i', strtotime($pesagem['data_hora_final']))
                    : '-',
                'status' => $pesagem['status'] ?? 'A',
                'obs' => $pesagem['obs'] ?? ''
            ];
            
            echo json_encode([
                'success' => true,
                'pesagem' => $pesagem_formatada,
                'raw' => $pesagem
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Registro não encontrado'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro no banco de dados: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// FUNÇÃO PARA FORMATAR VALORES NA TELA
// ============================================
function mostrarValor($valor, $tipo = 'texto') {
    if (is_null($valor) || $valor === '' || $valor === ' ') {
        return '<span class="celula-vazia">-</span>';
    }
    
    if ($tipo === 'numero') {
        return number_format((float)$valor, 3, ',', '.');
    }
    
    if ($tipo === 'inteiro') {
        return (string)(int)$valor;
    }
    
    if ($tipo === 'data') {
        if (!empty($valor) && $valor !== '0000-00-00 00:00:00' && $valor !== '0000-00-00') {
            $timestamp = strtotime($valor);
            if ($timestamp !== false) {
                return date('d/m/Y H:i', $timestamp);
            }
        }
        return '<span class="celula-vazia">-</span>';
    }
    
    if ($tipo === 'status') {
        if (strtoupper($valor) === 'F') {
            return '<span class="status-finalizado" title="Finalizado"><i class="fas fa-check-circle"></i> Finalizado</span>';
        } elseif (strtoupper($valor) === 'A') {
            return '<span class="status-aberto" title="Aberto"><i class="fas fa-spinner fa-spin"></i> Aberto</span>';
        }
    }
    
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

// ============================================
// PROCESSAMENTO DOS FILTROS DE BUSCA
// ============================================
$buscaGeral = isset($_POST['busca_geral']) ? trim($_POST['busca_geral']) : '';
$dataInicio = isset($_POST['data_inicio']) ? $_POST['data_inicio'] : '';
$dataFim = isset($_POST['data_fim']) ? $_POST['data_fim'] : '';
$filtroStatus = isset($_POST['filtro_status']) ? $_POST['filtro_status'] : '';

// Validar formato das datas
if (!empty($dataInicio) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
    $dataInicio = '';
}
if (!empty($dataFim) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = '';
}

// Construir query SQL
$sql = "SELECT * FROM pesagem WHERE 1=1 AND empresa = 1";
$params = [];

if (!empty($buscaGeral)) {
    $sql .= " AND (
        placa ILIKE ? OR 
        motorista ILIKE ? OR 
        nome_fornecedor ILIKE ? OR
        CAST(codigo AS TEXT) ILIKE ? OR
        CAST(peso_inicial AS TEXT) ILIKE ? OR
        CAST(peso_final AS TEXT) ILIKE ? OR
        CAST(peso_liquido AS TEXT) ILIKE ? OR
        CAST(numero_nf AS TEXT) ILIKE ? OR
        obs ILIKE ?
    )";
    
    for ($i = 0; $i < 9; $i++) {
        $params[] = "%$buscaGeral%";
    }
}

if (!empty($dataInicio)) {
    $sql .= " AND data_hora_inicial >= ?";
    $params[] = $dataInicio . ' 00:00:00';
}

if (!empty($dataFim)) {
    $sql .= " AND data_hora_inicial <= ?";
    $params[] = $dataFim . ' 23:59:59';
}

if (!empty($filtroStatus) && $filtroStatus !== 'todos') {
    $sql .= " AND status = ?";
    $params[] = $filtroStatus;
}

$sql .= " ORDER BY codigo DESC";

// Executar query
$stmt = $pdo->prepare($sql);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$temFiltroAplicado = !empty($buscaGeral) || !empty($dataInicio) || !empty($dataFim) || (!empty($filtroStatus) && $filtroStatus !== 'todos');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Sistema de Pesagem 24/7</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== ESTILOS GERAIS ===== */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 10px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-size: 14px;
            min-height: 100vh;
        }

        .container-principal {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 20px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .header-principal {
            background: linear-gradient(135deg, #333 0%, #444 100%);
            height: 70px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            position: relative;
            border-bottom: 3px solid #fdb525;
            border-radius: 12px 12px 0 0;
        }

        .logo-noroaco {
            height: 45px;
            width: auto;
        }

        /* ===== BARRA DE STATUS DA BALANÇA ===== */
        .status-barra {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            padding: 8px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #fdb525;
            color: white;
            font-size: 13px;
        }

        .status-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-led {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        .led-verde {
            background-color: #2ecc71;
            box-shadow: 0 0 10px #2ecc71;
        }

        .led-amarelo {
            background-color: #f1c40f;
            box-shadow: 0 0 10px #f1c40f;
        }

        .led-vermelho {
            background-color: #e74c3c;
            box-shadow: 0 0 10px #e74c3c;
        }

        .led-cinza {
            background-color: #95a5a6;
            box-shadow: none;
            animation: none;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .peso-atual-header {
            font-size: 20px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            background: rgba(0, 0, 0, 0.3);
            padding: 4px 15px;
            border-radius: 20px;
            border: 1px solid #fdb525;
        }

        /* ===== SEARCH CONTAINER ===== */
        .search-container {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            padding: 6px 15px;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            flex: 1;
            max-width: 1000px;
        }

        .search-input-group {
            flex: 1;
            display: flex;
            align-items: center;
            position: relative;
            min-width: 200px;
        }

        .search-input-group i {
            position: absolute;
            left: 12px;
            color: #7f8c8d;
            z-index: 1;
        }

        .search-input {
            padding: 8px 15px 8px 35px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.95);
            transition: all 0.3s;
            width: 100%;
            color: #333;
        }

        .search-input:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.3);
            background: white;
        }

        .filtro-data-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filtro-data-label {
            color: white;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .filtro-data-input {
            padding: 6px 10px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            font-size: 13px;
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            min-width: 120px;
        }

        .filtro-data-input:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 2px rgba(253, 181, 37, 0.3);
            background: white;
        }

        .btn-buscar {
            background-color: #fdb525;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-buscar:hover {
            background-color: #ffc64d;
            transform: translateY(-1px);
        }

        .btn-limpar {
            background-color: #7f8c8d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-limpar:hover {
            background-color: #95a5a6;
            transform: translateY(-1px);
        }

        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-novo-registro {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 6px rgba(253, 181, 37, 0.2);
        }

        .btn-novo-registro:hover {
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(253, 181, 37, 0.3);
        }

        .btn-ticket-carga {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 6px rgba(46, 204, 113, 0.2);
        }

        .btn-ticket-carga:hover {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(46, 204, 113, 0.3);
        }

        /* ===== CONTAINER TABELA ===== */
        .container-tabela {
            flex: 1;
            padding: 15px;
            background: #f8f9fa;
        }

        .titulo-tabela {
            color: #2c3e50;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: white;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border-left: 4px solid #fdb525;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .tabela-container {
            width: 100%;
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
        }

        th {
            background: linear-gradient(135deg, #333 0%, #444 100%);
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            border-right: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        th:last-child {
            border-right: none;
        }

        td {
            padding: 8px;
            border-bottom: 1px solid #e9ecef;
            color: #495057;
            border-right: 1px solid #e9ecef;
            vertical-align: middle;
            height: 45px;
            white-space: nowrap;
            min-width: 50px;
            font-size: 12px;
        }

        td:last-child {
            border-right: none;
        }

        tr:hover {
            background: #f8f9fa;
        }

        tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        tr:nth-child(even):hover {
            background-color: #e9ecef;
        }

        .campo-numerico {
            font-family: 'Courier New', monospace;
            font-weight: 500;
            text-align: right;
        }

        .campo-inteiro {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: center;
        }

        .celula-vazia {
            color: #95a5a6;
            font-style: italic;
        }

        .texto-ellipsis {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
            display: block;
        }

        .celula-expansivel {
            max-height: 60px;
            overflow-y: auto;
            font-size: 11px;
            line-height: 1.3;
            padding: 4px;
        }

        .status-finalizado {
            color: #28a745;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(40, 167, 69, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid rgba(40, 167, 69, 0.2);
            white-space: nowrap;
            font-size: 11px;
        }

        .status-aberto {
            color: #ffc107;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 193, 7, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid rgba(255, 193, 7, 0.2);
            white-space: nowrap;
            font-size: 11px;
        }

        .destaque-busca {
            background-color: #fff3cd;
            color: #856404;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: 600;
        }

        .mensagem-sem-registros {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            font-style: italic;
        }

        .mensagem-sem-registros i {
            font-size: 32px;
            margin-bottom: 15px;
            display: block;
            color: #bdc3c7;
        }

        /* ===== AÇÕES ===== */
        .acoes-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
        }

        .btn-acao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 13px;
        }

        .btn-editar {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }

        .btn-editar:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .btn-editar-desabilitado {
            background-color: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }

        .btn-finalizar {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }

        .btn-finalizar:hover {
            background: linear-gradient(135deg, #218838 0%, #28a745 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        .btn-finalizar-desabilitado {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-download {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            text-decoration: none;
        }

        .btn-download:hover {
            background: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
        }

        .btn-download-desabilitado {
            background-color: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }

        /* ===== MENSAGENS DE ALERTA ===== */
        .alerta {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            z-index: 10000;
            animation: slideIn 0.3s ease;
            max-width: 450px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: none;
        }

        .alerta-sucesso {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 5px solid #28a745;
        }

        .alerta-erro {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 5px solid #dc3545;
        }

        .alerta-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-left: 5px solid #17a2b8;
        }

        .alerta-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            color: #856404;
            border-left: 5px solid #ffc107;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ===== MODAIS ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
            padding: 20px 0;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: #fff;
            margin: 30px auto;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
            border: 1px solid #e0e0e0;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            color: #95a5a6;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .close-modal:hover {
            color: #e74c3c;
            background-color: #f8f9fa;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #34495e;
            font-size: 13px;
        }

        .campo-obrigatorio::after {
            content: " *";
            color: #e74c3c;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s;
            background: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: #fdb525;
            background: white;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 120px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.2);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            background: linear-gradient(135deg, #7f8c8d 0%, #95a5a6 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(127, 140, 141, 0.2);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838 0%, #28a745 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* ===== DISPLAY DE PESO ===== */
        .weight-status-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }

        .weight-display-container {
            display: flex;
            flex-direction: column;
        }

        .weight-display-label {
            font-weight: 600;
            color: #34495e;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .weight-display {
            font-size: 2.5rem;
            font-weight: 700;
            color: #333;
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            border: 4px solid #e1e5eb;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            transition: all 0.3s;
            font-family: 'Courier New', monospace;
        }

        .weight-display.estavel {
            border-color: #28a745;
            background-color: rgba(40, 167, 69, 0.05);
        }

        .weight-display.instavel {
            border-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.05);
            animation: pulse-border 1.5s infinite;
        }

        .weight-display.erro {
            border-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }

        .weight-display.atualizando {
            opacity: 0.7;
            border-color: #17a2b8;
        }

        @keyframes pulse-border {
            0% { border-color: #ffc107; }
            50% { border-color: #ff9f00; }
            100% { border-color: #ffc107; }
        }

        .weight-unit {
            font-size: 1.5rem;
            color: #6c757d;
            margin-left: 8px;
            font-weight: 500;
        }

        .status-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 2px solid #e1e5eb;
            display: flex;
            flex-direction: column;
        }

        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .status-title {
            font-weight: 600;
            color: #34495e;
            font-size: 14px;
        }

        .update-time {
            font-size: 11px;
            color: #6c757d;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #e9ecef;
        }

        .status-indicator-container {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }

        .status-stable {
            background-color: #28a745;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.4);
        }

        .status-unstable {
            background-color: #ffc107;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.4);
            animation: pulse 1.5s infinite;
        }

        .status-error {
            background-color: #dc3545;
            box-shadow: 0 0 8px rgba(220, 53, 69, 0.4);
            animation: pulse 1.5s infinite;
        }

        .status-warning {
            background-color: #ffc107;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.4);
            animation: pulse 1.5s infinite;
        }

        .status-text {
            font-weight: 600;
            font-size: 14px;
            color: #495057;
        }

        .status-description {
            font-size: 12px;
            color: #6c757d;
            line-height: 1.4;
            margin-top: 5px;
        }

        .info-registro {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px solid #e0e0e0;
        }

        .info-item {
            margin-bottom: 8px;
            padding: 6px 0;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item label {
            display: inline-block;
            width: 150px;
            font-weight: 600;
            color: #495057;
            font-size: 13px;
            flex-shrink: 0;
        }

        .info-item .valor {
            flex: 1;
            color: #2c3e50;
            font-weight: 500;
            word-break: break-word;
        }

        .erro-counter {
            font-size: 11px;
            color: #e74c3c;
            margin-left: 10px;
            padding: 2px 6px;
            background: rgba(231, 76, 60, 0.1);
            border-radius: 4px;
        }

        /* ===== RESPONSIVIDADE ===== */
        @media (max-width: 1200px) {
            .search-container {
                flex-wrap: wrap;
                max-width: 100%;
            }
            .header-principal {
                height: auto;
                padding: 15px;
                flex-wrap: wrap;
                gap: 15px;
            }
            .botoes-direita {
                width: 100%;
                justify-content: flex-end;
            }
        }

        @media (max-width: 992px) {
            .weight-status-row {
                grid-template-columns: 1fr;
            }
            .status-barra {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            .search-container {
                padding: 10px;
            }
            .filtro-data-group {
                width: 100%;
            }
            .filtro-data-input {
                width: 100%;
            }
            .botoes-filtro-container {
                width: 100%;
                display: flex;
                gap: 8px;
            }
            .btn-buscar, .btn-limpar {
                flex: 1;
            }
        }

        @media (max-width: 576px) {
            .botoes-direita {
                flex-direction: column;
            }
            .btn-novo-registro, .btn-ticket-carga {
                width: 100%;
            }
            .modal-content {
                padding: 15px;
            }
            .info-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .info-item label {
                width: 100%;
                margin-bottom: 4px;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

            <form method="POST" action="" class="search-container">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text"
                        id="busca_geral"
                        name="busca_geral"
                        class="search-input"
                        placeholder="Buscar por placa, motorista, NF, código, peso, obs..."
                        value="<?php echo htmlspecialchars($buscaGeral, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="off">
                </div>

                <div class="filtro-data-group">
                    <label for="data_inicio" class="filtro-data-label">De:</label>
                    <input type="date" id="data_inicio" name="data_inicio" class="filtro-data-input"
                        value="<?php echo htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="filtro-data-group">
                    <label for="data_fim" class="filtro-data-label">Até:</label>
                    <input type="date" id="data_fim" name="data_fim" class="filtro-data-input"
                        value="<?php echo htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="filtro-data-group">
                    <label for="filtro_status" class="filtro-data-label">Status:</label>
                    <select id="filtro_status" name="filtro_status" class="filtro-data-input">
                        <option value="todos" <?php echo (empty($filtroStatus) || $filtroStatus === 'todos') ? 'selected' : ''; ?>>Todos</option>
                        <option value="A" <?php echo $filtroStatus === 'A' ? 'selected' : ''; ?>>Abertos</option>
                        <option value="F" <?php echo $filtroStatus === 'F' ? 'selected' : ''; ?>>Finalizados</option>
                    </select>
                </div>

                <div class="botoes-filtro-container">
                    <button type="submit" class="btn-buscar">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>

                    <?php if ($temFiltroAplicado): ?>
                        <button type="button" class="btn-limpar" onclick="limparFiltros()">
                            <i class="fas fa-times"></i> Limpar
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <div class="botoes-direita">
                <button onclick="abrirModalTicketCarga()" class="btn-ticket-carga">
                    <i class="fas fa-ticket-alt"></i> Ticket Carga
                </button>

                <button onclick="abrirModalNovoRegistro()" class="btn-novo-registro">
                    <i class="fas fa-plus-circle"></i> Nova Pesagem
                </button>
            </div>
        </div>

        <!-- BARRA DE STATUS DA BALANÇA (24/7) -->
        <div class="status-barra">
            <div class="status-info">
                <div class="status-item">
                    <span class="status-led led-cinza" id="status-led"></span>
                    <span id="status-balanca-texto">Verificando balança...</span>
                </div>
                <div class="status-item">
                    <i class="fas fa-clock"></i>
                    <span id="ultima-leitura">-</span>
                </div>
                <div class="status-item">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="erros-consecutivos">Erros: 0</span>
                </div>
            </div>
            <div class="peso-atual-header" id="peso-header">
                0 kg
            </div>
        </div>

        <div id="mensagemAlerta" class="alerta" style="display: none;"></div>

        <div class="container-tabela">
            <?php if (count($dados) == 0): ?>
                <div class="titulo-tabela">
                    <i class="fas fa-info-circle" style="color: #fdb525; margin-right: 10px;"></i>
                    Nenhum registro encontrado.
                </div>
                <div class="mensagem-sem-registros">
                    <i class="fas fa-database"></i>
                    Não há registros de pesagem para exibir.
                </div>
            <?php else: ?>
                <div class="titulo-tabela">
                    <i class="fas fa-weight" style="color: #fdb525; margin-right: 10px;"></i>
                    Registros de Pesagem
                    <?php if ($temFiltroAplicado): ?>
                        <span style="font-size: 12px; color: #7f8c8d; margin-left: 10px;">
                            (<?php echo count($dados); ?> resultados)
                        </span>
                    <?php endif; ?>
                </div>

                <div class="tabela-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Placa</th>
                                <th>Peso Inicial</th>
                                <th>Peso Final</th>
                                <th>Peso Líquido</th>
                                <th>Motorista</th>
                                <th>Fornecedor</th>
                                <th>Nº NF</th>
                                <th>Data/Hora Inicial</th>
                                <th>Data/Hora Final</th>
                                <th>Observação</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados as $item): ?>
                                <?php
                                $temBusca = !empty($buscaGeral);
                                $classeLinha = $temBusca ? 'registro-encontrado' : '';
                                $codigoDisplay = $item['codigo'];
                                $placaDisplay = mostrarValor($item['placa']);
                                $motoristaDisplay = mostrarValor($item['motorista']);
                                $fornecedorDisplay = mostrarValor($item['nome_fornecedor']);
                                $nfDisplay = mostrarValor($item['numero_nf'], 'inteiro');
                                $obsDisplay = mostrarValor($item['obs']);
                                $temObs = !empty($item['obs']) && $item['obs'] !== ' ' && $item['obs'] !== '-';
                                
                                if ($temBusca) {
                                    $buscaLower = strtolower($buscaGeral);
                                    $codigoStr = (string)$item['codigo'];
                                    $placaStr = strtolower($item['placa']);
                                    $motoristaStr = strtolower($item['motorista']);
                                    $fornecedorStr = strtolower($item['nome_fornecedor']);
                                    $nfStr = isset($item['numero_nf']) ? (string)$item['numero_nf'] : '';
                                    $obsStr = strtolower($item['obs']);
                                    
                                    if (stripos($codigoStr, $buscaGeral) !== false) {
                                        $codigoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $codigoStr);
                                    }
                                    if (stripos($placaStr, $buscaLower) !== false) {
                                        $placaDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $item['placa']);
                                    }
                                    if (stripos($motoristaStr, $buscaLower) !== false) {
                                        $motoristaDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $item['motorista']);
                                    }
                                    if (stripos($fornecedorStr, $buscaLower) !== false) {
                                        $fornecedorDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $item['nome_fornecedor']);
                                    }
                                    if (stripos($nfStr, $buscaGeral) !== false) {
                                        $nfDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $nfStr);
                                    }
                                    if (stripos($obsStr, $buscaLower) !== false) {
                                        $obsDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $item['obs']);
                                    }
                                }
                                ?>
                                <tr class="<?php echo $classeLinha; ?>">
                                    <td style="text-align: center;"><?php echo $codigoDisplay; ?></td>
                                    <td style="text-align: center;"><?php echo $placaDisplay; ?></td>
                                    <td class="campo-numerico"><?php echo mostrarValor($item['peso_inicial'], 'numero'); ?></td>
                                    <td class="campo-numerico"><?php echo mostrarValor($item['peso_final'], 'numero'); ?></td>
                                    <td class="campo-numerico"><?php echo mostrarValor($item['peso_liquido'], 'numero'); ?></td>
                                    <td>
                                        <span class="texto-ellipsis" title="<?php echo htmlspecialchars($item['motorista'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $motoristaDisplay; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="texto-ellipsis" title="<?php echo htmlspecialchars($item['nome_fornecedor'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $fornecedorDisplay; ?>
                                        </span>
                                    </td>
                                    <td class="campo-inteiro"><?php echo $nfDisplay; ?></td>
                                    <td><?php echo mostrarValor($item['data_hora_inicial'], 'data'); ?></td>
                                    <td><?php echo mostrarValor($item['data_hora_final'], 'data'); ?></td>
                                    <td>
                                        <?php if ($temObs): ?>
                                            <div class="celula-expansivel"
                                                title="<?php echo htmlspecialchars($item['obs'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo $obsDisplay; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="celula-vazia">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;"><?php echo mostrarValor($item['status'], 'status'); ?></td>
                                    <td style="text-align: center;">
                                        <div class="acoes-container">
                                            <?php if ($item['status'] === 'A'): ?>
                                                <button onclick="editarRegistro(<?php echo $item['codigo']; ?>)"
                                                    class="btn-acao btn-editar" title="Editar observação">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao btn-editar-desabilitado"
                                                    title="Não é possível editar registros finalizados" disabled>
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($item['status'] === 'A'): ?>
                                                <button onclick="finalizarPesagem(<?php echo $item['codigo']; ?>)"
                                                    class="btn-acao btn-finalizar" title="Finalizar pesagem">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao btn-finalizar-desabilitado"
                                                    title="Registro já finalizado" disabled>
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($item['status'] === 'F'): ?>
                                                <a href="balanca_ticket_pesagem.php?codigo=<?php echo $item['codigo']; ?>"
                                                    target="_blank"
                                                    class="btn-acao btn-download"
                                                    title="Baixar PDF da pesagem">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-acao btn-download-desabilitado"
                                                    title="PDF disponível apenas para registros finalizados" disabled>
                                                    <i class="fas fa-file-pdf"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL TICKET CARGA -->
    <div id="modalTicketCarga" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-ticket-alt" style="color: #27ae60;"></i>
                    Gerar Ticket de Carga
                </div>
                <button class="close-modal" onclick="fecharModalTicketCarga()">&times;</button>
            </div>

            <div style="background: #e8f5e9; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <i class="fas fa-info-circle" style="color: #28a745;"></i>
                Digite o número da carga para gerar o ticket em PDF
            </div>

            <div class="form-group">
                <label for="numero_carga" class="campo-obrigatorio">Número da Carga</label>
                <input type="text"
                    id="numero_carga"
                    class="form-control"
                    placeholder="Ex: 123456"
                    maxlength="20"
                    onkeypress="return validarNumeroCarga(event)"
                    style="font-size: 16px; text-align: center;"
                    autocomplete="off">
                <small class="text-muted">Digite apenas números</small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalTicketCarga()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn btn-success" onclick="gerarTicketPDF()" id="btnGerarPDF" disabled>
                    <i class="fas fa-file-pdf"></i> Gerar PDF
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL NOVA PESAGEM -->
    <div id="modalNovoRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-plus-circle" style="color: #fdb525;"></i>
                    Nova Pesagem
                </div>
                <button class="close-modal" onclick="fecharModalNovoRegistro()">&times;</button>
            </div>

            <form id="formNovoRegistro">
                <input type="hidden" name="empresa" value="1">
                <input type="hidden" name="status" value="A">
                <input type="hidden" id="peso_inicial" name="peso_inicial" value="0">
                <input type="hidden" id="peso_final" name="peso_final" value="0">
                <input type="hidden" id="peso_liquido" name="peso_liquido" value="0">

                <div class="form-row">
                    <div class="form-group">
                        <label for="placa" class="campo-obrigatorio">Placa do Veículo</label>
                        <input type="text" id="placa" name="placa" class="form-control"
                            placeholder="Ex: ABC-1234" maxlength="10" required autocomplete="off">
                    </div>
                    
                    <div class="form-group">
                        <label for="motorista" class="campo-obrigatorio">Motorista</label>
                        <input type="text" id="motorista" name="motorista" class="form-control"
                            placeholder="Nome do motorista" required autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nome_fornecedor">Fornecedor</label>
                        <input type="text" id="nome_fornecedor" name="nome_fornecedor" class="form-control"
                            placeholder="Opcional" autocomplete="off">
                    </div>
                    
                    <div class="form-group">
                        <label for="numero_nf">Número NF</label>
                        <input type="number" id="numero_nf" name="numero_nf" class="form-control"
                            placeholder="Opcional" min="0" step="1" autocomplete="off">
                    </div>
                </div>

                <!-- DISPLAY DE PESO EM TEMPO REAL -->
                <div class="weight-status-row">
                    <div class="weight-display-container">
                        <div class="weight-display-label">Peso Atual (Inicial)</div>
                        <div class="weight-display" id="peso-display-modal">
                            0 <span class="weight-unit">kg</span>
                        </div>
                    </div>
                    <div class="status-card">
                        <div class="status-header">
                            <div class="status-title">Status da Balança</div>
                            <div class="update-time" id="last-update-time-modal">-</div>
                        </div>
                        <div class="status-indicator-container">
                            <span id="status-indicator-modal" class="status-indicator status-unstable"></span>
                            <span class="status-text" id="status-text-modal">Aguardando...</span>
                        </div>
                        <div class="status-description" id="status-description-modal">
                            Conectando à balança...
                        </div>
                        <div class="erro-counter" id="erro-counter-modal" style="display: none;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="obs">Observações</label>
                    <textarea id="obs" name="obs" class="form-control"
                        placeholder="Digite observações sobre a pesagem..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalNovoRegistro()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="salvarNovaPesagem()" id="btn-salvar-pesagem">
                        <i class="fas fa-save"></i> Salvar Pesagem
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR REGISTRO -->
    <div id="modalEditarRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-edit" style="color: #3498db;"></i>
                    Editar Observação
                </div>
                <button class="close-modal" onclick="fecharModalEditarRegistro()">&times;</button>
            </div>

            <form id="formEditarRegistro">
                <input type="hidden" id="edit_codigo" name="codigo">

                <div class="info-registro">
                    <div class="info-item">
                        <label>Código:</label>
                        <span class="valor" id="info_codigo"></span>
                    </div>
                    <div class="info-item">
                        <label>Placa:</label>
                        <span class="valor" id="info_placa"></span>
                    </div>
                    <div class="info-item">
                        <label>Motorista:</label>
                        <span class="valor" id="info_motorista"></span>
                    </div>
                    <div class="info-item">
                        <label>Fornecedor:</label>
                        <span class="valor" id="info_fornecedor"></span>
                    </div>
                    <div class="info-item">
                        <label>Número NF:</label>
                        <span class="valor" id="info_nf"></span>
                    </div>
                    <div class="info-item">
                        <label>Peso Inicial:</label>
                        <span class="valor" id="info_peso_inicial"></span>
                    </div>
                    <div class="info-item">
                        <label>Data/Hora:</label>
                        <span class="valor" id="info_data_inicial"></span>
                    </div>
                    <div class="info-item">
                        <label>Status:</label>
                        <span class="valor" id="info_status"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_obs" class="campo-obrigatorio">Observação</label>
                    <textarea id="edit_obs" name="obs" class="form-control"
                        placeholder="Digite a observação..."
                        style="min-height: 100px;" required></textarea>
                    <small class="text-muted">Apenas a observação pode ser alterada</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalEditarRegistro()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="salvarEdicaoPesagem()" id="btnSalvarEdicao">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL FINALIZAR PESAGEM -->
    <div id="modalFinalizarPesagem" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    Finalizar Pesagem
                </div>
                <button class="close-modal" onclick="fecharModalFinalizarPesagem()">&times;</button>
            </div>

            <form id="formFinalizarPesagem">
                <input type="hidden" id="codigo_finalizar" name="codigo">
                <input type="hidden" id="peso_final_input" name="peso_final">

                <div class="info-registro">
                    <div class="info-item">
                        <label>Código:</label>
                        <span class="valor" id="info_codigo_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Placa:</label>
                        <span class="valor" id="info_placa_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Motorista:</label>
                        <span class="valor" id="info_motorista_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Peso Inicial:</label>
                        <span class="valor" id="info_peso_inicial_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Data Inicial:</label>
                        <span class="valor" id="info_data_inicial_finalizar"></span>
                    </div>
                </div>

                <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin: 15px 0;">
                    <strong>Observação:</strong>
                    <div id="info_obs_finalizar" style="margin-top: 5px;">-</div>
                </div>

                <div class="weight-status-row">
                    <div class="weight-display-container">
                        <div class="weight-display-label">Peso Final</div>
                        <div class="weight-display" id="peso-display-finalizar">
                            0 <span class="weight-unit">kg</span>
                        </div>
                    </div>
                    <div class="status-card">
                        <div class="status-header">
                            <div class="status-title">Status da Balança</div>
                            <div class="update-time" id="last-update-time-finalizar">-</div>
                        </div>
                        <div class="status-indicator-container">
                            <span id="status-indicator-finalizar" class="status-indicator status-unstable"></span>
                            <span class="status-text" id="status-text-finalizar">Aguardando...</span>
                        </div>
                        <div class="status-description" id="status-description-finalizar">
                            Conectando à balança...
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalFinalizarPesagem()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-success" onclick="confirmarFinalizarPesagem()" id="btn-confirmar-finalizar">
                        <i class="fas fa-check"></i> Finalizar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================
        // SISTEMA DE PESAGEM 24/7 - JAVASCRIPT
        // ============================================

        // Variáveis globais
        let ultimoPeso = 0;
        let leiturasIguais = 0;
        const leiturasParaEstabilidade = 3;
        let intervaloHeader = null;
        let errosConsecutivos = 0;
        const MAX_ERROS = 10;

        // ===== FUNÇÕES DE UTILIDADE =====
        function mostrarMensagem(texto, tipo = 'info') {
            const alerta = document.getElementById('mensagemAlerta');
            if (!alerta) return;
            
            alerta.innerHTML = texto;
            alerta.className = 'alerta alerta-' + tipo;
            alerta.style.display = 'flex';
            
            setTimeout(function() {
                alerta.style.display = 'none';
            }, 5000);
        }

        function atualizarStatusHeader(data) {
            const statusLed = document.getElementById('status-led');
            const statusTexto = document.getElementById('status-balanca-texto');
            const ultimaLeitura = document.getElementById('ultima-leitura');
            const errosSpan = document.getElementById('erros-consecutivos');
            const pesoHeader = document.getElementById('peso-header');
            
            if (!statusLed || !statusTexto) return;
            
            // Atualiza LED e status
            if (data.success) {
                if (data.valor_numerico > 0) {
                    statusLed.className = 'status-led led-verde';
                    statusTexto.textContent = 'Balança OK - Peso estável';
                } else {
                    statusLed.className = 'status-led led-amarelo';
                    statusTexto.textContent = 'Balança OK - Peso zero';
                }
            } else {
                statusLed.className = 'status-led led-vermelho';
                statusTexto.textContent = 'Falha na comunicação';
                errosConsecutivos = data.erros_consecutivos || 0;
            }
            
            // Atualiza contador de erros
            if (errosConsecutivos > 0) {
                errosSpan.innerHTML = `Erros: ${errosConsecutivos}`;
                if (errosConsecutivos > MAX_ERROS) {
                    errosSpan.style.color = '#e74c3c';
                    errosSpan.style.fontWeight = 'bold';
                }
            } else {
                errosSpan.innerHTML = 'Erros: 0';
                errosSpan.style.color = '';
            }
            
            // Atualiza peso no header
            if (data.display && data.display !== '') {
                pesoHeader.textContent = data.display + 'kg';
            }
            
            // Atualiza hora da última leitura
            if (data.timestamp) {
                ultimaLeitura.textContent = 'Última: ' + data.timestamp;
            }
        }

        // ===== FUNÇÃO PRINCIPAL DE LEITURA DA BALANÇA =====
        function lerBalanca(callback, source = 'header') {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '?ajax_balanca=1&t=' + new Date().getTime(), true);
            
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const data = JSON.parse(this.responseText);
                        
                        // Atualiza contador de erros
                        if (!data.success) {
                            errosConsecutivos++;
                            if (errosConsecutivos >= MAX_ERROS) {
                                mostrarMensagem('⚠️ Muitos erros consecutivos. Tentando reconectar...', 'warning');
                            }
                        } else {
                            errosConsecutivos = 0;
                        }
                        
                        // Atualiza header sempre
                        atualizarStatusHeader(data);
                        
                        // Chama callback específico se existir
                        if (typeof callback === 'function') {
                            callback(data);
                        }
                    } catch (e) {
                        console.error('Erro ao processar dados:', e);
                    }
                }
            };
            
            xhr.onerror = function() {
                errosConsecutivos++;
                console.error('Erro de conexão');
            };
            
            xhr.send();
        }

        // ===== ATUALIZAÇÃO CONTÍNUA DO HEADER =====
        function iniciarMonitoramentoHeader() {
            if (intervaloHeader) clearInterval(intervaloHeader);
            
            // Primeira leitura imediata
            lerBalanca(null, 'header');
            
            // Depois a cada 3 segundos
            intervaloHeader = setInterval(() => {
                lerBalanca(null, 'header');
            }, 3000);
        }

        // ===== NOVA PESAGEM =====
        window.abrirModalNovoRegistro = function() {
            document.getElementById('modalNovoRegistro').style.display = 'block';
            
            ultimoPeso = 0;
            leiturasIguais = 0;
            errosConsecutivos = 0;
            
            const pesoDisplay = document.getElementById('peso-display-modal');
            const pesoInicial = document.getElementById('peso_inicial');
            const statusIndicator = document.getElementById('status-indicator-modal');
            const statusText = document.getElementById('status-text-modal');
            
            if (pesoDisplay) pesoDisplay.innerHTML = '0 <span class="weight-unit">kg</span>';
            if (pesoInicial) pesoInicial.value = 0;
            
            if (statusIndicator && statusText) {
                statusIndicator.className = "status-indicator status-unstable";
                statusText.textContent = "Conectando...";
            }
            
            // Inicia monitoramento do modal
            if (window.intervaloNovaPesagem) clearInterval(window.intervaloNovaPesagem);
            window.intervaloNovaPesagem = setInterval(atualizarPesoModal, 2000);
            
            // Primeira leitura imediata
            setTimeout(atualizarPesoModal, 100);
        };

        function atualizarPesoModal() {
            lerBalanca(function(data) {
                const pesoDisplay = document.getElementById('peso-display-modal');
                const lastUpdate = document.getElementById('last-update-time-modal');
                const statusIndicator = document.getElementById('status-indicator-modal');
                const statusText = document.getElementById('status-text-modal');
                const statusDesc = document.getElementById('status-description-modal');
                const pesoInicial = document.getElementById('peso_inicial');
                const erroCounter = document.getElementById('erro-counter-modal');
                
                if (!pesoDisplay || !lastUpdate) return;
                
                const now = new Date();
                lastUpdate.textContent = now.toLocaleTimeString();
                
                if (data.success) {
                    pesoDisplay.innerHTML = data.display + '<span class="weight-unit">kg</span>';
                    pesoDisplay.className = 'weight-display ' + (data.status_balanca || 'estavel');
                    
                    if (pesoInicial) pesoInicial.value = data.valor_numerico;
                    
                    // Verifica estabilidade
                    verificarEstabilidadeModal(data.valor_numerico);
                    
                    if (data.valor_numerico == 0) {
                        statusText.textContent = "Peso zero";
                        statusDesc.textContent = "Balança estável com peso zero";
                    } else {
                        statusText.textContent = "Estável";
                        statusDesc.textContent = "Peso capturado com sucesso";
                    }
                    
                    if (erroCounter) erroCounter.style.display = 'none';
                } else {
                    pesoDisplay.innerHTML = '⚠️ <span class="weight-unit">Erro</span>';
                    pesoDisplay.className = 'weight-display erro';
                    statusIndicator.className = "status-indicator status-error";
                    statusText.textContent = "Erro na leitura";
                    statusDesc.textContent = "Falha ao ler balança. Tentando novamente...";
                    
                    if (erroCounter) {
                        erroCounter.style.display = 'block';
                        erroCounter.textContent = `Erros: ${data.erros_consecutivos || 1}`;
                    }
                }
            }, 'modal');
        }

        function verificarEstabilidadeModal(novoPeso) {
            const statusIndicator = document.getElementById('status-indicator-modal');
            const statusText = document.getElementById('status-text-modal');
            
            if (!statusIndicator || !statusText) return;
            
            novoPeso = parseFloat(novoPeso);
            const tolerancia = 0.05;
            const mudanca = Math.abs(novoPeso - ultimoPeso);
            
            if (mudanca < tolerancia) {
                leiturasIguais++;
                
                if (leiturasIguais >= leiturasParaEstabilidade) {
                    statusIndicator.className = "status-indicator status-stable";
                    statusText.textContent = "Estável ✓";
                } else {
                    statusIndicator.className = "status-indicator status-unstable";
                    statusText.textContent = `Estabilizando... (${leiturasIguais}/${leiturasParaEstabilidade})`;
                }
            } else {
                leiturasIguais = 0;
                statusIndicator.className = "status-indicator status-unstable";
                statusText.textContent = "Instável";
            }
            
            ultimoPeso = novoPeso;
        }

        window.fecharModalNovoRegistro = function() {
            document.getElementById('modalNovoRegistro').style.display = 'none';
            document.getElementById('formNovoRegistro').reset();
            
            if (window.intervaloNovaPesagem) {
                clearInterval(window.intervaloNovaPesagem);
                window.intervaloNovaPesagem = null;
            }
        };

        window.salvarNovaPesagem = function() {
            const btnSalvar = document.getElementById('btn-salvar-pesagem');
            const placa = document.getElementById('placa').value;
            const motorista = document.getElementById('motorista').value;
            
            if (!placa || !motorista) {
                mostrarMensagem('Preencha placa e motorista', 'erro');
                return;
            }
            
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            
            const formData = new FormData(document.getElementById('formNovoRegistro'));
            
            fetch('balanca_insert.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Pesagem';
                
                if (data.success) {
                    mostrarMensagem('✓ Pesagem salva! Código: ' + (data.codigo || ''), 'sucesso');
                    fecharModalNovoRegistro();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    mostrarMensagem('Erro: ' + (data.message || 'Erro desconhecido'), 'erro');
                }
            })
            .catch(error => {
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Pesagem';
                mostrarMensagem('Erro de conexão', 'erro');
                console.error(error);
            });
        };

        // ===== FINALIZAR PESAGEM =====
        window.finalizarPesagem = function(codigo) {
            fetch('?ajax_get_pesagem=1&codigo=' + codigo)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const p = data.pesagem;
                        
                        document.getElementById('info_codigo_finalizar').textContent = p.codigo;
                        document.getElementById('info_placa_finalizar').textContent = p.placa;
                        document.getElementById('info_motorista_finalizar').textContent = p.motorista;
                        document.getElementById('info_peso_inicial_finalizar').textContent = p.peso_inicial;
                        document.getElementById('info_data_inicial_finalizar').textContent = p.data_hora_inicial;
                        document.getElementById('info_obs_finalizar').textContent = p.obs || '(Sem observação)';
                        document.getElementById('codigo_finalizar').value = codigo;
                        
                        document.getElementById('modalFinalizarPesagem').style.display = 'block';
                        
                        // Inicia monitoramento
                        ultimoPeso = 0;
                        leiturasIguais = 0;
                        
                        if (window.intervaloFinalizar) clearInterval(window.intervaloFinalizar);
                        window.intervaloFinalizar = setInterval(atualizarPesoFinalizar, 2000);
                        setTimeout(atualizarPesoFinalizar, 100);
                    }
                });
        };

        function atualizarPesoFinalizar() {
            lerBalanca(function(data) {
                const pesoDisplay = document.getElementById('peso-display-finalizar');
                const lastUpdate = document.getElementById('last-update-time-finalizar');
                const pesoFinal = document.getElementById('peso_final_input');
                
                if (!pesoDisplay || !lastUpdate) return;
                
                lastUpdate.textContent = new Date().toLocaleTimeString();
                
                if (data.success) {
                    pesoDisplay.innerHTML = data.display + '<span class="weight-unit">kg</span>';
                    if (pesoFinal) pesoFinal.value = data.valor_numerico;
                    verificarEstabilidadeFinalizar(data.valor_numerico);
                }
            }, 'finalizar');
        }

        function verificarEstabilidadeFinalizar(novoPeso) {
            const statusIndicator = document.getElementById('status-indicator-finalizar');
            const statusText = document.getElementById('status-text-finalizar');
            
            if (!statusIndicator || !statusText) return;
            
            novoPeso = parseFloat(novoPeso);
            const mudanca = Math.abs(novoPeso - ultimoPeso);
            
            if (mudanca < 0.05) {
                leiturasIguais++;
                if (leiturasIguais >= leiturasParaEstabilidade) {
                    statusIndicator.className = "status-indicator status-stable";
                    statusText.textContent = "Estável ✓";
                } else {
                    statusIndicator.className = "status-indicator status-unstable";
                    statusText.textContent = `Estabilizando... (${leiturasIguais}/${leiturasParaEstabilidade})`;
                }
            } else {
                leiturasIguais = 0;
                statusIndicator.className = "status-indicator status-unstable";
                statusText.textContent = "Instável";
            }
            
            ultimoPeso = novoPeso;
        }

        window.confirmarFinalizarPesagem = function() {
            const btnConfirmar = document.getElementById('btn-confirmar-finalizar');
            const pesoFinal = document.getElementById('peso_final_input').value;
            const codigo = document.getElementById('codigo_finalizar').value;
            
            if (!pesoFinal || isNaN(parseFloat(pesoFinal))) {
                mostrarMensagem('Peso final inválido', 'erro');
                return;
            }
            
            btnConfirmar.disabled = true;
            btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Finalizando...';
            
            const formData = new FormData(document.getElementById('formFinalizarPesagem'));
            
            fetch('balanca_upd_fin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Finalizar';
                
                if (data.success) {
                    mostrarMensagem('✓ Pesagem finalizada!', 'sucesso');
                    fecharModalFinalizarPesagem();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    mostrarMensagem('Erro: ' + (data.message || 'Erro desconhecido'), 'erro');
                }
            })
            .catch(error => {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Finalizar';
                mostrarMensagem('Erro de conexão', 'erro');
                console.error(error);
            });
        };

        window.fecharModalFinalizarPesagem = function() {
            document.getElementById('modalFinalizarPesagem').style.display = 'none';
            if (window.intervaloFinalizar) {
                clearInterval(window.intervaloFinalizar);
                window.intervaloFinalizar = null;
            }
        };

        // ===== EDITAR REGISTRO =====
        window.editarRegistro = function(codigo) {
            fetch('?ajax_get_pesagem=1&codigo=' + codigo)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const p = data.pesagem;
                        
                        document.getElementById('edit_codigo').value = p.codigo;
                        document.getElementById('info_codigo').textContent = p.codigo;
                        document.getElementById('info_placa').textContent = p.placa;
                        document.getElementById('info_motorista').textContent = p.motorista;
                        document.getElementById('info_fornecedor').textContent = p.nome_fornecedor;
                        document.getElementById('info_nf').textContent = p.numero_nf;
                        document.getElementById('info_peso_inicial').textContent = p.peso_inicial;
                        document.getElementById('info_data_inicial').textContent = p.data_hora_inicial;
                        document.getElementById('info_status').innerHTML = p.status === 'A' ? 
                            '<span class="status-aberto"><i class="fas fa-spinner fa-spin"></i> Aberto</span>' : 
                            '<span class="status-finalizado"><i class="fas fa-check-circle"></i> Finalizado</span>';
                        document.getElementById('edit_obs').value = p.obs || '';
                        
                        document.getElementById('modalEditarRegistro').style.display = 'block';
                    }
                });
        };

        window.salvarEdicaoPesagem = function() {
            const btnSalvar = document.getElementById('btnSalvarEdicao');
            const codigo = document.getElementById('edit_codigo').value;
            const obs = document.getElementById('edit_obs').value.trim();
            
            if (!codigo || !obs) {
                mostrarMensagem('Preencha a observação', 'erro');
                return;
            }
            
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            
            const formData = new FormData();
            formData.append('codigo', codigo);
            formData.append('obs', obs);
            
            fetch('balanca_upd_alt.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar';
                
                if (data.success) {
                    mostrarMensagem('✓ Observação atualizada!', 'sucesso');
                    fecharModalEditarRegistro();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    mostrarMensagem('Erro: ' + (data.message || 'Erro desconhecido'), 'erro');
                }
            })
            .catch(error => {
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar';
                mostrarMensagem('Erro de conexão', 'erro');
                console.error(error);
            });
        };

        window.fecharModalEditarRegistro = function() {
            document.getElementById('modalEditarRegistro').style.display = 'none';
            document.getElementById('formEditarRegistro').reset();
        };

        // ===== TICKET CARGA =====
        window.abrirModalTicketCarga = function() {
            document.getElementById('modalTicketCarga').style.display = 'block';
            document.getElementById('numero_carga').value = '';
            document.getElementById('btnGerarPDF').disabled = true;
            document.getElementById('numero_carga').focus();
        };

        window.fecharModalTicketCarga = function() {
            document.getElementById('modalTicketCarga').style.display = 'none';
        };

        window.validarNumeroCarga = function(event) {
            const char = String.fromCharCode(event.which);
            if (!/[0-9]/.test(char)) {
                event.preventDefault();
                return false;
            }
            
            setTimeout(() => {
                const valor = event.target.value.trim();
                document.getElementById('btnGerarPDF').disabled = valor === '';
            }, 50);
            
            return true;
        };

        window.gerarTicketPDF = function() {
            const numero = document.getElementById('numero_carga').value.trim();
            if (!numero) {
                mostrarMensagem('Digite o número da carga', 'erro');
                return;
            }
            
            window.open('balanca_ticket_carga.php?carga=' + encodeURIComponent(numero), '_blank');
            fecharModalTicketCarga();
            mostrarMensagem('✓ PDF gerado!', 'sucesso');
        };

        // ===== LIMPAR FILTROS =====
        window.limparFiltros = function() {
            document.getElementById('busca_geral').value = '';
            document.getElementById('data_inicio').value = '';
            document.getElementById('data_fim').value = '';
            document.getElementById('filtro_status').value = 'todos';
            document.querySelector('form[method="POST"]').submit();
        };

        // ===== FECHAR MODAIS COM ESC =====
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('modalEditarRegistro').style.display === 'block') {
                    fecharModalEditarRegistro();
                }
                if (document.getElementById('modalNovoRegistro').style.display === 'block') {
                    fecharModalNovoRegistro();
                }
                if (document.getElementById('modalFinalizarPesagem').style.display === 'block') {
                    fecharModalFinalizarPesagem();
                }
                if (document.getElementById('modalTicketCarga').style.display === 'block') {
                    fecharModalTicketCarga();
                }
            }
        });

        // ===== INICIALIZAÇÃO =====
        document.addEventListener('DOMContentLoaded', function() {
            iniciarMonitoramentoHeader();
            console.log('✅ Sistema 24/7 iniciado - Monitoramento contínuo ativo');
        });
    </script>
</body>
</html>