<?php
/**
 * print-quota — Command Center RX Job Accounting client.
 * Copyright (c) Gelsomino Lullo. MIT License.
 * Ceiling values are arguments: this file does not hardcode a product step.
 */
function pqLog($line)
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/ccrx.log', date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
}
function fotocopiaLogFile($riga) { pqLog($riga); }
function fotocopiaNomeComeScritto($cognome, $nome)
{
    $testo = trim(preg_replace('/\s+/u', ' ', $cognome . ' ' . $nome));
    if (function_exists('mb_substr')) {
        return mb_substr($testo, 0, 32, 'UTF-8');
    }
    return substr($testo, 0, 32);
}

function fotocopiaNomeAccount($cognome, $nome)
{
    $testo = fotocopiaNomeComeScritto($cognome, $nome);
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($testo, 'UTF-8');
    }
    return strtoupper($testo);
}

function fotocopiaAssoluto($basePagina, $href)
{
    $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    if ($href === '' || stripos($href, 'javascript:') === 0) {
        return '';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $parti = parse_url($basePagina);
    $schema = $parti['scheme'] . '://' . $parti['host'];
    if ($href[0] === '/') {
        return $schema . $href;
    }
    $dir = isset($parti['path']) ? preg_replace('#/[^/]*$#', '/', $parti['path']) : '/';
    $path = $dir . $href;
    $pezzi = [];
    foreach (explode('/', $path) as $pezzo) {
        if ($pezzo === '' || $pezzo === '.') {
            continue;
        }
        if ($pezzo === '..') {
            array_pop($pezzi);
            continue;
        }
        $pezzi[] = $pezzo;
    }
    return $schema . '/' . implode('/', $pezzi);
}

function fotocopiaQuery(array $campi)
{
    $parti = [];
    foreach ($campi as $nome => $valore) {
        $lista = is_array($valore) ? $valore : [$valore];
        foreach ($lista as $voce) {
            $parti[] = rawurlencode((string) $nome) . '=' . rawurlencode((string) $voce);
        }
    }
    return implode('&', $parti);
}

function fotocopiaHttp($url, $cookie, $ua, $post, $referer)
{
    $ch = curl_init($url);
    $opzioni = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ];
    if ($referer) {
        $opzioni[CURLOPT_REFERER] = $referer;
    }
    if ($post !== null) {
        $opzioni[CURLOPT_POST] = true;
        $opzioni[CURLOPT_POSTFIELDS] = fotocopiaQuery($post);
    }
    curl_setopt_array($ch, $opzioni);
    $raw = curl_exec($ch);
    $errore = curl_error($ch);
    $codice = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $urlEffettivo = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($raw === false) {
        return ['codice' => 0, 'corpo' => '', 'errore' => $errore !== '' ? $errore : 'Connessione non riuscita', 'url' => ''];
    }
    return [
        'codice' => $codice,
        'corpo' => substr($raw, $headerSize),
        'errore' => '',
        'url' => $urlEffettivo,
    ];
}

function fotocopiaDom($html)
{
    $dom = new DOMDocument();
    $precedenti = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($precedenti);
    return $dom;
}

function fotocopiaTesto($nodo)
{
    $testo = trim(preg_replace('/\s+/u', ' ', $nodo->textContent));
    return $testo;
}

function fotocopiaPaginaLoginRifiutata($html)
{
    if (stripos($html, 'forced to logout') !== false) {
        return true;
    }
    if (stripos($html, '<title>Logout</title>') !== false) {
        return true;
    }
    if (strpos($html, '_passerr_') !== false || strpos($html, '_login_err_') !== false || strpos($html, '_gen_err_') !== false || strpos($html, '_acc_err_') !== false) {
        return true;
    }
    return false;
}

function fotocopiaAssicuraCookieRtl($file, $ip)
{
    $testo = is_file($file) ? (string) file_get_contents($file) : '';
    if (strpos($testo, "\trtl\t") !== false) {
        return;
    }
    if (strpos($testo, 'Netscape HTTP Cookie File') === false) {
        $testo = "# Netscape HTTP Cookie File\n";
    }
    $testo .= $ip . "\tFALSE\t/\tFALSE\t0\trtl\t0\n";
    @file_put_contents($file, $testo);
}

function fotocopiaLeggiModello($base, $cookie, $ua)
{
    $pagina = fotocopiaHttp($base . '/js/jssrc/model/startwlm/Start_Wlm.model.htm', $cookie, $ua, null, $base . '/startwlm/Start_Wlm.htm');
    $valori = [];
    if ($pagina['codice'] !== 200) {
        return $valori;
    }
    if (preg_match_all("/_pp\.([A-Za-z0-9_]+)\s*=\s*(?:parseInt\('([^']*)',\s*10\)|'([^']*)')/", $pagina['corpo'], $trovati, PREG_SET_ORDER)) {
        foreach ($trovati as $riga) {
            $valori[$riga[1]] = $riga[2] !== '' ? $riga[2] : $riga[3];
        }
    }
    return $valori;
}

function fotocopiaTipoLoginAdmin(array $modello)
{
    $auth = isset($modello['f_getAuthModeIndex']) ? $modello['f_getAuthModeIndex'] : '_mode_off';
    if ($auth === '' || $auth === '_mode_off') {
        return '_mode_off';
    }
    return $auth;
}

function fotocopiaSessioneAdmin(array $modello)
{
    $info = isset($modello['f_getLoginInfo']) ? (string) $modello['f_getLoginInfo'] : '';
    $msg = isset($modello['msg1']) ? (string) $modello['msg1'] : '';
    if ($msg !== '' && isset($msg[0]) && $msg[0] === '_') {
        return false;
    }
    return $info !== '' && $info !== '1' && $info !== '8';
}

