<?php
// limite_cred_pdf_detalhado.php - VERSÃO FINAL CORRIGIDA
// ============================================================
// PDF DETALHADO - RELATÓRIO COMPLETO DE ANÁLISE
// CORREÇÃO: Seção 10.13 SOCIOS agora funciona corretamente
// ============================================================

// Configurações iniciais
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);

// =============================================
// FUNÇÃO PARA CARREGAR TCPDF
// =============================================
function carregarTCPDF()
{
    if (class_exists('TCPDF')) {
        return true;
    }

    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        if (class_exists('TCPDF')) {
            return true;
        }
    }

    $tcpdf_paths = [
        __DIR__ . '/tcpdf/tcpdf.php',
        __DIR__ . '/TCPDF/tcpdf.php',
        __DIR__ . '/includes/tcpdf/tcpdf.php',
        __DIR__ . '/lib/tcpdf/tcpdf.php',
        __DIR__ . '/pdf/tcpdf/tcpdf.php'
    ];

    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            if (class_exists('TCPDF')) {
                return true;
            }
        }
    }

    return false;
}

// =============================================
// CARREGAR TCPDF
// =============================================
try {
    if (!carregarTCPDF()) {
        throw new Exception("TCPDF não está instalado. Por favor, instale via Composer: composer require tecnickcom/tcpdf");
    }
} catch (Exception $e) {
    die("Erro fatal: " . $e->getMessage());
}

// =============================================
// CLASSE TCPDF PERSONALIZADA COM FOOTER
// =============================================
class PDFPersonalizado extends TCPDF
{
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

// =============================================
// CLASSE GERADORA DO PDF DETALHADO
// =============================================
class PDFDetalhadoGenerator
{
    private static $pdf;
    private static $dados;
    private static $y_position = 0;

    // Para controlar o formato das datas baseado no campo
    private static $campo_atual = '';

    // Constantes para layout
    const LARGURA_ROTULO = 55;
    const LARGURA_VALOR = 125; // 180 - 55
    const MARGEM_ESQUERDA = 15;
    const MARGEM_DIREITA = 15;
    const LARGURA_TOTAL = 180; // 210 - 15 - 15
    const ALTURA_LINHA_PADRAO = 6;



    /**
     * EXTRAI VALOR SEGURO OU RETORNA TRAÇO
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
     * FORMATA DATA - MODIFICADO PARA DD-MM-YYYY EXCETO DATAS ESPECÍFICAS
     */
    private static function formatarData($dataStr, $campo = null)
    {
        if (!$dataStr || $dataStr === ' / ' || $dataStr === 'null' || $dataStr === '') {
            return '-';
        }

        try {
            $campos_com_hora = ['Data Criação', 'Data Atualização'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dataStr)) {
                $date = new DateTime($dataStr);

                if ($campo && in_array($campo, $campos_com_hora)) {
                    return $date->format('d/m/Y H:i:s');
                }

                return $date->format('d/m/Y');
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dataStr)) {
                if ($campo && in_array($campo, $campos_com_hora)) {
                    return $dataStr;
                }

                $partes = explode('/', $dataStr);
                if (count($partes) == 3) {
                    return $partes[0] . '/' . $partes[1] . '/' . $partes[2];
                }
            }
        } catch (Exception $e) {
            return '-';
        }

