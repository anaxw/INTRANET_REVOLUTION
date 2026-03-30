<?php
// ================= CONFIGURAÇÕES DE CODIFICAÇÃO =================
ini_set('default_charset', 'UTF-8');
ini_set('internal_encoding', 'UTF-8');
ini_set('input_encoding', 'UTF-8');
ini_set('output_encoding', 'UTF-8');
ini_set('mbstring.internal_encoding', 'UTF-8');
ini_set('mbstring.http_output', 'UTF-8');
ini_set('mbstring.encoding_translation', 'On');

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Configurar locale para português brasileiro
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'portuguese');
date_default_timezone_set('America/Sao_Paulo');

// ================= CONFIGURAÇÃO DE SESSÃO =================
ini_set('session.gc_maxlifetime', 300);
ini_set('session.cookie_lifetime', 300);
session_set_cookie_params(300);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['ops'])) {
    $_SESSION['ops'] = [];
}

// ================= CONEXÃO COM BANCO DE DADOS =================
$dsn  = 'firebird:dbname=192.168.1.209:c:/BD/ARQSIST.FDB;charset=WIN1252';
$user = 'SYSDBA';
$pass = 'masterkey';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE    => PDO::CASE_LOWER,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// ================= FUNÇÕES DE CONVERSÃO =================
function converterParaUtf8($texto)
{
    if ($texto === null || $texto === '' || !is_string($texto)) {
        return $texto;
    }

    if (mb_check_encoding($texto, 'UTF-8') && mb_detect_encoding($texto, 'UTF-8', true) !== false) {
        return $texto;
    }

    $encodings = ['Windows-1252', 'ISO-8859-1', 'CP1252', 'ASCII'];
    $detected = mb_detect_encoding($texto, $encodings, true);

    if ($detected && $detected !== 'UTF-8') {
        $converted = mb_convert_encoding($texto, 'UTF-8', $detected);
        if (mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    $converted = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    $converted = mb_convert_encoding($converted, 'UTF-8', 'UTF-8');

    return $converted;
}

function corrigirCaracteresEspeciais($texto)
{
    if (empty($texto) || !is_string($texto)) return $texto;

    $texto = converterParaUtf8($texto);

    $problemasComuns = [
        'Ã§' => 'ç', 'Ã£' => 'ã', 'Ã¡' => 'á', 'Ã©' => 'é',
        'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ãª' => 'ê',
        'Ã´' => 'ô', 'Ã ' => 'à', 'Ãµ' => 'õ', 'Ã¢' => 'â',
        'Ã¨' => 'è', 'Ã¼' => 'ü', 'Ã±' => 'ñ',
        'Ã‡' => 'Ç', 'Ãƒ' => 'Ã', 'Ã' => 'Á', 'Ã‰' => 'É',
        'Ã' => 'Í', 'Ã“' => 'Ó', 'Ãš' => 'Ú', 'ÃŠ' => 'Ê',
        'Ã”' => 'Ô', 'Ãƒ' => 'À', 'Ã•' => 'Õ', 'Ã‚' => 'Â',
    ];

    foreach ($problemasComuns as $errado => $correto) {
        $texto = str_replace($errado, $correto, $texto);
    }

    return $texto;
}

// ================= FUNÇÃO BUSCAR OP =================
function buscarDadosOP(PDO $pdo, $opNumero)
{
    $sql = "
        SELECT
            o.seqop,
            CAST(p.codacessog AS INTEGER) AS cod_produto,
            COALESCE(NULLIF(a.obs1, ''), pr.nome) AS produto_nome,
            c.nome AS nome_cliente,
            COALESCE(cid.nome, 'CIDADE NÃO INFORMADA') || ' - ' ||
            COALESCE(cid.estado, 'UF NÃO INFORMADA') AS cidade,
            LPAD(EXTRACT(DAY FROM O.dev_data), 2, '0') || '-' ||
            LPAD(EXTRACT(MONTH FROM O.dev_data), 2, '0') || '-' ||
            EXTRACT(YEAR FROM O.dev_data) || ' ' ||
            O.dev_seq AS lote
        FROM arqes15 pr
        INNER JOIN arqes13 pd ON pd.pedido = pr.pedido
        LEFT OUTER JOIN op_lancamento o ON o.seqitem = pr.seqlanc
        INNER JOIN arqes01 p ON p.codigo = pr.produto
        INNER JOIN arqcad c ON c.codic = pd.codic AND c.tipoc = 'C'
        LEFT OUTER JOIN vd_obs a ON a.codobs = pr.pedido 
            AND a.tipo = 'J' 
            AND a.tipoc = 'I' 
            AND a.codic = pr.item
        LEFT JOIN cidades cid ON cid.seqcidade = c.seqcidade
        WHERE o.seqop = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$opNumero]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultado) {
        $sql_alternativo = "
            SELECT
                o.seqop,
                CAST(p.codacessog AS INTEGER) AS cod_produto,
                COALESCE(NULLIF(TRIM(a.obs1), ''), pr.nome) AS produto_nome,
                c.nome AS nome_cliente,
                COALESCE(cid.nome, 'CIDADE NÃO INFORMADA') || ' - ' ||
                COALESCE(cid.estado, 'UF NÃO INFORMADA') AS cidade,
                CASE 
                    WHEN o.dev_data IS NOT NULL 
                     AND o.dev_seq IS NOT NULL 
                    THEN 
                        LPAD(EXTRACT(DAY FROM o.dev_data), 2, '0') || '-' ||
                        LPAD(EXTRACT(MONTH FROM o.dev_data), 2, '0') || '-' ||
                        EXTRACT(YEAR FROM o.dev_data) || ' ' ||
                        LPAD(o.dev_seq, 2, '0')
                    ELSE 'LOTE NÃO DEFINIDO'
                END AS lote
            FROM op_lancamento o
            LEFT JOIN arqes13 pd ON pd.pedido = o.pedido
            LEFT JOIN arqcad c   ON c.codic = pd.codic AND c.tipoc = 'C'
            LEFT JOIN arqes15 pr ON pr.pedido = o.pedido
            LEFT JOIN arqes01 p  ON p.codigo = pr.produto
            LEFT JOIN vd_obs a   ON a.codobs = o.pedido
                                  AND a.tipo = 'J' 
                                  AND a.tipoc = 'I'
            LEFT JOIN cidades cid ON cid.seqcidade = c.seqcidade
            WHERE o.seqop = ?
        ";

        $stmt = $pdo->prepare($sql_alternativo);
        $stmt->execute([$opNumero]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($resultado && isset($resultado['lote'])) {
        $lote = $resultado['lote'];
        if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d+$/', $lote)) {
            $parts = explode(' ', $lote);
            if (count($parts) === 2) {
                $dataPart = $parts[0];
                $seqPart = $parts[1];
                $seqPart = str_pad($seqPart, 2, '0', STR_PAD_LEFT);
                $resultado['lote'] = $dataPart . ' ' . $seqPart;
            }
        }
    }

    if ($resultado) {
        foreach ($resultado as $key => &$value) {
            if (is_string($value)) {
                $value = converterParaUtf8($value);
                $value = corrigirCaracteresEspeciais($value);
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }

    return $resultado;
}

// ================= PROCESSAMENTO POST =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    /* ---- ADICIONAR OP ---- */
    if (isset($_POST['adicionar']) && !empty($_POST['op'])) {

        $opNumero = trim($_POST['op']);
        $quantidade = isset($_POST['quantidade']) ? (int)$_POST['quantidade'] : 1;

        if ($quantidade < 1) $quantidade = 1;
        if ($quantidade > 100) $quantidade = 100;

        $dados = buscarDadosOP($pdo, $opNumero);

        if ($dados) {
            foreach ($_SESSION['ops'] as $op) {
                if (isset($op['seqop']) && $op['seqop'] == $dados['seqop']) {
                    $_SESSION['mensagem'] = "OP {$opNumero} já adicionada!";
                    $_SESSION['tipo_mensagem'] = 'warning';

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'message' => "OP {$opNumero} já adicionada!",
                            'type' => 'warning'
                        ]);
                        exit;
                    } else {
                        header("Location: etiquetas.php");
                        exit;
                    }
                }
            }

            $produtoNome = isset($dados['produto_nome']) ?
                corrigirCaracteresEspeciais($dados['produto_nome']) :
                'PRODUTO NÃO ENCONTRADO';

            $nomeCliente = isset($dados['nome_cliente']) ?
                corrigirCaracteresEspeciais($dados['nome_cliente']) :
                'CLIENTE NÃO INFORMADO';

            $cidade = isset($dados['cidade']) ?
                corrigirCaracteresEspeciais($dados['cidade']) :
                'CIDADE NÃO INFORMADA';

            $lote = isset($dados['lote']) ?
                corrigirCaracteresEspeciais($dados['lote']) :
                'LOTE NÃO DEFINIDO';

            $_SESSION['ops'][] = [
                'id'           => uniqid(),
                'seqop'        => (int)$dados['seqop'],
                'cod_produto'  => $dados['cod_produto'],
                'produto'      => $produtoNome,
                'nome_cliente' => $nomeCliente,
                'cliente'      => $nomeCliente,
                'cidade'       => $cidade,
                'lote'         => $lote,
                'quantidade'   => $quantidade,
                'data'         => date('d/m/Y H:i:s')
            ];

            $_SESSION['mensagem'] = "OP {$opNumero} adicionada com sucesso! (Quantidade: {$quantidade})";
            $_SESSION['tipo_mensagem'] = 'success';

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => "OP {$opNumero} adicionada com sucesso! (Quantidade: {$quantidade})",
                    'type' => 'success',
                    'total_ops' => count($_SESSION['ops']),
                    'op_data' => [
                        'id' => $_SESSION['ops'][count($_SESSION['ops']) - 1]['id'],
                        'seqop' => (int)$dados['seqop'],
                        'cod_produto' => $dados['cod_produto'],
                        'produto' => $produtoNome,
                        'nome_cliente' => $nomeCliente,
                        'cidade' => $cidade,
                        'lote' => $lote,
                        'quantidade' => $quantidade
                    ]
                ]);
                exit;
            }
        } else {
            $_SESSION['mensagem'] = "OP {$opNumero} não encontrada!";
            $_SESSION['tipo_mensagem'] = 'error';

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => "OP {$opNumero} não encontrada!",
                    'type' => 'error'
                ]);
                exit;
            }
        }
    }

    /* ---- REMOVER OP ---- */
    if (isset($_POST['remover'], $_POST['op_id'])) {
        $novoArray = [];
        foreach ($_SESSION['ops'] as $op) {
            if ($op['id'] !== $_POST['op_id']) {
                $novoArray[] = $op;
            }
        }
        $_SESSION['ops'] = $novoArray;

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'OP removida com sucesso!',
                'total_ops' => count($_SESSION['ops'])
            ]);
            exit;
        }
    }

    /* ---- LIMPAR TODAS AS OPs ---- */
    if (isset($_POST['limpar_tudo'])) {
        $_SESSION['ops'] = [];
        $_SESSION['mensagem'] = "Todas as OPs foram removidas!";
        $_SESSION['tipo_mensagem'] = 'info';

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Todas as OPs foram removidas!',
                'total_ops' => 0
            ]);
            exit;
        }
    }

    if (!$isAjax) {
        header("Location: etiquetas.php");
        exit;
    }
}

