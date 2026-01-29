<?php
session_start();

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (ob_get_length()) ob_clean();

ini_set('memory_limit', '256M');

$texto   = isset($_GET['texto']) ? trim((string)$_GET['texto']) : '';
$altura  = isset($_GET['altura']) ? max(60, min(250, (int)$_GET['altura'])) : 90;
$largura = isset($_GET['largura']) ? max(3.0, min(10.0, (float)$_GET['largura'])) : 4.5;
$tipo    = isset($_GET['tipo']) ? (string)$_GET['tipo'] : '';

if (empty($texto) || $texto === 'LOTE NÃO DEFINIDO' || $texto === 'SEM CÓDIGO') {
    $img = imagecreatetruecolor(400, $altura + 40);
    $white = imagecolorallocate($img, 255, 255, 255);
    $gray = imagecolorallocate($img, 240, 240, 240);
    imagefill($img, 0, 0, $white);
    
    $message = 'SEM CÓDIGO';
    if ($tipo === 'lote' && $texto === 'LOTE NÃO DEFINIDO') {
        $message = 'LOTE NÃO DEFINIDO';
    }
    
    $font = 5;
    $text_width = imagefontwidth($font) * strlen($message);
    $text_x = (400 - $text_width) / 2;
    $text_y = ($altura + 40) / 2 - 10;
    
    imagefilledrectangle($img, $text_x - 10, $text_y - 5, $text_x + $text_width + 10, $text_y + 20, $gray);
    imagestring($img, $font, $text_x, $text_y, $message, imagecolorallocate($img, 100, 100, 100));
    
    imagepng($img);
    imagedestroy($img);
    exit;
}

$code128_char = array(
    0=>'212222', 1=>'222122', 2=>'222221', 3=>'121223', 4=>'121322',
    5=>'131222', 6=>'122213', 7=>'122312', 8=>'132212', 9=>'221213',
    10=>'221312', 11=>'231212', 12=>'112232', 13=>'122132', 14=>'122231',
    15=>'113222', 16=>'123122', 17=>'123221', 18=>'223211', 19=>'221132',
    20=>'221231', 21=>'213212', 22=>'223112', 23=>'312131', 24=>'311222',
    25=>'321122', 26=>'321221', 27=>'312212', 28=>'322112', 29=>'322211',
    30=>'212123', 31=>'212321', 32=>'232121', 33=>'111323', 34=>'131123',
    35=>'131321', 36=>'112313', 37=>'132113', 38=>'132311', 39=>'211313',
    40=>'231113', 41=>'231311', 42=>'112133', 43=>'112331', 44=>'132131',
    45=>'113123', 46=>'113321', 47=>'133121', 48=>'313121', 49=>'211331',
    50=>'231131', 51=>'213113', 52=>'213311', 53=>'213131', 54=>'311123',
    55=>'311321', 56=>'331121', 57=>'312113', 58=>'312311', 59=>'332111',
    60=>'314111', 61=>'221411', 62=>'431111', 63=>'111224', 64=>'111422',
    65=>'121124', 66=>'121421', 67=>'141122', 68=>'141221', 69=>'112214',
    70=>'112412', 71=>'122114', 72=>'122411', 73=>'142112', 74=>'142211',
    75=>'241211', 76=>'221114', 77=>'413111', 78=>'241112', 79=>'134111',
    80=>'111242', 81=>'121142', 82=>'121241', 83=>'114212', 84=>'124112',
    85=>'124211', 86=>'411212', 87=>'421112', 88=>'421211', 89=>'212141',
    90=>'214121', 91=>'412121', 92=>'111143', 93=>'111341', 94=>'131141',
    95=>'114113', 96=>'114311', 97=>'411113', 98=>'411311', 99=>'113141',
    100=>'114131', 101=>'311141', 102=>'411131', 103=>'211412',
    104=>'211214', 105=>'211232', 106=>'211134', 107=>'2331112'
);

