<?php
header('Content-Type: text/html; charset=utf-8');

// Configurações de codificação
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Função para tratar caracteres especiais
function safe_echo($string) {
    if ($string === null || $string === '') {
        return '';
    }
    // Converte para UTF-8 se necessário
    if (!mb_check_encoding($string, 'UTF-8')) {
        $string = utf8_encode($string);
    }
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Função para garantir que Seq SLA seja sempre inteiro
function formatSeqSla($value) {
    if ($value === null || $value === '') {
        return '0';
    }
    // Remove qualquer caractere não numérico e converte para inteiro
    $intValue = intval(preg_replace('/[^0-9]/', '', $value));
    return (string)$intValue;
}

// Função para converter nomes de colunas para minúsculas
function normalizeColumnNames($row) {
    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[strtolower(trim($key))] = $value;
    }
    return $normalized;
}

// Função para tratar dados nulos
function nullToEmpty($value) {
    return ($value === null) ? '' : $value;
}

// Função para formatar data e hora no padrão brasileiro com hífens
function formatarDataHoraBR($data) {
    if (empty($data)) return '';
    
    // Se a data estiver no formato ISO (YYYY-MM-DD HH:MM:SS)
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data)) {
        $partes = explode(' ', $data); // Separa data e hora
        
        // Formata a data
        $data_parts = explode('-', $partes[0]);
        if (count($data_parts) == 3) {
            $data_formatada = $data_parts[2] . '-' . $data_parts[1] . '-' . $data_parts[0];
            
            // Se tiver hora, adiciona ao formato
            if (isset($partes[1])) {
                // Limita a hora aos primeiros 5 caracteres (HH:MM)
                $hora = substr($partes[1], 0, 5);
                return $data_formatada . ' ' . $hora;
            }
            
            return $data_formatada;
        }
    }
    
    // Se já estiver no formato brasileiro com barras, converte para hífens
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $data)) {
        $data_sem_hora = str_replace('/', '-', $data);
        
        // Verifica se tem hora (formato dd/mm/aaaa HH:MM)
        if (preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/', $data)) {
            $partes = explode(' ', $data);
            return str_replace('/', '-', $partes[0]) . ' ' . $partes[1];
        }
        
        return $data_sem_hora;
    }
    
    return $data;
}

// Função para converter data BR para formato do Firebird
function converterDataParaFirebird($data_br) {
    if (empty($data_br)) return null;
    
    // Verifica se tem hora (formato YYYY-MM-DDTHH:MM dos inputs datetime-local)
    if (strpos($data_br, 'T') !== false) {
        $partes = explode('T', $data_br);
        $data_parts = explode('-', $partes[0]);
        if (count($data_parts) == 3) {
            if (isset($partes[1])) {
                return $data_parts[0] . '-' . $data_parts[1] . '-' . $data_parts[2] . ' ' . $partes[1] . ':00';
            }
            return $data_parts[0] . '-' . $data_parts[1] . '-' . $data_parts[2];
        }
    }
    
    // Formato brasileiro com barras (dd/mm/aaaa)
    $partes = explode('/', $data_br);
    if (count($partes) == 3) {
        // Verifica se tem hora (formato dd/mm/aaaa HH:MM)
        if (strpos($partes[2], ' ') !== false) {
            $data_hora = explode(' ', $partes[2]);
            $ano = $data_hora[0];
            $hora = $data_hora[1];
            return $ano . '-' . $partes[1] . '-' . $partes[0] . ' ' . $hora . ':00';
        }
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }
    
    return $data_br;
}

// Configurações do banco de dados Firebird/Interbase
$host = '192.168.1.209';
$database = 'C:\BD\ARQSIST.FDB';
$username = 'SYSDBA';
$password = 'masterkey';

// String de conexão para Firebird/Interbase
$dsn = "firebird:dbname={$host}:{$database}";

// Variáveis para controle de erros e resultados
$error_message = '';
$resultados = [];
$total_registros = 0;

