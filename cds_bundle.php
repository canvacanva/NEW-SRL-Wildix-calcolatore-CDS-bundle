<?php
/**
 * Wildix AI+Data Boost - utilizzo e calcolo bundle
 *
 * Gira sulla PBX:
 *   php cds_bundle.php                     con trascrizioni (default da $TRANSCRIPTIONS)
 *   php cds_bundle.php --trascrizioni=no   solo registrazioni, unita' AI escluse dal calcolo
 *   php cds_bundle.php --trascrizioni=si
 *
 *  1. spazio CDS per tipo (CDS PBX API, Company API Key con scope storage:read)
 *  2. ore di conversazione per mese (DB cdr locale), in due scenari:
 *       A = registrazione e trascrizione di TUTTE le chiamate
 *       B = registrazione e trascrizione delle sole chiamate ESTERNE RICEVUTE (dest_type 2)
 *  3. unita' AI (valore reale dal tab WMS, oppure stima dalle ore di chiamata)
 *  4. bundle necessari secondo le regole Wildix (da marzo 2026):
 *       - senza bundle: 10 unita' AI, 5 GB, retention max 3 mesi
 *       - ogni bundle: 25 unita' AI; spazio 10 GB x bundle x moltiplicatore
 *         (1 = x1, 2-3 = x2.5, 4-10 = x12.5, >10 = x45)
 *       - retention: 1 bundle fino a 1 anno, piu' di 1 bundle fino a 10 anni
 *       - i bundle aumentano da soli in caso di superamento e NON diminuiscono da soli
 */

// ===================== CONFIG =====================
$API_KEY = 'wsk-v1-INCOLLA_QUI';

$DB = ['host' => 'localhost', 'name' => 'cdr', 'user' => 'INCOLLA_UTENTE', 'pass' => 'INCOLLA_PASSWORD'];

$CURRENT_BUNDLES = 6;            // bundle attuali (WMS -> Call and chat history -> AI+Data Boost, "bundles")

// Retention impostata nel tab AI+Data Boost (mesi)
$RETENTION_MONTHS = [
    'backups'           => 12,
    'recordings'        => 24,
    'voicemails'        => 12,
    'ai_transcriptions' => 12,
    'cloud_analytics'   => 12,
];

// Tipi non esposti dalla CDS PBX API: valore in MB letto dal tab AI+Data Boost
$EXTRA_MB = [
    'ai_transcriptions' => 135,
    'cloud_analytics'   => 0,
];

// Unita' AI del mese corrente lette dal tab WMS ("time used"). null = stima dalle ore di chiamata
$AI_UNITS_MONTH = null;

// Calcolare le trascrizioni (unita' AI)? false = solo registrazioni e spazio
$TRANSCRIPTIONS  = true;

// Stima dalle chiamate: quota del tempo di conversazione trascritto e modalita'
$TRANSCRIBED_PCT = 100;          // 100 = tutte le chiamate risposte vengono trascritte (caso peggiore)
$AI_MODE         = 'realtime';   // 'realtime' (1 ora = 1 unita') oppure 'postcall' (1 ora = 0.5 unita')
$MONTHS_HISTORY  = 6;

// Cosa registra OGGI il centralino: serve a stimare lo spazio delle registrazioni nell'altro scenario
// 'all' = tutte le chiamate, 'in' = solo esterne ricevute
$CURRENT_RECORDING = 'all';
// ==================================================

foreach ($argv as $a) {
    if (preg_match('/^--trascrizioni=(si|s|yes|y|1|no|n|0)$/i', $a, $m)) $TRANSCRIPTIONS = in_array(strtolower($m[1]), ['si', 's', 'yes', 'y', '1']);
}

$BASE  = 'https://cds-pbx.wildix.com/api/v1/';
$TYPES = ['recordings', 'voicemails', 'faxes', 'backups', 'traces'];

function hb($b) { $u = ['B','KB','MB','GB','TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return sprintf('%.2f %s', $b, $u[$i]); }
function line($c = '-') { echo str_repeat($c, 78), "\n"; }

function get($url, $key)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $key", 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    if ($code !== 200 || !is_array($json)) {
        fwrite(STDERR, "ERRORE: $url -> HTTP $code " . substr((string)$resp, 0, 200) . "\n");
        exit(1);
    }
    return $json;
}

