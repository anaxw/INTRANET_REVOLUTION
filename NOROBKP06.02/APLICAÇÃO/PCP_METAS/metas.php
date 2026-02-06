<?php
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

class ConexaoLocal
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
            $pdo = new PDO($config['dsn'], $config['user'], $config['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ));

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
                    if (is_string($valor)) {
                        $encoding = mb_detect_encoding($valor, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
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

            $operadoresProcessados = array();
            foreach ($operadores as $operador) {
                if (isset($operador['NOME'])) {
                    $nome = $operador['NOME'];
                    $encoding = mb_detect_encoding($nome, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
                    if ($encoding && $encoding != 'UTF-8') {
                        $nome = mb_convert_encoding($nome, 'UTF-8', $encoding);
                    }
                    $operadoresProcessados[] = array('NOME' => $nome);
                }
            }
            $operadorFixo = array('NOME' => 'LUIZ FERNANDO DE OLIVEIRA SANTOS LASER');
            $operadoresProcessados[] = $operadorFixo;
            usort($operadoresProcessados, function ($a, $b) {
                return strcmp($a['NOME'], $b['NOME']);
            });

            return $operadoresProcessados;
        } catch (Exception $e) {
            die("Erro na consulta de operadores: " . $e->getMessage());
        }
    }

    public static function verificarOperadorData($operador, $data)
    {
        try {
            $pdo = self::getConexao('OPERADOR');

            $dataFormatada = date('Y-m-d', strtotime($data));

            $sql = "SELECT COUNT(*) as total 
                    FROM rob8_operador_maquina 
                    WHERE descricao = ? 
                      AND data = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($operador, $dataFormatada));

            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

            return ($resultado && $resultado['TOTAL'] > 0);
        } catch (Exception $e) {
            error_log("Erro na verificação: " . $e->getMessage());
            return false;
        }
    }
}

function converterParaUTF8($string)
{
    if (!is_string($string)) {
        return $string;
    }

    // PHP 8: mb_detect_encoding pode retornar false
    $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

    if ($encoding === false) {
        // Tentar detecção simples
        if (mb_check_encoding($string, 'UTF-8')) {
            return $string;
        }
        // Tentar converter de ISO-8859-1
        return mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
    }

    if ($encoding !== 'UTF-8') {
        return mb_convert_encoding($string, 'UTF-8', $encoding);
    }

    return $string;
}

function formatarNumeroParaExibicao($valor, $casasDecimais = 2, $ehHoras = false)
{
    if ($valor === null || $valor === '' || floatval($valor) == 0) {
        return '';
    }

    $num = floatval($valor);

    $formatado = number_format($num, $casasDecimais, ',', '.');

    $formatado = rtrim($formatado, '0');
    $formatado = rtrim($formatado, ',');

    if ($ehHoras && $formatado !== '') {
        return $formatado . ' H';
    }

    return $formatado;
}

function converterNumeroBrasileiroParaFloat($valor)
{
    if (empty($valor) || $valor === '' || $valor === null) {
        return 0.0;
    }

    if (is_numeric($valor) && !is_string($valor)) {
        return (float) $valor;
    }

    $valor = trim($valor);

    if (is_numeric($valor)) {
        return (float) $valor;
    }

    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (strpos($valor, ',') !== false) {
        $partes = explode(',', $valor);
        if (count($partes) === 2 && strlen($partes[1]) <= 2) {
            $valor = str_replace(',', '.', $valor);
        } else {
            $valor = str_replace(',', '', $valor);
        }
    }

    $valor = preg_replace('/[^\d\.\-]/', '', $valor);

    $resultado = (float) $valor;

    return $resultado;
}

// FUNÇÃO PARA CALCULAR DIA ÚTIL (SEGUNDA A SEXTA)
function calcularDiaUtil($diasAtras = 1)
{
    $data = new DateTime();
    $diasSubtraidos = 0;

    while ($diasSubtraidos < $diasAtras) {
        $data->modify('-1 day');
        $diaSemana = (int)$data->format('N'); // 1=segunda, 7=domingo

        // Se não for sábado (6) nem domingo (7), conta como dia útil
        if ($diaSemana < 6) {
            $diasSubtraidos++;
        }
    }

    return $data->format('Y-m-d');
}

// Calcular datas de ontem e anteontem considerando dias úteis
$dataOntemUtil = calcularDiaUtil(1);  // Último dia útil anterior (ontem útil)
$dataAnteontemUtil = calcularDiaUtil(2); // Penúltimo dia útil (anteontem útil)

// Verificar se há filtros na URL
$temFiltroUrl = isset($_GET['data_inicio']) || isset($_GET['data_fim']);

// Se não houver filtros na URL, definir como ontem e anteontem úteis
if (!$temFiltroUrl) {
    $dataInicio = $dataAnteontemUtil;  // Data mais antiga (anteontem útil)
    $dataFim = $dataOntemUtil;         // Data mais recente (ontem útil)
} else {
    $dataInicio = isset($_GET['data_inicio']) ? converterParaUTF8($_GET['data_inicio']) : null;
    $dataFim = isset($_GET['data_fim']) ? converterParaUTF8($_GET['data_fim']) : null;
}

// Converter para formato correto
if ($dataInicio) {
    $dataInicio = date('Y-m-d', strtotime($dataInicio));
}
if ($dataFim) {
    $dataFim = date('Y-m-d', strtotime($dataFim));
}

$dados = ConexaoLocal::consultarMaquinas($dataInicio, $dataFim);
$total = count($dados);

$operadores = ConexaoLocal::getOperadores();

// Determinar se estamos mostrando apenas ontem e anteontem úteis (padrão)
$mostrandoPadrao = !$temFiltroUrl && $dataInicio == $dataAnteontemUtil && $dataFim == $dataOntemUtil;

// Determinar se é um único dia
$eUnicoDia = $dataInicio == $dataFim;

// Hoje para referência
$dataHoje = date('Y-m-d');

// Função para obter nome do dia da semana
function getNomeDiaSemana($data)
{
    $dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $numeroDia = date('w', strtotime($data)); // 0 = Domingo, 1 = Segunda, etc.
    return $dias[$numeroDia];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Metas x Produção</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="metas.css">
    <style>
        /* Estilos adicionais para o filtro padrão */
        .indicador-filtro-padrao {
            display: inline-flex;
            align-items: center;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 6px 12px;
            margin-left: 15px;
            font-size: 14px;
            color: #495057;
        }

        .indicador-filtro-padrao i {
            color: #fdb525;
            margin-right: 6px;
        }

        .btn-limpar-padrao {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 10px;
            transition: background-color 0.3s;
            display: inline-flex;
            align-items: center;
            font-size: 14px;
        }

        .btn-limpar-padrao:hover {
            background-color: #5a6268;
        }

        .btn-limpar-padrao i {
            margin-right: 5px;
        }

        .filtro-padrao-info {
            background-color: #e8f4fd;
            border: 1px solid #b3d7ff;
            border-radius: 4px;
            padding: 10px 15px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #0056b3;
        }

        .filtro-padrao-info i {
            margin-right: 10px;
            color: #0056b3;
        }

        .data-filtro-padrao {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 8px 12px;
            margin-left: 10px;
            font-weight: bold;
            color: #856404;
        }

        .campo-filtro-padrao {
            background-color: #f8f9fa;
            border-color: #fdb525;
        }

        .btn-filtro-padrao {
            background-color: #fdb525;
            color: #fff;
        }

        .btn-filtro-padrao:hover {
            background-color: #e6a41a;
        }

        .opcoes-rapidas-filtro {
            display: flex;
            gap: 10px;
            margin: 10px 0;
            flex-wrap: wrap;
        }

        .btn-opcao-rapida {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            color: #495057;
            display: inline-flex;
            align-items: center;
        }

        .btn-opcao-rapida:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }

        .btn-opcao-rapida i {
            margin-right: 5px;
            font-size: 12px;
        }

        .badge-ontem {
            display: inline-block;
            background-color: #17a2b8;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }

        .badge-anteontem {
            display: inline-block;
            background-color: #6c757d;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }

        .badge-hoje {
            display: inline-block;
            background-color: #28a745;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }

        .badge-dia-util {
            display: inline-block;
            background-color: #fdb525;
            color: #333;
            font-size: 8px;
            padding: 1px 4px;
            border-radius: 8px;
            margin-left: 3px;
            vertical-align: middle;
        }

        .periodo-info {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }

        .seta-periodo {
            color: #6c757d;
            font-size: 12px;
        }

        .info-dia-semana {
            font-size: 12px;
            color: #6c757d;
            margin-left: 5px;
            font-style: italic;
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

            <form method="GET" action="" class="filtro-periodo">
                <label for="data_inicio">De:</label>
                <input type="date" id="data_inicio" name="data_inicio"
                    value="<?php
                            if ($temFiltroUrl && isset($_GET['data_inicio'])) {
                                echo htmlspecialchars($_GET['data_inicio'], ENT_QUOTES, 'UTF-8');
                            } elseif (!$temFiltroUrl) {
                                // Preencher com anteontem útil quando for padrão
                                echo htmlspecialchars($dataAnteontemUtil, ENT_QUOTES, 'UTF-8');
                            }
                            ?>"
                    class="<?php echo !$temFiltroUrl ? 'campo-filtro-padrao' : ''; ?>">

                <label for="data_fim">Até:</label>
                <input type="date" id="data_fim" name="data_fim"
                    value="<?php
                            if ($temFiltroUrl && isset($_GET['data_fim'])) {
                                echo htmlspecialchars($_GET['data_fim'], ENT_QUOTES, 'UTF-8');
                            } elseif (!$temFiltroUrl) {
                                // Preencher com ontem útil quando for padrão
                                echo htmlspecialchars($dataOntemUtil, ENT_QUOTES, 'UTF-8');
                            }
                            ?>"
                    class="<?php echo !$temFiltroUrl ? 'campo-filtro-padrao' : ''; ?>">

                <button type="submit" class="<?php echo !$temFiltroUrl ? 'btn-filtro-padrao' : ''; ?>">
                    <i class="fas fa-filter"></i> Filtrar
                </button>

                <?php if ($temFiltroUrl): ?>
                    <button type="button" class="btn-limpar-padrao" onclick="limparFiltro()">
                        <i class="fas fa-calendar-alt"></i> Voltar para últimos dias úteis
                    </button>
                <?php endif; ?>
            </form>

            <div class="botoes-direita">
                <button onclick="abrirModalNovoRegistro()" class="btn-novo-registro">
                    <i class="fas fa-plus-circle"></i> Novo Registro
                </button>
            </div>
        </div>
        <div id="mensagemAlerta" class="alerta" style="display: none;"></div>

        <div class="container-tabela">
            <?php if ($total == 0): ?>
                <div class="titulo-tabela">
                    <i class="fas fa-info-circle" style="color: #fdb525; margin-right: 10px;"></i>
                    <?php if ($mostrandoPadrao): ?>
                        Nenhum registro encontrado para os últimos 2 dias úteis.
                        <a href="javascript:void(0)" onclick="mostrarTodosRegistros()" style="margin-left: 10px; color: #fdb525; text-decoration: underline;">
                            Ver todos os registros
                        </a>
                    <?php else: ?>
                        Nenhum dado encontrado para o período selecionado.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="titulo-tabela">
                    <i class="fas fa-table" style="color: #fdb525; margin-right: 10px;"></i>
                    Registros de Produção

                </div>

                <div class="tabela-container">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-data">DATA</th>
                                <th class="col-operador">OPERADOR</th>
                                <th class="col-meta">META</th>
                                <th class="col-produtividade">PRODUTIVIDADE</th>
                                <th class="col-horas">HORAS</th>
                                <th class="col-diferenca">DIFERENÇA</th>
                                <th class="col-prod-atualizada">PROD. <br>ATUALIZADA</th>
                                <th class="col-ocorrencia">OCORRÊNCIA</th>
                                <th class="col-mot-alteracao">MOT. <br>ALTERAÇÃO</th>
                                <th class="col-acoes">AÇÕES</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados as $item): ?>
                                <?php
                                $data = !empty($item['data']) ? date('d/m/Y', strtotime($item['data'])) : '';

                                $metaValor = isset($item['meta']) ? $item['meta'] : null;
                                $metaFormatada = formatarNumeroParaExibicao($metaValor, 2);

                                $prodValor = isset($item['produtividade']) ? $item['produtividade'] : null;
                                $prodFormatada = formatarNumeroParaExibicao($prodValor, 2);

                                $horasValor = isset($item['horas']) ? $item['horas'] : null;
                                $horas = formatarNumeroParaExibicao($horasValor, 1, true);
                                $horasDisplay = $horas !== '' ? $horas : '';

                                $diferencaValor = isset($item['diferenca']) ? $item['diferenca'] : null;
                                $diferencaFormatada = formatarNumeroParaExibicao($diferencaValor, 2);

                                $prodAtualizadaValor = isset($item['prod_atualizada']) ? $item['prod_atualizada'] : null;
                                $prodAtualizadaFormatada = formatarNumeroParaExibicao($prodAtualizadaValor, 2);

                                $nome_operador = isset($item['operador']) ? htmlspecialchars($item['operador'], ENT_QUOTES, 'UTF-8') : '';
                                $ocorrencia = isset($item['ocorrencia']) ? htmlspecialchars($item['ocorrencia'], ENT_QUOTES, 'UTF-8') : '';
                                $ocorrenciaDisplay = nl2br($ocorrencia);
                                $motAlteracao = isset($item['mot_alteracao']) ? htmlspecialchars($item['mot_alteracao'], ENT_QUOTES, 'UTF-8') : '';
                                $motAlteracaoDisplay = nl2br($motAlteracao);
                                $idRegistro = isset($item['id']) ? $item['id'] : 0;

                                $dataValue = !empty($item['data']) ? date('Y-m-d', strtotime($item['data'])) : '';
                                $operadorValue = isset($item['operador']) ? htmlspecialchars($item['operador'], ENT_QUOTES, 'UTF-8') : '';
                                $metaValue = isset($item['meta']) ? $item['meta'] : 0;
                                $prodValue = isset($item['produtividade']) ? $item['produtividade'] : 0;
                                $horasValue = isset($item['horas']) ? $item['horas'] : 0;
                                $diferencaValue = isset($item['diferenca']) ? $item['diferenca'] : 0;
                                $prodAtualizadaValue = isset($item['prod_atualizada']) ? $item['prod_atualizada'] : 0;
                                $ocorrenciaValue = isset($item['ocorrencia']) ? $item['ocorrencia'] : '';
                                $motAlteracaoValue = isset($item['mot_alteracao']) ? $item['mot_alteracao'] : '';

                                // Verificar qual é a data
                                $eHoje = $dataValue == $dataHoje;
                                $eOntemUtil = $dataValue == $dataOntemUtil;
                                $eAnteontemUtil = $dataValue == $dataAnteontemUtil;
                                $diaSemana = date('N', strtotime($dataValue)); // 1=segunda, 7=domingo
                                $eDiaUtil = $diaSemana >= 1 && $diaSemana <= 5; // Segunda a sexta
                                ?>
                                <tr data-id="<?php echo $idRegistro; ?>"
                                    data-data="<?php echo $dataValue; ?>"
                                    data-operador="<?php echo $operadorValue; ?>"
                                    data-meta="<?php echo $metaValue; ?>"
                                    data-produtividade="<?php echo $prodValue; ?>"
                                    data-horas="<?php echo $horasValue; ?>"
                                    data-diferenca="<?php echo $diferencaValue; ?>"
                                    data-prod-atualizada="<?php echo $prodAtualizadaValue; ?>"
                                    data-ocorrencia="<?php echo $ocorrenciaValue; ?>"
                                    data-mot-alteracao="<?php echo $motAlteracaoValue; ?>">
                                    <td class="col-id"><?php echo htmlspecialchars($idRegistro, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="col-data">
                                        <?php echo $data; ?>
                                    </td>
                                    <td class="col-operador">
                                        <span class="texto-ellipsis" title="<?php echo $nome_operador; ?>">
                                            <?php echo $nome_operador; ?>
                                        </span>
                                    </td>
                                    <td class="col-meta campo-numerico <?php echo $metaFormatada === '' ? 'celula-vazia' : ''; ?>">
                                        <?php echo $metaFormatada !== '' ? $metaFormatada : '-'; ?>
                                    </td>
                                    <td class="col-produtividade campo-numerico <?php echo $prodFormatada === '' ? 'celula-vazia' : ''; ?>">
                                        <?php echo $prodFormatada !== '' ? $prodFormatada : '-'; ?>
                                    </td>
                                    <td class="col-horas campo-numerico <?php echo $horasDisplay === '' ? 'celula-vazia' : ''; ?>">
                                        <?php echo $horasDisplay !== '' ? $horasDisplay : '-'; ?>
                                    </td>
                                    <td class="col-diferenca campo-numerico <?php echo $diferencaFormatada === '' ? 'celula-vazia' : ''; ?>">
                                        <?php echo $diferencaFormatada !== '' ? $diferencaFormatada : '-'; ?>
                                    </td>
                                    <td class="col-prod-atualizada campo-numerico <?php echo $prodAtualizadaFormatada === '' ? 'celula-vazia' : ''; ?>">
                                        <?php echo $prodAtualizadaFormatada !== '' ? $prodAtualizadaFormatada : '-'; ?>
                                    </td>
                                    <td class="col-ocorrencia">
                                        <div class="celula-expansivel" title="<?php echo strip_tags($ocorrenciaDisplay); ?>">
                                            <?php echo $ocorrenciaDisplay ?: '<span class="celula-vazia">-</span>'; ?>
                                        </div>
                                    </td>
                                    <td class="col-mot-alteracao">
                                        <div class="celula-expansivel" title="<?php echo strip_tags($motAlteracaoDisplay); ?>">
                                            <?php echo $motAlteracaoDisplay ?: '<span class="celula-vazia">-</span>'; ?>
                                        </div>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <button onclick="editarRegistro(<?php echo $idRegistro; ?>)"
                                                class="btn-acao btn-editar" title="Editar registro">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button onclick="excluirRegistro(<?php echo $idRegistro; ?>)"
                                                class="btn-acao btn-excluir" title="Excluir registro">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL PARA NOVO REGISTRO -->
    <div id="modalNovoRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-plus-circle" style="color: #fdb525;"></i>
                    Novo Registro
                </div>
                <button class="close-modal" onclick="fecharModalNovoRegistro()">&times;</button>
            </div>

            <form id="formNovoRegistro" method="POST" action="" accept-charset="UTF-8">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="tipo" class="campo-obrigatorio">Tipo</label>
                            <select id="tipo" name="tipo" class="form-control" required>
                                <option value="O">Operador</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="data" class="campo-obrigatorio">Data</label>
                            <input type="date" id="data" name="data" class="form-control"
                                value="<?php echo $dataOntemUtil; ?>" required>
                            <div id="avisoData" class="texto-aviso-duplicado"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="operador" class="campo-obrigatorio">Operador</label>
                    <select id="operador" name="operador" class="form-control" required>
                        <option value="">Selecione um operador</option>
                        <?php foreach ($operadores as $op_item): ?>
                            <option value="<?php echo htmlspecialchars($op_item['NOME'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($op_item['NOME'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="avisoOperador" class="texto-aviso-duplicado"></div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="meta" class="campo-obrigatorio">Meta</label>
                            <input type="text" id="meta" name="meta" class="form-control campo-numerico"
                                placeholder="Ex: 1.000,50" value="" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="produtividade">Produtividade</label>
                            <input type="text" id="produtividade" name="produtividade" class="form-control campo-numerico"
                                placeholder="Ex: 950,75" value="">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="horas">Horas</label>
                            <input type="text" id="horas" name="horas" class="form-control campo-numerico"
                                placeholder="Ex: 8,5" value="">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="observacao">Observação</label>
                    <textarea id="observacao" name="observacao" class="form-control"
                        placeholder="Digite a observação..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalNovoRegistro()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnSalvar">
                        <i class="fas fa-save"></i> Salvar Registro
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL PARA EDITAR REGISTRO -->
    <div id="modalEditarRegistro" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-edit" style="color: #3498db;"></i>
                    Editar Registro
                </div>
                <button class="close-modal" onclick="fecharModalEditarRegistro()">&times;</button>
            </div>

            <form id="formEditarRegistro" method="POST" action="" accept-charset="UTF-8">
                <input type="hidden" id="edit_id" name="id">

                <div class="info-registro">
                    <div class="info-item">
                        <label>ID:</label>
                        <span class="valor" id="info_id"></span>
                    </div>
                    <div class="info-item">
                        <label>Data:</label>
                        <span class="valor" id="info_data"></span>
                    </div>
                    <div class="info-item">
                        <label>Operador:</label>
                        <span class="valor" id="info_operador"></span>
                    </div>
                    <div class="info-item">
                        <label>Meta:</label>
                        <span class="valor" id="info_meta"></span>
                    </div>
                    <div class="info-item">
                        <label>Produtividade:</label>
                        <span class="valor" id="info_produtividade"></span>
                    </div>
                    <div class="info-item">
                        <label>Horas:</label>
                        <span class="valor" id="info_horas"></span>
                    </div>
                    <div class="info-item">
                        <label>Observação:</label>
                        <span class="valor" id="info_observacao"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_diferenca" class="campo-obrigatorio">Diferença</label>
                    <input type="text" id="edit_diferenca" name="diferenca" class="form-control campo-numerico"
                        placeholder="Ex: 50,25" required>
                </div>

                <div class="form-group">
                    <label for="edit_motivo_alteracao" class="campo-obrigatorio">Motivo da Alteração</label>
                    <textarea id="edit_motivo_alteracao" name="motivo_alteracao" class="form-control"
                        placeholder="Digite o motivo da alteração..." required style="min-height: 80px;"></textarea>
                </div>

                <div style="display: none;">
                    <input type="text" id="edit_data" name="data">
                    <input type="text" id="edit_operador" name="operador">
                    <input type="text" id="edit_meta" name="meta">
                    <input type="text" id="edit_produtividade" name="produtividade">
                    <input type="text" id="edit_horas" name="horas">
                    <textarea id="edit_observacao" name="observacao"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalEditarRegistro()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnSalvarEdicao">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE CONFIRMAÇÃO DE EXCLUSÃO -->
    <div id="modalExcluirRegistro" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-trash" style="color: #e74c3c;"></i>
                    Excluir Registro
                </div>
                <button class="close-modal" onclick="fecharModalExclusao()">&times;</button>
            </div>

            <div class="modal-body">
                <div id="mensagemExclusao" class="alerta" style="display: none;"></div>

                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: #f39c12;"></i>
                </div>

                <p style="text-align: center; font-size: 15px; margin-bottom: 20px; color: #2c3e50;">
                    Tem certeza que deseja excluir este registro permanentemente?
                </p>

                <div class="info-registro" id="infoRegistroExcluir">
                    <div class="info-item">
                        <label>ID:</label>
                        <span class="valor" id="infoIdExcluir"></span>
                    </div>
                    <div class="info-item">
                        <label>Operador:</label>
                        <span class="valor" id="infoOperadorExcluir"></span>
                    </div>
                    <div class="info-item">
                        <label>Data:</label>
                        <span class="valor" id="infoDataExcluir"></span>
                    </div>
                    <div class="info-item">
                        <label>Meta:</label>
                        <span class="valor" id="infoMetaExcluir"></span>
                    </div>
                </div>

                <div class="mensagem-erro" style="margin: 20px 0;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Atenção!</strong> Esta ação não pode ser desfeita.
                </div>

                <div id="loadingExclusao" style="text-align: center; padding: 20px; display: none;">
                    <div class="spinner" style="border-top-color: #e74c3c;"></div>
                    <p style="margin-top: 10px; color: #7f8c8d;">Excluindo registro...</p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalExclusao()" id="btnCancelarExclusao">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmarExclusao()" id="btnConfirmarExclusao">
                    <i class="fas fa-trash"></i> Excluir
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE ALERTA PARA DUPLICIDADE -->
    <div id="modalAlertaDuplicidade" class="modal-alerta">
        <div class="modal-alerta-content">
            <div class="modal-alerta-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="modal-alerta-title">
                <i class="fas fa-ban"></i> Registro Duplicado!
            </div>
            <div class="modal-alerta-mensagem">
                Não é possível cadastrar o mesmo operador na mesma data.
            </div>
            <div class="modal-alerta-detalhes">
                <strong>Detalhes do conflito:</strong><br>
                • <strong>Operador:</strong> <span id="alertaOperador"></span><br>
                • <strong>Data:</strong> <span id="alertaData"></span><br>
                <br>
                <small><i class="fas fa-info-circle"></i> Cada operador pode ter apenas um registro por dia.</small>
            </div>
            <div class="modal-alerta-footer">
                <button type="button" class="btn btn-warning" onclick="fecharModalAlerta()">
                    <i class="fas fa-check"></i> Entendi
                </button>
                <button type="button" class="btn btn-secondary" onclick="alterarDadosDuplicidade()">
                    <i class="fas fa-edit"></i> Alterar Dados
                </button>
            </div>
        </div>
    </div>

    <script>
        var btnSalvarOriginal = '';
        var btnSalvarEdicaoOriginal = '';
        var dadosDuplicidade = null;
        var registroParaExcluir = null;
        var dataOntemUtil = '<?php echo $dataOntemUtil; ?>';
        var dataAnteontemUtil = '<?php echo $dataAnteontemUtil; ?>';

        function formatarNumeroParaExibicao(valor, casasDecimais = 2) {
            if (valor === null || valor === undefined || valor === '' || isNaN(valor)) {
                return '';
            }

            const num = parseFloat(valor);
            if (num === 0) return '';

            let partes = num.toFixed(casasDecimais).split('.');
            let parteInteira = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            if (casasDecimais > 0 && partes[1]) {
                let parteDecimal = partes[1].replace(/0+$/, '');
                if (parteDecimal === '') {
                    return parteInteira;
                }
                return parteInteira + ',' + parteDecimal;
            }

            return parteInteira;
        }

        function converterNumeroParaEnvio(valor) {
            if (!valor || valor === '') return '0';

            let valorConvertido = valor.toString();

            valorConvertido = valorConvertido.replace(/\./g, '');

            valorConvertido = valorConvertido.replace(',', '.');

            return valorConvertido;
        }

        function permitirApenasNumerosComVirgula(event) {
            const tecla = event.key;
            const valorAtual = event.target.value;
            const cursorPos = event.target.selectionStart;

            if (['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight',
                    'Home', 'End', 'Escape', 'PageUp', 'PageDown'
                ].includes(tecla)) {
                return true;
            }

            if (event.ctrlKey && ['a', 'c', 'v', 'x'].includes(tecla.toLowerCase())) {
                return true;
            }

            if (/^\d$/.test(tecla)) {
                return true;
            }

            if (tecla === ',') {
                if (!valorAtual.includes(',')) {
                    return true;
                }
                event.preventDefault();
                return false;
            }

            if (tecla === '.') {
                event.preventDefault();

                if (!valorAtual.includes(',')) {
                    const novoValor = valorAtual.substring(0, cursorPos) + ',' +
                        valorAtual.substring(cursorPos);
                    event.target.value = novoValor;

                    setTimeout(() => {
                        event.target.setSelectionRange(cursorPos + 1, cursorPos + 1);
                    }, 0);
                }
                return false;
            }

            if (tecla === '-' && cursorPos === 0 && !valorAtual.includes('-')) {
                return true;
            }

            event.preventDefault();
            return false;
        }

        function formatarCampoNumericoAoPerderFoco(campo) {
            const valor = campo.value.trim();
            if (!valor) return;

            try {
                let valorParaConverter = valor;

                valorParaConverter = valorParaConverter.replace(/\./g, '');

                valorParaConverter = valorParaConverter.replace(',', '.');

                const numero = parseFloat(valorParaConverter);

                if (isNaN(numero)) {
                    campo.value = '';
                    return;
                }

                let casasDecimais = 2;
                if (campo.id.includes('horas') || campo.name.includes('horas')) {
                    casasDecimais = 1;
                }

                campo.value = formatarNumeroParaExibicao(numero, casasDecimais);

            } catch (error) {
                console.error('Erro ao formatar campo:', error);
                campo.value = '';
            }
        }

        function prepararNumerosParaEnvioNovo() {
            const camposNumericos = ['meta', 'produtividade', 'horas'];

            camposNumericos.forEach(id => {
                const campo = document.getElementById(id);
                if (campo) {
                    campo.value = converterNumeroParaEnvio(campo.value);
                }
            });
        }

        function prepararNumerosParaEnvioEdicao() {
            const campoDiferenca = document.getElementById('edit_diferenca');
            if (campoDiferenca) {
                campoDiferenca.value = converterNumeroParaEnvio(campoDiferenca.value);
            }
        }

        function validarNumeroBrasileiro(valor) {
            if (!valor || valor.trim() === '') {
                return true;
            }

            const valorLimpo = valor.replace(/\./g, '').replace(',', '.');
            const numero = parseFloat(valorLimpo);

            return !isNaN(numero);
        }

        function abrirModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'block';
            btnSalvarOriginal = document.getElementById('btnSalvar').innerHTML;
            limparAvisosDuplicidade();

            // Preencher com data de ontem útil por padrão
            const dataInput = document.getElementById('data');
            if (dataInput && !dataInput.value) {
                dataInput.value = dataOntemUtil;
            }

            setTimeout(() => {
                document.getElementById('tipo').focus();
            }, 100);
        }

        function fecharModalNovoRegistro() {
            document.getElementById('modalNovoRegistro').style.display = 'none';
            document.getElementById('formNovoRegistro').reset();
            if (btnSalvarOriginal) {
                document.getElementById('btnSalvar').innerHTML = btnSalvarOriginal;
                document.getElementById('btnSalvar').disabled = false;
            }
            limparAvisosDuplicidade();
        }

        function editarRegistro(id) {
            try {
                const linha = document.querySelector('tr[data-id="' + id + '"]');

                if (linha) {
                    const dadosRegistro = {
                        id: id,
                        data: linha.getAttribute('data-data'),
                        operador: linha.getAttribute('data-operador'),
                        meta: linha.getAttribute('data-meta') || 0,
                        produtividade: linha.getAttribute('data-produtividade') || 0,
                        horas: linha.getAttribute('data-horas') || 0,
                        diferenca: linha.getAttribute('data-diferenca') || 0,
                        prod_atualizada: linha.getAttribute('data-prod-atualizada') || 0,
                        ocorrencia: linha.getAttribute('data-ocorrencia') || '',
                        mot_alteracao: linha.getAttribute('data-mot-alteracao') || ''
                    };

                    preencherModalEdicao(dadosRegistro);
                    document.getElementById('modalEditarRegistro').style.display = 'block';
                    btnSalvarEdicaoOriginal = document.getElementById('btnSalvarEdicao').innerHTML;
                } else {
                    mostrarMensagem('Registro não encontrado na tabela', 'erro');
                }

            } catch (error) {
                console.error('Erro:', error);
                mostrarMensagem('Erro ao carregar registro para edição', 'erro');
            }
        }

        function preencherModalEdicao(registro) {
            document.getElementById('edit_id').value = registro.id;

            const metaNum = parseFloat(registro.meta) || 0;
            const prodNum = parseFloat(registro.produtividade) || 0;
            const horasNum = parseFloat(registro.horas) || 0;
            const diferencaNum = parseFloat(registro.diferenca) || 0;

            document.getElementById('info_id').textContent = registro.id;
            document.getElementById('info_data').textContent = formatarDataBrasileira(registro.data);
            document.getElementById('info_operador').textContent = registro.operador;
            document.getElementById('info_meta').textContent = metaNum !== 0 ? formatarNumeroParaExibicao(metaNum, 2) : '-';
            document.getElementById('info_produtividade').textContent = prodNum !== 0 ? formatarNumeroParaExibicao(prodNum, 2) : '-';
            document.getElementById('info_horas').textContent = horasNum !== 0 ? formatarNumeroParaExibicao(horasNum, 1) + ' H' : '-';
            document.getElementById('info_observacao').textContent = registro.ocorrencia || '(sem observação)';

            document.getElementById('edit_data').value = registro.data;
            document.getElementById('edit_operador').value = registro.operador;
            document.getElementById('edit_meta').value = registro.meta;
            document.getElementById('edit_produtividade').value = registro.produtividade;
            document.getElementById('edit_horas').value = registro.horas;
            document.getElementById('edit_observacao').value = registro.ocorrencia;

            document.getElementById('edit_diferenca').value = diferencaNum !== 0 ? formatarNumeroParaExibicao(diferencaNum, 2) : '';
            document.getElementById('edit_motivo_alteracao').value = registro.mot_alteracao;

            setTimeout(() => {
                document.getElementById('edit_diferenca').focus();
            }, 100);
        }

        function fecharModalEditarRegistro() {
            document.getElementById('modalEditarRegistro').style.display = 'none';
            document.getElementById('formEditarRegistro').reset();
            if (btnSalvarEdicaoOriginal) {
                document.getElementById('btnSalvarEdicao').innerHTML = btnSalvarEdicaoOriginal;
                document.getElementById('btnSalvarEdicao').disabled = false;
            }
        }

        function excluirRegistro(id) {
            registroParaExcluir = id;
            document.getElementById('modalExcluirRegistro').style.display = 'block';
            document.getElementById('mensagemExclusao').style.display = 'none';
            document.getElementById('loadingExclusao').style.display = 'none';
            document.getElementById('btnCancelarExclusao').disabled = false;
            document.getElementById('btnConfirmarExclusao').disabled = false;
            carregarDetalhesParaExclusao(id);
        }

        function carregarDetalhesParaExclusao(id) {
            const linha = document.querySelector('tr[data-id="' + id + '"]');

            if (linha) {
                const metaNum = parseFloat(linha.getAttribute('data-meta')) || 0;

                document.getElementById('infoIdExcluir').textContent = id;
                document.getElementById('infoOperadorExcluir').textContent = linha.getAttribute('data-operador');
                document.getElementById('infoDataExcluir').textContent = formatarDataBrasileira(linha.getAttribute('data-data'));
                document.getElementById('infoMetaExcluir').textContent = metaNum !== 0 ? formatarNumeroParaExibicao(metaNum, 2) : '-';
            } else {
                document.getElementById('infoIdExcluir').textContent = id;
                document.getElementById('infoOperadorExcluir').textContent = 'Não encontrado';
                document.getElementById('infoDataExcluir').textContent = 'N/A';
                document.getElementById('infoMetaExcluir').textContent = 'N/A';
            }
        }

        function fecharModalExclusao() {
            document.getElementById('modalExcluirRegistro').style.display = 'none';
            registroParaExcluir = null;
        }

        function abrirModalAlertaDuplicidade(operador, data) {
            document.getElementById('alertaOperador').textContent = operador;
            document.getElementById('alertaData').textContent = formatarDataBrasileira(data);
            dadosDuplicidade = {
                operador: operador,
                data: data
            };
            document.getElementById('modalAlertaDuplicidade').style.display = 'block';
        }

        function fecharModalAlerta() {
            document.getElementById('modalAlertaDuplicidade').style.display = 'none';
            dadosDuplicidade = null;
        }

        function alterarDadosDuplicidade() {
            fecharModalAlerta();
            if (document.getElementById('modalNovoRegistro').style.display !== 'block') {
                document.getElementById('modalNovoRegistro').style.display = 'block';
            }

            const campoData = document.getElementById('data');
            const campoOperador = document.getElementById('operador');

            campoData.classList.add('campo-duplicado');
            campoOperador.classList.add('campo-duplicado');

            const avisoData = document.getElementById('avisoData');
            const avisoOperador = document.getElementById('avisoOperador');

            avisoData.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Data com conflito';
            avisoData.style.display = 'block';

            avisoOperador.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Operador já cadastrado nesta data';
            avisoOperador.style.display = 'block';

            campoData.focus();
        }

        function limparAvisosDuplicidade() {
            const campoData = document.getElementById('data');
            const campoOperador = document.getElementById('operador');

            if (campoData) campoData.classList.remove('campo-duplicado');
            if (campoOperador) campoOperador.classList.remove('campo-duplicado');

            const avisoData = document.getElementById('avisoData');
            const avisoOperador = document.getElementById('avisoOperador');

            if (avisoData) avisoData.style.display = 'none';
            if (avisoOperador) avisoOperador.style.display = 'none';
        }

        function formatarDataBrasileira(dataString) {
            if (!dataString) return '';
            const data = new Date(dataString);
            const dia = String(data.getDate()).padStart(2, '0');
            const mes = String(data.getMonth() + 1).padStart(2, '0');
            const ano = data.getFullYear();
            return dia + '/' + mes + '/' + ano;
        }

        function mostrarMensagem(mensagem, tipo) {
            var alerta = document.getElementById('mensagemAlerta');
            alerta.textContent = mensagem;
            alerta.className = 'alerta ' + (tipo === 'sucesso' ? 'mensagem-sucesso' : 'mensagem-erro');
            alerta.style.display = 'block';

            setTimeout(function() {
                alerta.style.display = 'none';
            }, 5000);
        }

        function mostrarMensagemExclusao(mensagem, tipo) {
            const divMensagem = document.getElementById('mensagemExclusao');
            divMensagem.textContent = mensagem;
            divMensagem.className = 'alerta ' + (tipo === 'sucesso' ? 'mensagem-sucesso' : 'mensagem-erro');
            divMensagem.style.display = 'block';
        }

        function limparFiltro() {
            window.location.href = window.location.pathname;
        }

        function mostrarTodosRegistros() {
            const dataFim = new Date().toISOString().split('T')[0];
            const dataInicio = new Date();
            dataInicio.setDate(dataInicio.getDate() - 30);
            const dataInicioFormatada = dataInicio.toISOString().split('T')[0];

            window.location.href = `?data_inicio=${dataInicioFormatada}&data_fim=${dataFim}`;
        }

        function mostrarHoje() {
            const hoje = new Date().toISOString().split('T')[0];
            window.location.href = `?data_inicio=${hoje}&data_fim=${hoje}`;
        }

        function mostrarOntemUtil() {
            window.location.href = `?data_inicio=${dataOntemUtil}&data_fim=${dataOntemUtil}`;
        }

        function mostrarUltimos5DiasUteis() {
            const hoje = new Date();
            let diasUteisEncontrados = 0;
            let dataFim = hoje;
            let dataInicio = new Date();

            // Encontrar o último dia útil (pode ser hoje se for dia útil)
            while (diasUteisEncontrados < 1) {
                const diaSemana = dataFim.getDay(); // 0 = domingo, 6 = sábado
                if (diaSemana !== 0 && diaSemana !== 6) {
                    diasUteisEncontrados++;
                } else {
                    dataFim.setDate(dataFim.getDate() - 1);
                }
            }

            // Agora encontrar 4 dias úteis anteriores
            dataInicio = new Date(dataFim);
            diasUteisEncontrados = 0;
            while (diasUteisEncontrados < 4) {
                dataInicio.setDate(dataInicio.getDate() - 1);
                const diaSemana = dataInicio.getDay();
                if (diaSemana !== 0 && diaSemana !== 6) {
                    diasUteisEncontrados++;
                }
            }

            const dataInicioFormatada = dataInicio.toISOString().split('T')[0];
            const dataFimFormatada = dataFim.toISOString().split('T')[0];

            window.location.href = `?data_inicio=${dataInicioFormatada}&data_fim=${dataFimFormatada}`;
        }

        function mostrarUltimos7Dias() {
            const dataFim = new Date().toISOString().split('T')[0];
            const dataInicio = new Date();
            dataInicio.setDate(dataInicio.getDate() - 7);
            const dataInicioFormatada = dataInicio.toISOString().split('T')[0];

            window.location.href = `?data_inicio=${dataInicioFormatada}&data_fim=${dataFim}`;
        }

        function mostrarEsteMes() {
            const hoje = new Date();
            const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
            const ultimoDia = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);

            const dataInicioFormatada = primeiroDia.toISOString().split('T')[0];
            const dataFimFormatada = ultimoDia.toISOString().split('T')[0];

            window.location.href = `?data_inicio=${dataInicioFormatada}&data_fim=${dataFimFormatada}`;
        }

        function salvarRegistro() {
            prepararNumerosParaEnvioNovo();

            const formData = new FormData(document.getElementById('formNovoRegistro'));

            const camposObrigatorios = ['data', 'tipo', 'operador', 'meta'];
            var camposFaltando = [];

            camposObrigatorios.forEach(function(campo) {
                if (!formData.get(campo) || formData.get(campo).toString().trim() === '') {
                    camposFaltando.push(campo);
                }
            });

            if (camposFaltando.length > 0) {
                mostrarMensagem('Preencha todos os campos obrigatórios: ' + camposFaltando.join(', '), 'erro');
                return false;
            }

            const camposNumericos = ['meta', 'produtividade', 'horas'];
            let camposInvalidos = [];

            camposNumericos.forEach(campo => {
                const valor = formData.get(campo);
                if (valor && valor !== '0') {
                    if (!validarNumeroBrasileiro(valor)) {
                        camposInvalidos.push(campo);
                    }
                }
            });

            if (camposInvalidos.length > 0) {
                mostrarMensagem('Valores inválidos nos campos: ' + camposInvalidos.join(', ') + '. Use números com vírgula como decimal.', 'erro');
                return false;
            }

            const btnSalvar = document.getElementById('btnSalvar');
            btnSalvar.innerHTML = '<div class="spinner"></div> Salvando...';
            btnSalvar.disabled = true;

            fetch('metas_insert.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Erro na requisição: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.sucesso) {
                        mostrarMensagem(data.mensagem, 'sucesso');
                        fecharModalNovoRegistro();
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        if (data.mensagem.includes('já possui um registro') ||
                            data.mensagem.includes('mesmo operador') ||
                            data.mensagem.includes('duplicado')) {

                            const operador = document.getElementById('operador').value;
                            const dataSelecionada = document.getElementById('data').value;
                            abrirModalAlertaDuplicidade(operador, dataSelecionada);
                        } else {
                            mostrarMensagem('Erro: ' + data.mensagem, 'erro');
                        }

                        btnSalvar.innerHTML = btnSalvarOriginal;
                        btnSalvar.disabled = false;
                    }
                })
                .catch(function(error) {
                    console.error('Erro completo:', error);
                    mostrarMensagem('Erro de comunicação: ' + error.message, 'erro');
                    btnSalvar.innerHTML = btnSalvarOriginal;
                    btnSalvar.disabled = false;
                });

            return false;
        }

        function salvarEdicao() {
            const campoDiferenca = document.getElementById('edit_diferenca');
            if (campoDiferenca && campoDiferenca.value.trim() !== '') {
                if (!validarNumeroBrasileiro(campoDiferenca.value)) {
                    mostrarMensagem('Valor inválido para diferença. Use números com vírgula como decimal.', 'erro');
                    campoDiferenca.focus();
                    return false;
                }

                campoDiferenca.value = converterNumeroParaEnvio(campoDiferenca.value);
            } else {
                campoDiferenca.value = '0';
            }

            const formData = new FormData(document.getElementById('formEditarRegistro'));

            const camposObrigatorios = ['diferenca', 'motivo_alteracao'];
            var camposFaltando = [];

            camposObrigatorios.forEach(function(campo) {
                if (!formData.get(campo) || formData.get(campo).toString().trim() === '') {
                    camposFaltando.push(campo);
                }
            });

            if (camposFaltando.length > 0) {
                mostrarMensagem('Preencha todos os campos obrigatórios: ' + camposFaltando.join(', '), 'erro');
                return false;
            }

            const btnSalvarEdicao = document.getElementById('btnSalvarEdicao');
            btnSalvarEdicao.innerHTML = '<div class="spinner"></div> Salvando...';
            btnSalvarEdicao.disabled = true;

            fetch('metas_update.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Erro na requisição: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.sucesso) {
                        mostrarMensagem(data.mensagem, 'sucesso');
                        fecharModalEditarRegistro();
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        mostrarMensagem('Erro: ' + data.mensagem, 'erro');
                        btnSalvarEdicao.innerHTML = btnSalvarEdicaoOriginal;
                        btnSalvarEdicao.disabled = false;
                    }
                })
                .catch(function(error) {
                    console.error('Erro completo:', error);
                    mostrarMensagem('Erro de comunicação: ' + error.message, 'erro');
                    btnSalvarEdicao.innerHTML = btnSalvarEdicaoOriginal;
                    btnSalvarEdicao.disabled = false;
                });

            return false;
        }

        function confirmarExclusao() {
            if (!registroParaExcluir) return;

            document.getElementById('btnCancelarExclusao').disabled = true;
            document.getElementById('btnConfirmarExclusao').disabled = true;
            document.getElementById('loadingExclusao').style.display = 'block';
            document.getElementById('mensagemExclusao').style.display = 'none';

            const formData = new FormData();
            formData.append('id', registroParaExcluir);

            fetch('metas_delete.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    document.getElementById('loadingExclusao').style.display = 'none';

                    if (data.sucesso) {
                        mostrarMensagemExclusao('✓ Registro excluído com sucesso! A página será recarregada...', 'sucesso');
                        setTimeout(function() {
                            fecharModalExclusao();
                            window.location.reload();
                        }, 2000);
                    } else {
                        mostrarMensagemExclusao('✗ Erro: ' + data.mensagem, 'erro');
                        document.getElementById('btnCancelarExclusao').disabled = false;
                        document.getElementById('btnConfirmarExclusao').disabled = false;
                    }
                })
                .catch(function(error) {
                    console.error('Erro:', error);
                    document.getElementById('loadingExclusao').style.display = 'none';
                    mostrarMensagemExclusao('✗ Erro de comunicação com o servidor', 'erro');
                    document.getElementById('btnCancelarExclusao').disabled = false;
                    document.getElementById('btnConfirmarExclusao').disabled = false;
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('formNovoRegistro').addEventListener('submit', function(e) {
                e.preventDefault();
                salvarRegistro();
            });

            document.getElementById('formEditarRegistro').addEventListener('submit', function(e) {
                e.preventDefault();
                salvarEdicao();
            });

            const camposNumericos = ['meta', 'produtividade', 'horas', 'edit_diferenca'];

            camposNumericos.forEach(function(id) {
                const campo = document.getElementById(id);
                if (campo) {
                    campo.addEventListener('keydown', permitirApenasNumerosComVirgula);

                    campo.addEventListener('blur', function() {
                        formatarCampoNumericoAoPerderFoco(this);
                    });

                    campo.addEventListener('focus', function() {
                        this.setAttribute('data-valor-original', this.value);
                    });

                    campo.addEventListener('input', function(e) {
                        if (this.value.length === 2 && this.value.startsWith('0') && /\d/.test(this.value[1])) {
                            this.value = this.value.substring(1);
                        }
                    });
                }
            });

            window.addEventListener('click', function(event) {
                const modais = ['modalNovoRegistro', 'modalEditarRegistro', 'modalExcluirRegistro', 'modalAlertaDuplicidade'];
                modais.forEach(function(modalId) {
                    const modal = document.getElementById(modalId);
                    if (event.target == modal) {
                        if (modalId === 'modalNovoRegistro') fecharModalNovoRegistro();
                        if (modalId === 'modalEditarRegistro') fecharModalEditarRegistro();
                        if (modalId === 'modalExcluirRegistro') fecharModalExclusao();
                        if (modalId === 'modalAlertaDuplicidade') fecharModalAlerta();
                    }
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    fecharModalAlerta();
                    fecharModalExclusao();
                    fecharModalEditarRegistro();
                    fecharModalNovoRegistro();
                }
            });

            document.querySelector('form[method="GET"]').addEventListener('submit', function(e) {
                var dataInicio = document.getElementById('data_inicio').value;
                var dataFim = document.getElementById('data_fim').value;

                if (dataInicio && dataFim) {
                    var inicio = new Date(dataInicio);
                    var fim = new Date(dataFim);

                    if (inicio > fim) {
                        e.preventDefault();
                        mostrarMensagem('A data inicial não pode ser maior que a data final!', 'erro');
                    }
                }
            });

            const dataInput = document.getElementById('data');
            const operadorSelect = document.getElementById('operador');

            if (dataInput && operadorSelect) {
                dataInput.addEventListener('change', function() {
                    setTimeout(verificarDuplicidadeEmTempoReal, 100);
                });

                operadorSelect.addEventListener('change', function() {
                    setTimeout(verificarDuplicidadeEmTempoReal, 100);
                });
            }

            // Melhorar a experiência em dispositivos móveis
            if ('ontouchstart' in window) {
                document.querySelectorAll('.btn-acao, .btn, .form-control').forEach(el => {
                    el.style.touchAction = 'manipulation';
                });

                // Aumentar área de toque para botões pequenos
                document.querySelectorAll('.btn-acao').forEach(btn => {
                    btn.style.padding = '8px';
                    btn.style.minHeight = '44px';
                    btn.style.minWidth = '44px';
                });
            }
        });

        function verificarDuplicidadeEmTempoReal() {
            const data = document.getElementById('data').value;
            const operador = document.getElementById('operador').value;

            if (!data || !operador || operador === '') {
                return;
            }

            const formData = new FormData();
            formData.append('verificar_duplicidade', 'true');
            formData.append('data', data);
            formData.append('operador', operador);

            fetch('metas_insert.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.existe) {
                        document.getElementById('data').classList.add('campo-duplicado');
                        document.getElementById('operador').classList.add('campo-duplicado');

                        const avisoData = document.getElementById('avisoData');
                        const avisoOperador = document.getElementById('avisoOperador');

                        avisoData.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Data com registro existente';
                        avisoData.style.display = 'block';

                        avisoOperador.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Operador já cadastrado nesta data';
                        avisoOperador.style.display = 'block';
                    } else {
                        limparAvisosDuplicidade();
                    }
                })
                .catch(function(error) {
                    console.error('Erro na verificação:', error);
                });
        }
    </script>
</body>

</html>