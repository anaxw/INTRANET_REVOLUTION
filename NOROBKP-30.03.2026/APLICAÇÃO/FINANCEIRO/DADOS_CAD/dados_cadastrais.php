<?php

/**
 * Consulta Integrada - SIC + NeoCredit
 * Versão: 2.4 - Removido hostname e filtro de unidades sem cadastro
 */

// Configuração de erro para desenvolvimento
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Conexão com o banco de dados PostgreSQL
try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");
} catch (PDOException $e) {
    error_log("Erro de conexão com o banco: " . $e->getMessage());
}

// Configurações das conexões Firebird
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

// Token da NeoCredit
$neo_token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzbHVnIjoiNmUzOTg2OGQtODUzYy00Zjg4LTgxMWUtMDg4NTI2Njk4MDNhIiwiZW1wcmVzYUlkIjoyNDYsIm5vbWUiOiJFc3RlaXJhIE5vdmEifQ.p0_PAIVn7SmnZDqI0atppDY1Z9V1ehSi2nrkZRp6XEI';

// Mapeamento de campos para exibição
$campos_cnpj = [
    'CODIC' => ['descricao' => 'Código (CODIC)', 'neo' => null, 'sic' => 'codic', 'comparar' => true],
    'DOCUMENTO' => ['descricao' => 'CNPJ', 'neo' => 'cnpj', 'sic' => 'documento', 'comparar' => true],
    'NOME' => ['descricao' => 'Razão Social', 'neo' => 'razao', 'sic' => 'nome', 'comparar' => true],
    'FANTASIA' => ['descricao' => 'Nome Fantasia', 'neo' => 'fantasia', 'sic' => 'nfantasia', 'comparar' => true],
    'DATA DA FUNDAÇÃO' => ['descricao' => 'Data de Abertura', 'neo' => 'dt_abertura', 'sic' => 'dt_fundacao', 'comparar' => true],
    'ENDEREÇO' => ['descricao' => 'Logradouro', 'neo' => 'logradouro', 'sic' => 'ende', 'comparar' => true],
    'NUMERO' => ['descricao' => 'Número', 'neo' => 'numero', 'sic' => 'ende_nro', 'comparar' => true],
    'COMPLEMENTO' => ['descricao' => 'Complemento', 'neo' => 'complemento', 'sic' => 'ende_complemento', 'comparar' => true],
    'BAIRRO' => ['descricao' => 'Bairro', 'neo' => 'bairro', 'sic' => 'bairro', 'comparar' => true],
    'CIDADE' => ['descricao' => 'Cidade', 'neo' => 'cidade', 'sic' => 'cidade', 'comparar' => true],
    'ESTADO' => ['descricao' => 'UF', 'neo' => 'uf', 'sic' => 'estado', 'comparar' => true],
    'CEP' => ['descricao' => 'CEP', 'neo' => 'cep', 'sic' => 'ncep', 'comparar' => true],
    'EMAIL' => ['descricao' => 'E-mail', 'neo' => 'email', 'sic' => 'email', 'comparar' => true],
    'TELEFONE' => ['descricao' => 'Telefone', 'neo' => 'fone_completo', 'sic' => 'fone1', 'comparar' => true],
    'TIPO DE REGIME' => ['descricao' => 'Regime Tributário', 'neo' => 'regime_icms', 'sic' => 'tipo_regime', 'comparar' => true]
];

$campos_cpf = [
    'CODIC' => ['descricao' => 'Código (CODIC)', 'neo' => null, 'sic' => 'codic', 'comparar' => true],
    'DOCUMENTO' => ['descricao' => 'CPF', 'neo' => 'cpf', 'sic' => 'documento', 'comparar' => true],
    'NOME' => ['descricao' => 'Nome Completo', 'neo' => 'nome', 'sic' => 'nome', 'comparar' => true],
    'DATA DA FUNDAÇÃO' => ['descricao' => 'Data de Nascimento', 'neo' => 'dt_nascimento', 'sic' => 'dt_fundacao', 'comparar' => true],
];

// ==================== FUNÇÕES AUXILIARES ====================

function limparCaracteresEspeciais($texto)
{
    if (empty($texto)) return $texto;
    $texto = preg_replace('/[^\x20-\x7E\xC0-\xFF]/u', '', $texto);
    $texto = preg_replace('/[\x00-\x1F\x7F]/', '', $texto);
    return $texto;
}

function decodificarJSONSeguro($jsonString)
{
    if (empty($jsonString)) return null;
    $jsonString = limparCaracteresEspeciais($jsonString);
    $jsonString = preg_replace('/^\xEF\xBB\xBF/', '', $jsonString);
    $data = json_decode($jsonString, true);
    if (json_last_error() === JSON_ERROR_NONE) return $data;
    $jsonString = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $jsonString);
    $data = json_decode($jsonString, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

function extrairEmailDoReceita($receita, $campos)
{
    if (isset($receita['emails']) && is_array($receita['emails']) && count($receita['emails']) > 0) {
        foreach ($receita['emails'] as $emailItem) {
            if (isset($emailItem['email']) && !empty($emailItem['email']) && $emailItem['email'] !== 'Não informado') {
                return limparCaracteresEspeciais($emailItem['email']);
            }
        }
    }
    if (isset($campos['email']) && !empty($campos['email']) && $campos['email'] !== 'Não informado') {
        return limparCaracteresEspeciais($campos['email']);
    }
    if (isset($receita['contato'])) {
        if (isset($receita['contato']['email']) && !empty($receita['contato']['email'])) {
            return limparCaracteresEspeciais($receita['contato']['email']);
        }
        if (isset($receita['contato']['email_principal']) && !empty($receita['contato']['email_principal'])) {
            return limparCaracteresEspeciais($receita['contato']['email_principal']);
        }
    }
    if (isset($receita['email']) && !empty($receita['email'])) {
        return limparCaracteresEspeciais($receita['email']);
    }
    if (isset($receita['endereco_eletronico']) && !empty($receita['endereco_eletronico'])) {
        return limparCaracteresEspeciais($receita['endereco_eletronico']);
    }
    return 'Não informado';
}

function extrairRegimeIcmsDoSintegra($campos)
{
    // Primeiro, tenta encontrar o campo específico do Sintegra que está no formato correto
    $campoSintegraEspecifico = null;
    foreach ($campos as $key => $value) {
        if (strpos($key, 'resultado_sintegra_completo') !== false && !empty($value)) {
            $campoSintegraEspecifico = $value;
            break;
        }
    }

    if (!empty($campoSintegraEspecifico)) {
        $sintegraData = null;
        if (is_string($campoSintegraEspecifico)) {
            $campoLimpo = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $campoSintegraEspecifico);
            $campoLimpo = trim($campoLimpo);
            $campoLimpo = preg_replace('/^\xEF\xBB\xBF/', '', $campoLimpo);
            $sintegraData = json_decode($campoLimpo, true);
        } elseif (is_array($campoSintegraEspecifico)) {
            $sintegraData = $campoSintegraEspecifico;
        }

        if (is_array($sintegraData)) {
            // Procura na lista_ie
            if (isset($sintegraData['lista_ie']) && is_array($sintegraData['lista_ie']) && count($sintegraData['lista_ie']) > 0) {
                foreach ($sintegraData['lista_ie'] as $ie) {
                    if (isset($ie['regime_icms']) && !empty($ie['regime_icms'])) {
                        return normalizarRegimeIcms(trim($ie['regime_icms']));
                    }
                }
            }
            // Procura no nível principal
            foreach (['Taxregime', 'tax_regime', 'regime_tributario', 'regime_icms', 'regime'] as $chave) {
                if (isset($sintegraData[$chave]) && !empty($sintegraData[$chave])) {
                    return normalizarRegimeIcms(trim($sintegraData[$chave]));
                }
            }
        }
    }

    // Fallback: tenta encontrar qualquer campo que contenha sintegra
    $campoSintegraGenerico = null;
    foreach ($campos as $key => $value) {
        if (stripos($key, 'sintegra') !== false && !empty($value)) {
            $campoSintegraGenerico = $value;
            break;
        }
    }

    if (empty($campoSintegraGenerico)) return 'Não informado';

    $sintegraData = null;
    if (is_string($campoSintegraGenerico)) {
        $campoLimpo = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $campoSintegraGenerico);
        $campoLimpo = trim($campoLimpo);
        $campoLimpo = preg_replace('/^\xEF\xBB\xBF/', '', $campoLimpo);
        $sintegraData = json_decode($campoLimpo, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $campoLimpo, $matches)) {
                $sintegraData = json_decode($matches[0], true);
            }
        }
    } elseif (is_array($campoSintegraGenerico)) {
        $sintegraData = $campoSintegraGenerico;
    }

    if (!is_array($sintegraData) || empty($sintegraData)) return 'Não informado';

    if (isset($sintegraData['lista_ie']) && is_array($sintegraData['lista_ie']) && count($sintegraData['lista_ie']) > 0) {
        foreach ($sintegraData['lista_ie'] as $ie) {
            if (isset($ie['regime_icms']) && !empty($ie['regime_icms'])) {
                return normalizarRegimeIcms(trim($ie['regime_icms']));
            }
        }
    }

    foreach (['Taxregime', 'tax_regime', 'regime_tributario', 'regime_icms', 'regime'] as $chave) {
        if (isset($sintegraData[$chave]) && !empty($sintegraData[$chave])) {
            return normalizarRegimeIcms(trim($sintegraData[$chave]));
        }
    }

    return 'Não informado';
}

function normalizarRegimeIcms($regime)
{
    if (empty($regime) || $regime === 'Não informado') return '';

    // Mapeamento de valores possíveis
    $mapeamento = [
        '0' => 'NENHUM',
        '1' => 'LUCRO REAL',
        '2' => 'LUCRO PRESUMIDO',
        '3' => 'SIMPLES NACIONAL',
        '4' => 'REIDI',
        '5' => 'EIRELLI',
        'SIMPLES NACIONAL' => 'SIMPLES NACIONAL',
        'SIMPLES' => 'SIMPLES NACIONAL',
        'SN' => 'SIMPLES NACIONAL',
        'MEI' => 'MEI',
        'LUCRO REAL' => 'LUCRO REAL',
        'LUCRO PRESUMIDO' => 'LUCRO PRESUMIDO',
        'NENHUM' => 'NENHUM'
    ];

    $regimeUpper = strtoupper(trim($regime));
    if (isset($mapeamento[$regimeUpper])) return $mapeamento[$regimeUpper];
    if (strpos($regimeUpper, 'SIMPLES') !== false) return 'SIMPLES NACIONAL';
    if (strpos($regimeUpper, 'MEI') !== false) return 'MEI';
    if (strpos($regimeUpper, 'LUCRO REAL') !== false) return 'LUCRO REAL';
    if (strpos($regimeUpper, 'LUCRO PRESUMIDO') !== false) return 'LUCRO PRESUMIDO';
    return $regimeUpper;
}

function limparDocumento($documento)
{
    return preg_replace('/[^0-9]/', '', $documento);
}

