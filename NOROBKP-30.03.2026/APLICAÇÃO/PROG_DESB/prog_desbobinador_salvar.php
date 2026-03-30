<?php
require_once 'conexoes/con_sic_noroaco.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Método não permitido');
}

if (ob_get_length()) ob_clean();

try {
    $erros = array();
    $registros_inseridos = array();
    $total_registros = 0;

    if (!isset($_POST['bobina_selecionada']) || empty($_POST['bobina_selecionada'])) {
        throw new Exception('Nenhuma bobina foi selecionada');
    }

    foreach ($_POST['bobina_selecionada'] as $index => $sequencialProdCarreg) {
        if (empty($sequencialProdCarreg)) continue;

        if (!isset($_POST['codigo_chapa'][$index]) || empty($_POST['codigo_chapa'][$index]) || $_POST['codigo_chapa'][$index] == '0') {
            $erros[] = "Código da chapa inválido para o índice: $index";
            continue;
        }

        $codigoChapa = $_POST['codigo_chapa'][$index];
        $quantidadeUtilizar = isset($_POST['qtde_bobina'][$index]) ? $_POST['qtde_bobina'][$index] : 0;
        $codigoBobina = isset($_POST['codigo_bobina'][$index]) ? $_POST['codigo_bobina'][$index] : '';
        $fornecedorBobina = isset($_POST['fornecedor_bobina'][$index]) ? $_POST['fornecedor_bobina'][$index] : '';
        $notaBobina = isset($_POST['nota_bobina'][$index]) ? $_POST['nota_bobina'][$index] : '';
        $etiquetaBobina = isset($_POST['etiqueta_bobina'][$index]) ? $_POST['etiqueta_bobina'][$index] : '';
        $certificadoBobina = isset($_POST['certificado_bobina'][$index]) ? $_POST['certificado_bobina'][$index] : '';
        $valorDemanda = isset($_POST['valor_digitado'][$index]) ? $_POST['valor_digitado'][$index] : 0;

        if ($quantidadeUtilizar <= 0) {
            $erros[] = "Quantidade inválida para a chapa $codigoChapa";
            continue;
        }

        $quantidadeUtilizar = str_replace(',', '.', $quantidadeUtilizar);
        $valorDemanda = str_replace(',', '.', $valorDemanda);

        // CORREÇÃO: Incluir o campo CERTIFICADO nas queries INSERT
        $sql_chapa = "INSERT INTO PROG_DESB_CHAPAS 
                (COD_PROD_BASE, TIPO, COD_PROD_VINC, QNTD_UTILIZADA, COD_FORN, SEQ_NOTA, SEQ_ETIQ, CERTIFICADO, USU_INSERIU, DT_HORA_INSERT) 
                VALUES ($codigoChapa, 'C', $codigoBobina, $valorDemanda, 
                        $fornecedorBobina, $notaBobina, $etiquetaBobina, 
                        " . ($certificadoBobina ? "'$certificadoBobina'" : "NULL") . ", 
                        'SISTEMA', CURRENT_TIMESTAMP)";
        
        $sql_bobina = "INSERT INTO PROG_DESB_CHAPAS 
                (COD_PROD_BASE, TIPO, COD_PROD_VINC, QNTD_UTILIZADA, COD_FORN, SEQ_NOTA, SEQ_ETIQ, CERTIFICADO, USU_INSERIU, DT_HORA_INSERT) 
                VALUES ($codigoBobina, 'B', $codigoChapa, $quantidadeUtilizar, 
                        $fornecedorBobina, $notaBobina, $etiquetaBobina, 
                        " . ($certificadoBobina ? "'$certificadoBobina'" : "NULL") . ",
                        'SISTEMA', CURRENT_TIMESTAMP)";
        
        $resultado_chapa = executa_insert($sql_chapa);
        $resultado_bobina = executa_insert($sql_bobina);
        
        if ($resultado_chapa && $resultado_bobina) {
            $total_registros += 2;
            $registros_inseridos[] = "Chapa: $codigoChapa | Bobina: $codigoBobina | Qtd: $quantidadeUtilizar kg | Certificado: " . ($certificadoBobina ?: 'N/A');
        } else {
            $erros[] = "Erro ao inserir para chapa $codigoChapa";
        }
    }

    if ($total_registros > 0) {
        $mensagem = "✅ DADOS INSERIDOS COM SUCESSO!\n\n";
        $mensagem .= "REGISTROS: $total_registros\n\n";
        foreach ($registros_inseridos as $registro) {
            $mensagem .= "• $registro\n";
        }
        echo $mensagem;
    } else {
        $mensagem = "❌ NENHUM REGISTRO INSERIDO.\n\n";
        foreach ($erros as $erro) {
            $mensagem .= "• $erro\n";
        }
        echo $mensagem;
    }

} catch (Exception $e) {
    echo "❌ ERRO GERAL: " . $e->getMessage();
}
?>