function fotocopiaErroreLogin(array $modello)
{
    $msg = isset($modello['msg1']) ? (string) $modello['msg1'] : '';
    if ($msg === '_passerr_' || $msg === '_kmpm_name_pass_err_') {
        return 'Password non accettata. Usare l\'utente Admin di Command Center RX, lo stesso della linguetta Admin';
    }
    if ($msg === '_login_err_' || $msg === '_acc_err_') {
        return 'Utente non accettato dalla stampante';
    }
    if ($msg !== '') {
        return 'Accesso rifiutato (' . $msg . ')';
    }
    return 'Accesso rifiutato. Controllare utente e password del fotocopiatore';
}

function fotocopiaCcrxApri($ip, $utente, $password)
{
    $ip = trim((string) $ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'errore' => 'Indirizzo IP non valido'];
    }
    if (trim((string) $utente) === '' || (string) $password === '') {
        return ['ok' => false, 'errore' => 'Credenziali mancanti nella scheda del fotocopiatore'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'errore' => 'Estensione cURL non disponibile sul server'];
    }

    $base = 'https://' . $ip;
    $cookie = tempnam(sys_get_temp_dir(), 'fccrx');
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    // La pagina di login risponde 500 se manca il cookie impostato dalla home.
    fotocopiaHttp($base . '/', $cookie, $ua, null, $base . '/');
    fotocopiaAssicuraCookieRtl($cookie, $ip);
    $home = fotocopiaHttp($base . '/startwlm/Start_Wlm.htm', $cookie, $ua, null, $base . '/');
    if ($home['codice'] !== 200 || strlen($home['corpo']) < 1000) {
        @unlink($cookie);
        $dettaglio = $home['errore'] !== '' ? $home['errore'] : ('HTTP ' . $home['codice']);
        return ['ok' => false, 'errore' => 'Stampante non raggiungibile (' . $dettaglio . ')'];
    }

    $modello = fotocopiaLeggiModello($base, $cookie, $ua);
    $tipo = fotocopiaTipoLoginAdmin($modello);
    $nascosto = isset($modello['hiddenvalue']) ? $modello['hiddenvalue'] : '';

    $login = fotocopiaHttp($base . '/startwlm/login.cgi', $cookie, $ua, [
        'failhtmfile' => '/startwlm/Start_Wlm.htm',
        'okhtmfile' => '/startwlm/Start_Wlm.htm',
        'func' => 'authLogin',
        'arg03_LoginType' => $tipo,
        'arg04_LoginFrom' => '_wlm_login',
        'arg01_UserName' => $utente,
        'arg02_Password' => $password,
        'arg05_AccountId' => '',
        'arg06_DomainName' => '',
        'hidden' => $nascosto,
        'Login' => 'Login',
    ], $base . '/startwlm/Start_Wlm.htm');

    $dopo = fotocopiaLeggiModello($base, $cookie, $ua);
    $entrato = $login['codice'] === 200 && fotocopiaSessioneAdmin($dopo);
    if (!$entrato) {
        $msg = isset($dopo['msg1']) ? $dopo['msg1'] : '';
        $info = isset($dopo['f_getLoginInfo']) ? $dopo['f_getLoginInfo'] : '';
        fotocopiaLogFile('login rifiutato ip=' . $ip . ' http=' . $login['codice'] . ' tipo=' . $tipo . ' info=' . $info . ' msg=' . $msg);
        @unlink($cookie);
        return ['ok' => false, 'errore' => fotocopiaErroreLogin($dopo)];
    }

    return [
        'ok' => true,
        'errore' => '',
        'base' => $base,
        'cookie' => $cookie,
        'ua' => $ua,
        'lista' => $base . '/mngset/jobset/MngSet_JobAcc_JobAccLst.htm?arg1=1&arg2=0&arg3=&arg4=0&arg5=&arg6=&arg7=1&arg8=0&arg9=',
    ];
}

function fotocopiaCcrxChiudi($sessione)
{
    if (empty($sessione['ok'])) {
        return;
    }
    fotocopiaHttp($sessione['base'] . '/startwlm/login.cgi', $sessione['cookie'], $sessione['ua'], [
        'func' => 'authLogout',
        'okhtmfile' => '/startwlm/Start_Wlm.htm',
        'failhtmfile' => '/startwlm/Start_Wlm.htm',
    ], $sessione['base'] . '/startwlm/Start_Wlm.htm');
    if (!empty($sessione['cookie']) && is_file($sessione['cookie'])) {
        @unlink($sessione['cookie']);
    }
}

function fotocopiaStessoCodice($letto, $codice)
{
    $letto = ltrim((string) $letto, '0');
    $codice = ltrim((string) $codice, '0');
    if ($letto === '') {
        $letto = '0';
    }
    if ($codice === '') {
        $codice = '0';
    }
    return $letto === $codice;
}

function fotocopiaEstraiConti($html, $urlPagina)
{
    $dom = fotocopiaDom($html);
    $conti = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $id = '';
        $hrefs = [];
        foreach ($tr->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type')) === 'checkbox') {
                $valore = trim($input->getAttribute('value'));
                if (preg_match('/^\d{1,8}$/', $valore)) {
                    $id = $valore;
                }
            }
        }
        foreach ($tr->getElementsByTagName('a') as $ancora) {
            $href = trim($ancora->getAttribute('href'));
            if ($href !== '') {
                $hrefs[] = fotocopiaAssoluto($urlPagina, $href);
            }
            $etichetta = trim($ancora->textContent);
            if ($id === '' && preg_match('/^\d{1,8}$/', $etichetta)) {
                $id = $etichetta;
            }
        }
        if ($id === '') {
            continue;
        }
        $testo = fotocopiaTesto($tr);
        $nome = '';
        $schema = '/' . preg_quote($id, '/') . '\s+(.+?)(?:\s+(?:Contatore|Counter)|$)/u';
        if (preg_match($schema, $testo, $nomeTrovato)) {
            $nome = trim($nomeTrovato[1]);
        }
        if (!isset($conti[$id])) {
            $conti[$id] = ['id' => $id, 'nome' => $nome, 'hrefs' => []];
        }
        if ($nome !== '' && $conti[$id]['nome'] === '') {
            $conti[$id]['nome'] = $nome;
        }
        $conti[$id]['hrefs'] = array_values(array_unique(array_merge($conti[$id]['hrefs'], $hrefs)));
    }
    return $conti;
}