function normalizarString($str)
{
    if (empty($str) || $str === 'Não informado') return '';
    $str = preg_replace('/[áàâãä]/u', 'a', $str);
    $str = preg_replace('/[éèêë]/u', 'e', $str);
    $str = preg_replace('/[íìîï]/u', 'i', $str);
    $str = preg_replace('/[óòôõö]/u', 'o', $str);
    $str = preg_replace('/[úùûü]/u', 'u', $str);
    $str = preg_replace('/[ç]/u', 'c', $str);
    $str = strtoupper(trim($str));
    $str = preg_replace('/\s+/', ' ', $str);
    $str = preg_replace('/[^\w\s]/u', '', $str);
    return $str;
}

function normalizarTelefone($telefone)
{
    if (empty($telefone) || $telefone === 'Não informado') return '';
    $numeros = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($numeros) >= 11 && substr($numeros, 0, 1) === '0') {
        $numeros = substr($numeros, 1);
    }
    return $numeros;
}

function formatarCNPJ($cnpj)
{
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    if (strlen($cnpj) == 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
    }
    return $cnpj;
}

function formatarCPF($cpf)
{
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) == 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }
    return $cpf;
}

function formatarCEP($cep)
{
    $cep = preg_replace('/[^0-9]/', '', $cep);
    if (strlen($cep) == 8) {
        return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $cep);
    }
    return $cep;
}

function formatarTelefone($telefone)
{
    if (empty($telefone) || $telefone === 'Não informado') return 'Não informado';
    $numeros = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($numeros) == 10) {
        return '(' . substr($numeros, 0, 2) . ') ' . substr($numeros, 2, 4) . '-' . substr($numeros, 6, 4);
    } elseif (strlen($numeros) == 11) {
        return '(' . substr($numeros, 0, 2) . ') ' . substr($numeros, 2, 5) . '-' . substr($numeros, 7, 4);
    }
    return $telefone;
}

function formatarRegime($regime)
{
    if (empty($regime) || $regime === 'Não informado') return 'Não informado';
    $mapeamento = ['0' => 'NENHUM', '1' => 'LUCRO REAL', '2' => 'LUCRO PRESUMIDO', '3' => 'SIMPLES NACIONAL', '4' => 'REIDI', '5' => 'EIRELLI'];
    return $mapeamento[$regime] ?? $regime;
}

function compararValores($valor1, $valor2, $campo)
{
    if (empty($valor1) || $valor1 === 'Não informado') return true;
    if (empty($valor2) || $valor2 === 'Não informado') return false;

    switch ($campo) {
        case 'CODIC':
            return (int)$valor1 === (int)$valor2;
        case 'DOCUMENTO':
            $valor1 = preg_replace('/[^0-9]/', '', $valor1);
            $valor2 = preg_replace('/[^0-9]/', '', $valor2);
            break;
        case 'CEP':
            $valor1 = preg_replace('/[^0-9]/', '', $valor1);
            $valor2 = preg_replace('/[^0-9]/', '', $valor2);
            break;
        case 'TELEFONE':
            $valor1 = normalizarTelefone($valor1);
            $valor2 = normalizarTelefone($valor2);
            break;
        case 'TIPO DE REGIME':
            $valor1 = normalizarRegimeIcms($valor1);
            $valor2 = normalizarRegimeIcms($valor2);
            break;
        default:
            $valor1 = normalizarString($valor1);
            $valor2 = normalizarString($valor2);
    }
    return $valor1 === $valor2;
}

// ==================== CONSULTAS FIREBIRD ====================

function consultarFirebirdCNPJ($conexao, $documento)
{
    $documentoLimpo = limparDocumento($documento);
    $formatos = [
        'limpo' => $documentoLimpo,
        'original' => $documento,
        'com_pontos' => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $documentoLimpo)
    ];

    $sql = "SELECT 
                CAST(c.codic AS INTEGER) as codic,
                c.ncgc as documento,
                c.nome,
                c.nfantasia,
                c.dt_fundacao,
                c.ende,
                c.ende_nro,
                c.ende_complemento,
                c.bairro,
                c.ncep,
                cd.nome as cidade,
                cd.estado,
                c.email,
                c.fone1,
                c.tipo_regime
            FROM arqcad c
            INNER JOIN cidades cd ON cd.seqcidade = c.seqcidade
            WHERE c.tipoc = 'C'
                AND c.situ IN ('A', 'B')
                AND c.ncgc IS NOT NULL
                AND (c.ncgc = :doc_limpo 
                    OR c.ncgc = :doc_original 
                    OR c.ncgc = :doc_formatado)
            ORDER BY c.codic
            FETCH FIRST 1 ROW ONLY";

    try {
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':doc_limpo', $formatos['limpo']);
        $stmt->bindParam(':doc_original', $formatos['original']);
        $stmt->bindParam(':doc_formatado', $formatos['com_pontos']);
        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resultado) {
            if (isset($resultado['CODIC'])) $resultado['CODIC'] = (int)$resultado['CODIC'];
            if (isset($resultado['NCEP'])) $resultado['NCEP'] = formatarCEP($resultado['NCEP']);
            if (isset($resultado['DT_FUNDACAO']) && $resultado['DT_FUNDACAO'] !== '0000-00-00') {
                $resultado['DT_FUNDACAO'] = date('d/m/Y', strtotime($resultado['DT_FUNDACAO']));
            }
            if (isset($resultado['FONE1'])) $resultado['FONE1'] = formatarTelefone($resultado['FONE1']);
            if (isset($resultado['TIPO_REGIME'])) $resultado['TIPO_REGIME'] = formatarRegime($resultado['TIPO_REGIME']);
            return ['sucesso' => true, 'dados' => $resultado];
        }
        return ['sucesso' => false, 'mensagem' => 'CNPJ não encontrado'];
    } catch (PDOException $e) {
        return ['sucesso' => false, 'mensagem' => 'Erro: ' . $e->getMessage()];
    }
}

function consultarFirebirdCPF($conexao, $documento)
{
    $documentoLimpo = limparDocumento($documento);
    $formatos = [
        'limpo' => $documentoLimpo,
        'original' => $documento,
        'com_pontos' => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $documentoLimpo)
    ];

    $sql = "SELECT 
                CAST(c.codic AS INTEGER) as codic,
                c.ncpf as documento,
                c.nome,
                c.dt_fundacao,
                c.email,
                c.fone1
            FROM arqcad c
            WHERE c.tipoc = 'C'
                AND c.situ in ('A', 'B')
                AND c.ncpf IS NOT NULL
                AND (c.ncpf = :doc_limpo 
                    OR c.ncpf = :doc_original 
                    OR c.ncpf = :doc_formatado)
            ORDER BY c.codic
            FETCH FIRST 1 ROW ONLY";

    try {
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':doc_limpo', $formatos['limpo']);
        $stmt->bindParam(':doc_original', $formatos['original']);
        $stmt->bindParam(':doc_formatado', $formatos['com_pontos']);
        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resultado) {
            if (isset($resultado['CODIC'])) $resultado['CODIC'] = (int)$resultado['CODIC'];
            if (isset($resultado['DT_FUNDACAO']) && $resultado['DT_FUNDACAO'] !== '0000-00-00') {
                $resultado['DT_FUNDACAO'] = date('d/m/Y', strtotime($resultado['DT_FUNDACAO']));
            }
            if (isset($resultado['FONE1'])) $resultado['FONE1'] = formatarTelefone($resultado['FONE1']);
            return ['sucesso' => true, 'dados' => $resultado];
        }
        return ['sucesso' => false, 'mensagem' => 'CPF não encontrado'];
    } catch (PDOException $e) {
        return ['sucesso' => false, 'mensagem' => 'Erro: ' . $e->getMessage()];
    }
}

// ==================== CONSULTA NEOCREDIT ====================

