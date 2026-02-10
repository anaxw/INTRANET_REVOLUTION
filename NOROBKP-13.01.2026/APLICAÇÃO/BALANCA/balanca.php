<?php
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

$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$balanca_ip = '192.168.1.220';
$balanca_porta = 23;
$timeout = 2; // timeout em segundos - MESMO DA PRIMEIRA PROGRAMAÇÃO

// Handler para buscar dados da pesagem para edição
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
                'data_hora_inicial' => !empty($pesagem['data_hora_inicial']) && $pesagem['data_hora_inicial'] !== '0000-00-00 00:00:00'
                    ? date('d/m/Y H:i', strtotime($pesagem['data_hora_inicial']))
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
        exit;
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro no banco de dados: ' . $e->getMessage()
        ]);
        exit;
    }
}

// FUNÇÃO IDÊNTICA À DA PRIMEIRA PROGRAMAÇÃO
function lerPesoBalança()
{
    global $balanca_ip, $balanca_porta, $timeout;

    // limite máximo de execução
    set_time_limit($timeout + 2);

    // socket TCP com timeout
    $socket = @fsockopen($balanca_ip, $balanca_porta, $errno, $errstr, $timeout);

    if (!$socket) {
        return "ERRO: Não foi possível conectar à balança. $errstr ($errno)";
    }

    // timeout de leitura
    stream_set_timeout($socket, $timeout);
    $info = stream_get_meta_data($socket);

    // lê dados com tamanho fixo - MESMO DA PRIMEIRA PROGRAMAÇÃO
    $dados = '';
    $tamanho_maximo = 16; // Máximo de bytes a serem lidos -> 16,32,64,128

    while (!feof($socket) && !$info['timed_out'] && strlen($dados) < $tamanho_maximo) {
        $dados .= fread($socket, 16); // Lê em blocos pequenos
        $info = stream_get_meta_data($socket);
    }

    fclose($socket);

    if (empty($dados)) {
        return "ERRO: Nenhum dado recebido da balança";
    }

    return trim($dados);
}

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
    error_log("Tempo: " . $response_time . "ms");

    $valor_numerico = 0;
    $sucesso = false;
    $display_text = 'ERRO: ' . substr($peso_raw, 0, 20);
    
    // EXTRAÇÃO DO VALOR NUMÉRICO SIMPLES COMO NA PRIMEIRA PROGRAMAÇÃO
    preg_match('/[-]?[\d,\.]+/', $peso_raw, $matches);
    if (isset($matches[0])) {
        $valor_string = $matches[0];
        $valor_string = str_replace(',', '.', $valor_string);
        $valor_numerico = (float)$valor_string;
        $valor_numerico = $valor_numerico / 1000000; // DIVIDIR POR 1.000.000 COMO NA PRIMEIRA
        
        $sucesso = true;
        if ($valor_numerico == 0) {
            $display_text = '0 kg';
        } else {
            $display_text = number_format($valor_numerico, 0, ',', '.') . ' kg';
        }
    }

    $response = [
        'peso' => $peso_raw,
        'valor_numerico' => $valor_numerico,
        'display' => $display_text,
        'raw' => $peso_raw,
        'success' => $sucesso,
        'response_time' => $response_time,
        'is_zero' => ($valor_numerico == 0),
        'timestamp' => date('H:i:s')
    ];

    echo json_encode($response);
    exit;
}
function mostrarValor($valor, $tipo = 'texto')
{
    if (is_null($valor) || $valor === '' || $valor === ' ') {
        return '<span class="celula-vazia">-</span>';
    }

    if ($tipo === 'numero') {
        return number_format((float)$valor, 0, ',', '.');
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

$buscaGeral = isset($_POST['busca_geral']) ? trim($_POST['busca_geral']) : '';
$dataInicio = isset($_POST['data_inicio']) ? $_POST['data_inicio'] : '';
$dataFim = isset($_POST['data_fim']) ? $_POST['data_fim'] : '';
$filtroStatus = isset($_POST['filtro_status']) ? $_POST['filtro_status'] : '';

if (!empty($dataInicio) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
    $dataInicio = '';
}

if (!empty($dataFim) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = '';
}

$sql = "SELECT * FROM pesagem WHERE 1=1 and empresa = 1";
$params = [];
$tipos = [];

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
        $tipos[] = 'string';
    }
}

if (!empty($dataInicio)) {
    $sql .= " AND data_hora_inicial >= ?";
    $params[] = $dataInicio . ' 00:00:00';
    $tipos[] = 'string';
}

if (!empty($dataFim)) {
    $sql .= " AND data_hora_inicial <= ?";
    $params[] = $dataFim . ' 23:59:59';
    $tipos[] = 'string';
}

if (!empty($filtroStatus) && $filtroStatus !== 'todos') {
    $sql .= " AND status = ?";
    $params[] = $filtroStatus;
    $tipos[] = 'string';
}

$sql .= " ORDER BY codigo DESC";

$stmt = $pdo->prepare($sql);

if (!empty($params)) {
    foreach ($params as $key => $param) {
        $stmt->bindValue($key + 1, $param, PDO::PARAM_STR);
    }
}