function fotocopiaTrovaConto(array $conti, $codice)
{
    foreach ($conti as $id => $conto) {
        if ((string) $id === (string) $codice || fotocopiaStessoCodice($id, $codice)) {
            return $conto;
        }
    }
    return null;
}

function fotocopiaLinkAggiunta($html, $urlPagina)
{
    if (!preg_match_all('/(?:href|action)\s*=\s*["\']([^"\']+)["\']|location\.href\s*=\s*["\']([^"\']+)["\']/i', $html, $trovati, PREG_SET_ORDER)) {
        return '';
    }
    foreach ($trovati as $voce) {
        $href = $voce[1] !== '' ? $voce[1] : $voce[2];
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (!preg_match('/New|Add|Insert|Crea|Aggiungi/i', $href)) {
            continue;
        }
        if (preg_match('/JobAcc|jobacc|jobset/i', $href) && !preg_match('/Cnt|Cntr|Counter|Contat|Lst\.htm|Set\.htm/i', $href)) {
            return fotocopiaAssoluto($urlPagina, $href);
        }
    }
    return '';
}

function fotocopiaScegliHref(array $hrefs, $conta)
{
    $scelto = '';
    foreach ($hrefs as $href) {
        $eContatore = preg_match('/Cnt|Cntr|Counter|Contat/i', $href);
        if ($conta && $eContatore) {
            return $href;
        }
        if (!$conta && !$eContatore) {
            $scelto = $href;
        }
    }
    return $scelto;
}

function fotocopiaControlliForm(DOMElement $form)
{
    $campi = [];
    $invio = [];
    foreach ($form->getElementsByTagName('input') as $input) {
        if ($input->hasAttribute('disabled')) {
            continue;
        }
        $nome = $input->getAttribute('name');
        if ($nome === '') {
            continue;
        }
        $tipo = strtolower($input->getAttribute('type'));
        if ($tipo === 'submit' || $tipo === 'button' || $tipo === 'image' || $tipo === 'reset') {
            $invio[] = ['nome' => $nome, 'valore' => $input->getAttribute('value'), 'tipo' => $tipo];
            continue;
        }
        if (($tipo === 'checkbox' || $tipo === 'radio') && !$input->hasAttribute('checked')) {
            continue;
        }
        $campi[$nome] = $input->getAttribute('value');
    }
    foreach ($form->getElementsByTagName('textarea') as $area) {
        $nome = $area->getAttribute('name');
        if ($nome !== '' && !$area->hasAttribute('disabled')) {
            $campi[$nome] = $area->textContent;
        }
    }
    foreach ($form->getElementsByTagName('select') as $select) {
        $nome = $select->getAttribute('name');
        if ($nome === '' || $select->hasAttribute('disabled')) {
            continue;
        }
        $valore = '';
        foreach ($select->getElementsByTagName('option') as $opzione) {
            if ($opzione->hasAttribute('selected')) {
                $valore = $opzione->getAttribute('value');
            }
            if ($valore === '' && $valore !== '0') {
                $valore = $opzione->getAttribute('value');
            }
        }
        $campi[$nome] = $valore;
    }
    return ['campi' => $campi, 'invio' => $invio, 'action' => $form->getAttribute('action')];
}

function fotocopiaFormPrincipale(DOMDocument $dom)
{
    $migliore = null;
    foreach ($dom->getElementsByTagName('form') as $form) {
        $azione = $form->getAttribute('action');
        if (stripos($azione, 'login.cgi') !== false) {
            continue;
        }
        $n = $form->getElementsByTagName('input')->length + $form->getElementsByTagName('select')->length;
        if ($migliore === null || $n > $migliore['n']) {
            $migliore = ['n' => $n, 'form' => $form];
        }
    }
    return $migliore ? $migliore['form'] : null;
}

function fotocopiaPunteggioOpzione($testo, $valore)
{
    $t = function_exists('mb_strtolower') ? mb_strtolower($testo . ' ' . $valore, 'UTF-8') : strtolower($testo . ' ' . $valore);
    $punti = 0;
    if (preg_match('/limit|limite|counter|contator|restriz/u', $t)) {
        $punti += 3;
    }
    if (preg_match('/reject|rifiut|vietat|off|disatt|nessun/u', $t)) {
        $punti -= 4;
    }
    if ((string) $valore === '2') {
        $punti += 1;
    }
    return $punti;
}

