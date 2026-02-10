<?php
// gerar_pdf.php - Geração de PDF em página separada - VERSÃO ATUALIZADA

// Configurações para DEBUG
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 60);

// Corrigir sintaxe obsoleta do TCPDF se necessário
function corrigirTCPDFAntesDeCarregar()
{
    $tcpdf_paths = [
        __DIR__ . '/tcpdf/tcpdf.php',
        __DIR__ . '/TCPDF/tcpdf.php',
        __DIR__ . '/includes/tcpdf/tcpdf.php'
    ];

    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            // Ler o conteúdo
            $conteudo = file_get_contents($path);

            // Substituir todas as ocorrências de sintaxe { } para acesso a strings
            $padroes_corrigir = [
                '/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\{([^}]+)\}/' => '\$$1[$2]',
                '/\{\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\}/' => '$\1',
            ];

            foreach ($padroes_corrigir as $padrao => $substituicao) {
                $conteudo = preg_replace($padrao, $substituicao, $conteudo);
            }

            // Salvar o arquivo corrigido
            file_put_contents($path, $conteudo);
            return true;
        }
    }
    return false;
}

// Verificar se TCPDF está disponível
function carregarTCPDF()
{
    // Tentar via Composer primeiro
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        return true;
    }

    // Corrigir sintaxe obsoleta antes de incluir
    corrigirTCPDFAntesDeCarregar();

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
            // Verificar e criar TCPDF se necessário
            if (!class_exists('TCPDF')) {
                if (!carregarTCPDF()) {
                    throw new Exception("TCPDF não está instalado. Para instalar: composer require tecnickcom/tcpdf");
                }
            }

            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('Sistema NOROAÇO');
            $pdf->SetAuthor('Sistema NOROAÇO');
            $pdf->SetSubject('Relatório de Consulta');
            $pdf->SetKeywords('Limite, Crédito, Consulta, NOROAÇO');

            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pdf->AddPage();

            self::adicionarCabecalho($pdf, $dados);
            self::adicionarDadosNeocredit($pdf, $dados);
            self::adicionarRodape($pdf, $dados);

            return $pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro ao gerar PDF: " . $e->getMessage());
        }
    }

    private static function adicionarCabecalho($pdf, $dados)
    {
        // Adicionar logo no canto esquerdo
        $logo_path = __DIR__ . '/imgs/logo.png';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 12, 12, 40, 0, 'PNG');
        }
        
        // Adicionar título no canto direito (sem razão social)
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(15, 15);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(180, 0, 'LIMITE DE CRÉDITO', 0, 1, 'R');
        
        // Linha separadora amarela
        $pdf->SetLineWidth(0.8);
        $pdf->SetDrawColor(254, 192, 63);
        $pdf->Line(15, 30, 195, 30);
        
        // Espaço após o cabeçalho
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
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(10, $y + $espacamento, 200, $y + $espacamento);
        $pdf->SetY($y + ($espacamento * 2));
    }

    private static function adicionarDadosNeocredit($pdf, $dados)
    {
        $pdf->SetFillColor(102, 102, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'NEOCREDIT', 0, 1, 'L', true);

        if (isset($dados['dados_api']['campos']) && !empty($dados['dados_api']['campos'])) {
            $campos = $dados['dados_api']['campos'];

            $pdf->SetFillColor(248, 249, 250);
            $pdf->Rect(10, $pdf->GetY(), 190, 60, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(2);

            $razao = isset($campos['razao']) && !empty($campos['razao']) ? $campos['razao'] : '-';
            $documento = isset($campos['documento']) && !empty($campos['documento']) ? self::formatarDocumento($campos['documento']) : '-';
            $risco = isset($campos['risco']) && !empty($campos['risco']) ? $campos['risco'] : '-';
            $classificacao_risco = isset($campos['classificacao_risco']) && !empty($campos['classificacao_risco']) ? $campos['classificacao_risco'] : '-';
            $limite_aprovado = isset($campos['limite_aprovado']) && !empty($campos['limite_aprovado']) ? 'R$ ' . number_format($campos['limite_aprovado'], 2, ',', '.') : '-';
            $data_validade = isset($campos['data_validade_limite_credito']) && !empty($campos['data_validade_limite_credito']) ? $campos['data_validade_limite_credito'] : '-';
            $status = isset($campos['status']) && !empty($campos['status']) ? $campos['status'] : '-';
            $score = isset($campos['score']) && !empty($campos['score']) ? $campos['score'] : '-';

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

                if ($label === 'Limite Aprovado' && $limite_aprovado !== '-') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 100, 0);
                } elseif ($label === 'Risco' && $risco !== '-') {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetTextColor(0, 0, 0);
                }

                $pdf->Cell(0, 5, $valor, 0, 1, 'L');

                $pdf->SetTextColor(0, 0, 0);
                $primeiro = false;
            }
        } else {
            $pdf->SetFillColor(248, 249, 250);
            $pdf->Rect(10, $pdf->GetY(), 190, 60, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(2);

            $dados_exibir = [
                'Razão Social' => '-',
                'Documento' => '-',
                'Risco' => '-',
                'Classificação de Risco' => '-',
                'Limite Aprovado' => '-',
                'Data Validade Limite' => '-',
                'Status' => '-',
                'Score' => '-'
            ];

            $primeiro = true;
            foreach ($dados_exibir as $label => $valor) {
                if (!$primeiro) {
                    self::adicionarSeparador($pdf, 1);
                }

                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(50, 5, $label . ':', 0, 0, 'L');
                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->Cell(0, 5, $valor, 0, 1, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $primeiro = false;
            }
        }

        $pdf->Ln(30);
    }


    private static function adicionarRodape($pdf, $dados)
    {
        $y_inicial = $pdf->GetY();

        $pdf->SetY(-30);

        if ($pdf->GetY() < $y_inicial) {
            $pdf->SetY($y_inicial);

            $espaco_disponivel = $pdf->getPageHeight() - $y_inicial - 35;
            if ($espaco_disponivel < 20) {
                $pdf->Ln(5);
                $pdf->SetLineWidth(0.5);
                $pdf->SetDrawColor(200, 200, 200);
                $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
                $pdf->Ln(8);
            }
        }

        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 4, 'Emissão: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 4, 'Sistema NOROAÇO - Consulta de Limite de Crédito', 0, 1, 'C');
    }

    private static function formatarDocumento($doc)
    {
        if (empty($doc)) return '-';

        $num = preg_replace('/[^0-9]/', '', $doc);
        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        } elseif (strlen($num) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $num);
        }
        return $doc;
    }
}