try {
    // Criando conexão PDO
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Tentar configurar codificação (pode variar conforme o Firebird)
    try {
        $pdo->exec("SET NAMES UTF8");
    } catch (Exception $e) {
        // Se não suportar UTF8, tenta ISO-8859-1
        try {
            $pdo->exec("SET NAMES ISO8859_1");
        } catch (Exception $e) {
            // Ignora se não conseguir
        }
    }
    
    // Recebendo filtros do formulário
    $data_inicio = $_GET['data_inicio'] ?? '';
    $data_fim = $_GET['data_fim'] ?? '';
    
    // Se não houver filtros, usa data padrão (últimos 30 dias)
    if (empty($data_inicio)) {
        $data_inicio = date('d/m/Y', strtotime('-30 days'));
    }
    if (empty($data_fim)) {
        $data_fim = date('d/m/Y');
    }
    
    $data_inicio_firebird = converterDataParaFirebird($data_inicio);
    $data_fim_firebird = converterDataParaFirebird($data_fim);
    
    // SQL OTIMIZADO - Incluindo hora da movimentação
    $sql = "
        SELECT 
            CAST(s.seqsla AS INTEGER) as seqsla, 
            COALESCE(tc.nome, 'NÃO INFORMADO') as Tipo_Consistencia, 
            COALESCE(sc.nome_status, 'ATIVO') as nome_status, 
            s.dt_movimentacao_inicial AS DATA_MOV,
            COALESCE(c.nome, 'SEM CADASTRO') as nome, 
            COALESCE(c.situ, 'A') as situ, 
            COALESCE(g.grupo, 'SEM GRUPO') as grupo,  
            COALESCE(v.nome, 'SEM VENDEDOR') as Nome_Vendedor,
            CASE 
                WHEN c.ncgc IS NOT NULL AND c.ncgc != '' THEN c.ncgc
                WHEN c.ncpf IS NOT NULL AND c.ncpf != '' THEN c.ncpf
                ELSE 'SEM DOCUMENTO'
            END as documento, 
            COALESCE(c.lcred, 0) as lcred,
            CASE 
                WHEN o.Obs1 IS NOT NULL OR o.Obs2 IS NOT NULL OR o.Obs3 IS NOT NULL 
                THEN TRIM(COALESCE(o.Obs1, '') || ' ' || COALESCE(o.Obs2, '') || ' ' || COALESCE(o.Obs3, ''))
                ELSE 'SEM OBSERVAÇÃO'
            END as Obs_Sla, 
            CASE 
                WHEN om.Obs1 IS NOT NULL OR om.Obs2 IS NOT NULL OR om.Obs3 IS NOT NULL 
                THEN TRIM(COALESCE(om.Obs1, '') || ' ' || COALESCE(om.Obs2, '') || ' ' || COALESCE(om.Obs3, ''))
                ELSE 'SEM OBSERVAÇÃO'
            END as Obs_Sla_Motivo, 
            COALESCE(s.nome_usuario, 'SISTEMA') AS USU_INCLUIU
        FROM ARQCAD_SLA s 
        LEFT JOIN ArqCad_Tp_Consistencia tc ON tc.seqlanc = s.seqtp_consistencia 
        LEFT JOIN Arqcad_Status_Consistencia sc ON sc.SeqStatus = s.SeqStatus 
        LEFT JOIN ArqCad c ON c.seqcadastro = s.seqcadastro 
        LEFT JOIN Cidades c1 ON c1.SeqCidade = c.seqcidade 
        LEFT JOIN ArqCad v ON c.tipR = v.tipoc AND c.codr = v.codic 
        LEFT JOIN ArqGrup g ON c.Grupo = g.codigo 
        LEFT JOIN ArqGrup gc ON c.grupo_anal_cred = gc.codigo 
        LEFT JOIN vd_obs o ON o.tipo = 'SLA' AND o.codobs = s.seqsla AND o.Tipoc = c.TipoC AND o.codic = c.CodiC 
        LEFT JOIN vd_obs om ON om.tipo = 'SLM' AND om.codobs = s.seqsla 
        WHERE s.SeqSLA > 0 and s.Status = 'A'
        ORDER BY s.SeqSla
    ";
    
    // Adicionar filtro de data se fornecido
    if (!empty($data_inicio_firebird) && !empty($data_fim_firebird)) {
        $sql = str_replace(
            "WHERE s.SeqSLA > 0", 
            "WHERE s.SeqSLA > 0 AND CAST(s.dt_movimentacao_inicial AS TIMESTAMP) BETWEEN :data_inicio AND :data_fim", 
            $sql
        );
    }
    
    // Preparando a consulta
    $stmt = $pdo->prepare($sql);
    
    // Executando com os parâmetros se houver filtro de data
    if (!empty($data_inicio_firebird) && !empty($data_fim_firebird)) {
        $stmt->execute([
            ':data_inicio' => $data_inicio_firebird,
            ':data_fim' => $data_fim_firebird
        ]);
    } else {
        $stmt->execute();
    }
    
    // Buscando todos os resultados
    $resultados_raw = $stmt->fetchAll();
    $resultados = array_map('normalizeColumnNames', $resultados_raw);
    
    // Garantir que SeqSLA seja inteiro em todos os registros
    foreach ($resultados as &$row) {
        if (isset($row['seqsla'])) {
            $row['seqsla'] = formatSeqSla($row['seqsla']);
        }
    }
    
    $total_registros = count($resultados);
    
    // DEBUG: Mostrar informações apenas se não houver resultados
    if ($total_registros === 0) {
        echo "<div style='background: #fff3cd; padding: 20px; margin: 20px; border: 1px solid #ffeeba; border-radius: 5px; font-family: monospace;'>";
        echo "<h3 style='color: #856404;'>🔍 Informações de Debug</h3>";
        
        // Verificar total de registros na tabela
        $count_stmt = $pdo->query("SELECT COUNT(*) as total FROM ARQCAD_SLA");
        $count_result = $count_stmt->fetch();
        echo "<p><strong>Total de registros na ARQCAD_SLA:</strong> " . $count_result['total'] . "</p>";
        
        // Mostrar os primeiros 5 registros para referência
        $sample_stmt = $pdo->query("SELECT FIRST 5 * FROM ARQCAD_SLA");
        $sample_results = $sample_stmt->fetchAll();
        if (!empty($sample_results)) {
            echo "<p><strong>Primeiros 5 registros (para referência):</strong></p>";
            echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd;'>";
            print_r($sample_results);
            echo "</pre>";
        }
        
        echo "</div>";
    }
    
} catch (PDOException $e) {
    $error_message = $e->getMessage();
    error_log("Erro no banco de dados: " . $error_message);
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Completo de SLA</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1820px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .filtros {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .filtros form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filtros .campo {
            flex: 1;
            min-width: 200px;
        }
        
        .filtros label {
            display: block;
            margin-bottom: 5px;
            color: #495057;
            font-weight: 500;
            font-size: 14px;
        }
        
        .filtros input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .filtros button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .filtros button:hover {
            background: #5a67d8;
        }
        
        .filtros .limpar {
            background: #6c757d;
        }
        
        .filtros .limpar:hover {
            background: #5a6268;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin: 20px;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
        }
        
        .table-container {
            padding: 20px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 500;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
            color: #495057;
        }
        
        /* Estilo específico para a coluna Seq SLA */
        td:first-child {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #667eea;
            text-align: right;
        }
        
        /* Estilo para coluna de data/hora */
        td:nth-child(4) {
            font-family: 'Courier New', monospace;
            color: #2c3e50;
            white-space: nowrap;
        }
        
        /* Estilo para a coluna de ações */
        .acoes-cell {
            white-space: nowrap;
            text-align: center;
        }
        
        .btn-acao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 18px;
            margin: 0 4px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
        }
        
        .btn-visualizar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 5px rgba(102, 126, 234, 0.3);
        }
        
        .btn-visualizar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.4);
        }
        
        .btn-financeiro {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            box-shadow: 0 2px 5px rgba(40, 167, 69, 0.3);
        }
        
        .btn-financeiro:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }
        
        /* Tooltip para os botões */
        .btn-acao {
            position: relative;
        }
        
        .btn-acao::after {
            content: attr(title);
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 100;
        }
        
        .btn-acao:hover::after {
            opacity: 1;
            visibility: visible;
            bottom: -40px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-ativo {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
        }
        
        .sem-dado {
            color: #999;
            font-style: italic;
        }
        
        .obs-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: help;
        }
        
        .pagination {
            padding: 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            border-top: 1px solid #dee2e6;
            flex-wrap: wrap;
        }
        
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            background: white;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .pagination button:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .total-registros {
            padding: 10px 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .total-registros strong {
            color: #667eea;
        }
        
        .info-box {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            margin: 20px;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
        }
        
        .export-options {
            display: flex;
            gap: 10px;
        }
        
        /* Badge para valores inteiros */
        .int-badge {
            display: inline-block;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            color: #495057;
            margin-left: 5px;
        }
        
        @media (max-width: 768px) {
            .filtros form {
                flex-direction: column;
            }
            
            .filtros .campo {
                width: 100%;
            }
            
            th, td {
                font-size: 12px;
                padding: 8px;
            }
            
            .btn-acao {
                width: 30px;
                height: 30px;
                font-size: 16px;
            }
            
            .total-registros {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Relatório Completo de SLA</h1>
            <p>Sistema de Gestão de Consistências - Todos os Registros</p>
            <?php if (!empty($data_inicio) && !empty($data_fim)): ?>
                <p style="font-size: 12px; margin-top: 10px;">Período: <?= safe_echo($data_inicio) ?> a <?= safe_echo($data_fim) ?></p>
            <?php endif; ?>
        </div>
        
        <div class="filtros">
            <form method="GET">
                <div class="campo">
                    <label for="data_inicio">Data Início</label>
                    <input type="text" id="data_inicio" name="data_inicio" value="<?= safe_echo($data_inicio) ?>" placeholder="dd/mm/aaaa HH:MM">
                    <small style="color: #6c757d;">Formato: dd/mm/aaaa HH:MM</small>
                </div>
                <div class="campo">
                    <label for="data_fim">Data Fim</label>
                    <input type="text" id="data_fim" name="data_fim" value="<?= safe_echo($data_fim) ?>" placeholder="dd/mm/aaaa HH:MM">
                    <small style="color: #6c757d;">Formato: dd/mm/aaaa HH:MM</small>
                </div>
                <button type="submit">🔍 Filtrar</button>
                <button type="button" class="limpar" onclick="window.location.href = window.location.pathname">🗑️ Limpar Filtros</button>
            </form>
        </div>
        
        <?php if (!empty($resultados)): ?>
            
            <div class="table-container">
                <table id="data-table">
                    <thead>
                        <tr>
                            <th>Seq SLA</th>
                            <th>Tipo Consistência</th>
                            <th>Status</th>
                            <th>Data/Hora Mov.</th>
                            <th>Nome</th>
                            <th>Situação</th>
                            <th>Grupo</th>
                            <th>Vendedor</th>
                            <th>Documento</th>
                            <th>Limite Crédito</th>
                            <th>Usuário Incluiu</th>
                            <th>AÇÕES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $index => $row): ?>
                            <?php
                            // Determinar classes CSS baseado nos dados
                            $row_class = '';
                            if (strpos($row['nome'] ?? '', 'SEM CADASTRO') !== false) {
                                $row_class = 'sem-cadastro';
                            }
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td><?= safe_echo($row['seqsla'] ?? '0') ?></td>
                                <td class="<?= (strpos($row['tipo_consistencia'] ?? '', 'NÃO INFORMADO') !== false) ? 'sem-dado' : '' ?>">
                                    <?= safe_echo($row['tipo_consistencia'] ?? 'NÃO INFORMADO') ?>
                                </td>
                                <td>
                                    <span class="status-badge status-ativo">
                                        <?= safe_echo($row['nome_status'] ?? 'ATIVO') ?>
                                    </span>
                                </td>
                                <td title="<?= safe_echo($row['data_mov'] ?? '') ?>">
                                    <?= safe_echo(formatarDataHoraBR($row['data_mov'] ?? '')) ?>
                                </td>
                                <td class="<?= (strpos($row['nome'] ?? '', 'SEM CADASTRO') !== false) ? 'sem-dado' : '' ?>">
                                    <?= safe_echo($row['nome'] ?? 'SEM CADASTRO') ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= ($row['situ'] ?? 'A') == 'A' ? 'status-ativo' : 'status-inativo' ?>">
                                        <?= safe_echo($row['situ'] ?? 'A') ?>
                                    </span>
                                </td>
                                <td class="<?= (strpos($row['grupo'] ?? '', 'SEM GRUPO') !== false) ? 'sem-dado' : '' ?>">
                                    <?= safe_echo($row['grupo'] ?? 'SEM GRUPO') ?>
                                </td>
                                <td class="<?= (strpos($row['nome_vendedor'] ?? '', 'SEM VENDEDOR') !== false) ? 'sem-dado' : '' ?>">
                                    <?= safe_echo($row['nome_vendedor'] ?? 'SEM VENDEDOR') ?>
                                </td>
                                <td class="<?= (strpos($row['documento'] ?? '', 'SEM DOCUMENTO') !== false) ? 'sem-dado' : '' ?>">
                                    <?= safe_echo($row['documento'] ?? 'SEM DOCUMENTO') ?>
                                </td>
                                <td>R$ <?= number_format((float)($row['lcred'] ?? 0), 2, ',', '.') ?></td>
                                <td><?= safe_echo($row['usu_incluiu'] ?? 'SISTEMA') ?></td>
                                <td class="acoes-cell">
                                    <!-- Botão Lupa (Visualizar) -->
                                    <a href="#" class="btn-acao btn-visualizar" title="Visualizar detalhes" onclick="visualizarRegistro('<?= safe_echo($row['seqsla'] ?? '0') ?>'); return false;">
                                        🔍
                                    </a>
                                    <!-- Botão Dólar (Financeiro) -->
                                    <a href="#" class="btn-acao btn-financeiro" title="Ações financeiras" onclick="acoesFinanceiras('<?= safe_echo($row['seqsla'] ?? '0') ?>'); return false;">
                                        💲
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (empty($error_message)): ?>
            <div style="text-align: center; padding: 50px; color: #6c757d;">
                <h3>📭 Nenhum registro encontrado</h3>
                <p>Não há registros na tabela ARQCAD_SLA ou o filtro aplicado não retornou resultados.</p>
                <?php if (!empty($data_inicio) || !empty($data_fim)): ?>
                    <p style="margin-top: 20px;">
                        <strong>Dica:</strong> Tente remover os filtros de data para ver todos os registros.
                    </p>
                    <button onclick="window.location.href = window.location.pathname" style="margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        🔄 Ver Todos os Registros
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Script para garantir que a coluna Seq SLA seja exibida como número inteiro
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.getElementById('data-table');
            if (table) {
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    // Formatar Seq SLA como inteiro
                    const firstCell = row.querySelector('td:first-child');
                    if (firstCell) {
                        const value = firstCell.textContent.trim();
                        // Remove qualquer caractere não numérico e converte para inteiro
                        const intValue = parseInt(value.replace(/[^0-9]/g, '')) || 0;
                        firstCell.textContent = intValue;
                        firstCell.setAttribute('data-type', 'integer');
                        firstCell.setAttribute('title', 'Valor inteiro: ' + intValue);
                    }
                });
            }
        });

        // Funções para os botões de ação
        function visualizarRegistro(seqSla) {
            // Aqui você pode implementar a lógica para visualizar o registro
            // Por exemplo, abrir um modal ou redirecionar para uma página de detalhes
            alert('Visualizar registro SLA: ' + seqSla);
            // window.location.href = 'detalhes_sla.php?id=' + seqSla;
        }

        function acoesFinanceiras(seqSla) {
            // Aqui você pode implementar a lógica para ações financeiras
            // Por exemplo, abrir um modal com opções financeiras ou redirecionar
            alert('Ações financeiras para o SLA: ' + seqSla);
            // window.location.href = 'financeiro_sla.php?id=' + seqSla;
        }

        // Helper para formatar data nos inputs
        function formatDateInput(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.substring(0,2) + '/' + value.substring(2);
            }
            if (value.length >= 5) {
                value = value.substring(0,5) + '/' + value.substring(5,9);
            }
            if (value.length >= 10 && value.length < 13) {
                value = value.substring(0,10) + ' ' + value.substring(10);
            }
            if (value.length >= 13) {
                value = value.substring(0,13) + ':' + value.substring(13,15);
            }
            input.value = value;
        }

        // Aplicar formatação aos inputs de data
        document.getElementById('data_inicio')?.addEventListener('input', function() {
            formatDateInput(this);
        });
        
        document.getElementById('data_fim')?.addEventListener('input', function() {
            formatDateInput(this);
        });
    </script>
</body>
</html>