<?php
session_start();
session_destroy();
session_start();

ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

header('Content-Type: text/html; charset=UTF-8');
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'portuguese');
date_default_timezone_set('America/Sao_Paulo');

// ================= CONEXÕES COM BANCOS DE DADOS =================
// Configurações Firebird
$config_firebird = [
    'dsn' => 'firebird:dbname=192.168.1.209:c:/BD/ARQSIST.FDB;charset=UTF8',
    'user' => 'SYSDBA',
    'pass' => 'masterkey'
];

// Configurações SQL Server
$config_sqlserver = [
    'server' => "LANTEK\\LANTEK",
    'database' => "Noroaço",
    'username' => "sa",
    'password' => "OQqt1WAhe3KK6eOqN4V"
];

// Conectar ao Firebird
try {
    $pdo_firebird = new PDO($config_firebird['dsn'], $config_firebird['user'], $config_firebird['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE    => PDO::CASE_LOWER,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
} catch (PDOException $e) {
    die("Erro na conexão Firebird: " . $e->getMessage());
}

// Conectar ao SQL Server
try {
    $pdo_sqlserver = new PDO(
        "sqlsrv:Server={$config_sqlserver['server']};Database={$config_sqlserver['database']}",
        $config_sqlserver['username'],
        $config_sqlserver['password']
    );
    $pdo_sqlserver->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão SQL Server: " . $e->getMessage());
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
    ];

    foreach ($problemasComuns as $errado => $correto) {
        $texto = str_replace($errado, $correto, $texto);
    }

    return $texto;
}

// ================= FUNÇÃO BUSCAR DADOS CRUZADOS =================
function buscarDadosCruzados($pdo_firebird, $pdo_sqlserver, $opNumero)
{
    $resultado = [];
    
    // Buscar dados do Firebird
    try {
        $sql_firebird = "
            SELECT
                o.seqop,
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
        
        $stmt_firebird = $pdo_firebird->prepare($sql_firebird);
        $stmt_firebird->execute([$opNumero]);
        $dados_firebird = $stmt_firebird->fetch(PDO::FETCH_ASSOC);
        
        if ($dados_firebird) {
            $resultado['seqop'] = $opNumero;
            $resultado['nome_cliente'] = isset($dados_firebird['nome_cliente']) ? 
                corrigirCaracteresEspeciais($dados_firebird['nome_cliente']) : 
                'CLIENTE NÃO INFORMADO';
            $resultado['cidade'] = isset($dados_firebird['cidade']) ? 
                corrigirCaracteresEspeciais($dados_firebird['cidade']) : 
                'CIDADE NÃO INFORMADA - UF NÃO INFORMADA';
            $resultado['lote'] = isset($dados_firebird['lote']) ? 
                corrigirCaracteresEspeciais($dados_firebird['lote']) : 
                'LOTE NÃO DEFINIDO';
        } else {
            return null;
        }
        
    } catch (PDOException $e) {
        return null;
    }
    
    // Buscar dados do SQL Server
    try {
        $sql_sqlserver = "
            SELECT 
                d.DIS_UData1_Prt as pedido,
                d.DIS_UData2_Prt as OP,
                d.PrdRef as descricao_produto
            FROM dbo.PPRR_PPRR_00000100 d 
            WHERE d.DIS_UData2_Prt = ?
        ";
        
        $stmt_sqlserver = $pdo_sqlserver->prepare($sql_sqlserver);
        $stmt_sqlserver->execute([$opNumero]);
        $dados_sqlserver = $stmt_sqlserver->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($dados_sqlserver)) {
            $dado = $dados_sqlserver[0];
            $resultado['pedido'] = isset($dado['pedido']) ? $dado['pedido'] : 'NÃO INFORMADO';
            $resultado['descricao_produto'] = isset($dado['descricao_produto']) ? 
                corrigirCaracteresEspeciais($dado['descricao_produto']) : 
                'DESCRIÇÃO NÃO ENCONTRADA';
            
            if (count($dados_sqlserver) > 1) {
                $descricoes = [];
                foreach ($dados_sqlserver as $dado) {
                    if (isset($dado['descricao_produto']) && !empty($dado['descricao_produto'])) {
                        $descricao = corrigirCaracteresEspeciais($dado['descricao_produto']);
                        if (!in_array($descricao, $descricoes)) {
                            $descricoes[] = $descricao;
                        }
                    }
                }
                if (!empty($descricoes)) {
                    $resultado['descricao_produto'] = implode(' / ', $descricoes);
                }
            }
        } else {
            return null;
        }
        
    } catch (PDOException $e) {
        return null;
    }
    
    return $resultado;
}