// INÍCIO DA EXECUÇÃO
try {
    // Verificar se TCPDF está disponível
    if (!carregarTCPDF()) {
        throw new Exception("TCPDF não está instalado. Para instalar: composer require tecnickcom/tcpdf");
    }

    // Verificar se os dados foram fornecidos via GET ou POST
    $dados_json = '';

    // Se temos dados JSON, decodificar
    if (!empty($dados_json)) {
        $dados = json_decode($dados_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Se erro na decodificação, criar dados padrão
            $dados = [
                'documento_limpo' => 'ERRO NA DECODIFICAÇÃO',
                'razao_social' => 'DADOS INVÁLIDOS',
                'dados_api' => [
                    'campos' => []
                ],
                'resultados_unidades' => []
            ];
        }
    }

    // Certificar-se que temos a estrutura mínima
    if (!isset($dados['dados_api'])) {
        $dados['dados_api'] = ['campos' => []];
    }
    if (!isset($dados['resultados_unidades'])) {
        $dados['resultados_unidades'] = [];
    }

    // Gerar PDF
    $pdf_content = PDFGenerator::gerarPDFConsulta($dados);

    // Nome do arquivo
    $razao = '';
    if (isset($dados['dados_api']['campos']['razao']) && !empty($dados['dados_api']['campos']['razao'])) {
        $razao = $dados['dados_api']['campos']['razao'];
    } elseif (isset($dados['razao_social']) && !empty($dados['razao_social'])) {
        $razao = $dados['razao_social'];
    }

    $filename = 'ANÁLISE PJ - NOROAÇO';
    if (!empty($razao)) {
        // Limitar tamanho do nome
        $razao_limpa = preg_replace('/[^a-zA-Z0-9]/', '_', substr($razao, 0, 30));
        $filename .= ' - ' . $razao_limpa;
    }
    $filename .= ' - ' . date('d-m-Y') . '.pdf';

    // Headers para download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

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
        <meta charset="utf-8">
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
            <div style="margin-top: 20px; font-size: 12px; color: #666;">
                <p><strong>Informações do erro:</strong></p>
                <ul>
                    <li>Data/Hora: <?php echo date('d/m/Y H:i:s'); ?></li>
                    <li>PHP Version: <?php echo phpversion(); ?></li>
                    <li>TCPDF: <?php echo class_exists('TCPDF') ? 'Carregado' : 'Não carregado'; ?></li>
                </ul>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}