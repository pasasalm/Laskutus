<?php
session_start();
include 'config.php';

function parseDecimal($value) {
    $normalized = str_replace(',', '.', trim((string) $value));
    if ($normalized === '') {
        return 0.0;
    }

    return is_numeric($normalized) ? (float) $normalized : 0.0;
}

function normalizeAgreementType($value) {
    $normalized = trim((string) $value);

    if ($normalized === 'tuntityo') {
        $normalized = 'tuntityö';
    }

    if (!in_array($normalized, ['tuntityö', 'urakka'], true)) {
        return 'tuntityö';
    }

    return $normalized;
}

function loadWorkTypes() {
    $result = executeQuery(
        "SELECT tuntityo_id, nimi, hinta_netto, alv_prosentti
         FROM tuntityo_hinnasto
         ORDER BY nimi"
    );

    return fetchAll($result);
}

function loadMaterials() {
    $result = executeQuery(
        "SELECT tarvike_id, tarvike_nimi, merkki, myyntihinta, yksikko, alv_prosentti
         FROM tarvikkeet
         ORDER BY tarvike_nimi, merkki"
    );

    return fetchAll($result);
}

function findWorksitesByAddress($address) {
    if ($address === '') {
        return [];
    }

    $result = executeQuery(
        "SELECT tk.tyokohde_id, tk.kohde_osoite, a.asiakas_id, a.as_nimi, a.as_osoite, a.puh_nro, a.sahkoposti
         FROM tyokohde tk
         LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
         WHERE LOWER(tk.kohde_osoite) = LOWER($1)
         ORDER BY tk.tyokohde_id DESC",
        [$address]
    );

    return fetchAll($result);
}

function findCustomerByName($name) {
    if ($name === '') {
        return null;
    }

    $result = executeQuery(
        "SELECT asiakas_id, as_nimi, as_osoite, puh_nro, sahkoposti
         FROM asiakas
         WHERE LOWER(as_nimi) = LOWER($1)
         LIMIT 1",
        [$name]
    );

    return fetchOne($result);
}

