<?php
// ADICIONE ESTAS CONFIGURAÇÕES NO INÍCIO DO ARQUIVO
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Classe de conexão atualizada
class TodasConexoes
{
    private static $configuracoes = array(
        'OPERADOR' => array(
            'dsn' => 'firebird:dbname=192.168.1.209:c:/BD/OPERADOR.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ),
        'ARQSIST' => array(
            'dsn' => 'firebird:dbname=192.168.1.209:c:/BD/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        )
    );

    public static function getConexao($banco = 'OPERADOR')
    {
        if (!isset(self::$configuracoes[$banco])) {
            throw new Exception("Banco de dados '$banco' não configurado");
        }
        
        $config = self::$configuracoes[$banco];

        try {
            // Configurações de conexão com UTF-8 já no DSN
            $pdo = new PDO($config['dsn'], $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            
            // Para Firebird, não usamos SET NAMES
            // O charset já está configurado no DSN
            
            return $pdo;
        } catch (PDOException $e) {
            die("Erro de conexão ($banco): " . $e->getMessage());
        }
    }

    public static function consultarMaquinas($dataInicio = null, $dataFim = null)
    {
        try {
            $pdo = self::getConexao('OPERADOR');

            $sql = "SELECT 
                    m.seq AS ID, 
                    m.data AS DATA, 
                    m.descricao AS OPERADOR, 
                    m.meta AS META, 
                    m.produtividade AS PRODUTIVIDADE,
                    m.horas_trabalhadas AS HORAS, 
                    m.diferenca AS DIFERENCA,  
                    m.soma_automatica AS PROD_ATUALIZADA,
                    m.observacao AS OCORRENCIA, 
                    m.observacao_diferenca AS MOT_ALTERACAO
                    FROM rob8_operador_maquina m";

            $where = array();
            $params = array();

            if ($dataInicio) {
                $where[] = "m.data >= ?";
                $params[] = $dataInicio;
            }

            if ($dataFim) {
                $where[] = "m.data <= ?";
                $params[] = $dataFim;
            }

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY m.data DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dadosFormatados = array();
            foreach ($dados as $linha) {
                $novaLinha = array();
                foreach ($linha as $chave => $valor) {
                    $novaChave = strtolower($chave);
                    // Se o valor for string, garantir UTF-8
                    if (is_string($valor)) {
                        // Tentar detectar e converter encoding se necessário
                        $encoding = mb_detect_encoding($valor, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                        if ($encoding && $encoding != 'UTF-8') {
                            $valor = mb_convert_encoding($valor, 'UTF-8', $encoding);
                        }
                    }
                    $novaLinha[$novaChave] = $valor;
                }
                $dadosFormatados[] = $novaLinha;
            }

            return $dadosFormatados;
        } catch (Exception $e) {
            die("Erro na consulta: " . $e->getMessage());
        }
    }

    public static function getOperadores()
    {
        try {
            $pdo = self::getConexao('ARQSIST');

            // BUSCA TODOS OS OPERADORES ATIVOS
            $sql = "SELECT TRIM(c.nome) as nome
                    FROM arqcad c
                    WHERE c.tipoc = 'O'
                      AND c.situ = 'A'
                      AND c.nome IS NOT NULL
                      AND c.nome != ''
                    ORDER BY c.nome ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $operadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Processar os nomes para garantir UTF-8
            $operadoresProcessados = [];
            foreach ($operadores as $operador) {
                if (isset($operador['NOME'])) {
                    $nome = $operador['NOME'];
                    // Detectar e converter encoding se necessário
                    $encoding = mb_detect_encoding($nome, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                    if ($encoding && $encoding != 'UTF-8') {
                        $nome = mb_convert_encoding($nome, 'UTF-8', $encoding);
                    }
                    $operadoresProcessados[] = ['NOME' => $nome];
                }
            }

            return $operadoresProcessados;

        } catch (Exception $e) {
            die("Erro na consulta de operadores: " . $e->getMessage());
        }
    }

    public static function salvarRegistro($dados)
    {
        try {
            $pdo = self::getConexao('OPERADOR');
            
            // Converter dados para UTF-8 antes de salvar
            foreach ($dados as $key => $value) {
                if (is_string($value)) {
                    // Garantir que esteja em UTF-8
                    $encoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                    if ($encoding && $encoding != 'UTF-8') {
                        $dados[$key] = mb_convert_encoding($value, 'UTF-8', $encoding);
                    }
                }
            }
            
            $sql = "INSERT INTO rob8_operador_maquina 
                   (data, descricao, meta, produtividade, horas_trabalhadas, 
                    diferenca, soma_automatica, observacao, observacao_diferenca)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            
            $params = array(
                $dados['data'],
                $dados['descricao'],
                isset($dados['meta']) ? floatval($dados['meta']) : 0,
                isset($dados['produtividade']) ? floatval($dados['produtividade']) : 0,
                isset($dados['horas_trabalhadas']) ? floatval($dados['horas_trabalhadas']) : 0,
                isset($dados['diferenca']) ? floatval($dados['diferenca']) : 0,
                isset($dados['soma_automatica']) ? floatval($dados['soma_automatica']) : 0,
                isset($dados['observacao']) ? $dados['observacao'] : '',
                isset($dados['observacao_diferenca']) ? $dados['observacao_diferenca'] : ''
            );
            
            return $stmt->execute($params);
            
        } catch (Exception $e) {
            die("Erro ao salvar registro: " . $e->getMessage());
        }
    }
}

// FUNÇÃO PARA CONVERTER CARACTERES ESPECIAIS
function converterParaUTF8($string) {
    if (!is_string($string)) {
        return $string;
    }
    
    $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    
    if ($encoding && $encoding != 'UTF-8') {
        return mb_convert_encoding($string, 'UTF-8', $encoding);
    }
    
    return $string;
}

// Obter parâmetros do filtro
$dataInicio = isset($_GET['data_inicio']) ? converterParaUTF8($_GET['data_inicio']) : null;
$dataFim = isset($_GET['data_fim']) ? converterParaUTF8($_GET['data_fim']) : null;

// Converter datas para formato Y-m-d se necessário
if ($dataInicio) {
    $dataInicio = date('Y-m-d', strtotime($dataInicio));
}
if ($dataFim) {
    $dataFim = date('Y-m-d', strtotime($dataFim));
}

// Obter dados com filtro
$dados = TodasConexoes::consultarMaquinas($dataInicio, $dataFim);
$total = count($dados);

// Obter lista de operadores do banco ARQSIST
$operadores = TodasConexoes::getOperadores();

// Processar submissão do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['operador'])) {
    try {
        // Converter dados do POST para UTF-8
        foreach ($_POST as $key => $value) {
            $_POST[$key] = converterParaUTF8($value);
        }
        
        $novoRegistro = array(
            'data' => $_POST['data'],
            'descricao' => $_POST['operador'],
            'meta' => isset($_POST['meta']) ? floatval($_POST['meta']) : 0,
            'produtividade' => isset($_POST['produtividade']) ? floatval($_POST['produtividade']) : 0,
            'horas_trabalhadas' => isset($_POST['horas']) ? floatval($_POST['horas']) : 0,
            'observacao' => isset($_POST['observacao']) ? $_POST['observacao'] : '',
            'diferenca' => 0,
            'soma_automatica' => 0,
            'observacao_diferenca' => ''
        );
        
        $resultado = TodasConexoes::salvarRegistro($novoRegistro);
        
        if ($resultado) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?sucesso=1");
            exit();
        }
    } catch (Exception $e) {
        $erroSalvar = "Erro ao salvar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabela de Produção</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ESTILOS CSS MANTIDOS IGUAIS - MESMOS DO CÓDIGO ANTERIOR */
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f0f0f0;
        }

        .retangulo-amarelo {
            background-color: #333;
            height: 80px;
            width: 100%;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            border: 1px solid #000;
            box-sizing: border-box;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            position: relative;
        }

        .logo-noroaco {
            height: 50px;
            margin-right: 25px;
            width: auto;
            object-fit: contain;
        }

        .filtro-periodo {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.9);
            padding: 8px 15px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .filtro-periodo label {
            font-weight: bold;
            color: #333;
            font-size: 14px;
            white-space: nowrap;
        }

        .filtro-periodo input {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            width: 150px;
        }

        .filtro-periodo button {
            background-color: #ffc64d;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .filtro-periodo button:hover {
            background-color: #555;
        }

        .filtro-periodo .btn-limpar {
            background-color: #6c757d;
        }

        .filtro-periodo .btn-limpar:hover {
            background-color: #5a6268;
        }

        .btn-novo-registro {
            background-color: #fdb525;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: background-color 0.3s;
            white-space: nowrap;
            margin-left: auto;
        }

        .btn-novo-registro:hover {
            background-color: #ffc64d;
            color: white;
            text-decoration: none;
        }

        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-left: auto;
        }

        .data-filtro {
            color: #666;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.9);
            padding: 5px 10px;
            border-radius: 4px;
            white-space: nowrap;
        }

        h2 {
            color: #333;
            border: 2px solid #333;
            border-top: none;
            padding: 15px;
            margin-top: 0;
            background-color: white;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
            position: relative;
            box-sizing: border-box;
        }

        /* MODAL STYLES */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }

        .close-modal {
            color: #aaa;
            font-size: 28px;
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
        }

        .close-modal:hover {
            color: #000;
            background-color: #f0f0f0;
            border-radius: 50%;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 2px rgba(253, 181, 37, 0.2);
        }

        select.form-control {
            height: 38px;
            background-color: white;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-col {
            flex: 1;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: #fdb525;
            color: white;
        }

        .btn-primary:hover {
            background-color: #ffc64d;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .mensagem-sucesso {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid #c3e6cb;
        }

        .mensagem-erro {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid #f5c6cb;
        }

        /* TABLE STYLES */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #ddd;
            table-layout: fixed;
            box-sizing: border-box;
        }

        th {
            background: #333;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-size: 12px;
            border-right: 1px solid #ddd;
            font-weight: bold;
        }

        th:last-child {
            border-right: none;
        }

        td {
            padding: 10px 8px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
            color: black;
            border-right: 1px solid #ddd;
            vertical-align: top;
        }

        td:last-child {
            border-right: none;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .data {
            color: #666;
            font-size: 12px;
            margin-top: 10px;
        }

        .col-id {
            width: 23px;
            text-align: center;
        }

        .col-data {
            width: 60px;
            text-align: center;
        }

        .col-operador {
            width: 250px;
        }

        .col-meta {
            width: 70px;
            text-align: center;
        }

        .col-produtividade {
            width: 90px;
            text-align: center;
        }

        .col-horas {
            width: 50px;
            text-align: center;
        }

        .col-diferenca {
            width: 80px;
            text-align: center;
        }

        .col-prod-atualizada {
            width: 70px;
            text-align: center;
        }

        .col-ocorrencia {
            width: 200px;
            word-wrap: break-word;
            white-space: normal;
        }

        .col-mot-alteracao {
            width: 180px;
            word-wrap: break-word;
            white-space: normal;
        }

        .col-acoes {
            width: 80px;
            text-align: center;
        }

        .texto-ellipsis {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-acao {
            display: inline-block;
            width: 20px;
            height: 20px;
            line-height: 20px;
            text-align: center;
            border-radius: 4px;
            margin: 0 2px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-editar {
            background-color: #515151;
            color: white;
        }

        .btn-editar:hover {
            background-color: #0b7dda;
        }

        .btn-excluir {
            background-color: #515151;
            color: white;
        }

        .btn-excluir:hover {
            background-color: #da190b;
        }

        .acoes-container {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        /* ADICIONAR PARA SUPORTE DE UTF-8 NOS TEXTOS */
        * {
            font-family: Arial, sans-serif;
        }
        
        table, th, td {
            font-family: Arial, sans-serif;
        }
        
        input, select, textarea {
            font-family: Arial, sans-serif;
        }
    </style>
</head>

<body>
    <!-- RETÂNGULO AMARELO COM LOGO, FILTRO E BOTÃO NOVO REGISTRO -->
    <div class="retangulo-amarelo">
        <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

        <form method="GET" action="" class="filtro-periodo">
            <label for="data_inicio">De:</label>
            <input type="date" id="data_inicio" name="data_inicio"
                value="<?php echo isset($_GET['data_inicio']) ? htmlspecialchars($_GET['data_inicio'], ENT_QUOTES, 'UTF-8') : ''; ?>">

            <label for="data_fim">Até:</label>
            <input type="date" id="data_fim" name="data_fim"
                value="<?php echo isset($_GET['data_fim']) ? htmlspecialchars($_GET['data_fim'], ENT_QUOTES, 'UTF-8') : ''; ?>">

            <button type="submit">
                <i class="fas fa-filter"></i> Filtrar
            </button>

            <?php if ($dataInicio || $dataFim): ?>
                <a href="?" class="btn-limpar" style="text-decoration: none;">
                    <button type="button" onclick="window.location.href='?'" class="btn-limpar">
                        <i class="fas fa-times"></i> Limpar
                    </button>
                </a>
            <?php endif; ?>
        </form>

        <div class="botoes-direita">
            <?php if ($dataInicio || $dataFim): ?>
                <div class="data-filtro">
                    <?php if ($dataInicio && $dataFim): ?>
                        Período: <?php echo date('d/m/Y', strtotime($dataInicio)); ?> a
                        <?php echo date('d/m/Y', strtotime($dataFim)); ?>
                    <?php elseif ($dataInicio): ?>
                        A partir de: <?php echo date('d/m/Y', strtotime($dataInicio)); ?>
                    <?php elseif ($dataFim): ?>
                        Até: <?php echo date('d/m/Y', strtotime($dataFim)); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Botão Novo Registro -->
            <a href="javascript:void(0);" onclick="abrirModalNovoRegistro()" class="btn-novo-registro">
                <i class="fas fa-plus-circle"></i> Novo Registro
            </a>
        </div>
    </div>

    <?php if (isset($_GET['sucesso'])): ?>
        <div class="mensagem-sucesso">
            <i class="fas fa-check-circle"></i> Registro salvo com sucesso!
        </div>
    <?php endif; ?>

    <?php if (isset($erroSalvar)): ?>
        <div class="mensagem-erro">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erroSalvar, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($total == 0): ?>
        <h2>Nenhum dado encontrado.</h2>
    <?php else: ?>
        <table>
            <tr>
                <th class="col-id">ID</th>
                <th class="col-data">DATA</th>
                <th class="col-operador">OPERADOR</th>
                <th class="col-meta">META</th>
                <th class="col-produtividade">PRODUTIVIDADE</th>
                <th class="col-horas">HORAS</th>
                <th class="col-diferenca">DIFERENÇA</th>
                <th class="col-prod-atualizada">PROD. ATUALIZADA</th>
                <th class="col-ocorrencia">OCORRÊNCIA</th>
                <th class="col-mot-alteracao">MOT. ALTERAÇÃO</th>
                <th class="col-acoes">AÇÕES</th>
            </tr>
            <?php foreach ($dados as $item): ?>
                <?php
                $data = !empty($item['data']) ? date('d/m/Y', strtotime($item['data'])) : '';
                $meta = isset($item['meta']) ? floatval($item['meta']) : 0;
                $metaFormatada = number_format($meta, 2, ',', '.');
                $prod = isset($item['produtividade']) ? floatval($item['produtividade']) : 0;
                $prodFormatada = number_format($prod, 2, ',', '.');
                $horas = isset($item['horas']) ? number_format(floatval($item['horas']), 1, ',', '.') : '0,0';
                $diferenca = isset($item['diferenca']) ? floatval($item['diferenca']) : 0;
                $diferencaFormatada = number_format($diferenca, 2, ',', '.');
                $prodAtualizada = isset($item['prod_atualizada']) ? number_format(floatval($item['prod_atualizada']), 2, ',', '.') : '0,00';
                $nome_operador = isset($item['operador']) ? htmlspecialchars($item['operador'], ENT_QUOTES, 'UTF-8') : '';
                $ocorrencia = isset($item['ocorrencia']) ? nl2br(htmlspecialchars($item['ocorrencia'], ENT_QUOTES, 'UTF-8')) : '';
                $motAlteracao = isset($item['mot_alteracao']) ? nl2br(htmlspecialchars($item['mot_alteracao'], ENT_QUOTES, 'UTF-8')) : '';
                $idRegistro = isset($item['id']) ? $item['id'] : 0;
                ?>
                <tr>
                    <td class="col-id"><?php echo htmlspecialchars($idRegistro, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="col-data"><?php echo $data; ?></td>
                    <td class="col-operador texto-ellipsis" title="<?php echo $nome_operador; ?>">
                        <?php echo $nome_operador; ?>
                    </td>
                    <td class="col-meta"><?php echo $metaFormatada; ?></td>
                    <td class="col-produtividade"><?php echo $prodFormatada; ?></td>
                    <td class="col-horas"><?php echo $horas; ?> H</td>
                    <td class="col-diferenca">
                        <?php echo ($diferenca >= 0 ? '' : '') . $diferencaFormatada; ?>
                    </td>
                    <td class="col-prod-atualizada"><?php echo $prodAtualizada; ?></td>
                    <td class="col-ocorrencia" title="<?php echo strip_tags($ocorrencia); ?>">
                        <?php echo $ocorrencia; ?>
                    </td>
                    <td class="col-mot-alteracao" title="<?php echo strip_tags($motAlteracao); ?>">
                        <?php echo $motAlteracao; ?>
                    </td>
                    <td class="col-acoes">
                        <div class="acoes-container">
                            <a href="javascript:void(0);" onclick="editarRegistro(<?php echo $idRegistro; ?>)"
                                class="btn-acao btn-editar" title="Editar registro">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                            <a href="javascript:void(0);" onclick="excluirRegistro(<?php echo $idRegistro; ?>)"
                                class="btn-acao btn-excluir" title="Excluir registro">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <div class="data">
        Atualizado em: <?php echo date('d/m/Y H:i:s'); ?>
    </div>

    <!-- MODAL PARA NOVO REGISTRO -->
    <div id="modalNovoRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Novo Registro</div>
                <button class="close-modal" onclick="fecharModalNovoRegistro()">&times;</button>
            </div>

            <form id="formNovoRegistro" method="POST" action="" accept-charset="UTF-8">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="tipo">Tipo *</label>
                            <select id="tipo" name="tipo" class="form-control" required>
                                <option value="">Selecione o tipo</option>
                                <option value="Operador">Operador</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="data">Data *</label>
                            <input type="date" id="data" name="data" class="form-control"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="operador">Operador *</label>
                    <select id="operador" name="operador" class="form-control" required>
                        <option value="">Selecione um operador</option>
                        <?php foreach ($operadores as $op_item): ?>
                            <option value="<?php echo htmlspecialchars($op_item['NOME'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($op_item['NOME'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="meta">Meta</label>
                            <input type="number" id="meta" name="meta" class="form-control" step="0.01" min="0"
                                placeholder="0,00" value="0.00">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="produtividade">Produtividade</label>
                            <input type="number" id="produtividade" name="produtividade" class="form-control"
                                step="0.01" min="0" placeholder="0,00" value="0.00">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="horas">Horas</label>
                            <input type="number" id="horas" name="horas" class="form-control" step="0.1" min="0"
                                placeholder="0,0" value="0.0">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="observacao">Observação</label>
                    <textarea id="observacao" name="observacao" class="form-control"
                        placeholder="Digite a observação..." style="font-family: Arial, sans-serif;"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalNovoRegistro()">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Registro
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'block';
        }

        function fecharModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'none';
            document.getElementById('formNovoRegistro').reset();
        }

        function editarRegistro(id) {
            alert('Editar registro ID: ' + id + ' (funcionalidade a ser implementada)');
        }

        function excluirRegistro(id) {
            if (confirm('Tem certeza que deseja excluir o registro ID: ' + id + '?')) {
                alert('Registro ID: ' + id + ' excluído (simulação)');
            }
        }

        // Fechar modal ao clicar fora
        window.onclick = function (event) {
            var modal = document.getElementById('modalNovoRegistro');
            if (event.target == modal) {
                fecharModalNovoRegistro();
            }
        }

        // Validação do formulário
        document.getElementById('formNovoRegistro').addEventListener('submit', function (e) {
            e.preventDefault();

            var tipo = document.getElementById('tipo').value;
            var data = document.getElementById('data').value;
            var operador = document.getElementById('operador').value;

            if (!tipo || !data || !operador) {
                alert('Por favor, preencha todos os campos obrigatórios (*)');
                return;
            }

            // Se tudo estiver ok, envia o formulário
            this.submit();
        });

        // Validação básica das datas no filtro
        document.querySelector('form[method="GET"]').addEventListener('submit', function (e) {
            var dataInicio = document.getElementById('data_inicio').value;
            var dataFim = document.getElementById('data_fim').value;

            if (dataInicio && dataFim) {
                var inicio = new Date(dataInicio);
                var fim = new Date(dataFim);

                if (inicio > fim) {
                    alert('A data inicial não pode ser maior que a data final!');
                    e.preventDefault();
                }
            }
        });

        // Calcular diferença automaticamente
        document.getElementById('produtividade').addEventListener('change', function() {
            var meta = parseFloat(document.getElementById('meta').value) || 0;
            var produtividade = parseFloat(this.value) || 0;
        });
    </script>
</body>
</html>