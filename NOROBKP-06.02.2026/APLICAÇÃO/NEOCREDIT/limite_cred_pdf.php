<?php
// gerar_pdf.php - Geração de PDF em página separada

// Configurações para DEBUG - vamos ver os erros
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

class PDFGenerator
{
    public static function gerarPDFConsulta($dados)
    {
        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('Sistema NOROAÇO');
            $pdf->SetAuthor('Sistema NOROAÇO');
            $pdf->SetTitle('Consulta de Limite de Crédito');
            $pdf->SetSubject('Relatório de Consulta');
            $pdf->SetKeywords('Limite, Crédito, Consulta, NOROAÇO');

            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pdf->AddPage();

            self::adicionarCabecalho($pdf, $dados);
            self::adicionarDadosNeocredit($pdf, $dados);
            self::adicionarDadosSIC($pdf, $dados);
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
        $pdf->Cell(180 - $margem_direita, 0, 'LIMITE DE CRÉDITO', 0, 1, 'R');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($margem_direita, 22);
        $pdf->SetTextColor(0, 0, 0);

        $razao_social = self::obterRazaoSocial($dados);
        if (strlen($razao_social) > 50) {
            $razao_social = substr($razao_social, 0, 47) . '...';
        }
        $pdf->Cell(180 - $margem_direita, 0, $razao_social, 0, 1, 'R');

        $pdf->SetLineWidth(0.8);
        $pdf->SetDrawColor(254, 192, 63);
        $pdf->Line(15, 35, 195, 35);
        $pdf->Ln(15);
    }

    private static function obterRazaoSocial($dados)
    {
        if (isset($dados['dados_api']['campos']['razao']) && !empty($dados['dados_api']['campos']['razao'])) {
            return $dados['dados_api']['campos']['razao'];
        }
        if (isset($dados['razao_social']) && !empty($dados['razao_social'])) {
            return $dados['razao_social'];
        }
        if (isset($dados['nome_empresa']) && !empty($dados['nome_empresa'])) {
            return $dados['nome_empresa'];
        }
        if (isset($dados['empresa']) && !empty($dados['empresa'])) {
            return $dados['empresa'];
        }
        if (isset($dados['nome']) && !empty($dados['nome'])) {
            return $dados['nome'];
        }

        if (isset($dados['documento_limpo']) && !empty($dados['documento_limpo'])) {
            return 'Documento: ' . $dados['documento_limpo'];
        }
        if (isset($dados['documento']) && !empty($dados['documento'])) {
            return 'Documento: ' . $dados['documento'];
        }

        return 'Consulta de Limite de Crédito';
    }

    private static function adicionarSeparador($pdf, $espacamento = 2)
    {
        $y = $pdf->GetY();
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(200, 200, 200); // Cinza claro
        $pdf->Line(10, $y + $espacamento, 200, $y + $espacamento);
        $pdf->SetY($y + ($espacamento * 2));
    }

    private static function adicionarDadosNeocredit($pdf, $dados)
    {
        $pdf->SetFillColor(102, 102, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'NEOCREDIT', 0, 1, 'L', true);

        if (isset($dados['dados_api']['campos'])) {
            $campos = $dados['dados_api']['campos'];

            $pdf->SetFillColor(248, 249, 250);
            $pdf->Rect(10, $pdf->GetY(), 190, 60, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(2);

            $razao = isset($campos['razao']) ? $campos['razao'] : 'N/A';
            $documento = isset($campos['documento']) ? self::formatarDocumento($campos['documento']) : 'N/A';
            $risco = isset($campos['risco']) ? $campos['risco'] : 'N/A';
            $classificacao_risco = isset($campos['classificacao_risco']) ? $campos['classificacao_risco'] : 'N/A';
            $limite_aprovado = isset($campos['limite_aprovado']) ? 'R$ ' . number_format($campos['limite_aprovado'], 2, ',', '.') : 'N/A';
            $data_validade = isset($campos['data_validade_limite_credito']) ? $campos['data_validade_limite_credito'] : 'N/A';
            $status = isset($campos['status']) ? $campos['status'] : 'N/A';
            $score = isset($campos['score']) ? $campos['score'] : 'N/A';

            $dados_exibir = [
                'Razão Social' => $razao,
                'Documento' => $documento,
                'Risco' => $risco,
                'Classificação de Risco' => $classificacao_risco,
                'Limite Aprovado' => $limite_aprovado,
                'Data Validade Limite' => $data_validade,
                'Status' => $status,
                'Score' => $score
            ];

            $primeiro = true;
            foreach ($dados_exibir as $label => $valor) {
                if (!$primeiro) {
                    self::adicionarSeparador($pdf, 1);
                }

                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(50, 5, $label . ':', 0, 0, 'L');
                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetTextColor(0, 0, 0);

                if ($label === 'Limite Aprovado' && $limite_aprovado !== 'N/A') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 100, 0); 
                } elseif ($label === 'Risco' && $risco !== 'N/A') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 0, 0); 
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
            $pdf->Cell(0, 10, 'Nenhum dado disponível da API Neocredit', 0, 1, 'C');
        }

