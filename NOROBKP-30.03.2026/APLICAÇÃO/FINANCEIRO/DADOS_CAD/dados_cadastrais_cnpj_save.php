<?php

/**
 * Script para atualizar dados cadastrais de CNPJ no SIC
 * Arquivo: dados_cadastrais_cnpj_save.php
 * 
 * Alterações: Adicionado campo usu_alterou dinâmico
 * Correção: Formatação de telefone e CEP no padrão SIC
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

function logSaveMessage($message, $type = 'info')
{
    $logFile = 'cnpj_update_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function formatarParaFirebird($valor, $maxLength = null)
{
    if (empty($valor) || $valor === 'Não informado') return null;

    $valor = trim($valor);

    if ($maxLength !== null && strlen($valor) > $maxLength) {
        logSaveMessage("Valor truncado: '$valor' -> '" . substr($valor, 0, $maxLength) . "' (limite: $maxLength)", 'warning');
        $valor = substr($valor, 0, $maxLength);
    }

    return $valor;
}

function somenteNumeros($valor)
{
    if (empty($valor)) return null;
    return preg_replace('/[^0-9]/', '', $valor);
}

/**
 * Formata telefone no padrão SIC com zero antes do DDD
 * Para 10 dígitos: (0XX)XXXX-XXXX
 * Para 11 dígitos: (0XX)XXXXX-XXXX
 */
function formatarTelefoneSIC($telefone)
{
    if (empty($telefone)) return null;

    // Remove tudo que não é número
    $numeros = preg_replace('/[^0-9]/', '', $telefone);

    // Se não tem números, retorna null
    if (empty($numeros)) return null;

    // Remove o primeiro dígito se for 0 (DDI Brasil) - mas vamos adicionar depois
    if (strlen($numeros) >= 11 && substr($numeros, 0, 1) === '0') {
        $numeros = substr($numeros, 1);
    }

    // Formata conforme a quantidade de dígitos, sempre com zero antes do DDD
    if (strlen($numeros) === 10) {
        // Formato (0XX)XXXX-XXXX
        $ddd = substr($numeros, 0, 2);
        $numero = substr($numeros, 2, 4);
        $sufixo = substr($numeros, 6, 4);
        return '(0' . $ddd . ')' . $numero . '-' . $sufixo;
    } elseif (strlen($numeros) === 11) {
        // Formato (0XX)XXXXX-XXXX
        $ddd = substr($numeros, 0, 2);
        $numero = substr($numeros, 2, 5);
        $sufixo = substr($numeros, 7, 4);
        return '(0' . $ddd . ')' . $numero . '-' . $sufixo;
    }

    // Se não tem 10 ou 11 dígitos, tenta formatar com o que tem
    if (strlen($numeros) >= 8) {
        // Tenta extrair DDD (primeiros 2 dígitos)
        $ddd = substr($numeros, 0, 2);
        $resto = substr($numeros, 2);

        if (strlen($resto) === 8) {
            return '(0' . $ddd . ')' . substr($resto, 0, 4) . '-' . substr($resto, 4, 4);
        } elseif (strlen($resto) === 9) {
            return '(0' . $ddd . ')' . substr($resto, 0, 5) . '-' . substr($resto, 5, 4);
        }
    }

    // Se não conseguiu formatar, retorna os números puros
    return $numeros;
}

/**
 * Formata CEP no padrão SIC: XXXXX-XXX
 */
function formatarCEPSIC($cep)
{
    if (empty($cep)) return null;

    // Remove tudo que não é número
    $numeros = preg_replace('/[^0-9]/', '', $cep);

    // Se tem 8 dígitos, formata
    if (strlen($numeros) === 8) {
        return substr($numeros, 0, 5) . '-' . substr($numeros, 5, 3);
    }

    // Se não tem 8 dígitos, retorna os números puros
    return $numeros;
}

function converterRegimeTributario($regime)
{
    if (empty($regime) || $regime === 'Não informado') {
        return null;
    }

    $regime = strtoupper(trim($regime));

    if ($regime === 'NORMAL' || $regime === 'LUCRO REAL' || $regime === 'LUCRO PRESUMIDO') {
        return 1;
    }

    if ($regime === 'SIMPLES NACIONAL' || $regime === 'SIMPLES') {
        return 3;
    }

    return null;
}