// Käsittele AJAX-kutsu asiakkaan tietojen hakuun
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_customer_by_name') {
    $name = trim((string) ($_POST['customer_name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Asiakkaan nimi puuttuu']);
        exit;
    }

    $customer = findCustomerByName($name);
    header('Content-Type: application/json');
    if ($customer) {
        echo json_encode(['found' => true, 'customer' => $customer]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

$estimate = [
    'address' => trim((string) ($_POST['address'] ?? $_GET['address'] ?? '')),
    'agreement_type' => normalizeAgreementType($_POST['agreement_type'] ?? $_GET['agreement_type'] ?? 'tuntityö'),
    'work_net' => parseDecimal($_POST['work_net'] ?? $_GET['work_net'] ?? 0),
    'work_gross' => parseDecimal($_POST['work_gross'] ?? $_GET['work_gross'] ?? 0),
    'material_net' => parseDecimal($_POST['material_net'] ?? $_GET['material_net'] ?? 0),
    'material_gross' => parseDecimal($_POST['material_gross'] ?? $_GET['material_gross'] ?? 0),
    'total_net' => parseDecimal($_POST['total_net'] ?? $_GET['total_net'] ?? 0),
    'total_gross' => parseDecimal($_POST['total_gross'] ?? $_GET['total_gross'] ?? 0),
    'household_deduction' => parseDecimal($_POST['household_deduction'] ?? $_GET['household_deduction'] ?? 0),
    'risk_factor' => parseDecimal($_POST['risk_factor'] ?? $_GET['risk_factor'] ?? 1.0),
    'base_work_net' => parseDecimal($_POST['base_work_net'] ?? $_GET['base_work_net'] ?? ($_POST['work_net'] ?? $_GET['work_net'] ?? 0)),
    'base_work_gross' => parseDecimal($_POST['base_work_gross'] ?? $_GET['base_work_gross'] ?? ($_POST['work_gross'] ?? $_GET['work_gross'] ?? 0))
];

$error = '';
$message = '';
$selectedWorksiteId = (string) ($_POST['selected_worksite_id'] ?? '');
$createdContract = null;
$selectedWorksiteRow = null;
$proposalOnly = false;

$workTypes = loadWorkTypes();
$materials = loadMaterials();
$candidateWorksites = findWorksitesByAddress($estimate['address']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_contract_preview' || $action === 'create_pending_contract') {
        $proposalOnly = ($action === 'create_contract_preview');
        $estimate['address'] = trim((string) ($_POST['address'] ?? ''));
        $estimate['agreement_type'] = normalizeAgreementType($_POST['agreement_type'] ?? $estimate['agreement_type']);
        $estimate['work_net'] = parseDecimal($_POST['work_net'] ?? 0);
        $estimate['work_gross'] = parseDecimal($_POST['work_gross'] ?? 0);
        $estimate['material_net'] = parseDecimal($_POST['material_net'] ?? 0);
        $estimate['material_gross'] = parseDecimal($_POST['material_gross'] ?? 0);
        $estimate['total_net'] = parseDecimal($_POST['total_net'] ?? 0);
        $estimate['total_gross'] = parseDecimal($_POST['total_gross'] ?? 0);
        $estimate['household_deduction'] = parseDecimal($_POST['household_deduction'] ?? 0);
        $estimate['risk_factor'] = parseDecimal($_POST['risk_factor'] ?? $estimate['risk_factor']);
        $estimate['base_work_net'] = parseDecimal($_POST['base_work_net'] ?? $estimate['base_work_net']);
        $estimate['base_work_gross'] = parseDecimal($_POST['base_work_gross'] ?? $estimate['base_work_gross']);

        $priceMultiplierInput = parseDecimal($_POST['price_multiplier'] ?? $estimate['risk_factor']);
        $priceMultiplier = max(0.5, min(1.5, $priceMultiplierInput > 0 ? $priceMultiplierInput : 1.0));

        $agreementType = $estimate['agreement_type'];

        if ($agreementType === 'urakka') {
            $baseWorkNet = $estimate['base_work_net'] > 0 ? $estimate['base_work_net'] : $estimate['work_net'];
            $baseWorkGross = $estimate['base_work_gross'] > 0 ? $estimate['base_work_gross'] : $estimate['work_gross'];

            $estimate['work_net'] = $baseWorkNet * $priceMultiplier;
            $estimate['work_gross'] = $baseWorkGross * $priceMultiplier;
            $estimate['total_net'] = $estimate['work_net'] + $estimate['material_net'];
            $estimate['total_gross'] = $estimate['work_gross'] + $estimate['material_gross'];
            $estimate['household_deduction'] = $baseWorkGross * 0.35;
        }

        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $customerAddress = trim((string) ($_POST['customer_address'] ?? ''));
        $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));

        if ($estimate['address'] === '') {
            $error = 'Tyokohteen osoite puuttuu. Palaa hinta-arvioon ja laske arvio uudelleen.';
        }

        $worksiteId = null;

        if ($error === '' && $selectedWorksiteId !== '') {
            if (!ctype_digit($selectedWorksiteId)) {
                $error = 'Virheellinen tyokohdevalinta.';
            } else {
                $selectedResult = executeQuery(
                    "SELECT tk.tyokohde_id, tk.kohde_osoite, a.asiakas_id, a.as_nimi, a.as_osoite, a.puh_nro, a.sahkoposti
                     FROM tyokohde tk
                     LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
                     WHERE tk.tyokohde_id = $1
                     LIMIT 1",
                    [$selectedWorksiteId]
                );
                $selectedWorksiteRow = fetchOne($selectedResult);

                if (!$selectedWorksiteRow) {
                    $error = 'Valittua tyokohdetta ei loytynyt.';
                } elseif (empty($selectedWorksiteRow['asiakas_id'])) {
                    $error = 'Tyokohteella ei ole asiakasta. Tayta asiakastiedot alla.';
                } else {
                    $worksiteId = (int) $selectedWorksiteRow['tyokohde_id'];
                }
            }
        }

        if ($error === '' && $worksiteId === null && !$proposalOnly) {
            if ($customerName === '' || $customerAddress === '' || $customerPhone === '' || $customerEmail === '') {
                $error = 'Anna kaikki asiakastiedot, koska tyokohteelle ei valittu valmista asiakasta.';
            }
        }

        if ($error === '' && $proposalOnly && $worksiteId === null) {
            if ($customerName === '' || $customerAddress === '' || $customerPhone === '' || $customerEmail === '') {
                $error = 'Anna kaikki asiakastiedot, koska tyokohteelle ei valittu valmista asiakasta.';
            } else {
                $selectedWorksiteRow = [
                    'tyokohde_id' => null,
                    'kohde_osoite' => $estimate['address'],
                    'asiakas_id' => null,
                    'as_nimi' => $customerName,
                    'as_osoite' => $customerAddress,
                    'puh_nro' => $customerPhone,
                    'sahkoposti' => $customerEmail
                ];
            }
        }

        if ($error === '' && $proposalOnly) {
            $createdContract = [
                'sopimus_id' => '-',
                'tyokohde_id' => $selectedWorksiteRow['tyokohde_id'] ?? '-',
                'tila' => 'ehdotus',
                'tyyppi' => $agreementType,
                'pvm' => date('Y-m-d'),
                'urakka_tyo_netto' => $estimate['work_net'],
                'urakka_tarvikkeet_netto' => $estimate['material_net'],
                'customer' => $selectedWorksiteRow,
                'estimate' => $estimate,
                'price_multiplier' => $priceMultiplier
            ];
        }

        if ($error === '' && !$proposalOnly) {
            executeQuery('BEGIN');
            try {
                // Sarjoitetaan sopimuksen luontipolku rinnakkaiskäytössä.
                executeQuery("SELECT pg_advisory_xact_lock(54002)");

                if ($worksiteId !== null) {
                    $selectedLockResult = executeQuery(
                        "SELECT tk.tyokohde_id, tk.kohde_osoite, a.asiakas_id, a.as_nimi, a.as_osoite, a.puh_nro, a.sahkoposti
                         FROM tyokohde tk
                         LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
                         WHERE tk.tyokohde_id = $1
                         FOR UPDATE",
                        [(int) $worksiteId]
                    );
                    $selectedWorksiteRow = fetchOne($selectedLockResult);

                    if (!$selectedWorksiteRow || empty($selectedWorksiteRow['asiakas_id'])) {
                        throw new Exception('Valittua tyokohdetta ei löytynyt tai sillä ei ole asiakasta.');
                    }
                } else {
                    $newCustomerResult = executeQuery(
                        "INSERT INTO asiakas (as_nimi, as_osoite, puh_nro, sahkoposti)
                         VALUES ($1, $2, $3, $4)
                         RETURNING asiakas_id, as_nimi, as_osoite, puh_nro, sahkoposti",
                        [$customerName, $customerAddress, $customerPhone, $customerEmail]
                    );
                    $newCustomer = fetchOne($newCustomerResult);

                    $newWorksiteResult = executeQuery(
                        "INSERT INTO tyokohde (asiakas_id, kohde_osoite)
                         VALUES ($1, $2)
                         RETURNING tyokohde_id, kohde_osoite",
                        [$newCustomer['asiakas_id'], $estimate['address']]
                    );
                    $newWorksite = fetchOne($newWorksiteResult);

                    $selectedWorksiteRow = [
                        'tyokohde_id' => $newWorksite['tyokohde_id'],
                        'kohde_osoite' => $newWorksite['kohde_osoite'],
                        'asiakas_id' => $newCustomer['asiakas_id'],
                        'as_nimi' => $newCustomer['as_nimi'],
                        'as_osoite' => $newCustomer['as_osoite'],
                        'puh_nro' => $newCustomer['puh_nro'],
                        'sahkoposti' => $newCustomer['sahkoposti']
                    ];
                    $worksiteId = (int) $newWorksite['tyokohde_id'];
                }

                $contractResult = executeQuery(
                    "INSERT INTO sopimus (tyokohde_id, tila, tyyppi, pvm, urakka_tyo_netto, urakka_tarvikkeet_netto)
                     VALUES ($1, 'odottaa_hyväksyntää', $2, CURRENT_DATE, $3, $4)
                     RETURNING sopimus_id, tyokohde_id, tila, tyyppi, pvm, urakka_tyo_netto, urakka_tarvikkeet_netto",
                    [$worksiteId, $agreementType, $estimate['work_net'], $estimate['material_net']]
                );
                $contract = fetchOne($contractResult);

                executeQuery('COMMIT');

                $createdContract = [
                    'sopimus_id' => $contract['sopimus_id'],
                    'tyokohde_id' => $contract['tyokohde_id'],
                    'tila' => $contract['tila'],
                    'tyyppi' => $contract['tyyppi'],
                    'pvm' => $contract['pvm'],
                    'urakka_tyo_netto' => $contract['urakka_tyo_netto'],
                    'urakka_tarvikkeet_netto' => $contract['urakka_tarvikkeet_netto'],
                    'customer' => $selectedWorksiteRow,
                    'estimate' => $estimate,
                    'price_multiplier' => $priceMultiplier
                ];

                $message = 'Sopimustarjous tallennettu ja odottaa yrityksen hyväksyntää.';
                $selectedWorksiteId = (string) $worksiteId;
                $candidateWorksites = findWorksitesByAddress($estimate['address']);
            } catch (Exception $e) {
                executeQuery('ROLLBACK');
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sopimustarjous - Laskutusjarjestelma</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="nav-content">
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
                    <li><a href="lasku.php">
                    <i class="fa-solid fa-file-invoice"></i>Luo lasku</a></li>
                    <li><a href="nayta_tiedot.php">
                    <i class="fa-solid fa-database"></i>Näytä tiedot</a></li>
                </ul>
            </div>
        </nav>

        <main class="content">
            <h2>Sopimustarjous</h2>
            <p>
                Talla sivulla muodostetaan sopimustarjous hinta-arvion pohjalta. Kaikki tarjoukset tallennetaan ensin
                tilaan <strong>odottaa_hyväksyntää</strong>, ja yrityksen edustaja hyväksyy ne myöhemmin.
            </p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo escapeInput($message); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo escapeInput($error); ?></div>
            <?php endif; ?>

            <section class="info-section">
                <h3>Hinta-arvion yhteenveto</h3>
                <?php if ($estimate['address'] === ''): ?>
                    <div class="alert alert-info">
                        Talle sivulle kannattaa tulla suoraan hinta-arviosta, jotta kohteen tiedot siirtyvat valmiiksi.
                    </div>
                <?php else: ?>
                    <table class="estimate-table">
                        <thead>
                            <tr>
                                <th>Kohde</th>
                                <th>Tyon osuus (netto)</th>
                                <th>Tarvikkeet (netto)</th>
                                <th>Yhteensa (netto)</th>
                                <th>Yhteensa (sis. ALV)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo escapeInput($estimate['address']); ?></td>
                                <td><?php echo formatCurrency($estimate['work_net']); ?></td>
                                <td><?php echo formatCurrency($estimate['material_net']); ?></td>
                                <td><?php echo formatCurrency($estimate['total_net']); ?></td>
                                <td><?php echo formatCurrency($estimate['total_gross']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="help-text">
                        Arvioitu kotitalousvahennykseen kelpaava osuus: <strong><?php echo formatCurrency($estimate['household_deduction']); ?></strong>
                    </p>
                <?php endif; ?>
            </section>

            <section class="info-section">
                <h3>Sopimuksen perustiedot</h3>
                <p>
                    Sopimuksen tyyppi valitaan tarjouksen yhteydessä. Tallennusvaiheessa sopimus siirtyy ensin
                    odottamaan hyväksyntää, joten se ei vielä ole laskutuskelpoinen. Alla näkyvät kiinteät tuntihinnat eivät koske urakkasopimuksia, mutta ovat tarjolla vertailtavaksi.
                    Urakkasopimuksissa lopullinen hinta määräytyy arvioitujen työmäärien, tarvikkeiden ja riskin perusteella, ja se näkyy sopimustiedossa tarjouksen tallennuksen jälkeen.
                </p>

                <div class="contract-grid">
                    <div class="card">
                        <h4>Kiinteat tuntihinnat</h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tyolaji</th>
                                    <th>Netto / h</th>
                                    <th>ALV %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workTypes as $workType): ?>
                                    <tr>
                                        <td><?php echo escapeInput($workType['nimi']); ?></td>
                                        <td><?php echo formatCurrency($workType['hinta_netto']); ?></td>
                                        <td><?php echo escapeInput($workType['alv_prosentti']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="card">
                        <h4>Kiinteat tarvikehinnat</h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tarvike</th>
                                    <th>Netto</th>
                                    <th>Yksikko</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials as $material): ?>
                                    <tr>
                                        <td><?php echo escapeInput($material['tarvike_nimi']); ?></td>
                                        <td><?php echo formatCurrency($material['myyntihinta']); ?></td>
                                        <td><?php echo escapeInput($material['yksikko']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-container top-gap">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_contract_preview">
                        <input type="hidden" name="address" value="<?php echo escapeInput($estimate['address']); ?>">
                        <input type="hidden" name="work_net" value="<?php echo escapeInput((string) $estimate['work_net']); ?>">
                        <input type="hidden" name="work_gross" value="<?php echo escapeInput((string) $estimate['work_gross']); ?>">
                        <input type="hidden" name="material_net" value="<?php echo escapeInput((string) $estimate['material_net']); ?>">
                        <input type="hidden" name="material_gross" value="<?php echo escapeInput((string) $estimate['material_gross']); ?>">
                        <input type="hidden" name="total_net" value="<?php echo escapeInput((string) $estimate['total_net']); ?>">
                        <input type="hidden" name="total_gross" value="<?php echo escapeInput((string) $estimate['total_gross']); ?>">
                        <input type="hidden" name="household_deduction" value="<?php echo escapeInput((string) $estimate['household_deduction']); ?>">
                        <input type="hidden" name="risk_factor" value="<?php echo escapeInput((string) $estimate['risk_factor']); ?>">
                        <input type="hidden" name="base_work_net" value="<?php echo escapeInput((string) $estimate['base_work_net']); ?>">
                        <input type="hidden" name="base_work_gross" value="<?php echo escapeInput((string) $estimate['base_work_gross']); ?>">

                        <div class="form-group">
                            <label for="agreement_type">Sopimuksen tyyppi</label>
                            <select id="agreement_type" name="agreement_type" required onchange="updateContractTypeUI()">
                                <option value="tuntityö" <?php echo $estimate['agreement_type'] === 'tuntityö' ? 'selected' : ''; ?>>Tuntityösopimus</option>
                                <option value="urakka" <?php echo $estimate['agreement_type'] === 'urakka' ? 'selected' : ''; ?>>Urakkasopimus</option>
                            </select>
                        </div>

                        <div id="urakka_pricing" style="<?php echo $estimate['agreement_type'] === 'urakka' ? 'display: block;' : 'display: none;'; ?>">
                            <div class="form-group">
                                <label for="price_multiplier">Hinnan kerroin (0.5 - 1.5) *</label>
                                <input type="number" id="price_multiplier" name="price_multiplier" min="0.5" max="1.5" step="0.1" value="<?php echo escapeInput(number_format((float) ($estimate['risk_factor'] > 0 ? $estimate['risk_factor'] : 1.0), 1, '.', '')); ?>" required>
                                <small>Oletuskerroin tulee automaattisesti riskilisästä. Voit muuttaa sitä välillä 0.5 - 1.5.</small>
                            </div>
                            <div class="form-group">
                                <p><strong>Perushinta (netto):</strong> <span id="base_price"></span></p>
                                <p><strong>Laskettu hinta (netto):</strong> <span id="calculated_price"></span></p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="selected_worksite_id">Valitse olemassa oleva tyokohde (jos loytyy samasta osoitteesta)</label>
                            <select id="selected_worksite_id" name="selected_worksite_id">
                                <option value="">-- Ei valintaa, luodaan uusi kohde ja asiakas --</option>
                                <?php foreach ($candidateWorksites as $site): ?>
                                    <option value="<?php echo escapeInput((string) $site['tyokohde_id']); ?>" <?php echo $selectedWorksiteId === (string) $site['tyokohde_id'] ? 'selected' : ''; ?>>
                                        <?php echo escapeInput($site['kohde_osoite']); ?> / <?php echo escapeInput((string) ($site['as_nimi'] ?? 'asiakas puuttuu')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <h4>Asiakastiedot (pakollinen jos valmista kohdetta ei valita)</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="customer_name">Asiakkaan nimi</label>
                                <input type="text" id="customer_name" name="customer_name" placeholder="Matti Meikalainen" value="<?php echo escapeInput((string) ($_POST['customer_name'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_address">Laskutusosoite</label>
                                <input type="text" id="customer_address" name="customer_address" placeholder="Kotikatu 5, 33100 Tampere" value="<?php echo escapeInput((string) ($_POST['customer_address'] ?? '')); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="customer_phone">Puhelin</label>
                                <input type="text" id="customer_phone" name="customer_phone" placeholder="0401234567" value="<?php echo escapeInput((string) ($_POST['customer_phone'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_email">Sahkoposti</label>
                                <input type="email" id="customer_email" name="customer_email" placeholder="asiakas@email.fi" value="<?php echo escapeInput((string) ($_POST['customer_email'] ?? '')); ?>">
                            </div>
                        </div>

                        <div class="action-row">
                            <button type="submit" class="btn btn-success">Luo sopimustarjous</button>
                            <a href="hinta_arvio.php" class="btn btn-secondary">Takaisin hinta-arvioon</a>
                        </div>
                    </form>
                </div>
            </section>

            <?php if ($createdContract): ?>
                <section class="invoice-preview">
                    <h3>Sopimusehdotus</h3>
                    <p><strong>Sopimus ID:</strong> <?php echo escapeInput((string) $createdContract['sopimus_id']); ?></p>
                    <p><strong>Tyyppi:</strong> <?php echo escapeInput($createdContract['tyyppi']); ?></p>
                    <p><strong>Tila:</strong> <?php echo escapeInput($createdContract['tila']); ?></p>
                    <p><strong>Pvm:</strong> <?php echo escapeInput((string) $createdContract['pvm']); ?></p>
                    <p><strong>Tyokohde:</strong> <?php echo escapeInput((string) $createdContract['customer']['kohde_osoite']); ?></p>
                    <p><strong>Asiakas:</strong> <?php echo escapeInput((string) $createdContract['customer']['as_nimi']); ?></p>
                    <?php if (($createdContract['tyyppi'] ?? '') === 'urakka'): ?>
                        <p><strong>Kerroin:</strong> <?php echo escapeInput(number_format((float) ($createdContract['price_multiplier'] ?? 1.0), 1, ',', '')); ?></p>
                    <?php endif; ?>

                    <div class="summary-box">
                        <p><strong>Arvioitu loppusumma (sis. ALV):</strong> <?php echo formatCurrency($createdContract['estimate']['total_gross']); ?></p>
                        <p><strong>Kotitalousvahennysarvio:</strong> <?php echo formatCurrency($createdContract['estimate']['household_deduction']); ?></p>
                    </div>

                    <div class="action-row no-print top-gap">
                        <?php if ($proposalOnly): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_pending_contract">
                                <input type="hidden" name="address" value="<?php echo escapeInput((string) $createdContract['estimate']['address']); ?>">
                                <input type="hidden" name="agreement_type" value="<?php echo escapeInput((string) $createdContract['tyyppi']); ?>">
                                <input type="hidden" name="work_net" value="<?php echo escapeInput((string) $createdContract['estimate']['work_net']); ?>">
                                <input type="hidden" name="work_gross" value="<?php echo escapeInput((string) $createdContract['estimate']['work_gross']); ?>">
                                <input type="hidden" name="material_net" value="<?php echo escapeInput((string) $createdContract['estimate']['material_net']); ?>">
                                <input type="hidden" name="material_gross" value="<?php echo escapeInput((string) $createdContract['estimate']['material_gross']); ?>">
                                <input type="hidden" name="total_net" value="<?php echo escapeInput((string) $createdContract['estimate']['total_net']); ?>">
                                <input type="hidden" name="total_gross" value="<?php echo escapeInput((string) $createdContract['estimate']['total_gross']); ?>">
                                <input type="hidden" name="household_deduction" value="<?php echo escapeInput((string) $createdContract['estimate']['household_deduction']); ?>">
                                <input type="hidden" name="risk_factor" value="<?php echo escapeInput((string) $createdContract['estimate']['risk_factor']); ?>">
                                <input type="hidden" name="base_work_net" value="<?php echo escapeInput((string) $createdContract['estimate']['base_work_net']); ?>">
                                <input type="hidden" name="base_work_gross" value="<?php echo escapeInput((string) $createdContract['estimate']['base_work_gross']); ?>">
                                <input type="hidden" name="price_multiplier" value="<?php echo escapeInput((string) ($createdContract['price_multiplier'] ?? 1.0)); ?>">
                                <input type="hidden" name="selected_worksite_id" value="<?php echo escapeInput((string) $selectedWorksiteId); ?>">
                                <input type="hidden" name="customer_name" value="<?php echo escapeInput((string) ($_POST['customer_name'] ?? '')); ?>">
                                <input type="hidden" name="customer_address" value="<?php echo escapeInput((string) ($_POST['customer_address'] ?? '')); ?>">
                                <input type="hidden" name="customer_phone" value="<?php echo escapeInput((string) ($_POST['customer_phone'] ?? '')); ?>">
                                <input type="hidden" name="customer_email" value="<?php echo escapeInput((string) ($_POST['customer_email'] ?? '')); ?>">
                                <button type="submit" class="btn btn-primary">Tallenna sopimustarjous</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="info-box">
                <p>
                    Sopimuksella ei ole ID arvoa ja sen tila on ehdotus kun kyseistä sopimusta ei ole vielä tallennettu tietokantaan.
                    Kun sopimus siirretään ehdotuksiin, se tallennetaan tietokantaan ja tila siirtyy odottaa_hyväksyntää tilaan.
                </p>
                <p>
                    Hyväksyntää odottavat sopimukset näet Näytä tiedot sivulta.
                </p>
            </section>
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sahkotarsky - Laskutusjarjestelma</p>
        </footer>
    </div>

    <script>
        // Urakkahintaojen säätö
        const basePrice = <?php echo (float) $estimate['total_net']; ?>;
        
        function updateContractTypeUI() {
            const agreementType = document.getElementById('agreement_type').value;
            const urakkaPricingDiv = document.getElementById('urakka_pricing');
            
            if (agreementType === 'urakka') {
                urakkaPricingDiv.style.display = 'block';
                updateCalculatedPrice();
            } else {
                urakkaPricingDiv.style.display = 'none';
            }
        }
        
        function updateCalculatedPrice() {
            const multiplier = parseFloat(document.getElementById('price_multiplier').value) || 1.0;
            const calculated = basePrice * multiplier;
            
            document.getElementById('base_price').textContent = formatCurrency(basePrice);
            document.getElementById('calculated_price').textContent = formatCurrency(calculated);
        }
        
        function formatCurrency(value) {
            return value.toFixed(2).replace('.', ',') + ' €';
        }
        
        // Kuuntele kerroimen muutoksia
        document.getElementById('price_multiplier')?.addEventListener('input', updateCalculatedPrice);
        
        // Alusta näyttö sivun latautuessa
        document.addEventListener('DOMContentLoaded', function() {
            updateContractTypeUI();
        });
        
        // Asiakkaan automaattinen täyttö nimen perusteella
        document.getElementById('customer_name').addEventListener('change', function() {
            const name = this.value.trim();
            if (name === '') {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'fetch_customer_by_name');
            formData.append('customer_name', name);

            fetch('sopimus.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.found && data.customer) {
                    document.getElementById('customer_address').value = data.customer.as_osoite || '';
                    document.getElementById('customer_phone').value = data.customer.puh_nro || '';
                    document.getElementById('customer_email').value = data.customer.sahkoposti || '';
                }
            })
            .catch(error => console.error('Virhe asiakkaan haulla:', error));
        });
    </script>
</body>
</html>