$stmt->execute();
$dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$temFiltroAplicado = !empty($buscaGeral) || !empty($dataInicio) || !empty($dataFim) || (!empty($filtroStatus) && $filtroStatus !== 'todos');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Balança</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="balanca.css">
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

                <!-- Filtro de Status Adicionado -->
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
                    <?php if (!empty($buscaGeral) || !empty($dataInicio) || !empty($dataFim) || (!empty($filtroStatus) && $filtroStatus !== 'todos')): ?>
                        <span style="font-size: 12px; color: #7f8c8d; margin-left: 10px;">
                            (<?php echo count($dados); ?> resultados)
                            <?php if (!empty($filtroStatus) && $filtroStatus !== 'todos'): ?>
                                | Status: <?php echo $filtroStatus === 'A' ? 'Abertos' : 'Finalizados'; ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="tabela-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Placa</th>
                                <th>Peso Inicial</th>
                                <th>Peso Final</th>
                                <th>Peso Líq.</th>
                                <th>Motorista</th>
                                <th>Fornecedor</th>
                                <th>NF</th>
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
                                    <td><?php echo $codigoDisplay; ?></td>
                                    <td><?php echo $placaDisplay; ?></td>
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
                                    <td>
                                        <?php
                                        if (!empty($item['data_hora_inicial']) && $item['data_hora_inicial'] !== '0000-00-00 00:00:00') {
                                            echo mostrarValor($item['data_hora_inicial'], 'data');
                                        } else {
                                            echo '<span class="celula-vazia">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($item['data_hora_final']) && $item['data_hora_final'] !== '0000-00-00 00:00:00') {
                                            echo mostrarValor($item['data_hora_final'], 'data');
                                        } else {
                                            echo '<span class="celula-vazia">-</span>';
                                        }
                                        ?>
                                    </td>
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
                                    <td>
                                        <?php echo mostrarValor($item['status'], 'status'); ?>
                                    </td>

                                    <td>
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

    <!-- MODAL DE TICKET CARGA -->
    <div id="modalTicketCarga" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-ticket-alt" style="color: #28a745;"></i>
                    Gerar Ticket de Carga
                </div>
                <button class="close-modal" onclick="fecharModalTicketCarga()">&times;</button>
            </div>

            <div class="instrucao-ticket">
                <i class="fas fa-info-circle"></i>
                Digite apenas o número da carga para gerar o ticket em PDF
            </div>

            <div class="form-group">
                <label for="numero_carga" class="campo-obrigatorio">Número da Carga *</label>
                <input type="text"
                    id="numero_carga"
                    class="ticket-carga-input"
                    placeholder="Ex: 123456"
                    maxlength="20"
                    onkeypress="return validarNumeroCarga(event)"
                    autocomplete="off">
                <small class="text-muted">Digite apenas números. Apenas uma carga por vez.</small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-gerar-pdf" onclick="gerarTicketPDF()" id="btnGerarPDF" disabled>
                    <i class="fas fa-file-pdf"></i> Gerar PDF do Ticket
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE NOVA PESAGEM -->
    <div id="modalNovoRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-plus-circle" style="color: #fdb525;"></i>
                    Nova Pesagem
                </div>
                <button class="close-modal" onclick="fecharModalNovoRegistro()">&times;</button>
            </div>

            <form id="formNovoRegistro" method="POST" action="balanca_insert.php" accept-charset="UTF-8">
                <input type="hidden" name="empresa" value="1">
                <input type="hidden" name="status" value="A">
                <input type="hidden" id="peso_final" name="peso_final" value="0">
                <input type="hidden" id="peso_liquido" name="peso_liquido" value="0">
                <input type="hidden" id="data_hora_final" name="data_hora_final" value="">

                <div class="form-row">
                    <div class="form-group">
                        <label for="placa" class="campo-obrigatorio">Placa do Veículo *</label>
                        <input type="text" id="placa" name="placa" class="form-control"
                            placeholder="Ex: ABC-1234" maxlength="10" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="motorista" class="campo-obrigatorio">Motorista *</label>
                        <input type="text" id="motorista" name="motorista" class="form-control"
                            placeholder="Nome do motorista" required autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nome_fornecedor">Fornecedor</label>
                        <input type="text" id="nome_fornecedor" name="nome_fornecedor" class="form-control"
                            placeholder="Nome do fornecedor (opcional)" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="numero_nf">Número NF</label>
                        <input type="number" id="numero_nf" name="numero_nf" class="form-control campo-inteiro"
                            placeholder="Ex: 123456 (opcional)" min="0" step="1" autocomplete="off">
                    </div>
                </div>

                <div class="weight-status-row">
                    <div class="weight-display-container">
                        <div class="weight-display-label">Peso Atual *</div>
                        <div class="weight-display" id="peso-display-modal">
                            0 <span class="weight-unit">kg</span>
                        </div>
                        <input type="hidden" id="peso_inicial" name="peso_inicial" value="0">
                    </div>
                    <div class="status-card">
                        <div class="status-header">
                            <div class="status-title">Status da Balança</div>
                            <div class="update-time" id="last-update-time-modal"><?php echo date('H:i:s'); ?></div>
                        </div>

                        <div class="status-indicator-container">
                            <span id="status-indicator-modal" class="status-indicator status-stable"></span>
                            <span class="status-text" id="status-text-modal">Estável</span>
                        </div>

                        <div class="status-description" id="status-description-modal">
                            A balança está estável e pronta para pesagem
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="obs">Observações</label>
                    <textarea id="obs" name="obs" class="form-control"
                        placeholder="Digite observações sobre a pesagem..."
                        style="min-height: 80px;" autocomplete="off"></textarea>
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

    <!-- MODAL DE EDITAR REGISTRO -->
    <div id="modalEditarRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-edit" style="color: #3498db;"></i>
                    Editar Observação da Pesagem
                </div>
                <button class="close-modal" onclick="fecharModalEditarRegistro()">&times;</button>
            </div>

            <form id="formEditarRegistro" method="POST" action="balanca_upd_alt.php" accept-charset="UTF-8">
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
                        <label>Data/Hora Inicial:</label>
                        <span class="valor" id="info_data_inicial"></span>
                    </div>
                    <div class="info-item">
                        <label>Status:</label>
                        <span class="valor" id="info_status"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_obs" class="campo-obrigatorio">Observação *</label>
                    <textarea id="edit_obs" name="obs" class="form-control"
                        placeholder="Digite a observação atualizada..."
                        style="min-height: 100px;" required autocomplete="off"></textarea>
                    <small class="text-muted">Este é o único campo que pode ser alterado. Todos os demais campos são somente leitura.</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalEditarRegistro()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="salvarEdicaoPesagem()" id="btnSalvarEdicao">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE FINALIZAR PESAGEM -->
    <div id="modalFinalizarPesagem" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    Finalizar Pesagem
                </div>
                <button class="close-modal" onclick="fecharModalFinalizarPesagem()">&times;</button>
            </div>

            <form id="formFinalizarPesagem" method="POST" action="balanca_upd_fin.php" accept-charset="UTF-8">
                <input type="hidden" id="codigo_finalizar" name="codigo" value="">
                <input type="hidden" id="peso_final_input" name="peso_final" value="0">

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
                        <label>Fornecedor:</label>
                        <span class="valor" id="info_fornecedor_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Número NF:</label>
                        <span class="valor" id="info_nf_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Peso Inicial:</label>
                        <span class="valor" id="info_peso_inicial_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Data/Hora Inicial:</label>
                        <span class="valor" id="info_data_inicial_finalizar"></span>
                    </div>
                    <div class="info-item">
                        <label>Status:</label>
                        <span class="valor" id="info_status_finalizar"></span>
                    </div>
                </div>

                <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin: 15px 0; font-size: 13px; line-height: 1.5;">
                    <strong>Observação Inicial (não editável):</strong>
                    <div id="info_obs_finalizar" style="margin-top: 5px; color: #495057;">(Sem observação)</div>
                </div>

                <div class="weight-status-row">
                    <div class="weight-display-container">
                        <div class="weight-display-label">Peso Final *</div>
                        <div class="weight-display" id="peso-display-finalizar">
                            0 <span class="weight-unit">kg</span>
                        </div>
                        <input type="hidden" id="peso_final_hidden" name="peso_final_hidden" value="0">
                    </div>
                    <div class="status-card">
                        <div class="status-header">
                            <div class="status-title">Status da Balança</div>
                            <div class="update-time" id="last-update-time-finalizar"><?php echo date('H:i:s'); ?></div>
                        </div>

                        <div class="status-indicator-container">
                            <span id="status-indicator-finalizar" class="status-indicator status-stable"></span>
                            <span class="status-text" id="status-text-finalizar">Estável</span>
                        </div>

                        <div class="status-description" id="status-description-finalizar">
                            A balança está estável e pronta para pesagem final
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalFinalizarPesagem()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-success" onclick="confirmarFinalizarPesagem()" id="btn-confirmar-finalizar">
                        <i class="fas fa-check"></i> Confirmar Finalização
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // VARIÁVEIS GLOBAIS
        let ultimoPeso = 0;
        let leiturasIguais = 0;
        const leiturasParaEstabilidade = 3;
        let intervaloBalanca = null;
        let intervaloBalancaFinalizar = null;
        let ultimaLeituraSucesso = true;
        let cachePeso = {
            valor: 0,
            timestamp: 0,
            tempoVida: 2000,
            display: "0"
        };

        // FUNÇÕES PARA TICKET CARGA
        function abrirModalTicketCarga() {
            document.getElementById('modalTicketCarga').style.display = 'block';
            document.getElementById('numero_carga').value = '';
            document.getElementById('btnGerarPDF').disabled = true;

            setTimeout(() => {
                document.getElementById('numero_carga').focus();
            }, 100);
        }

        function fecharModalTicketCarga() {
            document.getElementById('modalTicketCarga').style.display = 'none';
        }

        function validarNumeroCarga(event) {
            const charCode = event.which ? event.which : event.keyCode;

            if (charCode > 31 && (charCode < 48 || charCode > 57) &&
                charCode !== 8 && charCode !== 9 && charCode !== 13 && charCode !== 46) {
                event.preventDefault();
                return false;
            }

            if (charCode === 13) {
                event.preventDefault();
                gerarTicketPDF();
                return false;
            }

            setTimeout(() => {
                const input = document.getElementById('numero_carga');
                const btnGerarPDF = document.getElementById('btnGerarPDF');
                btnGerarPDF.disabled = !input.value.trim();
            }, 10);

            return true;
        }

        function gerarTicketPDF() {
            const numeroCarga = document.getElementById('numero_carga').value.trim();

            if (!numeroCarga) {
                mostrarMensagem('Por favor, digite um número de carga', 'erro');
                return;
            }

            if (!/^\d+$/.test(numeroCarga)) {
                mostrarMensagem('Digite apenas números para a carga', 'erro');
                return;
            }

            const btnGerarPDF = document.getElementById('btnGerarPDF');
            const originalText = btnGerarPDF.innerHTML;
            btnGerarPDF.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando PDF...';
            btnGerarPDF.disabled = true;

            const url = `balanca_ticket_carga.php?seqcarga=${encodeURIComponent(numeroCarga)}`;
            const janela = window.open(url, '_blank');

            setTimeout(() => {
                btnGerarPDF.innerHTML = originalText;
                btnGerarPDF.disabled = false;

                fecharModalTicketCarga();
                mostrarMensagem('PDF gerado com sucesso!', 'sucesso');
            }, 2000);

            if (!janela || janela.closed || typeof janela.closed === 'undefined') {
                setTimeout(() => {
                    mostrarMensagem('Popup bloqueado! Por favor, permita popups para este site.', 'erro');
                }, 500);
            }
        }

        // FUNÇÕES EXISTENTES
        function limparFiltros() {
            document.getElementById('busca_geral').value = '';
            document.getElementById('data_inicio').value = '';
            document.getElementById('data_fim').value = '';
            document.getElementById('filtro_status').value = 'todos';
            document.querySelector('form').submit();
        }

        function abrirModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'block';
            ultimoPeso = 0;
            leiturasIguais = 0;
            ultimaLeituraSucesso = true;

            // Inicializar display com valor do cache se existir
            const pesoDisplay = document.getElementById('peso-display-modal');
            const pesoInicialInput = document.getElementById('peso_inicial');

            if (cachePeso && cachePeso.valor > 0) {
                const pesoFormatado = numberFormat(cachePeso.valor, 0, ',', '.');
                pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;
                pesoInicialInput.value = cachePeso.valor;
                pesoDisplay.classList.add('stable');
            } else {
                pesoDisplay.innerHTML = `0 <span class="weight-unit">kg</span>`;
                pesoInicialInput.value = 0;
                pesoDisplay.classList.add('zero');
            }

            // Leitura imediata
            atualizarPesoModal();

            // Configurar intervalo mais rápido (1.5 segundos em vez de 3)
            if (intervaloBalanca) {
                clearInterval(intervaloBalanca);
            }
            intervaloBalanca = setInterval(atualizarPesoModal, 1500);

            setTimeout(() => {
                document.getElementById('placa').focus();
            }, 100);
        }

        function fecharModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'none';
            document.getElementById('formNovoRegistro').reset();

            if (intervaloBalanca) {
                clearInterval(intervaloBalanca);
                intervaloBalanca = null;
            }
        }

        function salvarNovaPesagem() {
            const form = document.getElementById('formNovoRegistro');
            const formData = new FormData(form);

            const placa = document.getElementById('placa').value;
            const motorista = document.getElementById('motorista').value;
            const empresa = document.querySelector('input[name="empresa"]').value;

            // Agora placa, motorista e empresa são obrigatórios
            if (!placa || !motorista || !empresa) {
                mostrarMensagem('Por favor, preencha pelo menos a placa, motorista e empresa', 'erro');
                return;
            }

            // NÃO VERIFICAR SE PESO É > 0 - ACEITAR PESO 0

            const btnSalvar = document.getElementById('btn-salvar-pesagem');
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            fetch('balanca_insert.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Pesagem';

                    if (data.success) {
                        mostrarMensagem('Pesagem salva com sucesso!', 'sucesso');
                        fecharModalNovoRegistro();
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        mostrarMensagem('Erro ao salvar: ' + data.message, 'erro');
                    }
                })
                .catch(error => {
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Pesagem';
                    mostrarMensagem('Erro de conexão ao tentar salvar os dados.', 'erro');
                });
        }

        function editarRegistro(codigo) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `?ajax_get_pesagem=1&codigo=${codigo}&t=${new Date().getTime()}`, true);

            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const data = JSON.parse(this.responseText);

                        if (data.success) {
                            const pesagem = data.pesagem;

                            document.getElementById('info_codigo').textContent = pesagem.codigo;
                            document.getElementById('info_placa').textContent = pesagem.placa;
                            document.getElementById('info_motorista').textContent = pesagem.motorista;
                            document.getElementById('info_fornecedor').textContent = pesagem.nome_fornecedor;
                            document.getElementById('info_nf').textContent = pesagem.numero_nf;
                            document.getElementById('info_peso_inicial').textContent = pesagem.peso_inicial + ' kg';
                            document.getElementById('info_data_inicial').textContent = pesagem.data_hora_inicial;

                            const statusText = pesagem.status === 'A' ? 'Aberto' : 'Finalizado';
                            const statusClass = pesagem.status === 'A' ? 'status-aberto' : 'status-finalizado';
                            document.getElementById('info_status').innerHTML = `<span class="${statusClass}">${statusText}</span>`;

                            document.getElementById('edit_obs').value = pesagem.obs || '';
                            document.getElementById('edit_codigo').value = codigo;

                            document.getElementById('modalEditarRegistro').style.display = 'block';

                            setTimeout(() => {
                                document.getElementById('edit_obs').focus();
                                document.getElementById('edit_obs').select();
                            }, 300);

                        } else {
                            mostrarMensagem('Erro ao carregar dados da pesagem: ' + data.message, 'erro');
                        }
                    } catch (e) {
                        mostrarMensagem('Erro ao processar dados da pesagem', 'erro');
                        console.error(e);
                    }
                }
            };

            xhr.onerror = function() {
                mostrarMensagem('Erro de conexão ao buscar dados da pesagem', 'erro');
            };

            xhr.send();
        }

        function fecharModalEditarRegistro() {
            document.getElementById('modalEditarRegistro').style.display = 'none';
            document.getElementById('formEditarRegistro').reset();
        }

        function salvarEdicaoPesagem() {
            const form = document.getElementById('formEditarRegistro');
            const formData = new FormData(form);

            const btnSalvar = document.getElementById('btnSalvarEdicao');
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            fetch('balanca_upd_alt.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';

                    if (data.success) {
                        mostrarMensagem('Observação atualizada com sucesso!', 'sucesso');
                        fecharModalEditarRegistro();
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        mostrarMensagem('Erro ao atualizar: ' + data.message, 'erro');
                    }
                })
                .catch(error => {
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
                    mostrarMensagem('Erro de conexão ao tentar salvar as alterações.', 'erro');
                });
        }

        function finalizarPesagem(codigo) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `?ajax_get_pesagem=1&codigo=${codigo}&t=${new Date().getTime()}`, true);

            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const data = JSON.parse(this.responseText);

                        if (data.success) {
                            const pesagem = data.pesagem;

                            document.getElementById('info_codigo_finalizar').textContent = pesagem.codigo;
                            document.getElementById('info_placa_finalizar').textContent = pesagem.placa;
                            document.getElementById('info_motorista_finalizar').textContent = pesagem.motorista;
                            document.getElementById('info_fornecedor_finalizar').textContent = pesagem.nome_fornecedor;
                            document.getElementById('info_nf_finalizar').textContent = pesagem.numero_nf;
                            document.getElementById('info_peso_inicial_finalizar').textContent = pesagem.peso_inicial + ' kg';
                            document.getElementById('info_data_inicial_finalizar').textContent = pesagem.data_hora_inicial;

                            const statusText = pesagem.status === 'A' ? 'Aberto' : 'Finalizado';
                            const statusClass = pesagem.status === 'A' ? 'status-aberto' : 'status-finalizado';
                            document.getElementById('info_status_finalizar').innerHTML = `<span class="${statusClass}">${statusText}</span>`;

                            document.getElementById('info_obs_finalizar').textContent = pesagem.obs || '(Sem observação)';
                            document.getElementById('codigo_finalizar').value = codigo;

                            ultimoPeso = 0;
                            leiturasIguais = 0;
                            ultimaLeituraSucesso = true;

                            document.getElementById('modalFinalizarPesagem').style.display = 'block';

                            // Inicializar display com valor do cache se existir
                            const pesoDisplay = document.getElementById('peso-display-finalizar');
                            const pesoFinalInput = document.getElementById('peso_final_input');
                            const pesoFinalHidden = document.getElementById('peso_final_hidden');

                            if (cachePeso && cachePeso.valor > 0) {
                                const pesoFormatado = numberFormat(cachePeso.valor, 0, ',', '.');
                                pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;
                                pesoFinalInput.value = cachePeso.valor;
                                pesoFinalHidden.value = cachePeso.valor;
                                pesoDisplay.classList.add('stable');
                            } else {
                                pesoDisplay.innerHTML = `0 <span class="weight-unit">kg</span>`;
                                pesoFinalInput.value = 0;
                                pesoFinalHidden.value = 0;
                                pesoDisplay.classList.add('zero');
                            }

                            // Leitura imediata
                            atualizarPesoFinalizar();

                            // Configurar intervalo mais rápido
                            if (intervaloBalancaFinalizar) {
                                clearInterval(intervaloBalancaFinalizar);
                            }
                            intervaloBalancaFinalizar = setInterval(atualizarPesoFinalizar, 1500);

                        } else {
                            mostrarMensagem('Erro ao carregar dados da pesagem: ' + data.message, 'erro');
                        }
                    } catch (e) {
                        mostrarMensagem('Erro ao processar dados da pesagem', 'erro');
                        console.error(e);
                    }
                }
            };

            xhr.onerror = function() {
                mostrarMensagem('Erro de conexão ao buscar dados da pesagem', 'erro');
            };

            xhr.send();
        }

        function fecharModalFinalizarPesagem() {
            document.getElementById('modalFinalizarPesagem').style.display = 'none';
            document.getElementById('formFinalizarPesagem').reset();

            if (intervaloBalancaFinalizar) {
                clearInterval(intervaloBalancaFinalizar);
                intervaloBalancaFinalizar = null;
            }
        }

        function confirmarFinalizarPesagem() {
            const form = document.getElementById('formFinalizarPesagem');
            const formData = new FormData(form);

            const btnConfirmar = document.getElementById('btn-confirmar-finalizar');
            btnConfirmar.disabled = true;
            btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';

            fetch('balanca_upd_fin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Confirmar Finalização';

                    if (data.success) {
                        mostrarMensagem('Pesagem finalizada com sucesso!', 'sucesso');
                        fecharModalFinalizarPesagem();
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        mostrarMensagem('Erro ao finalizar: ' + data.message, 'erro');
                    }
                })
                .catch(error => {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Confirmar Finalização';
                    mostrarMensagem('Erro de conexão ao tentar finalizar a pesagem.', 'erro');
                });
        }

        function mostrarMensagem(mensagem, tipo) {
            var alerta = document.getElementById('mensagemAlerta');
            alerta.textContent = mensagem;
            alerta.className = 'alerta ' + (tipo === 'sucesso' ? 'mensagem-sucesso' : 'mensagem-erro');
            alerta.style.display = 'block';

            setTimeout(function() {
                alerta.style.display = 'none';
            }, 5000);
        }

        // FUNÇÃO OTIMIZADA PARA LEITURA DA BALANÇA COM MANTENÇÃO DE VALOR
        function lerPesoAjax() {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const startTime = Date.now();

                // Timeout de 2 segundos
                xhr.timeout = 2000;

                xhr.open('GET', '?ajax_balanca=1&t=' + new Date().getTime(), true);

                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const data = JSON.parse(this.responseText);
                            const endTime = Date.now();
                            data.responseTime = endTime - startTime;

                            // Se houver erro na resposta, usar cache
                            if (!data.success || isNaN(parseFloat(data.valor_numerico))) {
                                if (cachePeso && cachePeso.valor &&
                                    (Date.now() - cachePeso.timestamp) < cachePeso.tempoVida) {
                                    data.valor_numerico = cachePeso.valor;
                                    data.display = numberFormat(cachePeso.valor, 0, ',', '.') + ' kg';
                                    data.success = true;
                                    data.is_zero = (cachePeso.valor == 0);
                                }
                            }

                            resolve(data);
                        } catch (e) {
                            // Em caso de erro, tentar usar cache
                            if (cachePeso && cachePeso.valor &&
                                (Date.now() - cachePeso.timestamp) < cachePeso.tempoVida) {
                                resolve({
                                    success: true,
                                    valor_numerico: cachePeso.valor,
                                    display: numberFormat(cachePeso.valor, 0, ',', '.') + ' kg',
                                    responseTime: Date.now() - startTime,
                                    is_zero: (cachePeso.valor == 0)
                                });
                            } else {
                                reject({
                                    error: 'Erro ao processar resposta',
                                    responseTime: Date.now() - startTime
                                });
                            }
                        }
                    } else {
                        reject({
                            error: 'Erro HTTP: ' + this.status,
                            responseTime: Date.now() - startTime
                        });
                    }
                };

                xhr.onerror = function() {
                    reject({
                        error: 'Erro de conexão',
                        responseTime: Date.now() - startTime
                    });
                };

                xhr.ontimeout = function() {
                    reject({
                        error: 'Timeout',
                        responseTime: Date.now() - startTime
                    });
                };

                xhr.send();
            });
        }

        async function atualizarPesoModal() {
            const pesoDisplay = document.getElementById('peso-display-modal');
            const lastUpdateTime = document.getElementById('last-update-time-modal');
            const statusText = document.getElementById('status-text-modal');
            const statusIndicator = document.getElementById('status-indicator-modal');
            const statusDescription = document.getElementById('status-description-modal');
            const pesoInicialInput = document.getElementById('peso_inicial');

            // MANTER O VALOR ATUAL ENQUANTO ATUALIZA
            const valorAtual = parseFloat(pesoInicialInput.value) || cachePeso.valor || 0;

            // Indicar que está atualizando
            pesoDisplay.classList.remove('stable', 'error', 'zero');
            pesoDisplay.classList.add('updating');

            // Apenas mostrar "Lendo..." se não houver valor válido em cache
            if (!cachePeso.valor || (Date.now() - cachePeso.timestamp) > cachePeso.tempoVida) {
                statusText.textContent = "Lendo...";
                statusDescription.textContent = "Conectando à balança...";
                statusIndicator.className = "status-indicator status-unstable";
            }

            try {
                const data = await lerPesoAjax();

                const now = new Date();
                lastUpdateTime.textContent = now.toLocaleTimeString();

                if (data.success && !isNaN(parseFloat(data.valor_numerico))) {
                    const pesoAtual = parseFloat(data.valor_numerico);
                    const pesoFormatado = numberFormat(pesoAtual, 0, ',', '.');

                    // SEMPRE ATUALIZAR O VALOR, MESMO SE FOR 0
                    pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;
                    pesoInicialInput.value = pesoAtual;

                    pesoDisplay.classList.remove('updating');
                    pesoDisplay.classList.add('updated');

                    // Se o peso for 0, mostrar como zerado
                    if (pesoAtual === 0) {
                        setTimeout(() => {
                            pesoDisplay.classList.remove('updated');
                            pesoDisplay.classList.add('zero');
                        }, 800);

                        statusIndicator.className = "status-indicator status-zero";
                        statusText.textContent = "Zerado";
                        statusDescription.textContent = `Balança zerada: 0 kg (${data.responseTime || '?'}ms)`;
                        leiturasIguais = leiturasParaEstabilidade; // Forçar estabilidade
                    } else {
                        // Se não for 0, verificar estabilidade normalmente
                        setTimeout(() => {
                            pesoDisplay.classList.remove('updated');
                            pesoDisplay.classList.add('stable');
                        }, 800);

                        verificarEstabilidadeModal(pesoAtual);

                        statusText.textContent = "Atualizado";
                        statusDescription.textContent = `Peso atualizado: ${pesoFormatado} kg (${data.responseTime || '?'}ms)`;
                    }

                    ultimaLeituraSucesso = true;

                    // Atualizar cache com novo valor
                    cachePeso = {
                        valor: pesoAtual,
                        timestamp: Date.now(),
                        tempoVida: 2000,
                        display: pesoFormatado
                    };
                } else {
                    // Manter valor anterior em caso de erro
                    const pesoFormatado = numberFormat(valorAtual, 0, ',', '.');
                    pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;

                    pesoDisplay.classList.remove('updating');
                    pesoDisplay.classList.add('error');

                    statusIndicator.className = "status-indicator status-error";
                    statusText.textContent = "Erro na leitura";
                    statusDescription.textContent = "Usando último valor conhecido";
                    ultimaLeituraSucesso = false;
                }
            } catch (error) {
                // Em caso de erro, manter valor do cache
                const pesoFormatado = numberFormat(valorAtual, 0, ',', '.');
                pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;

                pesoDisplay.classList.remove('updating');
                pesoDisplay.classList.add('error');

                statusIndicator.className = "status-indicator status-error";
                statusText.textContent = "Erro de conexão";
                statusDescription.textContent = `Falha: ${error.error || 'Erro desconhecido'} - Usando último valor`;
                ultimaLeituraSucesso = false;
            }
        }

        function verificarEstabilidadeModal(novoPeso) {
            const statusText = document.getElementById('status-text-modal');
            const statusIndicator = document.getElementById('status-indicator-modal');
            const statusDescription = document.getElementById('status-description-modal');

            // Se o peso for 0, mostrar como zerado/estável
            if (novoPeso === 0) {
                statusIndicator.className = "status-indicator status-zero";
                statusText.textContent = "Zerado";
                statusDescription.textContent = `Balança zerada: 0 kg`;
                leiturasIguais = leiturasParaEstabilidade; // Forçar estabilidade
                return;
            }

            const tolerancia = 0.05;
            const mudanca = Math.abs(novoPeso - ultimoPeso);

            if (mudanca < tolerancia) {
                leiturasIguais++;

                if (leiturasIguais >= leiturasParaEstabilidade) {
                    statusIndicator.className = "status-indicator status-stable";
                    statusText.textContent = "Estável";
                    statusDescription.textContent = `Peso estável: ${numberFormat(novoPeso, 0, ',', '.')} kg`;
                } else {
                    statusIndicator.className = "status-indicator status-unstable";
                    statusText.textContent = "Estabilizando...";
                    statusDescription.textContent = `Aguardando estabilização (${leiturasIguais}/${leiturasParaEstabilidade})`;
                }
            } else {
                leiturasIguais = 0;
                statusIndicator.className = "status-indicator status-unstable";
                statusText.textContent = "Instável";
                statusDescription.textContent = `Peso em variação: ${numberFormat(novoPeso, 0, ',', '.')} kg (dif: ${mudanca.toFixed(3)} kg)`;
            }

            ultimoPeso = novoPeso;
        }

        async function atualizarPesoFinalizar() {
            const pesoDisplay = document.getElementById('peso-display-finalizar');
            const lastUpdateTime = document.getElementById('last-update-time-finalizar');
            const statusText = document.getElementById('status-text-finalizar');
            const statusIndicator = document.getElementById('status-indicator-finalizar');
            const statusDescription = document.getElementById('status-description-finalizar');
            const pesoFinalInput = document.getElementById('peso_final_input');
            const pesoFinalHidden = document.getElementById('peso_final_hidden');

            // MANTER O VALOR ATUAL ENQUANTO ATUALIZA
            const valorAtual = parseFloat(pesoFinalInput.value) || cachePeso.valor || 0;

            // Indicar que está atualizando
            pesoDisplay.classList.remove('stable', 'error', 'zero');
            pesoDisplay.classList.add('updating');

            // Apenas mostrar "Lendo..." se não houver valor válido em cache
            if (!cachePeso.valor || (Date.now() - cachePeso.timestamp) > cachePeso.tempoVida) {
                statusText.textContent = "Lendo...";
                statusDescription.textContent = "Conectando à balança...";
                statusIndicator.className = "status-indicator status-unstable";
            }

            try {
                const data = await lerPesoAjax();

                const now = new Date();
                lastUpdateTime.textContent = now.toLocaleTimeString();

                if (data.success && !isNaN(parseFloat(data.valor_numerico))) {
                    const pesoAtual = parseFloat(data.valor_numerico);
                    const pesoFormatado = numberFormat(pesoAtual, 0, ',', '.');

                    // SEMPRE ATUALIZAR O VALOR, MESMO SE FOR 0
                    pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;

                    pesoFinalInput.value = pesoAtual;
                    pesoFinalHidden.value = pesoAtual;

                    pesoDisplay.classList.remove('updating');
                    pesoDisplay.classList.add('updated');

                    // Se o peso for 0, mostrar como zerado
                    if (pesoAtual === 0) {
                        setTimeout(() => {
                            pesoDisplay.classList.remove('updated');
                            pesoDisplay.classList.add('zero');
                        }, 800);

                        statusIndicator.className = "status-indicator status-zero";
                        statusText.textContent = "Zerado";
                        statusDescription.textContent = `Balança zerada: 0 kg (${data.responseTime || '?'}ms)`;
                        leiturasIguais = leiturasParaEstabilidade; // Forçar estabilidade
                    } else {
                        // Se não for 0, verificar estabilidade normalmente
                        setTimeout(() => {
                            pesoDisplay.classList.remove('updated');
                            pesoDisplay.classList.add('stable');
                        }, 800);

                        verificarEstabilidadeFinalizar(pesoAtual);

                        statusText.textContent = "Atualizado";
                        statusDescription.textContent = `Peso atualizado: ${pesoFormatado} kg (${data.responseTime || '?'}ms)`;
                    }

                    ultimaLeituraSucesso = true;

                    // Atualizar cache com novo valor
                    cachePeso = {
                        valor: pesoAtual,
                        timestamp: Date.now(),
                        tempoVida: 2000,
                        display: pesoFormatado
                    };
                } else {
                    // Manter valor anterior em caso de erro
                    const pesoFormatado = numberFormat(valorAtual, 0, ',', '.');
                    pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;

                    pesoFinalInput.value = valorAtual;
                    pesoFinalHidden.value = valorAtual;

                    pesoDisplay.classList.remove('updating');
                    pesoDisplay.classList.add('error');

                    statusIndicator.className = "status-indicator status-error";
                    statusText.textContent = "Erro na leitura";
                    statusDescription.textContent = "Usando último valor conhecido";
                    ultimaLeituraSucesso = false;
                }
            } catch (error) {
                // Em caso de erro, manter valor do cache
                const pesoFormatado = numberFormat(valorAtual, 0, ',', '.');
                pesoDisplay.innerHTML = `${pesoFormatado} <span class="weight-unit">kg</span>`;

                    pesoFinalInput.value = valorAtual;
                    pesoFinalHidden.value = valorAtual;

                    pesoDisplay.classList.remove('updating');
                    pesoDisplay.classList.add('error');

                    statusIndicator.className = "status-indicator status-error";
                    statusText.textContent = "Erro de conexão";
                    statusDescription.textContent = `Falha: ${error.error || 'Erro desconhecido'} - Usando último valor`;
                    ultimaLeituraSucesso = false;
            }
        }

        function verificarEstabilidadeFinalizar(novoPeso) {
            const statusText = document.getElementById('status-text-finalizar');
            const statusIndicator = document.getElementById('status-indicator-finalizar');
            const statusDescription = document.getElementById('status-description-finalizar');

            // Se o peso for 0, mostrar como zerado/estável
            if (novoPeso === 0) {
                statusIndicator.className = "status-indicator status-zero";
                statusText.textContent = "Zerado";
                statusDescription.textContent = `Balança zerada: 0 kg`;
                leiturasIguais = leiturasParaEstabilidade; // Forçar estabilidade
                return;
            }

            const tolerancia = 0.05;
            const mudanca = Math.abs(novoPeso - ultimoPeso);

            if (mudanca < tolerancia) {
                leiturasIguais++;

                if (leiturasIguais >= leiturasParaEstabilidade) {
                    statusIndicator.className = "status-indicator status-stable";
                    statusText.textContent = "Estável";
                    statusDescription.textContent = `Peso estável: ${numberFormat(novoPeso, 0, ',', '.')} kg`;
                } else {
                    statusIndicator.className = "status-indicator status-unstable";
                    statusText.textContent = "Estabilizando...";
                    statusDescription.textContent = `Aguardando estabilização (${leiturasIguais}/${leiturasParaEstabilidade})`;
                }
            } else {
                leiturasIguais = 0;
                statusIndicator.className = "status-indicator status-unstable";
                statusText.textContent = "Instável";
                statusDescription.textContent = `Peso em variação: ${numberFormat(novoPeso, 0, ',', '.')} kg (dif: ${mudanca.toFixed(3)} kg)`;
            }

            ultimoPeso = novoPeso;
        }

        // Função auxiliar para formatação de números
        function numberFormat(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(',', '').replace(' ', '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? '.' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? ',' : dec_point,
                toFixedFix = function(n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };

            var s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        }

        // CONFIGURAÇÕES INICIAIS
        document.addEventListener('DOMContentLoaded', function() {
            const buscaInput = document.getElementById('busca_geral');
            if (buscaInput && buscaInput.value) {
                buscaInput.select();
            }

            const inputCarga = document.getElementById('numero_carga');
            if (inputCarga) {
                inputCarga.addEventListener('input', function() {
                    const btnGerarPDF = document.getElementById('btnGerarPDF');
                    btnGerarPDF.disabled = !this.value.trim();
                });
            }

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' && event.target.id === 'busca_geral') {
                    event.preventDefault();
                    document.querySelector('form').submit();
                }

                if (event.key === 'Escape' && buscaInput && buscaInput.value) {
                    buscaInput.value = '';
                    buscaInput.focus();
                }

                if (event.key === 'Escape') {
                    fecharModalTicketCarga();
                    fecharModalNovoRegistro();
                    fecharModalFinalizarPesagem();
                    fecharModalEditarRegistro();
                }
            });

            window.addEventListener('click', function(event) {
                const modalTicket = document.getElementById('modalTicketCarga');
                const modalNovo = document.getElementById('modalNovoRegistro');
                const modalFinalizar = document.getElementById('modalFinalizarPesagem');
                const modalEditar = document.getElementById('modalEditarRegistro');

                if (event.target == modalTicket) {
                    fecharModalTicketCarga();
                }
                if (event.target == modalNovo) {
                    fecharModalNovoRegistro();
                }
                if (event.target == modalFinalizar) {
                    fecharModalFinalizarPesagem();
                }
                if (event.target == modalEditar) {
                    fecharModalEditarRegistro();
                }
            });

            // Melhorar a experiência em dispositivos móveis
            if ('ontouchstart' in window) {
                document.querySelectorAll('.btn-acao, .btn, .search-input, .form-control').forEach(el => {
                    el.style.touchAction = 'manipulation';
                });

                // Aumentar área de toque para botões pequenos
                document.querySelectorAll('.btn-acao').forEach(btn => {
                    btn.style.padding = '8px';
                    btn.style.minHeight = '44px';
                    btn.style.minWidth = '44px';
                });
            }

            const placaInput = document.getElementById('placa');
            if (placaInput) {
                placaInput.addEventListener('input', function(e) {
                    this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
                });
            }

            // Auto-focus no campo de busca em desktop
            if (window.innerWidth > 768 && buscaInput && !buscaInput.value) {
                setTimeout(() => {
                    buscaInput.focus();
                }, 300);
            }

            // Ajustar tamanho da tabela quando a janela for redimensionada
            window.addEventListener('resize', function() {
                const tabelaContainer = document.querySelector('.tabela-container');
                if (tabelaContainer) {
                    tabelaContainer.style.maxHeight = (window.innerHeight - 200) + 'px';
                }
            });

            // Inicializar altura da tabela
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 100);

            // Pré-carregar a conexão com a balança quando a página carrega
            setTimeout(() => {
                // Fazer uma leitura inicial silenciosa para "aquecer" a conexão
                if (window.location.href.indexOf('?') === -1) {
                    const testXhr = new XMLHttpRequest();
                    testXhr.open('GET', '?ajax_balanca=1&t=' + new Date().getTime(), true);
                    testXhr.send();
                }
            }, 2000);
        });

        // Detectar mudanças de orientação
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                window.scrollTo(0, 0);
                window.dispatchEvent(new Event('resize'));
            }, 100);
        });
    </script>
</body>

</html>