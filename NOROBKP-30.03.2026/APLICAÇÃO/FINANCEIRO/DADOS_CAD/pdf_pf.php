<?php
// ============================================================================
// pdf_pf.php - Gerador de PDF para Pessoa Física (APENAS DADOS DA API)
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);

// ============================================================================
// FUNÇÃO PARA CARREGAR TCPDF - VERSÃO CORRIGIDA
// ============================================================================
function carregarTCPDF()
{
    // Primeiro tenta carregar via Composer (autoload)
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';

        // Verifica se a classe TCPDF foi carregada
        if (class_exists('TCPDF')) {
            return true;
        }
    }

    // Lista de possíveis caminhos para o TCPDF
    $tcpdf_paths = [
        __DIR__ . '/tcpdf/tcpdf.php',
        __DIR__ . '/TCPDF/tcpdf.php',
        __DIR__ . '/includes/tcpdf/tcpdf.php',
        __DIR__ . '/lib/tcpdf/tcpdf.php',
        __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php'
    ];

    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);

            // Verifica se a classe foi carregada
            if (class_exists('TCPDF')) {
                return true;
            }
        }
    }

    return false;
}

// ============================================================================
// CARREGA TCPDF ANTES DE TUDO
// ============================================================================
if (!carregarTCPDF()) {
    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro - TCPDF não encontrado</title>
        <style>
            body {
                font-family: Arial;
                margin: 50px;
                background: #f8f9fa;
            }

            .error {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #dc3545;
            }

            .message {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }

            code {
                background: #f4f4f4;
                padding: 2px 5px;
                border-radius: 3px;
            }
        </style>
    </head>

    <body>
        <div class="error">
            <h1>TCPDF não encontrado</h1>
            <div class="message">
                <strong>Erro:</strong> A biblioteca TCPDF não foi encontrada no sistema.<br><br>
                <strong>Soluções:</strong>
                <ul>
                    <li>Execute: <code>composer require tecnickcom/tcpdf</code> na pasta do projeto</li>
                    <li>Ou baixe manualmente de: <a href="https://github.com/tecnickcom/tcpdf" target="_blank">https://github.com/tecnickcom/tcpdf</a></li>
                </ul>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">← Voltar</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}

// ============================================================================
// CLASSE PDF PERSONALIZADA COM RODAPÉ EM TODAS AS PÁGINAS
// ============================================================================
class PDFSimplificadoVertical extends TCPDF
{
    /**
     * Personaliza o rodapé para aparecer em todas as páginas
     */
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetY($this->GetY() + 2);
        $this->Cell(0, 5, '' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 5, 'Emissão: ' . date('d/m/Y H:i:s'), 0, 0, 'L');
    }
}

// ============================================================================
// CLASSE GERADORA DO PDF SIMPLIFICADO PARA PESSOA FÍSICA
// ============================================================================
class PDFPFGenerator
{
    private static $pdf;
    private static $dados;
    private static $y_position = 0;

    // Altura estimada para cada card
    private static $alturasCard = [
        'cabecalho' => 30,
        'dados_api' => 70
    ];

    // Constantes para layout
    const LARGURA_ROTULO = 60;
    const LARGURA_VALOR = 120;
    const MARGEM_ESQUERDA = 15;
    const MARGEM_DIREITA = 15;
    const LARGURA_TOTAL = 180;
    const ALTURA_LINHA_PADRAO = 6;

    /**
     * VERIFICA SE O CARD INTEIRO CABE NA PÁGINA ATUAL
     */
    private static function verificarCard($tipoCard)
    {
        $alturaNecessaria = isset(self::$alturasCard[$tipoCard]) ? self::$alturasCard[$tipoCard] : 50;
        $posicaoAtual = self::$pdf->GetY();
        $alturaPagina = self::$pdf->getPageHeight();
        $margemInferior = 45;

        if ($posicaoAtual + $alturaNecessaria > $alturaPagina - $margemInferior) {
            self::$pdf->AddPage();
            self::$y_position = 20;
            self::$pdf->SetY(self::$y_position);
            return false;
        }

        self::$y_position = $posicaoAtual;
        return true;
    }

