<?php
session_start();
include 'config.php';

function formatDateFi($value) {
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return escapeInput((string) $value);
    }

    return date('d.m.Y', $timestamp);
}

function getInvoiceDescription($invoiceData) {
    $kohde = trim((string) ($invoiceData['kohde_osoite'] ?? ''));
    $sopimusId = $invoiceData['sopimus_id'] ?? '';
    $laskunNro = $invoiceData['laskun_nro'] ?? '';
    $description = 'Tuntityolasku kohteesta';

    if ($kohde !== '') {
        $description .= ' ' . $kohde;
    }

    $details = [];
    if ($sopimusId !== '') {
        $details[] = 'sopimus ' . $sopimusId;
    }
    if ($laskunNro !== '') {
        $details[] = 'lasku ' . $laskunNro;
    }

    if (!empty($details)) {
        $description .= ' (' . implode(', ', $details) . ')';
    }

    return $description;
}

function loadCompanyInfo() {
    $result = executeQuery(
        "SELECT yritys_id, nimi, osoite, puh_nro, sahkoposti, y_tunnus, tilinumero
         FROM yritys
         ORDER BY yritys_id
         LIMIT 1"
    );
    $company = fetchOne($result);

    if (!$company) {
        return [
            'yritys_id' => null,
            'nimi' => 'Tmi Sahkotarsky',
            'osoite' => '',
            'puh_nro' => '',
            'sahkoposti' => '',
            'y_tunnus' => '',
            'tilinumero' => ''
        ];
    }

    return $company;
}

function loadWorksites() {
    $result = executeQuery(
        "SELECT tyokohde_id, kohde_osoite
         FROM tyokohde
         ORDER BY kohde_osoite"
    );

    return fetchAll($result);
}

function loadContracts($tyokohdeId) {
    if (!$tyokohdeId) {
        return [];
    }

    $result = executeQuery(
        "SELECT sopimus_id, tyokohde_id, tila, tyyppi, pvm, urakka_tyo_netto, urakka_tarvikkeet_netto
         FROM sopimus
         WHERE tyokohde_id = $1 AND tila = 'kesken'
         ORDER BY pvm DESC, sopimus_id DESC",
        [$tyokohdeId]
    );

    return fetchAll($result);
}

function loadContractDetails($sopimusId) {
    if (!$sopimusId) {
        return null;
    }

    $result = executeQuery(
        "SELECT
            s.sopimus_id,
            s.tyokohde_id,
            s.tila,
            s.tyyppi,
            s.pvm,
            s.urakka_tyo_netto,
            s.urakka_tarvikkeet_netto,
            tk.kohde_osoite,
            a.as_nimi,
            a.as_osoite,
            a.puh_nro,
            a.sahkoposti
         FROM sopimus s
         JOIN tyokohde tk ON s.tyokohde_id = tk.tyokohde_id
         JOIN asiakas a ON tk.asiakas_id = a.asiakas_id
         WHERE s.sopimus_id = $1",
        [$sopimusId]
    );

    return fetchOne($result);
}

function loadWorkSummary($sopimusId) {
    if (!$sopimusId) {
        return [];
    }

    $result = executeQuery(
        "SELECT
            ts.sopimus_id,
            ts.tuntityo_id,
            th.nimi AS tuntityo_nimi,
            ROUND(SUM(ts.maara), 2) AS total_maara,
            ROUND(COALESCE(AVG(ts.alennusprosentti), 0), 2) AS alennusprosentti,
            ROUND(th.hinta_netto, 2) AS yksikkohinta_netto,
            ROUND(SUM(th.hinta_netto * ts.maara), 2) AS total_netto_ilman_alennusta,
            ROUND(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0)), 2) AS total_netto,
            ROUND(SUM(th.hinta_netto * ts.maara) - SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0)), 2) AS alennus_summa
         FROM tyo_suorite ts
         JOIN tuntityo_hinnasto th ON ts.tuntityo_id = th.tuntityo_id
         WHERE ts.sopimus_id = $1
         GROUP BY ts.sopimus_id, ts.tuntityo_id, th.nimi, th.hinta_netto
         ORDER BY th.nimi",
        [$sopimusId]
    );

    return fetchAll($result);
}

function loadMaterialSummary($sopimusId) {
    if (!$sopimusId) {
        return [];
    }

    $result = executeQuery(
        "SELECT
            kt.sopimus_id,
            kt.tarvike_id,
            tr.tarvike_nimi,
            COALESCE(tr.yksikko, 'kpl') AS yksikko,
            ROUND(tr.myyntihinta, 2) AS yksikkohinta_netto,
            ROUND(SUM(kt.maara), 2) AS total_maara,
            ROUND(COALESCE(AVG(kt.alennusprosentti), 0), 2) AS alennusprosentti,
            ROUND(SUM(kt.maara * tr.myyntihinta), 2) AS total_netto_ilman_alennusta,
            ROUND(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0)), 2) AS total_netto,
            ROUND(SUM(kt.maara * tr.myyntihinta) - SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0)), 2) AS alennus_summa
         FROM kaytetyt_tarvikkeet kt
         JOIN tarvikkeet tr ON kt.tarvike_id = tr.tarvike_id
         WHERE kt.sopimus_id = $1
         GROUP BY kt.sopimus_id, kt.tarvike_id, tr.tarvike_nimi, tr.yksikko, tr.myyntihinta
         ORDER BY tr.tarvike_nimi",
        [$sopimusId]
    );

    return fetchAll($result);
}

function getContractTotalsFromRows($workRows, $materialRows) {
    $totals = [
        'tyo_netto' => 0.0,
        'tarvike_netto' => 0.0,
        'tyo_netto_ilman_alennusta' => 0.0,
        'tarvike_netto_ilman_alennusta' => 0.0,
        'alennus_summa' => 0.0
    ];

    foreach ($workRows as $row) {
        $totals['tyo_netto'] += (float) ($row['total_netto'] ?? 0);
        $totals['tyo_netto_ilman_alennusta'] += (float) ($row['total_netto_ilman_alennusta'] ?? 0);
    }

    foreach ($materialRows as $row) {
        $totals['tarvike_netto'] += (float) ($row['total_netto'] ?? 0);
        $totals['tarvike_netto_ilman_alennusta'] += (float) ($row['total_netto_ilman_alennusta'] ?? 0);
    }

    $totals['alennus_summa'] =
        ($totals['tyo_netto_ilman_alennusta'] + $totals['tarvike_netto_ilman_alennusta'])
        - ($totals['tyo_netto'] + $totals['tarvike_netto']);

    return $totals;
}

function loadContractInvoices($sopimusId) {
    if (!$sopimusId) {
        return [];
    }

    $result = executeQuery(
        "SELECT lasku_id, laskun_nro, pvm, erapaiva, maksu_pvm, viitenumero
         FROM lasku
         WHERE sopimus_id = $1
         ORDER BY lasku_id DESC",
        [$sopimusId]
    );

    return fetchAll($result);
}

function getUrakkaInstallmentInfo($sopimusId, $invoiceId = null) {
    if (!$sopimusId) {
        return ['count' => 1, 'index' => null];
    }

    $countResult = executeQuery(
        "SELECT COUNT(*) AS installment_count
         FROM lasku
         WHERE sopimus_id = $1
           AND muistutus_nro = 0
           AND edellinen_lasku_id IS NULL",
        [$sopimusId]
    );
    $countRow = fetchOne($countResult);
    $count = (int) ($countRow['installment_count'] ?? 0);
    if ($count <= 0) {
        $count = 1;
    }

    $index = null;
    if ($invoiceId) {
        $indexResult = executeQuery(
            "WITH ordered AS (
                SELECT lasku_id,
                       ROW_NUMBER() OVER (ORDER BY pvm ASC, lasku_id ASC) AS era_index
                FROM lasku
                WHERE sopimus_id = $1
                  AND muistutus_nro = 0
                  AND edellinen_lasku_id IS NULL
             )
             SELECT era_index
             FROM ordered
             WHERE lasku_id = $2
             LIMIT 1",
            [$sopimusId, $invoiceId]
        );
        $indexRow = fetchOne($indexResult);
        if ($indexRow && isset($indexRow['era_index'])) {
            $index = (int) $indexRow['era_index'];
        }
    }

    return ['count' => $count, 'index' => $index];
}