function fotocopiaImpostaTetto(DOMElement $form, array $campi, $tetto)
{
    $toccati = 0;
    $valoriScritti = 0;
    foreach ($form->getElementsByTagName('select') as $select) {
        $nome = $select->getAttribute('name');
        if ($nome === '') {
            continue;
        }
        $opzioni = [];
        foreach ($select->getElementsByTagName('option') as $opzione) {
            $opzioni[] = [
                'valore' => $opzione->getAttribute('value'),
                'testo' => trim($opzione->textContent),
                'punti' => fotocopiaPunteggioOpzione(trim($opzione->textContent), $opzione->getAttribute('value')),
            ];
        }
        if (count($opzioni) < 2) {
            continue;
        }
        $migliore = $opzioni[0];
        foreach ($opzioni as $opzione) {
            if ($opzione['punti'] > $migliore['punti']) {
                $migliore = $opzione;
            }
        }
        if ($migliore['punti'] < 1) {
            continue;
        }
        $campi[$nome] = $migliore['valore'];
        $toccati++;
        $riga = $select;
        while ($riga && strtolower($riga->nodeName) !== 'tr') {
            $riga = $riga->parentNode;
        }
        if ($riga) {
            foreach ($riga->getElementsByTagName('input') as $input) {
                $tipo = strtolower($input->getAttribute('type'));
                $nomeInput = $input->getAttribute('name');
                if ($nomeInput === '' || $tipo === 'hidden' || $tipo === 'checkbox' || $tipo === 'radio' || $tipo === 'submit') {
                    continue;
                }
                $campi[$nomeInput] = (string) $tetto;
                $valoriScritti++;
            }
        }
    }
    if ($valoriScritti === 0) {
        foreach ($form->getElementsByTagName('input') as $input) {
            $nomeInput = $input->getAttribute('name');
            $tipo = strtolower($input->getAttribute('type'));
            if ($nomeInput === '' || $tipo === 'hidden' || $tipo === 'checkbox' || $tipo === 'radio' || $tipo === 'submit') {
                continue;
            }
            if (preg_match('/lmt|limit|max|quota/i', $nomeInput)) {
                $campi[$nomeInput] = (string) $tetto;
                $valoriScritti++;
            }
        }
    }
    if ($toccati === 0 && $valoriScritti === 0) {
        foreach ($campi as $nome => $valore) {
            if (preg_match('/lmt|limit|max|quota/i', $nome)) {
                $campi[$nome] = (string) $tetto;
                $toccati++;
            }
        }
    }
    return [$campi, $toccati + $valoriScritti];
}

function fotocopiaImpostaIdentita(DOMElement $form, array $campi, $codice, $nomeAccount)
{
    $campoId = '';
    $campoNome = '';
    foreach ($form->getElementsByTagName('input') as $input) {
        $tipo = strtolower($input->getAttribute('type'));
        if ($tipo === 'hidden' || $tipo === 'checkbox' || $tipo === 'radio' || $tipo === 'submit' || $tipo === 'button') {
            continue;
        }
        $nome = $input->getAttribute('name');
        if ($nome === '') {
            continue;
        }
        $max = (int) $input->getAttribute('maxlength');
        if ($campoId === '' && preg_match('/id|code|acct/i', $nome) && ($max === 0 || $max <= 8)) {
            $campoId = $nome;
        }
        if ($campoNome === '' && preg_match('/name|nome|nm/i', $nome) && !preg_match('/user|login|domain/i', $nome)) {
            $campoNome = $nome;
        }
    }
    if ($campoId === '') {
        foreach ($form->getElementsByTagName('input') as $input) {
            $max = (int) $input->getAttribute('maxlength');
            $nome = $input->getAttribute('name');
            $tipo = strtolower($input->getAttribute('type'));
            if ($nome !== '' && $tipo !== 'hidden' && $tipo !== 'submit' && $tipo !== 'button' && $max > 0 && $max <= 8) {
                $campoId = $nome;
                break;
            }
        }
    }
    if ($campoNome === '') {
        foreach ($form->getElementsByTagName('input') as $input) {
            $max = (int) $input->getAttribute('maxlength');
            $nome = $input->getAttribute('name');
            $tipo = strtolower($input->getAttribute('type'));
            if ($nome !== '' && $nome !== $campoId && $tipo !== 'hidden' && $max >= 16) {
                $campoNome = $nome;
                break;
            }
        }
    }
    if ($campoId !== '') {
        $campi[$campoId] = (string) $codice;
    }
    if ($campoNome !== '' && $nomeAccount !== null) {
        $maxNome = 32;
        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('name') === $campoNome && (int) $input->getAttribute('maxlength') > 0) {
                $maxNome = (int) $input->getAttribute('maxlength');
            }
        }
        $campi[$campoNome] = function_exists('mb_substr')
            ? mb_substr($nomeAccount, 0, $maxNome, 'UTF-8')
            : substr($nomeAccount, 0, $maxNome);
    }
    return [$campi, $campoId !== ''];
}

function fotocopiaLeggiTetto(DOMDocument $dom, $codice)
{
    $form = fotocopiaFormPrincipale($dom);
    if (!$form) {
        return null;
    }
    $letti = fotocopiaControlliForm($form);
    $limiti = [];
    foreach ($form->getElementsByTagName('select') as $select) {
        $nome = $select->getAttribute('name');
        $selezionata = isset($letti['campi'][$nome]) ? (string) $letti['campi'][$nome] : '';
        $testo = '';
        foreach ($select->getElementsByTagName('option') as $opzione) {
            if ((string) $opzione->getAttribute('value') === $selezionata) {
                $testo = trim($opzione->textContent);
            }
        }
        if (fotocopiaPunteggioOpzione($testo, $selezionata) < 1) {
            continue;
        }
        $riga = $select;
        while ($riga && strtolower($riga->nodeName) !== 'tr') {
            $riga = $riga->parentNode;
        }
        if (!$riga) {
            continue;
        }
        foreach ($riga->getElementsByTagName('input') as $input) {
            $valore = trim($input->getAttribute('value'));
            if ($valore !== '' && preg_match('/^\d{1,8}$/', $valore) && !fotocopiaStessoCodice($valore, $codice)) {
                $limiti[] = (int) $valore;
            }
        }
    }
    if (!$limiti) {
        foreach ($letti['campi'] as $nome => $valore) {
            if (preg_match('/lmt|limit|max|quota/i', $nome) && preg_match('/^\d{1,8}$/', (string) $valore)) {
                $limiti[] = (int) $valore;
            }
        }
    }
    if (!$limiti) {
        return null;
    }
    return min($limiti);
}