    /**
     * EXTRAI VALOR SEGURO OU RETORNA TRAÇO (-)
     */
    private static function getValueOrDash($data, $keys, $default = '-')
    {
        if (!is_array($data)) {
            return $default;
        }

        if (is_string($keys)) {
            return isset($data[$keys]) && $data[$keys] !== '' && $data[$keys] !== null
                ? $data[$keys]
                : $default;
        }

        $current = $data;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || $current[$key] === '' || $current[$key] === null) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * FORMATA DATA
     */
    private static function formatarData($dataStr)
    {
        if (!$dataStr || $dataStr === '-' || $dataStr === 'null' || $dataStr === '' || $dataStr === 'Não informado') {
            return '-';
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dataStr)) {
                $date = new DateTime($dataStr);
                return $date->format('d/m/Y');
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dataStr)) {
                return $dataStr;
            }
        } catch (Exception $e) {
            return '-';
        }

        return $dataStr;
    }

    /**
     * FORMATA CPF
     */
    private static function formatarCPF($cpf)
    {
        if (!$cpf || $cpf === '-' || $cpf === 'Não informado') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $cpf);
        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        }
        return $cpf;
    }

    /**
     * ESCREVE LINHA COM LABEL FIXO
     */
    private static function escreverLinhaComLabelFixo($rotulo, $valor, $destaque = false)
    {
        $x_inicial = self::$pdf->GetX();
        $y_inicial = self::$pdf->GetY();

        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 25;

        if ($espaco_restante < self::ALTURA_LINHA_PADRAO) {
            self::$pdf->AddPage();
            $y_inicial = self::$pdf->GetY();
            self::$y_position = $y_inicial;
        }

        self::$pdf->SetFont('helvetica', $destaque ? 'B' : '', 9);
        $valorStr = is_array($valor) ? '-' : (string)$valor;
        if ($valorStr === '' || $valorStr === null) {
            $valorStr = '-';
        }

        // Calcular número de linhas necessárias
        $largura_texto = self::$pdf->GetStringWidth($valorStr);
        $numero_linhas = 1;

        if ($largura_texto > self::LARGURA_VALOR) {
            $palavras = explode(' ', $valorStr);
            $linha_teste = '';
            $numero_linhas = 1;

            foreach ($palavras as $palavra) {
                $teste_linha = $linha_teste ? $linha_teste . ' ' . $palavra : $palavra;
                if (self::$pdf->GetStringWidth($teste_linha) <= self::LARGURA_VALOR) {
                    $linha_teste = $teste_linha;
                } else {
                    $numero_linhas++;
                    $linha_teste = $palavra;
                }
            }
        }

        $altura_total = $numero_linhas * self::ALTURA_LINHA_PADRAO;

        // Desenhar rótulo
        self::$pdf->SetFont('helvetica', 'B', 9);
        self::$pdf->SetXY($x_inicial, $y_inicial);
        self::$pdf->Cell(
            self::LARGURA_ROTULO,
            $altura_total,
            $rotulo . ':',
            0,
            0,
            'L',
            false,
            '',
            0,
            false,
            'T',
            'T'
        );

        // Desenhar valor
        self::$pdf->SetFont('helvetica', $destaque ? 'B' : '', 9);
        if ($destaque) {
            self::$pdf->SetTextColor(0, 100, 0);
        }

        self::$pdf->SetXY($x_inicial + self::LARGURA_ROTULO, $y_inicial);
        self::$pdf->MultiCell(
            self::LARGURA_VALOR,
            self::ALTURA_LINHA_PADRAO,
            $valorStr,
            0,
            'L',
            false,
            1,
            '',
            '',
            true,
            0,
            false,
            true,
            0,
            'T'
        );

        self::$pdf->SetTextColor(0, 0, 0);
        self::$y_position = $y_inicial + $altura_total;
        self::$pdf->SetY(self::$y_position);

        return $altura_total;
    }

    /**
     * CRIA CARD COM TÍTULO
     */
    private static function criarCard($titulo, $corFundo = [59, 89, 152], $tipoCard = 'dados_api')
    {
        self::verificarCard($tipoCard);

        self::$pdf->SetFillColor($corFundo[0], $corFundo[1], $corFundo[2]);
        self::$pdf->SetTextColor(255, 255, 255);
        self::$pdf->SetFont('helvetica', 'B', 12);

        self::$pdf->SetY(self::$y_position);
        self::$pdf->Cell(0, 8, '  ' . $titulo, 0, 1, 'L', true);

        self::$y_position = self::$pdf->GetY();
        self::$pdf->SetTextColor(0, 0, 0);
        self::$pdf->SetFont('helvetica', '', 9);

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * FINALIZA CARD COM ESPAÇAMENTO
     */
    private static function finalizarCard()
    {
        self::$y_position += 5;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA CARD CABEÇALHO
     */
    private static function adicionarCardCabecalho()
    {
        self::verificarCard('cabecalho');

        $logo_path = __DIR__ . '/imgs/noroaco.png';
        if (file_exists($logo_path)) {
            self::$pdf->Image($logo_path, 15, 14, 12, 0, 'PNG');
        }

        self::$pdf->SetY(15);
        self::$pdf->SetFont('helvetica', 'B', 20);
        self::$pdf->SetTextColor(253, 181, 37);
        self::$pdf->Cell(0, 10, 'RELATÓRIO PESSOA FÍSICA', 0, 1, 'R');

        self::$pdf->SetFont('helvetica', 'B', 14);
        self::$pdf->SetTextColor(100, 100, 100);

        $nome = self::getValueOrDash(self::$dados, 'nome_completo', 'Não informado');
        self::$pdf->Cell(0, 6, $nome, 0, 1, 'R');

        self::$pdf->SetLineWidth(1);
        self::$pdf->SetDrawColor(253, 181, 37);
        self::$pdf->Line(15, 37, 195, 37);

        self::$y_position = 45;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA CARD COM OS DADOS DA API (APENAS O QUE APARECE NA TELA)
     */
    private static function adicionarCardDadosAPI()
    {
        self::criarCard('DADOS DA CONSULTA', [41, 128, 185], 'dados_api');

        // Coletar apenas as informações que aparecem na tela
        $nome = self::getValueOrDash(self::$dados, 'nome_completo', 'Não informado');
        $nomeMae = self::getValueOrDash(self::$dados, 'nome_mae', 'Não informado');
        $dataNascimento = self::formatarData(self::getValueOrDash(self::$dados, 'data_nascimento', 'Não informado'));
        $idade = self::getValueOrDash(self::$dados, 'idade', 'Não informado');
        $cpf = self::formatarCPF(self::getValueOrDash(self::$dados, 'cpf', 'Não informado'));
        $statusCPF = self::getValueOrDash(self::$dados, 'status_cpf', 'Não informado');

        // Exibir APENAS as informações que aparecem na tela
        self::escreverLinhaComLabelFixo('Nome Completo', $nome, true);
        self::escreverLinhaComLabelFixo('Nome da Mãe', $nomeMae);
        self::escreverLinhaComLabelFixo('Data de Nascimento', $dataNascimento);
        self::escreverLinhaComLabelFixo('Idade', $idade);
        self::escreverLinhaComLabelFixo('CPF', $cpf, true);
        self::finalizarCard();
    }

    /**
     * MÉTODO PRINCIPAL - GERA O PDF
     */
    public static function gerarPDF($dados)
    {
        try {
            self::$dados = $dados;

            // Usar a classe personalizada com rodapé automático
            self::$pdf = new PDFSimplificadoVertical('P', 'mm', 'A4', true, 'UTF-8', false);

            self::$pdf->SetCreator('NOROAÇO');
            self::$pdf->SetAuthor('NOROAÇO - Sistema de Crédito');
            self::$pdf->SetTitle('Relatório Pessoa Física - Dados da API');
            self::$pdf->SetSubject('Dados da Consulta de Pessoa Física');

            self::$pdf->setPrintHeader(false);
            self::$pdf->setPrintFooter(true);

            self::$pdf->SetMargins(15, 15, 15);
            self::$pdf->SetAutoPageBreak(true, 30);

            self::$pdf->AddPage();

            // Gerar apenas os cards necessários
            self::adicionarCardCabecalho();
            self::adicionarCardDadosAPI();

            return self::$pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro na geração do PDF: " . $e->getMessage());
        }
    }
}

// ============================================================================
// EXECUÇÃO PRINCIPAL
// ============================================================================

try {
    // Obter dados
    $dados_json = '';

    if (isset($_POST['dados']) && !empty($_POST['dados'])) {
        $dados_json = $_POST['dados'];
    } elseif (isset($_GET['dados']) && !empty($_GET['dados'])) {
        $dados_json = $_GET['dados'];
    } else {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $dados_json = $input;
        }
    }

    if (empty($dados_json)) {
        throw new Exception("Nenhum dado fornecido para gerar o PDF");
    }

    // Decodificar
    if (strpos($dados_json, '%') !== false) {
        $dados_json = urldecode($dados_json);
    }

    $dados = json_decode($dados_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
    }

    // Verificar se é PF
    $tipoDocumento = isset($dados['tipo_documento']) ? $dados['tipo_documento'] : 'CPF';
    if ($tipoDocumento !== 'CPF') {
        throw new Exception("Este relatório é específico para Pessoa Física (CPF)");
    }

    // Gerar PDF
    $pdf_content = PDFPFGenerator::gerarPDF($dados);

    // Nome do arquivo
    $nome = isset($dados['nome_completo']) ?
        preg_replace('/[^a-zA-Z0-9]/', '_', substr($dados['nome_completo'], 0, 30)) : 'PESSOA_FISICA';
    $cpf = isset($dados['cpf']) ? preg_replace('/[^0-9]/', '', substr($dados['cpf'], -4)) : 'XXXX';
    $filename = 'Dados_PF_' . $nome . '_' . $cpf . '_' . date('Ymd') . '.pdf';

    // Headers
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf_content;
    exit;
} catch (Exception $e) {
    error_log("ERRO PDF PF: " . $e->getMessage());

    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro no PDF - Pessoa Física</title>
        <style>
            body {
                font-family: Arial;
                margin: 50px;
                background: #f8f9fa;
            }

            .error {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #dc3545;
            }

            .message {
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
            <h1>Erro ao Gerar PDF - Pessoa Física</h1>
            <div class="message">
                <strong>Erro:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">← Voltar</a>
                <a href="javascript:window.close()" class="btn">Fechar</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}
?>