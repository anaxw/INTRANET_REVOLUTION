<?php
session_start();
require_once 'TodasConexoes.php';

header('Content-Type: application/json');

try {
    // Verificar se o usuário está logado
    if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
        throw new Exception('Usuário não autenticado. Faça login novamente.');
    }
    
    $usuario = $_SESSION['usuario'];
    
    // Receber dados do POST
    $codigo_cliente = $_POST['codigo_cliente'] ?? null;
    $razao_social = $_POST['razao_social'] ?? '';
    $documento = $_POST['documento'] ?? '';
    $id_neocredit = $_POST['id_neocredit'] ?? '';
    $total_distribuido = floatval($_POST['total_distribuido'] ?? 0);
    $distribuicoes = json_decode($_POST['distribuicoes'] ?? '[]', true);
    
    if (!$codigo_cliente) {
        throw new Exception('Código do cliente não informado');
    }
    
    if (empty($distribuicoes)) {
        throw new Exception('Nenhuma distribuição para salvar');
    }
    
    // Conexão com PostgreSQL
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Iniciar transação
    $pdo->beginTransaction();
    
    // 1. Inativar distribuições anteriores
    $sqlInativar = "UPDATE lcred_distribuicao 
                    SET status_distribuicao = 'INATIVA' 
                    WHERE codigo_cliente = ? AND status_distribuicao = 'ATIVA'";
    $stmtInativar = $pdo->prepare($sqlInativar);
    $stmtInativar->execute([$codigo_cliente]);
    
    // 2. Inserir novas distribuições
    $sqlInserir = "INSERT INTO lcred_distribuicao 
                  (codigo_cliente, id_neocredit, razao_social, documento, 
                   unidade, nome_unidade, valor_distribuido, porcentagem,
                   usuario_distribuidor, status_distribuicao) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ATIVA')";
    
    $stmtInserir = $pdo->prepare($sqlInserir);
    
    $resultadosUpdate = [];
    $errosUnidades = [];
    
    foreach ($distribuicoes as $distribuicao) {
        $unidade = $distribuicao['unidade'];
        $nome_unidade = $distribuicao['nome_unidade'];
        $valor = floatval($distribuicao['valor']);
        $codic = $distribuicao['codic'] ?? null;
        
        if ($valor <= 0) {
            continue; // Pular valores zero ou negativos
        }
        
        // Calcular porcentagem
        $porcentagem = ($total_distribuido > 0) ? ($valor / $total_distribuido * 100) : 0;
        
        // Inserir no PostgreSQL
        $stmtInserir->execute([
            $codigo_cliente,
            $id_neocredit,
            $razao_social,
            $documento,
            $unidade,
            $nome_unidade,
            $valor,
            round($porcentagem, 2),
            $usuario
        ]);
        
        // 3. ATUALIZAR O LIMITE NA UNIDADE (Firebird)
        // Mapear o nome da unidade para o Firebird
        $unidadeFirebird = '';
        switch ($unidade) {
            case 'barra_mansa':
                $unidadeFirebird = 'Barra Mansa';
                break;
            case 'botucatu':
                $unidadeFirebird = 'Botucatu';
                break;
            case 'lins':
                $unidadeFirebird = 'Lins';
                break;
            case 'rio_preto':
                $unidadeFirebird = 'Rio Preto';
                break;
            case 'votuporanga':
                $unidadeFirebird = 'Votuporanga';
                break;
            default:
                $errosUnidades[] = "Unidade desconhecida: {$unidade}";
                continue 2; // Pular para próxima iteração
        }
        
        try {
            // Tentar atualizar usando o CODIC se disponível
            if ($codic && is_numeric($codic)) {
                $resultadoUpdate = TodasConexoes::atualizarLimitePorCodic(
                    $unidadeFirebird,
                    $codic,
                    $valor,
                    $usuario
                );
            } else {
                // Se não tiver CODIC, tentar pelo documento
                $resultadoUpdate = TodasConexoes::atualizarLimiteUnidade(
                    $unidadeFirebird,
                    $documento,
                    $valor,
                    $usuario
                );
            }
            
            $resultadosUpdate[] = [
                'unidade' => $unidadeFirebird,
                'sucesso' => true,
                'codic' => $resultadoUpdate['codic'] ?? $codic,
                'limite' => $valor,
                'mensagem' => 'Limite atualizado com sucesso'
            ];
            
        } catch (Exception $e) {
            // Registrar erro mas continuar com outras unidades
            $erroMsg = $e->getMessage();
            error_log("Erro ao atualizar unidade {$unidadeFirebird}: " . $erroMsg);
            
            $resultadosUpdate[] = [
                'unidade' => $unidadeFirebird,
                'sucesso' => false,
                'codic' => $codic,
                'limite' => $valor,
                'erro' => $erroMsg,
                'mensagem' => 'Falha ao atualizar limite'
            ];
            
            $errosUnidades[] = "{$unidadeFirebird}: {$erroMsg}";
        }
    }
    
    // 4. Atualizar situação do crédito para Finalizado (F)
    $sqlAtualizarSituacao = "UPDATE lcred_neocredit SET situ = 'F' WHERE codigo = ?";
    $stmtSituacao = $pdo->prepare($sqlAtualizarSituacao);
    $stmtSituacao->execute([$codigo_cliente]);
    
    // Commit da transação
    $pdo->commit();
    
    // Preparar resposta
    $response = [
        'success' => true,
        'message' => 'Distribuição salva com sucesso',
        'total_distribuido' => $total_distribuido,
        'unidades_atualizadas' => $resultadosUpdate,
        'usuario' => $usuario,
        'codigo_cliente' => $codigo_cliente,
        'situacao_atualizada' => 'F'
    ];
    
    // Adicionar aviso se houver erros em algumas unidades
    if (!empty($errosUnidades)) {
        $response['aviso'] = 'Algumas unidades não foram atualizadas: ' . implode('; ', $errosUnidades);
        $response['tem_erros'] = true;
    } else {
        $response['tem_erros'] = false;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback em caso de erro
    if (isset($pdo)) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Erro no rollback: " . $rollbackError->getMessage());
        }
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}