function getNextInvoiceNumber() {
    $result = executeQuery("SELECT COALESCE(MAX(laskun_nro), 0) + 1 AS next_number FROM lasku");
    $row = fetchOne($result);

    return (int) ($row['next_number'] ?? 1);
}

function loadInvoiceById($invoiceId) {
    if (!$invoiceId) {
        return null;
    }

    $result = executeQuery(
        "SELECT
            l.lasku_id,
            l.laskun_nro,
                l.edellinen_lasku_id,
                l.muistutus_nro,
            l.pvm,
            l.erapaiva,
            l.maksu_pvm,
            l.viitenumero,
            l.viivastyskorko,
            l.laskutuslisa,
                lp.laskun_nro AS edellinen_laskun_nro,
            s.sopimus_id,
            s.tyokohde_id,
            s.tyyppi,
            s.tila,
            tk.kohde_osoite,
            a.as_nimi,
            a.as_osoite,
            a.puh_nro,
            a.sahkoposti
         FROM lasku l
            LEFT JOIN lasku lp ON l.edellinen_lasku_id = lp.lasku_id
         JOIN sopimus s ON l.sopimus_id = s.sopimus_id
         JOIN tyokohde tk ON s.tyokohde_id = tk.tyokohde_id
         JOIN asiakas a ON tk.asiakas_id = a.asiakas_id
         WHERE l.lasku_id = $1",
        [$invoiceId]
    );

    return fetchOne($result);
}

function loadInvoiceLineItems($sopimusId, $invoiceId = null) {
    if (!$sopimusId) {
        return [];
    }

    $contractResult = executeQuery(
        "SELECT tyyppi, urakka_tyo_netto, urakka_tarvikkeet_netto
         FROM sopimus
         WHERE sopimus_id = $1
         LIMIT 1",
        [$sopimusId]
    );
    $contract = fetchOne($contractResult);

    if ($contract && ($contract['tyyppi'] ?? '') === 'urakka') {
        $installmentInfo = getUrakkaInstallmentInfo($sopimusId, $invoiceId);
        $divider = max(1, (int) ($installmentInfo['count'] ?? 1));
        $eraIndex = $installmentInfo['index'];
        $eraSuffix = $eraIndex !== null ? ' (Erä ' . $eraIndex . '/' . $divider . ')' : ($divider > 1 ? ' (Eräjako ' . $divider . ')' : '');

        $tyoNetto = ((float) ($contract['urakka_tyo_netto'] ?? 0)) / $divider;
        $tarvikeNetto = ((float) ($contract['urakka_tarvikkeet_netto'] ?? 0)) / $divider;
        $alvPercent = 24.0;

        return [
            [
                'jarjestys' => 1,
                'rivityyppi' => 'tyo',
                'nimike' => 'Urakka, työosuus' . $eraSuffix,
                'maara' => 1,
                'yksikko' => 'erä',
                'yksikkohinta_netto' => round($tyoNetto, 2),
                'alennus_prosentti' => 0,
                'alv_prosentti' => $alvPercent,
                'total_netto_ilman_alennusta' => round($tyoNetto, 2),
                'total_netto' => round($tyoNetto, 2),
                'total_alv' => round($tyoNetto * ($alvPercent / 100.0), 2),
                'total_brutto' => round($tyoNetto * (1 + $alvPercent / 100.0), 2)
            ],
            [
                'jarjestys' => 2,
                'rivityyppi' => 'tarvike',
                'nimike' => 'Urakka, tarvikkeet' . $eraSuffix,
                'maara' => 1,
                'yksikko' => 'erä',
                'yksikkohinta_netto' => round($tarvikeNetto, 2),
                'alennus_prosentti' => 0,
                'alv_prosentti' => $alvPercent,
                'total_netto_ilman_alennusta' => round($tarvikeNetto, 2),
                'total_netto' => round($tarvikeNetto, 2),
                'total_alv' => round($tarvikeNetto * ($alvPercent / 100.0), 2),
                'total_brutto' => round($tarvikeNetto * (1 + $alvPercent / 100.0), 2)
            ]
        ];
    }

    $result = executeQuery(
        "SELECT *
         FROM (
            SELECT
                1 AS jarjestys,
                'tyo' AS rivityyppi,
                th.nimi AS nimike,
                ROUND(SUM(ts.maara), 2) AS maara,
                'h' AS yksikko,
                ROUND(
                    SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0))
                    / NULLIF(SUM(ts.maara), 0),
                    2
                ) AS yksikkohinta_netto,
                ROUND(
                    COALESCE(
                        (1 - (SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0)) / NULLIF(SUM(th.hinta_netto * ts.maara), 0))) * 100,
                        0
                    ),
                    2
                ) AS alennus_prosentti,
                ROUND(th.alv_prosentti, 2) AS alv_prosentti,
                ROUND(SUM(th.hinta_netto * ts.maara), 2) AS total_netto_ilman_alennusta,
                ROUND(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0)), 2) AS total_netto,
                ROUND(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0) * (th.alv_prosentti / 100.0)), 2) AS total_alv,
                ROUND(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti, 0) / 100.0) * (1 + th.alv_prosentti / 100.0)), 2) AS total_brutto
            FROM tyo_suorite ts
            JOIN tuntityo_hinnasto th ON ts.tuntityo_id = th.tuntityo_id
            WHERE ts.sopimus_id = $1
            GROUP BY th.nimi, th.alv_prosentti

            UNION ALL

            SELECT
                2 AS jarjestys,
                'tarvike' AS rivityyppi,
                tr.tarvike_nimi AS nimike,
                ROUND(SUM(kt.maara), 2) AS maara,
                COALESCE(tr.yksikko, 'kpl') AS yksikko,
                ROUND(
                    SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0))
                    / NULLIF(SUM(kt.maara), 0),
                    2
                ) AS yksikkohinta_netto,
                ROUND(
                    COALESCE(
                        (1 - (SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0)) / NULLIF(SUM(kt.maara * tr.myyntihinta), 0))) * 100,
                        0
                    ),
                    2
                ) AS alennus_prosentti,
                ROUND(COALESCE(tr.alv_prosentti, 0), 2) AS alv_prosentti,
                ROUND(SUM(kt.maara * tr.myyntihinta), 2) AS total_netto_ilman_alennusta,
                ROUND(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0)), 2) AS total_netto,
                ROUND(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0) * (COALESCE(tr.alv_prosentti, 0) / 100.0)), 2) AS total_alv,
                ROUND(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti, 0) / 100.0) * (1 + COALESCE(tr.alv_prosentti, 0) / 100.0)), 2) AS total_brutto
            FROM kaytetyt_tarvikkeet kt
            JOIN tarvikkeet tr ON kt.tarvike_id = tr.tarvike_id
            WHERE kt.sopimus_id = $1
            GROUP BY tr.tarvike_nimi, tr.yksikko, tr.alv_prosentti
         ) rivit
         ORDER BY jarjestys, nimike",
        [$sopimusId]
    );

    return fetchAll($result);
}

function calculateInvoiceTotals($lineItems) {
    $totals = [
        'netto' => 0.0,
        'netto_ilman_alennusta' => 0.0,
        'alennus_summa' => 0.0,
        'alv' => 0.0,
        'brutto' => 0.0,
        'tyo_netto' => 0.0,
        'tyo_netto_ilman_alennusta' => 0.0,
        'tarvike_netto' => 0.0,
        'tarvike_netto_ilman_alennusta' => 0.0
    ];

    foreach ($lineItems as $item) {
        $netto = (float) $item['total_netto'];
        $nettoIlmanAlennusta = (float) ($item['total_netto_ilman_alennusta'] ?? $netto);
        $alv = (float) $item['total_alv'];
        $brutto = (float) $item['total_brutto'];

        $totals['netto'] += $netto;
        $totals['netto_ilman_alennusta'] += $nettoIlmanAlennusta;
        $totals['alv'] += $alv;
        $totals['brutto'] += $brutto;

        if (($item['rivityyppi'] ?? '') === 'tyo') {
            $totals['tyo_netto'] += $netto;
            $totals['tyo_netto_ilman_alennusta'] += $nettoIlmanAlennusta;
        } else {
            $totals['tarvike_netto'] += $netto;
            $totals['tarvike_netto_ilman_alennusta'] += $nettoIlmanAlennusta;
        }
    }

    $totals['alennus_summa'] = $totals['netto_ilman_alennusta'] - $totals['netto'];

    return $totals;
}