// spazio incluso (GB) con N bundle
function capacity_gb($n)
{
    if ($n <= 0)  return 5;
    if ($n == 1)  return 10;
    if ($n <= 3)  return $n * 10 * 2.5;
    if ($n <= 10) return $n * 10 * 12.5;
    return $n * 10 * 45;
}
function bundles_for_storage($gb) { for ($n = 0; ; $n++) if (capacity_gb($n) >= $gb) return $n; }
function bundles_for_ai($units)   { return $units <= 10 ? 0 : (int)ceil($units / 25); }
function bundles_for_retention($m){ return $m <= 3 ? 0 : ($m <= 12 ? 1 : 2); }
function ai_units($n)             { return $n <= 0 ? 10 : $n * 25; }

$now = time();
echo "Wildix AI+Data Boost - ", gethostname(), " - ", date('d.m.Y H:i'), "\n";
echo "Trascrizioni: ", $TRANSCRIPTIONS ? "INCLUSE ($AI_MODE, {$TRANSCRIBED_PCT}% del tempo)" : "ESCLUSE (solo registrazioni)", "\n";

// ==================== 1. SPAZIO CDS ====================
line('=');
echo "SPAZIO CDS\n";
line();
printf("%-18s %8s %12s  %-12s %s\n", 'TIPO', 'FILE', 'TOTALE', 'PIU VECCHIO', 'ETA');
$grand = 0; $sizeByType = [];

foreach ($TYPES as $type) {
    $size = 0; $count = 0; $oldest = null; $start = null; $seen = [];
    do {
        $q = ['count' => 1000];
        if ($start !== null) $q['start'] = $start;
        $data = get($BASE . $type . '?' . http_build_query($q), $API_KEY);
        foreach ($data['items'] ?? [] as $it) {
            $size += (int)$it['size']; $count++;
            $oldest = min($oldest ?? $it['created'], $it['created']);
        }
        parse_str((string)parse_url($data['nextPage'] ?? '', PHP_URL_QUERY), $np);
        $start = ($np['start'] ?? '') !== '' ? $np['start'] : null;
        if ($start !== null && isset($seen[$start])) $start = null;
        if ($start !== null) $seen[$start] = true;
    } while ($start !== null && !empty($data['items']));

    $grand += $size;
    $sizeByType[$type] = $size;
    printf("%-18s %8d %12s  %-12s %s\n", $type, $count, hb($size),
        $oldest ? date('d.m.Y', $oldest) : '-', $oldest ? sprintf('%.1f mesi', ($now - $oldest) / (86400 * 30.44)) : '-');
}
foreach ($EXTRA_MB as $type => $mb) {
    $grand += $mb * 1048576;
    printf("%-18s %8s %12s  (valore manuale dal tab WMS)\n", $type, '-', hb($mb * 1048576));
}
line();
$usedGb = $grand / 1073741824;
printf("%-18s %8s %12s\n", 'TOTALE', '', hb($grand));

// ==================== 2. ORE DI CHIAMATA ====================
line('=');
echo "ORE DI CONVERSAZIONE (chiamate risposte, DB cdr)\n";
echo "  A = tutte le chiamate   B = solo esterne ricevute\n";
line();
$coef  = $AI_MODE === 'postcall' ? 0.5 : 1.0;
$share = $TRANSCRIPTIONS ? $TRANSCRIBED_PCT / 100 : 0;
$from  = date('Y-m-01 00:00:00', strtotime('first day of -' . ($MONTHS_HISTORY - 1) . ' month'));
$months = [];