        $pdf->Ln(10);
    }

    private static function adicionarDadosSIC($pdf, $dados)
    {
        $pdf->SetFillColor(102, 102, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'CODIC SIC', 0, 1, 'L', true);

        if (isset($dados['resultados_unidades']) && !empty($dados['resultados_unidades'])) {
            $unidades_encontradas = 0;
            $num_unidades = 0;

            foreach ($dados['resultados_unidades'] as $dados_unidade) {
                if ($dados_unidade['encontrado'] && !$dados_unidade['erro'] && $dados_unidade['codic']) {
                    $num_unidades++;
                }
            }

            $altura_caixa = max(40, $num_unidades * 10);

            $pdf->SetFillColor(248, 249, 250);
            $pdf->Rect(10, $pdf->GetY(), 190, $altura_caixa, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Ln(3);

            $primeira = true;
            foreach ($dados['resultados_unidades'] as $unidade => $dados_unidade) {
                if ($dados_unidade['encontrado'] && !$dados_unidade['erro'] && $dados_unidade['codic']) {
                    if (!$primeira) {
                        self::adicionarSeparador($pdf, 2);
                    }

                    $nome_unidade = self::getNomeUnidadeSIC($unidade);

                    $pdf->SetFont('helvetica', 'B', 8);
                    $pdf->Cell(50, 6, $nome_unidade . ':', 0, 0, 'L');
                    $pdf->SetFont('helvetica', '', 9);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell(0, 6, '' . $dados_unidade['codic'], 0, 1, 'L');

                    $unidades_encontradas++;
                    $primeira = false;
                }
            }

            if ($unidades_encontradas === 0) {
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Cell(0, 8, 'Nenhum cadastro encontrado nas unidades do SIC', 0, 1, 'C');
            }
        } else {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->Rect(15, $pdf->GetY(), 180, 15, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(0, 10, 'Nenhum dado disponível do Sistema SIC', 0, 1, 'C');
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


        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
    }

    private static function formatarDocumento($doc)
    {
        $num = preg_replace('/[^0-9]/', '', $doc);
        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        } elseif (strlen($num) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $num);
        }
        return $doc;
    }

    private static function getNomeUnidadeSIC($codigo_unidade)
    {
        $nomes_unidades = array(
            'BARRA_MANSA' => 'BARRA MANSA',
            'BOTUCATU' => 'BOTUCATU',
            'NOROACO' => 'VOTUPORANGA',
            'NOROMETAL' => 'LINS',
            'RIO_PRETO' => 'S. J. RIO PRETO'
        );

        return isset($nomes_unidades[$codigo_unidade])
            ? $nomes_unidades[$codigo_unidade]
            : $codigo_unidade;
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
    $pdf_content = PDFGenerator::gerarPDFConsulta($dados);

    // Nome do arquivo simples
    $filename = 'ANÁLISE PJ - NOROAÇO - ' . date('d-m-Y') . '.pdf';

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
    error_log("ERRO PDF: " . $e->getMessage());

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
                <a href="limite_cred.php" class="btn">Nova Consulta</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}
