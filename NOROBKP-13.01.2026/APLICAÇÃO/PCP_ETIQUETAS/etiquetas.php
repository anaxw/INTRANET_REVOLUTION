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

function corrigirCaracteresEspeciais($texto) {
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
function buscarDadosOP(PDO $pdo, $opNumero)
{
    $sql = "
        SELECT FIRST 1
            o.seqop,
            CAST(pi.produto AS INTEGER) AS cod_produto,
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
        INNER JOIN arqes13 pd ON pd.pedido = o.pedido
        INNER JOIN arqcad c   ON c.codic = pd.codic AND c.tipoc = 'C'
        INNER JOIN arqes15 pi ON pi.pedido = pd.pedido
        INNER JOIN arqes01 pr ON pr.codigo = pi.produto
        LEFT JOIN vd_obs a    ON a.codobs = pi.pedido 
                              AND a.tipo = 'J' 
                              AND a.tipoc = 'I'
        LEFT JOIN cidades cid ON cid.seqcidade = c.seqcidade
        WHERE o.seqop = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$opNumero]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    // Converter todos os campos para UTF-8 e corrigir caracteres especiais
    if ($resultado) {
        foreach ($resultado as $key => &$value) {
            if (is_string($value)) {
                $value = converterParaUtf8($value);
                $value = corrigirCaracteresEspeciais($value);
                
                // Decodificar entidades HTML se houver
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
                        'id' => $_SESSION['ops'][count($_SESSION['ops'])-1]['id'],
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
    <style>
        /* Configuração UTF-8 para CSS */
        @charset "UTF-8";
        
        /* Garantir que todos os elementos herdem UTF-8 */
        body, table, td, th, input, button, textarea, select, div, span, p, h1, h2, h3, h4, h5, h6 {
            font-family: 'Segoe UI', 'Arial Unicode MS', 'DejaVu Sans', sans-serif;
        }
        
        /* Estilos adicionais para o botão limpar tudo */
        .btn-limpar-tudo {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 5px rgba(231, 76, 60, 0.3);
            margin-left: 10px;
        }

        .btn-limpar-tudo:hover {
            background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.4);
        }

        .btn-limpar-tudo:active {
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(231, 76, 60, 0.3);
        }

        .btn-limpar-tudo:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .formulario-botoes {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Estilos para o campo de quantidade */
        .quantidade-container {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .campo-quantidade {
            width: 80px;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            background: #fff;
            transition: all 0.3s ease;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .campo-quantidade:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .campo-quantidade::-webkit-inner-spin-button,
        .campo-quantidade::-webkit-outer-spin-button {
            opacity: 1;
            height: 30px;
        }

        .quantidade-label {
            font-size: 12px;
            color: #666;
            font-weight: 600;
            margin-bottom: 2px;
            white-space: nowrap;
        }

        .col-quantidade {
            width: 80px;
            text-align: center;
        }

        .quantidade-badge {
            display: inline-block;
            background: #fdb525;
            color: white;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            min-width: 24px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2);
        }

        /* Estilos para quebra de linha nas colunas Cliente e Produto */
        .col-cliente,
        .col-produto {
            white-space: normal !important;
            word-wrap: break-word !important;
            word-break: break-word !important;
            max-width: 250px;
        }

        /* Garantir que o texto quebre linha e aceite caracteres especiais */
        .texto-completo {
            white-space: normal !important;
            word-wrap: break-word !important;
            word-break: break-word !important;
            display: block;
            font-family: inherit;
            unicode-bidi: embed;
        }

        /* Garantir que a tabela mantenha layout */
        table {
            table-layout: fixed;
            width: 100%;
        }

        .col-id {
            width: 40px;
        }

        .col-op-numero {
            width: 60px;
        }

        .col-quantidade {
            width: 60px;
        }

        .col-cod-produto {
            width: 70px;
        }

        .col-lote {
            width: 120px;
        }

        .col-produto {
            width: 250px;
        }

        .col-cliente {
            width: 200px;
        }

        .col-cidade {
            width: 150px;
        }

        .col-acoes {
            width: 70px;
        }

        /* Ajustes para garantir que caracteres especiais sejam exibidos */
        .preserve-special-chars {
            font-family: 'Segoe UI', 'Arial Unicode MS', 'DejaVu Sans', 'Noto Sans', sans-serif;
        }

        /* Estilo para dados nulos */
        .dado-nulo {
            color: #e74c3c !important;
            font-style: italic !important;
        }

        /* Estilo para mensagens AJAX */
        .mensagem-ajax {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease-out;
            max-width: 400px;
        }

        .mensagem-ajax.success {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
        }

        .mensagem-ajax.error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        .mensagem-ajax.warning {
            background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);
        }

        .mensagem-ajax.info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }

        @media (max-width: 768px) {
            .formulario-botoes {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .btn-limpar-tudo {
                margin-left: 0;
                width: 100%;
            }

            .quantidade-container {
                width: 100%;
                justify-content: center;
            }

            .campo-quantidade {
                width: 100px;
            }

            /* Ajustes para mobile nas colunas */
            .col-cliente,
            .col-produto {
                max-width: 150px !important;
            }

            /* Para mobile, usar layout automático */
            table {
                table-layout: auto;
            }
            
            .mensagem-ajax {
                top: 10px;
                right: 10px;
                left: 10px;
                max-width: none;
            }
        }
    </style>
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
                <?php if ($totalOps > 0): ?>
                    <?php
                    // Calcular total de cópias
                    $totalCopias = 0;
                    foreach ($_SESSION['ops'] as $op) {
                        $totalCopias += $op['quantidade'] ?? 1;
                    }
                    ?>
                    <!-- MODIFICAÇÃO: Usar target blank mas com script para fechar depois -->
                    <a href="etiquetas_imp.php" class="btn-imprimir" id="btn-imprimir" target="_blank">
                        <i class="fa fa-print" aria-hidden="true"></i>
                        Imprimir (<?php echo $totalCopias; ?> cópias)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- CONTÊINER DA TABELA -->
        <div class="container-tabela">
            <div class="titulo-tabela">
                Sistema de Etiquetas - Ordens de Produção
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
                            <th class="col-lote">LOTE</th>
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
                                        <div class="lista-vazia-icon">📝</div>
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
            divMensagem.style.top = '20px';
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