try {
    $pdo = new PDO("mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8", $DB['user'], $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // una riga per chiamata (uniqueid); "esterna ricevuta" se un segmento ha dest_type 2
    $st = $pdo->prepare("
        SELECT DATE_FORMAT(s, '%Y-%m') AS mese,
               COUNT(*)              AS a_calls,
               SUM(b)                AS a_sec,
               SUM(is_in)            AS b_calls,
               SUM(IF(is_in, b, 0))  AS b_sec
        FROM (SELECT uniqueid, MIN(start) AS s, MAX(billsec) AS b, MAX(dest_type = 2) AS is_in
              FROM cdr
              WHERE start >= ? AND disposition = 'ANSWERED'
              GROUP BY uniqueid) t
        GROUP BY mese ORDER BY mese");
    $st->execute([$from]);
    $months = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "DB cdr non disponibile: ", $e->getMessage(), "\n";
}

printf("%-8s | %8s %8s %8s | %8s %8s %8s\n", '', 'A', '', '', 'B', '', '');
printf("%-8s | %8s %8s %8s | %8s %8s %8s\n", 'MESE', 'CHIAMATE', 'ORE', 'UNITA AI', 'CHIAMATE', 'ORE', 'UNITA AI');
$totA = 0; $totB = 0;
foreach ($months as $m) {
    $ha = $m['a_sec'] / 3600; $hb = $m['b_sec'] / 3600;
    $totA += $m['a_sec']; $totB += $m['b_sec'];
    $aiA = $TRANSCRIPTIONS ? sprintf('%.1f', $ha * $share * $coef) : '-';
    $aiB = $TRANSCRIPTIONS ? sprintf('%.1f', $hb * $share * $coef) : '-';
    printf("%-8s | %8d %8.1f %8s | %8d %8.1f %8s\n", $m['mese'], $m['a_calls'], $ha, $aiA, $m['b_calls'], $hb, $aiB);
}
$ratioB = $totA > 0 ? $totB / $totA : null;   // quota di tempo delle esterne ricevute sul totale
if ($ratioB === null) echo "ATTENZIONE: nessun dato CDR, gli scenari A e B non sono stimabili\n";
else printf("Esterne ricevute = %.0f%% del tempo di conversazione totale\n", $ratioB * 100);
if ($TRANSCRIPTIONS) printf("Stima unita' AI: %d%% del tempo trascritto, modalita' %s (x%.1f)\n", $TRANSCRIBED_PCT, $AI_MODE, $coef);
else echo "Unita' AI non calcolate (trascrizioni escluse)\n";

// ==================== 3. UNITA' AI MESE CORRENTE ====================
$projA = $projB = 0;
if ($TRANSCRIPTIONS) {
line('=');
echo "UNITA' AI - MESE CORRENTE (", date('m/Y'), ")\n";
line();
$curMonth = date('Y-m');
$dayFrac  = (time() - strtotime(date('Y-m-01'))) / (date('t') * 86400);
$curA = 0; $curB = 0;
foreach ($months as $m) if ($m['mese'] === $curMonth) { $curA = $m['a_sec'] / 3600; $curB = $m['b_sec'] / 3600; }

$sofarA = $curA * $share * $coef;
$sofarB = $curB * $share * $coef;
$projA  = $dayFrac > 0 ? $sofarA / $dayFrac : $sofarA;
$projB  = $dayFrac > 0 ? $sofarB / $dayFrac : $sofarB;
printf("%-30s %10s %10s\n", '', 'A', 'B');
printf("%-30s %10.1f %10.1f\n", "Finora (stima da CDR)", $sofarA, $sofarB);
printf("%-30s %10.1f %10.1f   (%.0f%% del mese trascorso)\n", "Proiezione fine mese", $projA, $projB, $dayFrac * 100);
if ($AI_UNITS_MONTH !== null) {
    printf("Reale dal tab WMS finora: %.1f unita' (proiezione %.1f)\n", $AI_UNITS_MONTH, $dayFrac > 0 ? $AI_UNITS_MONTH / $dayFrac : $AI_UNITS_MONTH);
}

}

// ==================== 4. SPAZIO NEI DUE SCENARI ====================
line('=');
echo "SPAZIO CDS STIMATO\n";
line();
$recBytes   = $sizeByType['recordings'] ?? 0;
$otherBytes = $grand - $recBytes;
// registrazioni proporzionali al tempo di conversazione registrato
if ($ratioB === null) {
    $recA = $recB = $recBytes;   // senza CDR non si puo' stimare la differenza
} elseif ($CURRENT_RECORDING === 'all') {
    $recA = $recBytes;
    $recB = $recBytes * $ratioB;
} else {
    $recB = $recBytes;
    $recA = $ratioB > 0 ? $recBytes / $ratioB : $recBytes;
}
$gbA = ($recA + $otherBytes) / 1073741824;
$gbB = ($recB + $otherBytes) / 1073741824;
printf("%-30s %12s %12s\n", '', 'A', 'B');
printf("%-30s %12s %12s\n", 'Registrazioni', hb($recA), hb($recB));
printf("%-30s %12s %12s\n", 'Altri file', hb($otherBytes), hb($otherBytes));
printf("%-30s %12s %12s\n", 'Totale', hb($recA + $otherBytes), hb($recB + $otherBytes));
printf("Oggi si registra: %s (valore reale, l'altro scenario e' stimato in proporzione alle ore)\n",
    $CURRENT_RECORDING === 'all' ? 'tutte le chiamate = A' : 'solo esterne ricevute = B');

// ==================== 5. BUNDLE ====================
line('=');
echo "BUNDLE\n";
line();
$maxRet = max($RETENTION_MONTHS);
$bR = bundles_for_retention($maxRet);

$scen = [];
foreach (['A' => [$gbA, $projA], 'B' => [$gbB, $projB]] as $k => [$gb, $ai]) {
    $bS = bundles_for_storage($gb);
    $bA = bundles_for_ai($ai);
    $scen[$k] = ['gb' => $gb, 'ai' => $ai, 'bS' => $bS, 'bA' => $bA, 'need' => max($bS, $bA, $bR)];
}

printf("%-34s %10s %10s\n", 'CRITERIO', 'A', 'B');
printf("%-34s %10d %10d\n", 'Spazio CDS', $scen['A']['bS'], $scen['B']['bS']);
printf("%-34s %10s %10s\n", '   GB stimati', sprintf('%.1f', $scen['A']['gb']), sprintf('%.1f', $scen['B']['gb']));
if ($TRANSCRIPTIONS) {
    printf("%-34s %10d %10d\n", "Unita' AI (proiezione mese)", $scen['A']['bA'], $scen['B']['bA']);
    printf("%-34s %10s %10s\n", "   unita' stimate", sprintf('%.1f', $scen['A']['ai']), sprintf('%.1f', $scen['B']['ai']));
} else {
    printf("%-34s %10s %10s   (trascrizioni escluse)\n", "Unita' AI", '-', '-');
}
printf("%-34s %10d %10d   (retention massima %d mesi)\n", 'Retention', $bR, $bR, $maxRet);
line();
printf("%-34s %10d %10d\n", 'NECESSARI', $scen['A']['need'], $scen['B']['need']);
printf("%-34s %10d %10d\n", 'ATTUALI', $CURRENT_BUNDLES, $CURRENT_BUNDLES);
printf("%-34s %10d %10d   (i bundle non diminuiscono da soli)\n", 'FATTURATI SE NON SI INTERVIENE',
    max($CURRENT_BUNDLES, $scen['A']['need']), max($CURRENT_BUNDLES, $scen['B']['need']));

foreach ($scen as $k => $sc) {
    $n = $sc['need'];
    printf("\nScenario %s: %d bundle = %s GB e %d unita' AI", $k, $n, capacity_gb($n), ai_units($n));
    if ($n < $CURRENT_BUNDLES)      printf(" -> riducibili di %d rispetto agli attuali", $CURRENT_BUNDLES - $n);
    elseif ($n > $CURRENT_BUNDLES)  printf(" -> Wildix aggiungerebbe %d bundle", $n - $CURRENT_BUNDLES);
}
echo "\n\nNote:\n";
if ($TRANSCRIPTIONS) echo "- La stima AI suppone che ogni chiamata registrata venga anche trascritta (", $TRANSCRIBED_PCT, "%).\n";
else echo "- Trascrizioni escluse: se il cliente le usa davvero, le unita' AI possono far salire i bundle.\n";
echo "- Lo spazio dello scenario non attivo e' stimato in proporzione alle ore di conversazione.\n";
echo "- I bundle valgono per l'azienda (tutte le PBX del WMS Network); questo calcolo vede solo questa PBX.\n";