function getInvoiceTypeLabel($invoiceData) {
    $muistutusNro = (int) ($invoiceData['muistutus_nro'] ?? 0);

    if ($muistutusNro <= 0) {
        return 'Peruslasku';
    }

    if ($muistutusNro === 1) {
        return 'Muistutuslasku';
    }

    return 'Karhulasku';
}

function sanitizeIban($iban) {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $iban));
}

function sanitizeReferenceDigits($reference) {
    return preg_replace('/\D/', '', (string) $reference);
}

function calculateMod97($numericString) {
    $remainder = 0;
    $length = strlen($numericString);

    for ($i = 0; $i < $length; $i++) {
        $remainder = (($remainder * 10) + (int) $numericString[$i]) % 97;
    }

    return $remainder;
}

function toRfReference($referenceDigits) {
    $digits = ltrim(sanitizeReferenceDigits($referenceDigits), '0');
    if ($digits === '') {
        return '';
    }

    $checkBase = $digits . '271500';
    $check = 98 - calculateMod97($checkBase);
    $checkFormatted = str_pad((string) $check, 2, '0', STR_PAD_LEFT);

    return 'RF' . $checkFormatted . $digits;
}

function buildEpcPayload($invoiceData, $companyInfo, $amount) {
    $iban = sanitizeIban($companyInfo['tilinumero'] ?? '');
    if ($iban === '' || strlen($iban) < 15) {
        return '';
    }

    $name = trim((string) ($companyInfo['nimi'] ?? ''));
    if ($name === '') {
        $name = 'Maksunsaaja';
    }

    $referenceDigits = sanitizeReferenceDigits($invoiceData['viitenumero'] ?? '');
    $rfReference = toRfReference($referenceDigits);
    $amountEur = number_format(max(0, (float) $amount), 2, '.', '');

    $lines = [
        'BCD',
        '002',
        '1',
        'SCT',
        '',
        mb_substr($name, 0, 70),
        $iban,
        'EUR' . $amountEur,
        '',
        $rfReference,
        'Lasku ' . ($invoiceData['laskun_nro'] ?? '')
    ];

    return implode("\n", $lines);
}

function buildFinnishVirtualBarcode($companyInfo, $invoiceData, $amount) {
    $iban = sanitizeIban($companyInfo['tilinumero'] ?? '');
    $referenceDigits = sanitizeReferenceDigits($invoiceData['viitenumero'] ?? '');

    if (strlen($iban) < 18) {
        return '';
    }

    $ibanDigits = preg_replace('/\D/', '', substr($iban, 2));
    $ibanDigits = str_pad(substr($ibanDigits, 0, 16), 16, '0', STR_PAD_LEFT);

    $amountCents = (int) round(max(0, (float) $amount) * 100);
    $euros = (int) floor($amountCents / 100);
    $cents = $amountCents % 100;
    $amountPart = str_pad((string) $euros, 6, '0', STR_PAD_LEFT) . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);

    $referencePart = str_pad(substr($referenceDigits, -23), 23, '0', STR_PAD_LEFT);

    $dueDate = '';
    if (!empty($invoiceData['erapaiva'])) {
        $ts = strtotime((string) $invoiceData['erapaiva']);
        if ($ts !== false) {
            $dueDate = date('ymd', $ts);
        }
    }
    if ($dueDate === '') {
        $dueDate = '000000';
    }

    return '5' . $ibanDigits . $amountPart . $referencePart . $dueDate;
}

