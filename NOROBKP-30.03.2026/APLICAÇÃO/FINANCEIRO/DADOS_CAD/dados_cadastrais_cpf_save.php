<?php
/**
 * Script para atualizar dados cadastrais de CPF no SIC
 * Arquivo: dados_cadastrais_cpf_save.php
 * 
 * Alterações: Adicionado campo usu_alterou dinâmico
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

function logSaveMessage($message, $type = 'info') {
    $logFile = 'cpf_update_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Extrai as duas primeiras palavras do nome completo
 */
function extrairDuasPrimeirasPalavras($nomeCompleto) {
    if (empty($nomeCompleto) || $nomeCompleto === 'Não informado') {
        return '';
    }
    
    $partes = explode(' ', trim($nomeCompleto));
    
    if (count($partes) == 1) {
        return $nomeCompleto;
    }
    
    $duasPrimeiras = array_slice($partes, 0, 2);
    return implode(' ', $duasPrimeiras);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['save_action']) || $input['save_action'] !== 'salvar_sic') {
        throw new Exception('Requisição inválida');
    }
    
    $unidades = $input['unidades'] ?? [];
    $nome = trim($input['nome'] ?? '');
    $dt_nascimento = trim($input['dt_nascimento'] ?? '');
    $tipo_documento = $input['tipo_documento'] ?? 'CPF';
    $usu_alterou = trim($input['usu_alterou'] ?? '');
    
    if (empty($unidades)) {
        throw new Exception('Nenhuma unidade selecionada');
    }
    
    if (empty($nome)) {
        throw new Exception('Nome não informado');
    }
    
    if (empty($usu_alterou)) {
        throw new Exception('Usuário responsável não informado');
    }
    
    logSaveMessage("========================================");
    logSaveMessage("Iniciando atualização para $tipo_documento");
    logSaveMessage("Nome completo: $nome");
    logSaveMessage("Data Nascimento: $dt_nascimento");
    logSaveMessage("Usuário: $usu_alterou");
    logSaveMessage("Total de unidades selecionadas: " . count($unidades));
    
    $nomeProcessado = extrairDuasPrimeirasPalavras($nome);
    
    logSaveMessage("Nome processado (2 primeiras palavras): $nomeProcessado");
    
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
            logSaveMessage("Processando: {$config['nome']} - CODIC: $codic");
            
            $conexao = new PDO($config['dsn'], $config['user'], $config['pass']);
            $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $checkSql = "SELECT codic, nome, nfantasia, contato, tipoc, situ, dt_fundacao 
                        FROM arqcad 
                        WHERE codic = :codic 
                          AND tipoc = 'C' 
                          AND situ IN ('A', 'B')";
            
            $checkStmt = $conexao->prepare($checkSql);
            $checkStmt->bindParam(':codic', $codic, PDO::PARAM_INT);
            $checkStmt->execute();
            
            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                throw new Exception("Registro não encontrado ou não ativo (CODIC: $codic)");
            }
            
            $dt_nascimento_formatada = null;
            if (!empty($dt_nascimento) && $dt_nascimento !== 'Não informado') {
                $dateObj = DateTime::createFromFormat('d/m/Y', $dt_nascimento);
                if ($dateObj) {
                    $dt_nascimento_formatada = $dateObj->format('Y-m-d');
                } else {
                    $dateObj = DateTime::createFromFormat('Y-m-d', $dt_nascimento);
                    if ($dateObj) {
                        $dt_nascimento_formatada = $dateObj->format('Y-m-d');
                    }
                }
            }
            
            $currentNome = trim($record['nome'] ?? '');
            $currentNFantasia = trim($record['nfantasia'] ?? '');
            $currentContato = trim($record['contato'] ?? '');
            $currentDtFundacao = $record['dt_fundacao'];
            
            $nomeAtualizado = trim($nome);
            
            $updateNeeded = false;
            $changes = [];
            
            if ($currentNome !== $nomeAtualizado) {
                $updateNeeded = true;
                $changes[] = "nome: '$currentNome' -> '$nomeAtualizado'";
                logSaveMessage("Nome diferente: '$currentNome' -> '$nomeAtualizado'");
            }
            
            if ($currentNFantasia !== $nomeProcessado) {
                $updateNeeded = true;
                $changes[] = "nfantasia: '$currentNFantasia' -> '$nomeProcessado'";
                logSaveMessage("NFantasia diferente: '$currentNFantasia' -> '$nomeProcessado'");
            }
            
            if ($currentContato !== $nomeProcessado) {
                $updateNeeded = true;
                $changes[] = "contato: '$currentContato' -> '$nomeProcessado'";
                logSaveMessage("Contato diferente: '$currentContato' -> '$nomeProcessado'");
            }
            
            if ($dt_nascimento_formatada && $currentDtFundacao != $dt_nascimento_formatada) {
                $updateNeeded = true;
                $changes[] = "data: '$currentDtFundacao' -> '$dt_nascimento_formatada'";
                logSaveMessage("Data diferente: '$currentDtFundacao' -> '$dt_nascimento_formatada'");
            }
            
            if (!$updateNeeded) {
                $results[] = [
                    'unidade' => $config['nome'],
                    'codic' => $codic,
                    'status' => 'Aviso',
                    'message' => 'Dados já estão atualizados'
                ];
                $warningCount++;
                $conexao = null;
                continue;
            }
            
            $updateSql = "UPDATE arqcad 
                         SET nome = :nome, 
                             nfantasia = :nfantasia,
                             contato = :contato,
                             dt_fundacao = :dt_fundacao,
                             usu_alterou = :usu_alterou,
                             dt_alteracao = CURRENT_TIMESTAMP
                         WHERE codic = :codic 
                           AND tipoc = 'C' 
                           AND situ IN ('A', 'B')";
            
            $updateStmt = $conexao->prepare($updateSql);
            $updateStmt->bindParam(':nome', $nomeAtualizado);
            $updateStmt->bindParam(':nfantasia', $nomeProcessado);
            $updateStmt->bindParam(':contato', $nomeProcessado);
            $updateStmt->bindParam(':dt_fundacao', $dt_nascimento_formatada);
            $updateStmt->bindParam(':usu_alterou', $usu_alterou);
            $updateStmt->bindParam(':codic', $codic, PDO::PARAM_INT);
            $updateStmt->execute();
            
            $rowsAffected = $updateStmt->rowCount();
            
            if ($rowsAffected > 0) {
                logSaveMessage("SUCESSO - {$config['nome']} CODIC $codic - Alterações: " . implode(', ', $changes));
                $results[] = [
                    'unidade' => $config['nome'],
                    'codic' => $codic,
                    'status' => 'Sucesso',
                    'message' => 'Dados atualizados com sucesso',
                    'changes' => $changes,
                    'rows_affected' => $rowsAffected
                ];
                $successCount++;
            } else {
                throw new Exception('Nenhum registro foi atualizado');
            }
            
            $conexao = null;
            
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            logSaveMessage("ERRO PDO - {$config['nome']} CODIC $codic: $errorMsg", 'error');
            $results[] = [
                'unidade' => $config['nome'],
                'codic' => $codic,
                'status' => 'Erro',
                'message' => 'Erro no banco de dados: ' . $errorMsg
            ];
            $errorCount++;
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            logSaveMessage("ERRO - {$config['nome']} CODIC $codic: $errorMsg", 'error');
            $results[] = [
                'unidade' => $config['nome'],
                'codic' => $codic,
                'status' => 'Erro',
                'message' => $errorMsg
            ];
            $errorCount++;
        }
    }
    
    logSaveMessage("RESUMO - Sucessos: $successCount, Avisos: $warningCount, Erros: $errorCount");
    logSaveMessage("========================================");
    
    echo json_encode([
        'success' => true,
        'message' => "Processamento concluído: ✅ $successCount sucesso(s), ⚠️ $warningCount aviso(s), ❌ $errorCount erro(s)",
        'results' => $results,
        'total_success' => $successCount,
        'total_warnings' => $warningCount,
        'total_errors' => $errorCount
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logSaveMessage("ERRO GERAL: " . $e->getMessage(), 'error');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>