function fotocopiaLeggiCopie(DOMDocument $dom, $codice)
{
    $totale = null;
    $parti = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $testo = fotocopiaTesto($tr);
        if ($testo === '' || !preg_match_all('/\d{1,8}/', $testo, $numeri)) {
            continue;
        }
        $basso = function_exists('mb_strtolower') ? mb_strtolower($testo, 'UTF-8') : strtolower($testo);
        if (preg_match('/scan|scans|fax/u', $basso) && !preg_match('/stamp|print|copi/u', $basso)) {
            continue;
        }
        $n = null;
        foreach ($numeri[0] as $cifre) {
            if (fotocopiaStessoCodice($cifre, $codice)) {
                continue;
            }
            $n = (int) $cifre;
        }
        if ($n === null) {
            continue;
        }
        if (preg_match('/totale|total|pagine stampate|printed pages|copy\/print|copie\/stampe/u', $basso)) {
            $totale = $n;
        } elseif (preg_match('/stamp|print|copi|copia|pagine/u', $basso)) {
            $parti[] = $n;
        }
    }
    if ($totale !== null) {
        return $totale;
    }
    if ($parti) {
        return array_sum($parti);
    }
    return null;
}

function fotocopiaDiagnostica($ip, $fase, $html)
{
    $nomi = [];
    if (preg_match_all('/name="([^"]+)"/', $html, $trovati)) {
        $nomi = array_slice(array_unique($trovati[1]), 0, 40);
    }
    $href = [];
    if (preg_match_all('/href="([^"]*JobAcc[^"]*)"/i', $html, $link)) {
        $href = array_slice(array_unique($link[1]), 0, 15);
    }
    fotocopiaLogFile(
        'diagnostica ip=' . $ip
        . ' fase=' . $fase
        . ' bytes=' . strlen($html)
        . ' campi=' . implode(',', $nomi)
        . ' href=' . implode(',', $href)
    );
}

function fotocopiaProprietaModello($html)
{
    $valori = [];
    if (preg_match_all("/_pp\.([A-Za-z0-9_]+)\s*=\s*(?:parseInt\('([^']*)',\s*10\)|'([^']*)')/", $html, $trovati, PREG_SET_ORDER)) {
        foreach ($trovati as $riga) {
            $valori[$riga[1]] = $riga[2] !== '' ? $riga[2] : $riga[3];
        }
    }
    if (preg_match('/_pp\.msg\s*=\s*\{([^}]*)\}/s', $html, $blocco)) {
        if (preg_match_all("/(\d+)\s*:\s*(?:parseInt\('([^']*)',\s*10\)|'([^']*)')/", $blocco[1], $voci, PREG_SET_ORDER)) {
            foreach ($voci as $voce) {
                $valori['msg' . $voce[1]] = $voce[2] !== '' ? $voce[2] : $voce[3];
            }
        }
    }
    return $valori;
}

function fotocopiaListaModello($html, $proprieta)
{
    $lista = [];
    $schema = '/_pp\.' . preg_quote($proprieta, '/') . "\[index\]\s*=\s*'([^']*)'/";
    if (preg_match_all($schema, $html, $trovati)) {
        $lista = $trovati[1];
    }
    return $lista;
}

function fotocopiaQueryScheda($privato, $modifica)
{
    return 'arg1=0&arg2=0&arg3=&arg4=0&arg5=&arg6=&arg7=1&arg8=0&arg9=&arg10=&arg11='
        . rawurlencode((string) $privato)
        . '&arg12=' . ($modifica ? '1' : '0')
        . '&arg50=0';
}

function fotocopiaCcrxElenco($sessione)
{
    $queryLista = 'arg1=1&arg2=0&arg3=&arg4=0&arg5=&arg6=&arg7=1&arg8=0&arg9=';
    $lista = fotocopiaHttp(
        $sessione['base'] . '/js/jssrc/model/mngset/jobset/MngSet_JobAcc_JobAccLst.model.htm?' . $queryLista,
        $sessione['cookie'],
        $sessione['ua'],
        null,
        $sessione['lista']
    );
    $scheda = fotocopiaHttp(
        $sessione['base'] . '/js/jssrc/model/mngset/jobset/MngSet_JobAcc_NewAcc.model.htm?' . fotocopiaQueryScheda('', false),
        $sessione['cookie'],
        $sessione['ua'],
        null,
        $sessione['lista']
    );
    if ($lista['codice'] !== 200 || $scheda['codice'] !== 200 || fotocopiaPaginaLoginRifiutata($lista['corpo']) || fotocopiaPaginaLoginRifiutata($scheda['corpo'])) {
        return ['ok' => false, 'errore' => 'Elenco account non letto', 'conti' => [], 'html' => '', 'hidden' => ''];
    }
    $valoriLista = fotocopiaProprietaModello($lista['corpo']);
    $pubblici = fotocopiaListaModello($scheda['corpo'], 'sDeptPublicID');
    $privati = isset($valoriLista['allDocNumber']) && $valoriLista['allDocNumber'] !== ''
        ? explode('/', $valoriLista['allDocNumber'])
        : [];
    if (!$pubblici && (int) (isset($valoriLista['searchResult']) ? $valoriLista['searchResult'] : 0) > 0) {
        return ['ok' => false, 'errore' => 'Elenco account vuoto', 'conti' => [], 'html' => '', 'hidden' => ''];
    }
    $conti = [];
    foreach ($pubblici as $indice => $id) {
        if (!preg_match('/^\d{1,8}$/', $id)) {
            continue;
        }
        $conti[$id] = [
            'id' => $id,
            'privato' => isset($privati[$indice]) ? $privati[$indice] : '',
            'nome' => '',
            'hrefs' => [],
        ];
    }
    return [
        'ok' => true,
        'errore' => '',
        'conti' => $conti,
        'html' => '',
        'hidden' => isset($valoriLista['hiddenvalue']) ? $valoriLista['hiddenvalue'] : '',
    ];
}