// ================= PROCESSAMENTO POST =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!isset($_SESSION['ops_cruzadas'])) {
        $_SESSION['ops_cruzadas'] = [];
    }

    /* ---- ADICIONAR OP CRUZADA ---- */
    if (isset($_POST['adicionar']) && !empty($_POST['op'])) {
        $opNumero = trim($_POST['op']);
        $quantidade = isset($_POST['quantidade']) ? (int)$_POST['quantidade'] : 1;

        if ($quantidade < 1) $quantidade = 1;
        if ($quantidade > 100) $quantidade = 100;

        // Verificar se já existe
        foreach ($_SESSION['ops_cruzadas'] as $op) {
            if ($op['seqop'] == $opNumero) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => "OP {$opNumero} já adicionada!",
                        'type' => 'warning'
                    ]);
                    exit;
                } else {
                    $_SESSION['mensagem'] = "OP {$opNumero} já adicionada!";
                    $_SESSION['tipo_mensagem'] = 'warning';
                    header("Location: " . basename(__FILE__));
                    exit;
                }
            }
        }

        $dados = buscarDadosCruzados($pdo_firebird, $pdo_sqlserver, $opNumero);

        if ($dados) {
            $dados['id'] = uniqid();
            $dados['quantidade'] = $quantidade;
            $dados['data'] = date('d/m/Y H:i:s');
            
            $_SESSION['ops_cruzadas'][] = $dados;

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => "OP {$opNumero} adicionada com sucesso! (Dados cruzados)",
                    'type' => 'success',
                    'total_ops' => count($_SESSION['ops_cruzadas']),
                    'op_data' => $dados
                ]);
                exit;
            } else {
                $_SESSION['mensagem'] = "OP {$opNumero} adicionada com sucesso!";
                $_SESSION['tipo_mensagem'] = 'success';
            }
        } else {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => "OP {$opNumero} não encontrada ou não existe em ambos os sistemas!",
                    'type' => 'error'
                ]);
                exit;
            } else {
                $_SESSION['mensagem'] = "OP {$opNumero} não encontrada ou não existe em ambos os sistemas!";
                $_SESSION['tipo_mensagem'] = 'error';
            }
        }
    }

    /* ---- REMOVER OP ---- */
    if (isset($_POST['remover'], $_POST['op_id'])) {
        $_SESSION['ops_cruzadas'] = array_values(array_filter(
            $_SESSION['ops_cruzadas'],
            fn($op) => $op['id'] !== $_POST['op_id']
        ));

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'OP removida com sucesso!',
                'total_ops' => count($_SESSION['ops_cruzadas'])
            ]);
            exit;
        }
    }

    /* ---- LIMPAR TODAS AS OPs ---- */
    if (isset($_POST['limpar_tudo'])) {
        $_SESSION['ops_cruzadas'] = [];
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
        header("Location: " . basename(__FILE__));
        exit;
    }
}

