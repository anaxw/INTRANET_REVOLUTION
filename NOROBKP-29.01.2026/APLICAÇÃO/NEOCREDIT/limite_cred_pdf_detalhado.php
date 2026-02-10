<?php
// gerar_pdf_pf.php - Geração de PDF para Pessoa Física

// Configurações para DEBUG
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 60);

// Verificar se TCPDF está disponível
function carregarTCPDF()
{
    // Tentar via Composer primeiro
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        return true;
    }

    // Tentar via include manual
    $tcpdf_paths = [
        __DIR__ . '/tcpdf/tcpdf.php',
        __DIR__ . '/TCPDF/tcpdf.php',
        __DIR__ . '/includes/tcpdf/tcpdf.php'
    ];

    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            return true;
        }
    }

    return false;
}

class PDFGeneratorPF
{
    public static function gerarPDFConsulta($dados)
    {
        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('Sistema NOROAÇO');
            $pdf->SetAuthor('Sistema NOROAÇO');
            $pdf->SetTitle('ANÁLISE PF - Consulta Completa');
            $pdf->SetSubject('Relatório de Análise PF');
            $pdf->SetKeywords('PF, Análise, Consulta, NOROAÇO');

            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pdf->AddPage();

            self::adicionarCabecalho($pdf, $dados);
            self::adicionarDadosBasicos($pdf, $dados);
            self::adicionarDadosRF($pdf, $dados);
            self::adicionarDadosSPC($pdf, $dados);
            self::adicionarDadosJudiciais($pdf, $dados);
            self::adicionarDadosTrabalhistas($pdf, $dados);
            self::adicionarRodape($pdf, $dados);

            return $pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro ao gerar PDF: " . $e->getMessage());
        }
    }

    private static function adicionarCabecalho($pdf, $dados)
    {
        $logo_path = __DIR__ . '/imgs/logo.png';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 15, 10, 30, 0, 'PNG');
        }

        $margem_direita = 15;

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY($margem_direita, 15);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(180 - $margem_direita, 0, 'ANÁLISE PF', 0, 1, 'R');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($margem_direita, 22);
        $pdf->SetTextColor(0, 0, 0);

        $nome = self::obterNome($dados);
        if (strlen($nome) > 50) {
            $nome = substr($nome, 0, 47) . '...';
        }
        $pdf->Cell(180 - $margem_direita, 0, $nome, 0, 1, 'R');

        $pdf->SetLineWidth(0.8);
        $pdf->SetDrawColor(254, 192, 63);
        $pdf->Line(15, 35, 195, 35);
        $pdf->Ln(15);
    }

    private static function obterNome($dados)
    {
        if (isset($dados['campos']['razao']) && !empty($dados['campos']['razao'])) {
            return $dados['campos']['razao'];
        }
        
        if (isset($dados['campos']['documento']) && !empty($dados['campos']['documento'])) {
            return 'Documento: ' . self::formatarCPF($dados['campos']['documento']);
        }
        
        return 'Consulta de Pessoa Física';
    }

    private static function adicionarDadosBasicos($pdf, $dados)
    {
        $pdf->SetFillColor(102, 102, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'DADOS BÁSICOS', 0, 1, 'L', true);

        if (isset($dados['campos'])) {
            $campos = $dados['campos'];

            $pdf->SetFillColor(248, 249, 250);
            $pdf->Rect(10, $pdf->GetY(), 190, 45, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(2);

            $razao = isset($campos['razao']) ? $campos['razao'] : 'N/A';
            $documento = isset($campos['documento']) ? self::formatarCPF($campos['documento']) : 'N/A';
            $score = isset($campos['score']) ? $campos['score'] : 'N/A';
            $risco = isset($campos['risco']) ? $campos['risco'] : 'N/A';
            $classificacao_risco = isset($campos['classificacao_risco']) ? $campos['classificacao_risco'] : 'N/A';
            $tipo_consulta = isset($campos['tipo_consulta']) ? $campos['tipo_consulta'] : 'N/A';
            $status = isset($campos['status']) ? $campos['status'] : 'N/A';

            $dados_exibir = [
                'Nome Completo' => $razao,
                'CPF' => $documento,
                'Score' => $score,
                'Risco' => $risco,
                'Classificação de Risco' => $classificacao_risco,
                'Tipo de Consulta' => $tipo_consulta,
                'Status' => $status
            ];

            $primeiro = true;
            foreach ($dados_exibir as $label => $valor) {
                if (!$primeiro) {
                    $pdf->Ln(2);
                }

                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(60, 5, $label . ':', 0, 0, 'L');
                $pdf->SetFont('helvetica', '', 9);
                
                // Destacar Score e Risco
                if ($label === 'Score' && $score !== 'N/A') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 100, 0);
                } elseif ($label === 'Risco' && $risco !== 'N/A') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    if ($risco == 'Baixo') {
                        $pdf->SetTextColor(0, 100, 0);
                    } elseif ($risco == 'Médio') {
                        $pdf->SetTextColor(255, 165, 0);
                    } else {
                        $pdf->SetTextColor(255, 0, 0);
                    }
                }

                $pdf->Cell(0, 5, $valor, 0, 1, 'L');

                // Resetar cor para próximo item
                $pdf->SetTextColor(0, 0, 0);
                $primeiro = false;
            }
        } else {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->Rect(15, $pdf->GetY(), 180, 15, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(0, 10, 'Nenhum dado básico disponível', 0, 1, 'C');
        }

        $pdf->Ln(10);
    }

    private static function adicionarDadosRF($pdf, $dados)
    {
        $pdf->SetFillColor(70, 130, 180);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'DADOS DA RECEITA FEDERAL', 0, 1, 'L', true);

        if (isset($dados['campos']['resultado_completo_rf'])) {
            try {
                $dados_rf = json_decode($dados['campos']['resultado_completo_rf'], true);
                
                $pdf->SetFillColor(230, 240, 255);
                $pdf->Rect(10, $pdf->GetY(), 190, 50, 'F');

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Ln(2);

                $nome = isset($dados_rf['Name']) ? $dados_rf['Name'] : 'N/A';
                $data_nascimento = isset($dados_rf['BirthDate']) ? date('d/m/Y', strtotime($dados_rf['BirthDate'])) : 'N/A';
                $mae = isset($dados_rf['MotherName']) ? $dados_rf['MotherName'] : 'N/A';
                $situacao_cpf = isset($dados_rf['TaxIdStatus']) ? $dados_rf['TaxIdStatus'] : 'N/A';
                $data_situacao = isset($dados_rf['TaxIdStatusDate']) ? date('d/m/Y', strtotime($dados_rf['TaxIdStatusDate'])) : 'N/A';

                $dados_exibir = [
                    'Nome na RF' => $nome,
                    'Data de Nascimento' => $data_nascimento,
                    'Nome da Mãe' => $mae,
                    'Situação CPF' => $situacao_cpf,
                    'Data da Situação' => $data_situacao
                ];

                foreach ($dados_exibir as $label => $valor) {
                    $pdf->SetFont('helvetica', 'B', 8);
                    $pdf->Cell(50, 5, $label . ':', 0, 0, 'L');
                    $pdf->SetFont('helvetica', '', 8);
                    
                    if ($label === 'Situação CPF') {
                        $pdf->SetFont('helvetica', 'B', 8);
                        if ($situacao_cpf == 'REGULAR') {
                            $pdf->SetTextColor(0, 100, 0);
                        } else {
                            $pdf->SetTextColor(255, 0, 0);
                        }
                    }
                    
                    $pdf->Cell(0, 5, $valor, 0, 1, 'L');
                    $pdf->SetTextColor(0, 0, 0);
                }

            } catch (Exception $e) {
                self::adicionarMensagemErro($pdf, 'Erro ao processar dados da Receita Federal');
            }
        } else {
            self::adicionarMensagemErro($pdf, 'Nenhum dado da Receita Federal disponível');
        }

        $pdf->Ln(10);
    }

    private static function adicionarDadosSPC($pdf, $dados)
    {
        $pdf->SetFillColor(178, 34, 34);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'DADOS SPC/SERASA', 0, 1, 'L', true);

        if (isset($dados['campos']['resultado_completo_spc'])) {
            try {
                $dados_spc = json_decode($dados['campos']['resultado_completo_spc'], true);
                
                $pdf->SetFillColor(255, 240, 240);
                $pdf->Rect(10, $pdf->GetY(), 190, 60, 'F');

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Ln(2);

                // Resumo SPC
                if (isset($dados_spc['spc']['resumo'])) {
                    $resumo = $dados_spc['spc']['resumo'];
                    
                    $valor_total = isset($resumo['valor_total']) ? 'R$ ' . number_format($resumo['valor_total'], 2, ',', '.') : 'R$ 0,00';
                    $quantidade_total = isset($resumo['quantidade_total']) ? $resumo['quantidade_total'] : '0';
                    $data_ultima = isset($resumo['data_ultima_ocorrencia']) ? date('d/m/Y', strtotime($resumo['data_ultima_ocorrencia'])) : 'N/A';

                    $dados_exibir = [
                        'Valor Total em Dívidas' => $valor_total,
                        'Quantidade de Ocorrências' => $quantidade_total,
                        'Data da Última Ocorrência' => $data_ultima,
                        'Possui Restrição' => isset($dados_spc['restricao']) && $dados_spc['restricao'] == 'true' ? 'SIM' : 'NÃO'
                    ];

                    foreach ($dados_exibir as $label => $valor) {
                        $pdf->SetFont('helvetica', 'B', 8);
                        $pdf->Cell(65, 5, $label . ':', 0, 0, 'L');
                        $pdf->SetFont('helvetica', '', 8);
                        
                        if ($label === 'Possui Restrição' && $valor === 'SIM') {
                            $pdf->SetFont('helvetica', 'B', 8);
                            $pdf->SetTextColor(255, 0, 0);
                        } elseif ($label === 'Valor Total em Dívidas' && $resumo['valor_total'] > 0) {
                            $pdf->SetFont('helvetica', 'B', 8);
                            $pdf->SetTextColor(255, 0, 0);
                        }
                        
                        $pdf->Cell(0, 5, $valor, 0, 1, 'L');
                        $pdf->SetTextColor(0, 0, 0);
                        $pdf->Ln(1);
                    }
                }

                // Detalhes das dívidas
                if (isset($dados_spc['spc']['detalhe_spc']) && !empty($dados_spc['spc']['detalhe_spc'])) {
                    $pdf->Ln(2);
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell(0, 5, 'Detalhamento das Dívidas:', 0, 1, 'L');
                    
                    foreach ($dados_spc['spc']['detalhe_spc'] as $index => $divida) {
                        $pdf->SetFont('helvetica', 'I', 8);
                        $pdf->Cell(0, 4, ($index + 1) . '. ' . $divida['nome_associado'] . 
                                     ' - R$ ' . number_format($divida['valor'], 2, ',', '.') . 
                                     ' - Vencimento: ' . date('d/m/Y', strtotime($divida['data_vencimento'])), 
                                     0, 1, 'L');
                    }
                }

            } catch (Exception $e) {
                self::adicionarMensagemErro($pdf, 'Erro ao processar dados do SPC/Serasa');
            }
        } else {
            self::adicionarMensagemErro($pdf, 'Nenhum dado do SPC/Serasa disponível');
        }

        $pdf->Ln(10);
    }

    private static function adicionarDadosJudiciais($pdf, $dados)
    {
        $pdf->SetFillColor(139, 0, 139);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'DADOS JUDICIAIS', 0, 1, 'L', true);

        if (isset($dados['campos']['resultado_completo_judicial'])) {
            try {
                $dados_judicial = json_decode($dados['campos']['resultado_completo_judicial'], true);
                
                $pdf->SetFillColor(245, 230, 245);
                $pdf->Rect(10, $pdf->GetY(), 190, 45, 'F');

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Ln(2);

                if (isset($dados_judicial['resumo'])) {
                    $resumo = $dados_judicial['resumo'];
                    
                    $total_processos = isset($resumo['processos']['total']) ? $resumo['processos']['total'] : 0;
                    $processos_ativos = isset($resumo['status']['ativos']) ? $resumo['status']['ativos'] : 0;
                    $valor_total = 0;
                    
                    // Calcular valor total dos processos
                    if (isset($dados_judicial['Lawsuits'])) {
                        foreach ($dados_judicial['Lawsuits'] as $processo) {
                            if (isset($processo['Value'])) {
                                $valor_total += $processo['Value'];
                            }
                        }
                    }

                    $dados_exibir = [
                        'Total de Processos' => $total_processos,
                        'Processos Ativos' => $processos_ativos,
                        'Valor Total em Processos' => 'R$ ' . number_format($valor_total, 2, ',', '.')
                    ];

                    foreach ($dados_exibir as $label => $valor) {
                        $pdf->SetFont('helvetica', 'B', 8);
                        $pdf->Cell(65, 5, $label . ':', 0, 0, 'L');
                        $pdf->SetFont('helvetica', '', 8);
                        
                        if (($label === 'Processos Ativos' && $processos_ativos > 0) || 
                            ($label === 'Valor Total em Processos' && $valor_total > 0)) {
                            $pdf->SetFont('helvetica', 'B', 8);
                            $pdf->SetTextColor(255, 0, 0);
                        }
                        
                        $pdf->Cell(0, 5, $valor, 0, 1, 'L');
                        $pdf->SetTextColor(0, 0, 0);
                        $pdf->Ln(1);
                    }

                    // Detalhe do processo ativo
                    if ($processos_ativos > 0 && isset($dados_judicial['processos_ativos'])) {
                        $pdf->Ln(2);
                        $pdf->SetFont('helvetica', 'B', 9);
                        $pdf->Cell(0, 5, 'Processo Ativo:', 0, 1, 'L');
                        
                        $processo = $dados_judicial['processos_ativos'][0];
                        $pdf->SetFont('helvetica', 'I', 8);
                        $pdf->Cell(0, 4, 'Número: ' . $processo['Number'], 0, 1, 'L');
                        $pdf->Cell(0, 4, 'Assunto: ' . $processo['MainSubject'], 0, 1, 'L');
                        $pdf->Cell(0, 4, 'Valor: R$ ' . number_format($processo['Value'], 2, ',', '.'), 0, 1, 'L');
                        $pdf->Cell(0, 4, 'Tribunal: ' . $processo['CourtDistrict'], 0, 1, 'L');
                    }
                }

            } catch (Exception $e) {
                self::adicionarMensagemErro($pdf, 'Erro ao processar dados judiciais');
            }
        } else {
            self::adicionarMensagemErro($pdf, 'Nenhum dado judicial disponível');
        }

        $pdf->Ln(10);
    }

    private static function adicionarDadosTrabalhistas($pdf, $dados)
    {
        $pdf->SetFillColor(46, 139, 87);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'DADOS TRABALHISTAS', 0, 1, 'L', true);

        if (isset($dados['campos']['resultado_completo_dp'])) {
            try {
                $dados_dp = json_decode($dados['campos']['resultado_completo_dp'], true);
                
                $pdf->SetFillColor(230, 245, 230);
                $pdf->Rect(10, $pdf->GetY(), 190, 40, 'F');

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Ln(2);

                $is_employed = isset($dados_dp['IsEmployed']) ? ($dados_dp['IsEmployed'] ? 'SIM' : 'NÃO') : 'N/A';
                $total_income = isset($dados_dp['TotalIncome']) ? 'R$ ' . number_format($dados_dp['TotalIncome'], 2, ',', '.') : 'N/A';
                $total_professions = isset($dados_dp['TotalProfessions']) ? $dados_dp['TotalProfessions'] : 'N/A';
                $active_professions = isset($dados_dp['TotalActiveProfessions']) ? $dados_dp['TotalActiveProfessions'] : 'N/A';

                $dados_exibir = [
                    'Empregado Atualmente' => $is_employed,
                    'Renda Total' => $total_income,
                    'Total de Profissões' => $total_professions,
                    'Profissões Ativas' => $active_professions
                ];

                foreach ($dados_exibir as $label => $valor) {
                    $pdf->SetFont('helvetica', 'B', 8);
                    $pdf->Cell(50, 5, $label . ':', 0, 0, 'L');
                    $pdf->SetFont('helvetica', '', 8);
                    
                    if ($label === 'Empregado Atualmente' && $valor === 'SIM') {
                        $pdf->SetFont('helvetica', 'B', 8);
                        $pdf->SetTextColor(0, 100, 0);
                    }
                    
                    $pdf->Cell(0, 5, $valor, 0, 1, 'L');
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Ln(1);
                }

            } catch (Exception $e) {
                self::adicionarMensagemErro($pdf, 'Erro ao processar dados trabalhistas');
            }
        } else {
            self::adicionarMensagemErro($pdf, 'Nenhum dado trabalhista disponível');
        }

        $pdf->Ln(10);
    }

    private static function adicionarRodape($pdf, $dados)
    {
        $pdf->SetY(-30);
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 4, 'Emissão: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

        if (isset($dados['id'])) {
            $pdf->Cell(0, 4, 'ID da Consulta: ' . $dados['id'], 0, 1, 'C');
        }

        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 4, 'Sistema NOROAÇO - Documento confidencial', 0, 1, 'C');
    }

    private static function formatarCPF($cpf)
    {
        $num = preg_replace('/[^0-9]/', '', $cpf);
        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        }
        return $cpf;
    }

    private static function adicionarMensagemErro($pdf, $mensagem)
    {
        $pdf->SetFillColor(255, 243, 205);
        $pdf->Rect(15, $pdf->GetY(), 180, 15, 'F');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 10, $mensagem, 0, 1, 'C');
        $pdf->Ln(5);
    }
}

