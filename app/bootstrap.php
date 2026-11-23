<?php
/**
 * print-quota bootstrap.
 * Copyright (c) Gelsomino Lullo. MIT License.
 */
declare(strict_types=1);

const PQ_AUTHOR = 'Gelsomino Lullo';
const PQ_DEFAULT_PASSWORD = 'ChangeMe!';

date_default_timezone_set('Europe/Rome');

define('PQ_ROOT', dirname(__DIR__));

require_once PQ_ROOT . '/library/ccrx.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PRINT_QUOTA');
    session_start();
}

function pqDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = PQ_ROOT . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dir . '/print-quota.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    pqEnsureSchema($pdo);
    return $pdo;
}

function pqEnsureSchema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
    $hasOrg = (int) $pdo->query('SELECT COUNT(*) FROM organization')->fetchColumn();
    if ($hasOrg === 0) {
        $pdo->exec("INSERT INTO organization (id, name, org_type) VALUES (1, 'Organization', 'azienda')");
        $defaults = [
            'code_length' => '6',
            'quota_step' => '1000',
            'quota_initial' => '1000',
            'quota_max' => '9999999',
        ];
        $ins = $pdo->prepare('INSERT INTO setting (key, value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $ins->execute(['k' => $k, 'v' => $v]);
        }
        $hash = password_hash(PQ_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        $op = $pdo->prepare("INSERT INTO operator (username, password_hash, role, must_change_password, surname, given_name, active)
            VALUES ('admin', :hash, 'ADMIN', 'S', 'Admin', 'System', 'S')");
        $op->execute(['hash' => $hash]);
    }
}

function pqSetting(string $key, ?string $default = null): string
{
    $stmt = pqDb()->prepare('SELECT value FROM setting WHERE key = :k');
    $stmt->execute(['k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? (string) $default : (string) $v;
}

function pqSettingInt(string $key, int $default): int
{
    $n = (int) pqSetting($key, (string) $default);
    return $n > 0 ? $n : $default;
}

function pqVersion(): string
{
    $latest = 0;
    $roots = [PQ_ROOT . '/app', PQ_ROOT . '/library', PQ_ROOT . '/assets', PQ_ROOT];
    foreach ($roots as $dir) {
        foreach (glob($dir . '/*.{php,css,js,md}', GLOB_BRACE) ?: [] as $file) {
            $t = (int) @filemtime($file);
            if ($t > $latest) {
                $latest = $t;
            }
        }
    }
    if ($latest < 1) {
        $latest = time();
    }
    return date('ymd.Hi', $latest);
}

function pqH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pqUser(): ?array
{
    if (empty($_SESSION['pq_operator_id'])) {
        return null;
    }
    $stmt = pqDb()->prepare("SELECT * FROM operator WHERE id = :id AND active = 'S'");
    $stmt->execute(['id' => (int) $_SESSION['pq_operator_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pqRequireLogin(): array
{
    $user = pqUser();
    if (!$user) {
        header('Location: index.php?p=login');
        exit;
    }
    $page = (string) ($_GET['p'] ?? 'home');
    if (($user['must_change_password'] ?? '') === 'S' && $page !== 'password' && $page !== 'logout') {
        header('Location: index.php?p=password');
        exit;
    }
    return $user;
}

function pqIsAdmin(array $user): bool
{
    return ($user['role'] ?? '') === 'ADMIN';
}

function pqFlash(?string $type = null, ?string $text = null): ?array
{
    if ($type !== null) {
        $_SESSION['pq_flash'] = ['type' => $type, 'text' => $text];
        return null;
    }
    if (empty($_SESSION['pq_flash'])) {
        return null;
    }
    $f = $_SESSION['pq_flash'];
    unset($_SESSION['pq_flash']);
    return $f;
}

function pqRedirect(string $page, array $query = []): void
{
    $query['p'] = $page;
    header('Location: index.php?' . http_build_query($query));
    exit;
}

function pqDisplayName(string $surname, string $given): string
{
    return fotocopiaNomeComeScritto($surname, $given);
}

function pqAccountName(string $surname, string $given): string
{
    return fotocopiaNomeAccount($surname, $given);
}

function pqGenerateCode(PDO $pdo): string
{
    $len = max(4, min(10, pqSettingInt('code_length', 6)));
    $max = (int) str_repeat('9', $len);
    for ($i = 0; $i < 200; $i++) {
        $code = str_pad((string) random_int(0, $max), $len, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM person WHERE code = :c');
        $chk->execute(['c' => $code]);
        if ((int) $chk->fetchColumn() === 0) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate a unique code');
}

function pqCeilingFromCopies($copies, int $step, int $initial, int $max): int
{
    $n = ($copies === null) ? 0 : (int) $copies;
    if ($n < 1) {
        return min($initial, $max);
    }
    $ceiling = (int) (ceil($n / $step) * $step);
    if ($ceiling < $step) {
        $ceiling = $step;
    }
    if ($ceiling > $max) {
        $ceiling = $max;
    }
    return $ceiling;
}

function pqSaveState(PDO $pdo, int $personId, int $printerId, string $code, ?bool $present, $ceiling, $copies, string $error): void
{
    $sel = $pdo->prepare('SELECT id FROM account_state WHERE person_id = :p AND printer_id = :r');
    $sel->execute(['p' => $personId, 'r' => $printerId]);
    $id = $sel->fetchColumn();
    $data = [
        'code' => $code,
        'ceiling' => $ceiling,
        'copies' => $copies,
        'present' => $present === null ? 'N' : ($present ? 'S' : 'N'),
        'error' => $error !== '' ? substr($error, 0, 255) : null,
        'p' => $personId,
        'r' => $printerId,
    ];
    if ($id) {
        $upd = $pdo->prepare('UPDATE account_state SET code=:code, ceiling=:ceiling, copies=:copies, present=:present, synced_at=datetime(\'now\',\'localtime\'), error=:error WHERE person_id=:p AND printer_id=:r');
        $upd->execute($data);
        return;
    }
    $ins = $pdo->prepare('INSERT INTO account_state (person_id, printer_id, code, ceiling, copies, present, synced_at, error) VALUES (:p,:r,:code,:ceiling,:copies,:present,datetime(\'now\',\'localtime\'),:error)');
    $ins->execute($data);
}

function pqLogAction(PDO $pdo, ?int $printerId, ?int $personId, ?int $operatorId, string $code, string $action, $ceiling, $copies, bool $ok, string $error): void
{
    $stmt = $pdo->prepare('INSERT INTO action_log (printer_id, person_id, operator_id, code, action, ceiling, copies, ok, error) VALUES (:r,:p,:o,:c,:a,:ce,:co,:ok,:e)');
    $stmt->execute([
        'r' => $printerId,
        'p' => $personId,
        'o' => $operatorId,
        'c' => $code,
        'a' => $action,
        'ce' => $ceiling,
        'co' => $copies,
        'ok' => $ok ? 'S' : 'N',
        'e' => $error !== '' ? substr($error, 0, 255) : null,
    ]);
}

function pqPrinters(PDO $pdo, int $onlyId = 0): array
{
    $sql = "SELECT * FROM printer WHERE active = 'S'";
    $params = [];
    if ($onlyId > 0) {
        $sql .= ' AND id = :id';
        $params['id'] = $onlyId;
    }
    $sql .= ' ORDER BY name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Run one printer action for one person. Returns ['ok'=>bool,'text'=>string].
 */
function pqActOnPrinter(PDO $pdo, array $person, array $printer, string $action, int $step, ?int $operatorId): array
{
    $code = trim((string) $person['code']);
    $name = pqAccountName((string) $person['surname'], (string) $person['given_name']);
    $initial = pqSettingInt('quota_initial', 1000);
    $max = pqSettingInt('quota_max', 9999999);
    $sess = fotocopiaCcrxApri($printer['ip'], (string) $printer['login'], (string) $printer['password']);
    if (empty($sess['ok'])) {
        pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, null, null, null, (string) $sess['errore']);
        pqLogAction($pdo, (int) $printer['id'], (int) $person['id'], $operatorId, $code, $action, null, null, false, (string) $sess['errore']);
        return ['ok' => false, 'text' => $printer['name'] . ': ' . $sess['errore']];
    }
    $ok = false;
    $error = '';
    $ceiling = null;
    $copies = null;
    try {
        if ($action === 'read' || $action === 'probe') {
            $read = fotocopiaCcrxLeggi($sess, $code);
            $ok = !empty($read['ok']);
            $error = (string) ($read['errore'] ?? '');
            $ceiling = $read['limite'] ?? null;
            $copies = $read['copie'] ?? null;
            if ($action === 'read') {
                pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, !empty($read['presente']), $ceiling, $copies, $error);
            }
        } elseif ($action === 'create') {
            $write = fotocopiaCcrxScrivi($sess, $code, $name, $initial, false);
            $ok = !empty($write['ok']);
            $error = (string) ($write['errore'] ?? '');
            $ceiling = $initial;
            if ($ok) {
                pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, true, $ceiling, null, '');
            }
        } elseif ($action === 'increase') {
            $read = fotocopiaCcrxLeggi($sess, $code);
            if (empty($read['ok']) || empty($read['presente'])) {
                $ok = false;
                $error = empty($read['presente']) ? 'Account missing. Use Create.' : (string) ($read['errore'] ?? 'Read failed');
            } else {
                $current = $read['limite'];
                $base = ($current === null) ? $initial : (int) $current;
                $ceiling = min($max, $base + max(1, $step));
                $write = fotocopiaCcrxScrivi($sess, $code, $name, $ceiling, false);
                $ok = !empty($write['ok']);
                $error = (string) ($write['errore'] ?? '');
                $copies = $read['copie'] ?? null;
                if ($ok) {
                    pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, true, $ceiling, $copies, '');
                }
            }
        } elseif ($action === 'fixst') {
            $read = fotocopiaCcrxLeggi($sess, $code);
            if (empty($read['ok']) || empty($read['presente'])) {
                $ok = false;
                $error = (string) ($read['errore'] ?? 'Not present');
            } elseif ($read['limite'] !== null) {
                $ok = true;
                $ceiling = $read['limite'];
                $copies = $read['copie'] ?? null;
                pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, true, $ceiling, $copies, '');
            } else {
                $ceiling = pqCeilingFromCopies($read['copie'] ?? null, $step, $initial, $max);
                $write = fotocopiaCcrxScrivi($sess, $code, $name, $ceiling, false);
                $ok = !empty($write['ok']);
                $error = (string) ($write['errore'] ?? '');
                $copies = $read['copie'] ?? null;
                if ($ok) {
                    pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, true, $ceiling, $copies, '');
                }
            }
        } elseif ($action === 'delete') {
            $del = fotocopiaCcrxElimina($sess, $code);
            $ok = !empty($del['ok']);
            $error = (string) ($del['errore'] ?? '');
            if ($ok) {
                pqSaveState($pdo, (int) $person['id'], (int) $printer['id'], $code, false, null, null, '');
            }
        } else {
            $error = 'Unknown action';
        }
    } finally {
        fotocopiaCcrxChiudi($sess);
    }
    pqLogAction($pdo, (int) $printer['id'], (int) $person['id'], $operatorId, $code, $action, $ceiling, $copies, $ok, $error);
    $label = $printer['name'] . ': ' . ($ok ? 'ok' : $error);
    if ($ceiling !== null) {
        $label .= ' ceiling ' . $ceiling;
    }
    return ['ok' => $ok, 'text' => $label];
}

function pqEnqueue(PDO $pdo, string $action, ?int $personId, ?int $printerId, ?int $step, int $operatorId): int
{
    $stmt = $pdo->prepare("INSERT INTO job_queue (action, person_id, printer_id, step, status, operator_id) VALUES (:a,:p,:r,:s,'P',:o)");
    $stmt->execute([
        'a' => $action,
        'p' => $personId,
        'r' => $printerId,
        's' => $step,
        'o' => $operatorId,
    ]);
    return (int) $pdo->lastInsertId();
}

function pqRunNextJob(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    $job = $pdo->query("SELECT * FROM job_queue WHERE status = 'P' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$job) {
        $pdo->commit();
        return null;
    }
    $upd = $pdo->prepare("UPDATE job_queue SET status = 'R', started_at = datetime('now','localtime') WHERE id = :id AND status = 'P'");
    $upd->execute(['id' => $job['id']]);
    if ($upd->rowCount() !== 1) {
        $pdo->commit();
        return ['ok' => true, 'text' => 'skipped', 'id' => (int) $job['id']];
    }
    $pdo->commit();

    @set_time_limit(0);
    $lines = [];
    $ok = true;
    try {
        if ($job['action'] === 'probe') {
            foreach (pqPrinters($pdo, (int) ($job['printer_id'] ?? 0)) as $printer) {
                $sess = fotocopiaCcrxApri($printer['ip'], (string) $printer['login'], (string) $printer['password']);
                if (empty($sess['ok'])) {
                    $ok = false;
                    $lines[] = $printer['name'] . ': ' . $sess['errore'];
                    continue;
                }
                $list = fotocopiaCcrxElenco($sess);
                fotocopiaCcrxChiudi($sess);
                if (empty($list['ok'])) {
                    $ok = false;
                    $lines[] = $printer['name'] . ': ' . $list['errore'];
                } else {
                    $lines[] = $printer['name'] . ': ' . count($list['conti']) . ' accounts';
                }
            }
        } else {
            $personStmt = $pdo->prepare('SELECT * FROM person WHERE id = :id');
            $personStmt->execute(['id' => (int) $job['person_id']]);
            $person = $personStmt->fetch();
            if (!$person || trim((string) $person['code']) === '') {
                $ok = false;
                $lines[] = 'Print user or code missing';
            } else {
                $step = (int) ($job['step'] ?? 0);
                if ($step < 1) {
                    $step = pqSettingInt('quota_step', 1000);
                }
                foreach (pqPrinters($pdo, (int) ($job['printer_id'] ?? 0)) as $printer) {
                    $res = pqActOnPrinter($pdo, $person, $printer, (string) $job['action'], $step, (int) ($job['operator_id'] ?? 0));
                    if (empty($res['ok'])) {
                        $ok = false;
                    }
                    $lines[] = $res['text'];
                }
                if ($job['action'] === 'delete' && $ok) {
                    $pdo->prepare('UPDATE person SET code = NULL, updated_at = datetime(\'now\',\'localtime\') WHERE id = :id')->execute(['id' => $person['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $lines[] = $e->getMessage();
    }
    $text = implode("\n", $lines);
    $fin = $pdo->prepare("UPDATE job_queue SET status = :st, finished_at = datetime('now','localtime'), log = :log WHERE id = :id AND status = 'R'");
    $fin->execute(['st' => $ok ? 'D' : 'E', 'log' => $text, 'id' => $job['id']]);
    $open = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status IN ('P','R')")->fetchColumn();
    return ['ok' => $ok, 'text' => $text, 'id' => (int) $job['id'], 'open' => $open];
}