function consultarNeoCredit($documento, $token)
{
    try {
        $documentoLimpo = limparDocumento($documento);
        $postUrl = 'https://app-api.neocredit.com.br/empresa-esteira-solicitacao/2833/integracao';
        $postData = json_encode(['documento' => $documentoLimpo]);

        $ch = curl_init($postUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Length: ' . strlen($postData)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $postResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) throw new Exception('Erro na primeira chamada: ' . curl_error($ch));
        curl_close($ch);

        if ($httpCode !== 200) throw new Exception('Erro na API: Código ' . $httpCode);

        $postResult = json_decode($postResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Erro ao decodificar JSON');
        if (!isset($postResult['id'])) throw new Exception('ID não encontrado na resposta da API');

        $id = $postResult['id'];
        $maxAttempts = 30;
        $attempt = 0;
        $status = 'PROCESSANDO';
        $result = null;

        while ($status === 'PROCESSANDO' && $attempt < $maxAttempts) {
            $attempt++;
            if ($attempt > 1) sleep(10);

            $getUrl = "https://app-api.neocredit.com.br/empresa-esteira-solicitacao/{$id}/simplificada";
            $ch = curl_init($getUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $getResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($getResponse, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $status = $result['status'] ?? 'ERRO';
                    if ($status !== 'PROCESSANDO') break;
                }
            }
        }

        if ($attempt >= $maxAttempts) throw new Exception('Tempo limite excedido');
        if (!$result) throw new Exception('Não foi possível obter os dados');

        $tipo = (strlen($documentoLimpo) === 11) ? 'CPF' : 'CNPJ';
        $processedData = processarDadosNeoCredit($result, $documentoLimpo, $tipo);
        return ['sucesso' => true, 'dados' => $processedData];
    } catch (Exception $e) {
        return ['sucesso' => false, 'mensagem' => $e->getMessage()];
    }
}

function processarDadosNeoCredit($data, $documento, $tipo)
{
    $result = [];
    if ($tipo === 'CPF') {
        if (!isset($data['campos']['receita'])) throw new Exception('Campo receita não encontrado');
        $receita = decodificarJSONSeguro($data['campos']['receita']);
        if (!$receita) throw new Exception('Erro ao decodificar dados');

        $email = 'Não informado';
        if (isset($data['campos']['email']) && !empty($data['campos']['email'])) $email = $data['campos']['email'];
        elseif (isset($receita['email']) && !empty($receita['email'])) $email = $receita['email'];

        $telefone = 'Não informado';
        if (isset($data['campos']['telefone']) && !empty($data['campos']['telefone'])) $telefone = formatarTelefone($data['campos']['telefone']);

        $result = [
            'cpf' => formatarCPF($documento),
            'nome' => $receita['Name'] ?? 'Não informado',
            'dt_nascimento' => isset($receita['BirthDate']) ? date('d/m/Y', strtotime($receita['BirthDate'])) : 'Não informado',
            'email' => $email,
            'fone_completo' => $telefone,
            'status' => $receita['TaxIdStatus'] ?? 'Não informado',
            // Campos extras para CPF
            'mother_name' => $receita['MotherName'] ?? 'Não informado',
            'age' => isset($receita['Age']) ? $receita['Age'] . ' anos' : 'Não informado'
        ];
    } else {
        // --- LÓGICA PARA CNPJ (CORRIGIDA) ---
        if (!isset($data['campos']['receita'])) throw new Exception('Campo receita não encontrado');
        $campos = $data['campos'];
        $receita = decodificarJSONSeguro($campos['receita']);

        // Se falhar ao decodificar o JSON, tenta extrair via regex como fallback
        if (!$receita) {
            $receitaString = $campos['receita'];
            $email = 'Não informado';
            if (preg_match('/"email"\s*:\s*"([^"]+@[^"]+)"/', $receitaString, $matches)) $email = $matches[1];

            $razao = 'Não informado';
            if (preg_match('/"razao"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $razao = $matches[1];

            $fantasia = 'Não informado';
            if (preg_match('/"fantasia"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $fantasia = $matches[1];

            $dt_abertura = 'Não informado';
            if (preg_match('/"dt_abertura"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $dt_abertura = $matches[1];

            $logradouro = 'Não informado';
            if (preg_match('/"logradouro"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $logradouro = $matches[1];

            $numero = 'Não informado';
            if (preg_match('/"numero"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $numero = $matches[1];

            $bairro = 'Não informado';
            if (preg_match('/"bairro"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $bairro = $matches[1];

            $cidade = 'Não informado';
            if (preg_match('/"cidade"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $cidade = $matches[1];

            $uf = 'Não informado';
            if (preg_match('/"uf"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $uf = $matches[1];

            $cep = 'Não informado';
            if (preg_match('/"cep"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $cep = formatarCEP($matches[1]);

            $telefone = 'Não informado';
            if (preg_match('/"fone_formatado"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $telefone = $matches[1];
            elseif (preg_match('/"fone"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $telefone = formatarTelefone($matches[1]);

            // Tenta extrair regime tributário do campo sintegra, mesmo no fallback
            $regime_icms = extrairRegimeIcmsDoSintegra($campos);

            // Campos extras do fallback
            $faixa_porte = 'Não informado';
            if (preg_match('/"faixa_porte"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $faixa_porte = $matches[1];

            $tempo_mercado = 'Não informado';
            if (preg_match('/"tempo_mercado"\s*:\s*"([^"]+)"/', $receitaString, $matches)) $tempo_mercado = $matches[1];

            $capital_social = 'Não informado';
            if (preg_match('/"capital_social"\s*:\s*"([^"]+)"/', $receitaString, $matches)) {
                $capital_social = 'R$ ' . number_format((float)$matches[1], 2, ',', '.');
            }

            $ie = 'Não informado';

            $result = [
                'cnpj' => formatarCNPJ($documento),
                'razao' => $razao,
                'fantasia' => $fantasia,
                'dt_abertura' => $dt_abertura,
                'logradouro' => $logradouro,
                'numero' => $numero,
                'complemento' => 'Não informado',
                'bairro' => $bairro,
                'cidade' => $cidade,
                'uf' => $uf,
                'cep' => $cep,
                'email' => $email,
                'fone_completo' => $telefone,
                'regime_icms' => $regime_icms,
                'status' => 'Não informado',
                // Campos extras
                'faixa_porte' => $faixa_porte,
                'tempo_mercado' => $tempo_mercado,
                'capital_social' => $capital_social,
                'ie' => $ie
            ];
            return $result;
        }

        // --- SE O JSON FOI DECODIFICADO COM SUCESSO ---
        $info = $receita['informacoes'] ?? [];
        $enderecos = $receita['enderecos'] ?? [];
        $primeiroEndereco = $enderecos[0] ?? [];
        $telefones = $receita['telefones'] ?? [];

        $telefone = 'Não informado';
        if (!empty($telefones)) {
            $telefoneRaw = $telefones[0]['fone_formatado'] ?? $telefones[0]['numero'] ?? 'Não informado';
            $telefone = formatarTelefone($telefoneRaw);
        }

        $email = extrairEmailDoReceita($receita, $campos);
        if ($email === 'Não informado' && isset($campos['receita'])) {
            if (preg_match('/"email"\s*:\s*"([^"]+@[^"]+)"/', $campos['receita'], $matches)) $email = $matches[1];
        }

        // --- LÓGICA DE EXTRAÇÃO DO REGIME TRIBUTÁRIO MELHORADA ---
        $regime_icms = 'Não informado';

        // 1. Tenta extrair do campo específico do Sintegra que está no JSON principal
        foreach ($campos as $key => $value) {
            if (strpos($key, 'resultado_sintegra_completo') !== false && !empty($value)) {
                $sintegraData = null;
                if (is_string($value)) {
                    $sintegraData = decodificarJSONSeguro($value);
                } elseif (is_array($value)) {
                    $sintegraData = $value;
                }

                if (is_array($sintegraData)) {
                    // Procura na lista_ie
                    if (isset($sintegraData['lista_ie']) && is_array($sintegraData['lista_ie'])) {
                        foreach ($sintegraData['lista_ie'] as $ie) {
                            if (isset($ie['regime_icms']) && !empty($ie['regime_icms'])) {
                                $regime_icms = normalizarRegimeIcms(trim($ie['regime_icms']));
                                break 2;
                            }
                        }
                    }
                    // Procura no nível principal
                    if ($regime_icms === 'Não informado' && isset($sintegraData['Taxregime']) && !empty($sintegraData['Taxregime'])) {
                        $regime_icms = normalizarRegimeIcms($sintegraData['Taxregime']);
                        break;
                    }
                }
            }
        }

        // 2. Se ainda não encontrou, tenta com a função antiga (extrairRegimeIcmsDoSintegra)
        if ($regime_icms === 'Não informado') {
            $regime_icms = extrairRegimeIcmsDoSintegra($campos);
        }

        // 3. Último recurso: tenta extrair do JSON do campo 'receita' se ele tiver um campo Taxregime
        if ($regime_icms === 'Não informado' && isset($receita['Taxregime'])) {
            $regime_icms = normalizarRegimeIcms($receita['Taxregime']);
        }

        // 4. Se ainda não encontrou, tenta extrair do campo 'simples' dentro do JSON da receita
        if ($regime_icms === 'Não informado' && isset($receita['simples'])) {
            if (isset($receita['simples']['optante']) && $receita['simples']['optante'] === true) {
                $regime_icms = 'SIMPLES NACIONAL';
            }
        }

        // Normaliza o resultado final
        if ($regime_icms === 'Não informado' || empty($regime_icms)) {
            $regime_icms = 'Não informado';
        }

        // --- EXTRAÇÃO DOS CAMPOS EXTRAS (PORTE, TEMPO EMPRESA, CAPITAL, IE) ---
        $faixa_porte = $info['faixa_porte'] ?? 'Não informado';
        $tempo_mercado = $info['tempo_mercado'] ?? 'Não informado';

        $capital_social = 'Não informado';
        if (isset($info['capital_social']) && !empty($info['capital_social']) && is_numeric($info['capital_social'])) {
            $capital_social = 'R$ ' . number_format((float)$info['capital_social'], 2, ',', '.');
        } elseif (isset($info['capital_social_ou_zero']) && !empty($info['capital_social_ou_zero']) && is_numeric($info['capital_social_ou_zero'])) {
            $capital_social = 'R$ ' . number_format((float)$info['capital_social_ou_zero'], 2, ',', '.');
        }

        $ie = 'Não informado';
        // Tenta extrair IE do campo sintegra
        foreach ($campos as $key => $value) {
            if (strpos($key, 'resultado_sintegra_completo') !== false && !empty($value)) {
                $sintegraData = null;
                if (is_string($value)) {
                    $sintegraData = decodificarJSONSeguro($value);
                } elseif (is_array($value)) {
                    $sintegraData = $value;
                }

                if (is_array($sintegraData)) {
                    // Procura na lista_ie
                    if (isset($sintegraData['lista_ie']) && is_array($sintegraData['lista_ie'])) {
                        foreach ($sintegraData['lista_ie'] as $ieItem) {
                            if (isset($ieItem['ie']) && !empty($ieItem['ie'])) {
                                $ie = $ieItem['ie'];
                                break 2;
                            }
                        }
                    }
                    // Procura no nível principal
                    if ($ie === 'Não informado' && isset($sintegraData['StateRegistration']) && !empty($sintegraData['StateRegistration'])) {
                        $ie = $sintegraData['StateRegistration'];
                        break;
                    }
                }
            }
        }

        $result = [
            'cnpj' => formatarCNPJ($documento),
            'razao' => $info['razao'] ?? 'Não informado',
            'fantasia' => $info['fantasia'] ?? 'Não informado',
            'dt_abertura' => isset($info['dt_abertura']) ? date('d/m/Y', strtotime(str_replace('/', '-', $info['dt_abertura']))) : 'Não informado',
            'logradouro' => $primeiroEndereco['logradouro'] ?? 'Não informado',
            'numero' => $primeiroEndereco['numero'] ?? 'Não informado',
            'complemento' => $primeiroEndereco['complemento'] ?? 'Não informado',
            'bairro' => $primeiroEndereco['bairro'] ?? 'Não informado',
            'cidade' => $primeiroEndereco['cidade'] ?? 'Não informado',
            'uf' => $primeiroEndereco['uf'] ?? 'Não informado',
            'cep' => isset($primeiroEndereco['cep']) ? formatarCEP($primeiroEndereco['cep']) : 'Não informado',
            'email' => $email,
            'fone_completo' => $telefone,
            'regime_icms' => $regime_icms,
            'status' => $info['situacao'] ?? 'Não informado',
            // Campos extras
            'faixa_porte' => $faixa_porte,
            'tempo_mercado' => $tempo_mercado,
            'capital_social' => $capital_social,
            'ie' => $ie
        ];
    }
    return $result;
}

// ==================== FUNÇÕES DE INSERÇÃO NO BANCO ====================

function inserirDadosCPF($pdo, $dadosNeo, $dadosSIC, $usuario)
{
    if (!$pdo) return false;
    try {
        $sic_bm = $sic_bot = $sic_ls = $sic_rp = $sic_vot_rnp = 0;
        if (is_array($dadosSIC)) {
            foreach ($dadosSIC as $unidade => $resultado) {
                if ($resultado['dados']['sucesso'] && isset($resultado['dados']['dados']['CODIC'])) {
                    $codic = (int)$resultado['dados']['dados']['CODIC'];
                    switch ($unidade) {
                        case 'barra_mansa':
                            $sic_bm = $codic;
                            break;
                        case 'botucatu':
                            $sic_bot = $codic;
                            break;
                        case 'lins':
                            $sic_ls = $codic;
                            break;
                        case 'rio_preto':
                            $sic_rp = $codic;
                            break;
                        case 'votuporanga':
                            $sic_vot_rnp = $codic;
                            break;
                    }
                }
            }
        }

        $sql = "INSERT INTO neocredit.dados_cadastrais_cpf 
                (nome, documento, dt_nascimento, email, tel, sic_bm, sic_bot, sic_ls, sic_rp, sic_vot_rnp, dt_consulta, usu_consulta)
                VALUES (:nome, :documento, :dt_nascimento, :email, :tel, :sic_bm, :sic_bot, :sic_ls, :sic_rp, :sic_vot_rnp, CURRENT_TIMESTAMP, :usu_consulta)";

        $stmt = $pdo->prepare($sql);
        $dt_nascimento = null;
        if (isset($dadosNeo['dt_nascimento']) && $dadosNeo['dt_nascimento'] !== 'Não informado') {
            $dt = DateTime::createFromFormat('d/m/Y', $dadosNeo['dt_nascimento']);
            if ($dt) $dt_nascimento = $dt->format('Y-m-d');
        }
        $documento = preg_replace('/[^0-9]/', '', $dadosNeo['cpf'] ?? '');
        $telefone = preg_replace('/[^0-9]/', '', $dadosNeo['fone_completo'] ?? '');

        $stmt->bindValue(':nome', $dadosNeo['nome'] ?? 'Não informado');
        $stmt->bindValue(':documento', $documento);
        $stmt->bindValue(':dt_nascimento', $dt_nascimento);
        $stmt->bindValue(':email', $dadosNeo['email'] ?? 'Não informado');
        $stmt->bindValue(':tel', $telefone);
        $stmt->bindValue(':sic_bm', $sic_bm, PDO::PARAM_INT);
        $stmt->bindValue(':sic_bot', $sic_bot, PDO::PARAM_INT);
        $stmt->bindValue(':sic_ls', $sic_ls, PDO::PARAM_INT);
        $stmt->bindValue(':sic_rp', $sic_rp, PDO::PARAM_INT);
        $stmt->bindValue(':sic_vot_rnp', $sic_vot_rnp, PDO::PARAM_INT);
        $stmt->bindValue(':usu_consulta', $usuario);
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao inserir dados de CPF: " . $e->getMessage());
        return false;
    }
}

function inserirDadosCNPJ($pdo, $dadosNeo, $dadosSIC, $usuario)
{
    if (!$pdo) return false;
    try {
        $sic_bm = $sic_bot = $sic_ls = $sic_rp = $sic_vot_rnp = 0;
        if (is_array($dadosSIC)) {
            foreach ($dadosSIC as $unidade => $resultado) {
                if ($resultado['dados']['sucesso'] && isset($resultado['dados']['dados']['CODIC'])) {
                    $codic = (int)$resultado['dados']['dados']['CODIC'];
                    switch ($unidade) {
                        case 'barra_mansa':
                            $sic_bm = $codic;
                            break;
                        case 'botucatu':
                            $sic_bot = $codic;
                            break;
                        case 'lins':
                            $sic_ls = $codic;
                            break;
                        case 'rio_preto':
                            $sic_rp = $codic;
                            break;
                        case 'votuporanga':
                            $sic_vot_rnp = $codic;
                            break;
                    }
                }
            }
        }

        $sql = "INSERT INTO neocredit.dados_cadastrais_cnpj 
                (documento, nome, nome_fantasia, dt_abertura, logradouro, numero, complemento, bairro, cidade, estado, cep, email, tel, tp_regime, sic_bm, sic_bot, sic_ls, sic_rp, sic_vot_rnp, dt_consulta, usu_consulta)
                VALUES (:documento, :nome, :nome_fantasia, :dt_abertura, :logradouro, :numero, :complemento, :bairro, :cidade, :estado, :cep, :email, :tel, :tp_regime, :sic_bm, :sic_bot, :sic_ls, :sic_rp, :sic_vot_rnp, CURRENT_TIMESTAMP, :usu_consulta)";

        $stmt = $pdo->prepare($sql);
        $dt_abertura = null;
        if (isset($dadosNeo['dt_abertura']) && $dadosNeo['dt_abertura'] !== 'Não informado') {
            $dt = DateTime::createFromFormat('d/m/Y', $dadosNeo['dt_abertura']);
            if ($dt) $dt_abertura = $dt->format('Y-m-d');
        }
        $documento = preg_replace('/[^0-9]/', '', $dadosNeo['cnpj'] ?? '');
        $telefone = preg_replace('/[^0-9]/', '', $dadosNeo['fone_completo'] ?? '');
        $cep = preg_replace('/[^0-9]/', '', $dadosNeo['cep'] ?? '');
        $numero = preg_replace('/[^0-9]/', '', $dadosNeo['numero'] ?? '');
        if (empty($numero)) $numero = null;

        $stmt->bindValue(':documento', $documento);
        $stmt->bindValue(':nome', $dadosNeo['razao'] ?? 'Não informado');
        $stmt->bindValue(':nome_fantasia', $dadosNeo['fantasia'] ?? 'Não informado');
        $stmt->bindValue(':dt_abertura', $dt_abertura);
        $stmt->bindValue(':logradouro', $dadosNeo['logradouro'] ?? 'Não informado');
        $stmt->bindValue(':numero', $numero, PDO::PARAM_INT);
        $stmt->bindValue(':complemento', $dadosNeo['complemento'] ?? 'Não informado');
        $stmt->bindValue(':bairro', $dadosNeo['bairro'] ?? 'Não informado');
        $stmt->bindValue(':cidade', $dadosNeo['cidade'] ?? 'Não informado');
        $stmt->bindValue(':estado', $dadosNeo['uf'] ?? 'Não informado');
        $stmt->bindValue(':cep', $cep);
        $stmt->bindValue(':email', $dadosNeo['email'] ?? 'Não informado');
        $stmt->bindValue(':tel', $telefone);
        $stmt->bindValue(':tp_regime', $dadosNeo['regime_icms'] ?? 'Não informado');
        $stmt->bindValue(':sic_bm', $sic_bm, PDO::PARAM_INT);
        $stmt->bindValue(':sic_bot', $sic_bot, PDO::PARAM_INT);
        $stmt->bindValue(':sic_ls', $sic_ls, PDO::PARAM_INT);
        $stmt->bindValue(':sic_rp', $sic_rp, PDO::PARAM_INT);
        $stmt->bindValue(':sic_vot_rnp', $sic_vot_rnp, PDO::PARAM_INT);
        $stmt->bindValue(':usu_consulta', $usuario);
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao inserir dados de CNPJ: " . $e->getMessage());
        return false;
    }
}

function salvarApenasSICCPF($pdo, $dadosSIC, $documento, $usuario)
{
    if (!$pdo) return false;
    try {
        $sic_bm = $sic_bot = $sic_ls = $sic_rp = $sic_vot_rnp = 0;
        if (is_array($dadosSIC)) {
            foreach ($dadosSIC as $unidade => $resultado) {
                if ($resultado['dados']['sucesso'] && isset($resultado['dados']['dados']['CODIC'])) {
                    $codic = (int)$resultado['dados']['dados']['CODIC'];
                    switch ($unidade) {
                        case 'barra_mansa':
                            $sic_bm = $codic;
                            break;
                        case 'botucatu':
                            $sic_bot = $codic;
                            break;
                        case 'lins':
                            $sic_ls = $codic;
                            break;
                        case 'rio_preto':
                            $sic_rp = $codic;
                            break;
                        case 'votuporanga':
                            $sic_vot_rnp = $codic;
                            break;
                    }
                }
            }
        }

        $documentoLimpo = preg_replace('/[^0-9]/', '', $documento);
        $checkSql = "SELECT id FROM neocredit.dados_cadastrais_cpf WHERE documento = :documento";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindValue(':documento', $documentoLimpo);
        $checkStmt->execute();
        $existe = $checkStmt->fetch();

        if ($existe) {
            $sql = "UPDATE neocredit.dados_cadastrais_cpf 
                    SET sic_bm = :sic_bm, sic_bot = :sic_bot, sic_ls = :sic_ls, sic_rp = :sic_rp, sic_vot_rnp = :sic_vot_rnp,
                        dt_consulta = CURRENT_TIMESTAMP, usu_consulta = :usu_consulta
                    WHERE documento = :documento";
        } else {
            $sql = "INSERT INTO neocredit.dados_cadastrais_cpf 
                    (documento, sic_bm, sic_bot, sic_ls, sic_rp, sic_vot_rnp, dt_consulta, usu_consulta, nome, dt_nascimento, email, tel)
                    VALUES (:documento, :sic_bm, :sic_bot, :sic_ls, :sic_rp, :sic_vot_rnp, CURRENT_TIMESTAMP, :usu_consulta, 'Aguardando consulta', NULL, 'Aguardando consulta', 'Aguardando consulta')";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':documento', $documentoLimpo);
        $stmt->bindValue(':sic_bm', $sic_bm, PDO::PARAM_INT);
        $stmt->bindValue(':sic_bot', $sic_bot, PDO::PARAM_INT);
        $stmt->bindValue(':sic_ls', $sic_ls, PDO::PARAM_INT);
        $stmt->bindValue(':sic_rp', $sic_rp, PDO::PARAM_INT);
        $stmt->bindValue(':sic_vot_rnp', $sic_vot_rnp, PDO::PARAM_INT);
        $stmt->bindValue(':usu_consulta', $usuario);
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao salvar dados SIC de CPF: " . $e->getMessage());
        return false;
    }
}

function salvarApenasSICCNPJ($pdo, $dadosSIC, $documento, $usuario)
{
    if (!$pdo) return false;
    try {
        $sic_bm = $sic_bot = $sic_ls = $sic_rp = $sic_vot_rnp = 0;
        if (is_array($dadosSIC)) {
            foreach ($dadosSIC as $unidade => $resultado) {
                if ($resultado['dados']['sucesso'] && isset($resultado['dados']['dados']['CODIC'])) {
                    $codic = (int)$resultado['dados']['dados']['CODIC'];
                    switch ($unidade) {
                        case 'barra_mansa':
                            $sic_bm = $codic;
                            break;
                        case 'botucatu':
                            $sic_bot = $codic;
                            break;
                        case 'lins':
                            $sic_ls = $codic;
                            break;
                        case 'rio_preto':
                            $sic_rp = $codic;
                            break;
                        case 'votuporanga':
                            $sic_vot_rnp = $codic;
                            break;
                    }
                }
            }
        }

        $documentoLimpo = preg_replace('/[^0-9]/', '', $documento);
        $checkSql = "SELECT id FROM neocredit.dados_cadastrais_cnpj WHERE documento = :documento";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindValue(':documento', $documentoLimpo);
        $checkStmt->execute();
        $existe = $checkStmt->fetch();

        if ($existe) {
            $sql = "UPDATE neocredit.dados_cadastrais_cnpj 
                    SET sic_bm = :sic_bm, sic_bot = :sic_bot, sic_ls = :sic_ls, sic_rp = :sic_rp, sic_vot_rnp = :sic_vot_rnp,
                        dt_consulta = CURRENT_TIMESTAMP, usu_consulta = :usu_consulta
                    WHERE documento = :documento";
        } else {
            $sql = "INSERT INTO neocredit.dados_cadastrais_cnpj 
                    (documento, sic_bm, sic_bot, sic_ls, sic_rp, sic_vot_rnp, dt_consulta, usu_consulta, nome, nome_fantasia, logradouro, email, tel, tp_regime)
                    VALUES (:documento, :sic_bm, :sic_bot, :sic_ls, :sic_rp, :sic_vot_rnp, CURRENT_TIMESTAMP, :usu_consulta, 'Aguardando consulta', 'Aguardando consulta', 'Aguardando consulta', 'Aguardando consulta', 'Aguardando consulta', 'Aguardando consulta')";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':documento', $documentoLimpo);
        $stmt->bindValue(':sic_bm', $sic_bm, PDO::PARAM_INT);
        $stmt->bindValue(':sic_bot', $sic_bot, PDO::PARAM_INT);
        $stmt->bindValue(':sic_ls', $sic_ls, PDO::PARAM_INT);
        $stmt->bindValue(':sic_rp', $sic_rp, PDO::PARAM_INT);
        $stmt->bindValue(':sic_vot_rnp', $sic_vot_rnp, PDO::PARAM_INT);
        $stmt->bindValue(':usu_consulta', $usuario);
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao salvar dados SIC de CNPJ: " . $e->getMessage());
        return false;
    }
}

// ==================== PROCESSAMENTO AJAX ====================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'true') {
    ob_clean();
    header('Content-Type: application/json');

    try {
        $documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';
        $documentoLimpo = limparDocumento($documento);

        if (empty($documentoLimpo) || (strlen($documentoLimpo) !== 11 && strlen($documentoLimpo) !== 14)) {
            throw new Exception('Documento inválido. Digite 11 dígitos para CPF ou 14 para CNPJ');
        }

        $tipoDocumento = (strlen($documentoLimpo) === 11) ? 'CPF' : 'CNPJ';
        $resultado_neo = consultarNeoCredit($documento, $neo_token);

        $resultados_firebird = [];
        $unidades_com_dados = []; // Array para armazenar apenas unidades que encontraram dados
        
        foreach ($conexoes_firebird as $chave => $config) {
            try {
                $conexao = new PDO($config['dsn'], $config['user'], $config['pass']);
                $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if ($tipoDocumento === 'CPF') {
                    $consulta = consultarFirebirdCPF($conexao, $documento);
                } else {
                    $consulta = consultarFirebirdCNPJ($conexao, $documento);
                }
                // Só adiciona a unidade se a consulta for bem sucedida
                if ($consulta['sucesso']) {
                    $unidades_com_dados[$chave] = ['nome_unidade' => $config['nome'], 'dados' => $consulta];
                }
                $conexao = null;
            } catch (PDOException $e) {
                // Não adiciona unidades com erro de conexão
                error_log("Erro de conexão com {$config['nome']}: " . $e->getMessage());
            }
        }

        // Usar apenas unidades com dados encontrados
        $resultados_firebird = $unidades_com_dados;

        $usuario_consulta = 'SISTEMA'; // Valor fixo, sem hostname
        global $pdo;
        if (isset($pdo) && $resultado_neo['sucesso']) {
            if ($tipoDocumento === 'CPF') {
                inserirDadosCPF($pdo, $resultado_neo['dados'], $resultados_firebird, $usuario_consulta);
            } else {
                inserirDadosCNPJ($pdo, $resultado_neo['dados'], $resultados_firebird, $usuario_consulta);
            }
        }

        echo json_encode([
            'success' => true,
            'tipo_documento' => $tipoDocumento,
            'campos' => $tipoDocumento === 'CPF' ? $campos_cpf : $campos_cnpj,
            'firebird' => $resultados_firebird,
            'neocredit' => $resultado_neo
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_sic']) && $_POST['salvar_sic'] === 'true') {
    ob_clean();
    header('Content-Type: application/json');

    try {
        $documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';
        $documentoLimpo = limparDocumento($documento);

        if (empty($documentoLimpo) || (strlen($documentoLimpo) !== 11 && strlen($documentoLimpo) !== 14)) {
            throw new Exception('Documento inválido');
        }

        $tipoDocumento = (strlen($documentoLimpo) === 11) ? 'CPF' : 'CNPJ';
        $resultados_firebird = [];

        foreach ($conexoes_firebird as $chave => $config) {
            try {
                $conexao = new PDO($config['dsn'], $config['user'], $config['pass']);
                $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if ($tipoDocumento === 'CPF') {
                    $consulta = consultarFirebirdCPF($conexao, $documento);
                } else {
                    $consulta = consultarFirebirdCNPJ($conexao, $documento);
                }
                $resultados_firebird[$chave] = ['nome_unidade' => $config['nome'], 'dados' => $consulta];
                $conexao = null;
            } catch (PDOException $e) {
                $resultados_firebird[$chave] = ['nome_unidade' => $config['nome'], 'dados' => ['sucesso' => false, 'mensagem' => "Erro de conexão: " . $e->getMessage()]];
            }
        }

        $usuario_consulta = 'SISTEMA'; // Valor fixo, sem hostname
        global $pdo;
        if (isset($pdo)) {
            if ($tipoDocumento === 'CPF') {
                $salvo = salvarApenasSICCPF($pdo, $resultados_firebird, $documento, $usuario_consulta);
            } else {
                $salvo = salvarApenasSICCNPJ($pdo, $resultados_firebird, $documento, $usuario_consulta);
            }
            if ($salvo) {
                echo json_encode(['success' => true, 'message' => 'Dados do SIC salvos com sucesso!', 'tipo_documento' => $tipoDocumento]);
            } else {
                throw new Exception('Erro ao salvar os dados do SIC');
            }
        } else {
            throw new Exception('Erro de conexão com o banco de dados');
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dados Cadastrais</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 10px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-size: 14px;
        }

        .container-principal {
            max-width: 1600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header-principal {
            background: #333;
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 25px;
            border-bottom: 3px solid #fdb525;
            flex-wrap: wrap;
            gap: 10px;
        }

        .logo-noroaco {
            height: 50px;
        }

        .botoes-direita {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-pdf,
        .btn-pdf-detalhado,
        .btn-nova-consulta,
        .btn-salvar-sic {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-pdf-detalhado {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .btn-salvar-sic {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .btn-pdf:hover,
        .btn-pdf-detalhado:hover,
        .btn-nova-consulta:hover,
        .btn-salvar-sic:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Título da tabela com botão fixo à direita */
        .titulo-tabela {
            color: #2c3e50;
            padding: 12px 25px;
            background: white;
            font-size: 16px;
            font-weight: 600;
            border-left: 4px solid #fdb525;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .titulo-tabela-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .titulo-tabela-left i {
            margin-right: 8px;
            color: #fdb525;
        }

        .document-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-cpf {
            background: #d4edda;
            color: #155724;
        }

        .badge-cnpj {
            background: #fff3cd;
            color: #856404;
        }

        .btn-salvar-sic-titulo {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .btn-salvar-sic-titulo:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .form-section {
            background: #fff;
            padding: 25px;
            border-bottom: 1px solid #e0e0e0;
        }

        .form-section.hidden {
            display: none;
        }

        .form-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .input-with-icon {
            flex: 1;
            position: relative;
            min-width: 300px;
            max-width: 400px;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #fdb525;
            font-size: 18px;
        }

        #documento-input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }

        #documento-input:focus {
            border-color: #fdb525;
            outline: none;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .button-group {
            display: flex;
            gap: 12px;
        }

        #consultar-btn,
        #limpar-btn {
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
        }

        #consultar-btn {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
        }

        #limpar-btn {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }

        #consultar-btn:hover,
        #limpar-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .loading {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid #fdb525;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .progress-container {
            width: 100%;
            max-width: 400px;
            margin: 20px auto;
            background: #f0f0f0;
            border-radius: 10px;
            height: 12px;
            overflow: hidden;
        }

        .progress-bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #fdb525, #ffc64d);
            transition: width 0.3s ease;
        }

        .result-section {
            display: none;
            padding: 20px;
            overflow-x: auto;
        }

        /* Estilos para campos extras */
        .extra-fields-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            margin-bottom: 20px;
            padding: 15px 20px;
            border-left: 4px solid #fdb525;
        }

        .extra-fields-header {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .extra-fields-header i {
            color: #fdb525;
            font-size: 18px;
        }

        .extra-fields-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
        }

        .extra-field-item {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
        }

        .extra-field-item:hover {
            border-color: #fdb525;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .extra-field-label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .extra-field-label i {
            color: #fdb525;
            font-size: 14px;
            width: 18px;
        }

        .extra-field-value {
            color: #495057;
            font-size: 14px;
            font-weight: 500;
            word-break: break-word;
        }

        .tabela-comparativa {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 600px;
        }

        .tabela-comparativa th,
        .tabela-comparativa td {
            border: 1px solid #ddd;
            padding: 12px 10px;
            text-align: left;
            vertical-align: top;
        }

        .tabela-comparativa th {
            background: linear-gradient(135deg, #2c3e50 0%, #1a2632 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .tabela-comparativa tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .tabela-comparativa tr:hover {
            background-color: #f5f5f5;
        }

        .tabela-comparativa td:first-child {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            left: 0;
            z-index: 5;
        }

        .valor-encontrado {
            background-color: #e8f5e9;
        }

        .valor-diferente {
            background-color: #ffebee;
            color: #c62828;
            font-weight: 500;
            border-left: 3px solid #c62828;
        }

        .valor-nao-encontrado {
            color: #999;
            font-style: italic;
            background-color: #f5f5f5;
        }

        .divergencia-icon {
            display: inline-block;
            margin-left: 5px;
            font-size: 12px;
            cursor: help;
        }

        .error-message,
        .success-message {
            position: fixed;
            top: 90px;
            right: 20px;
            z-index: 1000;
            padding: 15px 20px;
            border-radius: 8px;
            font-weight: 500;
            max-width: 400px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .error-message.show,
        .success-message.show {
            opacity: 1;
            transform: translateX(0);
        }

        .error-message {
            background: linear-gradient(135deg, #ff4444 0%, #cc0000 100%);
            color: white;
        }

        .success-message {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
            color: white;
        }

        /* Large Success Message */
        .success-message-large {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px 50px;
            border-radius: 12px;
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            z-index: 10000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .success-message-large i {
            font-size: 48px;
        }

        .close-btn {
            margin-left: auto;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
            opacity: 0.8;
        }

        .close-btn:hover {
            opacity: 1;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 550px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #2c3e50 0%, #1a2632 100%);
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: white;
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: #fdb525;
        }

        .modal-close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #fdb525;
            transform: scale(1.1);
        }

        .modal-body {
            padding: 25px;
            max-height: 60vh;
            overflow-y: auto;
        }

        .unidades-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .unidade-checkbox {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
            cursor: pointer;
        }

        .unidade-checkbox:hover {
            background: #e9ecef;
            border-color: #fdb525;
        }

        .unidade-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 15px;
            cursor: pointer;
            accent-color: #fdb525;
        }

        .unidade-checkbox label {
            flex: 1;
            cursor: pointer;
            font-weight: 500;
            color: #2c3e50;
        }

        .unidade-checkbox .codic-info {
            font-size: 12px;
            color: #6c757d;
            margin-left: 10px;
        }

        /* Estilos para o select de usuário */
        .usuario-select-container {
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .usuario-select-container label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .usuario-select-container select {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .usuario-select-container select:focus {
            outline: none;
            border-color: #fdb525;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-size: 14px;
        }

        .modal-btn-cancel {
            background: #6c757d;
            color: white;
        }

        .modal-btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .modal-btn-confirm {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .modal-btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .modal-btn-confirm:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .modal-footer .loading-small {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #fdb525;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }

        .save-results {
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
        }

        .save-result-item {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .save-result-item:last-child {
            border-bottom: none;
        }

        .save-result-success {
            color: #28a745;
        }

        .save-result-warning {
            color: #ffc107;
        }

        .save-result-error {
            color: #dc3545;
        }

        .save-result-icon {
            font-size: 14px;
            margin-top: 2px;
        }

        @media (max-width: 768px) {
            .header-principal {
                flex-direction: column;
                height: auto;
                padding: 15px;
                gap: 10px;
            }

            .form-input-group {
                flex-direction: column;
            }

            .input-with-icon {
                max-width: 100%;
            }

            .button-group {
                width: 100%;
                justify-content: center;
            }

            .tabela-comparativa {
                font-size: 11px;
            }

            .tabela-comparativa th,
            .tabela-comparativa td {
                padding: 8px 6px;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .titulo-tabela {
                flex-direction: column;
                align-items: flex-start;
            }

            .extra-fields-grid {
                grid-template-columns: 1fr;
            }

            .extra-field-item {
                flex-direction: column;
                gap: 4px;
            }

            .extra-field-label {
                font-size: 12px;
            }

            .extra-field-value {
                font-size: 13px;
                padding-left: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <header class="header-principal">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">
            </div>
            <div class="botoes-direita">
                <button type="button" class="btn-pdf" id="btn-pdf-simplificado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PF
                </button>
                <button type="button" class="btn-pdf-detalhado" id="btn-pdf-detalhado" style="display: none;">
                    <i class="fa-solid fa-file-pdf"></i> PDF - PJ
                </button>
                <button type="button" class="btn-nova-consulta" id="btn-nova-consulta" style="display: none;">
                    <i class="fas fa-search"></i> Nova Consulta
                </button>
            </div>
        </header>

        <!-- Título da tabela com botão fixo à direita -->
        <div class="titulo-tabela">
            <div class="titulo-tabela-left">
                <i class="fa-solid fa-coins"></i> Dados Cadastrais
                <span id="tipoDocumentoBadge" class="document-type-badge badge-cpf">CPF</span>
            </div>
            <button type="button" class="btn-salvar-sic-titulo" id="btn-salvar-sic-titulo" style="display: none;">
                <i class="fa-solid fa-save"></i> SALVAR SIC
            </button>
        </div>

        <div class="form-section" id="form-section">
            <div class="form-input-group">
                <div class="input-with-icon">
                    <i class="fas fa-search"></i>
                    <input type="text" id="documento-input" placeholder="Digite CPF ou CNPJ" maxlength="18" autocomplete="off">
                </div>
                <div class="button-group">
                    <button type="button" id="consultar-btn">
                        <i class="fas fa-play"></i> Consultar
                    </button>
                    <button type="button" id="limpar-btn">
                        <i class="fas fa-eraser"></i> Limpar
                    </button>
                </div>
            </div>
        </div>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <div id="loading-text">Processando consulta...</div>
            <div class="progress-container">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            <div id="loadingStatus">Iniciando consulta...</div>
        </div>

        <div class="result-section" id="resultSection">
            <table class="tabela-comparativa" id="tabelaResultados">
                <thead>
                    <tr id="tableHeader">
                        <th>DESCRIÇÃO</th>
                        <th>NEOCREDIT</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de Seleção de Unidades -->
    <div id="modalUnidades" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-building"></i>
                    Selecionar Unidades
                </h3>
                <span class="modal-close" onclick="fecharModalUnidades()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="unidadesList" class="unidades-list">
                    <!-- Unidades serão carregadas via JavaScript -->
                </div>
                <div id="saveResults" class="save-results" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="fecharModalUnidades()">
                    Cancelar
                </button>
                <button id="btnConfirmarSalvar" class="modal-btn modal-btn-confirm">
                    <i class="fas fa-check"></i> Confirmar Atualização
                </button>
            </div>
        </div>
    </div>

    <div id="errorMessage" class="error-message">
        <i class="fas fa-exclamation-circle"></i>
        <span id="errorText"></span>
        <button class="close-btn" onclick="hideMessage('errorMessage')">×</button>
    </div>

    <div id="successMessage" class="success-message">
        <i class="fas fa-check-circle"></i>
        <span id="successText"></span>
        <button class="close-btn" onclick="hideMessage('successMessage')">×</button>
    </div>

    <script>
        // Elementos DOM
        const documentoInput = document.getElementById('documento-input');
        const formSection = document.getElementById('form-section');
        const loading = document.getElementById('loading');
        const progressBar = document.getElementById('progressBar');
        const loadingStatus = document.getElementById('loadingStatus');
        const resultSection = document.getElementById('resultSection');
        const tableHeader = document.getElementById('tableHeader');
        const tableBody = document.getElementById('tableBody');
        const consultarBtn = document.getElementById('consultar-btn');
        const limparBtn = document.getElementById('limpar-btn');
        const btnPDFSimplificado = document.getElementById('btn-pdf-simplificado');
        const btnPDFDetalhado = document.getElementById('btn-pdf-detalhado');
        const btnNovaConsulta = document.getElementById('btn-nova-consulta');
        const btnSalvarSICTitulo = document.getElementById('btn-salvar-sic-titulo');
        const tipoDocumentoBadge = document.getElementById('tipoDocumentoBadge');

        // Variáveis globais
        let dadosAtuais = null;
        let modalUnidades = null;
        let unidadesDisponiveis = [];

        // Função para mostrar mensagem de sucesso grande
        function showLargeSuccessMessage(message = 'SALVO COM SUCESSO!') {
            const largeMsgDiv = document.createElement('div');
            largeMsgDiv.className = 'success-message-large';
            largeMsgDiv.innerHTML = `
                <i class="fas fa-check-circle"></i>
                <div>${message}</div>
                <div style="font-size: 14px;">Dados atualizados no SIC</div>
            `;
            document.body.appendChild(largeMsgDiv);

            setTimeout(() => {
                largeMsgDiv.style.opacity = '0';
                largeMsgDiv.style.transition = 'opacity 0.3s ease';
                setTimeout(() => {
                    if (largeMsgDiv.parentNode) {
                        largeMsgDiv.parentNode.removeChild(largeMsgDiv);
                    }
                }, 300);
            }, 2000);
        }

        // Funções auxiliares
        function normalizarString(str) {
            if (!str || str === 'Não informado') return '';
            str = str.toString().toUpperCase().trim();
            str = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            str = str.replace(/[^\w\s]/g, '');
            str = str.replace(/\s+/g, ' ');
            return str;
        }

        function normalizarDocumento(doc) {
            if (!doc || doc === 'Não informado') return '';
            return doc.toString().replace(/\D/g, '');
        }

        function normalizarCEP(cep) {
            if (!cep || cep === 'Não informado') return '';
            return cep.toString().replace(/\D/g, '');
        }

        function normalizarTelefone(tel) {
            if (!tel || tel === 'Não informado') return '';
            let numeros = tel.toString().replace(/\D/g, '');
            if (numeros.length >= 11 && numeros.substring(0, 1) === '0') {
                numeros = numeros.substring(1);
            }
            return numeros;
        }

        function normalizarRegime(regime) {
            if (!regime || regime === 'Não informado') return '';
            const mapeamento = {
                '0': 'NENHUM',
                '1': 'LUCRO REAL',
                '2': 'LUCRO PRESUMIDO',
                '3': 'SIMPLES NACIONAL',
                '4': 'REIDI',
                '5': 'EIRELLI',
                'SIMPLES NACIONAL': 'SIMPLES NACIONAL',
                'SIMPLES': 'SIMPLES NACIONAL',
                'SN': 'SIMPLES NACIONAL',
                'MEI': 'MEI',
                'LUCRO REAL': 'LUCRO REAL',
                'LUCRO PRESUMIDO': 'LUCRO PRESUMIDO',
                'NENHUM': 'NENHUM'
            };
            let regimeUpper = regime.toString().toUpperCase().trim();
            if (mapeamento[regimeUpper]) return mapeamento[regimeUpper];
            if (regimeUpper.includes('SIMPLES')) return 'SIMPLES NACIONAL';
            if (regimeUpper.includes('MEI')) return 'MEI';
            if (regimeUpper.includes('LUCRO REAL')) return 'LUCRO REAL';
            if (regimeUpper.includes('LUCRO PRESUMIDO')) return 'LUCRO PRESUMIDO';
            return normalizarString(regime);
        }

        function compararValores(valorNeo, valorSic, campo) {
            if (!valorNeo || valorNeo === 'Não informado') return true;
            if (!valorSic || valorSic === 'Não informado') return false;
            let neoNorm = '',
                sicNorm = '';
            switch (campo) {
                case 'CODIC':
                    return parseInt(valorNeo) === parseInt(valorSic);
                case 'DOCUMENTO':
                    neoNorm = normalizarDocumento(valorNeo);
                    sicNorm = normalizarDocumento(valorSic);
                    break;
                case 'CEP':
                    neoNorm = normalizarCEP(valorNeo);
                    sicNorm = normalizarCEP(valorSic);
                    break;
                case 'TELEFONE':
                    neoNorm = normalizarTelefone(valorNeo);
                    sicNorm = normalizarTelefone(valorSic);
                    break;
                case 'TIPO DE REGIME':
                    neoNorm = normalizarRegime(valorNeo);
                    sicNorm = normalizarRegime(valorSic);
                    break;
                default:
                    neoNorm = normalizarString(valorNeo);
                    sicNorm = normalizarString(valorSic);
            }
            return neoNorm === sicNorm;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showError(message) {
            document.getElementById('errorText').textContent = message;
            document.getElementById('errorMessage').classList.add('show');
            setTimeout(() => hideMessage('errorMessage'), 5000);
        }

        function showSuccess(message) {
            document.getElementById('successText').textContent = message;
            document.getElementById('successMessage').classList.add('show');
            setTimeout(() => hideMessage('successMessage'), 5000);
        }

        function hideMessage(id) {
            document.getElementById(id).classList.remove('show');
        }

        function startLoading() {
            loading.style.display = 'block';
            resultSection.style.display = 'none';
            consultarBtn.disabled = true;
            limparBtn.disabled = true;
            documentoInput.disabled = true;
            progressBar.style.width = '0%';
        }

        function stopLoading() {
            loading.style.display = 'none';
            consultarBtn.disabled = false;
            limparBtn.disabled = false;
            documentoInput.disabled = false;
        }

        function resetForm() {
            documentoInput.value = '';
            resultSection.style.display = 'none';
            tableBody.innerHTML = '';
            btnPDFSimplificado.style.display = 'none';
            btnPDFDetalhado.style.display = 'none';
            btnNovaConsulta.style.display = 'none';
            btnSalvarSICTitulo.style.display = 'none';
            dadosAtuais = null;
            tipoDocumentoBadge.textContent = 'CPF';
            tipoDocumentoBadge.className = 'document-type-badge badge-cpf';

            // Remover seção de campos extras se existir
            const extraFields = document.querySelector('.extra-fields-section');
            if (extraFields) {
                extraFields.remove();
            }
        }

        function displayTableResults(data) {
            // Verificar se há dados extras para exibir
            const hasExtraFields = (data.tipo_documento === 'CNPJ' && data.neocredit.sucesso &&
                    (data.neocredit.dados.faixa_porte || data.neocredit.dados.tempo_mercado ||
                        data.neocredit.dados.capital_social || data.neocredit.dados.ie)) ||
                (data.tipo_documento === 'CPF' && data.neocredit.sucesso &&
                    (data.neocredit.dados.mother_name || data.neocredit.dados.age));

            let extraFieldsHtml = '';

            if (hasExtraFields) {
                extraFieldsHtml = '<div class="extra-fields-section">';
                extraFieldsHtml += '<div class="extra-fields-header"><i class="fas fa-info-circle"></i> Informações Adicionais</div>';
                extraFieldsHtml += '<div class="extra-fields-grid">';

                if (data.tipo_documento === 'CNPJ') {
                    const neo = data.neocredit.dados;
                    if (neo.faixa_porte && neo.faixa_porte !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-building"></i> Porte:</div>
                        <div class="extra-field-value">${escapeHtml(neo.faixa_porte)}</div>
                    </div>
                `;
                    }
                    if (neo.tempo_mercado && neo.tempo_mercado !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-clock"></i> Tempo de Empresa:</div>
                        <div class="extra-field-value">${escapeHtml(neo.tempo_mercado)}</div>
                    </div>
                `;
                    }
                    if (neo.capital_social && neo.capital_social !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-chart-line"></i> Capital Social:</div>
                        <div class="extra-field-value">${escapeHtml(neo.capital_social)}</div>
                    </div>
                `;
                    }
                    if (neo.ie && neo.ie !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-id-card"></i> Inscrição Estadual:</div>
                        <div class="extra-field-value">${escapeHtml(neo.ie)}</div>
                    </div>
                `;
                    }
                } else if (data.tipo_documento === 'CPF') {
                    const neo = data.neocredit.dados;
                    if (neo.mother_name && neo.mother_name !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-female"></i> Nome da Mãe:</div>
                        <div class="extra-field-value">${escapeHtml(neo.mother_name)}</div>
                    </div>
                `;
                    }
                    if (neo.age && neo.age !== 'Não informado') {
                        extraFieldsHtml += `
                    <div class="extra-field-item">
                        <div class="extra-field-label"><i class="fas fa-calendar-alt"></i> Idade:</div>
                        <div class="extra-field-value">${escapeHtml(neo.age)}</div>
                    </div>
                `;
                    }
                }

                extraFieldsHtml += '</div></div>';
            }

            // Construir o cabeçalho da tabela - APENAS COM UNIDADES QUE TEM DADOS
            let headerHtml = '<th>DESCRIÇÃO</th><th>NEOCREDIT</th>';
            const unidades = [];
            for (const [chave, resultado] of Object.entries(data.firebird)) {
                headerHtml += `<th>${escapeHtml(resultado.nome_unidade)}</th>`;
                unidades.push(chave);
            }
            tableHeader.innerHTML = headerHtml;

            // Construir o corpo da tabela
            let bodyHtml = '';
            for (const [campoKey, campoInfo] of Object.entries(data.campos)) {
                if (campoInfo.comparar === false) continue;

                bodyHtml += '      <tr>';
                bodyHtml += `        <td><strong>${escapeHtml(campoInfo.descricao)}</strong></td>`;

                // Coluna NeoCredit
                let neoValor = '';
                if (data.neocredit.sucesso) {
                    if (campoInfo.neo && data.neocredit.dados[campoInfo.neo]) {
                        neoValor = data.neocredit.dados[campoInfo.neo];
                    } else if (campoKey === 'DOCUMENTO' && data.tipo_documento === 'CNPJ') {
                        neoValor = data.neocredit.dados.cnpj || 'Não informado';
                    } else if (campoKey === 'DOCUMENTO' && data.tipo_documento === 'CPF') {
                        neoValor = data.neocredit.dados.cpf || 'Não informado';
                    } else {
                        neoValor = 'Não informado';
                    }
                    bodyHtml += `<td class="valor-encontrado"><strong>${escapeHtml(neoValor)}</strong></td>`;
                } else {
                    bodyHtml += `<td class="valor-nao-encontrado">${escapeHtml(data.neocredit.mensagem)}</td>`;
                }

                // Colunas das unidades SIC (apenas as que têm dados)
                for (const chave of unidades) {
                    const resultado = data.firebird[chave];
                    if (resultado.dados.sucesso) {
                        let valorSic = '';
                        const sicCampo = campoInfo.sic;
                        if (sicCampo && resultado.dados.dados[sicCampo.toUpperCase()]) {
                            valorSic = resultado.dados.dados[sicCampo.toUpperCase()];
                        } else {
                            valorSic = 'Não informado';
                        }

                        const isIgual = compararValores(neoValor, valorSic, campoInfo.descricao.toUpperCase());

                        if (!isIgual && neoValor !== 'Não informado' && valorSic !== 'Não informado') {
                            bodyHtml += `<td class="valor-diferente" title="Divergência: NeoCredit='${escapeHtml(neoValor)}' | SIC='${escapeHtml(valorSic)}'">`;
                            bodyHtml += `${escapeHtml(valorSic)} <span class="divergencia-icon">⚠️</span></td>`;
                        } else if (valorSic && valorSic !== 'Não informado') {
                            bodyHtml += `<td class="valor-encontrado">${escapeHtml(valorSic)}</td>`;
                        } else {
                            bodyHtml += `<td class="valor-nao-encontrado">${escapeHtml(valorSic)}</td>`;
                        }
                    } else {
                        // Esta unidade tem dados? Na verdade só chegamos aqui se a unidade está em 'firebird', 
                        // que agora só contém unidades com dados, então este caso não deve ocorrer
                        bodyHtml += `<td class="valor-nao-encontrado">${escapeHtml(resultado.dados.mensagem)}</td>`;
                    }
                }
                bodyHtml += '      </tr>';
            }
            tableBody.innerHTML = bodyHtml;

            // Inserir os campos extras ANTES da tabela
            const resultSectionDiv = document.getElementById('resultSection');
            const existingExtraFields = document.querySelector('.extra-fields-section');
            if (existingExtraFields) {
                existingExtraFields.remove();
            }

            if (extraFieldsHtml) {
                resultSectionDiv.insertAdjacentHTML('afterbegin', extraFieldsHtml);
            }

            resultSection.style.display = 'block';
            resultSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        // Funções do Modal
        function carregarUnidadesDisponiveis() {
            unidadesDisponiveis = [{
                    key: 'barra_mansa',
                    nome: 'SIC BARRA MANSA',
                    codic: null
                },
                {
                    key: 'botucatu',
                    nome: 'SIC BOTUCATU',
                    codic: null
                },
                {
                    key: 'lins',
                    nome: 'SIC LINS',
                    codic: null
                },
                {
                    key: 'rio_preto',
                    nome: 'SIC RIO PRETO',
                    codic: null
                },
                {
                    key: 'votuporanga',
                    nome: 'SIC VOTUPORANGA / RONDONOPOLIS',
                    codic: null
                }
            ];
        }

        function atualizarCodicUnidades() {
            for (const unidade of unidadesDisponiveis) {
                const dadosFirebird = dadosAtuais.firebird[unidade.key];
                if (dadosFirebird && dadosFirebird.dados && dadosFirebird.dados.sucesso && dadosFirebird.dados.dados.CODIC) {
                    unidade.codic = dadosFirebird.dados.dados.CODIC;
                } else {
                    unidade.codic = null;
                }
            }
        }

        function exibirResultadosSave(result, container) {
            if (!result.results || result.results.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 15px;">
                        <i class="fas fa-info-circle"></i> Nenhum resultado retornado
                    </div>
                `;
                return;
            }

            let html = `
                <div style="margin-bottom: 10px; padding: 8px; background: #e9ecef; border-radius: 6px;">
                    <strong>Resumo:</strong><br>
                    ✅ Sucesso: ${result.total_success || 0}<br>
                    ⚠️ Avisos: ${result.total_warnings || 0}<br>
                    ❌ Erros: ${result.total_errors || 0}
                </div>
                <div style="font-weight: 600; margin-bottom: 8px;">Detalhes por unidade:</div>
            `;

            for (const item of result.results) {
                let statusClass = '';
                let statusIcon = '';

                switch (item.status) {
                    case 'Sucesso':
                        statusClass = 'save-result-success';
                        statusIcon = '<i class="fas fa-check-circle"></i>';
                        break;
                    case 'Aviso':
                        statusClass = 'save-result-warning';
                        statusIcon = '<i class="fas fa-exclamation-triangle"></i>';
                        break;
                    case 'Erro':
                        statusClass = 'save-result-error';
                        statusIcon = '<i class="fas fa-times-circle"></i>';
                        break;
                    default:
                        statusClass = '';
                        statusIcon = '<i class="fas fa-info-circle"></i>';
                }

                html += `
                    <div class="save-result-item ${statusClass}">
                        <div class="save-result-icon">${statusIcon}</div>
                        <div>
                            <strong>${escapeHtml(item.unidade)}</strong><br>
                            <span style="font-size: 12px;">${escapeHtml(item.message)}</span>
                            ${item.changes ? `<br><span style="font-size: 11px; color: #666;">Alterações: ${escapeHtml(item.changes.join(', '))}</span>` : ''}
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;
            container.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function abrirModalUnidades() {
            if (!dadosAtuais || !dadosAtuais.firebird) {
                showError('Nenhum dado disponível para salvar');
                return;
            }

            const saveResultsDiv = document.getElementById('saveResults');
            saveResultsDiv.style.display = 'none';
            saveResultsDiv.innerHTML = '';

            atualizarCodicUnidades();

            const unidadesListDiv = document.getElementById('unidadesList');
            unidadesListDiv.innerHTML = `
                <div class="usuario-select-container">
                    <label for="selectUsuario">
                        <i class="fas fa-user-edit"></i> Usuário responsável pela alteração:
                    </label>
                    <select id="selectUsuario" class="select-usuario">
                        <option value="">Selecione um usuário</option>
                        <option value="EFANECO">EFANECO</option>
                        <option value="ISABELLY">ISABELLY</option>
                        <option value="MARCUS">MARCUS</option>
                        <option value="ANDREA">ANDREA</option>
                        <option value="GABRIELAF">GABRIELAF</option>
                        <option value="EMANOELE">EMANOELE</option>
                    </select>
                </div>
                <div id="unidadesCheckboxes"></div>
            `;

            const unidadesCheckboxesDiv = document.getElementById('unidadesCheckboxes');
            unidadesCheckboxesDiv.innerHTML = '';

            for (const unidade of unidadesDisponiveis) {
                const dadosFirebird = dadosAtuais.firebird[unidade.key];
                const temCodic = dadosFirebird && dadosFirebird.dados && dadosFirebird.dados.sucesso && dadosFirebird.dados.dados.CODIC;

                const div = document.createElement('div');
                div.className = 'unidade-checkbox';

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = `unidade_${unidade.key}`;
                checkbox.value = `${unidade.key}|${temCodic ? dadosFirebird.dados.dados.CODIC : ''}`;
                checkbox.disabled = !temCodic;

                const label = document.createElement('label');
                label.htmlFor = `unidade_${unidade.key}`;
                label.innerHTML = `${unidade.nome} ${temCodic ? `<span class="codic-info">(CODIC: ${dadosFirebird.dados.dados.CODIC})</span>` : '<span class="codic-info" style="color:#dc3545;">(Registro não encontrado)</span>'}`;

                div.appendChild(checkbox);
                div.appendChild(label);

                if (!temCodic) {
                    div.style.opacity = '0.6';
                    div.title = 'Registro não encontrado nesta unidade';
                }

                unidadesCheckboxesDiv.appendChild(div);
            }

            const btnConfirmar = document.getElementById('btnConfirmarSalvar');
            btnConfirmar.disabled = true;

            function validateForm() {
                const usuarioSelecionado = document.getElementById('selectUsuario').value;
                const temSelecionados = Array.from(document.querySelectorAll('#unidadesCheckboxes input[type="checkbox"]:checked')).length > 0;
                btnConfirmar.disabled = !(usuarioSelecionado && temSelecionados);
            }

            const selectUsuario = document.getElementById('selectUsuario');
            selectUsuario.addEventListener('change', validateForm);

            document.querySelectorAll('#unidadesCheckboxes input[type="checkbox"]').forEach(cb => {
                cb.addEventListener('change', validateForm);
            });

            btnConfirmar.onclick = () => confirmarSalvarSIC();

            modalUnidades.style.display = 'block';
        }

        async function confirmarSalvarSIC() {
            const checkboxes = document.querySelectorAll('#unidadesCheckboxes input[type="checkbox"]:checked');
            const unidadesSelecionadas = Array.from(checkboxes).map(cb => cb.value);
            const usuarioAlterou = document.getElementById('selectUsuario').value;

            if (unidadesSelecionadas.length === 0) {
                showError('Selecione pelo menos uma unidade para atualizar');
                return;
            }

            if (!usuarioAlterou) {
                showError('Selecione o usuário responsável pela alteração');
                return;
            }

            const documento = documentoInput.value.replace(/\D/g, '');
            const tipoDocumento = dadosAtuais.tipo_documento;

            let dadosEnvio = {};

            if (tipoDocumento === 'CPF') {
                const neo = dadosAtuais.neocredit.dados;
                dadosEnvio = {
                    save_action: 'salvar_sic',
                    unidades: unidadesSelecionadas,
                    nome: neo.nome || 'Não informado',
                    dt_nascimento: neo.dt_nascimento || 'Não informado',
                    tipo_documento: 'CPF',
                    usu_alterou: usuarioAlterou
                };
            } else {
                const neo = dadosAtuais.neocredit.dados;
                dadosEnvio = {
                    save_action: 'salvar_sic_cnpj',
                    unidades: unidadesSelecionadas,
                    dados_cnpj: {
                        cnpj: neo.cnpj || documento,
                        razao: neo.razao || 'Não informado',
                        fantasia: neo.fantasia || 'Não informado',
                        dt_abertura: neo.dt_abertura || 'Não informado',
                        logradouro: neo.logradouro || 'Não informado',
                        numero: neo.numero || 'Não informado',
                        complemento: neo.complemento || 'Não informado',
                        bairro: neo.bairro || 'Não informado',
                        cidade: neo.cidade || 'Não informado',
                        uf: neo.uf || 'Não informado',
                        cep: neo.cep || 'Não informado',
                        email: neo.email || 'Não informado',
                        fone_completo: neo.fone_completo || 'Não informado',
                        regime_icms: neo.regime_icms || 'Não informado'
                    },
                    tipo_documento: 'CNPJ',
                    usu_alterou: usuarioAlterou
                };
            }

            const btnConfirmar = document.getElementById('btnConfirmarSalvar');
            const originalText = btnConfirmar.innerHTML;
            btnConfirmar.disabled = true;
            btnConfirmar.innerHTML = '<span class="loading-small"></span> Processando...';

            const saveResultsDiv = document.getElementById('saveResults');
            saveResultsDiv.style.display = 'block';
            saveResultsDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><span class="loading-small"></span> Atualizando unidades selecionadas...</div>';

            try {
                const scriptUrl = tipoDocumento === 'CPF' ?
                    'dados_cadastrais_cpf_save.php' :
                    'dados_cadastrais_cnpj_save.php';

                const response = await fetch(scriptUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(dadosEnvio)
                });

                const result = await response.json();
                exibirResultadosSave(result, saveResultsDiv);

                if (result.success) {
                    showSuccess(result.message);

                    const hasSuccess = (result.total_success || 0) > 0;

                    if (hasSuccess) {
                        showLargeSuccessMessage('SALVO COM SUCESSO!');

                        setTimeout(() => {
                            fecharModalUnidades();
                        }, 1500);
                    } else if (result.total_warnings > 0) {
                        setTimeout(() => {
                            fecharModalUnidades();
                            showSuccess('Processamento concluído com avisos. Verifique os detalhes.');
                        }, 3000);
                    }
                } else {
                    showError(result.message || 'Erro ao processar atualização');
                }
            } catch (error) {
                console.error('Erro ao salvar:', error);
                saveResultsDiv.innerHTML = `
                    <div style="color: #dc3545; text-align: center; padding: 15px;">
                        <i class="fas fa-exclamation-circle"></i> 
                        Erro ao processar: ${error.message}
                    </div>
                `;
                showError('Erro ao salvar: ' + error.message);
            } finally {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = originalText;
            }
        }

        function fecharModalUnidades() {
            if (modalUnidades) {
                modalUnidades.style.display = 'none';

                const saveResultsDiv = document.getElementById('saveResults');
                if (saveResultsDiv) {
                    saveResultsDiv.style.display = 'none';
                    saveResultsDiv.innerHTML = '';
                }

                const checkboxes = document.querySelectorAll('#unidadesCheckboxes input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });

                const selectUsuario = document.getElementById('selectUsuario');
                if (selectUsuario) {
                    selectUsuario.value = '';
                }

                const btnConfirmar = document.getElementById('btnConfirmarSalvar');
                if (btnConfirmar) {
                    btnConfirmar.disabled = true;
                }
            }
        }

        // Eventos
        documentoInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 14) value = value.slice(0, 14);
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                tipoDocumentoBadge.textContent = 'CPF';
                tipoDocumentoBadge.className = 'document-type-badge badge-cpf';
            } else {
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
                tipoDocumentoBadge.textContent = 'CNPJ';
                tipoDocumentoBadge.className = 'document-type-badge badge-cnpj';
            }
            e.target.value = value;
        });

        documentoInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') consultarBtn.click();
        });

        consultarBtn.addEventListener('click', async function() {
            const documento = documentoInput.value.replace(/\D/g, '');
            if (documento.length !== 11 && documento.length !== 14) {
                showError('CPF deve ter 11 dígitos ou CNPJ 14 dígitos');
                documentoInput.style.borderColor = '#ff4444';
                return;
            }
            documentoInput.style.borderColor = '#e0e0e0';
            startLoading();
            let progress = 0;
            const interval = setInterval(() => {
                progress += 1;
                const percent = (progress / 180) * 100;
                progressBar.style.width = percent + '%';
                if (progress < 60) loadingStatus.textContent = 'Consultando SIC...';
                else if (progress < 120) loadingStatus.textContent = 'Consultando NeoCredit...';
                else loadingStatus.textContent = 'Finalizando...';
                if (progress >= 180) clearInterval(interval);
            }, 1000);
            try {
                const formData = new FormData();
                formData.append('documento', documento);
                formData.append('ajax', 'true');
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                clearInterval(interval);
                progressBar.style.width = '100%';
                setTimeout(() => {
                    stopLoading();
                    if (data.error) {
                        showError(data.error);
                        formSection.classList.remove('hidden');
                    } else {
                        displayTableResults(data);
                        dadosAtuais = data;
                        formSection.classList.add('hidden');
                        btnPDFSimplificado.style.display = data.tipo_documento === 'CPF' ? 'inline-flex' : 'none';
                        btnPDFDetalhado.style.display = data.tipo_documento === 'CNPJ' ? 'inline-flex' : 'none';
                        btnNovaConsulta.style.display = 'inline-flex';
                        btnSalvarSICTitulo.style.display = 'inline-flex';
                        showSuccess('Consulta realizada com sucesso!');
                    }
                }, 500);
            } catch (error) {
                clearInterval(interval);
                stopLoading();
                showError('Erro na consulta: ' + error.message);
                formSection.classList.remove('hidden');
            }
        });

        btnNovaConsulta.addEventListener('click', function() {
            resetForm();
            formSection.classList.remove('hidden');
        });

        limparBtn.addEventListener('click', function() {
            resetForm();
        });

        btnPDFSimplificado.addEventListener('click', function() {
            if (dadosAtuais?.neocredit.sucesso && dadosAtuais.tipo_documento === 'CPF') {
                const neo = dadosAtuais.neocredit.dados;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'pdf_pf.php';
                form.target = '_blank';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'dados';
                input.value = JSON.stringify({
                    nome_completo: neo.nome,
                    data_nascimento: neo.dt_nascimento,
                    cpf: neo.cpf,
                    status_cpf: neo.status,
                    email: neo.email,
                    telefone: neo.fone_completo,
                    nome_mae: neo.mother_name,
                    idade: neo.age
                });
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            } else {
                showError('Nenhum dado de CPF disponível');
            }
        });

        btnPDFDetalhado.addEventListener('click', function() {
            if (dadosAtuais?.neocredit.sucesso && dadosAtuais.tipo_documento === 'CNPJ') {
                const neo = dadosAtuais.neocredit.dados;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'pdf_pj.php';
                form.target = '_blank';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'dados';
                input.value = JSON.stringify({
                    cnpj: neo.cnpj,
                    razao_social: neo.razao,
                    fantasia: neo.fantasia,
                    data_abertura: neo.dt_abertura,
                    situacao_cnpj: neo.status,
                    logradouro: neo.logradouro,
                    numero: neo.numero,
                    complemento: neo.complemento,
                    bairro: neo.bairro,
                    cidade: neo.cidade,
                    uf: neo.uf,
                    cep: neo.cep,
                    email: neo.email,
                    telefone: neo.fone_completo,
                    regime_icms: neo.regime_icms,
                    porte: neo.faixa_porte,
                    tempo_empresa: neo.tempo_mercado,
                    capital_social: neo.capital_social,
                    inscricao_estadual: neo.ie
                });
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            } else {
                showError('Nenhum dado de CNPJ disponível');
            }
        });

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            modalUnidades = document.getElementById('modalUnidades');
            carregarUnidadesDisponiveis();

            if (btnSalvarSICTitulo) {
                btnSalvarSICTitulo.addEventListener('click', function() {
                    if (!dadosAtuais) {
                        showError('Nenhum dado disponível para salvar');
                        return;
                    }
                    abrirModalUnidades();
                });
            }
        });

        window.onclick = function(event) {
            if (event.target === modalUnidades) {
                fecharModalUnidades();
            }
        }
    </script>
</body>

</html>