// INÍCIO DA EXECUÇÃO
try {
    // Verificar se TCPDF está disponível
    if (!carregarTCPDF()) {
        throw new Exception("TCPDF não está instalado. Para instalar: composer require tecnickcom/tcpdf");
    }

    // Verificar se os dados foram fornecidos
    if (!isset($_GET['dados']) || empty($_GET['dados'])) {
        throw new Exception("Nenhum dado fornecido para gerar o PDF");
    }

    // Decodificar dados
    $dados_json = urldecode($_GET['dados']);
    $dados = json_decode($dados_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar dados: " . json_last_error_msg());
    }

    // Gerar PDF
    $pdf_content = PDFGeneratorPF::gerarPDFConsulta($dados);

    // Nome do arquivo
    $nome_pessoa = isset($dados['campos']['razao']) ? $dados['campos']['razao'] : 'Consulta';
    $filename = 'ANÁLISE PF - ' . $nome_pessoa . ' - ' . date('d-m-Y') . '.pdf';
    $filename = preg_replace('/[^a-zA-Z0-9\-\s]/', '', $filename);

    // Headers para download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf_content;
    exit;
} catch (Exception $e) {
    // Log do erro
    error_log("ERRO PDF PF: " . $e->getMessage());

    // Headers para HTML
    header('Content-Type: text/html; charset=utf-8');

    // Página de erro simplificada
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro ao Gerar PDF</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 50px;
                background: #f8f9fa;
            }

            .error {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }

            .error h1 {
                color: #dc3545;
            }

            .error-message {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }

            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin: 5px;
            }

            .btn:hover {
                background: #0056b3;
            }
        </style>
    </head>

    <body>
        <div class="error">
            <h1>Erro ao Gerar PDF</h1>
            <div class="error-message">
                <strong>Erro:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">Voltar</a>
                <a href="consulta_pf.php" class="btn">Nova Consulta</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}