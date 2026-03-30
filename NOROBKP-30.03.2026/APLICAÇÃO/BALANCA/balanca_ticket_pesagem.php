<?php
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

require_once('tcpdf/tcpdf.php');

$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_GET['codigo'])) {
    die("Código da pesagem não informado!");
}

$codigo = $_GET['codigo'];

try {
    $sql = "SELECT * FROM pesagem WHERE codigo = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigo]);
    $pesagem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pesagem) {
        die("Pesagem não encontrada!");
    }

    $dataInicialFormatada = '';
    $dataFinalFormatada = '';

    if (!empty($pesagem['data_hora_inicial']) && $pesagem['data_hora_inicial'] !== '0000-00-00 00:00:00') {
        $dataInicialFormatada = date('d/m/Y H:i', strtotime($pesagem['data_hora_inicial']));
    }

    if (!empty($pesagem['data_hora_final']) && $pesagem['data_hora_final'] !== '0000-00-00 00:00:00') {
        $dataFinalFormatada = date('d/m/Y H:i', strtotime($pesagem['data_hora_final']));
    }

    $pesoInicialFormatado = !empty($pesagem['peso_inicial']) ? number_format((float)$pesagem['peso_inicial'], 3, ',', '.') : '0,000';
    $pesoFinalFormatado = !empty($pesagem['peso_final']) ? number_format((float)$pesagem['peso_final'], 3, ',', '.') : '0,000';
    $pesoLiquidoFormatado = !empty($pesagem['peso_liquido']) ? number_format((float)$pesagem['peso_liquido'], 3, ',', '.') : '0,000';

    class MEU_PDF_UMA_PAGINA extends TCPDF
    {
        private $logo_config = [
            'file' => 'imgs/logo.png',
            'width' => 30,
            'height' => 12,
            'x' => 15,
            'y' => 8
        ];

        public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false)
        {
            if ($this->page > 0) {
                return;
            }
            parent::AddPage($orientation, $format, $keepmargins, $tocpage);
        }

        public function checkPageBreak($h = 0, $y = '', $addpage = true, $orphans = 0, $keepmargins = false)
        {
            return false;
        }

        public function Header()
        {
            // Logo
            if (file_exists($this->logo_config['file'])) {
                try {
                    $this->Image(
                        $this->logo_config['file'],
                        $this->logo_config['x'],
                        $this->logo_config['y'],
                        $this->logo_config['width'],
                        $this->logo_config['height'],
                        '',
                        '',
                        'T',
                        false,
                        300,
                        '',
                        false,
                        false,
                        0,
                        false,
                        false,
                        false
                    );
                } catch (Exception $e) {
                    // Fallback para texto se a imagem não carregar
                    $this->SetFont('helvetica', 'B', 12);
                    $this->SetXY($this->logo_config['x'], $this->logo_config['y']);
                    $this->Cell(30, 10, 'NOROAÇO', 0, 0, 'L');
                }
            }

            // Informações da empresa no cabeçalho
            $this->SetFont('helvetica', 'B', 12);
            $this->SetY(6);
            $this->Cell(0, 0, 'NOROAÇO COMÉRCIO DE FERRO E ACO LTDA', 0, 1, 'R');

            $this->SetFont('helvetica', '', 9);
            $this->SetY(12);
            $this->Cell(0, 0, 'Av. Alípio Rodero, 12276 - Centro Emp. Maria dos Santos Facchini', 0, 1, 'R');

            $this->SetFont('helvetica', '', 9);
            $this->SetY(17);
            $this->Cell(0, 0, 'Votuporanga - SP', 0, 1, 'R');

            $this->SetFont('helvetica', 'B', 9);
            $this->SetY(22);
            $this->Cell(0, 0, 'Telefone: (17) 3426-8600', 0, 1, 'R');

            // Linha divisória
            $this->SetLineWidth(0.5);
            $this->SetDrawColor(0, 0, 0);
            $this->Line(15, 30, 195, 30);
        }

        public function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Página 1 de 1', 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    $pdf = new MEU_PDF_UMA_PAGINA('P', 'mm', 'A4', true, 'UTF-8', false);

    // Configurar documento
    $pdf->SetCreator('NOROAÇO - Sistema de Pesagem');
    $pdf->SetSubject('Relatório de Pesagem');
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 35, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetAutoPageBreak(FALSE);
    $pdf->AddPage();

    // HTML com CSS e spacer explícito (spacer garantido para TCPDF)
    $html = '