        return $dataStr;
    }

    private static function adicionarTitulo($titulo)
    {
        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 6, $titulo, 0, 1, 'L');
        self::$y_position = self::$pdf->GetY();
    }

    private static function formatarMoeda($valor)
    {
        if ($valor === null || $valor === '' || $valor === '-') {
            return 'R$ 0,00';
        }

        if ($valor === 0 || $valor === '0' || floatval($valor) == 0) {
            return 'R$ 0,00';
        }

        if (is_string($valor) && strpos($valor, 'R$') !== false) {
            return $valor;
        }

        $valorLimpo = preg_replace('/[^0-9,.-]/', '', $valor);
        $valorLimpo = str_replace(',', '.', $valorLimpo);

        if (is_numeric($valorLimpo)) {
            return 'R$ ' . number_format(floatval($valorLimpo), 2, ',', '.');
        }
        if (is_numeric($valor)) {
            return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
        }

        return 'R$ 0,00';
    }

    private static function formatarScore($score)
    {
        if ($score === null || $score === '' || $score === '-') {
            return '-';
        }

        $scoreLimpo = preg_replace('/[^0-9.]/', '', $score);

        if (is_numeric($scoreLimpo)) {
            if (floor($scoreLimpo) == $scoreLimpo) {
                return intval($scoreLimpo) . '/1000';
            }
            return $scoreLimpo . '/1000';
        }

        return $score;
    }

    private static function formatarDocumento($doc)
    {
        if (!$doc || $doc === '-') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $doc);

        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        }
        if (strlen($num) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $num);
        }

        return $doc;
    }

    private static function formatarBoolean($valor, $trueText = 'Sim', $falseText = 'Não')
    {
        if ($valor === true || $valor === 'true' || $valor === 1 || $valor === '1') {
            return $trueText;
        }
        if ($valor === false || $valor === 'false' || $valor === 0 || $valor === '0') {
            return $falseText;
        }
        return '-';
    }

    private static function safeJsonDecode($jsonString)
    {
        if (!$jsonString || $jsonString === '-' || $jsonString === 'null') {
            return null;
        }

        if (is_array($jsonString)) {
            return $jsonString;
        }

        $decoded = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    private static function buscaRecursiva($array, $chave, $default = '-')
    {
        if (!is_array($array)) {
            return $default;
        }

        foreach ($array as $key => $value) {
            if ($key === $chave) {
                return $value;
            }
            if (is_array($value)) {
                $resultado = self::buscaRecursiva($value, $chave, null);
                if ($resultado !== null) {
                    return $resultado;
                }
            }
        }
        return $default;
    }

    private static function extrairCampoJson($jsonData, $caminho, $default = '-')
    {
        if (!$jsonData || !is_array($jsonData)) {
            return $default;
        }

        $valor = self::getValueOrDash($jsonData, $caminho, null);

        if ($valor !== null) {
            return $valor;
        }

        if (is_array($caminho) && !empty($caminho)) {
            return self::buscaRecursiva($jsonData, end($caminho), $default);
        }

        return $default;
    }

    private static function escreverLinhaComLabelFixo($rotulo, $valor, $destaque = false, $x_inicial = null)
    {
        self::$campo_atual = $rotulo;

        if ($x_inicial === null) {
            $x_inicial = self::$pdf->GetX();
        }

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

        self::$campo_atual = '';

        return $altura_total;
    }

    private static function criarTabelaDinamica($cabecalhos, $dados, $larguras = [])
    {
        if (empty($dados)) {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, 'Nenhum registro encontrado', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
            return;
        }

        if (empty($larguras)) {
            $numColunas = count($cabecalhos);
            $larguraColuna = self::LARGURA_TOTAL / $numColunas;
            $larguras = array_fill(0, $numColunas, $larguraColuna);
        }

        $x_inicial = self::$pdf->GetX();
        $y_inicial = self::$pdf->GetY();

        // Cabeçalho
        self::$pdf->SetFont('helvetica', 'B', 8);
        self::$pdf->SetFillColor(220, 220, 220);

        foreach ($cabecalhos as $i => $cabecalho) {
            self::$pdf->Cell($larguras[$i], 7, $cabecalho, 1, 0, 'C', true);
        }
        self::$pdf->Ln();

        $y_atual = self::$pdf->GetY();

        foreach ($dados as $index => $linhaDados) {
            $fill = ($index % 2 == 0) ? false : true;

            $altura_maxima = self::ALTURA_LINHA_PADRAO;
            self::$pdf->SetFont('helvetica', '', 8);

            foreach ($linhaDados as $i => $valor) {
                $valorStr = is_array($valor) ? '-' : (string)$valor;
                if ($valorStr === '' || $valorStr === null) $valorStr = '-';

                $largura_coluna = $larguras[$i] - 2;
                $largura_texto = self::$pdf->GetStringWidth($valorStr);

                if ($largura_texto > $largura_coluna) {
                    $palavras = explode(' ', $valorStr);
                    $linha_teste = '';
                    $num_linhas = 1;

                    foreach ($palavras as $palavra) {
                        $teste_linha = $linha_teste ? $linha_teste . ' ' . $palavra : $palavra;
                        if (self::$pdf->GetStringWidth($teste_linha) <= $largura_coluna) {
                            $linha_teste = $teste_linha;
                        } else {
                            $num_linhas++;
                            $linha_teste = $palavra;
                        }
                    }

                    $altura_necessaria = $num_linhas * self::ALTURA_LINHA_PADRAO;
                    if ($altura_necessaria > $altura_maxima) {
                        $altura_maxima = $altura_necessaria;
                    }
                }
            }

            self::$pdf->SetXY($x_inicial, $y_atual);

            foreach ($linhaDados as $i => $valor) {
                $valorStr = is_array($valor) ? '-' : (string)$valor;
                if ($valorStr === '' || $valorStr === null) $valorStr = '-';

                $x_coluna = self::$pdf->GetX();
                $y_coluna = self::$pdf->GetY();

                self::$pdf->Rect($x_coluna, $y_coluna, $larguras[$i], $altura_maxima);

                if ($fill) {
                    self::$pdf->SetFillColor(245, 245, 245);
                    self::$pdf->Rect($x_coluna, $y_coluna, $larguras[$i], $altura_maxima, 'F');
                }

                self::$pdf->SetXY($x_coluna + 1, $y_coluna + 1);
                self::$pdf->SetFont('helvetica', '', 8);

                self::$pdf->MultiCell(
                    $larguras[$i] - 2,
                    self::ALTURA_LINHA_PADRAO,
                    $valorStr,
                    0,
                    'L',
                    false,
                    0,
                    '',
                    '',
                    true,
                    0,
                    false,
                    true,
                    $altura_maxima - 2,
                    'T'
                );

                self::$pdf->SetXY($x_coluna + $larguras[$i], $y_coluna);
            }

            $y_atual += $altura_maxima;
            self::$pdf->SetXY($x_inicial, $y_atual);
        }

        self::$y_position = $y_atual + 3;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA LINHA NO CARD
     */
    private static function adicionarLinha($rotulo, $valor, $destaque = false)
    {
        if ($rotulo === 'Mensagem' && $valor !== '-' && !empty($valor) && strpos($valor, '|') !== false) {
            self::formatarMensagemErro($valor);
            return;
        }

        self::escreverLinhaComLabelFixo($rotulo, $valor, $destaque);
    }


    /**
     * FORMATA MENSAGEM DE ERRO
     */
    private static function formatarMensagemErro($mensagem)
    {
        if ($mensagem === '-' || empty($mensagem)) {
            self::$pdf->Cell(0, 6, '-', 0, 1, 'L');
            return;
        }

        self::$pdf->SetFont('helvetica', '', 9);

        if (strpos($mensagem, '|') === false) {
            self::escreverLinhaComLabelFixo('Mensagem', $mensagem);
            return;
        }

        $partes = explode('|', $mensagem);
        $x_atual = self::$pdf->GetX();
        $y_inicial = self::$pdf->GetY();

        // Calcular altura total
        $altura_total = 0;
        $alturas_partes = [];

        foreach ($partes as $index => $parte) {
            $parte = trim($parte);
            $largura_texto = self::$pdf->GetStringWidth($parte);

            if ($largura_texto <= self::LARGURA_VALOR) {
                $alturas_partes[$index] = self::ALTURA_LINHA_PADRAO;
            } else {
                $palavras = explode(' ', $parte);
                $linha_teste = '';
                $num_linhas = 1;

                foreach ($palavras as $palavra) {
                    $teste_linha = $linha_teste ? $linha_teste . ' ' . $palavra : $palavra;
                    if (self::$pdf->GetStringWidth($teste_linha) <= self::LARGURA_VALOR) {
                        $linha_teste = $teste_linha;
                    } else {
                        $num_linhas++;
                        $linha_teste = $palavra;
                    }
                }

                $alturas_partes[$index] = $num_linhas * self::ALTURA_LINHA_PADRAO;
            }

            $altura_total += $alturas_partes[$index];
        }

        // Verificar espaço
        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 25;
        if ($altura_total > $espaco_restante) {
            self::$pdf->AddPage();
            $y_inicial = self::$pdf->GetY();
        }

        // Rótulo "Mensagem" com altura total
        self::$pdf->SetFont('helvetica', 'B', 9);
        self::$pdf->SetXY($x_atual, $y_inicial);
        self::$pdf->Cell(
            self::LARGURA_ROTULO,
            $altura_total,
            'Mensagem:',
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

        // Desenhar cada parte
        $y_atual = $y_inicial;
        self::$pdf->SetFont('helvetica', '', 9);

        foreach ($partes as $index => $parte) {
            $parte = trim($parte);

            self::$pdf->SetXY($x_atual + self::LARGURA_ROTULO, $y_atual);
            self::$pdf->MultiCell(
                self::LARGURA_VALOR,
                self::ALTURA_LINHA_PADRAO,
                $parte,
                0,
                'L',
                false,
                1
            );

            $y_atual += $alturas_partes[$index];
        }

        self::$y_position = $y_atual;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * CRIA CARD COM TÍTULO
     */
    private static function criarCard($titulo, $corFundo = [59, 89, 152])
    {
        if (self::$y_position > 250) {
            self::$pdf->AddPage();
            self::$y_position = 20;
        }

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
     * CRIA SUB-CARD COM TÍTULO
     */
    private static function criarSubCard($titulo, $corFundo = [100, 100, 100])
    {
        if (self::$y_position > 250) {
            self::$pdf->AddPage();
            self::$y_position = 20;
        }

        self::$pdf->SetFillColor($corFundo[0], $corFundo[1], $corFundo[2]);
        self::$pdf->SetTextColor(255, 255, 255);
        self::$pdf->SetFont('helvetica', 'B', 11);

        self::$pdf->SetY(self::$y_position + 2);
        self::$pdf->Cell(0, 7, '  ' . $titulo, 0, 1, 'L', true);

        self::$y_position = self::$pdf->GetY();

        self::$pdf->SetTextColor(0, 0, 0);
        self::$pdf->SetFont('helvetica', '', 9);

        self::$y_position += 2;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * CRIA TABELA (wrapper para tabela dinâmica)
     */
    private static function criarTabela($cabecalhos, $dados, $larguras = [])
    {
        self::criarTabelaDinamica($cabecalhos, $dados, $larguras);
    }

    /**
     * FINALIZA CARD COM ESPAÇAMENTO
     */
    private static function finalizarCard()
    {
        self::$y_position += 5;
        self::$pdf->SetY(self::$y_position);

        self::$pdf->SetLineWidth(0.2);
        self::$pdf->SetDrawColor(200, 200, 200);
        self::$pdf->Line(15, self::$y_position, 195, self::$y_position);

        self::$y_position += 8;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * FUNÇÃO AUXILIAR PARA VERIFICAR ESPAÇO NA PÁGINA
     */
    private static function verificarEspacoPagina($espaco_necessario)
    {
        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 30;

        if ($espaco_restante < $espaco_necessario) {
            self::$pdf->AddPage();
            self::$y_position = self::$pdf->GetY();
        }
    }

    // =========================================
    // NOVA FUNÇÃO PARA RENDERIZAR REGIMES TRIBUTÁRIOS
    // =========================================
    /**
     * Renderiza um card de regime tributário (SIMEI ou SIMPLES)
     */
    private static function renderizarRegime(string $titulo, string $regime, $rf)
    {
        self::criarSubCard($titulo, [100, 100, 100]);

        if ($rf) {
            $dadosRegime = $rf[$regime] ?? null;
            self::adicionarLinha('OPTANTE', self::formatarBoolean($dadosRegime['optante'] ?? null));
            self::adicionarLinha('DATA OPÇÃO', self::formatarData($dadosRegime['data_opcao'] ?? null, "DATA OPÇÃO " . strtoupper($regime)));
            self::adicionarLinha('DATA EXCLUSÃO', self::formatarData($dadosRegime['data_exclusao'] ?? null, "DATA EXCLUSÃO " . strtoupper($regime)));
            self::adicionarLinha('ULTIMA ATUALIZAÇÃO', self::formatarData($dadosRegime['ultima_atualizacao'] ?? null, "ULTIMA ATUALIZAÇÃO " . strtoupper($regime)));
        } else {
            self::adicionarLinha('OPTANTE', '-');
            self::adicionarLinha('DATA OPÇÃO', '-');
            self::adicionarLinha('DATA EXCLUSÃO', '-');
            self::adicionarLinha('ULTIMA ATUALIZAÇÃO', '-');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);
    }

    // =========================================
    // CARD CABEÇALHO
    // =========================================
    private static function adicionarCardCabecalho()
    {
        $logo_path = __DIR__ . '/imgs/noroaco.png';
        if (file_exists($logo_path)) {
            self::$pdf->Image($logo_path, 15, 14, 12, 0, 'PNG');
        }

        self::$pdf->SetY(15);
        self::$pdf->SetFont('helvetica', 'B', 20);
        self::$pdf->SetTextColor(253, 181, 37);
        self::$pdf->Cell(0, 10, 'RELATÓRIO PESSOA JURÍDICA', 0, 1, 'R');

        self::$pdf->SetFont('helvetica', 'B', 14);
        self::$pdf->SetTextColor(100, 100, 100);

        $razao = self::getValueOrDash(self::$dados, ['campos', 'razao']);
        if ($razao === '-') {
            $razao = self::getValueOrDash(self::$dados, 'razao_social');
        }
        self::$pdf->Cell(0, 6, $razao, 0, 1, 'R');

        self::$pdf->SetLineWidth(1);
        self::$pdf->SetDrawColor(253, 181, 37);
        self::$pdf->Line(15, 33, 195, 33);

        self::$y_position = 40;
        self::$pdf->SetY(self::$y_position);
    }

    // =========================================
    // CARD INFORMAÇÕES BÁSICAS
    // =========================================
    private static function adicionarCardInformacoesBasicas()
    {
        self::criarCard('1. NEOCREDIT', [41, 128, 185]);

        self::adicionarLinha('ID da Análise', self::getValueOrDash(self::$dados, 'id'));
        self::adicionarLinha('Status', self::getValueOrDash(self::$dados, 'status'));

        // Data Criação - mantém formato com hora
        self::adicionarLinha('Data Criação', self::formatarData(self::getValueOrDash(self::$dados, 'data_criacao'), 'Data Criação'));

        // Data Atualização - mantém formato com hora
        self::adicionarLinha('Data Atualização', self::formatarData(self::getValueOrDash(self::$dados, 'data_atualizacao'), 'Data Atualização'));

        // Data Conclusão - formato DD-MM-YYYY
        self::adicionarLinha('Data Conclusão', self::formatarData(self::getValueOrDash(self::$dados, 'data_conclusao'), 'Data Conclusão'));

        $isConcluido = self::getValueOrDash(self::$dados, 'is_concluido');
        $concluidoTexto = ($isConcluido === true || $isConcluido === 'true' || $isConcluido === 1) ? 'Sim' : 'Não';
        self::adicionarLinha('Concluído', $concluidoTexto);

        self::adicionarLinha('Fase Atual', self::getValueOrDash(self::$dados, 'fase_atual'));

        self::finalizarCard();
    }

    // =========================================
    // CARD DADOS DO CLIENTE
    // =========================================
    private static function adicionarCardDadosCliente()
    {
        self::criarCard('2. DADOS DO CLIENTE', [39, 174, 96]);

        self::adicionarLinha('Razão Social', self::getValueOrDash(self::$dados, ['campos', 'razao']));
        self::adicionarLinha('Documento', self::formatarDocumento(self::getValueOrDash(self::$dados, ['campos', 'documento'])));
        self::adicionarLinha('Risco', self::getValueOrDash(self::$dados, ['campos', 'risco']));
        self::adicionarLinha('Score', self::formatarScore(self::getValueOrDash(self::$dados, ['campos', 'score'])));

        $status = self::getValueOrDash(self::$dados, ['campos', 'status']);
        self::adicionarLinha('Status', $status, in_array(strtolower($status), ['aprovar', 'aprovado']));

        self::adicionarLinha('Limite Aprovado', self::formatarMoeda(self::getValueOrDash(self::$dados, ['campos', 'limite_aprovado'])));
        self::adicionarLinha('Classificação Risco', self::getValueOrDash(self::$dados, ['campos', 'classificacao_risco']));
        self::adicionarLinha('Data Validade', self::formatarData(self::getValueOrDash(self::$dados, ['campos', 'data_validade_limite_credito']), 'Data Validade'));

        self::adicionarLinha('Mensagem', self::getValueOrDash(self::$dados, ['campos', 'msg_erro_consulta']));

        self::finalizarCard();
    }

    // =========================================
    // CARD RECEITA FEDERAL
    // =========================================
    private static function adicionarCardDadosRF()
    {
        self::criarCard('3. RECEITA FEDERAL', [192, 57, 43]);

        $rfJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_rf']);
        $rf = self::safeJsonDecode($rfJson);

        if (!$rf) {
            $rf = self::getValueOrDash(self::$dados, ['campos', 'dados_receita_federal']);
            if (is_string($rf)) {
                $rf = self::safeJsonDecode($rf);
            }
        }

        if (!$rf) {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados da Receita Federal não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
            self::finalizarCard();
            return;
        }

        $margem_conteudo = 20;
        $espaco_necessario = 20;

        // 3.1 IDENTIFICAÇÃO
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.1 IDENTIFICAÇÃO', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $campos_identificacao = [
            'RAZÃO SOCIAL' => self::extrairCampoJson($rf, ['informacoes', 'razao']) ?: '-',
            'NOME FANTASIA' => self::extrairCampoJson($rf, ['informacoes', 'fantasia']) ?: '-',
            'CNPJ' => self::formatarDocumento(self::extrairCampoJson($rf, ['informacoes', 'cnpj'])),
            'TIPO CNPJ' => self::extrairCampoJson($rf, ['informacoes', 'matriz']) ?: '-',
            'DATA DE FUNDAÇÃO' => self::formatarData(self::extrairCampoJson($rf, ['informacoes', 'dt_abertura']), 'DATA DE FUNDAÇÃO'),
            'SITUAÇÃO CADASTRAL' => self::extrairCampoJson($rf, ['informacoes', 'situacao']) ?: '-',
            'MOTIVO DA SITUAÇÃO' => self::extrairCampoJson($rf, ['informacoes', 'motivo_situacao']) ?: '-',
            'DATA DA SITUAÇÃO CADASTRAL' => self::formatarData(self::extrairCampoJson($rf, ['informacoes', 'data_situacao']), 'DATA DA SITUAÇÃO CADASTRAL'),
            'PORTE' => self::extrairCampoJson($rf, ['informacoes', 'faixa_porte']) ?: '-',
            'CAPITAL SOCIAL' => self::formatarMoeda(self::extrairCampoJson($rf, ['informacoes', 'capital_social']))
        ];

        foreach ($campos_identificacao as $rotulo => $valor) {
            self::$pdf->SetX($margem_conteudo);
            self::adicionarLinha($rotulo, $valor);
        }

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);


        // 3.3 ATIVIDADES
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.2 ATIVIDADES', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $atividades = [];
        if (isset($rf['cnae']) && is_array($rf['cnae'])) {
            $atividades = $rf['cnae'];
        } elseif (isset($rf['atividades']) && is_array($rf['atividades'])) {
            $atividades = $rf['atividades'];
        } elseif (isset($rf['cnaes']) && is_array($rf['cnaes'])) {
            $atividades = $rf['cnaes'];
        }

        $dadosTabela = [];

        if (!empty($atividades)) {
            foreach ($atividades as $ativ) {
                $isPrimary = false;
                if (isset($ativ['is_primary'])) {
                    $isPrimary = $ativ['is_primary'];
                } elseif (isset($ativ['primario'])) {
                    $isPrimary = $ativ['primario'];
                } elseif (isset($ativ['principal'])) {
                    $isPrimary = $ativ['principal'];
                }

                $primario = ($isPrimary === true || $isPrimary === 'true' || $isPrimary === 1) ? 'SIM' : 'NÃO';

                $dadosTabela[] = [
                    $ativ['codigo'] ?? $ativ['cnae'] ?? '-',
                    $ativ['descricao'] ?? '-',
                    $primario
                ];
            }
        }

        $x_atual = self::$pdf->GetX();
        self::$pdf->SetX($margem_conteudo);
        self::criarTabela(
            ['CNAE', 'DESCRIÇÃO', 'PRINCIPAL'],
            $dadosTabela,
            [25, 115, 25]
        );
        self::$pdf->SetX($x_atual);

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);

        // 3.4 ENDEREÇOS
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.3 ENDEREÇOS', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $enderecos = [];
        if (isset($rf['enderecos']) && is_array($rf['enderecos'])) {
            $enderecos = $rf['enderecos'];
        } elseif (isset($rf['endereco']) && is_array($rf['endereco'])) {
            $enderecos = is_array($rf['endereco'][0] ?? null) ? $rf['endereco'] : [$rf['endereco']];
        } else {
            $endereco_temp = [];
            if (isset($rf['cep']) || isset($rf['logradouro'])) {
                $endereco_temp[] = [
                    'cep' => $rf['cep'] ?? '-',
                    'logradouro' => $rf['logradouro'] ?? '-',
                    'numero' => $rf['numero'] ?? '-',
                    'complemento' => $rf['complemento'] ?? '-',
                    'bairro' => $rf['bairro'] ?? '-',
                    'cidade' => $rf['cidade'] ?? '-',
                    'uf' => $rf['uf'] ?? '-'
                ];
                $enderecos = $endereco_temp;
            }
        }

        $dadosTabela = [];

        if (!empty($enderecos)) {
            foreach ($enderecos as $end) {
                $dadosTabela[] = [
                    $end['cep'] ?? '-',
                    $end['logradouro'] ?? '-',
                    $end['numero'] ?? '-',
                    $end['complemento'] ?? '-',
                    $end['bairro'] ?? '-',
                    $end['cidade'] ?? '-',
                    $end['uf'] ?? '-'
                ];
            }
        }

        $x_atual = self::$pdf->GetX();
        self::$pdf->SetX($margem_conteudo);
        self::criarTabela(
            ['CEP', 'LOGRADOURO', 'Nº', 'COMPLEMENTO', 'BAIRRO', 'CIDADE', 'UF'],
            $dadosTabela,
            [18, 45, 12, 26, 32, 25, 8]
        );
        self::$pdf->SetX($x_atual);

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);

        // 3.5 EMAILS
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.4 EMAIL', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $emails = [];
        if (isset($rf['emails']) && is_array($rf['emails'])) {
            $emails = $rf['emails'];
        } elseif (isset($rf['contatos']['emails']) && is_array($rf['contatos']['emails'])) {
            $emails = $rf['contatos']['emails'];
        } elseif (isset($rf['email']) && !empty($rf['email'])) {
            $emails = is_array($rf['email']) ? $rf['email'] : [$rf['email']];
        }

        if (!empty($emails)) {
            self::$pdf->SetFont('helvetica', '', 9);
            foreach ($emails as $email) {
                $emailStr = '';
                if (is_array($email)) {
                    $emailStr = $email['email'] ?? $email['endereco'] ?? '';
                } elseif (is_string($email)) {
                    $emailStr = $email;
                }

                if (!empty($emailStr)) {
                    self::$pdf->SetX($margem_conteudo + 5);
                    self::$pdf->Cell(0, 5, '• ' . $emailStr, 0, 1, 'L');
                }
            }
        } else {
            self::$pdf->SetX($margem_conteudo);
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, 'Nenhum email cadastrado', 0, 1, 'L');
        }

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);

        // 3.6 TELEFONES
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.5 TELEFONES', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $telefones = [];
        if (isset($rf['telefones']) && is_array($rf['telefones'])) {
            $telefones = $rf['telefones'];
        } elseif (isset($rf['contatos']['telefones']) && is_array($rf['contatos']['telefones'])) {
            $telefones = $rf['contatos']['telefones'];
        } elseif (isset($rf['fone']) && !empty($rf['fone'])) {
            $telefones = is_array($rf['fone']) ? $rf['fone'] : [['fone' => $rf['fone']]];
        }

        $dadosTabela = [];

        if (!empty($telefones)) {
            foreach ($telefones as $fone) {
                $ddd = '-';
                $numero = '-';

                if (is_array($fone)) {
                    $ddd = $fone['ddd'] ?? '-';
                    $numero = $fone['fone'] ?? '-';
                } elseif (is_string($fone)) {
                    $numero = $fone;
                }

                $dadosTabela[] = [$ddd, $numero];
            }
        }

        $x_atual = self::$pdf->GetX();
        self::$pdf->SetX($margem_conteudo);
        self::criarTabela(
            ['DDD', 'TELEFONE'],
            $dadosTabela,
            [25, 130]
        );
        self::$pdf->SetX($x_atual);

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);

        // 3.7 QSA
        self::verificarEspacoPagina($espaco_necessario);

        self::$pdf->SetFillColor(255, 240, 240);
        self::$pdf->Rect(12, self::$y_position + 2, 186, 7, 'F');

        self::$pdf->SetFont('helvetica', 'B', 10);
        self::$pdf->SetTextColor(192, 57, 43);
        self::$pdf->SetX(15);
        self::$pdf->Cell(0, 11, '3.6 QSA - QUADRO DE SÓCIOS E ADMINISTRADORES', 0, 1, 'L');
        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = self::$pdf->GetY() + 2;
        self::$pdf->SetY(self::$y_position);
        self::$pdf->SetFont('helvetica', '', 9);
        self::$pdf->SetX($margem_conteudo);

        $qsa = [];
        if (isset($rf['qsa']) && is_array($rf['qsa'])) {
            $qsa = $rf['qsa'];
        } elseif (isset($rf['socios']) && is_array($rf['socios'])) {
            $qsa = $rf['socios'];
        }

        $dadosTabela = [];

        if (!empty($qsa)) {
            foreach ($qsa as $socio) {
                $tipo = $socio['tipo'] ?? $socio['tipo_socio'] ?? $socio['qualificacao'] ?? '-';
                $nome = $socio['nome'] ?? $socio['nome_socio'] ?? $socio['razao_social'] ?? '-';

                $dadosTabela[] = [$tipo, $nome];
            }
        }

        $x_atual = self::$pdf->GetX();
        self::$pdf->SetX($margem_conteudo);
        self::criarTabela(
            ['TIPO', 'NOME'],
            $dadosTabela,
            [50, 80]
        );
        self::$pdf->SetX($x_atual);

        self::$y_position = self::$pdf->GetY() + 8;
        self::$pdf->SetY(self::$y_position);

        self::finalizarCard();
    }

    // =========================================
    // CARD MENSAGENS COMPLEMENTARES
    // =========================================
    private static function adicionarCardMensagensComplementares()
    {
        self::criarCard('4. MENSAGENS COMPLEMENTARES', [155, 89, 182]);

        $spcJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_spc']);
        $spc = self::safeJsonDecode($spcJson);

        $mensagens = self::extrairCampoJson($spc, ['mensagem_complementar'], []);

        if (is_array($mensagens) && !empty($mensagens)) {
            foreach ($mensagens as $msg) {
                $origem = $msg['origem'] ?? 'Mensagem';
                $mensagem = $msg['mensagem'] ?? '-';
                self::adicionarLinha($origem, $mensagem);
            }
        } else {
            self::adicionarLinha('CONCENTRE', '-');
            self::adicionarLinha('PEFIN/REFIN', '-');
            self::adicionarLinha('RECHEQUE', '-');
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD SINTEGRA
    // =========================================
    private static function adicionarCardDadosSintegra()
    {
        self::criarCard('5. DADOS SINTEGRA', [241, 196, 15]);

        $sintegraJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_sintegra_completo']);
        $sintegra = self::safeJsonDecode($sintegraJson);

        if ($sintegra) {
            self::adicionarLinha('Situação IE', self::extrairCampoJson($sintegra, ['situacao_ie']));
            self::adicionarLinha('Regime ICMS', self::extrairCampoJson($sintegra, ['regime_icms']));
            self::adicionarLinha('Inscrição Estadual', self::extrairCampoJson($sintegra, ['StateRegistration']));
            self::adicionarLinha('UF', self::extrairCampoJson($sintegra, ['State']));

            if (isset($sintegra['lista_ie'][0])) {
                $ie = $sintegra['lista_ie'][0];
                self::adicionarLinha('Situação IE', $ie['situacao_ie'] ?? '-');
            }
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados Sintegra não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD INDICADOR
    // =========================================
    private static function adicionarCardDadosIndicador()
    {
        self::criarCard('6. DADOS INDICADOR', [230, 126, 34]);

        $indicadorJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_indicador']);
        $indicador = self::safeJsonDecode($indicadorJson);

        if ($indicador) {
            $divida = $indicador['divida'] ?? [];

            self::adicionarLinha('Status Dívidas', self::extrairCampoJson($indicador, ['divida', 'statusDividas']));

            $existeCobranca = self::extrairCampoJson($indicador, ['divida', 'existeCobranca']) === true ? 'Sim' : 'Não';
            self::adicionarLinha('Existe Cobrança', $existeCobranca, $existeCobranca === 'Sim');

            self::adicionarLinha(
                'Quantidade Dívidas',
                self::extrairCampoJson($indicador, ['divida', 'quantidadeDividas']),
                (self::extrairCampoJson($indicador, ['divida', 'quantidadeDividas'], 0) > 0)
            );

            self::adicionarLinha('Valor Total', self::formatarMoeda(self::extrairCampoJson($indicador, ['divida', 'valorTotal'])));

            self::adicionarLinha('CPC', self::extrairCampoJson($indicador, ['cpc']));
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados do Indicador não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD SPC / SERASA
    // =========================================
    private static function adicionarCardDadosSPC()
    {
        self::criarCard('7. DADOS SPC / SERASA', [66, 127, 138]);

        $spcJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_spc']);
        $spc = self::safeJsonDecode($spcJson);

        // Buscar dados de protesto do resultado_completo_protesto
        $protestoJson = self::getValueOrDash(self::$dados, ['protestos', 'resultado_completo_protesto']);
        $protesto = self::safeJsonDecode($protestoJson);

        if ($spc) {
            // === FUNÇÃO AUXILIAR PARA BUSCA PROFUNDA ===
            $buscaProfunda = function ($dados, $caminhos) {
                foreach ($caminhos as $caminho) {
                    $valor = self::extrairCampoJson($dados, $caminho);
                    if ($valor !== null && $valor !== '') {
                        return $valor;
                    }
                }
                return null;
            };

            // === CARD PRINCIPAL - COM MÚLTIPLOS CAMINHOS DE BUSCA ===

            // SCORE - TENTAR DIFERENTES CAMINHOS
            $score = $buscaProfunda($spc, [
                ['score_pj', 'detalhe_score_pj', 'score'],
                ['score', 'score']
            ]);

            // RISCO DE CRÉDITO
            $classe = $buscaProfunda($spc, [
                ['score_pj', 'detalhe_score_pj', 'classe'],
                ['classe']
            ]);

            // TAXA DE INADIMPLÊNCIA
            $probabilidade = $buscaProfunda($spc, [
                ['score_pj', 'detalhe_score_pj', 'probabilidade'],
                ['probabilidade']
            ]);

            // LIMITE DE CRÉDITO SPC
            $limiteSPC = $buscaProfunda($spc, [
                ['limite_credito_pj', 'detalhe_limite_credito_pj', 'valor_limite_credito'],
                ['valor_limite_credito']
            ]);

            // POSSUI RESTRIÇÃO?
            $possuiRestricao = $buscaProfunda($spc, [
                ['restricao']
            ]);

            // QUANTIDADE CCF
            $qtdeCCF = $buscaProfunda($spc, [
                ['ccf', 'resumo', 'quantidade_total']
            ]);

            // QUANTIDADE SPC
            $qtdeSPC = $buscaProfunda($spc, [
                ['spc', 'resumo', 'quantidade_total']
            ]);

            // QUANTIDADE AÇÕES
            $qtdeAcoes = $buscaProfunda($spc, [
                ['acao', 'resumo', 'quantidade_total']
            ]);

            // === DADOS DE PROTESTO - BUSCAR DO resultado_completo_protesto ===
            $qtdeProtestos = '0';
            $valorProtestos = '0';
            $primeiroProtestoData = '-';
            $ultimoProtestoData = '-';
            $ultimoProtestoNumero = '-';

            if ($protesto && isset($protesto['lista_protestos'])) {
                $listaProtestos = $protesto['lista_protestos'];

                // Quantidade total de protestos
                $qtdeProtestos = $listaProtestos['quantidade_total_protestos'] ?? '0';

                // Valor total dos protestos
                $valorProtestos = $listaProtestos['valor_total'] ?? '0';

                // Data do primeiro protesto
                $dataPrimeiro = $listaProtestos['data_primeiro_protesto'] ?? '';
                if (!empty($dataPrimeiro)) {
                    $primeiroProtestoData = self::formatarData($dataPrimeiro, 'PRIMEIRO PROTESTO DATA');
                }

                // Data do último protesto
                $dataUltimo = $listaProtestos['data_ultimo_protesto'] ?? '';
                if (!empty($dataUltimo)) {
                    $ultimoProtestoData = self::formatarData($dataUltimo, 'ÚLTIMO PROTESTO DATA');
                }
            } else {
                // Fallback para buscar do SPC se não encontrar no protesto
                $qtdeProtestos = $buscaProfunda($spc, [
                    ['protesto', 'resumo', 'quantidade_total'],
                    ['protesto', 'quantidade_total']
                ]) ?: '0';

                $valorProtestos = $buscaProfunda($spc, [
                    ['tratamentos', 'valor_total_protestos'],
                    ['valor_total_protestos']
                ]) ?: '0';

                // Tentar buscar datas do SPC
                $dataPrimeiro = $buscaProfunda($spc, [
                    ['data_primeiro_protesto']
                ]);
                if (!empty($dataPrimeiro)) {
                    $primeiroProtestoData = self::formatarData($dataPrimeiro, 'PRIMEIRO PROTESTO DATA');
                }

                $dataUltimo = $buscaProfunda($spc, [
                    ['data_ultimo_protesto']
                ]);
                if (!empty($dataUltimo)) {
                    $ultimoProtestoData = self::formatarData($dataUltimo, 'ÚLTIMO PROTESTO DATA');
                }
            }

            // === ADICIONAR TODAS AS LINHAS ===
            self::adicionarLinha('SCORE', self::formatarScore($score));
            self::adicionarLinha('RISCO DE CRÉDITO', $classe ?: '-');
            self::adicionarLinha('TAXA DE INADIMPLÊNCIA', $probabilidade ? (strpos($probabilidade, '%') ? $probabilidade : $probabilidade . '%') : '-');
            self::adicionarLinha('LIMITE DE CRÉDITO SPC', self::formatarMoeda($limiteSPC));

            // Converter restrição para SIM/NÃO
            $restricaoTexto = 'NÃO';
            if ($possuiRestricao !== null && $possuiRestricao !== '') {
                if (is_bool($possuiRestricao)) {
                    $restricaoTexto = $possuiRestricao ? 'SIM' : 'NÃO';
                } else {
                    $restricaoTexto = (strtolower($possuiRestricao) === 'true' || $possuiRestricao === '1' || $possuiRestricao === 'sim') ? 'SIM' : 'NÃO';
                }
            }
            self::adicionarLinha('POSSUI RESTRIÇÃO?', $restricaoTexto);

            self::adicionarLinha('QUANTIDADE CCF', $qtdeCCF ?: '0');
            self::adicionarLinha('QUANTIDADE SPC', $qtdeSPC ?: '0');
            self::adicionarLinha('QUANTIDADE AÇÕES', $qtdeAcoes ?: '0');
            self::adicionarLinha('QUANTIDADE PROTESTOS', $qtdeProtestos);
            self::adicionarLinha('VALOR PROTESTOS', self::formatarMoeda($valorProtestos));
            self::adicionarLinha('PRIMEIRO PROTESTO DATA', $primeiroProtestoData);
            self::adicionarLinha('ÚLTIMO PROTESTO DATA', $ultimoProtestoData);

            // Espaço antes dos subcards
            self::$y_position += 5;
            self::$pdf->SetY(self::$y_position);

            // === SUBCARD 7.1 - HISTÓRICO PAGAMENTOS ===
            self::criarSubCard('7.1 HISTÓRICO PAGAMENTOS', [130, 168, 174]);

            $qtdfontesinfor = self::extrairCampoJson($spc, ['historico_pagamento_por_faixa', 'quantidade_fontes']);
            $relacionamentoMaisAntigo = self::extrairCampoJson($spc, ['relacionamento_mais_antigo_com_fornecedores', 'detalhe_relacionamento_mais_antigo_com_fornecedores', 'data_relacionamento_mais_antigo']);

            self::adicionarLinha('QTD FONTES DE INFORMAÇÃO', $qtdfontesinfor);
            self::adicionarLinha('RELACIONAM. FORNECEDORES', self::formatarData($relacionamentoMaisAntigo, 'RELACIONAM. FORNECEDORES'));

            $faixas = self::extrairCampoJson($spc, ['historico_pagamento_por_faixa', 'detalhe_historico_pagamento_por_faixa'], []);

            // Criar tabela com os dados do histórico de pagamentos
            $dadosTabelaHistorico = [];

            if (is_array($faixas) && !empty($faixas)) {
                // Ordenar as faixas por período (assumindo que já vêm em ordem lógica)
                foreach ($faixas as $faixa) {
                    $periodo = $faixa['descricao_periodo'] ?? '-';
                    $qtdDe = $faixa['quantidade_periodo_de'] ?? '-';
                    $qtdAte = $faixa['quantidade_periodo_ate'] ?? '-';
                    $percDe = isset($faixa['percentual_periodo_de']) ? $faixa['percentual_periodo_de'] . '%' : '-';
                    $percAte = isset($faixa['percentual_periodo_ate']) ? $faixa['percentual_periodo_ate'] . '%' : '-';

                    $dadosTabelaHistorico[] = [
                        $periodo,
                        $qtdDe,
                        $qtdAte,
                        $percDe,
                        $percAte
                    ];
                }
            } else {
                // Se não houver dados, adicionar linha informativa
                $dadosTabelaHistorico[] = ['Nenhum registro encontrado', '', '', '', ''];
            }

            self::criarTabela(
                ['PERÍODO', 'QTD DE', 'QTD ATÉ', 'PERCENTUAL DE', 'PERCENTUAL ATÉ'],
                $dadosTabelaHistorico,
                [40, 25, 25, 35, 35] // Larguras ajustadas para caberem na página
            );

            self::$y_position += 5;
            self::$pdf->SetY(self::$y_position);

            // =========================================
            // SUBCARD 7.2 - REFERÊNCIA DE NEGÓCIO - VERSÃO CORRIGIDA
            // =========================================
            self::criarSubCard('7.2 REFERÊNCIA DE NEGÓCIO', [130, 168, 174]);

            // Buscar dados de referência de negócio com múltiplos caminhos
            $referencias = $buscaProfunda($spc, [
                ['referenciais_negocios_por_faixa', 'detalhe_referenciais_negocios_por_faixa'],
                ['referencia_negocio', 'detalhes'],
                ['referencias_negocio']
            ], []);

            // Criar tabela com os dados das referências de negócio
            if (is_array($referencias) && !empty($referencias)) {

                $dadosTabelaReferencias = [];

                foreach ($referencias as $ref) {
                    if (!is_array($ref)) continue;

                    // Extrair os campos conforme especificado
                    $descricao = $ref['descricao_negocio'] ?? '-';
                    $dataPotencial = $ref['data_potencial'] ?? $ref['data'] ?? '-';
                    $valorDe = $ref['valor_pontencial_de'] ?? $ref['valor_de'] ?? $ref['faixa_de'] ?? '';
                    $valorAte = $ref['valor_potenical_ate'] ?? $ref['valor_ate'] ?? $ref['faixa_ate'] ?? '';
                    $mediaDe = $ref['valor_faixa_media_potencial_de'] ?? $ref['media_de'] ?? '';
                    $mediaAte = $ref['valor_faixa_media_potencial_ate'] ?? $ref['media_ate'] ?? '';

                    // Formatar datas
                    $dataFormatada = self::formatarData($dataPotencial, 'DATA POTENCIAL');

                    // Formatar valores monetários
                    $valorDeFormatado = self::formatarMoeda($valorDe);
                    $valorAteFormatado = self::formatarMoeda($valorAte);
                    $mediaDeFormatado = self::formatarMoeda($mediaDe);
                    $mediaAteFormatado = self::formatarMoeda($mediaAte);

                    $dadosTabelaReferencias[] = [
                        $descricao,
                        $dataFormatada,
                        $valorDeFormatado,
                        $valorAteFormatado,
                        $mediaDeFormatado,
                        $mediaAteFormatado
                    ];
                }

                // Ordenar por data (mais recente primeiro) se possível
                usort($dadosTabelaReferencias, function ($a, $b) {
                    $dataA = DateTime::createFromFormat('d/m/Y', $a[1]);
                    $dataB = DateTime::createFromFormat('d/m/Y', $b[1]);

                    if ($dataA && $dataB) {
                        return $dataB <=> $dataA;
                    }
                    return 0;
                });

                // Definir larguras das colunas
                $larguras = [35, 30, 28, 28, 28, 28]; // Ajuste conforme necessário

                // Criar a tabela
                self::criarTabela(
                    [
                        'DESC NEGÓCIO',
                        'DATA POTENCIAL',
                        'VALOR DE',
                        'VALOR ATÉ',
                        'MÉDIA DE',
                        'MÉDIA ATÉ'
                    ],
                    $dadosTabelaReferencias,
                    $larguras
                );
            } else {
                // Se não houver dados de referência, mostrar mensagem
                self::$pdf->SetFont('helvetica', 'I', 9);
                self::$pdf->Cell(0, 6, 'Nenhum registro de referência de negócio encontrado', 0, 1, 'C');

                // Opcional: mostrar exemplo da estrutura esperada (pode remover em produção)
                self::$pdf->SetFont('helvetica', 'I', 8);
                self::$pdf->SetTextColor(150, 150, 150);
                self::$pdf->Cell(0, 4, 'Campos esperados: descricao_negocio, data_potencial, valor_pontencial_de, valor_potenical_ate, valor_faixa_media_potencial_de, valor_faixa_media_potencial_ate', 0, 1, 'C');
                self::$pdf->SetTextColor(0, 0, 0);
            }

            self::$y_position = self::$pdf->GetY() + 5;
            self::$pdf->SetY(self::$y_position);

            self::criarSubCard('7.3 REGISTRO DE CONSULTAS', [130, 168, 174]);

            // Buscar dados de consultas
            $consultasDetalhadas = $buscaProfunda($spc, [
                ['detalhe_registro_consulta']
            ], []);

            $dadosTabelaConsultas = [];

            if (is_array($consultasDetalhadas) && !empty($consultasDetalhadas)) {
                foreach ($consultasDetalhadas as $consulta) {
                    if (!is_array($consulta)) continue;

                    $dataConsulta = $consulta['data_consulta'] ?? '';
                    $dataConsultaFormatada = self::formatarData($dataConsulta, 'DATA CONSULTA');

                    $qtdBanco = isset($consulta['quantidade_consulta_banco']) ? $consulta['quantidade_consulta_banco'] : '0';
                    $qtdEmpresa = isset($consulta['quantidade_consulta_empresa']) ? $consulta['quantidade_consulta_empresa'] : '0';

                    $dadosTabelaConsultas[] = [
                        $dataConsultaFormatada,
                        $qtdBanco,
                        $qtdEmpresa
                    ];
                }
            }

            $cabecalhosConsultas = ['DATA CONSULTA', 'QTD CONSULTA BANCO', 'QTD CONSULTA EMPRESA'];
            $largurasConsultas = [60, 55, 55];

            $x_atual = self::$pdf->GetX();
            self::$pdf->SetX(15);

            if (!empty($dadosTabelaConsultas)) {
                self::criarTabela($cabecalhosConsultas, $dadosTabelaConsultas, $largurasConsultas);
            } else {
                self::$pdf->SetFont('helvetica', 'I', 9);
                self::$pdf->Cell(0, 6, 'Nenhum registro de consulta encontrado', 0, 1, 'C');
            }

            self::$pdf->SetX($x_atual);
            self::$y_position = self::$pdf->GetY() + 5;
            self::$pdf->SetY(self::$y_position);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados SPC não disponíveis para este CNPJ -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD JUDICIAL
    // =========================================
    private static function adicionarCardDadosJudiciais()
    {
        self::criarCard('8. DADOS JUDICIAIS', [52, 152, 219]);

        $judicialJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_judicial']);
        $judicial = self::safeJsonDecode($judicialJson);

        if ($judicial) {
            self::adicionarLinha(
                'Total Processos',
                self::extrairCampoJson($judicial, ['TotalLawsuits']),
                (self::extrairCampoJson($judicial, ['TotalLawsuits'], 0) > 0)
            );
            self::adicionarLinha('Como Autor', self::extrairCampoJson($judicial, ['TotalLawsuitsAsAuthor']));
            self::adicionarLinha(
                'Como Réu',
                self::extrairCampoJson($judicial, ['TotalLawsuitsAsDefendant']),
                (self::extrairCampoJson($judicial, ['TotalLawsuitsAsDefendant'], 0) > 0)
            );

            $natureza = $judicial['resumo']['natureza'] ?? [];
            self::$pdf->SetFont('helvetica', 'B', 9);
            self::$pdf->Cell(0, 5, 'Distribuição por Natureza:', 0, 1, 'L');
            self::$y_position = self::$pdf->GetY();

            self::$pdf->SetFont('helvetica', '', 9);
            self::$pdf->Cell(15, 5, '', 0, 0, 'L');
            self::$pdf->Cell(40, 5, 'Cível:', 0, 0, 'L');
            self::$pdf->Cell(0, 5, self::extrairCampoJson($judicial, ['resumo', 'natureza', 'civel']), 0, 1, 'L');

            self::$pdf->Cell(15, 5, '', 0, 0, 'L');
            self::$pdf->Cell(40, 5, 'Trabalhista:', 0, 0, 'L');
            self::$pdf->Cell(0, 5, self::extrairCampoJson($judicial, ['resumo', 'natureza', 'trabalhista']), 0, 1, 'L');

            self::$pdf->Cell(15, 5, '', 0, 0, 'L');
            self::$pdf->Cell(40, 5, 'Criminal:', 0, 0, 'L');
            self::$pdf->Cell(0, 5, self::extrairCampoJson($judicial, ['resumo', 'natureza', 'criminal']), 0, 1, 'L');

            self::$y_position = self::$pdf->GetY();
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados judiciais não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD RELACIONAMENTOS
    // =========================================
    private static function adicionarCardRelacionamentos()
    {
        self::criarCard('9. RELACIONAMENTOS ECONÔMICOS ATIVOS', [142, 68, 173]);

        $relJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_relacionamentos']);
        $rel = self::safeJsonDecode($relJson);

        if ($rel) {
            // --- Campos Principais ---
            self::adicionarLinha('TOTAL DE RELACIONAMENTOS', self::extrairCampoJson($rel, ['TotalRelationships']));
            self::adicionarLinha('TOTAL SÓCIOS', self::extrairCampoJson($rel, ['TotalOwners']));
            self::adicionarLinha('TOTAL FUNCIONÁRIOS', self::extrairCampoJson($rel, ['TotalEmployees']));

            // Campo booleano: FALSE = NAO; TRUE = SIM
            $familiar = self::extrairCampoJson($rel, ['IsFamilyCompany']) === true ? 'SIM' : 'NÃO';
            self::adicionarLinha('EMPRESA FAMILIAR', $familiar, $familiar === 'SIM');

            // --- Tabela de Sócios/Administradores ---
            $relacionamentos = $rel['Relationships'] ?? $rel['CurrentRelationships'] ?? [];
            $dadosTabelaSocios = [];

            if (!empty($relacionamentos)) {
                foreach ($relacionamentos as $r) {
                    // Filtra apenas os que são Sócios ou Administradores
                    if (!empty($r['RelatedEntityName'])) {
                        $nome = $r['RelatedEntityName'] ?? '-';
                        $cargo = $r['RelationshipName'] ?? '-';
                        $inicioRelacionamento = self::formatarData($r['RelationshipStartDate'] ?? '', 'INICIO DO RELACIONAMENTO');

                        $dadosTabelaSocios[] = [
                            $nome,
                            $cargo,
                            $inicioRelacionamento
                        ];
                    }
                }
            }

            // Adiciona o título SÓCIOS / ADMINISTRADORES
            self::adicionarTitulo('SÓCIOS / ADMINISTRADORES:');

            // Ajusta a posição Y para dar um espaço antes da tabela
            self::$y_position += 2;
            self::$pdf->SetY(self::$y_position);

            // Cria a tabela (mesmo que vazia, a função já trata "Nenhum registro encontrado")
            self::criarTabela(
                ['NOME', 'CARGO', 'INICIO DO RELACIONAMENTO'],
                $dadosTabelaSocios,
                [70, 50, 45]
            );
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados de relacionamentos não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    // =========================================
    // CARD TODOS OS CAMPOS - VERSÃO REFORMULADA CORRIGIDA
    // =========================================
    private static function adicionarCardTodosCamposReformulado()
    {
        self::criarCard('10. TODOS OS CAMPOS DO RELATÓRIO', [0, 0, 0]);

        $spcJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_spc']);
        $spc = self::safeJsonDecode($spcJson);
        $rfJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_rf']);
        $rf = self::safeJsonDecode($rfJson);
        $sintegraJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_sintegra_completo']);
        $sintegra = self::safeJsonDecode($sintegraJson);
        $relacionamentosJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_relacionamentos']);
        $relacionamentos = self::safeJsonDecode($relacionamentosJson);
        $indicadorJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_indicador']);
        $indicador = self::safeJsonDecode($indicadorJson);

        // =========================================
        // 10.1 NEOCREDIT
        // =========================================
        self::criarSubCard('10.1 NEOCREDIT', [100, 100, 100]);

        self::adicionarLinha('STATUS', self::getValueOrDash(self::$dados, ['campos', 'status']));
        self::adicionarLinha('CLASSIFICAÇÃO', self::getValueOrDash(self::$dados, ['campos', 'classificacao_risco']));
        self::adicionarLinha('SCORE', self::formatarScore(self::getValueOrDash(self::$dados, ['campos', 'score'])));
        self::adicionarLinha('RISCO', self::getValueOrDash(self::$dados, ['campos', 'risco']));
        self::adicionarLinha('LIMITE SUGERIDO', self::formatarMoeda(self::getValueOrDash(self::$dados, ['campos', 'limite_sugerido'])));
        self::adicionarLinha('DATA VALIDADE LIMITE', self::formatarData(self::getValueOrDash(self::$dados, ['campos', 'data_validade_limite_credito']), 'DATA VALIDADE LIMITE'));

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.2 SPC
        // =========================================
        self::criarSubCard('10.2 SPC', [100, 100, 100]);

        if ($spc) {
            self::adicionarLinha('TEMPO ATUACAO MERCADO', self::extrairCampoJson($rf, ['informacoes', 'tempo_mercado']));
            self::adicionarLinha('CAPITAL SOCIAL', self::formatarMoeda(self::extrairCampoJson($rf, ['informacoes', 'capital_social'])));
            self::adicionarLinha('SPC SCORE', self::formatarScore(self::extrairCampoJson($spc, ['score_pj', 'detalhe_score_pj', 'score'])));
            self::adicionarLinha('TAXA INADIMPLENCIA', self::extrairCampoJson($spc, ['tratamentos', 'media_pontualidade']) . '%');
            self::adicionarLinha('SPC LIMITE CREDITO', self::formatarMoeda(self::extrairCampoJson($spc, ['limite_credito_pj', 'resumo', 'valor_total'])));
            self::adicionarLinha('% VARIACAO CONSULTAS', self::extrairCampoJson($spc, ['tratamentos', 'variacao_registro_de_consultas']));

            $restricao = self::extrairCampoJson($spc, ['restricao']);
            self::adicionarLinha('POSSUI RESTRICAO', ($restricao === 'true' || $restricao === true) ? 'SIM' : 'NÃO');

            $restricaoSocio = self::extrairCampoJson($relacionamentos, ['socios_com_restricao']);
            self::adicionarLinha('SPC RESTRICAO SOCIO', $restricaoSocio ? 'SIM' : 'NÃO');

            self::adicionarLinha('CHEQUES SEM FUNDO', self::extrairCampoJson($spc, ['tratamentos', 'quantidade_cheques']));
            self::adicionarLinha('VALOR PROTESTOS', self::formatarMoeda(self::extrairCampoJson($spc, ['tratamentos', 'valor_total_protestos'])));
            self::adicionarLinha('QUANTIDADE PROTESTOS', self::extrairCampoJson($spc, ['tratamentos', 'quantidade_protestos']));
            self::adicionarLinha('DATA PRIMEIRO PROTESTO', self::formatarData(self::extrairCampoJson($spc, ['tratamentos', 'data_primeiro_protesto']), 'DATA PRIMEIRO PROTESTO'));
            self::adicionarLinha('CONTRA ORDEM', self::extrairCampoJson($spc, ['contra_ordem', 'resumo', 'quantidade_total']));
            self::adicionarLinha('ALERTA DOCUMENTOS', self::extrairCampoJson($spc, ['alerta_documento', 'resumo', 'quantidade_total']));
            self::adicionarLinha('PENDENCIAS FINANCEIRAS', self::extrairCampoJson($spc, ['pendencia_financeira', 'resumo', 'quantidade_total']));
            self::adicionarLinha('PARTICIPACAO FALENCIA', self::extrairCampoJson($spc, ['participacao_falencia', 'resumo', 'quantidade_total']));
            self::adicionarLinha('INFO PODER JUDICIÁRIO', self::extrairCampoJson($spc, ['informacao_poder_judiciario', 'resumo', 'quantidade_total']));

            // NOVO: Tabela de Mensagens Complementares
            $mensagens = self::extrairCampoJson($spc, ['mensagem_complementar'], []);

            if (is_array($mensagens) && !empty($mensagens)) {
                // Adiciona um título para a seção de mensagens
                self::$pdf->SetFont('helvetica', 'B', 9);
                self::$pdf->Cell(0, 5, 'MENSAGENS COMPLEMENTARES:', 0, 1, 'L');
                self::$y_position = self::$pdf->GetY() + 2;
                self::$pdf->SetY(self::$y_position);

                // Prepara os dados da tabela
                $dadosTabelaMensagens = [];
                foreach ($mensagens as $msg) {
                    $origem = $msg['origem'] ?? 'Mensagem';
                    $mensagem = $msg['mensagem'] ?? '-';

                    // Se a mensagem contém pipes, pode ser que já esteja formatada
                    // Mas vamos manter como está para exibir na tabela
                    $dadosTabelaMensagens[] = [
                        $origem,
                        $mensagem
                    ];
                }

                // Cria a tabela com as mensagens
                self::criarTabela(
                    ['DESCRIÇÃO', 'MENSAGEM'],
                    $dadosTabelaMensagens,
                    [55, 130] // Larguras ajustadas: 35 para descrição, 130 para mensagem
                );

                self::$y_position += 3;
                self::$pdf->SetY(self::$y_position);
            } else {
                // Se não houver mensagens, mostra uma linha informando
                self::$pdf->SetFont('helvetica', 'I', 9);
                self::$pdf->Cell(0, 6, 'Nenhuma mensagem complementar encontrada', 0, 1, 'C');
                self::$y_position = self::$pdf->GetY() + 3;
                self::$pdf->SetY(self::$y_position);
            }
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados SPC não disponíveis -', 0, 1, 'C');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.3 RECEITA FEDERAL
        // =========================================
        self::criarSubCard('10.3 RECEITA FEDERAL', [100, 100, 100]);

        if ($rf) {
            self::adicionarLinha('RAZAO SOCIAL', self::extrairCampoJson($rf, ['informacoes', 'razao']));
            self::adicionarLinha('NOME FANTASIA', self::extrairCampoJson($rf, ['informacoes', 'fantasia']));
            self::adicionarLinha('DATA FUNDAÇÃO', self::formatarData(self::extrairCampoJson($rf, ['informacoes', 'dt_abertura']), 'DATA FUNDAÇÃO'));
            self::adicionarLinha('PORTE', self::extrairCampoJson($rf, ['informacoes', 'faixa_porte']));
            self::adicionarLinha('SITUAÇÃO CADASTRAL', self::extrairCampoJson($rf, ['informacoes', 'situacao']));
            self::adicionarLinha('CAPITAL SOCIAL RF', self::formatarMoeda(self::extrairCampoJson($rf, ['informacoes', 'capital_social'])));
            self::adicionarLinha('CNPJ', self::formatarDocumento(self::extrairCampoJson($rf, ['informacoes', 'cnpj'])));

            $contribuinte = self::extrairCampoJson($rf, ['informacoes', 'contribuinte']);

            self::adicionarLinha('TIPO CNPJ', self::extrairCampoJson($rf, ['informacoes', 'matriz']) ?: '-');
            self::adicionarLinha('DATA SITUACAO CADASTRAL', self::formatarData(self::extrairCampoJson($rf, ['informacoes', 'data_situacao']), 'DATA SITUACAO CADASTRAL'));
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados Receita Federal não disponíveis -', 0, 1, 'C');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.4 ENDEREÇOS
        // =========================================
        self::criarSubCard('10.4 ENDEREÇOS', [100, 100, 100]);

        $enderecos = [];
        if ($rf && isset($rf['enderecos']) && is_array($rf['enderecos'])) {
            $enderecos = $rf['enderecos'];
        }

        $dadosTabelaEnderecos = [];
        if (!empty($enderecos)) {
            foreach ($enderecos as $end) {
                $dadosTabelaEnderecos[] = [
                    $end['cep'] ?? '-',
                    $end['logradouro'] ?? '-',
                    $end['numero'] ?? '-',
                    $end['bairro'] ?? '-',
                    $end['cidade'] ?? '-',
                    $end['uf'] ?? '-'
                ];
            }
        }

        self::criarTabela(
            ['CEP', 'LOGRADOURO', 'Nº', 'BAIRRO', 'CIDADE', 'UF'],
            $dadosTabelaEnderecos,
            [20, 50, 15, 35, 30, 15]
        );

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.5 ATIVIDADES - CORRIGIDO
        // =========================================
        self::criarSubCard('10.5 ATIVIDADES', [100, 100, 100]);

        $atividades = [];
        if ($rf && isset($rf['cnae']) && is_array($rf['cnae'])) {
            $atividades = $rf['cnae'];
        } elseif ($rf && isset($rf['atividades']) && is_array($rf['atividades'])) {
            $atividades = $rf['atividades'];
        }

        $dadosTabelaAtividades = [];
        if (!empty($atividades)) {
            foreach ($atividades as $ativ) {
                // Extrair os campos conforme especificado
                $codigo = $ativ['cnae'] ?? $ativ['codigo'] ?? '-';
                $descricao = $ativ['descricao'] ?? '-';

                // Campo PRIMÁRIO: se FALSE = NAO, se TRUE = SIM
                $primario = 'NAO'; // Default é NAO

                // Verificar em diferentes possibilidades de nomenclatura
                if (isset($ativ['primario'])) {
                    // Se for string 'true' ou 'false'
                    if (is_string($ativ['primario'])) {
                        $primario = (strtolower($ativ['primario']) === 'true') ? 'SIM' : 'NAO';
                    }
                    // Se for booleano
                    elseif (is_bool($ativ['primario'])) {
                        $primario = $ativ['primario'] ? 'SIM' : 'NAO';
                    }
                    // Se for número (0 ou 1)
                    elseif (is_numeric($ativ['primario'])) {
                        $primario = ($ativ['primario'] == 1) ? 'SIM' : 'NAO';
                    }
                }
                // Verificar outros nomes possíveis para o campo primário
                elseif (isset($ativ['is_primary'])) {
                    if (is_string($ativ['is_primary'])) {
                        $primario = (strtolower($ativ['is_primary']) === 'true') ? 'SIM' : 'NAO';
                    } elseif (is_bool($ativ['is_primary'])) {
                        $primario = $ativ['is_primary'] ? 'SIM' : 'NAO';
                    } elseif (is_numeric($ativ['is_primary'])) {
                        $primario = ($ativ['is_primary'] == 1) ? 'SIM' : 'NAO';
                    }
                } elseif (isset($ativ['principal'])) {
                    if (is_string($ativ['principal'])) {
                        $primario = (strtolower($ativ['principal']) === 'true') ? 'SIM' : 'NAO';
                    } elseif (is_bool($ativ['principal'])) {
                        $primario = $ativ['principal'] ? 'SIM' : 'NAO';
                    } elseif (is_numeric($ativ['principal'])) {
                        $primario = ($ativ['principal'] == 1) ? 'SIM' : 'NAO';
                    }
                }

                $dadosTabelaAtividades[] = [
                    $codigo,
                    $descricao,
                    $primario
                ];
            }
        }

        // Ordenar para mostrar primeiro as atividades primárias (se quiser)
        usort($dadosTabelaAtividades, function ($a, $b) {
            // Se a for SIM e b for NAO, a vem primeiro
            if ($a[2] === 'SIM' && $b[2] === 'NAO') return -1;
            if ($a[2] === 'NAO' && $b[2] === 'SIM') return 1;
            return 0;
        });

        self::criarTabela(
            ['CÓDIGO', 'DESCRICAO', 'PRINCIPAL'],
            $dadosTabelaAtividades,
            [25, 120, 20]
        );

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.6 CONTATO
        // =========================================
        self::criarSubCard('10.6 CONTATO', [100, 100, 100]);

        $email = '-';
        $telefone = '-';

        if ($rf) {

            $emailExtraido = self::extrairCampoJson($rf, ['emails', 0, 'email']);
            if (is_string($emailExtraido) && !empty($emailExtraido)) {
                $email = $emailExtraido;
            }

            $foneFormatado = self::extrairCampoJson($rf, ['telefones', 0, 'fone_formatado']);
            if (is_string($foneFormatado) && !empty($foneFormatado)) {
                $telefone = $foneFormatado;
            }
        }

        self::adicionarLinha('EMAIL PRINCIPAL', $email);
        self::adicionarLinha('TELEFONE', $telefone);

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.7 SIMEI e 10.8 SIMPLES (SIMPLIFICADOS)
        // =========================================
        self::renderizarRegime('10.7 SIMEI', 'simei', $rf);
        self::renderizarRegime('10.8 SIMPLES', 'simples', $rf);

        // =========================================
        // 10.9 SINTEGRA
        // =========================================
        self::criarSubCard('10.9 SINTEGRA', [100, 100, 100]);

        if ($sintegra) {
            self::adicionarLinha('RAZAO SOCIAL', self::extrairCampoJson($sintegra, ['OfficialName']));
            self::adicionarLinha('NOME FANTASIA', self::extrairCampoJson($sintegra, ['BusinessName']));
            self::adicionarLinha('CNPJ', self::formatarDocumento(self::extrairCampoJson($sintegra, ['cnpj'])));
            self::adicionarLinha('DATA CADASTRO', self::formatarData(self::extrairCampoJson($sintegra, ['FoundingDate']), 'DATA CADASTRO SINTEGRA'));
            self::adicionarLinha('ESTADO', self::extrairCampoJson($sintegra, ['address_estado']));
            self::adicionarLinha('CIDADE', self::extrairCampoJson($sintegra, ['address_city']));
            self::adicionarLinha('LOGRADOURO', self::extrairCampoJson($sintegra, ['address_logradouro']));
            self::adicionarLinha('COMPLEMENTO', self::extrairCampoJson($sintegra, ['address_complemento']));
            self::adicionarLinha('PAIS', self::extrairCampoJson($sintegra, ['address_pais']));
            self::adicionarLinha('CEP', self::extrairCampoJson($sintegra, ['address_cep']));
            self::adicionarLinha('BAIRRO', self::extrairCampoJson($sintegra, ['address_bairro']));
            self::adicionarLinha('NUMERO', self::extrairCampoJson($sintegra, ['address_numero']));

            $habilitado = self::extrairCampoJson($sintegra, ['possui_ie_habilitado']);
            self::adicionarLinha('HABILITADO', self::formatarBoolean($habilitado));

            $possuiIeNaoHabilitada = self::extrairCampoJson($sintegra, ['possui_ie_nao_habilitado']);
            self::adicionarLinha('POSSUI IE NÃO HABILITADA?', self::formatarBoolean($possuiIeNaoHabilitada));

            self::adicionarLinha('IE', self::extrairCampoJson($sintegra, ['ie']));
            self::adicionarLinha('REGIME TRIBUTACAO', self::extrairCampoJson($sintegra, ['regime_icms']));
        } else {
            self::adicionarLinha('RAZAO SOCIAL', '-');
            self::adicionarLinha('NOME FANTASIA', '-');
            self::adicionarLinha('CNPJ', '-');
            self::adicionarLinha('DATA CADASTRO', '-');
            self::adicionarLinha('ESTADO', '-');
            self::adicionarLinha('CIDADE', '-');
            self::adicionarLinha('LOGRADOURO', '-');
            self::adicionarLinha('COMPLEMENTO', '-');
            self::adicionarLinha('PAIS', '-');
            self::adicionarLinha('CEP', '-');
            self::adicionarLinha('BAIRRO', '-');
            self::adicionarLinha('NUMERO', '-');
            self::adicionarLinha('HABILITADO', '-');
            self::adicionarLinha('POSSUI IE NAO HABILITADA?', '-');
            self::adicionarLinha('IE', '-');
            self::adicionarLinha('REGIME TRIBUTACAO', '-');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.10 INDICADOR
        // =========================================
        self::criarSubCard('10.10 INDICADOR', [100, 100, 100]);

        if ($indicador) {
            self::adicionarLinha('STATUS', self::extrairCampoJson($indicador, ['divida', 'statusDividas']));
            self::adicionarLinha('VALOR TOTAL REGISTROS', self::formatarMoeda(self::extrairCampoJson($indicador, ['divida', 'valorTotal'])));
            self::adicionarLinha('SALDO DEVEDOR', self::formatarMoeda(self::extrairCampoJson($indicador, ['divida', 'saldoDevedor'])));
            self::adicionarLinha('DATA PRIMEIRA OCORRENCIA', self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataPrimeiraOcorrencia']), 'DATA PRIMEIRA OCORRENCIA'));
            self::adicionarLinha('DATA ULTIMA OCORRENCIA', self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataUltimaOcorrencia']), 'DATA ULTIMA OCORRENCIA'));
            self::adicionarLinha('TITULOS ABERTOS', self::extrairCampoJson($indicador, ['divida', 'quantidadeDividas']));
        } else {
            self::adicionarLinha('STATUS', '-');
            self::adicionarLinha('VALOR TOTAL REGISTROS', '-');
            self::adicionarLinha('SALDO DEVEDOR', '-');
            self::adicionarLinha('DATA PRIMEIRA OCORRENCIA', '-');
            self::adicionarLinha('DATA ULTIMA OCORRENCIA', '-');
            self::adicionarLinha('TITULOS ABERTOS', '-');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.11 CONSULTAS
        // =========================================
        self::criarSubCard('10.11 CONSULTAS', [100, 100, 100]);

        if ($spc) {
            self::adicionarLinha('CONSULTAS TOTAL', self::extrairCampoJson($spc, ['registro_consulta', 'resumo', 'quantidade_total']));
            self::adicionarLinha('CONSULTAS ULTIMOS 30 DIAS', self::extrairCampoJson($spc, ['tratamentos', 'quantidade_de_consultas_30_dias']));
            self::adicionarLinha('CONSULTAS ULTIMOS 90 DIAS', self::extrairCampoJson($spc, ['tratamentos', 'quantidade_de_consultas_90_dias']));
            self::adicionarLinha('CONSULTAS ULTIMOS 3 MESES', self::extrairCampoJson($spc, ['tratamentos', 'total_consultas_3_meses']));
            self::adicionarLinha('% CONSULTAS 3 MESES', self::extrairCampoJson($spc, ['tratamentos', 'percentual_consultas_3_meses']) . '%');

            // Últimas consultas em tabela
            $ultimasConsultas = self::extrairCampoJson($spc, ['ultimas_consultas', 'detalhe_ultimas_consultas'], []);
            $dadosTabelaConsultas = [];

            if (is_array($ultimasConsultas) && !empty($ultimasConsultas)) {
                foreach ($ultimasConsultas as $consulta) {
                    $dadosTabelaConsultas[] = [
                        self::formatarDocumento($consulta['cnpj_cliente'] ?? '-'),
                        self::formatarData($consulta['data_consulta'] ?? '', 'DATA CONSULTA'),
                        $consulta['nome_cliente_consultante'] ?? '-',
                        $consulta['quantidade_consulta'] ?? '1'
                    ];
                }
            }

            self::$pdf->SetFont('helvetica', 'B', 9);
            self::$pdf->Cell(0, 5, 'ÚLTIMAS CONSULTAS:', 0, 1, 'L');
            self::$pdf->SetFont('helvetica', '', 8);

            self::criarTabela(
                ['CNPJ CONSULTANTE', 'DATA', 'NOME', 'QTD'],
                $dadosTabelaConsultas,
                [35, 25, 85, 15]
            );
        } else {
            self::adicionarLinha('CONSULTAS TOTAL', '-');
            self::adicionarLinha('CONSULTAS ULTIMOS 30 DIAS', '-');
            self::adicionarLinha('CONSULTAS ULTIMOS 90 DIAS', '-');
            self::adicionarLinha('CONSULTAS ULTIMOS 3 MESES', '-');
            self::adicionarLinha('% CONSULTAS 3 MESES', '-');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // =========================================
        // 10.12 HISTÓRICO PAGAMENTOS
        // =========================================
        self::criarSubCard('10.12 HISTÓRICO PAGAMENTOS', [100, 100, 100]);

        if ($spc) {

            $qtdfontesinfor = self::extrairCampoJson($spc, ['historico_pagamento_por_faixa', 'quantidade_fontes']);
            $relacionamentoMaisAntigo = self::extrairCampoJson($spc, ['relacionamento_mais_antigo_com_fornecedores', 'detalhe_relacionamento_mais_antigo_com_fornecedores', 'data_relacionamento_mais_antigo']);

            self::adicionarLinha('QTDE FONTES DE INFORMAÇÃO', $qtdfontesinfor);
            self::adicionarLinha('RELACIONAM. FORNECEDORES', self::formatarData($relacionamentoMaisAntigo, 'RELACIONAMENTOS FORNECEDORES'));

            $faixas = self::extrairCampoJson($spc, ['historico_pagamento_por_faixa', 'detalhe_historico_pagamento_por_faixa'], []);

            // Criar tabela com os dados do histórico de pagamentos
            $dadosTabelaHistorico = [];

            if (is_array($faixas) && !empty($faixas)) {
                // Ordenar as faixas por período (assumindo que já vêm em ordem lógica)
                foreach ($faixas as $faixa) {
                    $periodo = $faixa['descricao_periodo'] ?? '-';
                    $qtdDe = $faixa['quantidade_periodo_de'] ?? '-';
                    $qtdAte = $faixa['quantidade_periodo_ate'] ?? '-';
                    $percDe = isset($faixa['percentual_periodo_de']) ? $faixa['percentual_periodo_de'] . '%' : '-';
                    $percAte = isset($faixa['percentual_periodo_ate']) ? $faixa['percentual_periodo_ate'] . '%' : '-';

                    $dadosTabelaHistorico[] = [
                        $periodo,
                        $qtdDe,
                        $qtdAte,
                        $percDe,
                        $percAte
                    ];
                }
            } else {
                // Se não houver dados, adicionar linha informativa
                $dadosTabelaHistorico[] = ['Nenhum registro encontrado', '', '', '', ''];
            }

            self::criarTabela(
                ['PERÍODO', 'QTD DE', 'QTD ATÉ', 'PERCENTUAL DE', 'PERCENTUAL ATÉ'],
                $dadosTabelaHistorico,
                [40, 25, 25, 35, 35] // Larguras ajustadas para caberem na página
            );
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados de histórico de pagamentos não disponíveis -', 0, 1, 'C');
        }

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);

        // Finaliza o card 10
        self::finalizarCard();
    }

    // =========================================
    // CARD HISTÓRICO DE FASES
    // =========================================
    private static function adicionarCardHistoricoFases()
    {
        self::criarCard('11. HISTÓRICO DE FASES', [46, 204, 113]);

        $historico = self::getValueOrDash(self::$dados, 'historico_fases');

        if ($historico === '-' || !is_array($historico)) {
            $historico = [];
        }

        if (!empty($historico)) {
            $dadosTabela = [];
            foreach ($historico as $fase) {
                $dataEntrada = isset($fase['data_entrada']) ? self::formatarData($fase['data_entrada'], 'Data Entrada') : '-';
                $dataSaida = isset($fase['data_saida']) && $fase['data_saida'] ? self::formatarData($fase['data_saida'], 'Data Saída') : '-';
                $atual = ($fase['fase_atual'] ?? false) ? 'Sim' : 'Não';

                $dadosTabela[] = [
                    $fase['nome'] ?? '-',
                    $dataEntrada,
                    $dataSaida,
                    $atual
                ];
            }

            self::criarTabela(
                ['Fase', 'Data Entrada', 'Data Saída', 'Atual'],
                $dadosTabela,
                [50, 45, 45, 25]
            );
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Histórico de fases não disponível -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    /**
     * MÉTODO PRINCIPAL
     */
    public static function gerarPDFDetalhado($dados)
    {
        try {
            self::$dados = $dados;

            self::$pdf = new PDFPersonalizado('P', 'mm', 'A4', true, 'UTF-8', false);

            self::$pdf->SetCreator('NOROAÇO');
            self::$pdf->SetAuthor('NOROAÇO - Sistema de Crédito');
            self::$pdf->SetTitle('Relatório Detalhado de Análise de Crédito');
            self::$pdf->SetSubject('Relatório Completo com todos os campos');

            self::$pdf->setFontSubsetting(true);
            self::$pdf->setFont('helvetica', '', 10, '', true);

            self::$pdf->setPrintHeader(false);
            self::$pdf->setPrintFooter(true);

            self::$pdf->SetMargins(15, 15, 15);
            self::$pdf->SetAutoPageBreak(true, 25);

            self::$pdf->AddPage();
            self::$pdf->SetFont('helvetica', '', 10);

            self::adicionarCardCabecalho();
            self::adicionarCardInformacoesBasicas();
            self::adicionarCardDadosCliente();
            self::adicionarCardDadosRF();
            self::adicionarCardMensagensComplementares();
            self::adicionarCardDadosSintegra();
            self::adicionarCardDadosIndicador();
            self::adicionarCardDadosSPC();
            self::adicionarCardDadosJudiciais();
            self::adicionarCardRelacionamentos();
            self::adicionarCardTodosCamposReformulado(); // NOVO CARD REFORMULADO CORRIGIDO
            self::adicionarCardHistoricoFases();

            return self::$pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro na geração do PDF detalhado: " . $e->getMessage());
        }
    }
}

// =============================================
// EXECUÇÃO PRINCIPAL
// =============================================
try {
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
        throw new Exception("Nenhum dado fornecido para gerar o PDF detalhado");
    }

    if (strpos($dados_json, '%') !== false) {
        $dados_json = urldecode($dados_json);
    }

    $dados = json_decode($dados_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
    }

    $pdf_content = PDFDetalhadoGenerator::gerarPDFDetalhado($dados);

    $razao = isset($dados['campos']['razao']) ?
        preg_replace('/[^a-zA-Z0-9]/', '_', substr($dados['campos']['razao'], 0, 30)) : 'ANALISE';
    $filename = 'Análise PJ - ' . $razao . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf_content;
    exit;
} catch (Exception $e) {
    error_log("ERRO PDF DETALHADO: " . $e->getMessage());

    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro no PDF Detalhado</title>
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
            <h1>Erro ao Gerar PDF Detalhado</h1>
            <div class="message">
                <strong>Erro:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">← Voltar</a>
                <a href="limite_cred.php" class="btn">Nova Consulta</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}
?>