function encode_code128($text, $type = 'B') {
    global $code128_char;
    
    $text = (string)$text;
    
    if ($type == 'C') {
        $text = preg_replace('/[^0-9]/', '', $text);
        if (strlen($text) % 2 != 0) {
            $text = '0' . $text;
        }
        $start = 105;
    } elseif ($type == 'A') {
        $start = 104;
    } else {
        $start = 105;
    }
    
    $codes = array($start);
    
    if ($type == 'C') {
        for ($i = 0; $i < strlen($text); $i += 2) {
            $pair = substr($text, $i, 2);
            $codes[] = intval($pair);
        }
    } elseif ($type == 'A') {
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            $ascii = ord($char);
            
            if ($ascii >= 32 && $ascii <= 95) {
                $codes[] = $ascii - 32;
            } elseif ($ascii >= 0 && $ascii <= 31) {
                $codes[] = $ascii + 64;
            } elseif ($ascii >= 96 && $ascii <= 127) {
                $codes[] = $ascii - 32;
            } else {
                $codes[] = 0;
            }
        }
    } else {
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            $ascii = ord($char);
            
            if ($ascii >= 32 && $ascii <= 127) {
                $codes[] = $ascii - 32;
            } else {
                $codes[] = 0;
            }
        }
    }
    
    $checksum = $codes[0];
    for ($i = 1; $i < count($codes); $i++) {
        $checksum += $codes[$i] * $i;
    }
    $checksum = $checksum % 103;
    $codes[] = $checksum;
    
    $codes[] = 106;
    
    $pattern = '';
    foreach ($codes as $code) {
        if (isset($code128_char[$code])) {
            $pattern .= $code128_char[$code];
        }
    }
    
    return $pattern;
}

function get_code_type($text, $original_type) {
    $text = (string)$text;
    
    if ($original_type == 'op') {
        if (preg_match('/^[0-9]+$/', $text) && strlen($text) >= 4) {
            return 'C';
        }
    }
    
    if ($original_type == 'produto') {
        if (preg_match('/^[0-9]+$/', $text) && strlen($text) >= 4) {
            return 'C';
        }
    }
    
    if ($original_type == 'lote') {
        if (preg_match('/^[0-9\-]+$/', $text)) {
            return 'C';
        } elseif (preg_match('/^[A-Z0-9\-]+$/', $text)) {
            return 'B';
        }
    }
    
    if (preg_match('/^[0-9]+$/', $text) && strlen($text) >= 4) {
        return 'C';
    }
    
    return 'B';
}

function criar_barcode_largo($texto, $altura, $largura, $tipo) {
    global $code128_char;
    
    $code_type = get_code_type($texto, $tipo);
    $pattern = encode_code128($texto, $code_type);
    
    $bar_width = strlen($pattern) * $largura;
    $margem_esquerda = 30;
    $margem_direita = 30;
    $margem_superior = 15;
    $margem_inferior = 40;
    
    $total_width = ceil($bar_width + $margem_esquerda + $margem_direita);
    $total_height = $altura + $margem_superior + $margem_inferior;
    
    $img = @imagecreatetruecolor($total_width, $total_height);
    if (!$img) {
        $img = imagecreate($total_width, $total_height);
    }
    
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $dark_black = imagecolorallocate($img, 10, 10, 10);
    $light_gray = imagecolorallocate($img, 250, 250, 250);
    $text_bg = imagecolorallocate($img, 245, 245, 245);
    
    imagefill($img, 0, 0, $white);
    
    $x = $margem_esquerda;
    $y = $margem_superior;
    
    $bar_spacing = 0.5;
    
    for ($i = 0; $i < strlen($pattern); $i++) {
        $bar_size = intval($pattern[$i]) * $largura;
        
        if ($i % 2 === 0) {
            imagefilledrectangle($img, $x, $y, $x + $bar_size, $y + $altura, $dark_black);
            
            if ($bar_size > 3) {
                imagefilledrectangle($img, $x + 1, $y + 1, $x + $bar_size - 1, $y + $altura - 1, $black);
            }
        }
        
        $x += $bar_size;
        
        if ($bar_spacing > 0 && $i < strlen($pattern) - 1) {
            $x += $bar_spacing;
        }
    }
    
    $font_size = 5;
    $text_width = imagefontwidth($font_size) * strlen($texto);
    $text_x = ($total_width - $text_width) / 2;
    $text_y = $altura + $margem_superior + 10;
    
    $bg_width = $text_width + 20;
    $bg_x = ($total_width - $bg_width) / 2;
    
    imagefilledrectangle($img, $bg_x, $text_y - 5, $bg_x + $bg_width, $text_y + 20, $text_bg);
    imagerectangle($img, $bg_x, $text_y - 5, $bg_x + $bg_width, $text_y + 20, imagecolorallocate($img, 200, 200, 200));
    
    imagestring($img, $font_size, $text_x, $text_y, $texto, $dark_black);
    
    imagefilter($img, IMG_FILTER_CONTRAST, -15);
    imagefilter($img, IMG_FILTER_SMOOTH, 0);
    
    return $img;
}

$img = criar_barcode_largo($texto, $altura, $largura, $tipo);
imagepng($img);
imagedestroy($img);
exit;
?>