<style>
    .titulo-principal {
        font-size: 14pt;
        font-weight: bold;
        text-align: center;
        color: #333;
    }
    .titulo-secao {
        font-size: 12pt;
        font-weight: bold;
        color: #555;
        background-color: #f5f5f5;
        border-left: 4px solid #fdb525;
        padding: 5px 10px; /* padding interno do título */
        /* margin-bottom pode ser ignorado pelo TCPDF, por isso usamos spacer abaixo */
    }
    .dado-item {
        font-size: 11pt;
        line-height: 1.4;
        margin-bottom: 8px;
    }
    .dado-label {
        font-weight: bold;
        display: inline-block;
        width: 130px;
        vertical-align: top;
    }
    .dado-valor {
        display: inline-block;
        vertical-align: top;
    }
    .peso-linha {
        margin-bottom: 10px;
        line-height: 1.4;
    }
    .peso-container {
        display: inline-block;
        width: 250px;
    }
    .peso-valor-principal {
        font-size: 11pt;
        font-weight: bold;
        color: #333;
        display: inline-block;
        width: 120px;
    }
    .peso-data {
        font-size: 9pt;
        color: #666;
        font-style: italic;
        display: inline-block;
        margin-left: 40px;
    }
    .peso-liquido {
        font-size: 12pt;
        font-weight: bold;
        color: #fdb525;
        margin: 10px 0;
    }
    .observacao-box {
        margin-top: 10px;
        border: 1px solid #ddd;
        padding: 8px;
        background-color: #f9f9f9;
        border-radius: 5px;
        max-height: 80mm;
        overflow: hidden;
    }
    .observacao-texto {
        font-size: 10pt;
        font-style: italic;
        max-height: 70mm;
        overflow: hidden;
    }
    .assinatura {
        margin-top: 20px;
        text-align: center;
    }
    .assinatura-linha {
        border-top: 1px solid #000;
        width: 250px;
        margin: 0 auto;
        padding-top: 5px;
    }
    .rodape {
        margin-top: 10px;
        font-size: 8pt;
        text-align: center;
        color: #666;
    }
</style>

<!-- TÍTULO DA PESAGEM: usamos um spacer explícito logo abaixo para garantir espaço -->
<table cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td class="titulo-secao">
            PESAGEM Nº ' . htmlspecialchars($codigo) . '
        </td>
    </tr>
    <!-- SPACER EXPLÍCITO: ajuste a altura (em pixels) abaixo para aumentar/diminuir distância -->
    <tr><td style="height:14px;line-height:14px;">&nbsp;</td></tr>
</table>

<div class="dado-item">
    <span class="dado-label">Placa:</span>
    <span class="dado-valor">' . htmlspecialchars($pesagem['placa'] ?: '-') . '</span>
</div>

<div class="dado-item">
    <span class="dado-label">Motorista:</span>
    <span class="dado-valor">' . htmlspecialchars($pesagem['motorista'] ?: '-') . '</span>
</div>

<div class="dado-item">
    <span class="dado-label">Fornecedor:</span>
    <span class="dado-valor">' . htmlspecialchars($pesagem['nome_fornecedor'] ?: '-') . '</span>
</div>

<div class="dado-item">
    <span class="dado-label">Número NF:</span>
    <span class="dado-valor">' . htmlspecialchars($pesagem['numero_nf'] ?: '-') . '</span>
</div>

<div class="peso-linha">
    <span class="dado-label">Pesagem Inicial:</span>
    <span class="peso-valor-principal">' . $pesoInicialFormatado . ' kg</span>
    <span class="peso-data">(Data/Hora: ' . $dataInicialFormatada . ')</span>
</div>

<div class="peso-linha">
    <span class="dado-label">Pesagem Final:</span>
    <span class="peso-valor-principal">' . $pesoFinalFormatado . ' kg</span>
    <span class="peso-data">(Data/Hora: ' . $dataFinalFormatada . ')</span>
</div>

<div class="dado-item">
    <span class="dado-label">Peso Líquido:</span>
    <span class="dado-valor peso-liquido">' . $pesoLiquidoFormatado . ' kg</span>
</div>';

$observacao = trim($pesagem['obs'] ?? '');

$html .= '
<div class="dado-item">
    <span class="dado-label">Observação:</span>
    <span class="dado-valor">' . nl2br(htmlspecialchars($observacao)) . '</span>
</div>';


    // Assinatura
    $html .= '
<div class="assinatura">
    <div class="assinatura-linha"></div>
</div>

<div class="rodape">
    Emissão ' . date('d/m/Y H:i:s') . '
</div>';

    // Escrever o conteúdo HTML
    // Parâmetros: ($html, $ln=true, $fill=false, $reseth=true, $cell=false, $align='')
    $pdf->writeHTML($html, true, false, true, false, '');

    // Nome do arquivo
    $fileName = 'pesagem_' . preg_replace('/[^0-9a-zA-Z_-]/', '', $codigo) . '_' . date('Ymd_His') . '.pdf';

    // Output do PDF (visualizar no navegador)
    $pdf->Output($fileName, 'I');
} catch (PDOException $e) {
    die("Erro no banco de dados: " . $e->getMessage());
} catch (Exception $e) {
    die("Erro ao gerar PDF: " . $e->getMessage());
}
