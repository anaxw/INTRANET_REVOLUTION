<?php
// Configuração do banco de dados
$host = '192.168.1.209';
$database = 'c:/BD/ARQSIST.FDB';
$username = 'SYSDBA';
$password = 'masterkey';
$charset = 'UTF8';

// Configuração da conexão PDO para Firebird
$dsn = "firebird:dbname={$host}:{$database};charset={$charset}";

try {
    // Criar conexão PDO
    $pdo = new PDO($dsn, $username, $password);

    // Configurar atributos do PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // SQL Query
    $sql = "
    SELECT
        op.desc_produto_item AS Nome,
        CASE
        -- Prioridade 1: Extração de ob.obs1
        WHEN ob.obs1 IS NOT NULL 
             AND position('COMP.:' IN ob.obs1) > 0 
             AND position('MM' IN ob.obs1) > position('COMP.:' IN ob.obs1) THEN
            CASE
                -- Valida se o trecho extraído é numérico
                WHEN trim(substring(
                        ob.obs1 
                        FROM position('COMP.:' IN ob.obs1) + 6 
                        FOR position('MM' IN ob.obs1) - position('COMP.:' IN ob.obs1) - 6
                     )) SIMILAR TO '[0-9]+([.][0-9]+)?' THEN
                    CAST(
                        trim(substring(
                            ob.obs1 
                            FROM position('COMP.:' IN ob.obs1) + 6 
                            FOR position('MM' IN ob.obs1) - position('COMP.:' IN ob.obs1) - 6
                        )) AS NUMERIC(12,2)
                    )
                ELSE NULL
            END
        -- Prioridade 2: Extração de op.desc_produto
        WHEN position('MM' IN op.desc_produto) >= 4 THEN
            CASE
                -- Valida se o trecho extraído é numérico
                WHEN trim(substring(op.desc_produto 
                              FROM position('MM' IN op.desc_produto) - 4 FOR 4)) SIMILAR TO '[0-9]+([.][0-9]+)?' THEN
                    CAST(
                        trim(substring(op.desc_produto 
                                      FROM position('MM' IN op.desc_produto) - 4 FOR 4
                        )) AS NUMERIC(12,2)
                    )
                ELSE NULL
            END
        ELSE
            NULL
        END AS Comprimento,
        CASE 
        WHEN (op.plano_corte <> '') THEN CAST(op.plano_corte AS NUMERIC(12,2)) 
        ELSE
            CASE
                WHEN position('CORTE:' in ob.obs1) > 0 THEN
                    CASE
                        -- Extraímos o texto após 'CORTE:' e garantimos que é numérico (incluindo vírgulas como decimais)
                        WHEN replace(trim(substring(ob.obs1 FROM position('CORTE:' in ob.obs1) + 7 FOR 15)), ',', '.') SIMILAR TO '[0-9]+([.][0-9]+)?' THEN
                            CAST(replace(trim(substring(ob.obs1 FROM position('CORTE:' in ob.obs1) + 7 FOR 15)), ',', '.') AS NUMERIC(12,2))
                        ELSE 0
                    END
                ELSE 0
            END
        END AS Largura,
        CASE
            WHEN trim(op.desc_modelagem) SIMILAR TO '[0-9]+([.][0-9]+)?' 
            THEN CAST(op.desc_modelagem AS NUMERIC(12,2)) 
            ELSE NULL 
        END AS Espessura,
        CASE
        WHEN position('QTDE:' in ob.obs1) > 0 AND position('PÇ' in ob.obs1) > position('QTDE:' in ob.obs1) THEN
            CASE 
                WHEN trim(substring(
                    ob.obs1 FROM position('QTDE:' in ob.obs1) + 5 FOR
                    position('PÇ' in ob.obs1) - position('QTDE:' in ob.obs1) - 5
                )) SIMILAR TO '[0-9]+' THEN
                    CAST(
                        trim(substring(
                            ob.obs1 FROM position('QTDE:' in ob.obs1) + 5 FOR
                            position('PÇ' in ob.obs1) - position('QTDE:' in ob.obs1) - 5
                        )) AS INTEGER
                    )
                ELSE NULL
            END
        ELSE
            CAST(op.Qtde_Pc_Pedido AS INTEGER)
        END AS Quantidade,
        op.seqop,
        ob.obs1,
        g.grupo,
        sgr.grupo AS subgrupo,
        CASE
            WHEN d.nome = 'PRODUCAO INTERNA' THEN 'PRODUCAO INTERNA'
            ELSE 'PEDIDO'
        END AS Tipo_Pedido,
        CASE
            WHEN sgr.grupo LIKE '%PERFIL%' THEN 'PERFIL'
            WHEN sgr.grupo LIKE '%CHAPA%' THEN 'CHAPA'
            ELSE 'OUTROS'
        END AS Tipo_Produto,
        op.dt_term_producao,
        CASE
            WHEN op.dt_term_producao IS NOT NULL THEN 'Sim'
            ELSE 'Nao'
        END AS Data_termino,
        CASE 
            WHEN ob.obs1 IS NULL THEN 'CHAPA PRODUCAO'
            WHEN ob.obs1 LIKE '%A-36%' THEN 'ASTM A36'
            WHEN ob.obs1 LIKE '%CIVIL-300%' THEN 'CIVIL 300'
            WHEN ob.obs1 LIKE '%SAE%' THEN 'SAE 1020'
            WHEN ob.obs1 LIKE '%CHAPA PROD%' THEN 'CHAPA PRODUCAO'
            ELSE NULL
        END AS Tipo_Aco,
        o.codacessog as Cod_Produto,
        RIGHT('0' || EXTRACT(DAY FROM op.dev_data), 2) || '-' ||
        RIGHT('0' || EXTRACT(MONTH FROM op.dev_data), 2) || '-' ||
        RIGHT(EXTRACT(YEAR FROM op.dev_data), 2) || ' ' || op.dev_seq as Lote,
        cad.nome as Cliente,
        case
            when e.seqende is null then cid2.nome
            else cid.nome
        end as Cidade,
        case
            when e.seqende is null then cid2.estado
            else cid.estado
        end as Estado
    FROM SP_Op_Lancamento(0, 0, '0', current_date-180, current_date, 'NM', '') op
    INNER JOIN arqes15 ped ON ped.pedido = op.pedido AND ped.item = op.item
    INNER JOIN arqes13 p ON p.pedido = ped.pedido
    LEFT OUTER JOIN vd_obs ob ON ob.codobs = ped.pedido AND ob.tipoc = 'I' AND ob.tipo = 'J' AND ob.codic = ped.item
    INNER JOIN arqes01 o ON ped.produto = o.codigo
    INNER JOIN arqes02 g ON o.grupo = g.codigo
    INNER JOIN arqes02 sgr ON sgr.codigo = o.subg
    INNER JOIN arqfs02 d ON d.codigo = p.dispo
    inner join arqcad cad on cad.codic = p.codic and cad.tipoc = 'C'
    left join arqcadend e on e.seqende = p.seqendentrega and e.tipoc = 'C'
    left join cidades cid on cid.seqcidade = e.seqcidade
    inner join cidades cid2 on cid2.seqcidade = cad.seqcidade
    WHERE op.Sit_Ord_Prod IN ('2', '9')
    AND NOT op.desc_produto LIKE ('%PAINEL%')
    AND op.cod_grupo IN (544, 16, 3, 271, 107, 6, 272, 12, 13, 106, 418, 428, 15)
    AND op.Cod_empresa = '1'
    AND op.sit_op IN ('A', 'I')
    ORDER BY op.dt_term_producao DESC
    ";

    // Executar a consulta
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll();

    // Função para limpar e converter caracteres especiais corretamente
    function limparTexto($texto)
    {
        if ($texto === null || $texto === '') {
            return '';
        }

        // Se o texto já estiver em UTF-8, mantém, senão converte
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        // Remove caracteres de controle, mas mantém acentos e caracteres especiais
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);

        // Retorna o texto limpo
        return $texto;
    }

    // Aplicar a limpeza em todos os campos de texto
    foreach ($results as &$row) {

        // Garantir que SEQOP seja inteiro
        $row['SEQOP'] = (int)$row['SEQOP'];

        // Limpeza dos campos de texto (com proteção caso venha null)
        $row['NOME'] = limparTexto($row['NOME'] ?? '');
        $row['OBS1'] = limparTexto($row['OBS1'] ?? '');
        $row['GRUPO'] = limparTexto($row['GRUPO'] ?? '');
        $row['SUBGRUPO'] = limparTexto($row['SUBGRUPO'] ?? '');
        $row['TIPO_PEDIDO'] = limparTexto($row['TIPO_PEDIDO'] ?? '');
        $row['TIPO_PRODUTO'] = limparTexto($row['TIPO_PRODUTO'] ?? '');
        $row['TIPO_ACO'] = limparTexto($row['TIPO_ACO'] ?? '');
        $row['LOTE'] = limparTexto($row['LOTE'] ?? '');
        $row['CLIENTE'] = limparTexto($row['CLIENTE'] ?? '');
        $row['CIDADE'] = limparTexto($row['CIDADE'] ?? '');
        $row['ESTADO'] = limparTexto($row['ESTADO'] ?? '');

        // Criar a coluna concatenada conforme a condição
        if (empty($row['OBS1'])) {
            $row['CONCATENADO'] = $row['SEQOP'] . ' - ' . $row['NOME'];
        } else {
            $row['CONCATENADO'] = $row['SEQOP'] . ' - ' . $row['OBS1'];
        }
    }
} catch (PDOException $e) {
    die("Erro na conexão ou consulta: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Produção</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Roboto', 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            padding: 25px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        h1::before {
            content: "📊";
            font-size: 32px;
        }

        .info {
            padding: 15px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info::before {
            content: "📅";
            font-size: 18px;
        }

        /* Seção de filtro melhorada */
        .filter-section {
            padding: 20px 30px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-bottom: 1px solid #e9ecef;
        }

        .filter-container {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .filter-input-group {
            flex: 2;
            min-width: 350px;
        }

        .filter-input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background-color: white;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .tags-container {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            min-height: 40px;
        }

        .tag {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            animation: tagAppear 0.2s ease-out;
        }

        @keyframes tagAppear {
            from {
                opacity: 0;
                transform: scale(0.8);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .tag:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .tag-remove {
            cursor: pointer;
            font-weight: bold;
            font-size: 18px;
            line-height: 1;
            padding: 0 3px;
            transition: all 0.2s ease;
        }

        .tag-remove:hover {
            color: #ff6b6b;
            transform: scale(1.1);
        }

        .clear-filters {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .clear-filters:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .filter-stats {
            margin-top: 10px;
            font-size: 13px;
            color: #6c757d;
            font-weight: 500;
            padding: 8px 12px;
            background-color: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #667eea;
        }

        /* Tabela melhorada */
        .scroll-wrapper {
            overflow-x: auto;
            max-height: 70vh;
            background-color: white;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }

        th {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #4a627a;
        }

        th:first-child {
            border-top-left-radius: 8px;
        }

        th:last-child {
            border-top-right-radius: 8px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }

        tr:hover td {
            background-color: #f8f9fa;
            transform: scale(1.002);
        }

        /* Cores alternadas para melhor visualização */
        tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }

        .numerico {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 500;
            color: #2c3e50;
        }

        .status-sim {
            color: #27ae60;
            font-weight: bold;
            background-color: #d5f4e6;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            font-size: 11px;
        }

        .status-nao {
            color: #e74c3c;
            font-weight: bold;
            background-color: #fee2e2;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            font-size: 11px;
        }

        .obs-cell {
            max-width: 300px;
            white-space: normal;
            word-wrap: break-word;
            font-size: 12px;
            color: #495057;
            line-height: 1.4;
        }

        .concatenado-cell {
            max-width: 400px;
            white-space: normal;
            word-wrap: break-word;
            font-weight: 600;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 3px solid #667eea;
            font-size: 12px;
        }

        /* Badges para tipos de produto */
        td:nth-child(11) {
            font-weight: 500;
        }

        /* Estilização específica para cada tipo de produto */
        td:nth-child(11):contains("PERFIL") {
            color: #3498db;
        }

        td:nth-child(11):contains("CHAPA") {
            color: #e74c3c;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            table {
                font-size: 11px;
            }

            th,
            td {
                padding: 8px 6px;
            }

            .obs-cell {
                max-width: 150px;
            }

            .concatenado-cell {
                max-width: 200px;
            }

            h1 {
                font-size: 20px;
                padding: 15px 20px;
            }

            .filter-section {
                padding: 15px 20px;
            }

            .filter-input-group {
                min-width: 250px;
            }
        }

        .total-registros {
            padding: 15px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 2px solid #dee2e6;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .total-registros::before {
            content: "📈";
            font-size: 18px;
        }

        .no-results {
            padding: 60px;
            text-align: center;
            color: #6c757d;
            font-size: 16px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            margin: 20px;
        }

        /* Tooltip melhorado */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            background-color: #2c3e50;
            color: white;
            font-size: 11px;
            border-radius: 4px;
            white-space: nowrap;
            z-index: 20;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
        }

        /* Scrollbar personalizada */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #764ba2;
        }

        /* Indicadores visuais para células importantes */
        .importante {
            position: relative;
        }

        .importante::after {
            content: "⭐";
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 8px;
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- Seção de filtro múltiplo -->
        <div class="filter-section">
            <div class="filter-container">
                <div class="filter-input-group">
                    <label for="opFilter">🔍 Filtrar por OP (Seq. OP):</label>
                    <input type="text" id="opFilter" class="filter-input"
                        placeholder="Digite o número da OP e pressione Enter...">
                    <div class="tags-container" id="tagsContainer"></div>
                    <div class="filter-stats" id="filterStats"></div>
                </div>
                <button class="clear-filters" id="clearFilters">Limpar Filtros</button>
            </div>
        </div>

        <div class="scroll-wrapper">
            <div id="tableContainer">
                <?php if (count($results) > 0): ?>
                    <table id="dataTable">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Comprimento</th>
                                <th>Largura</th>
                                <th>Espessura</th>
                                <th>Quantidade</th>
                                <th>Seq. OP</th>
                                <th>Obs1</th>
                                <th>Grupo</th>
                                <th>Subgrupo</th>
                                <th>Tipo Pedido</th>
                                <th>Tipo Produto</th>
                                <th>Data Término</th>
                                <th>Data Término?</th>
                                <th>Tipo Aço</th>
                                <th>Cód. Produto</th>
                                <th>Lote</th>
                                <th>Cliente</th>
                                <th>Cidade</th>
                                <th>Estado</th>
                                <th>Descrição OP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr data-seqop="<?php echo $row['SEQOP']; ?>">
                                    <td><?php echo htmlspecialchars($row['NOME'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="numerico"><?php echo $row['COMPRIMENTO'] !== null ? number_format($row['COMPRIMENTO'], 0, '', '') : '-'; ?></td>
                                    <td class="numerico"><?php echo $row['LARGURA'] !== null ? number_format($row['LARGURA'], 0, '', '.') : '-'; ?></td>
                                    <td class="numerico"><?php echo $row['ESPESSURA'] !== null ? number_format($row['ESPESSURA'], 2, ',', '.') : '-'; ?></td>
                                    <td class="numerico"><?php echo number_format($row['QUANTIDADE'], 0, '', '.'); ?></td>
                                    <td class="numerico"><?php echo number_format($row['SEQOP'], 0, '', ''); ?></td>
                                    <td class="obs-cell"><?php echo htmlspecialchars($row['OBS1'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['GRUPO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SUBGRUPO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['TIPO_PEDIDO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['TIPO_PRODUTO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $row['DT_TERM_PRODUCAO'] ? date('d/m/Y', strtotime($row['DT_TERM_PRODUCAO'])) : '-'; ?></td>
                                    <td class="<?php echo $row['DATA_TERMINO'] == 'Sim' ? 'status-sim' : 'status-nao'; ?>">
                                        <?php echo $row['DATA_TERMINO'] ?? '-'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['TIPO_ACO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="numerico"><?php echo number_format($row['COD_PRODUTO'], 0, '', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['LOTE'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['CLIENTE'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['CIDADE'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['ESTADO'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="concatenado-cell"><?php echo htmlspecialchars($row['CONCATENADO'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-results">
                        Nenhum registro encontrado para os critérios selecionados.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        // Armazenar os filtros de OP
        let filters = [];

        // Elementos do DOM
        const opFilterInput = document.getElementById('opFilter');
        const tagsContainer = document.getElementById('tagsContainer');
        const clearFiltersBtn = document.getElementById('clearFilters');
        const filterStats = document.getElementById('filterStats');
        const totalRegistrosSpan = document.getElementById('totalRegistros');

        // Função para atualizar a tabela com base nos filtros
        function updateTable() {
            const rows = document.querySelectorAll('#dataTable tbody tr');
            let visibleCount = 0;

            // Se não houver filtros, mostrar todas as linhas
            if (filters.length === 0) {
                rows.forEach(row => {
                    row.style.display = '';
                    visibleCount++;
                });
            } else {
                // Aplicar filtros
                rows.forEach(row => {
                    const seqop = row.getAttribute('data-seqop');
                    const shouldShow = filters.some(filter => filter.toString() === seqop);
                    if (shouldShow) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            // Atualizar contador
            totalRegistrosSpan.innerHTML = `Total de registros: ${visibleCount} (Filtrados de ${rows.length} totais)`;

            // Atualizar estatísticas do filtro
            if (filters.length > 0) {
                filterStats.innerHTML = `📊 ${filters.length} OP(s) selecionada(s) | ${visibleCount} registro(s) encontrado(s)`;
            } else {
                filterStats.innerHTML = `📊 Nenhum filtro aplicado. Mostrando todos os ${rows.length} registros.`;
            }
        }

        // Função para adicionar um novo filtro
        function addFilter(value) {
            // Validar se é um número
            const numValue = parseInt(value);
            if (isNaN(numValue)) {
                alert('Por favor, digite apenas números para a OP.');
                return false;
            }

            // Verificar se já existe
            if (filters.includes(numValue)) {
                alert(`A OP ${numValue} já está na lista de filtros.`);
                return false;
            }

            // Adicionar o filtro
            filters.push(numValue);

            // Criar a tag visual
            createTag(numValue);

            // Limpar o input
            opFilterInput.value = '';

            // Atualizar a tabela
            updateTable();

            return true;
        }

        // Função para criar uma tag visual
        function createTag(value) {
            const tag = document.createElement('div');
            tag.className = 'tag';
            tag.innerHTML = `
                OP ${value}
                <span class="tag-remove" data-value="${value}">×</span>
            `;

            // Adicionar evento de remoção
            const removeSpan = tag.querySelector('.tag-remove');
            removeSpan.addEventListener('click', (e) => {
                e.stopPropagation();
                removeFilter(value);
            });

            tagsContainer.appendChild(tag);
        }

        // Função para remover um filtro
        function removeFilter(value) {
            // Remover do array
            const index = filters.indexOf(value);
            if (index > -1) {
                filters.splice(index, 1);
            }

            // Remover a tag visual
            const tags = document.querySelectorAll('.tag');
            tags.forEach(tag => {
                const removeSpan = tag.querySelector('.tag-remove');
                if (removeSpan && removeSpan.getAttribute('data-value') == value) {
                    tag.remove();
                }
            });

            // Atualizar a tabela
            updateTable();
        }

        // Função para limpar todos os filtros
        function clearFilters() {
            filters = [];
            // Remover todas as tags
            tagsContainer.innerHTML = '';
            // Atualizar a tabela
            updateTable();
            // Limpar o input
            opFilterInput.value = '';
        }

        // Evento para adicionar filtro ao pressionar Enter
        opFilterInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const value = opFilterInput.value.trim();
                if (value) {
                    addFilter(value);
                }
            }
        });

        // Evento para o botão de limpar filtros
        clearFiltersBtn.addEventListener('click', clearFilters);

        // Inicializar a tabela
        updateTable();

        // Função para verificar se existe a tabela
        if (document.querySelector('#dataTable tbody tr') === null) {
            // Se não há dados, desabilitar o filtro
            opFilterInput.disabled = true;
            opFilterInput.placeholder = "Nenhum dado disponível para filtrar";
            clearFiltersBtn.disabled = true;
        }
    </script>
</body>

</html>