$totalOps = count($_SESSION['ops'] ?? []);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Sistema de Etiquetas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">   
    <link rel="stylesheet" type="text/css" href="etiquetas.css">
    <style>
          .btn-pdf {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 5px;
            height: 35px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            margin-left: 10px;
            text-decoration: none;
        }

        .btn-pdf:hover {
            background-color: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btn-pdf:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .btn-pdf i {
            font-size: 18px;
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <!-- CABEÇALHO -->
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

            <!-- MENSAGENS DA SESSÃO -->
            <?php if (isset($_SESSION['mensagem']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])): ?>
                <div class="mensagem-flutuante mensagem-<?php echo $_SESSION['tipo_mensagem'] ?? 'info'; ?>">
                    <?php
                    echo htmlspecialchars($_SESSION['mensagem'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    unset($_SESSION['mensagem']);
                    unset($_SESSION['tipo_mensagem']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="botoes-direita">
                <a href="etiquetas_imp.php" class="btn-imprimir" id="btn-imprimir"
                    style="<?php echo $totalOps === 0 ? 'display: none;' : 'display: inline-flex;'; ?>">
                    <i class="fa fa-print" aria-hidden="true"></i>
                    Imprimir (<span id="total-copias"><?php
                                                        $totalCopias = 0;
                                                        foreach ($_SESSION['ops'] as $op) {
                                                            $totalCopias += ($op['quantidade'] ?? 1);
                                                        }
                                                        echo $totalCopias;
                                                        ?></span> cópias)
                </a>

                <!-- BOTÃO PDF - VERSÃO DEFINITIVA -->
                <a href="Tutorial_op.pdf" target="_blank" class="btn-pdf" id="btn-abrir-pdf">
                    <i class="fa fa-file-pdf" aria-hidden="true"></i>
                    Tutorial
                </a>
            </div>
        </div>

        <!-- CONTÊINER DA TABELA -->
        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fas fa-ticket" style="color: #fdb525; margin-right: 10px;"></i> Sistema de Etiquetas - OP
            </div>

            <!-- FORMULÁRIOS -->
            <div class="formulario-centralizado">
                <form method="POST" class="formulario-botoes" id="form-adicionar" accept-charset="UTF-8" onsubmit="return false;">
                    <input type="hidden" name="charset" value="UTF-8">
                    <input type="text"
                        name="op"
                        id="campo-op"
                        class="campo-op"
                        placeholder="Digite o número da OP..."
                        autocomplete="off"
                        autofocus>
                    <div class="quantidade-container">
                        <input type="number"
                            name="quantidade"
                            id="campo-quantidade"
                            class="campo-quantidade"
                            value="1"
                            min="1"
                            max="100"
                            title="Quantidade de cópias">
                    </div>
                    <button type="button" id="btn-adicionar" class="btn-adicionar">
                        <i class="fas fa-plus-circle"></i>
                        Add OP
                    </button>
                </form>

                <form method="POST" id="form-limpar-tudo" style="display: inline;" accept-charset="UTF-8">
                    <input type="hidden" name="charset" value="UTF-8">
                    <button type="button" name="limpar_tudo" class="btn-limpar-tudo" id="btn-limpar-tudo"
                        <?php echo $totalOps === 0 ? 'disabled' : ''; ?>>
                        <i class="fas fa-trash-alt"></i>
                        Limpar Tudo
                    </button>
                </form>
            </div>

            <!-- TABELA -->
            <div class="tabela-container">
                <table id="tabela-ops">
                    <thead>
                        <tr>
                            <th class="col-id">#</th>
                            <th class="col-op-numero">OP</th>
                            <th class="col-quantidade">Qtd</th>
                            <th class="col-cod-produto">Código</th>
                            <th class="col-lote">Lote</th>
                            <th class="col-produto">Produto</th>
                            <th class="col-cliente">Cliente</th>
                            <th class="col-cidade">Cidade</th>
                            <th class="col-acoes">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="corpo-tabela">
                        <?php if ($totalOps === 0): ?>
                            <tr id="linha-vazia">
                                <td colspan="9" style="border: none;">
                                    <div class="lista-vazia">
                                        <div class="lista-vazia-icon">📋</div>
                                        <h3>Nenhuma OP cadastrada</h3>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($_SESSION['ops'] as $index => $op): ?>
                                <?php
                                $loteClass = (isset($op['lote']) &&
                                    ($op['lote'] == 'LOTE NÃO DEFINIDO' ||
                                        $op['lote'] == 'LOTE NÃO INFORMADO')) ? 'dado-nulo' : '';

                                $cidadeClass = (isset($op['cidade']) &&
                                    ($op['cidade'] == 'CIDADE NÃO INFORMADA - UF NÃO INFORMADA' ||
                                        strpos($op['cidade'], 'NÃO INFORMADA') !== false)) ? 'dado-nulo' : '';

                                $produtoTexto = isset($op['produto']) && !empty($op['produto']) ?
                                    $op['produto'] :
                                    'PRODUTO NÃO ENCONTRADO';

                                $clienteTexto = isset($op['nome_cliente']) && !empty($op['nome_cliente']) ?
                                    $op['nome_cliente'] : (isset($op['cliente']) ? $op['cliente'] : 'CLIENTE NÃO INFORMADO');

                                $cidadeTexto = isset($op['cidade']) && !empty($op['cidade']) ?
                                    $op['cidade'] :
                                    'CIDADE NÃO INFORMADA';

                                $loteTexto = isset($op['lote']) && !empty($op['lote']) ?
                                    $op['lote'] :
                                    'LOTE NÃO DEFINIDO';

                                $codigoProduto = isset($op['cod_produto']) ?
                                    $op['cod_produto'] :
                                    'N/A';

                                $seqop = isset($op['seqop']) ?
                                    $op['seqop'] :
                                    'N/A';

                                $quantidade = $op['quantidade'] ?? 1;
                                $opId = $op['id'] ?? '';
                                ?>
                                <tr id="linha-<?php echo $opId; ?>" data-op-id="<?php echo $opId; ?>">
                                    <td class="col-id"><?php echo str_pad($index + 1, 3, '0', STR_PAD_LEFT); ?></td>
                                    <td class="col-op-numero"><strong><?php echo htmlspecialchars($seqop, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></strong></td>
                                    <td class="col-quantidade">
                                        <span class="quantidade-badge" title="<?php echo $quantidade; ?> cópias">
                                            <?php echo $quantidade; ?>
                                        </span>
                                    </td>
                                    <td class="col-cod-produto"><?php echo htmlspecialchars($codigoProduto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                    <td class="col-lote <?php echo $loteClass; ?>">
                                        <?php echo htmlspecialchars($loteTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    </td>
                                    <td class="col-produto preserve-special-chars">
                                        <div class="texto-completo">
                                            <?php echo htmlspecialchars($produtoTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td class="col-cliente preserve-special-chars">
                                        <div class="texto-completo">
                                            <?php echo htmlspecialchars($clienteTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td class="col-cidade <?php echo $cidadeClass; ?>">
                                        <?php echo htmlspecialchars($cidadeTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <button type="button" class="btn-acao btn-remover"
                                                onclick="removerOP('<?php echo $opId; ?>', '<?php echo htmlspecialchars($seqop, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>', '<?php echo htmlspecialchars($codigoProduto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>', <?php echo $quantidade; ?>)"
                                                title="Remover OP">
                                                <i class="fas fa-trash"></i>
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

    <!-- MODAL DE CONFIRMAÇÃO SUTIL - LIMPAR TUDO -->
    <div id="modal-limpar-tudo" class="modal-confirmacao-sutil">
        <div class="modal-conteudo-sutil">
            <div class="modal-header-sutil">
                <i class="fas fa-trash-alt"></i>
                <h3>Limpar todas as OPs</h3>
            </div>
            <div class="modal-body-sutil">
                <p>
                    <strong>Tem certeza?</strong> Esta ação removerá permanentemente
                    <span id="total-ops-modal" class="total-ops-sutil">0</span> OP(s) da lista.
                </p>
                <div class="alerta-info-sutil">
                    <i class="fas fa-info-circle"></i>
                    <span>Esta operação não pode ser desfeita.</span>
                </div>
            </div>
            <div class="modal-footer-sutil">
                <button type="button" id="btn-cancelar-limpar" class="btn-modal-sutil btn-cancelar-sutil">
                    <i class="fas fa-times"></i>
                    Cancelar
                </button>
                <button type="button" id="btn-confirmar-limpar" class="btn-modal-sutil btn-confirmar-sutil">
                    <i class="fas fa-trash-alt"></i>
                    Limpar tudo
                </button>
            </div>
        </div>
    </div>

    <script>
        // ========== CONFIGURAÇÕES GLOBAIS ==========
        document.addEventListener('DOMContentLoaded', function() {
            const campoOP = document.getElementById('campo-op');
            const campoQuantidade = document.getElementById('campo-quantidade');
            const btnAdicionar = document.getElementById('btn-adicionar');
            const btnLimparTudo = document.getElementById('btn-limpar-tudo');
            const btnImprimir = document.getElementById('btn-imprimir');
            const corpoTabela = document.getElementById('corpo-tabela');

            // ===== MODAL DE CONFIRMAÇÃO - LIMPAR TUDO =====
            const modal = document.getElementById('modal-limpar-tudo');
            const btnCancelar = document.getElementById('btn-cancelar-limpar');
            const btnConfirmar = document.getElementById('btn-confirmar-limpar');

            // Abrir modal ao clicar em Limpar Tudo
            if (btnLimparTudo) {
                btnLimparTudo.addEventListener('click', function(e) {
                    e.preventDefault();
                    abrirModalLimpar();
                });
            }

            // Cancelar - fechar modal
            if (btnCancelar) {
                btnCancelar.addEventListener('click', function() {
                    fecharModalLimpar();
                });
            }

            // Confirmar - executar limpeza
            if (btnConfirmar) {
                btnConfirmar.addEventListener('click', function() {
                    executarLimpeza();
                });
            }

            // Fechar modal ao clicar fora
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        fecharModalLimpar();
                    }
                });
            }

            // Fechar modal com tecla ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                    fecharModalLimpar();
                }
            });

            // Função para abrir o modal
            function abrirModalLimpar() {
                const totalLinhas = document.querySelectorAll('#corpo-tabela tr:not(#linha-vazia)').length;

                if (totalLinhas === 0) {
                    mostrarMensagem('Não há OPs para limpar!', 'warning');
                    return;
                }

                const totalOpsSpan = document.getElementById('total-ops-modal');
                if (totalOpsSpan) {
                    totalOpsSpan.textContent = totalLinhas;
                }

                modal.style.display = 'flex';

                const btnCancelar = document.getElementById('btn-cancelar-limpar');
                if (btnCancelar) {
                    btnCancelar.focus();
                }
            }

            // Função global para fechar o modal
            window.fecharModalLimpar = function() {
                const modal = document.getElementById('modal-limpar-tudo');
                if (modal) {
                    modal.style.display = 'none';
                }

                const btnConfirmar = document.getElementById('btn-confirmar-limpar');
                if (btnConfirmar) {
                    btnConfirmar.innerHTML = '<i class="fas fa-trash-alt"></i> Limpar tudo';
                    btnConfirmar.disabled = false;
                }

                const campoOP = document.getElementById('campo-op');
                if (campoOP) {
                    campoOP.focus();
                }
            }

            // Função global para executar a limpeza
            window.executarLimpeza = function() {
                const formData = new FormData();
                formData.append('limpar_tudo', '1');

                const btnConfirmar = document.getElementById('btn-confirmar-limpar');
                const btnOriginalHtml = btnConfirmar.innerHTML;
                btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Limpando...';
                btnConfirmar.disabled = true;

                fetch('etiquetas.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Erro na rede');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            window.fecharModalLimpar();
                            mostrarMensagem('✅ Todas as OPs foram removidas!', 'success');

                            const corpoTabela = document.getElementById('corpo-tabela');
                            if (corpoTabela) {
                                corpoTabela.innerHTML = '';
                                mostrarListaVazia();
                            }

                            atualizarBotoes();
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        mostrarMensagem('❌ Erro ao limpar lista. Tente novamente.', 'error');

                        btnConfirmar.innerHTML = btnOriginalHtml;
                        btnConfirmar.disabled = false;
                        window.fecharModalLimpar();
                    });
            }

            // ===== FUNÇÕES DE ADICIONAR OP =====
            if (campoOP) {
                campoOP.focus();

                btnAdicionar.addEventListener('click', function() {
                    adicionarOP();
                });

                campoOP.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        adicionarOP();
                    }
                });
            }

            // ===== FUNÇÕES DE IMPRESSÃO =====
            if (btnImprimir) {
                btnImprimir.addEventListener('click', function(e) {
                    e.preventDefault();

                    const totalLinhas = document.querySelectorAll('#corpo-tabela tr:not(#linha-vazia)').length;
                    if (totalLinhas === 0) {
                        mostrarMensagem('Nenhuma OP para imprimir!', 'error');
                        return;
                    }

                    const guiaImpressao = window.open('etiquetas_imp.php', '_blank');

                    if (guiaImpressao) {
                        guiaImpressao.focus();
                    } else {
                        alert('Permita popups para este site para melhor experiência de impressão.\n\nClique em OK para abrir em nova guia mesmo assim.');
                        window.location.href = 'etiquetas_imp.php';
                    }
                });
            }

            // ===== VALIDAÇÃO DE QUANTIDADE =====
            if (campoQuantidade) {
                campoQuantidade.addEventListener('change', function() {
                    let value = parseInt(this.value);
                    if (isNaN(value) || value < 1) {
                        this.value = 1;
                    } else if (value > 100) {
                        this.value = 100;
                    }
                });

                campoQuantidade.addEventListener('input', function() {
                    let value = parseInt(this.value);
                    if (isNaN(value) || value < 1) {
                        this.value = '';
                    } else if (value > 100) {
                        this.value = 100;
                    }
                });
            }

            // ===== AUTO-FECHAR MENSAGENS DA SESSÃO =====
            setTimeout(function() {
                const mensagens = document.querySelectorAll('.mensagem-flutuante');
                mensagens.forEach(msg => {
                    msg.style.animation = 'fadeOut 0.5s ease-out';
                    setTimeout(() => {
                        if (msg.parentNode) {
                            msg.parentNode.removeChild(msg);
                        }
                    }, 500);
                });
            }, 3000);

            // ===== INICIALIZAR BOTÕES =====
            atualizarBotoes();
        });

        // ========== FUNÇÕES GLOBAIS ==========

        // Função para mostrar mensagem flutuante
        function mostrarMensagem(mensagem, tipo = 'info') {
            const mensagensAntigas = document.querySelectorAll('.mensagem-ajax');
            mensagensAntigas.forEach(msg => msg.remove());

            const divMensagem = document.createElement('div');
            divMensagem.className = `mensagem-ajax ${tipo}`;
            divMensagem.textContent = mensagem;
            divMensagem.style.position = 'fixed';
            divMensagem.style.top = '94px';
            divMensagem.style.right = '20px';
            divMensagem.style.zIndex = '9999';

            document.body.appendChild(divMensagem);

            setTimeout(() => {
                divMensagem.style.animation = 'fadeOut 0.5s ease-out';
                setTimeout(() => {
                    if (divMensagem.parentNode) {
                        divMensagem.parentNode.removeChild(divMensagem);
                    }
                }, 500);
            }, 3000);
        }

        // Função para adicionar OP via AJAX
        function adicionarOP() {
            const campoOP = document.getElementById('campo-op');
            const campoQuantidade = document.getElementById('campo-quantidade');
            const btnAdicionar = document.getElementById('btn-adicionar');

            if (!campoOP.value.trim()) {
                mostrarMensagem('Digite o número da OP!', 'error');
                campoOP.focus();
                return;
            }

            const btnOriginalHtml = btnAdicionar.innerHTML;
            btnAdicionar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            btnAdicionar.disabled = true;

            const formData = new FormData();
            formData.append('adicionar', '1');
            formData.append('op', campoOP.value.trim());
            formData.append('quantidade', campoQuantidade.value);

            fetch('etiquetas.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro na rede');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        mostrarMensagem(data.message, data.type);

                        if (data.op_data) {
                            adicionarLinhaTabela(data.op_data, data.total_ops);
                        }

                        campoOP.value = '';
                        campoQuantidade.value = '1';
                        campoOP.focus();
                    } else {
                        mostrarMensagem(data.message, data.type);
                    }

                    btnAdicionar.innerHTML = btnOriginalHtml;
                    btnAdicionar.disabled = false;
                    atualizarBotoes();
                })
                .catch(error => {
                    console.error('Erro:', error);
                    mostrarMensagem('Erro ao adicionar OP. Tente novamente.', 'error');
                    btnAdicionar.innerHTML = btnOriginalHtml;
                    btnAdicionar.disabled = false;
                });
        }

        // Função para adicionar linha na tabela
        function adicionarLinhaTabela(opData, totalOps) {
            const corpoTabela = document.getElementById('corpo-tabela');
            const linhaVazia = document.getElementById('linha-vazia');

            if (linhaVazia) {
                linhaVazia.remove();
            }

            const loteClass = opData.lote && (opData.lote.includes('NÃO DEFINIDO') || opData.lote.includes('NÃO INFORMADO')) ? 'dado-nulo' : '';
            const cidadeClass = opData.cidade && opData.cidade.includes('NÃO INFORMADA') ? 'dado-nulo' : '';

            const novaLinha = document.createElement('tr');
            novaLinha.id = `linha-${opData.id}`;
            novaLinha.dataset.opId = opData.id;

            novaLinha.innerHTML = `
            <td class="col-id">${String(totalOps).padStart(3, '0')}</td>
            <td class="col-op-numero"><strong>${escapeHtml(String(opData.seqop))}</strong></td>
            <td class="col-quantidade">
                <span class="quantidade-badge" title="${opData.quantidade} cópias">
                    ${opData.quantidade}
                </span>
            </td>
            <td class="col-cod-produto">${escapeHtml(String(opData.cod_produto))}</td>
            <td class="col-lote ${loteClass}">${escapeHtml(opData.lote)}</td>
            <td class="col-produto preserve-special-chars">
                <div class="texto-completo" title="${escapeHtml(opData.produto)}">${escapeHtml(opData.produto)}</div>
            </td>
            <td class="col-cliente preserve-special-chars">
                <div class="texto-completo" title="${escapeHtml(opData.nome_cliente)}">${escapeHtml(opData.nome_cliente)}</div>
            </td>
            <td class="col-cidade ${cidadeClass}">${escapeHtml(opData.cidade)}</td>
            <td class="col-acoes">
                <div class="acoes-container">
                    <button type="button" class="btn-acao btn-remover" 
                        onclick="removerOP('${opData.id}', '${escapeHtml(String(opData.seqop))}', '${escapeHtml(String(opData.cod_produto))}', ${opData.quantidade})"
                        title="Remover OP">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        `;

            corpoTabela.appendChild(novaLinha);
            atualizarNumerosLinhas();
        }

        // Função para remover OP via AJAX
        window.removerOP = function(opId, seqop, codProduto, quantidade) {
            if (confirm(`Remover esta OP?\n\nOP: ${seqop}\nProduto: ${codProduto}\nQuantidade: ${quantidade} cópias`)) {
                const formData = new FormData();
                formData.append('remover', '1');
                formData.append('op_id', opId);

                fetch('etiquetas.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Erro na rede');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            mostrarMensagem('OP removida com sucesso!', 'success');

                            const linha = document.getElementById(`linha-${opId}`);
                            if (linha) {
                                linha.remove();
                            }

                            atualizarNumerosLinhas();

                            if (data.total_ops === 0) {
                                mostrarListaVazia();
                            }

                            atualizarBotoes();
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        mostrarMensagem('Erro ao remover OP.', 'error');
                    });
            }
        }

        // Função para mostrar lista vazia
        function mostrarListaVazia() {
            const corpoTabela = document.getElementById('corpo-tabela');
            if (corpoTabela) {
                corpoTabela.innerHTML = `
                <tr id="linha-vazia">
                    <td colspan="9" style="border: none;">
                        <div class="lista-vazia">
                            <div class="lista-vazia-icon">📋</div>
                            <h3>Nenhuma OP cadastrada</h3>
                            <p>Adicione uma OP digitando o número no campo acima</p>
                        </div>
                    </td>
                </tr>
            `;
            }
        }

        // Função para atualizar números das linhas
        function atualizarNumerosLinhas() {
            const linhas = document.querySelectorAll('#corpo-tabela tr:not(#linha-vazia)');
            linhas.forEach((linha, index) => {
                const celulaNumero = linha.querySelector('.col-id');
                if (celulaNumero) {
                    celulaNumero.textContent = String(index + 1).padStart(3, '0');
                }
            });
        }

        // Função para atualizar estado dos botões
        function atualizarBotoes() {
            const btnLimparTudo = document.getElementById('btn-limpar-tudo');
            const btnImprimir = document.getElementById('btn-imprimir');
            const totalLinhas = document.querySelectorAll('#corpo-tabela tr:not(#linha-vazia)').length;

            if (btnLimparTudo) {
                btnLimparTudo.disabled = totalLinhas === 0;
            }

            if (btnImprimir) {
                let totalCopias = 0;
                document.querySelectorAll('.quantidade-badge').forEach(badge => {
                    const qtd = parseInt(badge.textContent) || 1;
                    totalCopias += qtd;
                });

                if (totalLinhas > 0) {
                    btnImprimir.innerHTML = `<i class="fa fa-print" aria-hidden="true"></i> Imprimir (${totalCopias} cópias)`;
                    btnImprimir.style.display = 'inline-flex';
                } else {
                    btnImprimir.style.display = 'none';
                }
            }
        }

        // Função para escapar HTML
        function escapeHtml(text) {
            if (!text) return '';
            if (typeof text !== 'string') {
                text = String(text);
            }
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Inicializar altura da tabela
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 100);
    </script>
</body>

</html>