function buscarSeqCidade($conexao, $cidade, $uf)
{
    if (empty($cidade) || empty($uf)) {
        return null;
    }

    $sql = "SELECT seqcidade
            FROM cidades
            WHERE UPPER(nome) LIKE UPPER(:cidade)
            AND UPPER(estado) = UPPER(:uf)
            FETCH FIRST 1 ROW ONLY";

    $cidadeLike = '%' . $cidade . '%';

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':cidade', $cidadeLike);
    $stmt->bindParam(':uf', $uf);

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && isset($result['SEQCIDADE'])) {
        return (int)$result['SEQCIDADE'];
    }

    return null;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['save_action']) || $input['save_action'] !== 'salvar_sic_cnpj') {
        throw new Exception('Requisição inválida');
    }

    $unidades = $input['unidades'] ?? [];
    $dados_cnpj = $input['dados_cnpj'] ?? [];
    $usu_alterou = trim($input['usu_alterou'] ?? '');

    if (empty($unidades)) {
        throw new Exception('Nenhuma unidade selecionada');
    }

    if (empty($dados_cnpj)) {
        throw new Exception('Dados do CNPJ não informados');
    }

    if (empty($usu_alterou)) {
        throw new Exception('Usuário responsável não informado');
    }

    $tipo_regime = converterRegimeTributario($dados_cnpj['regime_icms'] ?? null);

    $conexoes_firebird = [
        'barra_mansa' => [
            'nome' => 'SIC BARRA MANSA',
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_BM/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'botucatu' => [
            'nome' => 'SIC BOTUCATU',
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_Botucatu/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'lins' => [
            'nome' => 'SIC LINS',
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_Lins/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'rio_preto' => [
            'nome' => 'SIC RIO PRETO',
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_RP/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'votuporanga' => [
            'nome' => 'SIC VOTUPORANGA / RONDONOPOLIS',
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ]
    ];

    $results = [];
    $successCount = 0;
    $errorCount = 0;
    $warningCount = 0;

    foreach ($unidades as $unidadeInfo) {
        list($unidadeKey, $codic) = explode('|', $unidadeInfo);
        $codic = (int)$codic;

        if (!isset($conexoes_firebird[$unidadeKey])) {
            $results[] = [
                'unidade' => $unidadeKey,
                'codic' => $codic,
                'status' => 'Erro',
                'message' => 'Unidade não encontrada'
            ];
            $errorCount++;
            continue;
        }

        $config = $conexoes_firebird[$unidadeKey];

        try {
            $conexao = new PDO($config['dsn'], $config['user'], $config['pass']);
            $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $checkSql = "SELECT *
                         FROM arqcad
                         WHERE codic = :codic
                         AND tipoc = 'C'
                         AND situ IN ('A', 'B')";

            $checkStmt = $conexao->prepare($checkSql);
            $checkStmt->bindParam(':codic', $codic, PDO::PARAM_INT);
            $checkStmt->execute();

            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new Exception("Registro não encontrado");
            }

            $nome = formatarParaFirebird($dados_cnpj['razao'] ?? null, 60);
            $nfantasia = formatarParaFirebird($dados_cnpj['fantasia'] ?? null, 30);

            $dt_fundacao = null;
            if (!empty($dados_cnpj['dt_abertura'])) {
                $dateObj = DateTime::createFromFormat('d/m/Y', $dados_cnpj['dt_abertura']);
                if ($dateObj) {
                    $dt_fundacao = $dateObj->format('Y-m-d');
                }
            }

            $ende = formatarParaFirebird($dados_cnpj['logradouro'] ?? null, 60);
            $ende_nro = formatarParaFirebird($dados_cnpj['numero'] ?? null, 10);
            $ende_complemento = formatarParaFirebird($dados_cnpj['complemento'] ?? null, 20);
            $bairro = formatarParaFirebird($dados_cnpj['bairro'] ?? null, 30);

            // Formata CEP no padrão SIC (XXXXX-XXX)
            $cep_raw = $dados_cnpj['cep'] ?? null;
            $ncep = null;
            if (!empty($cep_raw) && $cep_raw !== 'Não informado') {
                $ncep = formatarCEPSIC($cep_raw);
                logSaveMessage("CEP original: '$cep_raw' -> formatado: '$ncep'", 'info');
            }

            // Formata telefone no padrão SIC (XX)XXXX-XXXX ou (XX)XXXXX-XXXX
            $fone_raw = $dados_cnpj['fone_completo'] ?? null;
            $fone1 = null;
            if (!empty($fone_raw) && $fone_raw !== 'Não informado') {
                $fone1 = formatarTelefoneSIC($fone_raw);
                logSaveMessage("Telefone original: '$fone_raw' -> formatado: '$fone1'", 'info');
            }

            $email = formatarParaFirebird($dados_cnpj['email'] ?? null, 60);

            $seqcidade = buscarSeqCidade(
                $conexao,
                $dados_cnpj['cidade'] ?? null,
                $dados_cnpj['uf'] ?? null
            );

            $updateFields = [];
            $params = [':codic' => $codic];

            if ($nome !== null) {
                $updateFields[] = "nome = :nome";
                $params[':nome'] = $nome;
            }

            if ($nfantasia !== null) {
                $updateFields[] = "nfantasia = :nfantasia";
                $params[':nfantasia'] = $nfantasia;
            }

            if ($dt_fundacao !== null) {
                $updateFields[] = "dt_fundacao = :dt_fundacao";
                $params[':dt_fundacao'] = $dt_fundacao;
            }

            if ($ende !== null) {
                $updateFields[] = "ende = :ende";
                $params[':ende'] = $ende;
            }

            if ($ende_nro !== null) {
                $updateFields[] = "ende_nro = :ende_nro";
                $params[':ende_nro'] = $ende_nro;
            }

            if ($ende_complemento !== null) {
                $updateFields[] = "ende_complemento = :ende_complemento";
                $params[':ende_complemento'] = $ende_complemento;
            }

            if ($bairro !== null) {
                $updateFields[] = "bairro = :bairro";
                $params[':bairro'] = $bairro;
            }

            if ($ncep !== null) {
                $updateFields[] = "ncep = :ncep";
                $params[':ncep'] = $ncep;
            }

            if ($fone1 !== null) {
                $updateFields[] = "fone1 = :fone1";
                $params[':fone1'] = $fone1;
            }

            if ($email !== null) {
                $updateFields[] = "email = :email";
                $params[':email'] = $email;
            }

            if ($seqcidade !== null) {
                $updateFields[] = "seqcidade = :seqcidade";
                $params[':seqcidade'] = $seqcidade;
            }

            if ($tipo_regime !== null) {
                $updateFields[] = "tipo_regime = :tipo_regime";
                $params[':tipo_regime'] = $tipo_regime;
            }

            $updateFields[] = "usu_alterou = :usu_alterou";
            $updateFields[] = "dt_alteracao = CURRENT_TIMESTAMP";
            $params[':usu_alterou'] = $usu_alterou;

            $updateSql = "UPDATE arqcad
                          SET " . implode(", ", $updateFields) . "
                          WHERE codic = :codic
                          AND tipoc = 'C'
                          AND situ in ('A', 'B')";

            $stmt = $conexao->prepare($updateSql);

            foreach ($params as $key => $value) {
                if ($key === ':codic') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }

            $stmt->execute();

            $rowsAffected = $stmt->rowCount();

            if ($rowsAffected > 0) {
                $results[] = [
                    'unidade' => $config['nome'],
                    'codic' => $codic,
                    'status' => 'Sucesso',
                    'message' => 'Dados atualizados com sucesso'
                ];
                $successCount++;
            } else {
                $results[] = [
                    'unidade' => $config['nome'],
                    'codic' => $codic,
                    'status' => 'Aviso',
                    'message' => 'Nenhuma alteração realizada (dados já estavam atualizados)'
                ];
                $warningCount++;
            }
        } catch (Exception $e) {
            $results[] = [
                'unidade' => $config['nome'],
                'codic' => $codic,
                'status' => 'Erro',
                'message' => $e->getMessage()
            ];
            $errorCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Processamento concluído: ✅ $successCount sucesso(s), ⚠️ $warningCount aviso(s), ❌ $errorCount erro(s)",
        'results' => $results,
        'total_success' => $successCount,
        'total_warnings' => $warningCount,
        'total_errors' => $errorCount
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