function renderInvoicePreview($invoiceData, $companyInfo, $lineItems, $autoPrint = false) {
    $totals = calculateInvoiceTotals($lineItems);
    $laskutuslisa = (float) ($invoiceData['laskutuslisa'] ?? 0);
    $viivastyskorko = (float) ($invoiceData['viivastyskorko'] ?? 0);
    $lisaKulut = $laskutuslisa + $viivastyskorko;
    $loppusumma = $totals['brutto'] + $lisaKulut;
    $tyoBrutto = $totals['tyo_netto'] * 1.24;
    $kotitalousvahennys = $tyoBrutto * 0.35;
    $filename = $invoiceData['sopimus_id'] . '_' . $invoiceData['lasku_id'] . '_' . $invoiceData['pvm'];
    $description = getInvoiceDescription($invoiceData);
    $epcPayload = buildEpcPayload($invoiceData, $companyInfo, $loppusumma);
    $epcQrUrl = '';
    if ($epcPayload !== '') {
        $epcQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&data=' . rawurlencode($epcPayload);
    }
    $virtualBarcode = buildFinnishVirtualBarcode($companyInfo, $invoiceData, $loppusumma);
    $copyPaymentText = implode("\n", [
        'Saaja: ' . ($companyInfo['nimi'] ?? ''),
        'IBAN: ' . sanitizeIban($companyInfo['tilinumero'] ?? ''),
        'Viite: ' . ($invoiceData['viitenumero'] ?? ''),
        'Summa: ' . number_format($loppusumma, 2, ',', ' ') . ' EUR',
        'Erapaiva: ' . formatDateFi($invoiceData['erapaiva'] ?? ''),
        'Virtuaaliviivakoodi: ' . $virtualBarcode
    ]);
    ?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeInput($filename); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="invoice-print-body">
    <div class="invoice-document-wrapper">
        <div class="print-toolbar no-print">
            <a class="btn btn-secondary" href="lasku.php?tyokohde_id=<?php echo urlencode((string) $invoiceData['tyokohde_id']); ?>&sopimus_id=<?php echo urlencode((string) $invoiceData['sopimus_id']); ?>&invoice_id=<?php echo urlencode((string) $invoiceData['lasku_id']); ?>">Takaisin laskulle</a>
            <button class="btn btn-primary" type="button" onclick="window.print()">Tulosta / tallenna PDF</button>
        </div>

        <main class="invoice-document">
            <section class="invoice-top-grid">
                <div class="invoice-brand-block">
                    <div class="invoice-logo">TMI Sahkotarsky</div>
                    <div class="invoice-return-address">
                        <p class="invoice-label">Palautusosoite</p>
                        <p><?php echo escapeInput($companyInfo['nimi']); ?></p>
                        <?php if (!empty($companyInfo['osoite'])): ?>
                            <p><?php echo nl2br(escapeInput($companyInfo['osoite'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="invoice-metadata">
                    <h1>LASKU</h1>
                    <table class="invoice-meta-table">
                        <tr>
                            <th>Laskun paivays</th>
                            <td><?php echo escapeInput(formatDateFi($invoiceData['pvm'])); ?></td>
                            <th>Laskutyyppi</th>
                            <td><?php echo escapeInput(getInvoiceTypeLabel($invoiceData)); ?></td>
                        </tr>
                        <tr>
                            <th>Laskun numero</th>
                            <td><?php echo escapeInput((string) $invoiceData['laskun_nro']); ?></td>
                            <th>Edellinen lasku</th>
                            <td><?php echo !empty($invoiceData['edellinen_laskun_nro']) ? escapeInput((string) $invoiceData['edellinen_laskun_nro']) : '-'; ?></td>
                        </tr>
                        <tr>
                            <th>Maksuehto</th>
                            <td>14 pv netto</td>
                            <th>Viitteemme</th>
                            <td>Sopimus <?php echo escapeInput((string) $invoiceData['sopimus_id']); ?></td>
                        </tr>
                        <tr>
                            <th>Erapaiva</th>
                            <td><?php echo escapeInput(formatDateFi($invoiceData['erapaiva'])); ?></td>
                            <th>Viitteenne</th>
                            <td></td>
                        </tr>
                        <tr>
                            <th>Viitenumero</th>
                            <td><?php echo escapeInput((string) $invoiceData['viitenumero']); ?></td>
                            <th>Laskutuslisa / Viivastyskorko</th>
                            <td><?php echo formatCurrency($laskutuslisa); ?> / <?php echo formatCurrency($viivastyskorko); ?></td>
                        </tr>
                    </table>
                </div>
            </section>

            <section class="invoice-customer-card">
                <h2><?php echo escapeInput($invoiceData['as_nimi']); ?></h2>
                <p><?php echo nl2br(escapeInput($invoiceData['as_osoite'])); ?></p>
                <?php if (!empty($invoiceData['puh_nro'])): ?>
                    <p>Puh: <?php echo escapeInput($invoiceData['puh_nro']); ?></p>
                <?php endif; ?>
                <?php if (!empty($invoiceData['sahkoposti'])): ?>
                    <p>Sahkoposti: <?php echo escapeInput($invoiceData['sahkoposti']); ?></p>
                <?php endif; ?>
            </section>

            <section class="invoice-description-box">
                <p><?php echo escapeInput($description); ?></p>
                <p class="invoice-description-meta">Kohde: <?php echo escapeInput($invoiceData['kohde_osoite']); ?> | Sopimustyyppi: <?php echo escapeInput($invoiceData['tyyppi']); ?> | Sopimuksen tila: <?php echo escapeInput($invoiceData['tila']); ?></p>
            </section>

            <section>
                <table class="invoice-line-table">
                    <thead>
                        <tr>
                            <th>Nimike</th>
                            <th>Maara</th>
                            <th>Yks.</th>
                            <th>A'hinta EUR</th>
                            <th>Alennus %</th>
                            <th>Alv %</th>
                            <th>Veroton yht. EUR</th>
                            <th>Verollinen yht. EUR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lineItems as $item): ?>
                            <tr>
                                <td>
                                    <?php echo escapeInput($item['nimike']); ?>
                                    <span class="invoice-row-type"><?php echo ($item['rivityyppi'] === 'tyo') ? 'Tyo' : 'Tarvike'; ?></span>
                                </td>
                                <td><?php echo escapeInput((string) $item['maara']); ?></td>
                                <td><?php echo escapeInput($item['yksikko']); ?></td>
                                <td><?php echo escapeInput(number_format((float) $item['yksikkohinta_netto'], 2, ',', ' ')); ?></td>
                                <td><?php echo escapeInput(number_format((float) ($item['alennus_prosentti'] ?? 0), 2, ',', ' ')); ?></td>
                                <td><?php echo escapeInput(number_format((float) $item['alv_prosentti'], 2, ',', ' ')); ?></td>
                                <td><?php echo escapeInput(number_format((float) $item['total_netto'], 2, ',', ' ')); ?></td>
                                <td><?php echo escapeInput(number_format((float) $item['total_brutto'], 2, ',', ' ')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="invoice-summary-grid">
                <div class="invoice-summary-notes">
                    <p>Hinta ilman alennuksia (netto): <?php echo formatCurrency($totals['netto_ilman_alennusta']); ?></p>
                    <p>Alennukset yhteensa (netto): <?php echo formatCurrency($totals['alennus_summa']); ?></p>
                    <p>Tyon osuus netto: <?php echo formatCurrency($totals['tyo_netto']); ?></p>
                    <p>Tarvikkeiden osuus netto: <?php echo formatCurrency($totals['tarvike_netto']); ?></p>
                    <p>Kotitalousvahennys (35 % tyon verollisesta osuudesta): <?php echo formatCurrency($kotitalousvahennys); ?></p>
                    <p><a href="https://www.vero.fi/henkiloasiakkaat/vahennykset/kotitalousvahennys/kotitalousvahennyksen-maara/" target="_blank" rel="noopener noreferrer">Kotitalousvahennyksen ohje vero.fi-sivulla</a></p>
                    <p>Lasku perustuu sopimukselle kirjattuihin tuntityosuoritteisiin ja kaytettyihin tarvikkeisiin.</p>
                </div>
                <div class="invoice-summary-totals">
                    <table>
                        <tr>
                            <th>Veroton ilman alennuksia EUR</th>
                            <td><?php echo escapeInput(number_format($totals['netto_ilman_alennusta'], 2, ',', ' ')); ?></td>
                        </tr>
                        <tr>
                            <th>Alennukset yhteensa EUR</th>
                            <td><?php echo escapeInput(number_format($totals['alennus_summa'], 2, ',', ' ')); ?></td>
                        </tr>
                        <tr>
                            <th>Veroton yhteensa EUR</th>
                            <td><?php echo escapeInput(number_format($totals['netto'], 2, ',', ' ')); ?></td>
                        </tr>
                        <tr>
                            <th>ALV yhteensa EUR</th>
                            <td><?php echo escapeInput(number_format($totals['alv'], 2, ',', ' ')); ?></td>
                        </tr>
                        <?php if ($lisaKulut > 0): ?>
                        <tr>
                            <th>Laskutuslisa + viivastyskorko EUR</th>
                            <td><?php echo escapeInput(number_format($lisaKulut, 2, ',', ' ')); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="invoice-grand-total">
                            <th>Verollinen yhteensa EUR</th>
                            <td><?php echo escapeInput(number_format($loppusumma, 2, ',', ' ')); ?></td>
                        </tr>
                    </table>
                </div>
            </section>

            <section class="invoice-bottom-grid">
                <div class="invoice-bank-box">
                    <table class="invoice-meta-table compact">
                        <tr>
                            <th>IBAN</th>
                            <td><?php echo escapeInput($companyInfo['tilinumero']); ?></td>
                            <th>Erapaiva</th>
                            <td><?php echo escapeInput(formatDateFi($invoiceData['erapaiva'])); ?></td>
                        </tr>
                        <tr>
                            <th>Viitenumero</th>
                            <td><?php echo escapeInput((string) $invoiceData['viitenumero']); ?></td>
                            <th>Yhteensa EUR</th>
                            <td><?php echo escapeInput(number_format($loppusumma, 2, ',', ' ')); ?></td>
                        </tr>
                    </table>
                </div>

                <div class="invoice-recipient-box">
                    <div>
                        <p class="invoice-label">Saaja</p>
                        <p><?php echo escapeInput($companyInfo['nimi']); ?></p>
                        <?php if (!empty($companyInfo['osoite'])): ?>
                            <p><?php echo nl2br(escapeInput($companyInfo['osoite'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p>Y-tunnus: <?php echo escapeInput($companyInfo['y_tunnus']); ?></p>
                        <?php if (!empty($companyInfo['puh_nro'])): ?>
                            <p>Puhelin: <?php echo escapeInput($companyInfo['puh_nro']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($companyInfo['sahkoposti'])): ?>
                            <p>Sahkoposti: <?php echo escapeInput($companyInfo['sahkoposti']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="invoice-bottom-grid">
                <div class="invoice-bank-box">
                    <p class="invoice-label">EPC QR -koodi</p>
                    <?php if ($epcQrUrl !== ''): ?>
                        <img src="<?php echo escapeInput($epcQrUrl); ?>" alt="EPC QR" width="220" height="220">
                        <p>Skannaa pankkisovelluksella.</p>
                    <?php else: ?>
                        <p>QR-koodia ei voitu muodostaa (tarkista IBAN).</p>
                    <?php endif; ?>
                </div>

                <div class="invoice-recipient-box">
                    <div>
                        <p class="invoice-label">Virtuaaliviivakoodi</p>
                        <p><?php echo escapeInput($virtualBarcode !== '' ? $virtualBarcode : 'Ei voitu muodostaa'); ?></p>
                    </div>
                    <div class="no-print">
                        <p class="invoice-label">Kopioi maksupohjaan</p>
                        <textarea id="payment_payload" rows="6" style="width: 100%;"><?php echo escapeInput($copyPaymentText); ?></textarea>
                        <button class="btn btn-secondary" type="button" onclick="copyPaymentPayload()">Kopioi maksutiedot</button>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>

    <script>
        function copyPaymentPayload() {
            const payload = document.getElementById('payment_payload');
            if (!payload) {
                return;
            }

            payload.select();
            payload.setSelectionRange(0, 99999);

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(payload.value);
            } else {
                document.execCommand('copy');
            }
        }
    </script>
</body>
</html>
<?php
}

$message = $_GET['message'] ?? '';
$error = '';
$tyokohteet = loadWorksites();
$sopimukset = [];
$tuntityot = [];
$tarvikkeet = [];
$sopimus_data = null;
$summat = null;
$laskut = [];

$valittu_tyokohde = $_GET['tyokohde_id'] ?? null;
$valittu_sopimus = $_GET['sopimus_id'] ?? null;
$valittu_lasku = $_GET['invoice_id'] ?? null;

$previewInvoiceId = $_GET['preview_invoice_id'] ?? null;
if ($previewInvoiceId) {
    $invoiceData = loadInvoiceById($previewInvoiceId);

    if (!$invoiceData) {
        http_response_code(404);
        echo 'Laskua ei loytynyt.';
        exit;
    }

    $companyInfo = loadCompanyInfo();
    $lineItems = loadInvoiceLineItems($invoiceData['sopimus_id'], $invoiceData['lasku_id']);
    renderInvoicePreview($invoiceData, $companyInfo, $lineItems, isset($_GET['print']) && $_GET['print'] === '1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_discounts') {
        $tyokohdeId = $_POST['tyokohde_id'] ?? '';
        $sopimusId = $_POST['sopimus_id'] ?? '';
        $tyoAlennukset = $_POST['tyo_alennus'] ?? [];
        $tarvikeAlennukset = $_POST['tarvike_alennus'] ?? [];

        if (!$sopimusId) {
            $error = 'Sopimus puuttuu alennusten päivityksestä.';
        } else {
            foreach ($tyoAlennukset as $tuntityoId => $alennus) {
                $alennusArvo = (float) $alennus;
                if ($alennusArvo < 0 || $alennusArvo > 100) {
                    continue;
                }

                executeQuery(
                    "UPDATE tyo_suorite
                     SET alennusprosentti = $1
                     WHERE sopimus_id = $2 AND tuntityo_id = $3",
                    [$alennusArvo, $sopimusId, (int) $tuntityoId]
                );
            }

            foreach ($tarvikeAlennukset as $tarvikeId => $alennus) {
                $alennusArvo = (float) $alennus;
                if ($alennusArvo < 0 || $alennusArvo > 100) {
                    continue;
                }

                executeQuery(
                    "UPDATE kaytetyt_tarvikkeet
                     SET alennusprosentti = $1
                     WHERE sopimus_id = $2 AND tarvike_id = $3",
                    [$alennusArvo, $sopimusId, (int) $tarvikeId]
                );
            }

            $queryString = http_build_query([
                'tyokohde_id' => $tyokohdeId,
                'sopimus_id' => $sopimusId,
                'message' => 'Alennusprosentit paivitettiin onnistuneesti.'
            ]);
            header('Location: lasku.php?' . $queryString);
            exit;
        }
    }

    if ($action === 'select_worksite') {
        $tyokohdeId = $_POST['tyokohde_id'] ?? '';
        $location = 'lasku.php';

        if ($tyokohdeId !== '') {
            $location .= '?tyokohde_id=' . urlencode((string) $tyokohdeId);
        }

        header('Location: ' . $location);
        exit;
    }

    if ($action === 'select_contract') {
        $tyokohdeId = $_POST['tyokohde_id'] ?? '';
        $sopimusId = $_POST['sopimus_id'] ?? '';
        $queryString = http_build_query([
            'tyokohde_id' => $tyokohdeId,
            'sopimus_id' => $sopimusId
        ]);

        header('Location: lasku.php?' . $queryString);
        exit;
    }

    if ($action === 'create_invoice') {
        $sopimusId = $_POST['sopimus_id'] ?? null;
        $tyokohdeId = $_POST['tyokohde_id'] ?? null;
        $laskuNro = $_POST['lasku_nro'] ?? null;
        $pvm = $_POST['pvm'] ?? date('Y-m-d');
        $todayIso = date('Y-m-d');

        if (!$sopimusId || !$laskuNro || !$pvm) {
            $error = 'Kaikki kentät ovat pakollisia.';
        } elseif (!ctype_digit((string) $sopimusId) || !ctype_digit((string) $laskuNro)) {
            $error = 'Virheellinen sopimus tai laskunumero.';
        } elseif ($pvm < $todayIso) {
            $error = 'Lähetyspäivä ei voi olla menneisyydessä.';
        } else {
            $createdInvoice = null;
            executeQuery('BEGIN');
            try {
                // Sarjoitetaan laskunumeron käyttö, jotta rinnakkaiset käyttäjät eivät luo samaa numeroa.
                executeQuery("SELECT pg_advisory_xact_lock(54001)");

                $checkResult = executeQuery(
                    "SELECT tyyppi
                     FROM sopimus
                     WHERE sopimus_id = $1
                     FOR UPDATE",
                    [(int) $sopimusId]
                );
                $contract = fetchOne($checkResult);

                if (!$contract || $contract['tyyppi'] !== 'tuntityö') {
                    throw new Exception('Vain tuntityo-sopimukset voidaan laskuttaa.');
                }

                $duplicateCheck = executeQuery(
                    "SELECT lasku_id
                     FROM lasku
                     WHERE laskun_nro = $1
                     LIMIT 1",
                    [(int) $laskuNro]
                );
                if (fetchOne($duplicateCheck)) {
                    throw new Exception('Annettu laskunumero on jo käytössä.');
                }

                $viitenumero = str_pad((string) $laskuNro, 5, '0', STR_PAD_LEFT);
                $erapaiva = date('Y-m-d', strtotime($pvm . ' +14 days'));
                $insertResult = executeQuery(
                    "INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
                     VALUES ($1, $2, 0, $3, $4, $5)
                     RETURNING lasku_id",
                    [(int) $sopimusId, (int) $laskuNro, $pvm, $erapaiva, $viitenumero]
                );
                $createdInvoice = fetchOne($insertResult);

                if (!$createdInvoice) {
                    throw new Exception('Virhe laskun luonnissa.');
                }

                executeQuery('COMMIT');
            } catch (Exception $e) {
                executeQuery('ROLLBACK');
                $error = $e->getMessage();
            }

            if ($createdInvoice) {
                $queryString = http_build_query([
                    'tyokohde_id' => $tyokohdeId,
                    'sopimus_id' => $sopimusId,
                    'invoice_id' => $createdInvoice['lasku_id'],
                    'message' => 'Lasku ' . $createdInvoice['lasku_id'] . ' luotiin luonnoksena. Voit nyt muodostaa PDF-esikatselun.'
                ]);
                header('Location: lasku.php?' . $queryString);
                exit;
            }
        }
    }

    if ($action === 'create_urakka_invoices') {
        $sopimusId = $_POST['sopimus_id'] ?? null;
        $tyokohdeId = $_POST['tyokohde_id'] ?? null;
        $startingInvoiceNumber = $_POST['starting_invoice_number'] ?? null;
        $installmentCount = (int) ($_POST['installment_count'] ?? 1);
        $todayIso = date('Y-m-d');

        $allowedInstallments = [1, 2, 4];
        if (!$sopimusId || !$startingInvoiceNumber || !in_array($installmentCount, $allowedInstallments, true)) {
            $error = 'Urakkalaskutuksen tiedot ovat puutteelliset.';
        } elseif (!ctype_digit((string) $sopimusId) || !ctype_digit((string) $startingInvoiceNumber)) {
            $error = 'Virheellinen sopimus tai laskunumero.';
        } else {
            $sendDates = [];
            for ($i = 1; $i <= $installmentCount; $i++) {
                $dateValue = trim((string) ($_POST['send_date_' . $i] ?? ''));
                if ($dateValue === '') {
                    $error = 'Anna lähetyspäivä kaikille erille.';
                    break;
                }
                if ($dateValue < $todayIso) {
                    $error = 'Lähetyspäivä ei voi olla menneisyydessä.';
                    break;
                }
                $sendDates[] = $dateValue;
            }

            if ($error === '') {
                for ($i = 1; $i < count($sendDates); $i++) {
                    if ($sendDates[$i] < $sendDates[$i - 1]) {
                        $error = 'Erien lähetyspäivät pitää syöttää nousevassa järjestyksessä.';
                        break;
                    }
                }
            }

            if ($error === '') {
                $start = (int) $startingInvoiceNumber;
                if ($start <= 0) {
                    $error = 'Laskunumeron tulee olla positiivinen.';
                }

                $numbersToUse = [];
                if ($error === '') {
                    for ($i = 0; $i < $installmentCount; $i++) {
                        $numbersToUse[] = $start + $i;
                    }

                    $firstInvoiceId = null;
                    $previousInvoiceId = null;

                    executeQuery('BEGIN');
                    try {
                        // Sarjoitetaan erälaskujen numerointi rinnakkaiskäytössä.
                        executeQuery("SELECT pg_advisory_xact_lock(54001)");

                        $checkResult = executeQuery(
                            "SELECT tyyppi
                             FROM sopimus
                             WHERE sopimus_id = $1
                             FOR UPDATE",
                            [(int) $sopimusId]
                        );
                        $contract = fetchOne($checkResult);

                        if (!$contract || $contract['tyyppi'] !== 'urakka') {
                            throw new Exception('Vain urakkasopimukset voidaan jakaa erälaskutukseen.');
                        }

                        $existingNumbers = executeQuery(
                            "SELECT laskun_nro
                             FROM lasku
                             WHERE laskun_nro = ANY($1::int[])",
                            ['{' . implode(',', $numbersToUse) . '}']
                        );
                        $duplicates = fetchAll($existingNumbers);
                        if (!empty($duplicates)) {
                            throw new Exception('Vähintään yksi annetuista laskunumeroista on jo käytössä.');
                        }

                        for ($i = 0; $i < $installmentCount; $i++) {
                            $invoiceNumber = $numbersToUse[$i];
                            $sendDate = $sendDates[$i];
                            $dueDate = date('Y-m-d', strtotime($sendDate . ' +14 days'));
                            $reference = str_pad((string) $invoiceNumber, 5, '0', STR_PAD_LEFT);

                            if ($previousInvoiceId) {
                                $insertResult = executeQuery(
                                    "INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero, edellinen_lasku_id)
                                     VALUES ($1, $2, 0, $3, $4, $5, $6)
                                     RETURNING lasku_id",
                                    [(int) $sopimusId, (int) $invoiceNumber, $sendDate, $dueDate, $reference, $previousInvoiceId]
                                );
                            } else {
                                $insertResult = executeQuery(
                                    "INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
                                     VALUES ($1, $2, 0, $3, $4, $5)
                                     RETURNING lasku_id",
                                    [(int) $sopimusId, (int) $invoiceNumber, $sendDate, $dueDate, $reference]
                                );
                            }
                            $createdInvoice = fetchOne($insertResult);

                            if ($firstInvoiceId === null && $createdInvoice) {
                                $firstInvoiceId = $createdInvoice['lasku_id'];
                            }

                            if ($createdInvoice && !empty($createdInvoice['lasku_id'])) {
                                $previousInvoiceId = $createdInvoice['lasku_id'];
                            }
                        }

                        executeQuery('COMMIT');
                    } catch (Exception $e) {
                        executeQuery('ROLLBACK');
                        $error = $e->getMessage();
                    }

                    if ($error === '' && $firstInvoiceId !== null) {
                        $queryString = http_build_query([
                            'tyokohde_id' => $tyokohdeId,
                            'sopimus_id' => $sopimusId,
                            'invoice_id' => $firstInvoiceId,
                            'message' => 'Urakkalaskutus luotu ' . $installmentCount . ' erässä.'
                        ]);
                        header('Location: lasku.php?' . $queryString);
                        exit;
                    }
                }
            }
        }
    }
}

if ($valittu_tyokohde) {
    $sopimukset = loadContracts($valittu_tyokohde);

    if (empty($sopimukset)) {
        $error = 'Kyseiselle kohteelle ei ole aktiivista sopimusta';
    }
}

if ($valittu_sopimus) {
    $sopimus_data = loadContractDetails($valittu_sopimus);
    if ($sopimus_data && ($sopimus_data['tyyppi'] ?? '') === 'urakka') {
        $tuntityot = [];
        $tarvikkeet = [];
        $urakkaTyoNetto = (float) ($sopimus_data['urakka_tyo_netto'] ?? 0);
        $urakkaTarvikeNetto = (float) ($sopimus_data['urakka_tarvikkeet_netto'] ?? 0);
        $summat = [
            'tyo_netto' => $urakkaTyoNetto,
            'tarvike_netto' => $urakkaTarvikeNetto,
            'tyo_netto_ilman_alennusta' => $urakkaTyoNetto,
            'tarvike_netto_ilman_alennusta' => $urakkaTarvikeNetto,
            'alennus_summa' => 0.0
        ];
    } else {
        $tuntityot = loadWorkSummary($valittu_sopimus);
        $tarvikkeet = loadMaterialSummary($valittu_sopimus);
        $summat = getContractTotalsFromRows($tuntityot, $tarvikkeet);
    }
    $laskut = loadContractInvoices($valittu_sopimus);
}

$valittu_lasku_data = null;
if ($valittu_lasku) {
    $valittu_lasku_data = loadInvoiceById($valittu_lasku);
}

$oletusLahetysPvm = date('Y-m-d');
$oletusEraPvm = date('Y-m-d', strtotime($oletusLahetysPvm . ' +14 days'));
$oletusLaskunNro = getNextInvoiceNumber();
?>

<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luo lasku</title>
    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <nav class="navbar"> <div class="nav-content">
            <div class="brand">
                <h1>Tmi Sähkötärsky</h1>
                <span>Laskutusjärjestelmä</span>
            </div>
            <ul>
                <li><a href="index.php">
                <i class="fa-solid fa-house"></i>Etusivu</a></li>
                <li><a href="lisaa_tyokohde.php">
                <i class="fa-solid fa-building"></i>Lisää työkohde</a></li>
                <li><a href="lisaa_tapahtuma.php">
                <i class="fa-solid fa-hammer"></i>Lisää tapahtuma</a></li>
                <li><a href="hinta_arvio.php">
                <i class="fa-solid fa-calculator"></i>Hinta-arvio</a></li>
                <li><a href="lasku.php" class="active">
                <i class="fa-solid fa-file-invoice"></i>Luo lasku</a></li>
                <li><a href="nayta_tiedot.php">
                <i class="fa-solid fa-database"></i>Näytä tiedot</a></li>
            </ul>
        </div>
        </nav>

        <main>
            <h2>Luo lasku</h2>
            <div class="form-container">
                <h3>Muistutuslaskut</h3>
                <p>
                    Jos asiakkaan lasku on erääntynyt ja maksamatta, voit luoda muistutuslaskun
                    erillisellä sivulla.
                </p>
                <a class="btn btn-secondary" href="luo_muistutuslasku.php">Siirry muistutuslaskuihin</a>
            </div>
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo escapeInput($message); ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo escapeInput($error); ?></div>
            <?php endif; ?>

            <div class="form-container">
                <h3>1. Valitse työkohde</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="select_worksite">
                    <div class="form-group">
                        <label for="tyokohde_id">Mistä kohteesta haluat luoda laskun?</label>
                        <select name="tyokohde_id" id="tyokohde_id" onchange="this.form.submit()" required>
                            <option value="">-- Valitse työkohde --</option>
                            <?php foreach ($tyokohteet as $kohde): ?>
                                <option value="<?php echo escapeInput((string) $kohde['tyokohde_id']); ?>" <?php echo ((string) $valittu_tyokohde === (string) $kohde['tyokohde_id']) ? 'selected' : ''; ?>>
                                    <?php echo escapeInput($kohde['kohde_osoite']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <?php if (!empty($sopimukset)): ?>
                <div class="form-container">
                    <h3>2. Valitse sopimus</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="select_contract">
                        <input type="hidden" name="tyokohde_id" value="<?php echo escapeInput((string) $valittu_tyokohde); ?>">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sopimus ID</th>
                                    <th>Tyyppi</th>
                                    <th>Tila</th>
                                    <th>Pvm</th>
                                    <th>Urakka työ netto</th>
                                    <th>Urakka tarvikkeet netto</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sopimukset as $sopimus): ?>
                                    <tr>
                                        <td><?php echo escapeInput((string) $sopimus['sopimus_id']); ?></td>
                                        <td><?php echo escapeInput($sopimus['tyyppi']); ?></td>
                                        <td><?php echo escapeInput($sopimus['tila']); ?></td>
                                        <td><?php echo escapeInput(formatDateFi($sopimus['pvm'])); ?></td>
                                        <td><?php echo formatCurrency((float) ($sopimus['urakka_tyo_netto'] ?? 0)); ?></td>
                                        <td><?php echo formatCurrency((float) ($sopimus['urakka_tarvikkeet_netto'] ?? 0)); ?></td>
                                        <td>
                                            <button class="btn btn-secondary" type="submit" name="sopimus_id" value="<?php echo escapeInput((string) $sopimus['sopimus_id']); ?>">Valitse</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($sopimus_data): ?>
                <div class="info-section">
                    <h3>3. Sopimuksen tiedot</h3>
                    <div class="info-box">
                        <p><strong>Asiakas:</strong> <?php echo escapeInput($sopimus_data['as_nimi']); ?></p>
                        <p><strong>Kohde:</strong> <?php echo escapeInput($sopimus_data['kohde_osoite']); ?></p>
                        <p><strong>Sopimustyyppi:</strong> <?php echo escapeInput($sopimus_data['tyyppi']); ?></p>
                        <p><strong>Sopimuksen tila:</strong> <?php echo escapeInput($sopimus_data['tila']); ?></p>
                        <p><strong>Sopimuksen pvm:</strong> <?php echo escapeInput(formatDateFi($sopimus_data['pvm'])); ?></p>
                        <?php if (($sopimus_data['tyyppi'] ?? '') === 'urakka'): ?>
                            <?php
                                $urakkaTyo = (float) ($sopimus_data['urakka_tyo_netto'] ?? 0);
                                $urakkaTarvikkeet = (float) ($sopimus_data['urakka_tarvikkeet_netto'] ?? 0);
                                $urakkaYhteensa = $urakkaTyo + $urakkaTarvikkeet;
                            ?>
                            <p><strong>Urakkasopimuksen työosuus (netto):</strong> <?php echo formatCurrency($urakkaTyo); ?></p>
                            <p><strong>Urakkasopimuksen tarvikkeet (netto):</strong> <?php echo formatCurrency($urakkaTarvikkeet); ?></p>
                            <p><strong>Urakkasopimuksen yhteensä (netto):</strong> <?php echo formatCurrency($urakkaYhteensa); ?></p>
                        <?php endif; ?>
                        <p><strong>Asiakkaan yhteystiedot:</strong></p>
                        <p>Nimi: <?php echo escapeInput($sopimus_data['as_nimi']); ?></p>
                        <p>Osoite: <?php echo escapeInput($sopimus_data['as_osoite']); ?></p>
                        <p>Puhelin: <?php echo escapeInput($sopimus_data['puh_nro']); ?></p>
                        <p>Sähkoposti: <?php echo escapeInput($sopimus_data['sahkoposti']); ?></p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_discounts">
                        <input type="hidden" name="tyokohde_id" value="<?php echo escapeInput((string) $valittu_tyokohde); ?>">
                        <input type="hidden" name="sopimus_id" value="<?php echo escapeInput((string) $valittu_sopimus); ?>">

                    <?php if (!empty($tuntityot)): ?>
                        <h4>Tuntityöerittely</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Työ</th>
                                    <th>Määrä</th>
                                    <th>Alennusprosentti</th>
                                    <th>Netto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tuntityot as $tyo): ?>
                                    <tr>
                                        <td><?php echo escapeInput($tyo['tuntityo_nimi']); ?></td>
                                        <td><?php echo escapeInput((string) $tyo['total_maara']); ?> h</td>
                                        <td>
                                            <input
                                                type="number"
                                                name="tyo_alennus[<?php echo escapeInput((string) $tyo['tuntityo_id']); ?>]"
                                                min="0"
                                                max="100"
                                                step="0.5"
                                                value="<?php echo escapeInput(number_format((float) $tyo['alennusprosentti'], 2, '.', '')); ?>"
                                            >
                                        </td>
                                        <td><?php echo formatCurrency((float) $tyo['total_netto']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($tarvikkeet)): ?>
                        <h4>Tarvike-erittely</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tarvike</th>
                                    <th>Yksikkö</th>
                                    <th>Yksikköhinta</th>
                                    <th>Määrä</th>
                                    <th>Alennusprosentti</th>
                                    <th>Netto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tarvikkeet as $tarvike): ?>
                                    <tr>
                                        <td><?php echo escapeInput($tarvike['tarvike_nimi']); ?></td>
                                        <td><?php echo escapeInput($tarvike['yksikko']); ?></td>
                                        <td><?php echo formatCurrency((float) $tarvike['yksikkohinta_netto']); ?></td>
                                        <td><?php echo escapeInput((string) $tarvike['total_maara']); ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                name="tarvike_alennus[<?php echo escapeInput((string) $tarvike['tarvike_id']); ?>]"
                                                min="0"
                                                max="100"
                                                step="0.5"
                                                value="<?php echo escapeInput(number_format((float) $tarvike['alennusprosentti'], 2, '.', '')); ?>"
                                            >
                                        </td>
                                        <td><?php echo formatCurrency((float) $tarvike['total_netto']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($tuntityot) || !empty($tarvikkeet)): ?>
                        <button type="submit" class="btn btn-secondary">Tallenna alennusprosentit</button>
                    <?php endif; ?>
                    </form>

                    <?php if ($summat): ?>
                        <div class="summary-box">
                            <?php
                                $tyoNetto = (float) $summat['tyo_netto'];
                                $tarvikeNetto = (float) $summat['tarvike_netto'];
                                $kokonaisNetto = $tyoNetto + $tarvikeNetto;
                                $kokonaisIlmanAlennusta = (float) $summat['tyo_netto_ilman_alennusta'] + (float) $summat['tarvike_netto_ilman_alennusta'];
                                $kotitalousvahennys = ($tyoNetto * 1.24) * 0.35;
                            ?>
                            <p><strong>Tyon osuus yhteensä:</strong> <?php echo formatCurrency($tyoNetto); ?></p>
                            <p><strong>Tarvikkeiden osuus yhteensä:</strong> <?php echo formatCurrency($tarvikeNetto); ?></p>
                            <p><strong>Yhteensä netto:</strong> <?php echo formatCurrency($kokonaisNetto); ?></p>
                            <p><strong>Hinta ilman alennuksia:</strong> <?php echo formatCurrency($kokonaisIlmanAlennusta); ?></p>
                            <p><strong>Hinta alennusten jalkeen:</strong> <?php echo formatCurrency($kokonaisNetto); ?></p>
                            <p><strong>Alennusten vaikutus:</strong> <?php echo formatCurrency((float) $summat['alennus_summa']); ?></p>
                            <p><strong>Kotitalousvähennykseen kelpaava osuus (35 % tyon verollisesta osuudesta):</strong> <?php echo formatCurrency($kotitalousvahennys); ?></p>
                            <p><a href="https://www.vero.fi/henkiloasiakkaat/vahennykset/kotitalousvahennys/kotitalousvahennyksen-maara/" target="_blank" rel="noopener noreferrer">Lue lisaa vero.fi: kotitalousvahennyksen maara</a></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-container">
                    <h3>4. Luo laskuluonnos</h3>
                    <?php if (($sopimus_data['tyyppi'] ?? '') === 'urakka'): ?>
                        <form method="POST" action="" class="invoice-create-form" id="urakka-installment-form">
                            <input type="hidden" name="action" value="create_urakka_invoices">
                            <input type="hidden" name="tyokohde_id" value="<?php echo escapeInput((string) $valittu_tyokohde); ?>">
                            <input type="hidden" name="sopimus_id" value="<?php echo escapeInput((string) $valittu_sopimus); ?>">

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="starting_invoice_number">Aloituslaskunumero</label>
                                    <input type="number" name="starting_invoice_number" id="starting_invoice_number" min="1" value="<?php echo escapeInput((string) $oletusLaskunNro); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="installment_count">Erien määrä</label>
                                    <select name="installment_count" id="installment_count" required>
                                        <option value="1" selected>1 erä</option>
                                        <option value="2">2 erää</option>
                                        <option value="4">4 erää</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row" id="installment_dates">
                                <div class="form-group installment-date" data-installment="1">
                                    <label for="send_date_1">Erä 1 lähetyspäivä</label>
                                    <input type="date" name="send_date_1" id="send_date_1" min="<?php echo escapeInput($oletusLahetysPvm); ?>" value="<?php echo escapeInput($oletusLahetysPvm); ?>" required>
                                </div>
                                <div class="form-group installment-date" data-installment="2" style="display:none;">
                                    <label for="send_date_2">Erä 2 lähetyspäivä</label>
                                    <input type="date" name="send_date_2" id="send_date_2" min="<?php echo escapeInput($oletusLahetysPvm); ?>" value="<?php echo escapeInput($oletusLahetysPvm); ?>">
                                </div>
                                <div class="form-group installment-date" data-installment="3" style="display:none;">
                                    <label for="send_date_3">Erä 3 lähetyspäivä</label>
                                    <input type="date" name="send_date_3" id="send_date_3" min="<?php echo escapeInput($oletusLahetysPvm); ?>" value="<?php echo escapeInput($oletusLahetysPvm); ?>">
                                </div>
                                <div class="form-group installment-date" data-installment="4" style="display:none;">
                                    <label for="send_date_4">Erä 4 lähetyspäivä</label>
                                    <input type="date" name="send_date_4" id="send_date_4" min="<?php echo escapeInput($oletusLahetysPvm); ?>" value="<?php echo escapeInput($oletusLahetysPvm); ?>">
                                </div>
                            </div>

                            <p class="help-text">Valitse 1, 2 tai 4 erää. Jokainen lähetyspäivä voi olla tästä päivästä eteenpäin, mutta ei menneisyydessä.</p>
                            <button type="submit" class="btn btn-primary">Luo urakkalaskutus eriin</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="" class="invoice-create-form">
                            <input type="hidden" name="action" value="create_invoice">
                            <input type="hidden" name="tyokohde_id" value="<?php echo escapeInput((string) $valittu_tyokohde); ?>">
                            <input type="hidden" name="sopimus_id" value="<?php echo escapeInput((string) $valittu_sopimus); ?>">

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="lasku_nro">Laskunumero</label>
                                    <input type="number" name="lasku_nro" id="lasku_nro" min="1" value="<?php echo escapeInput((string) $oletusLaskunNro); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="pvm">Lähetyspäivä</label>
                                    <input type="date" name="pvm" id="pvm" min="<?php echo escapeInput($oletusLahetysPvm); ?>" value="<?php echo escapeInput($oletusLahetysPvm); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="erapaiva_preview">Erapäivä</label>
                                    <input type="date" id="erapaiva_preview" value="<?php echo escapeInput($oletusEraPvm); ?>" readonly>
                                </div>
                            </div>

                            <p class="help-text">Erapäivä lasketaan automaattisesti 14 päivää lähetyspäivästä. Lähetyspäivä voi olla tänään tai tulevaisuudessa.</p>
                            <button type="submit" class="btn btn-primary">Luo laskuluonnos</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (!empty($laskut)): ?>
                    <div class="form-container">
                        <h3>5. Sopimuksen laskut</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Lasku ID</th>
                                    <th>Laskunumero</th>
                                    <th>Lähetyspäivä</th>
                                    <th>Eräpäivä</th>
                                    <th>Maksupäivä</th>
                                    <th>PDF</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($laskut as $lasku): ?>
                                    <tr class="<?php echo ((string) $valittu_lasku === (string) $lasku['lasku_id']) ? 'selected-row' : ''; ?>">
                                        <td><?php echo escapeInput((string) $lasku['lasku_id']); ?></td>
                                        <td><?php echo escapeInput((string) $lasku['laskun_nro']); ?></td>
                                        <td><?php echo escapeInput(formatDateFi($lasku['pvm'])); ?></td>
                                        <td><?php echo escapeInput(formatDateFi($lasku['erapaiva'])); ?></td>
                                        <td><?php echo escapeInput(formatDateFi($lasku['maksu_pvm'])); ?></td>
                                        <td class="action-row">
                                            <a class="btn btn-secondary" href="lasku.php?tyokohde_id=<?php echo urlencode((string) $valittu_tyokohde); ?>&sopimus_id=<?php echo urlencode((string) $valittu_sopimus); ?>&invoice_id=<?php echo urlencode((string) $lasku['lasku_id']); ?>">Valitse</a>
                                            <a class="btn btn-primary" href="lasku.php?preview_invoice_id=<?php echo urlencode((string) $lasku['lasku_id']); ?>" target="_blank">Muodosta PDF</a>
                                            <a class="btn btn-success" href="lasku.php?preview_invoice_id=<?php echo urlencode((string) $lasku['lasku_id']); ?>&print=1" target="_blank">Tulosta</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($valittu_lasku_data): ?>
                    <div class="invoice-preview-card">
                        <h3>Valittu laskuluonnos</h3>
                        <p><strong>Lasku ID:</strong> <?php echo escapeInput((string) $valittu_lasku_data['lasku_id']); ?></p>
                        <p><strong>Tiedoston oletusnimi:</strong> <?php echo escapeInput($valittu_lasku_data['sopimus_id'] . '_' . $valittu_lasku_data['lasku_id'] . '_' . $valittu_lasku_data['pvm']); ?></p>
                        <p><strong>Lähetyspäivä:</strong> <?php echo escapeInput(formatDateFi($valittu_lasku_data['pvm'])); ?></p>
                        <p><strong>Erapäivä:</strong> <?php echo escapeInput(formatDateFi($valittu_lasku_data['erapaiva'])); ?></p>
                        <p><strong>Viitenumero:</strong> <?php echo escapeInput((string) $valittu_lasku_data['viitenumero']); ?></p>
                        <div class="action-row top-gap">
                            <a class="btn btn-primary" href="lasku.php?preview_invoice_id=<?php echo urlencode((string) $valittu_lasku_data['lasku_id']); ?>" target="_blank">Avaa PDF-esikatselu</a>
                            <a class="btn btn-success" href="lasku.php?preview_invoice_id=<?php echo urlencode((string) $valittu_lasku_data['lasku_id']); ?>&print=1" target="_blank">Tulosta tai tallenna PDF</a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>

    <script>
        const sendDateInput = document.getElementById('pvm');
        const dueDatePreview = document.getElementById('erapaiva_preview');
        const installmentCountSelect = document.getElementById('installment_count');

        function updateDueDate() {
            if (!sendDateInput || !dueDatePreview || !sendDateInput.value) {
                return;
            }

            const sendDate = new Date(sendDateInput.value + 'T00:00:00');
            if (Number.isNaN(sendDate.getTime())) {
                return;
            }

            sendDate.setDate(sendDate.getDate() + 14);
            const year = sendDate.getFullYear();
            const month = String(sendDate.getMonth() + 1).padStart(2, '0');
            const day = String(sendDate.getDate()).padStart(2, '0');
            dueDatePreview.value = year + '-' + month + '-' + day;
        }

        if (sendDateInput) {
            sendDateInput.addEventListener('change', updateDueDate);
            updateDueDate();
        }

        function updateInstallmentFields() {
            if (!installmentCountSelect) {
                return;
            }

            const count = parseInt(installmentCountSelect.value, 10);
            const installmentFields = document.querySelectorAll('.installment-date');

            installmentFields.forEach((field) => {
                const index = parseInt(field.getAttribute('data-installment'), 10);
                const input = field.querySelector('input');

                if (index <= count) {
                    field.style.display = '';
                    if (input) {
                        input.required = true;
                    }
                } else {
                    field.style.display = 'none';
                    if (input) {
                        input.required = false;
                    }
                }
            });
        }

        if (installmentCountSelect) {
            installmentCountSelect.addEventListener('change', updateInstallmentFields);
            updateInstallmentFields();
        }
    </script>
</body>
</html>