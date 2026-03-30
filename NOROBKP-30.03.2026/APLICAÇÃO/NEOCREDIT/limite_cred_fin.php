<?php
session_start();

$usuario = 'MARCUS';

// ========== DECLARAÇÃO DAS VARIÁVEIS DE FILTRO ==========
$buscaGeral = isset($_POST['busca_geral']) ? trim($_POST['busca_geral']) : '';
$filtroStatus = isset($_POST['filtro_status']) ? trim($_POST['filtro_status']) : '';
// ========================================================

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

// Função para extrair apenas o número do CODIC
function extrairNumeroCodic($codic)
{
    if ($codic === null || $codic === '' || $codic === '(-)') {
        return null;
    }

    if (is_numeric($codic)) {
        return (string)$codic;
    }

    if (preg_match('/\((\d+)\)/', $codic, $matches)) {
        return $matches[1];
    }

    if (preg_match('/(\d+)/', $codic, $matches)) {
        return $matches[1];
    }

    return null;
}

try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ========== CONSTRUÇÃO DA QUERY COM BUSCA EM TODAS AS COLUNAS ==========
    // CORREÇÃO DEFINITIVA: CONVERTER ABSOLUTAMENTE TUDO PARA TEXTO
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
        ln.codic_vt_rnd
    FROM lcred_neocredit ln
    WHERE 1=1";

    $params = [];

    // APLICAR FILTRO DE BUSCA GERAL - TODAS AS COLUNAS CONVERTIDAS PARA TEXTO
    if (!empty($buscaGeral)) {
        // Remove caracteres não numéricos do termo de busca para comparar com documento
        $buscaNumeros = preg_replace('/[^0-9]/', '', $buscaGeral);

        // Se o termo de busca conter apenas números E tiver 11 ou 14 dígitos (CPF/CNPJ)
        if (strlen($buscaNumeros) >= 11 && strlen($buscaNumeros) <= 14 && $buscaNumeros === preg_replace('/[^0-9]/', '', $buscaGeral)) {
            // Busca ESPECÍFICA para documento - compara o campo sem formatação com o termo limpo
            $sql .= " AND (
            CAST(ln.codigo AS TEXT) ILIKE ? OR 
            CAST(ln.id_neocredit AS TEXT) ILIKE ? OR 
            CAST(ln.razao_social AS TEXT) ILIKE ? OR
            REPLACE(REPLACE(REPLACE(REPLACE(CAST(ln.documento AS TEXT), '.', ''), '/', ''), '-', ''), ' ', '') ILIKE ? OR
            CAST(ln.risco AS TEXT) ILIKE ? OR
            CAST(ln.classificacao_risco AS TEXT) ILIKE ? OR
            CAST(ln.lcred_aprovado AS TEXT) ILIKE ? OR
            CAST(ln.validade_cred AS TEXT) ILIKE ? OR
            CAST(ln.status AS TEXT) ILIKE ? OR
            CAST(ln.score AS TEXT) ILIKE ? OR
            CAST(ln.situ AS TEXT) ILIKE ? OR
            CAST(ln.codic_bm AS TEXT) ILIKE ? OR
            CAST(ln.codic_bot AS TEXT) ILIKE ? OR
            CAST(ln.codic_ls AS TEXT) ILIKE ? OR
            CAST(ln.codic_rp AS TEXT) ILIKE ? OR
            CAST(ln.codic_vt_rnd AS TEXT) ILIKE ?
        )";

            $termoBusca = "%{$buscaGeral}%";
            $termoBuscaNumeros = "%{$buscaNumeros}%";

            // Adiciona os parâmetros na ordem correta
            $params[] = $termoBusca; // codigo
            $params[] = $termoBusca; // id_neocredit
            $params[] = $termoBusca; // razao_social
            $params[] = $termoBuscaNumeros; // documento (comparação com números puros)
            $params[] = $termoBusca; // risco
            $params[] = $termoBusca; // classificacao_risco
            $params[] = $termoBusca; // lcred_aprovado
            $params[] = $termoBusca; // validade_cred
            $params[] = $termoBusca; // status
            $params[] = $termoBusca; // score
            $params[] = $termoBusca; // situ
            $params[] = $termoBusca; // codic_bm
            $params[] = $termoBusca; // codic_bot
            $params[] = $termoBusca; // codic_ls
            $params[] = $termoBusca; // codic_rp
            $params[] = $termoBusca; // codic_vt_rnd
        } else {
            // Busca NORMAL para outros termos
            $sql .= " AND (
            CAST(ln.codigo AS TEXT) ILIKE ? OR 
            CAST(ln.id_neocredit AS TEXT) ILIKE ? OR 
            CAST(ln.razao_social AS TEXT) ILIKE ? OR
            CAST(ln.documento AS TEXT) ILIKE ? OR
            CAST(ln.risco AS TEXT) ILIKE ? OR
            CAST(ln.classificacao_risco AS TEXT) ILIKE ? OR
            CAST(ln.lcred_aprovado AS TEXT) ILIKE ? OR
            CAST(ln.validade_cred AS TEXT) ILIKE ? OR
            CAST(ln.status AS TEXT) ILIKE ? OR
            CAST(ln.score AS TEXT) ILIKE ? OR
            CAST(ln.situ AS TEXT) ILIKE ? OR
            CAST(ln.codic_bm AS TEXT) ILIKE ? OR
            CAST(ln.codic_bot AS TEXT) ILIKE ? OR
            CAST(ln.codic_ls AS TEXT) ILIKE ? OR
            CAST(ln.codic_rp AS TEXT) ILIKE ? OR
            CAST(ln.codic_vt_rnd AS TEXT) ILIKE ?
        )";

            $termoBusca = "%{$buscaGeral}%";

            // Adiciona 16 parâmetros (um para cada coluna)
            for ($i = 0; $i < 16; $i++) {
                $params[] = $termoBusca;
            }
        }
    }

    // APLICAR FILTRO DE STATUS (SITUAÇÃO)
    if (!empty($filtroStatus) && $filtroStatus !== 'todos') {
        $sql .= " AND CAST(ln.situ AS TEXT) = ?";
        $params[] = $filtroStatus;
    }

    $sql .= " ORDER BY ln.codigo DESC";
    // ======================================================================

    $stmt = $pdo->prepare($sql);

    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }

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
    <title>NOROAÇO - Gerenciador de Crédito Neocredit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="lcred_fin.css">
    <style>

    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo NOROAÇO" class="logo-noroaco">

            <form method="POST" action="" class="search-container <?php echo $temFiltroAplicado ? 'com-botao-limpar' : ''; ?>">

                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text"
                        id="busca_geral"
                        name="busca_geral"
                        class="search-input"
                        placeholder="Buscar em todas as colunas..."
                        value="<?php echo htmlspecialchars($buscaGeral, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="off">
                </div>

                <div class="filtro-data-group">
                    <label for="filtro_status" class="filtro-data-label">Situação:</label>
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
            <div class="titulo-tabela" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: white; border-radius: 8px; margin-bottom: 15px;">
                <div>
                    <i class="fa-solid fa-coins" style="color: #fdb525; margin-right: 10px;"></i>
                    Gerenciador de Crédito - Neocredit (Aprovação)
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alerta mensagem-erro" style="margin: 15px 20px; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; color: #721c24;">
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
                                <td colspan="12" style="border: none; padding: 0;">
                                    <div class="lista-vazia">
                                        <div class="lista-vazia-icon">
                                            <i class="fas fa-search" style="font-size: 48px;"></i>
                                        </div>
                                        <h3 style="margin-bottom: 10px;">Nenhum crédito encontrado</h3>
                                        <?php if ($temFiltroAplicado): ?>
                                            <p style="margin-bottom: 20px; color: #6c757d;">
                                                Não há registros com os filtros aplicados<br>
                                                <strong>Busca:</strong> "<?php echo htmlspecialchars($buscaGeral); ?>"<br>
                                                <?php if ($filtroStatus !== 'todos'): ?>
                                                    <strong>Situação:</strong> <?php echo $filtroStatus === 'A' ? 'Abertos' : 'Finalizados'; ?>
                                                <?php endif; ?>
                                            </p>
                                            <button class="btn-limpar" onclick="limparFiltros()" style="margin-top: 10px; display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;">
                                                <i class="fas fa-times"></i> Limpar filtros
                                            </button>
                                        <?php else: ?>
                                            <p style="color: #6c757d;">Não há registros de crédito na base de dados</p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($creditos as $credito): ?>
                                <?php
                                // Buscar distribuição existente
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
                                    // Silenciosamente ignorar erro
                                }

                                $temDistribuicao = !empty($distribuicaoExistente);

                                // Datas
                                $validadeFormatada = $credito['validade_cred']
                                    ? date('d/m/Y', strtotime($credito['validade_cred']))
                                    : 'N/A';

                                // Valores
                                $creditoFormatado = is_numeric($credito['lcred_aprovado'])
                                    ? 'R$ ' . number_format($credito['lcred_aprovado'], 2, ',', '.')
                                    : $credito['lcred_aprovado'];

                                $saldoFormatado = isset($credito['saldo_disponivel']) && is_numeric($credito['saldo_disponivel'])
                                    ? 'R$ ' . number_format($credito['saldo_disponivel'], 2, ',', '.')
                                    : 'R$ 0,00';

                                // Documento
                                $documentoFormatado = formatarDocumento($credito['documento']);
                                $documentoLimpo = preg_replace('/[^0-9]/', '', $credito['documento']);

                                // Situação
                                $situacao = strtoupper($credito['situ'] ?? 'A');

                                // CODICs - extrair números
                                $codic_bm = extrairNumeroCodic($credito['codic_bm'] ?? null);
                                $codic_bot = extrairNumeroCodic($credito['codic_bot'] ?? null);
                                $codic_ls = extrairNumeroCodic($credito['codic_ls'] ?? null);
                                $codic_rp = extrairNumeroCodic($credito['codic_rp'] ?? null);
                                $codic_vt_rnd = extrairNumeroCodic($credito['codic_vt_rnd'] ?? null);

                                $dadosModal = htmlspecialchars(json_encode([
                                    'codigo' => $credito['codigo'],
                                    'id_neocredit' => $credito['id_neocredit'],
                                    'razao_social' => $credito['razao_social'],
                                    'documento' => $documentoFormatado,
                                    'documento_limpo' => $documentoLimpo,
                                    'lcred_aprovado' => $creditoFormatado,
                                    'lcred_aprovado_numero' => $credito['lcred_aprovado'] ?? 0,
                                    'saldo_formatado' => $saldoFormatado,
                                    'saldo_disponivel' => $credito['saldo_disponivel'] ?? 0,
                                    'validade_cred' => $validadeFormatada,
                                    'status' => $credito['status'] ?? 'N/A',
                                    'score' => $credito['score'] ?? '0',
                                    'situ' => $credito['situ'] ?? 'A',
                                    'risco' => $credito['risco'] ?? 'N/A',
                                    'classificacao_risco' => $credito['classificacao_risco'] ?? 'N/A',
                                    'tem_distribuicao' => $temDistribuicao,
                                    'distribuicao_existente' => $distribuicaoExistente,
                                    'total_distribuido' => $credito['total_distribuido'] ?? 0,
                                    'codic_bm' => $codic_bm,
                                    'codic_bot' => $codic_bot,
                                    'codic_ls' => $codic_ls,
                                    'codic_rp' => $codic_rp,
                                    'codic_vt_rnd' => $codic_vt_rnd
                                ]), ENT_QUOTES, 'UTF-8');
                                ?>

                                <tr data-dados='<?php echo $dadosModal; ?>' id="cliente-<?php echo $credito['codigo']; ?>">
                                    <td><?php echo $credito['codigo']; ?></td>
                                    <td><?php echo htmlspecialchars($credito['id_neocredit'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($credito['razao_social'] ?? ''); ?></td>
                                    <td><?php echo $documentoFormatado; ?></td>
                                    <td><?php echo htmlspecialchars($credito['risco'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($credito['classificacao_risco'] ?? ''); ?></td>
                                    <td class="valor-monetario"><?php echo $creditoFormatado; ?></td>
                                    <td><?php echo $validadeFormatada; ?></td>
                                    <td><?php echo htmlspecialchars($credito['status'] ?? ''); ?></td>
                                    <td><?php echo $credito['score'] ?? '0'; ?></td>
                                    <td class="situacao-col">
                                        <?php if ($situacao === 'A'): ?>
                                            <span style="display: flex; align-items: center; gap: 5px;">
                                                <i class="fas fa-spinner fa-spin" style="color: #fdb525;"></i>

                                            </span>
                                        <?php elseif ($situacao === 'F'): ?>
                                            <span style="display: flex; align-items: center; gap: 5px;">
                                                <i class="fas fa-check-circle" style="color: #28a745;"></i>

                                            </span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($credito['situ'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <?php if ($situacao === 'A'): ?>
                                                <button class="btn-acao btn-distribuir"
                                                    onclick="abrirModalTelaCheia(this)"
                                                    title="Distribuir limite por unidade">
                                                    <i class="fas fa-share-alt"></i>
                                                </button>

                                                <button class="btn-acao btn-finalizar-sem-distribuicao"
                                                    onclick="abrirModalFinalizarSimples(this)"
                                                    title="Finalizar sem distribuir limite">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-acao btn-distribuir" disabled title="Crédito finalizado">
                                                    <i class="fas fa-share-alt"></i>
                                                </button>
                                                <button class="btn-acao btn-finalizar-sem-distribuicao" disabled title="Crédito finalizado">
                                                    <i class="fas fa-check-circle"></i>
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

        <!-- Modal de Distribuição -->
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
                            <div class="secao-conteudo-analise" id="analiseCreditoConteudo"></div>
                        </div>
                    </div>

                    <div class="modal-metade direita">
                        <div class="secao-modal-limite">
                            <div class="secao-titulo">
                                <i class="fas fa-wallet"></i>
                                <span>Limite Disponível</span>
                            </div>
                            <div class="secao-conteudo-limite" id="limiteGrandeConteudo"></div>
                        </div>

                        <div class="secao-modal">
                            <div class="secao-titulo">
                                <i class="fas fa-share-alt"></i>
                                <span>Distribuição por Unidade</span>
                            </div>
                            <div class="secao-conteudo" id="distribuicaoUnidadesConteudo"></div>

                            <div style="text-align: center; padding: 20px;">
                                <button class="btn-salvar-distribuicao" id="btnSalvarDistribuicao" onclick="salvarDistribuicaoTelaCheia()">
                                    <i class="fas fa-save"></i> SALVAR DISTRIBUIÇÃO E ATUALIZAR UNIDADES
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal para Finalização Simples - COM CAMPOS SEPARADOS -->
        <div id="modalFinalizarSimples" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-title">
                        <i class="fas fa-check-circle"></i>
                        Finalizar Crédito sem Distribuição
                    </div>
                    <button class="close-modal" onclick="fecharModalFinalizarSimples()" title="Fechar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="info-item-separado">
                        <label>Código:</label>
                        <div class="valor-campo" id="finalizar-codigo"></div>
                    </div>

                    <div class="info-item-separado">
                        <label>Razão Social:</label>
                        <div class="valor-campo" id="finalizar-razao-social"></div>
                    </div>

                    <div class="info-item-separado">
                        <label>Documento:</label>
                        <div class="valor-campo" id="finalizar-documento"></div>
                    </div>

                    <div class="info-item-separado">
                        <label>ID Neocredit:</label>
                        <div class="valor-campo" id="finalizar-id-neocredit"></div>
                    </div>

                    <div class="info-item-separado">
                        <label>Limite Aprovado:</label>
                        <div class="valor-campo" id="finalizar-limite-aprovado"></div>
                    </div>

                    <div class="info-item-separado">
                        <label>Status Atual:</label>
                        <div class="valor-campo" id="finalizar-situacao"></div>
                    </div>

                    <div class="observacao-finalizar">
                        <strong>Observação:</strong>
                        Esta ação irá finalizar o crédito sem distribuir limite para nenhuma unidade. O crédito será marcado como finalizado e não poderá ser alterado posteriormente.
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-success" id="btnConfirmarFinalizacao" onclick="confirmarFinalizacaoSimples()">
                            <i class="fas fa-check-circle"></i> CONFIRMAR FINALIZAÇÃO
                        </button>
                        <button class="btn btn-secondary" onclick="fecharModalFinalizarSimples()">
                            <i class="fas fa-times"></i> CANCELAR
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Constantes e variáveis globais
            const UNIDADES = [{
                    id: 'barra_mansa',
                    nome: 'Barra Mansa',
                    icone: 'fas fa-industry',
                    codicField: 'codic_bm'
                },
                {
                    id: 'botucatu',
                    nome: 'Botucatu',
                    icone: 'fas fa-industry',
                    codicField: 'codic_bot'
                },
                {
                    id: 'lins',
                    nome: 'Lins',
                    icone: 'fas fa-industry',
                    codicField: 'codic_ls'
                },
                {
                    id: 'rio_preto',
                    nome: 'Rio Preto',
                    icone: 'fas fa-industry',
                    codicField: 'codic_rp'
                },
                {
                    id: 'votuporanga',
                    nome: 'Votuporanga',
                    icone: 'fas fa-industry',
                    codicField: 'codic_vt_rnd'
                }
            ];

            let dadosClienteModalTelaCheia = null;
            let distribuicaoModalTelaCheia = {};
            let dadosClienteFinalizarSimples = null;

            // ===== FUNÇÕES DE FILTRO =====
            // ===== FUNÇÕES DE FILTRO =====
            function limparFiltros() {
                // Limpa o campo de busca
                document.getElementById('busca_geral').value = '';

                // Reseta o select para "Todos"
                document.getElementById('filtro_status').value = 'todos';

                // Cria um input hidden para indicar que é uma ação de limpar
                const form = document.querySelector('form');

                // Adiciona um campo hidden para garantir que o PHP entenda que é para limpar
                let hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'limpar_filtros';
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);

                // Submete o formulário
                form.submit();
            }
            // ===============================
            // ===============================

            // ===== FUNÇÕES PRINCIPAIS =====
            function abrirModalTelaCheia(botao) {
                const linha = botao.closest('tr');
                if (!linha) return;

                dadosClienteModalTelaCheia = JSON.parse(linha.getAttribute('data-dados'));
                distribuicaoModalTelaCheia = {};

                if (dadosClienteModalTelaCheia.situ === 'F') {
                    alert('Este crédito está finalizado. Não é possível distribuir limite.');
                    return;
                }

                carregarInformacoesEmpresa();
                carregarAnaliseCredito();
                carregarLimiteGrande();
                carregarDistribuicaoUnidades();

                const btnSalvar = document.getElementById('btnSalvarDistribuicao');
                if (btnSalvar) {
                    if (dadosClienteModalTelaCheia.situ === 'F') {
                        btnSalvar.disabled = true;
                        btnSalvar.style.opacity = '0.5';
                        btnSalvar.style.cursor = 'not-allowed';
                        btnSalvar.title = 'Crédito finalizado - operação não permitida';
                    } else {
                        setTimeout(() => {
                            validarDistribuicaoTelaCheia();
                        }, 100);
                    }
                }

                document.getElementById('modalTelaCheia').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            function fecharModalTelaCheia() {
                document.getElementById('modalTelaCheia').style.display = 'none';
                document.body.style.overflow = 'auto';
                dadosClienteModalTelaCheia = null;
                distribuicaoModalTelaCheia = {};
            }

            function abrirModalFinalizarSimples(botao) {
                const linha = botao.closest('tr');
                if (!linha) return;

                dadosClienteFinalizarSimples = JSON.parse(linha.getAttribute('data-dados'));

                if (dadosClienteFinalizarSimples.situ === 'F') {
                    alert('Este crédito já está finalizado.');
                    return;
                }

                carregarInfoClienteFinalizarCampos();

                document.getElementById('modalFinalizarSimples').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            function fecharModalFinalizarSimples() {
                document.getElementById('modalFinalizarSimples').style.display = 'none';
                document.body.style.overflow = 'auto';
                dadosClienteFinalizarSimples = null;
            }

            function carregarInfoClienteFinalizarCampos() {
                document.getElementById('finalizar-codigo').textContent = dadosClienteFinalizarSimples.codigo;
                document.getElementById('finalizar-razao-social').textContent = dadosClienteFinalizarSimples.razao_social;
                document.getElementById('finalizar-documento').textContent = dadosClienteFinalizarSimples.documento;
                document.getElementById('finalizar-id-neocredit').textContent = dadosClienteFinalizarSimples.id_neocredit || 'N/A';
                document.getElementById('finalizar-limite-aprovado').textContent = dadosClienteFinalizarSimples.lcred_aprovado;

                const situacaoElement = document.getElementById('finalizar-situacao');
                situacaoElement.textContent = dadosClienteFinalizarSimples.situ === 'A' ? 'ABERTO' : 'FINALIZADO';
                situacaoElement.className = 'valor-campo ' +
                    (dadosClienteFinalizarSimples.situ === 'A' ? 'situacao-aberto' : 'situacao-finalizado');
            }

            async function confirmarFinalizacaoSimples() {
                if (!dadosClienteFinalizarSimples) {
                    alert('Erro: Dados do cliente não encontrados.');
                    return;
                }

                if (dadosClienteFinalizarSimples.situ === 'F') {
                    alert('Este crédito já está finalizado.');
                    fecharModalFinalizarSimples();
                    return;
                }

                const confirmacao = confirm(`CONFIRMAR FINALIZAÇÃO\n\n` +
                    `Código: ${dadosClienteFinalizarSimples.codigo}\n` +
                    `Cliente: ${dadosClienteFinalizarSimples.razao_social}\n` +
                    `Documento: ${dadosClienteFinalizarSimples.documento}\n\n` +
                    `⚠️ Esta ação irá:\n` +
                    `1. Alterar a situação para "F" (FINALIZADO)\n` +
                    `2. Não distribuirá limite para nenhuma unidade\n` +
                    `3. Manterá o histórico existente\n\n` +
                    `Deseja continuar?`);

                if (!confirmacao) {
                    return;
                }

                const btnConfirmar = document.getElementById('btnConfirmarFinalizacao');
                const originalText = btnConfirmar.innerHTML;
                const originalTitle = btnConfirmar.title;

                btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSANDO...';
                btnConfirmar.title = 'Finalizando crédito...';
                btnConfirmar.disabled = true;

                try {
                    const formData = new FormData();
                    formData.append('codigo_cliente', dadosClienteFinalizarSimples.codigo);
                    formData.append('razao_social', dadosClienteFinalizarSimples.razao_social);
                    formData.append('documento', dadosClienteFinalizarSimples.documento_limpo);
                    formData.append('id_neocredit', dadosClienteFinalizarSimples.id_neocredit);
                    formData.append('acao', 'finalizar_simples');
                    formData.append('usuario', '<?php echo $usuario; ?>');

                    const response = await fetch('finalizar_simples.php', {
                        method: 'POST',
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error(`Erro HTTP: ${response.status}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        alert(`✅ CRÉDITO FINALIZADO COM SUCESSO!\n\n` +
                            `Código: ${dadosClienteFinalizarSimples.codigo}\n` +
                            `Cliente: ${dadosClienteFinalizarSimples.razao_social}\n` +
                            `Situação alterada para: FINALIZADO\n` +
                            `Usuário: ${data.usuario || 'Sistema'}\n\n` +
                            `A página será recarregada.`);

                        setTimeout(() => {
                            fecharModalFinalizarSimples();
                            location.reload();
                        }, 1500);
                    } else {
                        throw new Error(data.error || 'Erro desconhecido');
                    }
                } catch (error) {
                    alert(`❌ ERRO AO FINALIZAR CRÉDITO\n\n` +
                        `Não foi possível finalizar o crédito.\n` +
                        `Erro: ${error.message}`);

                    btnConfirmar.innerHTML = originalText;
                    btnConfirmar.title = originalTitle;
                    btnConfirmar.disabled = false;
                    console.error('Erro:', error);
                }
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
                        <div class="card-analise-valor">
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
                        <div class="card-analise-valor">
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
                const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
                const totalDistribuido = parseFloat(dadosClienteModalTelaCheia.total_distribuido) || 0;
                const saldoRestante = totalAprovado - totalDistribuido;

                let cor = '#fdb525';
                let icone = '';

                if (saldoRestante < 0) {
                    cor = '#dc3545';
                    icone = '<i class="fas fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></i>';
                } else if (saldoRestante === 0) {
                    cor = '#28a745';
                    icone = '<i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i>';
                }

                const html = `
                <div class="limite-grande-container">
                    <div class="limite-grande-valor" style="color: ${cor}">
                        ${formatarMoeda(saldoRestante)}
                    </div>
                </div>
            `;
                document.getElementById('limiteGrandeConteudo').innerHTML = html;
            }

            function carregarDistribuicaoUnidades() {
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

                const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;

                const distribuicaoExistente = {};
                if (dadosClienteModalTelaCheia.tem_distribuicao && dadosClienteModalTelaCheia.distribuicao_existente) {
                    dadosClienteModalTelaCheia.distribuicao_existente.forEach(dist => {
                        distribuicaoExistente[dist.unidade] = parseFloat(dist.valor_distribuido);
                    });
                }

                const formatarCodicExibicao = (codic) => {
                    return codic ? `(${codic})` : '(-)';
                };

                let html = '<div class="distribuicao-linha-horizontal">';

                UNIDADES.forEach(unidade => {
                    const valorExistente = distribuicaoExistente[unidade.id] || 0;
                    const valorInicial = valorExistente > 0 ? formatarNumeroBR(valorExistente) : '';

                    const codic = dadosClienteModalTelaCheia[unidade.codicField];
                    const temCodic = codic && codic !== '' && codic !== 'null';
                    const codicExibicao = formatarCodicExibicao(codic);

                    if (valorExistente > 0) {
                        distribuicaoModalTelaCheia[unidade.id] = valorExistente;
                    }

                    html += `
                    <div class="item-unidade-linha">
                        <div class="nome-unidade-linha">
                            <i class="${unidade.icone}"></i>
                            ${unidade.nome}
                            <span class="codic-info">
                                ${temCodic ? 
                                    '<span class="badge-codic">' + codicExibicao + '</span>' : 
                                    '<span class="badge-sem-codic">(-)</span>'
                                }
                            </span>
                        </div>
                        <div class="input-unidade-grupo-linha">
                            <span class="input-unidade-prefix-linha">R$</span>
                            <input type="text" 
                                   class="input-unidade-linha ${!temCodic ? 'sem-codic' : ''}"
                                   id="dist_input_${unidade.id}"
                                   data-unidade="${unidade.id}"
                                   data-codic="${codic || ''}"
                                   data-tem-codic="${temCodic}"
                                   placeholder="0,00"
                                   value="${valorInicial}"
                                   oninput="formatarValorDistribuicaoTelaCheia(this)"
                                   onkeyup="validarDistribuicaoTelaCheia()"
                                   autocomplete="off"
                                   ${!temCodic ? 'title="Unidade sem CODIC - não será atualizada no Firebird"' : ''}>
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
                    atualizarPorcentagemTelaCheia(unidade.id, valor, totalAprovado);
                });

                validarDistribuicaoTelaCheia();
            }

            function validarUnidadesComCodic() {
                let unidadesComCodicValor = 0;

                UNIDADES.forEach(unidade => {
                    const input = document.getElementById(`dist_input_${unidade.id}`);
                    if (input) {
                        const valor = distribuicaoModalTelaCheia[unidade.id] || 0;
                        const temCodic = input.dataset.temCodic === 'true';

                        if (temCodic && valor > 0) {
                            unidadesComCodicValor++;
                        }
                    }
                });

                return unidadesComCodicValor > 0;
            }

            function contarUnidadesComValor() {
                let unidadesComValor = 0;

                UNIDADES.forEach(unidade => {
                    const valor = distribuicaoModalTelaCheia[unidade.id] || 0;
                    if (valor > 0) {
                        unidadesComValor++;
                    }
                });

                return unidadesComValor;
            }

            function formatarValorDistribuicaoTelaCheia(input) {
                if (dadosClienteModalTelaCheia && dadosClienteModalTelaCheia.situ === 'F') {
                    return;
                }

                let valor = input.value.replace(/\D/g, '');
                valor = valor.replace(/^0+/, '');

                const unidadeId = input.dataset.unidade;
                const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;

                if (valor === '') {
                    input.value = '';
                    delete distribuicaoModalTelaCheia[unidadeId];
                    atualizarPorcentagemTelaCheia(unidadeId, 0, totalAprovado);
                    validarDistribuicaoTelaCheia();
                    return;
                }

                const valorDecimal = parseInt(valor) / 100;

                input.classList.remove('erro');
                document.getElementById(`dist_erro_${unidadeId}`).textContent = '';
                input.value = formatarNumeroBR(valorDecimal);
                distribuicaoModalTelaCheia[unidadeId] = valorDecimal;

                atualizarPorcentagemTelaCheia(unidadeId, distribuicaoModalTelaCheia[unidadeId] || 0, totalAprovado);
                validarDistribuicaoTelaCheia();
            }

            function calcularTotalDistribuidoTelaCheia() {
                let total = 0;
                UNIDADES.forEach(unidade => {
                    total += distribuicaoModalTelaCheia[unidade.id] || 0;
                });
                return total;
            }

            function atualizarPorcentagemTelaCheia(unidadeId, valor, totalAprovado) {
                if (totalAprovado <= 0) totalAprovado = 1;
                const porcentagemElement = document.getElementById(`dist_porcentagem_${unidadeId}`);
                if (porcentagemElement) {
                    const porcentagem = (valor / totalAprovado * 100).toFixed(1);
                    porcentagemElement.textContent = `${porcentagem}%`;
                }
            }

            function validarDistribuicaoTelaCheia() {
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
                const unidadesComValor = contarUnidadesComValor();
                const temUnidadesComCodicValor = validarUnidadesComCodic();

                const btnSalvar = document.getElementById('btnSalvarDistribuicao');

                if (btnSalvar) {
                    if (totalDistribuido === 0) {
                        btnSalvar.disabled = true;
                        btnSalvar.title = 'Insira valores para distribuir';
                    } else if (unidadesComValor > 0 && !temUnidadesComCodicValor) {
                        btnSalvar.disabled = true;
                        btnSalvar.title = 'Pelo menos uma unidade com valor precisa ter CODIC';
                    } else {
                        btnSalvar.disabled = false;
                        if (saldoRestante < 0) {
                            btnSalvar.title = 'Clique para salvar (ATENÇÃO: excede limite!)';
                        } else {
                            btnSalvar.title = 'Clique para salvar distribuição e atualizar unidades';
                        }
                    }
                }

                atualizarLimiteGrandeTempoReal(totalDistribuido);
                return totalDistribuido;
            }

            function atualizarLimiteGrandeTempoReal(totalDistribuido) {
                const totalAprovado = parseFloat(dadosClienteModalTelaCheia.lcred_aprovado_numero) || 0;
                const saldoRestante = totalAprovado - totalDistribuido;

                let cor = '#fdb525';
                let icone = '';
                let textoStatus = '';

                if (saldoRestante < 0) {
                    cor = '#dc3545';
                    icone = '<i class="fas fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></i>';
                } else if (saldoRestante === 0) {
                    cor = '#28a745';
                    icone = '<i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i>';
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

                const distribuicoesComCodic = [];
                const distribuicoesSemCodic = [];

                UNIDADES.forEach(unidade => {
                    const input = document.getElementById(`dist_input_${unidade.id}`);
                    if (input) {
                        const valor = distribuicaoModalTelaCheia[unidade.id] || 0;
                        const codic = input.dataset.codic;
                        const temCodic = input.dataset.temCodic === 'true';

                        if (valor > 0) {
                            const distribuicao = {
                                unidade: unidade.id,
                                nome_unidade: unidade.nome,
                                valor: valor,
                                codic: codic,
                                tem_codic: temCodic
                            };

                            if (temCodic && codic && codic !== '' && codic !== 'null') {
                                distribuicoesComCodic.push(distribuicao);
                            } else {
                                distribuicoesSemCodic.push(distribuicao);
                            }
                        }
                    }
                });

                if (distribuicoesComCodic.length === 0) {
                    alert('⚠️ ATENÇÃO!\n\nÉ necessário que pelo menos uma unidade tenha CODIC e valor para atualizar os limites.\n\nUnidades sem CODIC não serão atualizadas no sistema das filiais.');
                    return;
                }

                let mensagemConfirmacao = `
    📋 CONFIRMAÇÃO DE DISTRIBUIÇÃO
    
    Valor total a distribuir: ${formatarMoeda(totalDistribuido)}
    Limite aprovado: ${formatarMoeda(totalAprovado)}
    Saldo restante: ${formatarMoeda(saldoRestante)}
    
    Unidades que SERÃO ATUALIZADAS (com CODIC):`;

                distribuicoesComCodic.forEach((dist, index) => {
                    mensagemConfirmacao += `\n${index + 1}. ${dist.nome_unidade} - ${formatarMoeda(dist.valor)} (CODIC: ${dist.codic})`;
                });

                if (distribuicoesSemCodic.length > 0) {
                    mensagemConfirmacao += `\n\nUnidades APENAS no histórico (sem CODIC):`;
                    distribuicoesSemCodic.forEach((dist, index) => {
                        mensagemConfirmacao += `\n${index + 1}. ${dist.nome_unidade} - ${formatarMoeda(dist.valor)}`;
                    });
                }

                mensagemConfirmacao += `
    
    Esta ação irá:
    1. ✅ Salvar TODAS as distribuições no histórico
    2. ✅ Atualizar o limite (LCRED) SOMENTE nas unidades com CODIC
    3. ✅ Finalizar o crédito (situação = F)
    
    Deseja continuar?`;

                if (saldoRestante < 0) {
                    const excedente = Math.abs(saldoRestante);
                    mensagemConfirmacao = `
        ⚠️ ATENÇÃO - LIMITE EXCEDIDO ⚠️
        
        Valor total a distribuir: ${formatarMoeda(totalDistribuido)}
        Limite aprovado: ${formatarMoeda(totalAprovado)}
        Excedente: ${formatarMoeda(excedente)} (${((excedente/totalAprovado)*100).toFixed(1)}%)
        
        ⚠️ VOCÊ ESTÁ DISTRIBUINDO MAIS QUE O LIMITE APROVADO!
        
        ${mensagemConfirmacao}
        
        ⚠️ ATENÇÃO: Esta operação criará um excedente de crédito nas unidades com CODIC.
        
        Deseja realmente continuar?`;
                }

                if (confirm(mensagemConfirmacao.replace(/\n\s+/g, '\n'))) {
                    const todasDistribuicoes = [...distribuicoesComCodic, ...distribuicoesSemCodic];

                    const formData = new FormData();
                    formData.append('codigo_cliente', dadosClienteModalTelaCheia.codigo);
                    formData.append('razao_social', dadosClienteModalTelaCheia.razao_social);
                    formData.append('documento', dadosClienteModalTelaCheia.documento_limpo);
                    formData.append('id_neocredit', dadosClienteModalTelaCheia.id_neocredit);
                    formData.append('total_distribuido', totalDistribuido);
                    formData.append('limite_aprovado', totalAprovado);
                    formData.append('distribuicoes', JSON.stringify(todasDistribuicoes));
                    formData.append('usuario', '<?php echo $usuario; ?>');

                    const btnSalvar = document.getElementById('btnSalvarDistribuicao');
                    const originalText = btnSalvar.innerHTML;
                    const originalTitle = btnSalvar.title;

                    btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSANDO...';
                    btnSalvar.title = 'Salvando distribuição e atualizando unidades...';
                    btnSalvar.disabled = true;

                    fetch('salvar_distribuicao.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`Erro HTTP: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                let mensagemSucesso = `
                    ✅ DISTRIBUIÇÃO CONCLUÍDA COM SUCESSO!
                    
                    Resumo da operação:
                    • Cliente: ${dadosClienteModalTelaCheia.razao_social}
                    • Total distribuído: ${formatarMoeda(totalDistribuido)}
                    • Código: ${dadosClienteModalTelaCheia.codigo}
                    • Usuário: ${data.usuario || 'MARCUS'}
                    
                    RESULTADOS:`;

                                const unidadesAtualizadas = data.unidades_atualizadas?.filter(u => u.sucesso) || [];
                                const unidadesNaoAtualizadas = data.unidades_atualizadas?.filter(u => !u.sucesso && u.causa === 'sem_codic') || [];

                                if (unidadesAtualizadas.length > 0) {
                                    mensagemSucesso += `\n\n✅ UNIDADES ATUALIZADAS NO FIREBIRD:`;
                                    unidadesAtualizadas.forEach((unidade, index) => {
                                        mensagemSucesso += `\n${index + 1}. ${unidade.nome_unidade || unidade.unidade}:`;
                                        mensagemSucesso += `\n   • Limite: ${formatarMoeda(unidade.limite)}`;
                                        mensagemSucesso += `\n   • CODIC: ${unidade.codic || 'N/A'}`;
                                        mensagemSucesso += `\n   • Status: ${unidade.mensagem || 'Atualizado'}`;
                                    });
                                }

                                if (unidadesNaoAtualizadas.length > 0) {
                                    mensagemSucesso += `\n\n📝 UNIDADES APENAS NO HISTÓRICO (-):`;
                                    unidadesNaoAtualizadas.forEach((unidade, index) => {
                                        mensagemSucesso += `\n${index + 1}. ${unidade.nome_unidade || unidade.unidade}:`;
                                        mensagemSucesso += `\n   • Limite: ${formatarMoeda(unidade.limite)}`;
                                        mensagemSucesso += `\n   • Status: Salvo apenas no histórico`;
                                    });
                                }

                                const unidadesComErro = data.unidades_atualizadas?.filter(u => !u.sucesso && u.causa !== 'sem_codic') || [];
                                if (unidadesComErro.length > 0) {
                                    mensagemSucesso += `\n\n❌ ERROS ENCONTRADOS:`;
                                    unidadesComErro.forEach((unidade, index) => {
                                        mensagemSucesso += `\n${index + 1}. ${unidade.nome_unidade || unidade.unidade}:`;
                                        mensagemSucesso += `\n   • Erro: ${unidade.erro}`;
                                    });
                                }

                                mensagemSucesso += `\n\n📝 Situação do crédito alterada para: FINALIZADO (F)`;

                                if (saldoRestante < 0) {
                                    mensagemSucesso += `\n\n⚠️ ATENÇÃO: Limite excedido em ${formatarMoeda(Math.abs(saldoRestante))}`;
                                }

                                mensagemSucesso += `\n\nA página será recarregada automaticamente.`;

                                alert(mensagemSucesso.replace(/\n\s+/g, '\n'));

                                setTimeout(() => {
                                    fecharModalTelaCheia();
                                    location.reload();
                                }, 1500);

                            } else {
                                let erroMsg = '❌ ERRO AO SALVAR DISTRIBUIÇÃO\n\n';
                                erroMsg += `Motivo: ${data.error || 'Erro desconhecido'}`;

                                alert(erroMsg);

                                btnSalvar.innerHTML = originalText;
                                btnSalvar.title = originalTitle;
                                btnSalvar.disabled = false;
                            }
                        })
                        .catch(error => {
                            let erroMsg = '❌ ERRO NA COMUNICAÇÃO\n\n';
                            erroMsg += `Não foi possível conectar ao servidor.\n`;
                            erroMsg += `Erro: ${error.message}`;

                            alert(erroMsg);
                            console.error('Erro no fetch:', error);

                            btnSalvar.innerHTML = originalText;
                            btnSalvar.title = originalTitle;
                            btnSalvar.disabled = false;
                        });
                }
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
                const modalFinalizarSimples = document.getElementById('modalFinalizarSimples');

                if (event.target === modalTelaCheia) {
                    fecharModalTelaCheia();
                }
                if (event.target === modalFinalizarSimples) {
                    fecharModalFinalizarSimples();
                }
            };

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    if (document.getElementById('modalTelaCheia').style.display === 'block') {
                        fecharModalTelaCheia();
                    }
                    if (document.getElementById('modalFinalizarSimples').style.display === 'block') {
                        fecharModalFinalizarSimples();
                    }
                }
            });

            document.addEventListener('DOMContentLoaded', function() {
                console.log('Sistema de distribuição de crédito carregado.');

                setTimeout(() => {
                    document.querySelectorAll('.input-unidade-linha').forEach(input => {
                        input.addEventListener('blur', function() {
                            if (this.value === '') {
                                this.value = '0,00';
                                formatarValorDistribuicaoTelaCheia(this);
                            }
                        });
                    });
                }, 500);
            });
        </script>
</body>

</html>