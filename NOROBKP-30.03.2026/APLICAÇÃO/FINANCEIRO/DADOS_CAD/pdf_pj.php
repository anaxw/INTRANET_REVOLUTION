<?php
// ============================================================================
// pdf_pj.php - Gerador de PDF para Pessoa Jurídica (APENAS DADOS DA API)
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
// CLASSE GERADORA DO PDF SIMPLIFICADO PARA PESSOA JURÍDICA
// ============================================================================
class PDFPJGenerator
{
    private static $pdf;
    private static $dados;
    private static $receita;
    private static $sintegra;
    private static $relacionamentos;
    private static $y_position = 0;

    // Altura estimada para cada card
    private static $alturasCard = [
        'cabecalho' => 30,
        'dados_empresa' => 180,
        'qsa' => 120,
        'endereco' => 80,
        'contato' => 60,
        'atividades' => 100,
        'simei' => 50
    ];

    // Constantes para layout
    const LARGURA_ROTULO = 70;
    const LARGURA_VALOR = 110;
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
        // Se $data não for array, retorna traço
        if (!is_array($data)) {
            return $default;
        }

        // Se for string simples
        if (is_string($keys)) {
            // Verifica se a chave existe e não é vazia/nula
            if (isset($data[$keys]) && $data[$keys] !== '' && $data[$keys] !== null) {
                return $data[$keys];
            }
            return $default;
        }

        // Se for array de chaves (para caminhos aninhados)
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
     * EXTRAI VALOR DA RECEITA FEDERAL
     */
    private static function getReceitaValue($keys, $default = '-')
    {
        return self::getValueOrDash(self::$receita, $keys, $default);
    }

