<?php
session_start();

// LIMPAR COMPLETAMENTE QUALQUER SAÍDA ANTES DO JSON
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Desabilitar totalmente exibição de erros
error_reporting(0);
ini_set('display_errors', 0);

// Array de resposta
$response = ['success' => false, 'message' => '', 'results' => []];

try {
    // Verificar se é POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Verificar dados básicos
    if (!isset($_POST['codigo_cliente']) || !isset($_POST['distribuicoes'])) {
        throw new Exception('Dados incompletos');
    }

    $codigo_cliente = $_POST['codigo_cliente'];
    $razao_social = $_POST['razao_social'] ?? '';
    $documento = $_POST['documento'] ?? '';
    $id_neocredit = $_POST['id_neocredit'] ?? '';

    // Decodificar JSON
    $distribuicoes = json_decode($_POST['distribuicoes'], true);
    if (!$distribuicoes) {
        throw new Exception('Erro ao decodificar distribuições');
    }

    // ========== CONFIGURAÇÕES FIREBIRD ==========
    $conexoes_firebird = [
        'barra_mansa' => [
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_BM/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'botucatu' => [
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_Botucatu/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'votuporanga' => [
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'lins' => [
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_Lins/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ],
        'rio_preto' => [
            'dsn' => 'firebird:dbname=10.10.94.15:c:/SIC_RP/Arq01/ARQSIST.FDB;charset=UTF8',
            'user' => 'SYSDBA',
            'pass' => 'masterkey'
        ]
    ];

    // ========== CONEXÃO POSTGRESQL ==========
    function conectarPostgres() {
        try {
            $pdo = new PDO(
                "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
                "postgres",
                "postgres"
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Erro ao conectar PostgreSQL: " . $e->getMessage());
        }
    }

    $resultados = [
        'atualizadas' => [],
        'ignoradas' => [],
        'erros' => []
    ];

    // ========== PROCESSAR CADA UNIDADE NO FIREBIRD ==========
    $total_atualizadas_firebird = 0;
    
    foreach ($distribuicoes as $dist) {
        // Validar dados mínimos
        if (!isset($dist['unidade'], $dist['valor'], $dist['codic'])) {
            continue;
        }

        $unidade = $dist['unidade'];
        $valor = floatval($dist['valor']);
        $codic_original = $dist['codic'];
        $nome_unidade = $dist['nome_unidade'] ?? $unidade;
        
        if ($valor <= 0) continue;

        // Extrair número do CODIC
        $codic = null;
        if (is_numeric($codic_original)) {
            $codic = $codic_original;
        } elseif (preg_match('/\((\d+)\)/', $codic_original, $matches)) {
            $codic = $matches[1];
        } elseif (preg_match('/(\d+)/', $codic_original, $matches)) {
            $codic = $matches[1];
        }

        // Se não tem CODIC válido, ignorar
        if (!$codic) {
            $resultados['ignoradas'][] = [
                'unidade' => $unidade,
                'nome' => $nome_unidade,
                'codic' => $codic_original,
                'valor' => $valor,
                'motivo' => 'CODIC inválido ou vazio'
            ];
            continue;
        }

        try {
            // Verificar se a unidade existe nas configurações
            if (!isset($conexoes_firebird[$unidade])) {
                throw new Exception("Configuração não encontrada para unidade: $unidade");
            }

            // Conectar ao Firebird
            $conn = new PDO(
                $conexoes_firebird[$unidade]['dsn'],
                $conexoes_firebird[$unidade]['user'],
                $conexoes_firebird[$unidade]['pass']
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // VERIFICAR SE CLIENTE EXISTE NESTA UNIDADE
            $stmt = $conn->prepare("SELECT FIRST 1 CODIC, LCRED, NOME FROM ARQCAD WHERE CODIC = ? AND TIPOC = 'C'");
            $stmt->execute([$codic]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cliente) {
                // Cliente não existe nesta unidade - IGNORAR (não é erro)
                $resultados['ignoradas'][] = [
                    'unidade' => $unidade,
                    'nome' => $nome_unidade,
                    'codic' => $codic,
                    'valor' => $valor,
                    'motivo' => 'Cliente não possui cadastro nesta unidade'
                ];
                continue;
            }

            // CLIENTE EXISTE - ATUALIZAR LIMITE
            $valorFormatado = number_format($valor, 2, '.', '');
            $usuario = 'MARCUS';
            
            $stmt = $conn->prepare("UPDATE ARQCAD SET LCRED = ?, USU_ALTEROU = ? WHERE CODIC = ? AND TIPOC = 'C'");
            $stmt->execute([$valorFormatado, $usuario, $codic]);

            if ($stmt->rowCount() > 0) {
                $total_atualizadas_firebird++;
                $resultados['atualizadas'][] = [
                    'unidade' => $unidade,
                    'nome' => $nome_unidade,
                    'codic' => $codic,
                    'valor' => $valor,
                    'limite_anterior' => $cliente['LCRED'] ?? 0,
                    'limite_novo' => $valorFormatado,
                    'cliente' => $cliente['NOME'] ?? 'N/A'
                ];
            } else {
                $resultados['erros'][] = [
                    'unidade' => $unidade,
                    'nome' => $nome_unidade,
                    'codic' => $codic,
                    'valor' => $valor,
                    'erro' => 'Nenhum registro foi atualizado'
                ];
            }

        } catch (PDOException $e) {
            $resultados['erros'][] = [
                'unidade' => $unidade,
                'nome' => $nome_unidade,
                'codic' => $codic,
                'valor' => $valor,
                'erro' => 'Erro Firebird: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $resultados['erros'][] = [
                'unidade' => $unidade,
                'nome' => $nome_unidade,
                'codic' => $codic,
                'valor' => $valor,
                'erro' => $e->getMessage()
            ];
        }
    }

    // ========== SE PELO MENOS UMA UNIDADE FOI ATUALIZADA ==========
    // ========== FINALIZAR O CRÉDITO NO POSTGRESQL ==========
    $finalizado_postgres = false;
    $mensagem_postgres = '';

    if ($total_atualizadas_firebird > 0) {
        try {
            // Conectar ao PostgreSQL
            $pdo_postgres = conectarPostgres();
            
            // ATUALIZAR SITUAÇÃO DO CRÉDITO PARA FINALIZADO (F)
            $sql_update = "UPDATE lcred_neocredit SET situ = 'F' WHERE codigo = :codigo";
            $stmt = $pdo_postgres->prepare($sql_update);
            $stmt->bindParam(':codigo', $codigo_cliente, PDO::PARAM_INT);
            $stmt->execute();
            
            $linhas_afetadas = $stmt->rowCount();
            
            if ($linhas_afetadas > 0) {
                $finalizado_postgres = true;
                $mensagem_postgres = "Crédito finalizado com sucesso no PostgreSQL";
            } else {
                // Verificar se já estava finalizado
                $stmt_check = $pdo_postgres->prepare("SELECT situ FROM lcred_neocredit WHERE codigo = :codigo");
                $stmt_check->bindParam(':codigo', $codigo_cliente);
                $stmt_check->execute();
                $situacao_atual = $stmt_check->fetchColumn();
                
                if ($situacao_atual === 'F') {
                    $mensagem_postgres = "Crédito já estava finalizado anteriormente";
                    $finalizado_postgres = true;
                } else {
                    $mensagem_postgres = "Nenhuma linha foi atualizada no PostgreSQL (código não encontrado?)";
                }
            }
            
        } catch (PDOException $e) {
            $mensagem_postgres = "Erro ao finalizar no PostgreSQL: " . $e->getMessage();
        } catch (Exception $e) {
            $mensagem_postgres = "Erro ao finalizar no PostgreSQL: " . $e->getMessage();
        }
    }

    // ========== MONTAR RESPOSTA FINAL ==========
    if ($total_atualizadas_firebird > 0) {
        $mensagem = "✅ $total_atualizadas_firebird unidade(s) atualizada(s) no Firebird!";
        
        if ($finalizado_postgres) {
            $mensagem .= " Crédito finalizado no PostgreSQL.";
        } else {
            $mensagem .= " ⚠️ $mensagem_postgres";
        }
        
        $response = [
            'success' => true,
            'message' => $mensagem,
            'codigo_cliente' => $codigo_cliente,
            'razao_social' => $razao_social,
            'total_atualizadas_firebird' => $total_atualizadas_firebird,
            'finalizado_postgres' => $finalizado_postgres,
            'mensagem_postgres' => $mensagem_postgres,
            'resultados' => $resultados,
            'resumo' => [
                'atualizadas_firebird' => count($resultados['atualizadas']),
                'ignoradas_sem_codic' => count(array_filter($resultados['ignoradas'], function($item) {
                    return strpos($item['motivo'], 'CODIC') !== false;
                })),
                'ignoradas_sem_cadastro' => count(array_filter($resultados['ignoradas'], function($item) {
                    return strpos($item['motivo'], 'cadastro') !== false;
                })),
                'erros_firebird' => count($resultados['erros'])
            ]
        ];
    } else {
        // Nenhuma unidade foi atualizada
        $total_ignoradas = count($resultados['ignoradas']);
        $total_erros = count($resultados['erros']);
        
        $mensagem = "⚠️ Nenhuma unidade foi atualizada no Firebird.";
        
        if ($total_ignoradas > 0) {
            $mensagem .= " $total_ignoradas unidade(s) ignoradas.";
        }
        if ($total_erros > 0) {
            $mensagem .= " $total_erros erro(s) encontrados.";
        }
        
        $response = [
            'success' => false,
            'message' => $mensagem,
            'codigo_cliente' => $codigo_cliente,
            'total_atualizadas_firebird' => 0,
            'finalizado_postgres' => false,
            'mensagem_postgres' => 'Nenhuma atualização no Firebird, crédito NÃO foi finalizado',
            'resultados' => $resultados,
            'resumo' => [
                'atualizadas_firebird' => 0,
                'ignoradas_sem_codic' => count(array_filter($resultados['ignoradas'], function($item) {
                    return strpos($item['motivo'], 'CODIC') !== false;
                })),
                'ignoradas_sem_cadastro' => count(array_filter($resultados['ignoradas'], function($item) {
                    return strpos($item['motivo'], 'cadastro') !== false;
                })),
                'erros_firebird' => count($resultados['erros'])
            ]
        ];
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

// Garantir que não há nada antes do JSON
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
?>