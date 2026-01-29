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

session_start();

// Garantir que a sessão também use UTF-8
if (isset($_SESSION['ops'])) {
    array_walk_recursive($_SESSION['ops'], function (&$value) {
        if (is_string($value)) {
            // Garantir UTF-8 na sessão
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            }
        }
    });
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

    // Firebird não suporta SET NAMES, o charset já está definido no DSN
    // Não executar SET NAMES

} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// ================= FUNÇÕES DE CONVERSÃO =================
function converterParaUtf8($texto)
{
    if ($texto === null || $texto === '' || !is_string($texto)) {
        return $texto;
    }

    // Se já for UTF-8 válido, retorna como está
    if (mb_check_encoding($texto, 'UTF-8') && mb_detect_encoding($texto, 'UTF-8', true) !== false) {
        return $texto;
    }

    // Tentar detectar a codificação
    $encodings = ['Windows-1252', 'ISO-8859-1', 'CP1252', 'ASCII'];
    $detected = mb_detect_encoding($texto, $encodings, true);

    if ($detected && $detected !== 'UTF-8') {
        // Converter da codificação detectada para UTF-8
        $converted = mb_convert_encoding($texto, 'UTF-8', $detected);

        // Verificar se a conversão foi bem sucedida
        if (mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    // Fallback: Windows-1252 (comum em Firebird brasileiro)
    $converted = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');

    // Remover caracteres inválidos
    $converted = mb_convert_encoding($converted, 'UTF-8', 'UTF-8');

    return $converted;
}

function corrigirCaracteresEspeciais($texto)
{
    if (empty($texto) || !is_string($texto)) return $texto;

    // Primeiro garantir UTF-8
    $texto = converterParaUtf8($texto);

    // Correções manuais para problemas comuns
    $problemasComuns = [
        // Problemas de dupla codificação
        'Ã§' => 'ç',
        'Ã£' => 'ã',
        'Ã¡' => 'á',
        'Ã©' => 'é',
        'Ã­' => 'í',
        'Ã³' => 'ó',
        'Ãº' => 'ú',
        'Ãª' => 'ê',
        'Ã´' => 'ô',
        'Ã ' => 'à',
        'Ãµ' => 'õ',
        'Ã¢' => 'â',
        'Ã¨' => 'è',
        'Ã¼' => 'ü',
        'Ã±' => 'ñ',

        // Maiúsculas
        'Ã‡' => 'Ç',
        'Ãƒ' => 'Ã',
        'Ã' => 'Á',
        'Ã‰' => 'É',
        'Ã' => 'Í',
        'Ã“' => 'Ó',
        'Ãš' => 'Ú',
        'ÃŠ' => 'Ê',
        'Ã”' => 'Ô',
        'Ãƒ' => 'À',
        'Ã•' => 'Õ',
        'Ã‚' => 'Â',

        // Outros símbolos
        'â‚¬' => '€',
        'â€š' => '‚',
        'â€ž' => '„',
        'â€¦' => '…',
        'â€¡' => '‡',
        'â€°' => '‰',
        'â€¹' => '‹',
        'â€˜' => '‘',
        'â€™' => '’',
        'â€œ' => '“',
        'â€' => '”',
        'â€¢' => '•',
        'â€“' => '–',
        'â€”' => '—',
        'â„¢' => '™',
        'â€º' => '›',
    ];

    foreach ($problemasComuns as $errado => $correto) {
        $texto = str_replace($errado, $correto, $texto);
    }

    return $texto;
}

// ================= NÃO LIMPAR OPs AO RECARREGAR =================
// Removido: As OPs só serão limpas quando o usuário solicitar

// ================= FUNÇÃO BUSCAR OP =================
// ================= FUNÇÃO BUSCAR OP (ATUALIZADA) =================
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

    // Se não encontrou, tentar uma busca alternativa
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

    // Se ainda não encontrou, tentar uma busca mais simples
    if (!$resultado) {
        $sql_simples = "
            SELECT
                o.seqop,
                CAST(p.codacessog AS INTEGER) AS cod_produto

                COALESCE(NULLIF(a.obs1, ''), pr.nome) AS produto_nome,
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
                        o.dev_seq
                    ELSE 'LOTE NÃO DEFINIDO'
                END AS lote
            FROM op_lancamento o
            INNER JOIN arqes15 pr ON pr.seqlanc = o.seqitem
            INNER JOIN arqes13 pd ON pd.pedido = pr.pedido
            INNER JOIN arqes01 p ON p.codigo = pr.produto
            INNER JOIN arqcad c ON c.codic = pd.codic AND c.tipoc = 'C'
            LEFT JOIN vd_obs a ON a.codobs = pr.pedido 
                AND a.tipo = 'J' 
                AND a.tipoc = 'I'
            LEFT JOIN cidades cid ON cid.seqcidade = c.seqcidade
            WHERE o.seqop = ?
        ";

        $stmt = $pdo->prepare($sql_simples);
        $stmt->execute([$opNumero]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Se encontrou dados, processar o lote para garantir formato correto
    if ($resultado && isset($resultado['lote'])) {
        // Verificar se o lote está no formato correto
        $lote = $resultado['lote'];
        if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d+$/', $lote)) {
            // Formatar o dev_seq para ter 2 dígitos se necessário
            $parts = explode(' ', $lote);
            if (count($parts) === 2) {
                $dataPart = $parts[0];
                $seqPart = $parts[1];
                $seqPart = str_pad($seqPart, 2, '0', STR_PAD_LEFT);
                $resultado['lote'] = $dataPart . ' ' . $seqPart;
            }
        }
    }

    // Converter todos os campos para UTF-8 e corrigir caracteres especiais
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

// ================= PROCESSAMENTO POST (MODIFICADO PARA AJAX) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar se é uma requisição AJAX
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!isset($_SESSION['ops'])) {
        $_SESSION['ops'] = [];
    }

    /* ---- ADICIONAR OP ---- */
    if (isset($_POST['adicionar']) && !empty($_POST['op'])) {

        $opNumero = trim($_POST['op']);
        $quantidade = isset($_POST['quantidade']) ? (int)$_POST['quantidade'] : 1;

        // Validar quantidade
        if ($quantidade < 1) {
            $quantidade = 1;
        }
        if ($quantidade > 100) {
            $quantidade = 100;
        }

        $dados = buscarDadosOP($pdo, $opNumero);

        if ($dados) {
            // Verificar se já existe no array
            foreach ($_SESSION['ops'] as $op) {
                if ($op['seqop'] == $dados['seqop']) {
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

            // Processar os dados para garantir UTF-8
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
        $_SESSION['ops'] = array_values(array_filter(
            $_SESSION['ops'],
            fn($op) => $op['id'] !== $_POST['op_id']
        ));

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

    // Se não for AJAX e chegou até aqui, redirecionar normalmente
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
</head>

<body>
    <div class="container-principal">
        <!-- CABEÇALHO -->
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

            <!-- MENSAGENS DA SESSÃO (para quando não é AJAX) -->
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
                <!-- Botão sempre presente, apenas controlamos visibilidade -->
                <a href="etiquetas_imp.php" class="btn-imprimir" id="btn-imprimir" target="_blank" style="display: none;">
                    <i class="fa fa-print" aria-hidden="true"></i>
                    Imprimir (<span id="total-copias">0</span> cópias)
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
                <!-- Formulário para adicionar OP (agora com AJAX) -->
                <form method="POST" class="formulario-botoes" id="form-adicionar" accept-charset="UTF-8" onsubmit="return false;">
                    <input type="hidden" name="charset" value="UTF-8">

                    <input type="text"
                        name="op"
                        id="campo-op"
                        class="campo-op"
                        placeholder="Digite o número da OP..."
                        autocomplete="off"
                        autofocus>

                    <!-- Container para quantidade -->
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

                <!-- Formulário separado para limpar tudo (também com AJAX) -->
                <form method="POST" id="form-limpar-tudo" style="display: inline;" accept-charset="UTF-8">
                    <input type="hidden" name="charset" value="UTF-8">
                    <button type="submit" name="limpar_tudo" class="btn-limpar-tudo" id="btn-limpar-tudo"
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
                            <!-- Coluna Data oculta -->
                            <th class="col-data" style="display: none;">Data</th>
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
                                // Define classes CSS para dados nulos
                                $loteClass = (isset($op['lote']) &&
                                    ($op['lote'] == 'LOTE NÃO DEFINIDO' ||
                                        $op['lote'] == 'LOTE NÃO INFORMADO')) ? 'dado-nulo' : '';

                                $cidadeClass = (isset($op['cidade']) &&
                                    ($op['cidade'] == 'CIDADE NÃO INFORMADA - UF NÃO INFORMADA' ||
                                        strpos($op['cidade'], 'NÃO INFORMADA') !== false)) ? 'dado-nulo' : '';

                                // Preparar dados para exibição - já convertidos para UTF-8
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
                                            <?php
                                            // Exibir diretamente - já está em UTF-8
                                            echo htmlspecialchars($produtoTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                            ?>
                                        </div>
                                    </td>
                                    <td class="col-cliente preserve-special-chars">
                                        <div class="texto-completo">
                                            <?php
                                            echo htmlspecialchars($clienteTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                            ?>
                                        </div>
                                    </td>
                                    <td class="col-cidade <?php echo $cidadeClass; ?>">
                                        <?php echo htmlspecialchars($cidadeTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    </td>
                                    <!-- Coluna Data oculta -->
                                    <td class="col-data" style="display: none;"><?php echo $op['data'] ?? ''; ?></td>
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

    <script>
        // Configurar UTF-8 no JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Configurar charset para formulários
            document.querySelectorAll('form').forEach(form => {
                form.setAttribute('accept-charset', 'UTF-8');
            });

            const campoOP = document.getElementById('campo-op');
            const campoQuantidade = document.getElementById('campo-quantidade');
            const btnAdicionar = document.getElementById('btn-adicionar');
            const formLimparTudo = document.getElementById('form-limpar-tudo');
            const btnLimparTudo = document.getElementById('btn-limpar-tudo');
            const btnImprimir = document.getElementById('btn-imprimir');
            const corpoTabela = document.getElementById('corpo-tabela');

            if (campoOP) {
                campoOP.focus();

                // Adicionar OP com AJAX
                btnAdicionar.addEventListener('click', function() {
                    adicionarOP();
                });

                // Permitir Enter para adicionar
                campoOP.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        adicionarOP();
                    }
                });
            }

            // Configurar formulário de limpar tudo para AJAX
            if (formLimparTudo) {
                formLimparTudo.addEventListener('submit', function(e) {
                    e.preventDefault();
                    limparTodasOPs();
                });
            }

            // Configurar botão de imprimir para abrir em nova janela e monitorar
            if (btnImprimir) {
                btnImprimir.addEventListener('click', function(e) {
                    // Abrir em nova janela (não guia)
                    const janelaImpressao = window.open('etiquetas_imp.php', 'impressao_etiquetas',
                        'width=800,height=600,left=100,top=100,toolbar=no,location=no,status=no,menubar=no,scrollbars=yes');

                    // Tentar monitorar se a janela foi fechada
                    if (janelaImpressao) {
                        // Focar na janela original
                        window.focus();

                        // Verificar periodicamente se a janela ainda está aberta
                        const verificarJanela = setInterval(function() {
                            if (janelaImpressao.closed) {
                                clearInterval(verificarJanela);
                                // A janela foi fechada, podemos fazer algo se necessário
                            }
                        }, 1000);
                    }
                });
            }

            // Validar campo quantidade
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

            // Atualizar botões baseado no número de OPs
            atualizarBotoes();
        });

        // Função para mostrar mensagem
        function mostrarMensagem(mensagem, tipo = 'info') {
            // Remover mensagens anteriores
            const mensagensAntigas = document.querySelectorAll('.mensagem-ajax');
            mensagensAntigas.forEach(msg => msg.remove());

            // Criar nova mensagem
            const divMensagem = document.createElement('div');
            divMensagem.className = `mensagem-ajax ${tipo}`;
            divMensagem.textContent = mensagem;
            divMensagem.style.position = 'fixed';
            divMensagem.style.top = '94px';
            divMensagem.style.right = '20px';
            divMensagem.style.zIndex = '9999';

            document.body.appendChild(divMensagem);

            // Remover após 3 segundos
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

            // Desabilitar botão para evitar duplo clique
            const btnOriginalHtml = btnAdicionar.innerHTML;
            btnAdicionar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            btnAdicionar.disabled = true;

            // Criar FormData
            const formData = new FormData();
            formData.append('adicionar', '1');
            formData.append('op', campoOP.value.trim());
            formData.append('quantidade', campoQuantidade.value);

            // Enviar via AJAX
            fetch('etiquetas.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Mostrar mensagem de sucesso
                        mostrarMensagem(data.message, data.type);

                        // Atualizar a tabela
                        if (data.op_data) {
                            adicionarLinhaTabela(data.op_data, data.total_ops);
                        }

                        // Limpar campos
                        campoOP.value = '';
                        campoQuantidade.value = '1';
                        campoOP.focus();
                    } else {
                        // Mostrar mensagem de erro
                        mostrarMensagem(data.message, data.type);
                    }

                    // Reativar botão
                    btnAdicionar.innerHTML = btnOriginalHtml;
                    btnAdicionar.disabled = false;

                    // Atualizar botões
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

            // Remover linha "lista vazia" se existir
            if (linhaVazia) {
                linhaVazia.remove();
            }

            // Determinar classes para dados nulos
            const loteClass = opData.lote.includes('NÃO DEFINIDO') || opData.lote.includes('NÃO INFORMADO') ? 'dado-nulo' : '';
            const cidadeClass = opData.cidade.includes('NÃO INFORMADA') ? 'dado-nulo' : '';

            // Criar nova linha
            const novaLinha = document.createElement('tr');
            novaLinha.id = `linha-${opData.id}`;
            novaLinha.dataset.opId = opData.id;

            const numeroLinha = totalOps; // Já é o total atualizado

            novaLinha.innerHTML = `
                <td class="col-id">${String(numeroLinha).padStart(3, '0')}</td>
                <td class="col-op-numero"><strong>${escapeHtml(opData.seqop)}</strong></td>
                <td class="col-quantidade">
                    <span class="quantidade-badge" title="${opData.quantidade} cópias">
                        ${opData.quantidade}
                    </span>
                </td>
                <td class="col-cod-produto">${escapeHtml(opData.cod_produto)}</td>
                <td class="col-lote ${loteClass}">${escapeHtml(opData.lote)}</td>
                <td class="col-produto preserve-special-chars">
                    <div class="texto-completo">${escapeHtml(opData.produto)}</div>
                </td>
                <td class="col-cliente preserve-special-chars">
                    <div class="texto-completo">${escapeHtml(opData.nome_cliente)}</div>
                </td>
                <td class="col-cidade ${cidadeClass}">${escapeHtml(opData.cidade)}</td>
                <td class="col-data" style="display: none;">${new Date().toLocaleString('pt-BR')}</td>
                <td class="col-acoes">
                    <div class="acoes-container">
                        <button type="button" class="btn-acao btn-remover" 
                            onclick="removerOP('${opData.id}', '${escapeHtml(opData.seqop.toString())}', '${escapeHtml(opData.cod_produto.toString())}', ${opData.quantidade})"
                            title="Remover OP">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;

            corpoTabela.appendChild(novaLinha);

            // Atualizar números das linhas existentes
            atualizarNumerosLinhas();
        }

        // Função para remover OP via AJAX
        function removerOP(opId, seqop, codProduto, quantidade) {
            if (!confirm(`Remover esta OP?\n\nOP: ${seqop}\nProduto: ${codProduto}\nQuantidade: ${quantidade} cópias`)) {
                return;
            }

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
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        mostrarMensagem('OP removida com sucesso!', 'success');

                        // Remover linha da tabela
                        const linha = document.getElementById(`linha-${opId}`);
                        if (linha) {
                            linha.remove();
                        }

                        // Atualizar números das linhas
                        atualizarNumerosLinhas();

                        // Se não houver mais OPs, mostrar mensagem de lista vazia
                        if (data.total_ops === 0) {
                            mostrarListaVazia();
                        }

                        // Atualizar botões
                        atualizarBotoes();
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    mostrarMensagem('Erro ao remover OP.', 'error');
                });
        }

        // Função para limpar todas as OPs via AJAX
        function limparTodasOPs() {
            if (!confirm('Tem certeza que deseja remover TODAS as OPs da lista?\n\nEsta ação não pode ser desfeita.')) {
                return;
            }

            const formData = new FormData();
            formData.append('limpar_tudo', '1');

            fetch('etiquetas.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        mostrarMensagem(data.message, 'info');

                        // Limpar todas as linhas da tabela
                        const corpoTabela = document.getElementById('corpo-tabela');
                        corpoTabela.innerHTML = '';

                        // Mostrar mensagem de lista vazia
                        mostrarListaVazia();

                        // Atualizar botões
                        atualizarBotoes();
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    mostrarMensagem('Erro ao limpar lista.', 'error');
                });
        }

        // Função para mostrar lista vazia
        function mostrarListaVazia() {
            const corpoTabela = document.getElementById('corpo-tabela');
            corpoTabela.innerHTML = `
                <tr id="linha-vazia">
                    <td colspan="9" style="border: none;">
                        <div class="lista-vazia">
                            <div class="lista-vazia-icon">📝</div>
                            <h3>Nenhuma OP cadastrada</h3>
                        </div>
                    </td>
                </tr>
            `;
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

            // Atualizar botão Limpar Tudo
            if (btnLimparTudo) {
                btnLimparTudo.disabled = totalLinhas === 0;
            }

            // Atualizar botão Imprimir
            if (btnImprimir) {
                // Calcular total de cópias
                let totalCopias = 0;
                document.querySelectorAll('.quantidade-badge').forEach(badge => {
                    const qtd = parseInt(badge.textContent) || 1;
                    totalCopias += qtd;
                });

                // Atualizar texto do botão
                if (totalLinhas > 0) {
                    btnImprimir.innerHTML = `<i class="fa fa-print" aria-hidden="true"></i> Imprimir (${totalCopias} cópias)`;
                    btnImprimir.style.display = 'inline-flex';
                } else {
                    btnImprimir.style.display = 'none';
                }
            }
        }

        // Função para escapar HTML (segurança)
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-fechar mensagens da sessão (se houver)
        setTimeout(function() {
            const mensagens = document.querySelectorAll('.mensagem-flutuante');
            mensagens.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 3000);

        // Ajustar tabela quando a janela for redimensionada
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

        // Teste de caracteres especiais
        console.log('Caracteres especiais testados: á é í ó ú ç ã õ â ê ô à ü');
    </script>
</body>

</html>