function fotocopiaTettoDaModello(array $valori)
{
    $numeri = [];
    if (isset($valori['mode1']) && (string) $valori['mode1'] === '1' && isset($valori['limitCount1']) && $valori['limitCount1'] !== '') {
        $numeri[] = (int) $valori['limitCount1'];
    }
    if (isset($valori['mode4']) && (string) $valori['mode4'] === '1' && isset($valori['limitCount4']) && $valori['limitCount4'] !== '') {
        $numeri[] = (int) $valori['limitCount4'];
    }
    if (!$numeri) {
        return null;
    }
    return max($numeri);
}

function fotocopiaCcrxModelloAccount($sessione, $privato, $modifica)
{
    $url = $sessione['base'] . '/js/jssrc/model/mngset/jobset/MngSet_JobAcc_NewAcc.model.htm?' . fotocopiaQueryScheda($privato, $modifica);
    $pagina = fotocopiaHttp($url, $sessione['cookie'], $sessione['ua'], null, $sessione['lista']);
    if ($pagina['codice'] !== 200 || fotocopiaPaginaLoginRifiutata($pagina['corpo']) || strlen($pagina['corpo']) < 500) {
        return ['ok' => false, 'errore' => 'Scheda account non letta', 'valori' => []];
    }
    return ['ok' => true, 'errore' => '', 'valori' => fotocopiaProprietaModello($pagina['corpo'])];
}

function fotocopiaCcrxCopie($sessione, $privato)
{
    $query = 'arg1=0&arg2=0&arg3=&arg4=0&arg5=&arg6=&arg7=1&arg8=0&arg9=&arg10=&arg11=' . rawurlencode((string) $privato);
    $pagina = fotocopiaHttp(
        $sessione['base'] . '/js/jssrc/model/mngset/jobset/MngSet_JobAcc_EchJobPrnCnt.model.htm?' . $query,
        $sessione['cookie'],
        $sessione['ua'],
        null,
        $sessione['lista']
    );
    if ($pagina['codice'] !== 200 || fotocopiaPaginaLoginRifiutata($pagina['corpo']) || strlen($pagina['corpo']) < 400) {
        return null;
    }
    if (!preg_match('/_pp\.copyPrinterCounter\s*=\s*\[(.*?)\]/s', $pagina['corpo'], $blocco)) {
        return null;
    }
    if (!preg_match("/'(\\d+)'/", $blocco[1], $numero)) {
        return null;
    }
    return (int) $numero[1];
}

function fotocopiaCampiAccount(array $valori, $codice, $nome, $tetto, $nuovo, $privato)
{
    $modo = function ($n) use ($valori) {
        $m = isset($valori['mode' . $n]) ? (string) $valori['mode' . $n] : '';
        return $m === '' ? '0' : $m;
    };
    $quota = function ($n) use ($valori) {
        $limite = isset($valori['limitCount' . $n]) ? (string) $valori['limitCount' . $n] : '';
        if ($limite !== '') {
            return $limite;
        }
        return isset($valori['defaultCount' . $n]) ? (string) $valori['defaultCount' . $n] : '0';
    };
    $coppie = [
        1 => ['arg04_copy', 'arg05_copymax'],
        2 => ['arg06_copycolor', 'arg07_copycolormax'],
        3 => ['arg08_copyfull', 'arg09_copyfullmax'],
        4 => ['arg10_print', 'arg11_printmax'],
        5 => ['arg12_copy', 'arg13_print'],
        6 => ['arg14_copyprint', 'arg15_copyprintmax'],
        7 => ['arg16_copyprintsingle', 'arg17_copyprintsinglemax'],
        8 => ['arg18_copyprintfull', 'arg19_copyprintfulmax'],
        9 => ['arg20_scanother', 'arg21_scanothermax'],
        10 => ['arg22_faxtx', 'arg23_faxtxmax'],
    ];
    $identita = $nuovo ? '' : (isset($valori['msg11']) && $valori['msg11'] !== '' ? $valori['msg11'] : (string) $privato);
    $campi = [
        'okhtmfile' => '/mngset/jobset/MngSet_AddEditRslt.htm',
        'failhtmfile' => '/mngset/jobset/MngSet_Err.htm',
        'func' => 'setJobAccAddEdit',
        'arg25_editType' => $nuovo ? '0' : '1',
        'arg26_deptId' => $identita,
        'arg27' => '0',
        'hidden' => isset($valori['hiddenvalue']) ? $valori['hiddenvalue'] : '',
        'arg01_Acname' => $nome,
        'arg02_furigana' => '',
        'arg03_id' => (string) $codice,
        'arg24_faxportcopy' => isset($valori['faxport_index']) ? $valori['faxport_index'] : '',
        'submit001' => 'Submit',
    ];
    foreach ($coppie as $n => $nomi) {
        $campi[$nomi[0]] = $modo($n);
        $campi[$nomi[1]] = $quota($n);
    }
    if ($tetto !== null) {
        $testoTetto = (string) (int) $tetto;
        $giaAttivo = false;
        if ($modo(1) === '1') {
            $campi['arg04_copy'] = '1';
            $campi['arg05_copymax'] = $testoTetto;
            $giaAttivo = true;
        }
        if ($modo(4) === '1') {
            $campi['arg10_print'] = '1';
            $campi['arg11_printmax'] = $testoTetto;
            $giaAttivo = true;
        }
        if (!$giaAttivo) {
            $campi['arg04_copy'] = '1';
            $campi['arg05_copymax'] = $testoTetto;
            $campi['arg10_print'] = '1';
            $campi['arg11_printmax'] = $testoTetto;
        }
    }
    return $campi;
}