    /**
     * EXTRAI VALOR DO SINTEGRA
     */
    private static function getSintegraValue($keys, $default = '-')
    {
        return self::getValueOrDash(self::$sintegra, $keys, $default);
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
     * FORMATA CNPJ
     */
    private static function formatarCNPJ($cnpj)
    {
        if (!$cnpj || $cnpj === '-' || $cnpj === 'Não informado') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($num) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $num);
        }
        return $cnpj;
    }

    /**
     * FORMATA TELEFONE
     */
    private static function formatarTelefone($telefone)
    {
        if (!$telefone || $telefone === '-' || $telefone === 'Não informado') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $telefone);
        if (strlen($num) === 11) {
            return '(' . substr($num, 0, 2) . ') ' . substr($num, 2, 5) . '-' . substr($num, 7);
        }
        if (strlen($num) === 10) {
            return '(' . substr($num, 0, 2) . ') ' . substr($num, 2, 4) . '-' . substr($num, 6);
        }
        return $telefone;
    }

    /**
     * FORMATA CEP
     */
    private static function formatarCEP($cep)
    {
        if (!$cep || $cep === '-' || $cep === 'Não informado') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $cep);
        if (strlen($num) === 8) {
            return substr($num, 0, 5) . '-' . substr($num, 5, 3);
        }
        return $cep;
    }

    /**
     * FORMATA VALOR MONETÁRIO
     */
    private static function formatarMoeda($valor)
    {
        if (!$valor || $valor === '-' || $valor === 'Não informado' || $valor === 'R$ 0,00') {
            return '-';
        }

        if (strpos($valor, 'R$') !== false) {
            return $valor;
        }

        $valorLimpo = preg_replace('/[^0-9,.-]/', '', $valor);
        $valorLimpo = str_replace(',', '.', $valorLimpo);

        if (is_numeric($valorLimpo)) {
            return 'R$ ' . number_format(floatval($valorLimpo), 2, ',', '.');
        }

        return $valor;
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
        
        // Garantir que o valor seja string e não vazio
        $valorStr = '-';
        if (!is_array($valor) && !is_object($valor)) {
            $valorStr = (string)$valor;
            if ($valorStr === '' || $valorStr === 'null' || $valorStr === null) {
                $valorStr = '-';
            }
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
    private static function criarCard($titulo, $corFundo = [59, 89, 152], $tipoCard = 'dados_empresa')
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

        self::$pdf->SetLineWidth(0.2);
        self::$pdf->SetDrawColor(200, 200, 200);
        self::$pdf->Line(15, self::$y_position, 195, self::$y_position);

        self::$y_position += 8;
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
        self::$pdf->Cell(0, 10, 'RELATÓRIO PESSOA JURÍDICA', 0, 1, 'R');

        self::$pdf->SetFont('helvetica', 'B', 14);
        self::$pdf->SetTextColor(100, 100, 100);

        $razao = self::getValueOrDash(self::$dados, 'razao_social', 'Não informado');
        self::$pdf->Cell(0, 6, $razao, 0, 1, 'R');

        self::$pdf->SetLineWidth(1);
        self::$pdf->SetDrawColor(253, 181, 37);
        self::$pdf->Line(15, 37, 195, 37);

        self::$y_position = 45;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA CARD DADOS DA EMPRESA (COM TODOS OS CAMPOS SOLICITADOS)
     */
    private static function adicionarCardDadosEmpresa()
    {
        self::criarCard('DADOS DA EMPRESA', [41, 128, 185], 'dados_empresa');

        // Coletar todas as informações solicitadas
        $cnpj = self::formatarCNPJ(self::getValueOrDash(self::$dados, 'cnpj', '-'));
        $razaoSocial = self::getValueOrDash(self::$dados, 'razao_social', '-');
        $fantasia = self::getValueOrDash(self::$dados, 'fantasia', '-');
        $dataAbertura = self::formatarData(self::getValueOrDash(self::$dados, 'data_abertura', '-'));
        $tempoMercado = self::getValueOrDash(self::$dados, 'tempo_mercado', '-');
        $porte = self::getValueOrDash(self::$dados, 'porte', '-');
        $naturezaJuridica = self::getValueOrDash(self::$dados, 'natureza_juridica', '-');
        $capitalSocial = self::formatarMoeda(self::getValueOrDash(self::$dados, 'capital_social', '-'));
        $matrizFilial = self::getValueOrDash(self::$dados, 'matriz_filial', '-');
        $dataSituacao = self::formatarData(self::getValueOrDash(self::$dados, 'data_situacao', '-'));
        $inscricaoEstadual = self::getValueOrDash(self::$dados, 'inscricao_estadual', '-');
        $statusIE = self::getValueOrDash(self::$dados, 'status_ie', '-');
        $motivoSituacao = self::getValueOrDash(self::$dados, 'motivo_situacao', '-');
        $situacaoCNPJ = self::getValueOrDash(self::$dados, 'situacao_cnpj', '-');

        // Exibir TODAS as informações solicitadas
        self::escreverLinhaComLabelFixo('CNPJ', $cnpj, true);
        self::escreverLinhaComLabelFixo('Razão Social', $razaoSocial, true);
        self::escreverLinhaComLabelFixo('Nome Fantasia', $fantasia);
        self::escreverLinhaComLabelFixo('Situação do CNPJ', $situacaoCNPJ, $situacaoCNPJ === 'ATIVA');
        self::escreverLinhaComLabelFixo('Data de Abertura', $dataAbertura);
        self::escreverLinhaComLabelFixo('Tempo de Mercado', $tempoMercado);
        self::escreverLinhaComLabelFixo('Porte da Empresa', $porte);
        self::escreverLinhaComLabelFixo('Natureza Jurídica', $naturezaJuridica);
        self::escreverLinhaComLabelFixo('Capital Social', $capitalSocial);
        self::escreverLinhaComLabelFixo('Matriz/Filial', $matrizFilial);
        self::escreverLinhaComLabelFixo('Data da Situação', $dataSituacao);
        self::escreverLinhaComLabelFixo('Inscrição Estadual', $inscricaoEstadual);
        self::escreverLinhaComLabelFixo('Status da IE', $statusIE);
        self::escreverLinhaComLabelFixo('Motivo da Situação', $motivoSituacao);

        self::finalizarCard();
    }

    /**
     * ADICIONA CARD QSA (Quadro de Sócios e Administradores)
     */
    private static function adicionarCardQSA()
    {
        $qsa = self::getReceitaValue('qsa', []);
        
        if (empty($qsa) || !is_array($qsa)) {
            return;
        }

        self::criarCard('QUADRO DE SÓCIOS E ADMINISTRADORES (QSA)', [142, 68, 173], 'qsa');

        foreach ($qsa as $index => $socio) {
            $nome = self::getValueOrDash($socio, 'nome', '-');
            $tipo = self::getValueOrDash($socio, 'tipo', '-');
            $documento = self::getValueOrDash($socio, 'documento', '-');
            $cpfRepresentante = self::getValueOrDash($socio, 'cpf_representante_legal', '-');
            
            // Nome do sócio em destaque
            self::escreverLinhaComLabelFixo('Nome', $nome, true);
            self::escreverLinhaComLabelFixo('Tipo', $tipo);
            self::escreverLinhaComLabelFixo('Documento', $documento);
            
            if ($cpfRepresentante !== '-') {
                self::escreverLinhaComLabelFixo('CPF Representante', $cpfRepresentante);
            }
            
            // Linha separadora entre sócios (exceto após o último)
            if ($index < count($qsa) - 1) {
                self::$y_position += 3;
                self::$pdf->SetY(self::$y_position);
                self::$pdf->SetLineWidth(0.1);
                self::$pdf->SetDrawColor(220, 220, 220);
                self::$pdf->Line(20, self::$y_position, 190, self::$y_position);
                self::$y_position += 5;
                self::$pdf->SetY(self::$y_position);
            }
        }

        self::finalizarCard();
    }

    /**
     * ADICIONA CARD ENDEREÇO (COM COMPLEMENTO)
     */
    private static function adicionarCardEndereco()
    {
        self::criarCard('ENDEREÇO', [52, 152, 219], 'endereco');

        // Coletar informações de endereço
        $logradouro = self::getValueOrDash(self::$dados, 'logradouro_completo', '-');
        $bairro = self::getValueOrDash(self::$dados, 'bairro', '-');
        $cidade = self::getValueOrDash(self::$dados, 'cidade', '-');
        $uf = self::getValueOrDash(self::$dados, 'uf', '-');
        $cep = self::formatarCEP(self::getValueOrDash(self::$dados, 'cep', '-'));
        $complemento = self::getValueOrDash(self::$dados, 'complemento', '-');

        $cidadeUf = $cidade . ($uf !== '-' ? ' - ' . $uf : '');

        self::escreverLinhaComLabelFixo('Logradouro', $logradouro);
        self::escreverLinhaComLabelFixo('Bairro', $bairro);
        self::escreverLinhaComLabelFixo('Cidade/UF', $cidadeUf);
        self::escreverLinhaComLabelFixo('CEP', $cep);
        
        if ($complemento !== '-') {
            self::escreverLinhaComLabelFixo('Complemento', $complemento);
        }

        self::finalizarCard();
    }

    /**
     * ADICIONA CARD CONTATO (TELEFONE E E-MAIL)
     */
    private static function adicionarCardContato()
    {
        self::criarCard('CONTATO', [46, 204, 113], 'contato');

        $telefone = self::formatarTelefone(self::getValueOrDash(self::$dados, 'telefone', '-'));
        $email = self::getValueOrDash(self::$dados, 'email', '-');
        
        self::escreverLinhaComLabelFixo('Telefone', $telefone);
        
        if ($email !== '-') {
            self::escreverLinhaComLabelFixo('E-mail', $email);
        }

        self::finalizarCard();
    }

    /**
     * ADICIONA CARD ATIVIDADES ECONÔMICAS
     */
    private static function adicionarCardAtividades()
    {
        $atividades = self::getReceitaValue('atividades', []);
        
        if (empty($atividades) || !is_array($atividades)) {
            return;
        }

        self::criarCard('ATIVIDADES ECONÔMICAS', [230, 126, 34], 'atividades');

        foreach ($atividades as $index => $atividade) {
            $cnae = self::getValueOrDash($atividade, 'cnae', '-');
            $primario = isset($atividade['primario']) ? ($atividade['primario'] ? 'Principal' : 'Secundária') : '-';
            $descricao = self::getValueOrDash($atividade, 'descricao', '-');
            
            self::escreverLinhaComLabelFixo('CNAE', $cnae);
            self::escreverLinhaComLabelFixo('Tipo', $primario, $atividade['primario'] ?? false);
            self::escreverLinhaComLabelFixo('Descrição', $descricao);
            
            // Linha separadora entre atividades (exceto após a última)
            if ($index < count($atividades) - 1) {
                self::$y_position += 3;
                self::$pdf->SetY(self::$y_position);
                self::$pdf->SetLineWidth(0.1);
                self::$pdf->SetDrawColor(220, 220, 220);
                self::$pdf->Line(20, self::$y_position, 190, self::$y_position);
                self::$y_position += 5;
                self::$pdf->SetY(self::$y_position);
            }
        }

        self::finalizarCard();
    }

    /**
     * ADICIONA CARD SIMPLES/MEI
     */
    private static function adicionarCardSimplesMEI()
    {
        $simeiOptante = self::getReceitaValue(['simei', 'optante'], false);
        $simplesOptante = self::getReceitaValue(['simples', 'optante'], false);
        
        if (!$simeiOptante && !$simplesOptante) {
            return;
        }

        self::criarCard('REGIME TRIBUTÁRIO', [39, 174, 96], 'simei');

        // SIMPLES NACIONAL
        if ($simplesOptante) {
            self::escreverLinhaComLabelFixo('Optante pelo Simples Nacional', 'SIM', true);
            $dataOpcaoSimples = self::formatarData(self::getReceitaValue(['simples', 'data_opcao'], '-'));
            $dataExclusaoSimples = self::formatarData(self::getReceitaValue(['simples', 'data_exclusao'], '-'));
            self::escreverLinhaComLabelFixo('Data de Opção Simples', $dataOpcaoSimples);
            
            if ($dataExclusaoSimples !== '-') {
                self::escreverLinhaComLabelFixo('Data de Exclusão Simples', $dataExclusaoSimples);
            }
        } else {
            self::escreverLinhaComLabelFixo('Optante pelo Simples Nacional', 'NÃO');
        }
        
        // MEI
        if ($simeiOptante) {
            self::escreverLinhaComLabelFixo('Optante pelo MEI', 'SIM', true);
            $dataOpcaoSimei = self::formatarData(self::getReceitaValue(['simei', 'data_opcao'], '-'));
            $dataExclusaoSimei = self::formatarData(self::getReceitaValue(['simei', 'data_exclusao'], '-'));
            self::escreverLinhaComLabelFixo('Data de Opção MEI', $dataOpcaoSimei);
            
            if ($dataExclusaoSimei !== '-') {
                self::escreverLinhaComLabelFixo('Data de Exclusão MEI', $dataExclusaoSimei);
            }
        }

        self::finalizarCard();
    }

    /**
     * MÉTODO PRINCIPAL - GERA O PDF
     */
    public static function gerarPDF($dados)
    {
        try {
            self::$dados = $dados;
            
            // Decodificar o campo receita se existir
            if (isset($dados['receita']) && is_string($dados['receita'])) {
                self::$receita = json_decode($dados['receita'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::$receita = [];
                }
            } else {
                self::$receita = [];
            }
            
            // Decodificar o campo sintegra se existir
            if (isset($dados['sintegra']) && is_string($dados['sintegra'])) {
                self::$sintegra = json_decode($dados['sintegra'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::$sintegra = [];
                }
            } else {
                self::$sintegra = [];
            }

            // Usar a classe personalizada com rodapé automático
            self::$pdf = new PDFSimplificadoVertical('P', 'mm', 'A4', true, 'UTF-8', false);

            self::$pdf->SetCreator('NOROAÇO');
            self::$pdf->SetAuthor('NOROAÇO - Sistema de Crédito');
            self::$pdf->SetTitle('Relatório Pessoa Jurídica - Dados da API');
            self::$pdf->SetSubject('Dados da Consulta de Pessoa Jurídica');

            self::$pdf->setPrintHeader(false);
            self::$pdf->setPrintFooter(true);

            self::$pdf->SetMargins(15, 15, 15);
            self::$pdf->SetAutoPageBreak(true, 30);

            self::$pdf->AddPage();

            // Gerar todos os cards (um embaixo do outro)
            self::adicionarCardCabecalho();
            self::adicionarCardDadosEmpresa();
            self::adicionarCardQSA();
            self::adicionarCardEndereco();
            self::adicionarCardContato();
            self::adicionarCardAtividades();
            self::adicionarCardSimplesMEI();

            return self::$pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro na geração do PDF: " . $e->getMessage());
        }
    }
}

// ============================================================================
// FUNÇÃO PARA EXTRAIR TODOS OS DADOS DO JSON COMPLETO
// ============================================================================
function extrairDadosCompletos($jsonCompleto) {
    $dadosExtraidos = [];
    
    // 1. Extrair dados do campo 'receita'
    if (isset($jsonCompleto['campos']['receita']) && is_string($jsonCompleto['campos']['receita'])) {
        $receita = json_decode($jsonCompleto['campos']['receita'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($receita)) {
            
            // Dados principais da empresa
            if (isset($receita['informacoes'])) {
                $info = $receita['informacoes'];
                $dadosExtraidos['cnpj'] = isset($info['cnpj']) ? $info['cnpj'] : '-';
                $dadosExtraidos['razao_social'] = isset($info['razao']) ? $info['razao'] : '-';
                $dadosExtraidos['fantasia'] = isset($info['fantasia']) ? $info['fantasia'] : '-';
                $dadosExtraidos['data_abertura'] = isset($info['dt_abertura']) ? $info['dt_abertura'] : '-';
                $dadosExtraidos['tempo_mercado'] = isset($info['tempo_mercado']) ? $info['tempo_mercado'] : '-';
                $dadosExtraidos['porte'] = isset($info['faixa_porte']) ? $info['faixa_porte'] : '-';
                $dadosExtraidos['natureza_juridica'] = isset($info['desc_nat_jur']) ? $info['desc_nat_jur'] : '-';
                $dadosExtraidos['capital_social'] = isset($info['capital_social']) ? $info['capital_social'] : '-';
                $dadosExtraidos['situacao_cnpj'] = isset($info['situacao']) ? $info['situacao'] : '-';
                $dadosExtraidos['matriz_filial'] = isset($info['matriz']) ? $info['matriz'] : '-';
                $dadosExtraidos['data_situacao'] = isset($info['data_situacao']) ? $info['data_situacao'] : '-';
                $dadosExtraidos['inscricao_estadual'] = isset($info['inscricao_estadual']) ? $info['inscricao_estadual'] : '-';
                $dadosExtraidos['motivo_situacao'] = isset($info['motivo_situacao']) ? $info['motivo_situacao'] : '-';
            }
            
            // Endereço
            if (isset($receita['enderecos'][0])) {
                $end = $receita['enderecos'][0];
                $logradouro = isset($end['logradouro']) ? $end['logradouro'] : '';
                $numero = isset($end['numero']) && $end['numero'] && $end['numero'] !== '0' ? ', ' . $end['numero'] : '';
                $dadosExtraidos['logradouro_completo'] = $logradouro . $numero;
                $dadosExtraidos['bairro'] = isset($end['bairro']) ? $end['bairro'] : '-';
                $dadosExtraidos['cidade'] = isset($end['cidade']) ? $end['cidade'] : '-';
                $dadosExtraidos['uf'] = isset($end['uf']) ? $end['uf'] : '-';
                $dadosExtraidos['cep'] = isset($end['cep']) ? $end['cep'] : '-';
                $dadosExtraidos['complemento'] = isset($end['complemento']) && $end['complemento'] ? $end['complemento'] : '-';
            }
            
            // Telefone
            if (isset($receita['telefones'][0])) {
                $tel = $receita['telefones'][0];
                $dadosExtraidos['telefone'] = isset($tel['fone_formatado']) ? $tel['fone_formatado'] : 
                                              (isset($tel['fone']) ? $tel['fone'] : '-');
            } else {
                $dadosExtraidos['telefone'] = '-';
            }
            
            // Email
            if (isset($receita['emails'][0])) {
                $dadosExtraidos['email'] = $receita['emails'][0]['email'];
            } else {
                $dadosExtraidos['email'] = '-';
            }
            
            // QSA (Sócios)
            $dadosExtraidos['qsa'] = isset($receita['qsa']) ? $receita['qsa'] : [];
            
            // Atividades
            $dadosExtraidos['atividades'] = isset($receita['atividades']) ? $receita['atividades'] : [];
            
            // Simples e MEI
            $dadosExtraidos['simples'] = isset($receita['simples']) ? $receita['simples'] : [];
            $dadosExtraidos['simei'] = isset($receita['simei']) ? $receita['simei'] : [];
        }
    }
    
    // 2. Extrair dados do Sintegra (IE e status)
    $chaveSintegra = '01KC1EREQJQ5CWYX9VH8DTN17F.resultado_sintegra_completo';
    if (isset($jsonCompleto['campos'][$chaveSintegra]) && is_string($jsonCompleto['campos'][$chaveSintegra])) {
        $sintegra = json_decode($jsonCompleto['campos'][$chaveSintegra], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($sintegra)) {
            $dadosExtraidos['status_ie'] = isset($sintegra['Status']) ? $sintegra['Status'] : '-';
            
            if (isset($sintegra['lista_ie'][0]['ie'])) {
                $dadosExtraidos['inscricao_estadual'] = $sintegra['lista_ie'][0]['ie'];
            }
            
            if (isset($sintegra['Contributorstatus'])) {
                $dadosExtraidos['situacao_cnpj'] = $sintegra['Contributorstatus'];
            }
        }
    }
    
    // 3. Extrair dados dos relacionamentos
    $chaveRelacionamentos = '01KC1EREQJQ5CWYX9VH8DTN17F.resultado_completo_relacionamentos';
    if (isset($jsonCompleto['campos'][$chaveRelacionamentos]) && is_string($jsonCompleto['campos'][$chaveRelacionamentos])) {
        $relacionamentos = json_decode($jsonCompleto['campos'][$chaveRelacionamentos], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($relacionamentos)) {
            $dadosExtraidos['relacionamentos'] = $relacionamentos;
        }
    }
    
    // 4. Adicionar dados básicos
    $dadosExtraidos['id'] = isset($jsonCompleto['id']) ? $jsonCompleto['id'] : null;
    $dadosExtraidos['status'] = isset($jsonCompleto['status']) ? $jsonCompleto['status'] : '-';
    $dadosExtraidos['fase_atual'] = isset($jsonCompleto['fase_atual']) ? $jsonCompleto['fase_atual'] : '-';
    
    return $dadosExtraidos;
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

    $dadosOriginal = json_decode($dados_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
    }

    // Extrair todos os dados da estrutura completa
    $dadosExtraidos = extrairDadosCompletos($dadosOriginal);
    
    // Mesclar com os dados originais para manter compatibilidade
    $dados = array_merge($dadosOriginal, $dadosExtraidos);
    
    // Adicionar também a receita como objeto para o gerador
    if (isset($dadosOriginal['campos']['receita']) && is_string($dadosOriginal['campos']['receita'])) {
        $dados['receita'] = $dadosOriginal['campos']['receita'];
    }
    
    // Adicionar sintegra como objeto para o gerador
    $chaveSintegra = '01KC1EREQJQ5CWYX9VH8DTN17F.resultado_sintegra_completo';
    if (isset($dadosOriginal['campos'][$chaveSintegra]) && is_string($dadosOriginal['campos'][$chaveSintegra])) {
        $dados['sintegra'] = $dadosOriginal['campos'][$chaveSintegra];
    }

    // Verificar se é PJ
    $tipoDocumento = isset($dados['tipo_documento']) ? $dados['tipo_documento'] : 'CNPJ';
    if ($tipoDocumento !== 'CNPJ') {
        throw new Exception("Este relatório é específico para Pessoa Jurídica (CNPJ)");
    }

    // Gerar PDF
    $pdf_content = PDFPJGenerator::gerarPDF($dados);

    // Nome do arquivo
    $razao = isset($dados['razao_social']) ?
        preg_replace('/[^a-zA-Z0-9]/', '_', substr($dados['razao_social'], 0, 30)) : 'PESSOA_JURIDICA';
    $cnpj = isset($dados['cnpj']) ? preg_replace('/[^0-9]/', '', substr($dados['cnpj'], -4)) : 'XXXX';
    $filename = 'Dados_PJ_' . $razao . '_' . $cnpj . '_' . date('Ymd') . '.pdf';

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
    error_log("ERRO PDF PJ: " . $e->getMessage());

    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro no PDF - Pessoa Jurídica</title>
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
            <h1>Erro ao Gerar PDF - Pessoa Jurídica</h1>
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