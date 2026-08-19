<?php
/* O molde dos e-mails.
 *
 * Um só, usado por todos. Desenhado na página "08 — E-mail / Conta" do
 * Figma, seguindo o "07 — E-mail / Pedido recebido" que já existia.
 *
 * Tudo em tabela e estilo embutido porque cliente de e-mail é o
 * navegador de 2005: Outlook ignora folha externa, Gmail remove <style>
 * em alguns modos, e metade deles não sabe flexbox.
 *
 * As fontes da marca não existem em cliente de e-mail. A pilha cai em
 * Arial, que é o certo: e-mail não é lugar de webfont, porque o Outlook
 * ignora e o Gmail baixa só às vezes. O peso e o tamanho seguram a
 * identidade sozinhos.                                                 */
if (!defined('ABSPATH')) exit;

const OVR_TITULO_FONTE = "'Space Grotesk','Arial Black',Arial,Helvetica,sans-serif";
const OVR_CORPO_FONTE  = "Inter,-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
const OVR_TINTA        = '#090a0c';
const OVR_TINTA_FRACA  = '#6b6b66';
const OVR_SUPERFICIE   = '#f4f2ed';
const OVR_VOLT         = '#c9ff00';

/* --- os tijolos ---------------------------------------------------- */

function ovr_email_kicker(string $t): string {
    return '<p style="margin:0 0 16px;font-family:' . OVR_TITULO_FONTE . ';font-size:11px;font-weight:700;'
         . 'letter-spacing:.12em;text-transform:uppercase;color:' . OVR_TINTA . '">' . esc_html($t) . '</p>';
}

function ovr_email_titulo(string $t): string {
    return '<h1 style="margin:0 0 20px;font-family:' . OVR_TITULO_FONTE . ';font-size:30px;line-height:1.2;'
         . 'font-weight:700;letter-spacing:-.02em;color:' . OVR_TINTA . '">' . esc_html($t) . '</h1>';
}

function ovr_email_paragrafo(string $t, int $tam = 16): string {
    return '<p style="margin:0 0 24px;font-family:' . OVR_CORPO_FONTE . ';font-size:' . $tam . 'px;'
         . 'line-height:1.6;color:' . OVR_TINTA . '">' . nl2br(esc_html($t)) . '</p>';
}

/* A caixa de destaque. `$codigo` é a linha grande, para cupom; sem ele
   a caixa serve de resumo comum. */
function ovr_email_caixa(string $titulo, string $corpo, string $codigo = ''): string {
    $h  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' style="margin:0 0 24px"><tr><td style="background:' . OVR_SUPERFICIE . ';padding:24px">'
        . '<p style="margin:0 0 12px;font-family:' . OVR_TITULO_FONTE . ';font-size:11px;font-weight:700;'
        . 'letter-spacing:.12em;text-transform:uppercase;color:' . OVR_TINTA_FRACA . '">' . esc_html($titulo) . '</p>';
    if ($codigo !== '') {
        /* O código é a única coisa que a pessoa precisa copiar. Sai da
           massa de texto e ganha linha própria, como no desenho. */
        $h .= '<p style="margin:0 0 12px;font-family:' . OVR_TITULO_FONTE . ';font-size:26px;font-weight:700;'
            . 'letter-spacing:.04em;color:' . OVR_TINTA . '">' . esc_html($codigo) . '</p>';
    }
    return $h . '<p style="margin:0;font-family:' . OVR_CORPO_FONTE . ';font-size:14px;line-height:1.6;'
        . 'color:' . OVR_TINTA . '">' . nl2br(esc_html($corpo)) . '</p></td></tr></table>';
}

/* Botão em tabela, não <a> com padding: o Outlook come o padding de
   âncora e o botão vira link solto no meio do texto. */
function ovr_email_botao(string $rotulo, string $url): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px">'
         . '<tr><td style="background:' . OVR_VOLT . '"><a href="' . esc_url($url) . '"'
         . ' style="display:inline-block;padding:16px 26px;font-family:' . OVR_TITULO_FONTE . ';font-size:13px;'
         . 'font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . OVR_TINTA . ';'
         . 'text-decoration:none">' . esc_html($rotulo) . '</a></td></tr></table>';
}

function ovr_email_nota(string $t): string {
    return '<p style="margin:0;font-family:' . OVR_CORPO_FONTE . ';font-size:11px;line-height:1.6;'
         . 'color:' . OVR_TINTA_FRACA . '">' . esc_html($t) . '</p>';
}

/* --- a moldura ----------------------------------------------------- */

function ovr_email_moldura(string $miolo, string $rodape): string {
    return '<div style="margin:0;padding:32px 12px;background:#fbfaf6">'
    /* 620 é a largura do desenho. Acima de 640 o Outlook começa a
       quebrar a tabela, então este é o teto prático. */
     . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" align="center"'
     . ' style="width:620px;max-width:100%;background:#ffffff;border:1px solid #e6e3db">'

     . '<tr><td style="background:' . OVR_TINTA . ';padding:32px 40px">'
     . '<p style="margin:0 0 8px;font-family:' . OVR_TITULO_FONTE . ';font-size:36px;font-weight:700;'
     . 'letter-spacing:-.02em;color:' . OVR_VOLT . '">OVR</p>'
     . '<p style="margin:0;font-family:' . OVR_TITULO_FONTE . ';font-size:10px;font-weight:700;'
     . 'letter-spacing:.16em;text-transform:uppercase;color:#fbfaf6">Camisetas personalizadas</p>'
     . '</td></tr>'

     . '<tr><td style="padding:40px">' . $miolo . '</td></tr>'

     . '<tr><td style="background:' . OVR_SUPERFICIE . ';padding:24px 40px">'
     . '<p style="margin:0;font-family:' . OVR_CORPO_FONTE . ';font-size:11px;line-height:1.6;'
     . 'color:' . OVR_TINTA_FRACA . '">' . esc_html($rodape) . '</p>'
     . '</td></tr></table></div>';
}

function ovr_email_enviar(string $para, string $assunto, string $html): bool {
    return wp_mail($para, $assunto, $html, ['Content-Type: text/html; charset=UTF-8']);
}