function fotocopiaEsitoScrittura($risposta)
{
    if ($risposta['codice'] !== 200 || fotocopiaPaginaLoginRifiutata($risposta['corpo'])) {
        return 'La stampante non ha accettato il modulo (HTTP ' . $risposta['codice'] . ')';
    }
    $url = isset($risposta['url']) ? $risposta['url'] : '';
    if (stripos($url, 'MngSet_Err') !== false || (stripos($risposta['corpo'], 'MngSet_Err.htm') !== false && stripos($risposta['corpo'], 'failhtmfile') === false)) {
        return 'La stampante ha rifiutato i dati dell\'account';
    }
    return '';
}

function fotocopiaCcrxLeggi($sessione, $codice)
{
    $elenco = fotocopiaCcrxElenco($sessione);
    if (!$elenco['ok']) {
        return ['ok' => false, 'presente' => false, 'limite' => null, 'copie' => null, 'nome' => '', 'privato' => '', 'errore' => $elenco['errore']];
    }
    $conto = fotocopiaTrovaConto($elenco['conti'], $codice);
    if ($conto === null) {
        return ['ok' => true, 'presente' => false, 'limite' => null, 'copie' => null, 'nome' => '', 'privato' => '', 'errore' => ''];
    }
    if ($conto['privato'] === '') {
        return ['ok' => false, 'presente' => true, 'limite' => null, 'copie' => null, 'nome' => '', 'privato' => '', 'errore' => 'Identificativo interno dell\'account non letto'];
    }
    $modello = fotocopiaCcrxModelloAccount($sessione, $conto['privato'], true);
    if (!$modello['ok']) {
        return ['ok' => false, 'presente' => true, 'limite' => null, 'copie' => null, 'nome' => '', 'privato' => $conto['privato'], 'errore' => $modello['errore']];
    }
    $nome = isset($modello['valori']['accountName']) ? $modello['valori']['accountName'] : '';
    return [
        'ok' => true,
        'presente' => true,
        'limite' => fotocopiaTettoDaModello($modello['valori']),
        'copie' => fotocopiaCcrxCopie($sessione, $conto['privato']),
        'nome' => $nome,
        'privato' => $conto['privato'],
        'errore' => '',
    ];
}

function fotocopiaCcrxScrivi($sessione, $codice, $nomeAccount, $tetto, $soloNome)
{
    $elenco = fotocopiaCcrxElenco($sessione);
    if (!$elenco['ok']) {
        return ['ok' => false, 'errore' => $elenco['errore']];
    }
    $conto = fotocopiaTrovaConto($elenco['conti'], $codice);
    $nuovo = $conto === null;
    $privato = $nuovo ? '' : $conto['privato'];
    if (!$nuovo && $privato === '') {
        return ['ok' => false, 'errore' => 'Identificativo interno dell\'account non letto'];
    }
    $modello = fotocopiaCcrxModelloAccount($sessione, $privato, !$nuovo);
    if (!$modello['ok']) {
        return ['ok' => false, 'errore' => $modello['errore']];
    }
    if ($nomeAccount === '' && isset($modello['valori']['accountName'])) {
        $nomeAccount = $modello['valori']['accountName'];
    }
    $campi = fotocopiaCampiAccount($modello['valori'], $codice, $nomeAccount, $soloNome ? null : $tetto, $nuovo, $privato);
    if ($campi['hidden'] === '') {
        return ['ok' => false, 'errore' => 'Token della scheda account mancante'];
    }
    $pagina = $sessione['base'] . '/mngset/jobset/MngSet_JobAcc_NewAcc.htm?' . fotocopiaQueryScheda($privato, !$nuovo);
    $risposta = fotocopiaHttp($sessione['base'] . '/mngset/jobset/set.cgi', $sessione['cookie'], $sessione['ua'], $campi, $pagina);
    $errore = fotocopiaEsitoScrittura($risposta);
    if ($errore !== '') {
        fotocopiaLogFile('scrittura ip=' . parse_url($sessione['base'], PHP_URL_HOST) . ' url=' . (isset($risposta['url']) ? $risposta['url'] : '') . ' ' . $errore);
        return ['ok' => false, 'errore' => $errore];
    }
    return ['ok' => true, 'errore' => ''];
}

function fotocopiaCcrxElimina($sessione, $codice)
{
    $elenco = fotocopiaCcrxElenco($sessione);
    if (!$elenco['ok']) {
        return ['ok' => false, 'errore' => $elenco['errore'], 'assente' => false];
    }
    $conto = fotocopiaTrovaConto($elenco['conti'], $codice);
    if ($conto === null) {
        return ['ok' => true, 'errore' => '', 'assente' => true];
    }
    if ($conto['privato'] === '' || $elenco['hidden'] === '') {
        return ['ok' => false, 'errore' => 'Dati per l\'eliminazione non letti', 'assente' => false];
    }
    $campi = [
        'okhtmfile' => '/mngset/jobset/MngSet_JobAcc_JobAccLst.htm',
        'failhtmfile' => '/mngset/jobset/MngSet_Err.htm',
        'func' => 'setJobAcceDel',
        'arg01_Delmode' => '0',
        'arg02_Num' => '1',
        'arg03_ID' => $conto['privato'],
        'hidden' => $elenco['hidden'],
    ];
    $risposta = fotocopiaHttp($sessione['base'] . '/mngset/jobset/set.cgi', $sessione['cookie'], $sessione['ua'], $campi, $sessione['lista']);
    $errore = fotocopiaEsitoScrittura($risposta);
    if ($errore !== '') {
        return ['ok' => false, 'errore' => $errore, 'assente' => false];
    }
    $dopo = fotocopiaCcrxElenco($sessione);
    if ($dopo['ok'] && fotocopiaTrovaConto($dopo['conti'], $codice) !== null) {
        return ['ok' => false, 'errore' => 'L\'account è ancora in elenco', 'assente' => false];
    }
    return ['ok' => true, 'errore' => '', 'assente' => false];
}

