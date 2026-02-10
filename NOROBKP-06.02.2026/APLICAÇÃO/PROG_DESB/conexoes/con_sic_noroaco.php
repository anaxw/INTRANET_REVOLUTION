<?php
require_once 'conexoes/con_sic_noroaco.php';

// Iniciar sessão para mensagens
session_start();

// Função para log de erros
function log_error($message, $sql = '') {
    $log_file = 'logs/error_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";
    if ($sql) {
        $log_message .= "\nSQL: $sql";
    }
    $log_message .= "\n" . str_repeat('-', 80) . "\n";
    
    // Garantir que o diretório de logs existe
    if (!file_exists('logs')) {
        mkdir('logs', 0777, true);
    }
    
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

try {
    // Verificar se temos dados POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método de requisição inválido');
    }

    // Coletar dados do formulário
    $valor_digitado = $_POST['valor_digitado'] ?? [];
    $estoque_bloco2 = $_POST['estoque_bloco2'] ?? [];
    $producao_interna_un = $_POST['producao_interna_un'] ?? [];
    $pedidos_compra_un = $_POST['pedidos_compra_un'] ?? [];
    $solic_compra_un = $_POST['solic_compra_un'] ?? [];
    $codigo_chapa = $_POST['codigo_chapa'] ?? [];
    
    // Dados das bobinas
    $bobina_selecionada = $_POST['bobina_selecionada'] ?? [];
    $qtde_bobina = $_POST['qtde_bobina'] ?? [];
    $codigo_bobina = $_POST['codigo_bobina'] ?? [];
    $fornecedor_bobina = $_POST['fornecedor_bobina'] ?? [];
    $nota_bobina = $_POST['nota_bobina'] ?? [];
    $etiqueta_bobina = $_POST['etiqueta_bobina'] ?? [];
    $certificado_bobina = $_POST['certificado_bobina'] ?? [];
    $descricao_bobina = $_POST['descricao_bobina'] ?? [];

    // Inicializar contadores
    $registros_salvos = 0;
    $registros_erro = 0;
    $registros_processados = 0;
    $log_mensagens = [];

    // Processar cada linha
    foreach ($valor_digitado as $index => $demanda) {
        $demanda = str_replace(',', '.', $demanda);
        $demanda_numerica = floatval($demanda);
        
        // Só processar se houver demanda e bobina selecionada
        if ($demanda_numerica > 0 && isset($bobina_selecionada[$index]) && !empty($bobina_selecionada[$index])) {
            $registros_processados++;
            
            try {
                // Preparar dados
                $cod_chapa = $codigo_chapa[$index] ?? null;
                $seq_bobina = $bobina_selecionada[$index];
                $qtde_utilizada = floatval(str_replace(',', '.', $qtde_bobina[$index] ?? 0));
                $cod_bobina = $codigo_bobina[$index] ?? null;
                $cod_fornecedor = $fornecedor_bobina[$index] ?? null;
                $seq_nota = $nota_bobina[$index] ?? null;
                $seq_etiqueta = $etiqueta_bobina[$index] ?? null;
                $certificado = $certificado_bobina[$index] ?? '';
                $desc_bobina = $descricao_bobina[$index] ?? '';
                
                // Dados da chapa
                $estoque_bloco = floatval(str_replace(',', '.', $estoque_bloco2[$index] ?? 0));
                $producao_interna = floatval(str_replace(',', '.', $producao_interna_un[$index] ?? 0));
                $pedidos_compra = floatval(str_replace(',', '.', $pedidos_compra_un[$index] ?? 0));
                $solic_compra = floatval(str_replace(',', '.', $solic_compra_un[$index] ?? 0));
                
                // Construir SQL para inserção
                $sql = "INSERT INTO prog_desb_chapas (
                    cod_prod_base, cod_prod_vinc, qntd_utilizada, 
                    seq_carga_prod, cod_forn, seq_nota, seq_etiq, 
                    certificado, tipo, estoque_bloco, prod_interna, 
                    pedidos_compra, solicit_compra, data_inclusao,
                    descricao_bobina
                ) VALUES (
                    :cod_prod_base, :cod_prod_vinc, :qntd_utilizada, 
                    :seq_carga_prod, :cod_forn, :seq_nota, :seq_etiq, 
                    :certificado, 'C', :estoque_bloco, :prod_interna, 
                    :pedidos_compra, :solicit_compra, CURRENT_TIMESTAMP,
                    :descricao_bobina
                )";
                
                // Parâmetros
                $params = [
                    ':cod_prod_base' => $cod_chapa,
                    ':cod_prod_vinc' => $cod_bobina,
                    ':qntd_utilizada' => $qtde_utilizada,
                    ':seq_carga_prod' => $seq_bobina,
                    ':cod_forn' => $cod_fornecedor,
                    ':seq_nota' => $seq_nota,
                    ':seq_etiq' => $seq_etiqueta,
                    ':certificado' => $certificado,
                    ':estoque_bloco' => $estoque_bloco,
                    ':prod_interna' => $producao_interna,
                    ':pedidos_compra' => $pedidos_compra,
                    ':solicit_compra' => $solic_compra,
                    ':descricao_bobina' => $desc_bobina
                ];
                
                // Executar query preparada
                $stmt = executa_query_preparada($sql, $params);
                
                if ($stmt) {
                    $registros_salvos++;
                    $log_mensagens[] = "✅ Linha $index: Chapa $cod_chapa + Bobina $cod_bobina - $qtde_utilizada kg";
                } else {
                    $registros_erro++;
                    $log_mensagens[] = "❌ Linha $index: Erro ao salvar";
                }
                
            } catch (Exception $e) {
                $registros_erro++;
                $log_mensagens[] = "❌ Linha $index: " . $e->getMessage();
                log_error("Erro na linha $index: " . $e->getMessage());
            }
        }
    }
    
    // Montar mensagem de resultado
    $mensagem_final = "📊 RESULTADO DO PROCESSAMENTO\n";
    $mensagem_final .= str_repeat('=', 50) . "\n";
    $mensagem_final .= "✅ Registros processados: $registros_processados\n";
    $mensagem_final .= "💾 Registros salvos com sucesso: $registros_salvos\n";
    $mensagem_final .= "❌ Registros com erro: $registros_erro\n";
    $mensagem_final .= str_repeat('-', 50) . "\n";
    
    if (!empty($log_mensagens)) {
        $mensagem_final .= "📋 DETALHES:\n";
        foreach ($log_mensagens as $log) {
            $mensagem_final .= $log . "\n";
        }
    }
    
    if ($registros_processados === 0) {
        $mensagem_final .= "\n⚠️ Nenhum registro foi processado. Verifique se:\n";
        $mensagem_final .= "   1. Há demandas preenchidas (> 0)\n";
        $mensagem_final .= "   2. Bobinas foram selecionadas\n";
        $mensagem_final .= "   3. Campos obrigatórios estão preenchidos";
    }
    
    echo $mensagem_final;
    
} catch (Exception $e) {
    $error_message = "❌ ERRO CRÍTICO NO SISTEMA\n";
    $error_message .= "Mensagem: " . $e->getMessage() . "\n";
    $error_message .= "Arquivo: " . $e->getFile() . "\n";
    $error_message .= "Linha: " . $e->getLine() . "\n";
    
    log_error($error_message);
    echo $error_message;
}
?>