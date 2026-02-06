<?php
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

require_once('tcpdf/tcpdf.php');

// Conexão com o banco Firebird
$dsn = 'firebird:dbname=192.168.1.209:c:/BD/ARQSIST.FDB;charset=UTF8';
$user = 'SYSDBA';
$pass = 'masterkey';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}

if (!isset($_GET['seqcarga'])) {
    die("Sequência da carga não informada!");
}

$seqcarga = $_GET['seqcarga'];

try {
    // Consulta principal
    $sql = "SELECT 
                c.nrocarga,
                m.Nome AS Motorista,
                f.placa,
                c.caminhao_peso_tara AS peso_inicial,
                c.caminhao_peso_bruto AS peso_final,
                (c.caminhao_peso_tara - c.caminhao_peso_bruto) AS peso_liquido
            FROM VD_CARGA c
            LEFT JOIN ArqCad m 
                ON c.TipoC = m.TipoC 
               AND c.CodiC = m.Codic
            INNER JOIN frota f 
                ON f.codigo = c.cod_frota
            WHERE c.SeqCarga = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$seqcarga]);
    $carga = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$carga) {
        die("Carga não encontrada!");
    }

    // Buscar data/hora do peso inicial
    $sql_data_inicial = "SELECT r.data 
                        FROM log_registros r 
                        WHERE r.registro LIKE '%/ De Tara: 0.000%' 
                        AND r.cod_link = ? 
                        AND r.origem = 'CG'
                        AND r.tipo = 'A' 
                        AND r.tipo_log = 0
                        ORDER BY r.data DESC
                        ROWS 1";

    $stmt_data_inicial = $pdo->prepare($sql_data_inicial);
    $stmt_data_inicial->execute([$seqcarga]);
    $data_inicial = $stmt_data_inicial->fetch(PDO::FETCH_ASSOC);

    // Buscar data/hora do peso final
    $sql_data_final = "SELECT r.data 
                      FROM log_registros r 
                      WHERE r.registro LIKE '%/ De Peso Bruto:%' 
                      AND r.cod_link = ? 
                      AND r.origem = 'CG'
                      AND r.tipo = 'A' 
                      AND r.tipo_log = 0
                      ORDER BY r.data DESC
                      ROWS 1";

    $stmt_data_final = $pdo->prepare($sql_data_final);
    $stmt_data_final->execute([$seqcarga]);
    $data_final = $stmt_data_final->fetch(PDO::FETCH_ASSOC);

    // Função para formatar data/hora
    function formatarDataHora($dataString)
    {
        if (!$dataString) return '';

        try {
            // Converter string de data do Firebird para objeto DateTime
            $data = DateTime::createFromFormat('Y-m-d H:i:s.u', $dataString);
            if (!$data) {
                // Tentar outro formato se o primeiro falhar
                $data = new DateTime($dataString);
            }
            return $data->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    // Formatar as datas
    $data_hora_inicial = isset($data_inicial['DATA']) ? formatarDataHora($data_inicial['DATA']) : '';
    $data_hora_final = isset($data_final['DATA']) ? formatarDataHora($data_final['DATA']) : '';

    // Limpar valores nulos e formatar valores
    foreach ($carga as $key => $value) {
        if ($value === null) {
            $carga[$key] = '';
        }
        // Formatar pesos para ter 3 casas decimais
        if (in_array($key, ['PESO_INICIAL', 'PESO_FINAL', 'PESO_LIQUIDO']) && $value !== '' && is_numeric($value)) {
            $carga[$key] = number_format(floatval($value), 3, ',', '.');
        }
    }

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
    $pdf->SetCreator('NOROAÇO - Sistema de Cargas');
    $pdf->SetSubject('Ticket de Carga');
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 35, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetAutoPageBreak(FALSE);
    $pdf->AddPage();

    // HTML com CSS
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
        padding: 5px 10px;
    }
    .dado-item {
        font-size: 11pt;
        line-height: 1.4;
        margin-bottom: 8px;
    }
    .dado-label {
        font-weight: bold;
        display: inline-block;
        width: 150px;
        vertical-align: top;
    }
    .dado-valor {
        display: inline-block;
        vertical-align: top;
    }
    .peso-section {
        margin-top: 20px;
        border-top: 1px dashed #ccc;
        padding-top: 10px;
    }
    .peso-titulo {
        font-size: 12pt;
        font-weight: bold;
        color: #333;
        margin-bottom: 10px;
    }
    .data-hora {
        font-size: 10pt;
        color: #666;
        font-style: italic;
        margin-left: 10px;
    }
    .assinatura {
        margin-top: 40px;
        text-align: center;
    }
    .assinatura-linha {
        border-top: 1px solid #000;
        width: 250px;
        margin: 0 auto;
        padding-top: 5px;
    }
    .assinatura-texto {
        font-size: 10pt;
        color: #666;
        margin-top: 5px;
    }
    .rodape {
        margin-top: 10px;
        font-size: 8pt;
        text-align: center;
        color: #666;
    }
</style>

<!-- TÍTULO DA CARGA -->
<table cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td class="titulo-secao">
            CARGA Nº ' . (int)htmlspecialchars($carga['NROCARGA'] ?: $seqcarga) . '
        </td>
    </tr>
    <tr><td style="height:14px;line-height:14px;">&nbsp;</td></tr>
</table>';

    if (isset($carga['MOTORISTA'])) {
        $html .= '
<div class="dado-item">
    <span class="dado-label">Motorista:</span>
    <span class="dado-valor">' . htmlspecialchars($carga['MOTORISTA'] ?: '-') . '</span>
</div>';
    }

    if (isset($carga['PLACA'])) {
        $html .= '
<div class="dado-item">
    <span class="dado-label">Placa do Veículo:</span>
    <span class="dado-valor">' . htmlspecialchars($carga['PLACA'] ?: '-') . '</span>
</div>';
    }


    if (isset($carga['PESO_INICIAL'])) {
        $html .= '
    <div class="dado-item">
        <span class="dado-label">Peso Inicial:</span>
        <span class="dado-valor">' . htmlspecialchars($carga['PESO_INICIAL'] ?: '-') . ' kg';

        if ($data_hora_inicial) {
            $html .= ' <span class="data-hora">(Data/hora: ' . htmlspecialchars($data_hora_inicial) . ')</span>';
        }

        $html .= '</span>
    </div>';
    }

    if (isset($carga['PESO_FINAL'])) {
        $html .= '
    <div class="dado-item">
        <span class="dado-label">Peso Final:</span>
        <span class="dado-valor">' . htmlspecialchars($carga['PESO_FINAL'] ?: '-') . ' kg';

        if ($data_hora_final) {
            $html .= ' <span class="data-hora">(Data/hora: ' . htmlspecialchars($data_hora_final) . ')</span>';
        }

        $html .= '</span>
    </div>';
    }

    if (isset($carga['PESO_LIQUIDO'])) {
        $html .= '
    <div class="dado-item">
        <span class="dado-label">Peso Líquido:</span>
        <span class="dado-valor" style="font-weight: bold; color: #fdb525;">' . htmlspecialchars($carga['PESO_LIQUIDO'] ?: '-') . ' kg</span>
    </div>';
    }

    $html .= '</div>

<div class="assinatura">
    <div class="assinatura-linha"></div>
</div>

<div class="rodape">
    Emissão ' . date('d/m/Y H:i:s') . '
</div>';

    // Escrever o conteúdo HTML
    $pdf->writeHTML($html, true, false, true, false, '');

    // Nome do arquivo
    $fileName = 'ticket_carga_' . preg_replace('/[^0-9a-zA-Z_-]/', '', $seqcarga) . '_' . date('Ymd_His') . '.pdf';

    // Output do PDF (visualizar no navegador)
    $pdf->Output($fileName, 'I');
} catch (PDOException $e) {
    die("Erro no banco de dados: " . $e->getMessage());
} catch (Exception $e) {
    die("Erro ao gerar PDF: " . $e->getMessage());
}