function fotocopiaCcrxDettaglio($sessione, array $conto)
{
    $scheda = fotocopiaScegliHref($conto['hrefs'], false);
    $contatore = fotocopiaScegliHref($conto['hrefs'], true);
    $limite = null;
    $copie = null;
    if ($scheda !== '') {
        $pagina = fotocopiaHttp($scheda, $sessione['cookie'], $sessione['ua'], null, $sessione['lista']);
        if ($pagina['codice'] === 200 && !fotocopiaPaginaLoginRifiutata($pagina['corpo'])) {
            $dom = fotocopiaDom($pagina['corpo']);
            $limite = fotocopiaLeggiTetto($dom, $conto['id']);
            $copie = fotocopiaLeggiCopie($dom, $conto['id']);
            if ($contatore === '') {
                $contatore = fotocopiaLinkAggiunta($pagina['corpo'], $scheda);
                if ($contatore !== '' && !preg_match('/Cnt|Cntr|Counter|Contat/i', $contatore)) {
                    $contatore = '';
                }
                if (preg_match_all('/href="([^"]+)"/i', $pagina['corpo'], $link)) {
                    foreach ($link[1] as $href) {
                        if (preg_match('/Cnt|Cntr|Counter|Contat/i', $href)) {
                            $contatore = fotocopiaAssoluto($scheda, html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                            break;
                        }
                    }
                }
            }
        }
    }
    if ($contatore !== '') {
        $paginaContatore = fotocopiaHttp($contatore, $sessione['cookie'], $sessione['ua'], null, $scheda !== '' ? $scheda : $sessione['lista']);
        if ($paginaContatore['codice'] === 200 && !fotocopiaPaginaLoginRifiutata($paginaContatore['corpo'])) {
            $copieLette = fotocopiaLeggiCopie(fotocopiaDom($paginaContatore['corpo']), $conto['id']);
            if ($copieLette !== null) {
                $copie = $copieLette;
            }
        }
    }
    if ($scheda === '' && $contatore === '') {
        fotocopiaDiagnostica(parse_url($sessione['base'], PHP_URL_HOST), 'dettaglio-' . $conto['id'], implode(' ', $conto['hrefs']));
    }
    return ['limite' => $limite, 'copie' => $copie, 'scheda' => $scheda];
}

function fotocopiaCcrxInviaForm($sessione, $urlPagina, $html, $codice, $nomeAccount, $tetto)
{
    $dom = fotocopiaDom($html);
    $form = fotocopiaFormPrincipale($dom);
    if (!$form) {
        fotocopiaDiagnostica(parse_url($sessione['base'], PHP_URL_HOST), 'form', $html);
        return ['ok' => false, 'errore' => 'Modulo account non trovato sulla stampante'];
    }
    $letti = fotocopiaControlliForm($form);
    $campi = $letti['campi'];
    list($campi, $idOk) = fotocopiaImpostaIdentita($form, $campi, $codice, $nomeAccount);
    if (!$idOk) {
        fotocopiaDiagnostica(parse_url($sessione['base'], PHP_URL_HOST), 'identita', $html);
        return ['ok' => false, 'errore' => 'Campo ID account non riconosciuto'];
    }
    if ($tetto !== null) {
        list($campi, $toccati) = fotocopiaImpostaTetto($form, $campi, (int) $tetto);
        if ($toccati === 0) {
            fotocopiaDiagnostica(parse_url($sessione['base'], PHP_URL_HOST), 'tetto', $html);
            return ['ok' => false, 'errore' => 'Campo del tetto non riconosciuto'];
        }
    }
    foreach ($letti['invio'] as $pulsante) {
        if ($pulsante['tipo'] === 'submit' && !preg_match('/reset|azzera|cancel|annulla/i', $pulsante['nome'] . $pulsante['valore'])) {
            $campi[$pulsante['nome']] = $pulsante['valore'];
            break;
        }
    }
    $azione = $letti['action'] !== '' ? fotocopiaAssoluto($urlPagina, $letti['action']) : $urlPagina;
    $risposta = fotocopiaHttp($azione, $sessione['cookie'], $sessione['ua'], $campi, $urlPagina);
    if ($risposta['codice'] !== 200 || fotocopiaPaginaLoginRifiutata($risposta['corpo'])) {
        return ['ok' => false, 'errore' => 'La stampante non ha accettato il modulo (HTTP ' . $risposta['codice'] . ')'];
    }
    if (stripos($risposta['corpo'], 'error') !== false && stripos($risposta['corpo'], 'arg01_') !== false && stripos($risposta['corpo'], '<title>') !== false) {
        if (preg_match('/<title>[^<]*(error|errore)[^<]*<\/title>/i', $risposta['corpo'])) {
            return ['ok' => false, 'errore' => 'La stampante ha rifiutato i dati dell\'account'];
        }
    }
    return ['ok' => true, 'errore' => ''];
}
