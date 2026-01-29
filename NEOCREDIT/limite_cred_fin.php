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

        @media (max-width: 992px) {
            .search-container {
                flex-wrap: wrap;
            }

            .search-input-group {
                min-width: 100%;
                order: 1;
                margin-bottom: 8px;
            }

            .filtro-data-group {
                flex: 1;
                min-width: 140px;
            }

            .botoes-filtro-container {
                order: 2;
                width: 100%;
                justify-content: center;
                margin-top: 8px;
                display: flex;
                gap: 8px;
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
                min-width: 100px;
            }

            .filtro-data-label {
                font-size: 11px;
                margin-bottom: 2px;
                white-space: nowrap;
            }

            .filtro-data-input {
                min-width: 100%;
                padding: 5px 8px;
                font-size: 12px;
            }

            .btn-buscar,
            .btn-limpar {
                padding: 6px 12px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo NOROAÇO" class="logo-noroaco">

            <form method="POST" action="" class="search-container">
                <!-- <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text"
                        id="busca_geral"
                        name="busca_geral"
                        class="search-input"
                        placeholder="Buscar por código, razão social, documento, risco..."
                        value="<?php echo htmlspecialchars($buscaGeral, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="off">
                </div> -->

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
                <?php if ($temFiltroAplicado): ?>
                    <span style="font-size: 12px; color: #7f8c8d; margin-left: 10px;">
                        (<?php echo $totalRegistros; ?> resultados)
                        <?php if (!empty($filtroStatus) && $filtroStatus !== 'todos'): ?>
                            | Status: <?php echo $filtroStatus === 'A' ? 'Abertos' : 'Finalizados'; ?>
                        <?php endif; ?>
                        <?php if (!empty($buscaGeral)): ?>
                            | Busca: "<?php echo htmlspecialchars($buscaGeral, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
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

                                if ($situacao === 'A') {
                                    $situacaoClass = 'situacao-aberto';
                                    $situacaoIcon = 'fa-spinner';
                                    $situacaoText = '';
                                } elseif ($situacao === 'F') {
                                    $situacaoClass = 'situacao-finalizado';
                                    $situacaoIcon = 'fa-check-circle';
                                    $situacaoText = '';
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
                                $situacaoDisplay = $situacaoText;

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
                                    $situacaoStr = strtolower($situacaoText);

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
                                        $situacaoDisplay = preg_replace("/(" . preg_quote($buscaGeral, '/') . ")/i", '<span class="destaque-busca">$1</span>', $situacaoText);
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
                                    'situacao_text' => $situacaoText
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
                                            <span class="situacao-texto situacao-aberto"><?php echo $situacaoDisplay; ?></span>
                                        <?php elseif ($situacao === 'F'): ?>
                                            <i class="fas fa-check-circle situacao-check" title="Finalizado"></i>
                                            <span class="situacao-texto situacao-finalizado"><?php echo $situacaoDisplay; ?></span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($credito['situ']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <button class="btn-acao btn-finalizar"
                                                onclick="abrirModalTelaCheia(this)"
                                                title="Distribuir limite por unidade">
                                                <i class="fas fa-check"></i>
                                            </button>
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
            document.getElementById('busca_geral').value = '';
            document.getElementById('filtro_status').value = 'todos';
            document.querySelector('form').submit();
        }

        function abrirModalTelaCheia(botao) {
            const linha = botao.closest('tr');
            if (!linha) return;

            dadosClienteModalTelaCheia = JSON.parse(linha.getAttribute('data-dados'));
            distribuicaoModalTelaCheia = {};

            carregarInformacoesEmpresa();
            carregarAnaliseCredito();
            carregarLimiteGrande();
            carregarDistribuicaoUnidades();

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
            const saldoDisponivel = parseFloat(dadosClienteModalTelaCheia.saldo_disponivel) || 0;
            const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
            const totalDistribuidoExistente = parseFloat(dadosClienteModalTelaCheia.total_distribuido) || 0;

            const distribuicaoExistente = {};
            if (dadosClienteModalTelaCheia.tem_distribuicao && dadosClienteModalTelaCheia.distribuicao_existente) {
                dadosClienteModalTelaCheia.distribuicao_existente.forEach(dist => {
                    distribuicaoExistente[dist.unidade] = parseFloat(dist.valor_distribuido);
                });
            }

            let html = '<div class="distribuicao-linha-horizontal">';

            UNIDADES.forEach(unidade => {
                const valorExistente = distribuicaoExistente[unidade.id] || 0;
                const valorInicial = valorExistente > 0 ? formatarNumeroBR(valorExistente) : '';

                if (valorExistente > 0) {
                    distribuicaoModalTelaCheia[unidade.id] = valorExistente;
                }

                html += `
                    <div class="item-unidade-linha">
                        <div class="nome-unidade-linha">
                            <i class="${unidade.icone}"></i>
                            ${unidade.nome}
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
                                   onkeyup="validarDistribuicaoTelaCheia()">
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

        window.onclick = function(event) {
            const modalTelaCheia = document.getElementById('modalTelaCheia');
            if (event.target === modalTelaCheia) {
                fecharModalTelaCheia();
            }
        };

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('modalTelaCheia').style.display === 'block') {
                    fecharModalTelaCheia();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            console.log('Sistema de distribuição de crédito carregado.');
            console.log('Total de registros: <?php echo $totalRegistros; ?>');

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