$totalOps = count($_SESSION['ops_cruzadas'] ?? []);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Sistema de Etiquetas Cruzadas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="etiquetas.css">
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
                <!-- Botão sempre presente, apenas controlamos visibilidade -->
                <a href="etiquetas_imp_plasma.php" class="btn-imprimir" id="btn-imprimir" target="_blank" style="display: none;">
                    <i class="fa fa-print" aria-hidden="true"></i>
                    Imprimir (<span id="total-copias">0</span> cópias)
                </a>
            </div>
        </div>

        <!-- CONTÊINER DA TABELA -->
        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fas fa-ticket" style="color: #fdb525; margin-right: 10px;"></i> Sistema de Etiquetas - Plasma / Laser
            </div>

            <!-- FORMULÁRIOS -->
            <div class="formulario-centralizado">
                <!-- Formulário para adicionar OP -->
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

                <!-- Formulário para limpar tudo -->
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
                            <th class="col-pedido">Pedido</th>
                            <th class="col-lote">Lote</th>
                            <th class="col-descricao">Produto (SQL Server)</th>
                            <th class="col-cliente">Cliente (Firebird)</th>
                            <th class="col-cidade">Cidade/UF (Firebird)</th>
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
                                        <p>Digite uma OP para buscar dados cruzados</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($_SESSION['ops_cruzadas'] as $index => $op): ?>
                                <?php
                                $loteClass = (isset($op['lote']) &&
                                    ($op['lote'] == 'LOTE NÃO DEFINIDO' ||
                                        $op['lote'] == 'LOTE NÃO INFORMADO')) ? 'dado-nulo' : '';

                                $cidadeClass = (isset($op['cidade']) &&
                                    ($op['cidade'] == 'CIDADE NÃO INFORMADA - UF NÃO INFORMADA' ||
                                        strpos($op['cidade'], 'NÃO INFORMADA') !== false)) ? 'dado-nulo' : '';

                                $descricaoTexto = isset($op['descricao_produto']) && !empty($op['descricao_produto']) ?
                                    $op['descricao_produto'] :
                                    'DESCRIÇÃO NÃO ENCONTRADA';

                                $clienteTexto = isset($op['nome_cliente']) && !empty($op['nome_cliente']) ?
                                    $op['nome_cliente'] :
                                    'CLIENTE NÃO INFORMADO';

                                $cidadeTexto = isset($op['cidade']) && !empty($op['cidade']) ?
                                    $op['cidade'] :
                                    'CIDADE NÃO INFORMADA';

                                $loteTexto = isset($op['lote']) && !empty($op['lote']) ?
                                    $op['lote'] :
                                    'LOTE NÃO DEFINIDO';

                                $pedidoTexto = isset($op['pedido']) && !empty($op['pedido']) ?
                                    $op['pedido'] :
                                    'NÃO INFORMADO';

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
                                    <td class="col-pedido"><?php echo htmlspecialchars($pedidoTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                    <td class="col-lote <?php echo $loteClass; ?>">
                                        <?php echo htmlspecialchars($loteTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    </td>
                                    <td class="col-descricao preserve-special-chars">
                                        <div class="texto-completo">
                                            <?php echo htmlspecialchars($descricaoTexto, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
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
                                                onclick="removerOP('<?php echo $opId; ?>', '<?php echo htmlspecialchars($seqop, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>', <?php echo $quantidade; ?>)"
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
        document.addEventListener('DOMContentLoaded', function() {
            const campoOP = document.getElementById('campo-op');
            const campoQuantidade = document.getElementById('campo-quantidade');
            const btnAdicionar = document.getElementById('btn-adicionar');
            const formLimparTudo = document.getElementById('form-limpar-tudo');
            const btnLimparTudo = document.getElementById('btn-limpar-tudo');
            const btnImprimir = document.getElementById('btn-imprimir');

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

            if (formLimparTudo) {
                formLimparTudo.addEventListener('submit', function(e) {
                    e.preventDefault();
                    limparTodasOPs();
                });
            }

            if (btnImprimir) {
                btnImprimir.addEventListener('click', function(e) {
                    const janelaImpressao = window.open('etiquetas_imp_plasma.php', 'impressao_etiquetas',
                        'width=800,height=600,left=100,top=100,toolbar=no,location=no,status=no,menubar=no,scrollbars=yes');
                    
                    if (janelaImpressao) {
                        window.focus();
                    }
                });
            }

            if (campoQuantidade) {
                campoQuantidade.addEventListener('change', function() {
                    let value = parseInt(this.value);
                    if (isNaN(value) || value < 1) {
                        this.value = 1;
                    } else if (value > 100) {
                        this.value = 100;
                    }
                });
            }

            atualizarBotoes();
        });

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
            btnAdicionar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando dados...';
            btnAdicionar.disabled = true;

            const formData = new FormData();
            formData.append('adicionar', '1');
            formData.append('op', campoOP.value.trim());
            formData.append('quantidade', campoQuantidade.value);

            fetch('<?php echo basename(__FILE__); ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
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
                    mostrarMensagem('Erro ao buscar dados. Tente novamente.', 'error');
                    btnAdicionar.innerHTML = btnOriginalHtml;
                    btnAdicionar.disabled = false;
                });
        }

        function adicionarLinhaTabela(opData, totalOps) {
            const corpoTabela = document.getElementById('corpo-tabela');
            const linhaVazia = document.getElementById('linha-vazia');

            if (linhaVazia) {
                linhaVazia.remove();
            }

            const loteClass = opData.lote.includes('NÃO DEFINIDO') || opData.lote.includes('NÃO INFORMADO') ? 'dado-nulo' : '';
            const cidadeClass = opData.cidade.includes('NÃO INFORMADA') ? 'dado-nulo' : '';

            const novaLinha = document.createElement('tr');
            novaLinha.id = `linha-${opData.id}`;
            novaLinha.dataset.opId = opData.id;

            const numeroLinha = totalOps;

            novaLinha.innerHTML = `
                <td class="col-id">${String(numeroLinha).padStart(3, '0')}</td>
                <td class="col-op-numero"><strong>${escapeHtml(opData.seqop)}</strong></td>
                <td class="col-quantidade">
                    <span class="quantidade-badge" title="${opData.quantidade} cópias">
                        ${opData.quantidade}
                    </span>
                </td>
                <td class="col-pedido">${escapeHtml(opData.pedido)}</td>
                <td class="col-lote ${loteClass}">${escapeHtml(opData.lote)}</td>
                <td class="col-descricao preserve-special-chars">
                    <div class="texto-completo">${escapeHtml(opData.descricao_produto)}</div>
                </td>
                <td class="col-cliente preserve-special-chars">
                    <div class="texto-completo">${escapeHtml(opData.nome_cliente)}</div>
                </td>
                <td class="col-cidade ${cidadeClass}">${escapeHtml(opData.cidade)}</td>
                <td class="col-acoes">
                    <div class="acoes-container">
                        <button type="button" class="btn-acao btn-remover" 
                            onclick="removerOP('${opData.id}', '${escapeHtml(opData.seqop.toString())}', ${opData.quantidade})"
                            title="Remover OP">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;

            corpoTabela.appendChild(novaLinha);
            atualizarNumerosLinhas();
        }

        function removerOP(opId, seqop, quantidade) {
            if (!confirm(`Remover esta OP?\n\nOP: ${seqop}\nQuantidade: ${quantidade} cópias`)) {
                return;
            }

            const formData = new FormData();
            formData.append('remover', '1');
            formData.append('op_id', opId);

            fetch('<?php echo basename(__FILE__); ?>', {
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

        function limparTodasOPs() {
            if (!confirm('Tem certeza que deseja remover TODAS as OPs da lista?\n\nEsta ação não pode ser desfeita.')) {
                return;
            }

            const formData = new FormData();
            formData.append('limpar_tudo', '1');

            fetch('<?php echo basename(__FILE__); ?>', {
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
                        const corpoTabela = document.getElementById('corpo-tabela');
                        corpoTabela.innerHTML = '';
                        mostrarListaVazia();
                        atualizarBotoes();
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    mostrarMensagem('Erro ao limpar lista.', 'error');
                });
        }

        function mostrarListaVazia() {
            const corpoTabela = document.getElementById('corpo-tabela');
            corpoTabela.innerHTML = `
                <tr id="linha-vazia">
                    <td colspan="9" style="border: none;">
                        <div class="lista-vazia">
                            <div class="lista-vazia-icon">📝</div>
                            <h3>Nenhuma OP cadastrada</h3>
                            <p>Digite uma OP para buscar dados cruzados</p>
                        </div>
                    </td>
                </tr>
            `;
        }

        function atualizarNumerosLinhas() {
            const linhas = document.querySelectorAll('#corpo-tabela tr:not(#linha-vazia)');
            linhas.forEach((linha, index) => {
                const celulaNumero = linha.querySelector('.col-id');
                if (celulaNumero) {
                    celulaNumero.textContent = String(index + 1).padStart(3, '0');
                }
            });
        }

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

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        setTimeout(function() {
            const mensagens = document.querySelectorAll('.mensagem-flutuante');
            mensagens.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 3000);
    </script>
</body>
</html>