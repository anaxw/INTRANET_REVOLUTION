<?php
function formatarDocumento($documento)
{
    $documento = preg_replace('/[^0-9]/', '', $documento);

    if (strlen($documento) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $documento);
    } elseif (strlen($documento) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $documento);
    } else {
        return $documento;
    }
}

try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $checkTable = $pdo->query("SELECT to_regclass('public.lcred_distribuicao')");
    $tableExists = $checkTable->fetchColumn();

    if (!$tableExists) {
        $createTableSQL = "
        CREATE TABLE lcred_distribuicao (
            id SERIAL PRIMARY KEY,
            codigo_cliente INTEGER NOT NULL,
            id_neocredit VARCHAR(50),
            razao_social VARCHAR(255),
            documento VARCHAR(20),
            unidade VARCHAR(50) NOT NULL,
            nome_unidade VARCHAR(100) NOT NULL,
            valor_distribuido DECIMAL(15,2) DEFAULT 0,
            porcentagem DECIMAL(5,2) DEFAULT 0,
            data_distribuicao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            usuario_distribuidor VARCHAR(100),
            status_distribuicao VARCHAR(20) DEFAULT 'ATIVA'
        );
        
        CREATE INDEX idx_distribuicao_cliente ON lcred_distribuicao(codigo_cliente);
        CREATE INDEX idx_distribuicao_unidade ON lcred_distribuicao(unidade);
        ";

        $pdo->exec($createTableSQL);
    }

    // Obter filtros do formulário
    $buscaGeral = isset($_POST['busca_geral']) ? trim($_POST['busca_geral']) : '';
    $filtroStatus = isset($_POST['filtro_status']) ? $_POST['filtro_status'] : '';

    // Construir a query base
    $sql = "SELECT 
            ln.codigo, 
            ln.id_neocredit, 
            ln.razao_social, 
            ln.documento, 
            ln.risco, 
            ln.classificacao_risco, 
            ln.lcred_aprovado, 
            ln.validade_cred, 
            ln.status, 
            ln.score, 
            ln.situ,
            ln.codic_bm,
            ln.codic_bot,
            ln.codic_ls,
            ln.codic_rp,
            ln.codic_vt_rnd,
            COALESCE((
                SELECT ln.lcred_aprovado - COALESCE(SUM(ld.valor_distribuido), 0)
                FROM lcred_distribuicao ld
                WHERE ld.codigo_cliente = ln.codigo 
                AND ld.status_distribuicao = 'ATIVA'
            ), ln.lcred_aprovado) as saldo_disponivel,
            COALESCE((
                SELECT SUM(ld.valor_distribuido)
                FROM lcred_distribuicao ld
                WHERE ld.codigo_cliente = ln.codigo 
                AND ld.status_distribuicao = 'ATIVA'
            ), 0) as total_distribuido
        FROM lcred_neocredit ln
        WHERE 1=1";

    $params = [];
    $tipos = [];

    // Aplicar filtro de busca geral
    if (!empty($buscaGeral)) {
        $sql .= " AND (
            CAST(ln.codigo AS TEXT) ILIKE ? OR 
            ln.id_neocredit ILIKE ? OR 
            ln.razao_social ILIKE ? OR
            ln.documento ILIKE ? OR
            ln.risco ILIKE ? OR
            ln.classificacao_risco ILIKE ? OR
            CAST(ln.lcred_aprovado AS TEXT) ILIKE ? OR
            CAST(ln.score AS TEXT) ILIKE ? OR
            ln.situ ILIKE ?
        )";

        for ($i = 0; $i < 9; $i++) {
            $params[] = "%$buscaGeral%";
            $tipos[] = 'string';
        }
    }

    // Aplicar filtro de status (situação)
    if (!empty($filtroStatus) && $filtroStatus !== 'todos') {
        $sql .= " AND ln.situ = ?";
        $params[] = $filtroStatus;
        $tipos[] = 'string';
    }

    $sql .= " ORDER BY ln.codigo DESC";

    $stmt = $pdo->prepare($sql);

    if (!empty($params)) {
        foreach ($params as $key => $param) {
            $stmt->bindValue($key + 1, $param, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
    $creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRegistros = count($creditos);
    $temFiltroAplicado = !empty($buscaGeral) || (!empty($filtroStatus) && $filtroStatus !== 'todos');
} catch (PDOException $e) {
    $error = "Erro na conexão: " . $e->getMessage();
    $creditos = [];
    $totalRegistros = 0;
    $temFiltroAplicado = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOROAÇO - Consulta de Crédito Neocredit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="limite_cred_fin.css">
    <style>
        .search-container {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            padding: 6px 15px;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 300px;
            width: 100%;
            margin: 0 auto;
            transition: max-width 0.3s ease;
        }

        .search-container.com-botao-limpar {
            max-width: 390px;
        }

        .search-input-group {
            flex: 1;
            display: flex;
            align-items: center;
            position: relative;
            min-width: 180px;
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
            min-width: 140px;
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
            flex: 1;
            min-width: 0;
        }

        .filtro-data-input:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 2px rgba(253, 181, 37, 0.3);
            background: white;
        }

        .botoes-filtro-container {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
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
            min-width: fit-content;
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
            min-width: fit-content;
        }

        .btn-limpar:hover {
            background-color: #95a5a6;
            transform: translateY(-1px);
        }

        .info-busca {
            color: white;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.1);
            padding: 6px 12px;
            border-radius: 4px;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-filtro {
            color: white;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.1);
            padding: 6px 12px;
            border-radius: 4px;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .data-filtro i {
            font-size: 11px;
            opacity: 0.8;
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
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(253, 181, 37, 0.3);
        }

        /* Estilos para mensagens de busca */
        .destaque-busca {
            background-color: #fff3cd;
            color: #856404;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 600;
        }

        .registro-encontrado {
            background-color: #fff9e6 !important;
            border-left: 4px solid #fdb525;
        }

        /* Estilos para botões desabilitados */
        .btn-acao:disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
            background-color: #95a5a6 !important;
        }

        .btn-acao:disabled:hover {
            transform: none !important;
            background-color: #95a5a6 !important;
        }

        .btn-acao:disabled .fas {
            color: #7f8c8d !important;
        }

        /* Estilos para botões de ação */
        .col-acoes .acoes-container {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
            flex-wrap: nowrap;
        }

        .btn-acao {
            min-width: 40px;
            height: 40px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.3s;
            color: white;
            background-color: #fdb525;
        }

        .btn-acao:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        /* Estilo específico para botão de distribuição */
        .btn-finalizar {
            background-color: #fdb525 !important;
        }

        .btn-finalizar:hover {
            background-color: #ffc64d !important;
        }

        /* Estilo específico para botão de informações */
        .btn-info {
            background-color: #3498db !important;
        }

        .btn-info:hover {
            background-color: #2980b9 !important;
        }

        /* Botão de informações quando finalizado */
        .btn-info-finalizado {
            background-color: #7f8c8d !important;
            opacity: 0.7;
        }

        .btn-info-finalizado:hover {
            background-color: #95a5a6 !important;
            transform: none;
            cursor: default;
        }

        /* Modal de Informações - MESMO ESTILO DO MODAL INSERIR DA BALANÇA */
        .modal-informacoes {
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
        }

        .modal-informacoes-content {
            background-color: #fff;
            margin: 50px auto;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
            border: 1px solid #e0e0e0;
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .close-modal-info {
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
            position: absolute;
            right: 15px;
            top: 15px;
        }

        .close-modal-info:hover {
            color: #e74c3c;
            background-color: #f8f9fa;
        }

        .modal-informacoes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .modal-informacoes-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-informacoes-body {
            padding: 10px 0;
        }

        /* Estilo do formulário - igual ao modal de inserir da balança */
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
            color: #2c3e50;
        }

        .form-control:focus {
            outline: none;
            border-color: #fdb525;
            background: white;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .form-control:disabled {
            background-color: #f5f5f5;
            color: #495057;
            cursor: not-allowed;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
            line-height: 1.4;
        }

        .form-row {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* Classes para status (mantidas do seu código original) */
        .risco-alto {
            color: #e74c3c;
            font-weight: 700;
        }

        .risco-medio {
            color: #f39c12;
            font-weight: 700;
        }

        .risco-baixo {
            color: #27ae60;
            font-weight: 700;
        }

        .score-alto {
            color: #27ae60;
        }

        .score-medio {
            color: #f39c12;
        }

        .score-baixo {
            color: #e74c3c;
        }

        .status-aprovado {
            color: #27ae60;
            font-weight: 700;
        }

        .status-derivar {
            color: #f39c12;
            font-weight: 700;
        }

        .status-negado {
            color: #e74c3c;
            font-weight: 700;
        }

        .situacao-aberto {
            color: #3498db;
        }

        .situacao-finalizado {
            color: #27ae60;
        }

        .valor-monetario-info {
            font-weight: 700;
            font-size: 16px;
            color: #2c3e50;
        }

        .saldo-disponivel {
            color: #27ae60;
        }

        .saldo-esgotado {
            color: #e74c3c;
        }

        .limite-restante {
            color: #f39c12;
        }

        .score-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            background: rgba(0, 0, 0, 0.05);
            margin-left: 5px;
        }

        /* Distribuição por unidades */
        .unidades-distribuidas {
            background: #e8f4fd;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #cfe2ff;
        }

        .unidades-distribuidas h4 {
            margin: 0 0 10px 0;
            color: #0d6efd;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unidade-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #b6d4fe;
        }

        .unidade-item:last-child {
            border-bottom: none;
        }

        .unidade-nome {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 13px;
        }

        .unidade-valor {
            font-weight: 700;
            color: #2c3e50;
            font-family: 'Courier New', monospace;
        }

        /* Badge para distribuição existente */
        .distribuicao-badge {
            background: #27ae60;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Estilos dos botões no modal */
        .modal-informacoes-footer {
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

        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.2);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #7f8c8d 0%, #95a5a6 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(127, 140, 141, 0.2);
        }

        /* Layout de duas colunas para campos */
        .campos-duas-colunas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .campos-duas-colunas {
                grid-template-columns: 1fr;
            }
        }

        /* Estilo para campos somente leitura */
        .campo-readonly {
            background-color: #f8f9fa;
            border-color: #e9ecef;
            color: #495057;
            font-weight: 500;
        }

        /* Alerta para crédito finalizado no modal */
        .alerta-finalizado {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .alerta-finalizado i {
            color: #721c24;
            font-size: 24px;
        }

        .alerta-finalizado-texto h3 {
            margin: 0 0 5px 0;
            color: #721c24;
            font-size: 16px;
        }

        .alerta-finalizado-texto p {
            margin: 0;
            color: #721c24;
            font-size: 14px;
        }

        /* Alerta para excedente */
        .alerta-excedente {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #856404;
            font-weight: 600;
        }

        .alerta-excedente i {
            color: #f39c12;
        }

        /* Layout de três colunas para campos */
        .campos-tres-colunas {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .campos-tres-colunas {
                grid-template-columns: 1fr;
            }
        }

        /* Layout de razão social com largura total */
        .campos-duas-colunas-razao {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .search-container {
                max-width: 100%;
            }

            .search-container.com-botao-limpar {
                max-width: 100%;
            }

            .modal-informacoes-content {
                width: 95%;
                margin: 20px auto;
                padding: 20px;
            }

            .form-row {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media (max-width: 992px) {
            .search-container {
                flex-wrap: wrap;
                padding: 10px;
                gap: 10px;
            }

            .search-input-group {
                min-width: 100%;
                order: 1;
                margin-bottom: 8px;
            }

            .filtro-data-group {
                flex: 1;
                min-width: calc(50% - 5px);
                order: 2;
            }

            .botoes-filtro-container {
                order: 3;
                width: 100%;
                justify-content: center;
                margin-top: 8px;
                display: flex;
                gap: 8px;
            }

            /* Ajuste para os botões na coluna de ações em telas menores */
            .col-acoes .acoes-container {
                gap: 5px;
            }

            .btn-acao {
                min-width: 35px;
                height: 35px;
                font-size: 14px;
            }
        }

        @media (max-width: 768px) {
            .search-container {
                padding: 8px;
                gap: 6px;
            }

            .search-input {
                padding: 6px 10px 6px 30px;
                font-size: 12px;
            }

            .filtro-data-group {
                min-width: 100%;
                margin-bottom: 8px;
            }

            .filtro-data-label {
                font-size: 11px;
                min-width: 50px;
            }

            .filtro-data-input {
                min-width: 0;
                padding: 5px 8px;
                font-size: 12px;
                flex: 1;
            }

            .btn-buscar,
            .btn-limpar {
                padding: 6px 12px;
                font-size: 12px;
                flex: 1;
            }

            .modal-informacoes-content {
                width: 95%;
                margin: 20px auto;
                padding: 20px;
                max-height: 90vh;
            }

            /* Ajuste para os botões em telas muito pequenas */
            .col-acoes .acoes-container {
                flex-direction: row;
                /* Mantém lado a lado mesmo em mobile */
            }
        }

        @media (max-width: 480px) {
            .search-container {
                padding: 6px;
            }

            .filtro-data-group {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .filtro-data-label {
                width: 100%;
            }

            .filtro-data-input {
                width: 100%;
            }

            .botoes-filtro-container {
                flex-direction: column;
                gap: 6px;
            }

            .btn-buscar,
            .btn-limpar {
                width: 100%;
                justify-content: center;
            }

            /* Ajuste final para botões em telas muito pequenas */
            .col-acoes .acoes-container {
                gap: 3px;
            }

            .btn-acao {
                min-width: 32px;
                height: 32px;
                font-size: 13px;
            }

            .modal-informacoes-content {
                padding: 15px;
                width: 98%;
                margin: 10px auto;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo NOROAÇO" class="logo-noroaco">

            <form method="POST" action="" class="search-container <?php echo $temFiltroAplicado ? 'com-botao-limpar' : ''; ?>">
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
        </header>

        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fa-solid fa-coins"></i> Gerenciador de Crédito - Neocredit (Aprovação)
            </div>

            <?php if (isset($error)): ?>
                <div class="alerta mensagem-erro">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="tabela-container">
                <table id="tabelaCreditos">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>ID Neocredit</th>
                            <th>Razão Social</th>
                            <th>Documento</th>
                            <th>Risco</th>
                            <th>Classificação</th>
                            <th>Crédito Aprovado</th>
                            <th>Validade</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Situação</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalRegistros === 0): ?>
                            <tr>
                                <td colspan="12" style="border: none;">
                                    <div class="lista-vazia">
                                        <div class="lista-vazia-icon"></div>
                                        <h3>Nenhum crédito encontrado</h3>
                                        <?php if ($temFiltroAplicado): ?>
                                            <p>Não há registros com os filtros aplicados</p>
                                            <button class="btn-limpar" onclick="limparFiltros()" style="margin-top: 10px;">
                                                <i class="fas fa-filter"></i> Limpar filtros
                                            </button>
                                        <?php else: ?>
                                            <p>Não há registros de crédito na base de dados</p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($creditos as $credito): ?>
                                <?php
                                $distribuicaoExistente = [];
                                try {
                                    $stmtDist = $pdo->prepare("
                                        SELECT unidade, nome_unidade, valor_distribuido 
                                        FROM lcred_distribuicao 
                                        WHERE codigo_cliente = ? AND status_distribuicao = 'ATIVA'
                                    ");
                                    $stmtDist->execute([$credito['codigo']]);
                                    $distribuicaoExistente = $stmtDist->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                }

                                $temDistribuicao = !empty($distribuicaoExistente);

                                $statusClass = '';
                                switch (strtoupper($credito['status'])) {
                                    case 'APROVADO':
                                        $statusClass = 'status-aprovado';
                                        break;
                                    case 'DERIVAR':
                                        $statusClass = 'status-derivar';
                                        break;
                                    case 'NEGADO':
                                        $statusClass = 'status-negado';
                                        break;
                                }

                                $riscoClass = '';
                                switch (strtoupper($credito['risco'])) {
                                    case 'ALTO':
                                        $riscoClass = 'risco-alto';
                                        break;
                                    case 'MÉDIO':
                                    case 'MEDIO':
                                        $riscoClass = 'risco-medio';
                                        break;
                                    case 'BAIXO':
                                        $riscoClass = 'risco-baixo';
                                        break;
                                }

                                $scoreClass = '';
                                $score = intval($credito['score']);
                                if ($score >= 700) {
                                    $scoreClass = 'score-alto';
                                } elseif ($score >= 500) {
                                    $scoreClass = 'score-medio';
                                } else {
                                    $scoreClass = 'score-baixo';
                                }

                                $validade = $credito['validade_cred'];
                                if ($validade) {
                                    try {
                                        $validadeFormatada = date('d/m/Y', strtotime($validade));
                                    } catch (Exception $e) {
                                        $validadeFormatada = $validade;
                                    }
                                } else {
                                    $validadeFormatada = 'N/A';
                                }

                                $creditoAprovado = $credito['lcred_aprovado'];
                                if (is_numeric($creditoAprovado)) {
                                    $creditoFormatado = 'R$ ' . number_format($creditoAprovado, 2, ',', '.');
                                } else {
                                    $creditoFormatado = $creditoAprovado;
                                }

                                $saldoDisponivel = $credito['saldo_disponivel'];
                                $saldoFormatado = 'R$ ' . number_format($saldoDisponivel, 2, ',', '.');
                                $totalDistribuido = $credito['total_distribuido'];

                                $saldoClass = 'saldo-disponivel';
                                if ($saldoDisponivel <= 0) {
                                    $saldoClass = 'saldo-esgotado';
                                } elseif ($saldoDisponivel < $credito['lcred_aprovado']) {
                                    $saldoClass = 'limite-restante';
                                }

                                $documentoFormatado = formatarDocumento($credito['documento']);
                                $documentoLimpo = preg_replace('/[^0-9]/', '', $credito['documento']);
                                $documentoClass = (strlen($documentoLimpo) === 11) ? 'documento-cpf' : 'documento-cnpj';

                                $situacao = strtoupper($credito['situ']);
                                $situacaoClass = '';
                                $situacaoIcon = '';
                                $situacaoText = '';
                                $situacaoDisplay = '';

                                if ($situacao === 'A') {
                                    $situacaoClass = 'situacao-aberto';
                                    $situacaoIcon = 'fa-spinner';
                                    $situacaoText = '';
                                    $situacaoDisplay = '';
                                } elseif ($situacao === 'F') {
                                    $situacaoClass = 'situacao-finalizado';
                                    $situacaoIcon = 'fa-check-circle';
                                    $situacaoText = '';
                                    $situacaoDisplay = '';
                                }

                                // Processar destaque de busca
                                $temBusca = !empty($buscaGeral);
                                $classeLinha = $temBusca ? 'registro-encontrado' : '';

                                $codigoDisplay = $credito['codigo'];
                                $idNeocreditDisplay = $credito['id_neocredit'];
                                $razaoSocialDisplay = $credito['razao_social'];
                                $documentoDisplay = $documentoFormatado;
                                $riscoDisplay = $credito['risco'];
                                $classificacaoDisplay = $credito['classificacao_risco'];
                                $creditoDisplay = $creditoFormatado;
                                $validadeDisplay = $validadeFormatada;
                                $statusDisplay = $credito['status'];
                                $scoreDisplay = $credito['score'];
                                $situacaoDisplayText = $situacaoDisplay;

                                if ($temBusca) {
                                    $buscaLower = strtolower($buscaGeral);
                                    $codigoStr = (string)$credito['codigo'];
                                    $idNeocreditStr = strtolower($credito['id_neocredit']);
                                    $razaoSocialStr = strtolower($credito['razao_social']);
                                    $documentoStr = strtolower($documentoFormatado);
                                    $riscoStr = strtolower($credito['risco']);
                                    $classificacaoStr = strtolower($credito['classificacao_risco']);
                                    $creditoStr = $creditoFormatado;
                                    $statusStr = strtolower($credito['status']);
                                    $scoreStr = (string)$credito['score'];
                                    $situacaoStr = strtolower($situacaoDisplay);

                                    if (stripos($codigoStr, $buscaGeral) !== false) {
                                        $codigoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $codigoStr);
                                    }
                                    if (stripos($idNeocreditStr, $buscaLower) !== false) {
                                        $idNeocreditDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['id_neocredit']);
                                    }
                                    if (stripos($razaoSocialStr, $buscaLower) !== false) {
                                        $razaoSocialDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['razao_social']);
                                    }
                                    if (stripos($documentoStr, $buscaLower) !== false) {
                                        $documentoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $documentoFormatado);
                                    }
                                    if (stripos($riscoStr, $buscaLower) !== false) {
                                        $riscoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['risco']);
                                    }
                                    if (stripos($classificacaoStr, $buscaLower) !== false) {
                                        $classificacaoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['classificacao_risco']);
                                    }
                                    if (stripos($creditoStr, $buscaGeral) !== false) {
                                        $creditoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $creditoFormatado);
                                    }
                                    if (stripos($statusStr, $buscaLower) !== false) {
                                        $statusDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['status']);
                                    }
                                    if (stripos($scoreStr, $buscaGeral) !== false) {
                                        $scoreDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $credito['score']);
                                    }
                                    if (stripos($situacaoStr, $buscaLower) !== false) {
                                        $situacaoDisplayText = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $situacaoDisplay);
                                    }
                                }

                                $dadosModal = htmlspecialchars(json_encode([
                                    'codigo' => $credito['codigo'],
                                    'id_neocredit' => $credito['id_neocredit'],
                                    'razao_social' => $credito['razao_social'],
                                    'documento' => $documentoFormatado,
                                    'documento_limpo' => $documentoLimpo,
                                    'risco' => $credito['risco'],
                                    'classificacao_risco' => $credito['classificacao_risco'],
                                    'lcred_aprovado' => $creditoFormatado,
                                    'lcred_aprovado_numero' => $credito['lcred_aprovado'],
                                    'saldo_disponivel' => $saldoDisponivel,
                                    'saldo_formatado' => $saldoFormatado,
                                    'total_distribuido' => $totalDistribuido,
                                    'validade_cred' => $validadeFormatada,
                                    'status' => $credito['status'],
                                    'score' => $credito['score'],
                                    'situ' => $credito['situ'],
                                    'risco_class' => $riscoClass,
                                    'score_class' => $scoreClass,
                                    'status_class' => $statusClass,
                                    'saldo_class' => $saldoClass,
                                    'tem_distribuicao' => $temDistribuicao,
                                    'distribuicao_existente' => $distribuicaoExistente,
                                    'situacao_class' => $situacaoClass,
                                    'situacao_text' => $situacaoText,
                                    // ADICIONE ESTAS LINHAS PARA OS CÓDIGOS DAS UNIDADES
                                    'codic_bm' => $credito['codic_bm'] ?? null,
                                    'codic_bot' => $credito['codic_bot'] ?? null,
                                    'codic_ls' => $credito['codic_ls'] ?? null,
                                    'codic_rp' => $credito['codic_rp'] ?? null,
                                    'codic_vt_rnd' => $credito['codic_vt_rnd'] ?? null
                                ]), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr data-dados='<?php echo $dadosModal; ?>' id="cliente-<?php echo $credito['codigo']; ?>" class="<?php echo $classeLinha; ?>">
                                    <td><?php echo $codigoDisplay; ?></td>
                                    <td><?php echo $idNeocreditDisplay; ?></td>
                                    <td>
                                        <?php echo $razaoSocialDisplay; ?>
                                        <?php if ($temDistribuicao): ?>
                                            <span class="distribuicao-info">
                                                <i class="fas fa-check-circle" style="color: #28a745;"></i> Crédito distribuído
                                                <?php if ($saldoDisponivel < 0): ?>
                                                    <span class="saldo-negativo-badge">
                                                        <i class="fas fa-exclamation-triangle"></i> Excedente: R$ <?php echo number_format(abs($saldoDisponivel), 2, ',', '.'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $documentoClass; ?>"><?php echo $documentoDisplay; ?></td>
                                    <td class="<?php echo $riscoClass; ?>">
                                        <?php echo $riscoDisplay; ?>
                                    </td>
                                    <td><?php echo $classificacaoDisplay; ?></td>
                                    <td class="valor-monetario">
                                        <?php echo $creditoDisplay; ?>
                                        <?php if ($temDistribuicao): ?>
                                            <span class="distribuicao-info">
                                                Distribuído: R$ <?php echo number_format($totalDistribuido, 2, ',', '.'); ?>
                                                <?php if ($saldoDisponivel < 0): ?>
                                                    <br><span style="color: #dc3545; font-weight: bold;">
                                                        <i class="fas fa-exclamation-triangle"></i> Excedente: R$ <?php echo number_format(abs($saldoDisponivel), 2, ',', '.'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $validadeDisplay; ?></td>
                                    <td class="<?php echo $statusClass; ?>">
                                        <?php echo $statusDisplay; ?>
                                    </td>
                                    <td>
                                        <span class="score-badge <?php echo $scoreClass; ?>">
                                            <?php echo $scoreDisplay; ?>
                                        </span>
                                    </td>
                                    <td class="situacao-col">
                                        <?php if ($situacao === 'A'): ?>
                                            <i class="fas fa-spinner fa-spin situacao-spinner" title="Em Aberto"></i>
                                            <span class="situacao-texto situacao-aberto"><?php echo $situacaoDisplayText; ?></span>
                                        <?php elseif ($situacao === 'F'): ?>
                                            <i class="fas fa-check-circle situacao-check" title="Finalizado"></i>
                                            <span class="situacao-texto situacao-finalizado"><?php echo $situacaoDisplayText; ?></span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($credito['situ']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <?php if ($situacao === 'A'): ?>
                                                <button class="btn-acao btn-finalizar"
                                                    onclick="abrirModalTelaCheia(this)"
                                                    title="Distribuir limite por unidade">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao btn-finalizar" disabled
                                                    style="opacity: 0.5; cursor: not-allowed;"
                                                    title="Crédito finalizado - operação não permitida">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Botão de informações - AGORA FICA AO LADO -->
                                            <?php if ($situacao === 'A'): ?>
                                                <button class="btn-acao btn-info"
                                                    onclick="abrirModalInformacoes(this)"
                                                    title="Finalizar sem Atulizar Limite">
                                                    <i class="fas fa-close"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao btn-info btn-info-finalizado" disabled
                                                    title="Crédito finalizado - apenas visualização">
                                                    <i class="fas fa-close"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de Distribuição (existente) -->
    <div id="modalTelaCheia" class="modal-tela-cheia">
        <div class="modal-tela-cheia-content">
            <button class="close-modal-tela-cheia" onclick="fecharModalTelaCheia()" title="Fechar">
                <i class="fas fa-times"></i>
            </button>

            <div class="modal-tela-cheia-body">
                <div class="modal-metade esquerda">
                    <div class="secao-modal">
                        <div class="secao-titulo">
                            <i class="fas fa-building"></i>
                            <span>Informações da Empresa</span>
                        </div>
                        <div class="secao-conteudo-info" id="infoEmpresaConteudo"></div>
                    </div>

                    <div class="secao-modal">
                        <div class="secao-titulo">
                            <i class="fas fa-chart-line"></i>
                            <span>Análise de Crédito</span>
                        </div>
                        <div class="secao-conteudo-analise" id="analiseCreditoConteudo">
                        </div>
                    </div>
                </div>

                <div class="modal-metade direita">
                    <div class="secao-modal-limite">
                        <div class="secao-titulo">
                            <i class="fas fa-wallet"></i>
                            <span>Limite Disponível</span>
                        </div>
                        <div class="secao-conteudo-limite" id="limiteGrandeConteudo">
                        </div>
                    </div>

                    <div class="secao-modal">
                        <div class="secao-titulo">
                            <i class="fas fa-share-alt"></i>
                            <span>Distribuição por Unidade</span>
                        </div>
                        <div class="secao-conteudo" id="distribuicaoUnidadesConteudo">
                        </div>

                        <div style="text-align: center; padding: 20px;">
                            <button class="btn-salvar-distribuicao" id="btnSalvarDistribuicao" onclick="salvarDistribuicaoTelaCheia()">
                                <i class="fas fa-save"></i> Salvar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Informações - COM ESTILO DO MODAL INSERIR DA BALANÇA (CAMPOS EM FORMULÁRIO) -->
    <div id="modalInformacoes" class="modal-informacoes">
        <div class="modal-informacoes-content">
            <button class="close-modal-info" onclick="fecharModalInformacoes()">&times;</button>

            <div class="modal-informacoes-header">
                <div class="modal-informacoes-title">
                    <i class="fas fa-building" style="color: #fdb525;"></i>
                    Informações do Cliente
                </div>
            </div>

            <div class="modal-informacoes-body" id="modalInfoConteudo">
                <!-- Conteúdo será carregado dinamicamente -->
            </div>
        </div>
    </div>

    <script>
        const UNIDADES = [{
                id: 'barra_mansa',
                nome: 'Barra Mansa',
                icone: 'fas fa-industry'
            },
            {
                id: 'botucatu',
                nome: 'Botucatu',
                icone: 'fas fa-industry'
            },
            {
                id: 'lins',
                nome: 'Lins',
                icone: 'fas fa-industry'
            },
            {
                id: 'rio_preto',
                nome: 'Rio Preto',
                icone: 'fas fa-industry'
            },
            {
                id: 'votuporanga',
                nome: 'Votuporanga',
                icone: 'fas fa-industry'
            }
        ];

        let dadosClienteModalTelaCheia = null;
        let distribuicaoModalTelaCheia = {};

        // Função para limpar filtros
        function limparFiltros() {
            // Remover a classe que aumenta o tamanho do container
            const searchContainer = document.querySelector('.search-container');
            if (searchContainer) {
                searchContainer.classList.remove('com-botao-limpar');
            }

            // Submeter o formulário limpo
            document.getElementById('filtro_status').value = 'todos';
            // Se tiver campo de busca geral, descomente a linha abaixo:
            // document.getElementById('busca_geral').value = '';
            document.querySelector('form').submit();
        }

        // ===== FUNÇÕES PARA MODAL DE DISTRIBUIÇÃO =====
        function abrirModalTelaCheia(botao) {
            // Verificar se o botão está desabilitado
            if (botao.disabled) {
                alert('Este crédito está finalizado. Não é possível distribuir limite.');
                return;
            }

            const linha = botao.closest('tr');
            if (!linha) return;

            dadosClienteModalTelaCheia = JSON.parse(linha.getAttribute('data-dados'));
            distribuicaoModalTelaCheia = {};

            // Verificar se a situação é "F" (Finalizado)
            if (dadosClienteModalTelaCheia.situ === 'F') {
                alert('Este crédito está finalizado. Não é possível distribuir limite.');
                return;
            }

            carregarInformacoesEmpresa();
            carregarAnaliseCredito();
            carregarLimiteGrande();
            carregarDistribuicaoUnidades();

            // Desabilitar o botão de salvar se a situação for "F"
            const btnSalvar = document.getElementById('btnSalvarDistribuicao');
            if (btnSalvar) {
                if (dadosClienteModalTelaCheia.situ === 'F') {
                    btnSalvar.disabled = true;
                    btnSalvar.style.opacity = '0.5';
                    btnSalvar.style.cursor = 'not-allowed';
                    btnSalvar.title = 'Crédito finalizado - operação não permitida';
                } else {
                    btnSalvar.disabled = false;
                    btnSalvar.style.opacity = '1';
                    btnSalvar.style.cursor = 'pointer';
                    btnSalvar.title = 'Salvar distribuição';
                }
            }

            document.getElementById('modalTelaCheia').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function carregarInformacoesEmpresa() {
            const html = `
                <div class="info-empresa-grid">
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-id-card"></i> ID Neocredit
                        </div>
                        <div class="card-analise-valor">${dadosClienteModalTelaCheia.id_neocredit || 'N/A'}</div>
                    </div>
                    
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-file-contract"></i> Documento
                        </div>
                        <div class="card-analise-valor documento-valor">${dadosClienteModalTelaCheia.documento}</div>
                    </div>
                    
                    <div class="card-analise-razao">
                        <div class="razao-social-com-data">
                            <div class="razao-social-texto">
                                <div class="card-analise-titulo">
                                    <i class="fas fa-building"></i> Razão Social
                                </div>
                                <div class="card-analise-valor razao-social-valor">${dadosClienteModalTelaCheia.razao_social}</div>
                            </div>
                            <div class="validade-credito-lado">
                                <div class="card-analise-titulo">
                                    <i class="fas fa-calendar-alt"></i> Validade
                                </div>
                                <div class="card-analise-valor-validade validade-valor">${dadosClienteModalTelaCheia.validade_cred}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('infoEmpresaConteudo').innerHTML = html;
        }

        function carregarAnaliseCredito() {
            const scoreNum = parseInt(dadosClienteModalTelaCheia.score) || 0;
            let scoreClass = 'score-medio';
            if (scoreNum >= 700) scoreClass = 'score-alto';
            if (scoreNum < 500) scoreClass = 'score-baixo';

            const html = `
                <div class="analise-grid">
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-exclamation-triangle"></i>
                            Risco
                        </div>
                        <div class="card-analise-valor ${dadosClienteModalTelaCheia.risco_class}">
                            ${dadosClienteModalTelaCheia.risco || 'N/A'}
                        </div>
                    </div>
                    
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-chart-bar"></i>
                            Classificação de Risco
                        </div>
                        <div class="card-analise-valor">
                            ${dadosClienteModalTelaCheia.classificacao_risco || 'N/A'}
                        </div>
                    </div>
                    
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-clipboard-check"></i>
                            Status
                        </div>
                        <div class="card-analise-valor ${dadosClienteModalTelaCheia.status_class}">
                            ${dadosClienteModalTelaCheia.status || 'N/A'}
                        </div>
                    </div>
                    
                    <div class="card-analise">
                        <div class="card-analise-titulo">
                            <i class="fas fa-star"></i>
                            Score
                        </div>
                        <div class="card-analise-valor">
                            <span class="score-badge ${scoreClass}" style="font-size: 16px; padding: 8px 15px;">${dadosClienteModalTelaCheia.score || '0'}</span>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('analiseCreditoConteudo').innerHTML = html;
        }

        function carregarLimiteGrande() {
            const saldoDisponivel = parseFloat(dadosClienteModalTelaCheia.saldo_disponivel) || 0;
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const totalDistribuido = parseFloat(dadosClienteModalTelaCheia.total_distribuido) || 0;
            const saldoRestante = totalAprovado - totalDistribuido;

            let cor = '#fdb525';
            let icone = '';
            let alertaExcedente = '';

            if (saldoRestante < 0) {
                cor = '#dc3545';
                icone = '<i class="fas fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></i>';
                alertaExcedente = `
                    <div class="excedente-container">
                        <div class="excedente-texto">
                            <i class="fas fa-exclamation-triangle"></i>
                            ATENÇÃO: Distribuição excede o limite aprovado em ${formatarMoeda(Math.abs(saldoRestante))}
                        </div>
                    </div>
                `;
            } else if (saldoRestante === 0) {
                cor = '#28a745';
                icone = '<i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i>';
            }

            const html = `
                <div class="limite-grande-container">
                    <div class="limite-grande-label">
                        ${icone}Saldo Disponível
                    </div>
                    <div class="limite-grande-valor" style="color: ${cor}">
                        ${formatarMoeda(saldoRestante)}
                    </div>
                    ${alertaExcedente}
                </div>
            `;

            document.getElementById('limiteGrandeConteudo').innerHTML = html;
        }

        function carregarDistribuicaoUnidades() {
            // Verificar se o crédito está finalizado
            if (dadosClienteModalTelaCheia.situ === 'F') {
                const html = `
                    <div class="alerta-finalizado">
                        <i class="fas fa-ban"></i>
                        <div class="alerta-finalizado-texto">
                            <h3>Crédito Finalizado</h3>
                            <p>Este crédito está finalizado. Não é possível realizar distribuições.</p>
                        </div>
                    </div>
                `;
                document.getElementById('distribuicaoUnidadesConteudo').innerHTML = html;
                return;
            }

            const saldoDisponivel = parseFloat(dadosClienteModalTelaCheia.saldo_disponivel) || 0;
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const totalDistribuidoExistente = parseFloat(dadosClienteModalTelaCheia.total_distribuido) || 0;

            const distribuicaoExistente = {};
            if (dadosClienteModalTelaCheia.tem_distribuicao && dadosClienteModalTelaCheia.distribuicao_existente) {
                dadosClienteModalTelaCheia.distribuicao_existente.forEach(dist => {
                    distribuicaoExistente[dist.unidade] = parseFloat(dist.valor_distribuido);
                });
            }

            // Função auxiliar para formatar o codic - APENAS quando for null
            const formatarCodic = (codic) => {
                return codic === null || codic === '' || codic === undefined ? '(-)' : `(${codic})`;
            };

            let html = '<div class="distribuicao-linha-horizontal">';

            // Mapear os códigos das unidades
            const codics = {
                'barra_mansa': dadosClienteModalTelaCheia.codic_bm,
                'botucatu': dadosClienteModalTelaCheia.codic_bot,
                'lins': dadosClienteModalTelaCheia.codic_ls,
                'rio_preto': dadosClienteModalTelaCheia.codic_rp,
                'votuporanga': dadosClienteModalTelaCheia.codic_vt_rnd
            };

            UNIDADES.forEach(unidade => {
                const valorExistente = distribuicaoExistente[unidade.id] || 0;
                const valorInicial = valorExistente > 0 ? formatarNumeroBR(valorExistente) : '';

                // Obter o codic para a unidade atual
                const codic = formatarCodic(codics[unidade.id]);

                if (valorExistente > 0) {
                    distribuicaoModalTelaCheia[unidade.id] = valorExistente;
                }

                html += `
                    <div class="item-unidade-linha">
                        <div class="nome-unidade-linha">
                            <i class="${unidade.icone}"></i>
                            ${unidade.nome} ${codic}
                        </div>
                        <div class="input-unidade-grupo-linha">
                            <span class="input-unidade-prefix-linha">R$</span>
                            <input type="text" 
                                   class="input-unidade-linha" 
                                   id="dist_input_${unidade.id}"
                                   data-unidade="${unidade.id}"
                                   placeholder="0,00"
                                   value="${valorInicial}"
                                   oninput="formatarValorDistribuicaoTelaCheia(this)"
                                   onkeyup="validarDistribuicaoTelaCheia()"
                                   ${dadosClienteModalTelaCheia.situ === 'F' ? 'disabled style="background-color: #f5f5f5;"' : ''}>
                        </div>
                        <div class="info-unidade-linha">
                            <span id="dist_porcentagem_${unidade.id}" class="porcentagem-linha">0%</span>
                            ${valorExistente > 0 ?
                                `<span class="distribuido-linha">
                                    <i class="fas fa-check-circle" style="color: #28a745; margin-right: 5px;"></i>
                                    ${formatarMoeda(valorExistente)}
                                </span>` :
                                ''
                            }
                            <span id="dist_erro_${unidade.id}" class="erro-linha"></span>
                        </div>
                    </div>
                `;
            });

            html += '</div>';

            document.getElementById('distribuicaoUnidadesConteudo').innerHTML = html;

            UNIDADES.forEach(unidade => {
                const valor = distribuicaoModalTelaCheia[unidade.id] || 0;
                atualizarPorcentagemTelaCheia(unidade.id, valor);
            });

            validarDistribuicaoTelaCheia();
        }

        function formatarValorDistribuicaoTelaCheia(input) {
            // Verificar se o crédito está finalizado
            if (dadosClienteModalTelaCheia && dadosClienteModalTelaCheia.situ === 'F') {
                return;
            }

            let valor = input.value.replace(/\D/g, '');
            valor = valor.replace(/^0+/, '');

            const unidadeId = input.dataset.unidade;

            if (valor === '') {
                input.value = '';
                delete distribuicaoModalTelaCheia[unidadeId];
                atualizarPorcentagemTelaCheia(unidadeId, 0);
                validarDistribuicaoTelaCheia();
                return;
            }

            const valorDecimal = parseInt(valor) / 100;

            input.classList.remove('erro');
            document.getElementById(`dist_erro_${unidadeId}`).textContent = '';
            input.value = formatarNumeroBR(valorDecimal);
            distribuicaoModalTelaCheia[unidadeId] = valorDecimal;

            atualizarPorcentagemTelaCheia(unidadeId, distribuicaoModalTelaCheia[unidadeId] || 0);
            validarDistribuicaoTelaCheia();
        }

        function calcularTotalDistribuidoTelaCheia() {
            let total = 0;
            UNIDADES.forEach(unidade => {
                total += distribuicaoModalTelaCheia[unidade.id] || 0;
            });
            return total;
        }

        function atualizarPorcentagemTelaCheia(unidadeId, valor) {
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 1;
            const porcentagemElement = document.getElementById(`dist_porcentagem_${unidadeId}`);
            const porcentagem = (valor / totalAprovado * 100).toFixed(1);
            porcentagemElement.textContent = `${porcentagem}%`;
        }

        function validarDistribuicaoTelaCheia() {
            // Se o crédito estiver finalizado, desabilitar tudo
            if (dadosClienteModalTelaCheia && dadosClienteModalTelaCheia.situ === 'F') {
                const btnSalvar = document.getElementById('btnSalvarDistribuicao');
                if (btnSalvar) {
                    btnSalvar.disabled = true;
                    btnSalvar.style.opacity = '0.5';
                    btnSalvar.style.cursor = 'not-allowed';
                    btnSalvar.title = 'Crédito finalizado - operação não permitida';
                }
                return 0;
            }

            const totalDistribuido = calcularTotalDistribuidoTelaCheia();
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const saldoRestante = totalAprovado - totalDistribuido;

            const btnSalvar = document.getElementById('btnSalvarDistribuicao');
            if (btnSalvar) {
                if (totalDistribuido === 0) {
                    btnSalvar.disabled = true;
                    btnSalvar.title = 'Insira valores para distribuir';
                } else {
                    btnSalvar.disabled = false;
                    if (saldoRestante < 0) {
                        btnSalvar.title = 'Clique para salvar (ATENÇÃO: excede limite!)';
                    } else {
                        btnSalvar.title = 'Clique para salvar distribuição';
                    }
                }
            }

            atualizarLimiteGrandeTempoReal(totalDistribuido);

            return totalDistribuido;
        }

        function atualizarLimiteGrandeTempoReal(totalDistribuido) {
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const totalDistribuidoExistente = parseFloat(dadosClienteModalTelaCheia.total_distribuido) || 0;
            const saldoRestante = totalAprovado - totalDistribuido;

            let cor = '#fdb525';
            let icone = '';
            let alertaExcedente = '';
            let textoStatus = '';

            if (saldoRestante < 0) {
                cor = '#dc3545';
            } else if (saldoRestante === 0) {
                cor = '#28a745';
            } else if (saldoRestante > 0 && saldoRestante < totalAprovado) {
                cor = '#3498db';
            }

            const html = `
                <div class="limite-grande-container">
                    <div class="limite-grande-valor" style="color: ${cor}">
                        ${formatarMoeda(saldoRestante)}
                    </div>
                    ${textoStatus}
                </div>
            `;

            document.getElementById('limiteGrandeConteudo').innerHTML = html;
        }

        function salvarDistribuicaoTelaCheia() {
            // Verificar se o crédito está finalizado
            if (dadosClienteModalTelaCheia && dadosClienteModalTelaCheia.situ === 'F') {
                alert('Este crédito está finalizado. Não é possível salvar distribuições.');
                return;
            }

            const totalDistribuido = calcularTotalDistribuidoTelaCheia();
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const saldoRestante = totalAprovado - totalDistribuido;

            if (totalDistribuido === 0) {
                alert('Insira valores para distribuir!');
                return;
            }

            let mensagemConfirmacao = `Confirmar distribuição de ${formatarMoeda(totalDistribuido)}?`;

            if (saldoRestante < 0) {
                const excedente = Math.abs(saldoRestante);
                mensagemConfirmacao = `⚠️ ATENÇÃO ⚠️\n\nVocê está distribuindo ${formatarMoeda(totalDistribuido)}, que excede o limite aprovado de ${formatarMoeda(totalAprovado)} em ${formatarMoeda(excedente)}.\n\nDeseja realmente continuar?`;
            }

            if (confirm(mensagemConfirmacao)) {
                const distribuicoesParaSalvar = [];
                UNIDADES.forEach(unidade => {
                    const valor = distribuicaoModalTelaCheia[unidade.id] || 0;
                    if (valor > 0) {
                        distribuicoesParaSalvar.push({
                            unidade: unidade.id,
                            nome_unidade: unidade.nome,
                            valor: valor
                        });
                    }
                });

                if (distribuicoesParaSalvar.length === 0) {
                    alert('Nenhuma distribuição para salvar!');
                    return;
                }

                const formData = new FormData();
                formData.append('codigo_cliente', dadosClienteModalTelaCheia.codigo);
                formData.append('razao_social', dadosClienteModalTelaCheia.razao_social);
                formData.append('documento', dadosClienteModalTelaCheia.documento_limpo);
                formData.append('id_neocredit', dadosClienteModalTelaCheia.id_neocredit);
                formData.append('total_distribuido', totalDistribuido);
                formData.append('distribuicoes', JSON.stringify(distribuicoesParaSalvar));

                const btnSalvar = document.getElementById('btnSalvarDistribuicao');
                const originalText = btnSalvar.innerHTML;
                btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                btnSalvar.disabled = true;

                fetch('salvar_distribuicao.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (saldoRestante < 0) {
                                alert('Distribuição salva com sucesso!\n\n⚠️ ATENÇÃO: Foi distribuído ' + formatarMoeda(Math.abs(saldoRestante)) + ' a mais que o limite aprovado.');
                            } else {
                                alert('Distribuição salva com sucesso!');
                            }
                            fecharModalTelaCheia();
                            location.reload();
                        } else {
                            alert('Erro ao salvar: ' + (data.error || 'Erro desconhecido'));
                            btnSalvar.innerHTML = originalText;
                            btnSalvar.disabled = false;
                        }
                    })
                    .catch(error => {
                        alert('Erro na requisição: ' + error);
                        btnSalvar.innerHTML = originalText;
                        btnSalvar.disabled = false;
                    });
            }
        }

        function fecharModalTelaCheia() {
            document.getElementById('modalTelaCheia').style.display = 'none';
            document.body.style.overflow = 'auto';
            dadosClienteModalTelaCheia = null;
            distribuicaoModalTelaCheia = {};
        }

        // ===== FUNÇÕES PARA MODAL DE INFORMAÇÕES =====
        function abrirModalInformacoes(botao) {
            // Verificar se o botão está desabilitado
            if (botao.disabled) {
                alert('Este crédito está finalizado. Apenas visualização disponível.');
            }

            const linha = botao.closest('tr');
            if (!linha) return;

            const dadosCliente = JSON.parse(linha.getAttribute('data-dados'));
            carregarInformacoesModal(dadosCliente);

            document.getElementById('modalInformacoes').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function carregarInformacoesModal(dados) {
            // Formatar dados para exibição
            const documentoClass = dados.documento_limpo.length === 11 ? 'documento-cpf' : 'documento-cnpj';
            const saldoClass = dados.saldo_disponivel <= 0 ? 'saldo-esgotado' :
                dados.saldo_disponivel < dados.lcred_aprovado_numero ? 'limite-restante' : 'saldo-disponivel';

            // Determinar situação
            let situacaoTexto = '';
            let situacaoIcon = '';
            if (dados.situ === 'A') {
                situacaoTexto = 'Aberto';
                situacaoIcon = 'fa-spinner fa-spin';
            } else if (dados.situ === 'F') {
                situacaoTexto = 'Finalizado';
                situacaoIcon = 'fa-check-circle';
            } else {
                situacaoTexto = dados.situ;
                situacaoIcon = 'fa-question-circle';
            }

            // Determinar classe CSS para classificação baseada no valor
            let classificacaoClass = '';
            if (dados.classificacao_risco) {
                const classificacao = dados.classificacao_risco.toLowerCase();
                if (classificacao.includes('alto') || classificacao.includes('a')) {
                    classificacaoClass = 'risco-alto';
                } else if (classificacao.includes('médio') || classificacao.includes('medio') || classificacao.includes('m')) {
                    classificacaoClass = 'risco-medio';
                } else if (classificacao.includes('baixo') || classificacao.includes('b')) {
                    classificacaoClass = 'risco-baixo';
                }
            }

            // Função para formatar codic - APENAS quando for null
            const formatarCodic = (codic) => {
                return codic === null || codic === '' || codic === undefined ? '(-)' : codic;
            };

            // Montar HTML das unidades distribuídas (se houver) com os códigos
            let unidadesHTML = '';
            if (dados.tem_distribuicao && dados.distribuicao_existente && dados.distribuicao_existente.length > 0) {
                // Mapear nomes das unidades para os códigos
                const codicsUnidades = {
                    'barra_mansa': {
                        nome: 'Barra Mansa',
                        codic: dados.codic_bm
                    },
                    'botucatu': {
                        nome: 'Botucatu',
                        codic: dados.codic_bot
                    },
                    'lins': {
                        nome: 'Lins',
                        codic: dados.codic_ls
                    },
                    'rio_preto': {
                        nome: 'Rio Preto',
                        codic: dados.codic_rp
                    },
                    'votuporanga': {
                        nome: 'Votuporanga',
                        codic: dados.codic_vt_rnd
                    }
                };

                unidadesHTML = `
                    <div class="unidades-distribuidas">
                        <h4><i class="fas fa-share-alt"></i> Limite Distribuído por Unidade</h4>
                        ${dados.distribuicao_existente.map(unidade => {
                            const unidadeInfo = codicsUnidades[unidade.unidade] || { nome: unidade.nome_unidade, codic: null };
                            const codic = formatarCodic(unidadeInfo.codic);
                            const codicDisplay = codic === '(-)' ? '(-)' : `(${codic})`;
                            return `
                                <div class="unidade-item">
                                    <div class="unidade-nome">
                                        <i class="fas fa-industry"></i>
                                        ${unidadeInfo.nome} ${codicDisplay}
                                    </div>
                                    <div class="unidade-valor">
                                        R$ ${parseFloat(unidade.valor_distribuido).toLocaleString('pt-BR', {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        })}
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `;
            }

            // Adicionar seção para mostrar todos os códigos das unidades
            const codicsHTML = `
                <div class="campos-duas-colunas" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="info_codic_bm">Código Barra Mansa</label>
                        <input type="text" 
                               id="info_codic_bm" 
                               class="form-control campo-readonly" 
                               value="${formatarCodic(dados.codic_bm)}" 
                               readonly>
                    </div>
                    <div class="form-group">
                        <label for="info_codic_bot">Código Botucatu</label>
                        <input type="text" 
                               id="info_codic_bot" 
                               class="form-control campo-readonly" 
                               value="${formatarCodic(dados.codic_bot)}" 
                               readonly>
                    </div>
                    <div class="form-group">
                        <label for="info_codic_ls">Código Lins</label>
                        <input type="text" 
                               id="info_codic_ls" 
                               class="form-control campo-readonly" 
                               value="${formatarCodic(dados.codic_ls)}" 
                               readonly>
                    </div>
                    <div class="form-group">
                        <label for="info_codic_rp">Código Rio Preto</label>
                        <input type="text" 
                               id="info_codic_rp" 
                               class="form-control campo-readonly" 
                               value="${formatarCodic(dados.codic_rp)}" 
                               readonly>
                    </div>
                    <div class="form-group">
                        <label for="info_codic_vt_rnd">Código Votuporanga</label>
                        <input type="text" 
                               id="info_codic_vt_rnd" 
                               class="form-control campo-readonly" 
                               value="${formatarCodic(dados.codic_vt_rnd)}" 
                               readonly>
                    </div>
                </div>
            `;

            const html = `
                <form id="formInfoCliente">
                    <!-- PRIMEIRA LINHA: Razão Social com largura total -->
                    <div class="campos-duas-colunas-razao">
                        <div class="form-group">
                            <label for="info_razao_social">Razão Social</label>
                            <input type="text" 
                                   id="info_razao_social" 
                                   class="form-control campo-readonly" 
                                   value="${dados.razao_social}" 
                                   readonly>
                        </div>
                    </div>

                    <!-- SEGUNDA LINHA: ID Neocredit, Documento e Validade lado a lado (3 colunas) -->
                    <div class="campos-tres-colunas">
                        <div class="form-group">
                            <label for="info_id_neocredit">ID Neocredit</label>
                            <input type="text" 
                                   id="info_id_neocredit" 
                                   class="form-control campo-readonly" 
                                   value="${dados.id_neocredit || 'N/A'}" 
                                   readonly>
                        </div>
                        <div class="form-group">
                            <label for="info_documento">Documento</label>
                            <input type="text" 
                                   id="info_documento" 
                                   class="form-control campo-readonly ${documentoClass}" 
                                   value="${dados.documento}" 
                                   readonly>
                        </div>
                        <div class="form-group">
                            <label for="info_validade">Validade</label>
                            <input type="text" 
                                   id="info_validade" 
                                   class="form-control campo-readonly" 
                                   value="${dados.validade_cred}" 
                                   readonly>
                        </div>
                    </div>

                    <!-- TERCEIRA LINHA: Risco, Classificação e Status lado a lado (3 colunas) -->
                    <div class="campos-tres-colunas">
                        <div class="form-group">
                            <label for="info_risco">Risco</label>
                            <input type="text" 
                                   id="info_risco" 
                                   class="form-control campo-readonly ${dados.risco_class}" 
                                   value="${dados.risco}" 
                                   readonly>
                        </div>
                        <div class="form-group">
                            <label for="info_classificacao">Classificação</label>
                            <input type="text" 
                                   id="info_classificacao" 
                                   class="form-control campo-readonly ${classificacaoClass}" 
                                   value="${dados.classificacao_risco}" 
                                   readonly>
                        </div>
                        <div class="form-group">
                            <label for="info_status">Status</label>
                            <input type="text" 
                                   id="info_status" 
                                   class="form-control campo-readonly ${dados.status_class}" 
                                   value="${dados.status}" 
                                   readonly>
                        </div>
                    </div>

                    <!-- QUARTA LINHA: Score e Limite Aprovado lado a lado (2 colunas) -->
                    <div class="campos-duas-colunas">
                        <div class="form-group">
                            <label for="info_score">Score</label>
                            <input type="text" 
                                   id="info_score" 
                                   class="form-control campo-readonly" 
                                   value="${dados.score}" 
                                   readonly>
                        </div>
                        <div class="form-group">
                            <label for="info_credito_aprovado">Limite Aprovado</label>
                            <input type="text" 
                                   id="info_credito_aprovado" 
                                   class="form-control campo-readonly valor-monetario-info" 
                                   value="${dados.lcred_aprovado}" 
                                   readonly>
                        </div>
                    </div>
                    
                    ${dados.saldo_disponivel < 0 ? `
                        <div class="alerta-excedente">
                            <i class="fas fa-exclamation-triangle"></i>
                            ATENÇÃO: Limite excedido em ${formatarMoeda(Math.abs(dados.saldo_disponivel))}
                        </div>
                    ` : ''}
                    
                    ${unidadesHTML}
                    ${codicsHTML}
                    
                    <!-- Informação de situação -->
                    <div class="form-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                        <label>Situação do Crédito</label>
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: ${dados.situ === 'F' ? '#d4edda' : '#d1ecf1'}; border-radius: 6px; border: 1px solid ${dados.situ === 'F' ? '#c3e6cb' : '#bee5eb'};">
                            <i class="fas ${dados.situ === 'F' ? 'fa-check-circle' : 'fa-spinner'} ${dados.situ === 'A' ? 'fa-spin' : ''}" 
                               style="color: ${dados.situ === 'F' ? '#28a745' : '#17a2b8'}; font-size: 20px;"></i>
                            <div>
                                <strong style="color: ${dados.situ === 'F' ? '#155724' : '#0c5460'};">
                                    ${dados.situ === 'F' ? 'CRÉDITO FINALIZADO' : 'CRÉDITO EM ABERTO'}
                                </strong>
                                <div style="color: ${dados.situ === 'F' ? '#155724' : '#0c5460'}; font-size: 13px;">
                                    ${dados.situ === 'F' ? 'Este crédito está finalizado. Todas as ações estão desabilitadas.' : 'Este crédito está disponível para distribuição.'}
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            `;

            document.getElementById('modalInfoConteudo').innerHTML = html;
        }

        function fecharModalInformacoes() {
            document.getElementById('modalInformacoes').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // ===== FUNÇÕES UTILITÁRIAS =====
        function formatarMoeda(valor) {
            const num = parseFloat(valor) || 0;
            const sinal = num < 0 ? '-' : '';
            const valorAbsoluto = Math.abs(num);
            const formatado = valorAbsoluto.toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            return `R$ ${sinal}${formatado}`;
        }

        function formatarNumeroBR(valor) {
            const num = parseFloat(valor) || 0;
            return num.toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // ===== EVENT LISTENERS =====
        window.onclick = function(event) {
            const modalTelaCheia = document.getElementById('modalTelaCheia');
            const modalInfo = document.getElementById('modalInformacoes');

            if (event.target === modalTelaCheia) {
                fecharModalTelaCheia();
            }
            if (event.target === modalInfo) {
                fecharModalInformacoes();
            }
        };

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('modalTelaCheia').style.display === 'block') {
                    fecharModalTelaCheia();
                }
                if (document.getElementById('modalInformacoes').style.display === 'block') {
                    fecharModalInformacoes();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            console.log('Sistema de distribuição de crédito carregado.');
            console.log('Total de registros: <?php echo $totalRegistros; ?>');

            // Adicionar ou remover a classe com-botao-limpar baseado no estado do filtro
            const searchContainer = document.querySelector('.search-container');
            const temFiltroAplicado = <?php echo $temFiltroAplicado ? 'true' : 'false'; ?>;

            if (temFiltroAplicado && searchContainer) {
                searchContainer.classList.add('com-botao-limpar');
            }

            // Auto-focus no campo de busca em desktop
            const buscaInput = document.getElementById('busca_geral');
            if (buscaInput && window.innerWidth > 768) {
                setTimeout(() => {
                    buscaInput.focus();
                }, 300);
            }

            // Configurar tecla Enter para enviar o formulário
            if (buscaInput) {
                buscaInput.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        document.querySelector('form').submit();
                    }
                });
            }
        });
    </script>
</body>

</html>