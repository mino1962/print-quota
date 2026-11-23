<?php
/**
 * print-quota web application.
 * Copyright (c) Gelsomino Lullo. MIT License.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$page = (string) ($_GET['p'] ?? 'home');
$pdo = pqDb();

if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?p=login');
    exit;
}

if ($page === 'runqueue') {
    $user = pqUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    session_write_close();
    @ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    $res = pqRunNextJob($pdo);
    echo json_encode($res ?: ['ok' => true, 'idle' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM operator WHERE username = :u AND active = 'S'");
    $stmt->execute(['u' => trim((string) ($_POST['username'] ?? ''))]);
    $op = $stmt->fetch();
    if ($op && password_verify((string) ($_POST['password'] ?? ''), (string) $op['password_hash'])) {
        $_SESSION['pq_operator_id'] = (int) $op['id'];
        header('Location: index.php?p=' . (($op['must_change_password'] === 'S') ? 'password' : 'home'));
        exit;
    }
    $loginError = 'Utente o password non validi.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login') {
    $user = pqRequireLogin();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'password') {
        $a = (string) ($_POST['password'] ?? '');
        $b = (string) ($_POST['password2'] ?? '');
        if (strlen($a) < 8 || $a !== $b) {
            pqFlash('err', 'Le password devono coincidere e avere almeno 8 caratteri.');
            pqRedirect('password');
        }
        $pdo->prepare("UPDATE operator SET password_hash = :h, must_change_password = 'N', updated_at = datetime('now','localtime') WHERE id = :id")
            ->execute(['h' => password_hash($a, PASSWORD_DEFAULT), 'id' => $user['id']]);
        pqFlash('ok', 'Password aggiornata.');
        pqRedirect('home');
    }
    if ($act === 'settings' && pqIsAdmin($user)) {
        $len = max(4, min(10, (int) ($_POST['code_length'] ?? 6)));
        $step = max(1, (int) ($_POST['quota_step'] ?? 1000));
        $initial = max(1, (int) ($_POST['quota_initial'] ?? $step));
        $max = max($initial, (int) ($_POST['quota_max'] ?? 9999999));
        $up = $pdo->prepare('INSERT INTO setting (key, value) VALUES (:k,:v) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach (['code_length' => $len, 'quota_step' => $step, 'quota_initial' => $initial, 'quota_max' => $max] as $k => $v) {
            $up->execute(['k' => $k, 'v' => (string) $v]);
        }
        $pdo->prepare('UPDATE organization SET name=:n, org_type=:t, address=:a, city=:c, tax_id=:x WHERE id=1')->execute([
            'n' => trim((string) ($_POST['org_name'] ?? 'Organization')) ?: 'Organization',
            't' => trim((string) ($_POST['org_type'] ?? 'azienda')),
            'a' => trim((string) ($_POST['address'] ?? '')),
            'c' => trim((string) ($_POST['city'] ?? '')),
            'x' => trim((string) ($_POST['tax_id'] ?? '')),
        ]);
        pqFlash('ok', 'Impostazioni salvate.');
        pqRedirect('settings');
    }
    if ($act === 'person_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $surname = trim((string) ($_POST['surname'] ?? ''));
        $given = trim((string) ($_POST['given_name'] ?? ''));
        if ($surname === '' || $given === '') {
            pqFlash('err', 'Cognome e nome sono obbligatori.');
            pqRedirect('person', ['id' => $id]);
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '' && $id === 0) {
            $code = pqGenerateCode($pdo);
        }
        $fields = [
            'surname' => $surname,
            'given' => $given,
            'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
            'code' => $code !== '' ? $code : null,
            'group' => trim((string) ($_POST['group_name'] ?? '')) ?: null,
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'ext' => trim((string) ($_POST['external_id'] ?? '')) ?: null,
            'active' => (($_POST['active'] ?? 'S') === 'N') ? 'N' : 'S',
        ];
        if ($id > 0) {
            $fields['id'] = $id;
            $pdo->prepare("UPDATE person SET surname=:surname, given_name=:given, email=:email, code=:code, group_name=:group, phone=:phone, notes=:notes, external_id=:ext, active=:active, updated_at=datetime('now','localtime') WHERE id=:id")->execute($fields);
        } else {
            $pdo->prepare('INSERT INTO person (surname, given_name, email, code, group_name, phone, notes, external_id, active) VALUES (:surname,:given,:email,:code,:group,:phone,:notes,:ext,:active)')->execute($fields);
        }
        pqFlash('ok', 'Utente di stampa salvato.');
        pqRedirect('persons');
    }
    if ($act === 'enqueue') {
        $personId = (int) ($_POST['person_id'] ?? 0);
        $printerId = (int) ($_POST['printer_id'] ?? 0);
        $action = (string) ($_POST['job'] ?? 'read');
        $allowed = ['read', 'create', 'increase', 'delete', 'fixst', 'probe'];
        if (!in_array($action, $allowed, true)) {
            pqFlash('err', 'Azione sconosciuta.');
            pqRedirect('persons');
        }
        $step = (int) ($_POST['step'] ?? 0);
        pqEnqueue($pdo, $action, $personId > 0 ? $personId : null, $printerId > 0 ? $printerId : null, $step > 0 ? $step : null, (int) $user['id']);
        pqFlash('ok', 'In coda. Apri la pagina Coda e lasciala aperta finché termina.');
        pqRedirect('queue');
    }
    if ($act === 'printer_save' && pqIsAdmin($user)) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($name === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            pqFlash('err', 'Nome e indirizzo IP valido sono obbligatori.');
            pqRedirect('printer', ['id' => $id]);
        }
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($id > 0) {
            $sql = "UPDATE printer SET name=:name, location=:loc, ip=:ip, login=:login, active=:active, updated_at=datetime('now','localtime')";
            $params = [
                'name' => $name,
                'loc' => trim((string) ($_POST['location'] ?? '')),
                'ip' => $ip,
                'login' => $login,
                'active' => (($_POST['active'] ?? 'S') === 'N') ? 'N' : 'S',
                'id' => $id,
            ];
            if ($password !== '') {
                $sql .= ', password=:password';
                $params['password'] = $password;
            }
            $sql .= ' WHERE id=:id';
            $pdo->prepare($sql)->execute($params);
        } else {
            $pdo->prepare('INSERT INTO printer (name, location, ip, login, password, active) VALUES (:name,:loc,:ip,:login,:password,:active)')->execute([
                'name' => $name,
                'loc' => trim((string) ($_POST['location'] ?? '')),
                'ip' => $ip,
                'login' => $login,
                'password' => $password,
                'active' => 'S',
            ]);
        }
        pqFlash('ok', 'Stampante salvata.');
        pqRedirect('printers');
    }
    if ($act === 'operator_save' && pqIsAdmin($user)) {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $role = (($_POST['role'] ?? '') === 'OPERATOR') ? 'OPERATOR' : 'ADMIN';
        if ($username === '') {
            pqFlash('err', 'Il nome utente è obbligatorio.');
            pqRedirect('operators');
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($id === 0) {
            if (strlen($password) < 8) {
                pqFlash('err', 'La password iniziale deve avere almeno 8 caratteri.');
                pqRedirect('operators');
            }
            $pdo->prepare("INSERT INTO operator (username, password_hash, role, must_change_password, surname, given_name, email, active) VALUES (:u,:h,:r,'S',:s,:g,:e,'S')")->execute([
                'u' => $username,
                'h' => password_hash($password, PASSWORD_DEFAULT),
                'r' => $role,
                's' => trim((string) ($_POST['surname'] ?? '')),
                'g' => trim((string) ($_POST['given_name'] ?? '')),
                'e' => trim((string) ($_POST['email'] ?? '')) ?: null,
            ]);
        } else {
            $pdo->prepare("UPDATE operator SET username=:u, role=:r, surname=:s, given_name=:g, email=:e, active=:a, updated_at=datetime('now','localtime') WHERE id=:id")->execute([
                'u' => $username,
                'r' => $role,
                's' => trim((string) ($_POST['surname'] ?? '')),
                'g' => trim((string) ($_POST['given_name'] ?? '')),
                'e' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'a' => (($_POST['active'] ?? 'S') === 'N') ? 'N' : 'S',
                'id' => $id,
            ]);
            if ($password !== '') {
                $pdo->prepare("UPDATE operator SET password_hash=:h, must_change_password='S' WHERE id=:id")->execute([
                    'h' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $id,
                ]);
            }
        }
        pqFlash('ok', 'Operatore salvato.');
        pqRedirect('operators');
    }
    if ($act === 'make_print_user' && pqIsAdmin($user)) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM operator WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $op = $stmt->fetch();
        if (!$op) {
            pqFlash('err', 'Operatore non trovato.');
            pqRedirect('operators');
        }
        if (!empty($op['person_id'])) {
            pqFlash('ok', 'Già collegato a un utente di stampa.');
            pqRedirect('operators');
        }
        $code = pqGenerateCode($pdo);
        $pdo->prepare('INSERT INTO person (surname, given_name, email, code, active) VALUES (:s,:g,:e,:c,\'S\')')->execute([
            's' => $op['surname'] !== '' ? $op['surname'] : $op['username'],
            'g' => $op['given_name'] !== '' ? $op['given_name'] : 'User',
            'e' => $op['email'],
            'c' => $code,
        ]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE operator SET person_id=:p WHERE id=:id')->execute(['p' => $pid, 'id' => $id]);
        pqFlash('ok', 'Utente di stampa creato con codice ' . $code . '.');
        pqRedirect('operators');
    }
    if ($act === 'csv_import') {
        if (empty($_FILES['csv']['tmp_name'])) {
            pqFlash('err', 'Scegli un file CSV.');
            pqRedirect('csv');
        }
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $header = fgetcsv($fh, 0, ';');
        if (!$header) {
            pqFlash('err', 'File vuoto.');
            pqRedirect('csv');
        }
        $header = array_map(static function ($h) {
            return strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
        }, $header);
        $created = 0;
        $updated = 0;
        $errors = 0;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count(array_filter($row, static fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $name) {
                $data[$name] = trim((string) ($row[$i] ?? ''));
            }
            if (($data['cognome'] ?? '') === '' || ($data['nome'] ?? '') === '') {
                $errors++;
                continue;
            }
            $match = null;
            if (($data['esterno_id'] ?? '') !== '') {
                $q = $pdo->prepare('SELECT id FROM person WHERE external_id = :e');
                $q->execute(['e' => $data['esterno_id']]);
                $match = $q->fetchColumn();
            } elseif (($data['codice'] ?? '') !== '') {
                $q = $pdo->prepare('SELECT id FROM person WHERE code = :c');
                $q->execute(['c' => $data['codice']]);
                $match = $q->fetchColumn();
            }
            $code = $data['codice'] ?? '';
            if ($code === '' && !$match) {
                $code = pqGenerateCode($pdo);
            }
            $payload = [
                'surname' => $data['cognome'],
                'given' => $data['nome'],
                'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
                'code' => $code !== '' ? $code : null,
                'group' => ($data['gruppo'] ?? '') !== '' ? $data['gruppo'] : null,
                'phone' => ($data['telefono'] ?? '') !== '' ? $data['telefono'] : null,
                'notes' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
                'ext' => ($data['esterno_id'] ?? '') !== '' ? $data['esterno_id'] : null,
                'active' => ($data['attivo'] ?? '') === '' ? null : ((($data['attivo'] ?? '') === 'N') ? 'N' : 'S'),
            ];
            try {
                if ($match) {
                    $payload['id'] = (int) $match;
                    if ($payload['code'] === null) {
                        unset($payload['code']);
                        $pdo->prepare("UPDATE person SET surname=:surname, given_name=:given, email=COALESCE(:email,email), group_name=COALESCE(:group,group_name), phone=COALESCE(:phone,phone), notes=COALESCE(:notes,notes), external_id=COALESCE(:ext,external_id), active=COALESCE(:active,active), updated_at=datetime('now','localtime') WHERE id=:id")->execute($payload);
                    } else {
                        $pdo->prepare("UPDATE person SET surname=:surname, given_name=:given, email=COALESCE(:email,email), code=:code, group_name=COALESCE(:group,group_name), phone=COALESCE(:phone,phone), notes=COALESCE(:notes,notes), external_id=COALESCE(:ext,external_id), active=COALESCE(:active,active), updated_at=datetime('now','localtime') WHERE id=:id")->execute($payload);
                    }
                    $updated++;
                } else {
                    $payload['active'] = $payload['active'] ?? 'S';
                    $pdo->prepare('INSERT INTO person (surname, given_name, email, code, group_name, phone, notes, external_id, active) VALUES (:surname,:given,:email,:code,:group,:phone,:notes,:ext,:active)')->execute($payload);
                    $created++;
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }
        fclose($fh);
        pqFlash('ok', "Importazione conclusa. Creati $created, aggiornati $updated, errori $errors.");
        pqRedirect('csv');
    }
    if ($act === 'cancel_job') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE job_queue SET status = 'C', finished_at = datetime('now','localtime'), log = 'Annullata' WHERE id = :id AND status = 'P'")->execute(['id' => $id]);
        pqFlash('ok', 'Richiesta in coda annullata.');
        pqRedirect('queue');
    }
    pqRedirect('home');
}

if ($page === 'csv_template') {
    pqRequireLogin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_template.csv"');
    echo "\xEF\xBB\xBFcognome;nome;email;codice;gruppo;telefono;note;esterno_id;attivo\n";
    echo "Rossi;Mario;mario.rossi@example.com;;Ufficio;;Riga di esempio, da cancellare prima dell'importazione;EXT-1;S\n";
    exit;
}

if ($page === 'csv_export') {
    pqRequireLogin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBFcognome;nome;email;codice;gruppo;telefono;note;esterno_id;attivo\n";
    $out = fopen('php://output', 'w');
    foreach ($pdo->query("SELECT * FROM person ORDER BY surname, given_name") as $row) {
        fputcsv($out, [
            $row['surname'], $row['given_name'], $row['email'], $row['code'], $row['group_name'],
            $row['phone'], $row['notes'], $row['external_id'], $row['active'],
        ], ';');
    }
    fclose($out);
    exit;
}

$user = ($page === 'login') ? null : pqRequireLogin();
$flash = pqFlash();
$org = $pdo->query('SELECT * FROM organization WHERE id=1')->fetch();
$ver = pqVersion();

function pqLayoutStart(?array $user, ?array $flash, string $ver, array $org): void
{
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>print-quota</title><link rel="stylesheet" href="assets/app.css?v=' . pqH($ver) . '"></head><body>';
    echo '<header><div><span class="brand">print-quota</span> <span style="opacity:.7">' . pqH($org['name'] ?? '') . '</span></div><nav>';
    if ($user && ($user['must_change_password'] ?? '') !== 'S') {
        echo '<a href="index.php?p=home">Home</a><a href="index.php?p=persons">Utenti di stampa</a><a href="index.php?p=printers">Stampanti</a><a href="index.php?p=queue">Coda</a><a href="index.php?p=csv">CSV</a>';
        if (pqIsAdmin($user)) {
            echo '<a href="index.php?p=operators">Operatori</a><a href="index.php?p=settings">Impostazioni</a>';
        }
        echo '<a href="index.php?p=logout">Esci</a>';
    }
    echo '</nav></header><main>';
    if ($flash) {
        echo '<div class="flash ' . (($flash['type'] ?? '') === 'ok' ? 'ok' : 'err') . '">' . pqH($flash['text'] ?? '') . '</div>';
    }
}

function pqLayoutEnd(string $ver): void
{
    echo '</main><footer>v' . pqH($ver) . ' · ' . pqH(PQ_AUTHOR) . '</footer></body></html>';
}

pqLayoutStart($user, $flash, $ver, $org ?: []);

if ($page === 'login') {
    echo '<div class="card" style="max-width:420px;margin:40px auto"><h1>print-quota</h1>';
    echo '<p>Accesso. Il primo amministratore è <code>admin</code> / <code>' . pqH(PQ_DEFAULT_PASSWORD) . '</code> e deve cambiare la password.</p>';
    if (!empty($loginError)) {
        echo '<div class="flash err">' . pqH($loginError) . '</div>';
    }
    echo '<form method="post"><label>Nome utente</label><input name="username" required autofocus><label>Password</label><input type="password" name="password" required><p><button>Entra</button></p></form></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'password') {
    echo '<div class="card" style="max-width:480px"><h1>Cambio password</h1><p>Imposta una nuova password prima di usare l\'applicazione.</p>';
    echo '<form method="post"><input type="hidden" name="act" value="password"><label>Nuova password</label><input type="password" name="password" required minlength="8"><label>Ripeti</label><input type="password" name="password2" required minlength="8"><p><button>Salva</button></p></form></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'settings' && pqIsAdmin($user)) {
    echo '<div class="card"><h1>Impostazioni</h1><form method="post"><input type="hidden" name="act" value="settings"><div class="row">';
    echo '<div><label>Nome organizzazione</label><input name="org_name" value="' . pqH($org['name'] ?? '') . '"></div>';
    echo '<div><label>Tipo</label><input name="org_type" value="' . pqH($org['org_type'] ?? 'azienda') . '"></div>';
    echo '<div><label>Indirizzo</label><input name="address" value="' . pqH($org['address'] ?? '') . '"></div>';
    echo '<div><label>Città</label><input name="city" value="' . pqH($org['city'] ?? '') . '"></div>';
    echo '<div><label>Codice fiscale / P. IVA</label><input name="tax_id" value="' . pqH($org['tax_id'] ?? '') . '"></div>';
    echo '<div><label>Lunghezza codice</label><input type="number" name="code_length" min="4" max="10" value="' . (int) pqSetting('code_length', '6') . '"></div>';
    echo '<div><label>Passo quota</label><input type="number" name="quota_step" min="1" value="' . (int) pqSetting('quota_step', '1000') . '"></div>';
    echo '<div><label>Tetto iniziale</label><input type="number" name="quota_initial" min="1" value="' . (int) pqSetting('quota_initial', '1000') . '"></div>';
    echo '<div><label>Tetto massimo</label><input type="number" name="quota_max" min="1" value="' . (int) pqSetting('quota_max', '9999999') . '"></div>';
    echo '</div><p><button>Salva</button></p></form></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'persons' || $page === 'person') {
    if ($page === 'person') {
        $id = (int) ($_GET['id'] ?? 0);
        $row = ['surname' => '', 'given_name' => '', 'email' => '', 'code' => '', 'group_name' => '', 'phone' => '', 'notes' => '', 'external_id' => '', 'active' => 'S'];
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM person WHERE id=:id');
            $stmt->execute(['id' => $id]);
            $found = $stmt->fetch();
            if ($found) {
                $row = $found;
            }
        }
        echo '<div class="card"><h1>' . ($id ? 'Modifica utente di stampa' : 'Nuovo utente di stampa') . '</h1><form method="post"><input type="hidden" name="act" value="person_save"><input type="hidden" name="id" value="' . $id . '"><div class="row">';
        foreach (['surname' => 'Cognome', 'given_name' => 'Nome', 'email' => 'Email', 'code' => 'Codice', 'group_name' => 'Gruppo', 'phone' => 'Telefono', 'external_id' => 'Id esterno'] as $field => $label) {
            echo '<div><label>' . $label . '</label><input name="' . $field . '" value="' . pqH($row[$field] ?? '') . '"></div>';
        }
        echo '<div><label>Attivo</label><select name="active"><option value="S"' . (($row['active'] ?? '') === 'S' ? ' selected' : '') . '>Sì</option><option value="N"' . (($row['active'] ?? '') === 'N' ? ' selected' : '') . '>No</option></select></div>';
        echo '</div><label>Note</label><textarea name="notes">' . pqH($row['notes'] ?? '') . '</textarea><p><button>Salva</button> <a class="btn secondary" href="index.php?p=persons">Indietro</a></p></form></div>';
        pqLayoutEnd($ver);
        exit;
    }
    $step = (int) pqSetting('quota_step', '1000');
    echo '<div class="card"><h1>Utenti di stampa</h1><p><a class="btn" href="index.php?p=person">Nuovo</a></p><table><tr><th>Nome</th><th>Codice</th><th>Gruppo</th><th>Azioni</th></tr>';
    foreach ($pdo->query("SELECT * FROM person ORDER BY surname, given_name") as $row) {
        echo '<tr><td><a href="index.php?p=person&id=' . (int) $row['id'] . '">' . pqH($row['surname'] . ' ' . $row['given_name']) . '</a></td><td>' . pqH($row['code']) . '</td><td>' . pqH($row['group_name']) . '</td><td class="actions">';
        if ($row['code']) {
            foreach (['read' => 'Leggi', 'create' => 'Crea', 'increase' => '+' . $step, 'delete' => 'Elimina'] as $job => $label) {
                echo '<form method="post"><input type="hidden" name="act" value="enqueue"><input type="hidden" name="job" value="' . $job . '"><input type="hidden" name="person_id" value="' . (int) $row['id'] . '"><button class="secondary">' . pqH($label) . '</button></form> ';
            }
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'printers' || $page === 'printer') {
    if (!pqIsAdmin($user) && $page === 'printer') {
        pqRedirect('printers');
    }
    if ($page === 'printer') {
        $id = (int) ($_GET['id'] ?? 0);
        $row = ['name' => '', 'location' => '', 'ip' => '', 'login' => '', 'active' => 'S'];
        if ($id) {
            $stmt = $pdo->prepare('SELECT * FROM printer WHERE id=:id');
            $stmt->execute(['id' => $id]);
            $found = $stmt->fetch();
            if ($found) {
                $row = $found;
            }
        }
        echo '<div class="card"><h1>Stampante</h1><form method="post"><input type="hidden" name="act" value="printer_save"><input type="hidden" name="id" value="' . $id . '"><div class="row">';
        echo '<div><label>Nome</label><input name="name" required value="' . pqH($row['name']) . '"></div>';
        echo '<div><label>Ubicazione</label><input name="location" value="' . pqH($row['location']) . '"></div>';
        echo '<div><label>IP</label><input name="ip" required value="' . pqH($row['ip']) . '"></div>';
        echo '<div><label>Utente Command Center</label><input name="login" value="' . pqH($row['login']) . '"></div>';
        echo '<div><label>Password' . ($id ? ' (vuoto = non cambiare)' : '') . '</label><input type="password" name="password"></div>';
        echo '<div><label>Attiva</label><select name="active"><option value="S">Sì</option><option value="N"' . (($row['active'] ?? '') === 'N' ? ' selected' : '') . '>No</option></select></div>';
        echo '</div><p><button>Salva</button></p></form></div>';
        pqLayoutEnd($ver);
        exit;
    }
    echo '<div class="card"><h1>Stampanti</h1>';
    if (pqIsAdmin($user)) {
        echo '<p><a class="btn" href="index.php?p=printer">Nuova</a> ';
        echo '<form method="post" style="display:inline"><input type="hidden" name="act" value="enqueue"><input type="hidden" name="job" value="probe"><button class="secondary">Prova connessione</button></form></p>';
    }
    echo '<table><tr><th>Nome</th><th>IP</th><th>Ubicazione</th></tr>';
    foreach ($pdo->query("SELECT * FROM printer ORDER BY name") as $row) {
        $name = pqIsAdmin($user) ? '<a href="index.php?p=printer&id=' . (int) $row['id'] . '">' . pqH($row['name']) . '</a>' : pqH($row['name']);
        echo '<tr><td>' . $name . '</td><td>' . pqH($row['ip']) . '</td><td>' . pqH($row['location']) . '</td></tr>';
    }
    echo '</table></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'operators' && pqIsAdmin($user)) {
    echo '<div class="card"><h1>Operatori</h1><form method="post"><input type="hidden" name="act" value="operator_save"><div class="row">';
    echo '<div><label>Nome utente</label><input name="username" required></div><div><label>Password</label><input type="password" name="password" required minlength="8"></div>';
    echo '<div><label>Ruolo</label><select name="role"><option value="ADMIN">Admin</option><option value="OPERATOR">Operatore</option></select></div>';
    echo '<div><label>Cognome</label><input name="surname"></div><div><label>Nome</label><input name="given_name"></div><div><label>Email</label><input name="email"></div>';
    echo '</div><p><button>Aggiungi operatore</button></p></form></div>';
    echo '<div class="card"><table><tr><th>Nome utente</th><th>Ruolo</th><th>Utente di stampa</th><th></th></tr>';
    foreach ($pdo->query('SELECT * FROM operator ORDER BY id') as $row) {
        echo '<tr><td>' . pqH($row['username']) . '</td><td>' . pqH($row['role'] === 'ADMIN' ? 'Admin' : 'Operatore') . '</td><td>' . ($row['person_id'] ? 'Sì #' . (int) $row['person_id'] : 'No') . '</td><td>';
        if (!$row['person_id']) {
            echo '<form method="post"><input type="hidden" name="act" value="make_print_user"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="secondary">Crea utenza di stampa</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'queue') {
    $open = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status IN ('P','R')")->fetchColumn();
    echo '<div class="card"><h1>Coda</h1><p id="qstatus">' . ($open ? $open . ' aperte' : 'Nessuna attività') . '</p>';
    echo '<table id="qtable"><tr><th>#</th><th>Richiesta</th><th>Azione</th><th>Stato</th><th>Esito</th><th></th></tr>';
    $stati = ['P' => 'In coda', 'R' => 'In esecuzione', 'D' => 'Completata', 'E' => 'Errore', 'C' => 'Annullata'];
    foreach ($pdo->query('SELECT * FROM job_queue ORDER BY id DESC LIMIT 40') as $row) {
        echo '<tr><td>' . (int) $row['id'] . '</td><td>' . pqH($row['created_at']) . '</td><td>' . pqH($row['action']) . '</td><td>' . pqH($stati[$row['status']] ?? $row['status']) . '</td><td>' . nl2br(pqH($row['log'])) . '</td><td>';
        if ($row['status'] === 'P') {
            echo '<form method="post"><input type="hidden" name="act" value="cancel_job"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="secondary">Annulla</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
    if ($open > 0) {
        echo '<script>function tick(){fetch("index.php?p=runqueue",{method:"POST",credentials:"same-origin"}).then(r=>r.json()).then(d=>{if(d&&d.open>0){setTimeout(tick,400);}else{location.reload();}}).catch(()=>setTimeout(tick,3000));}tick();</script>';
    }
    pqLayoutEnd($ver);
    exit;
}

if ($page === 'csv') {
    echo '<div class="card"><h1>Utenti di stampa da CSV</h1>';
    echo '<p>Separatore <code>;</code>, UTF-8. Colonne: <code>cognome;nome;email;codice;gruppo;telefono;note;esterno_id;attivo</code>.</p>';
    echo '<p><a class="btn secondary" href="index.php?p=csv_template">Scarica modello vuoto</a> <a class="btn secondary" href="index.php?p=csv_export">Esporta</a></p>';
    echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="act" value="csv_import"><label>File da importare</label><input type="file" name="csv" accept=".csv,text/csv" required><p><button>Importa</button></p></form>';
    echo '<p>Ordine di riconoscimento: id esterno, poi codice. Un codice vuoto su una riga nuova viene generato. L\'importazione non crea gli account sulla stampante. Dettaglio in <code>docs/csv-utenti.md</code>.</p></div>';
    pqLayoutEnd($ver);
    exit;
}

$counts = [
    'people' => (int) $pdo->query("SELECT COUNT(*) FROM person WHERE active='S'")->fetchColumn(),
    'printers' => (int) $pdo->query("SELECT COUNT(*) FROM printer WHERE active='S'")->fetchColumn(),
    'open' => (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status IN ('P','R')")->fetchColumn(),
];
echo '<div class="card"><h1>print-quota</h1><p>Gestione quote di stampa per stampanti Command Center RX. Accesso come ' . pqH($user['username']) . ' (' . pqH($user['role'] === 'ADMIN' ? 'Admin' : 'Operatore') . ').</p>';
echo '<p>Utenti di stampa attivi: ' . $counts['people'] . ' · Stampanti: ' . $counts['printers'] . ' · Attività aperte: ' . $counts['open'] . '</p></div>';
pqLayoutEnd($ver);
