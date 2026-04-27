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

function normalizeOfferType($value) {
    $normalized = trim((string) $value);

    if ($normalized === 'tuntityo') {
        $normalized = 'tuntityö';
    }

    if (!in_array($normalized, ['tuntityö', 'urakka'], true)) {
        return 'tuntityö';
    }

    return $normalized;
}

$error = '';
$mode = $_POST['mode'] ?? 'existing';
$existingAddressQuery = trim($_POST['existing_address_query'] ?? '');
$newAddress = trim($_POST['new_address'] ?? '');
$offerType = normalizeOfferType($_POST['offer_type'] ?? 'tuntityö');

$existingEstimateRows = [];
$newEstimate = null;
$selectedWorkQty = $_POST['work_qty'] ?? [];
$selectedMaterialQty = $_POST['material_qty'] ?? [];

$workTypesResult = executeQuery(
    "SELECT
        tuntityo_id AS id,
        nimi AS name,
        hinta_netto AS net_price,
        alv_prosentti AS vat_percent
     FROM tuntityo_hinnasto
     ORDER BY nimi"
);
$workTypes = fetchAll($workTypesResult);

$materialsResult = executeQuery(
    "SELECT
        tarvike_id AS id,
        tarvike_nimi AS name,
        merkki AS brand,
        myyntihinta AS net_price,
        yksikko AS unit,
        alv_prosentti AS vat_percent
     FROM tarvikkeet
     ORDER BY tarvike_nimi, merkki"
);
$materials = fetchAll($materialsResult);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'estimate_existing') {
        if ($existingAddressQuery === '') {
            $error = 'Syota tyokohteen osoite hakuun.';
        } else {
            $result = executeQuery(
                "SELECT
                    tyokohde_id AS worksite_id,
                    kohde_osoite AS address,
                    arvio_tyon_netto AS work_net,
                    arvio_tarvikkeet_netto AS materials_net,
                    arvio_yhteensa_netto AS total_net
                 FROM hinta_arvio
                 WHERE LOWER(kohde_osoite) LIKE LOWER($1)
                 ORDER BY kohde_osoite",
                ['%' . $existingAddressQuery . '%']
            );
            $existingEstimateRows = fetchAll($result);
        }
    }

    if ($action === 'estimate_new') {
        if ($newAddress === '') {
            $error = 'Syota uuden tyokohteen osoite.';
        }

        $offerType = normalizeOfferType($_POST['offer_type'] ?? 'tuntityö');

        $workNet = 0.0;
        $workVat = 0.0;
        $materialNet = 0.0;
        $materialVat = 0.0;
        $totalWorkHours = 0.0;
        $selectedWorkRows = [];
        $selectedMaterialRows = [];

        foreach ($workTypes as $work) {
            $qty = parseDecimal($selectedWorkQty[$work['id']] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lineNet = $qty * (float) $work['net_price'];
            $lineVat = $lineNet * ((float) $work['vat_percent'] / 100.0);
            $workNet += $lineNet;
            $workVat += $lineVat;
            $totalWorkHours += $qty;

            $selectedWorkRows[] = [
                'name' => $work['name'],
                'quantity' => $qty,
                'unit_price' => (float) $work['net_price'],
                'line_net' => $lineNet
            ];
        }

        foreach ($materials as $material) {
            $qty = parseDecimal($selectedMaterialQty[$material['id']] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lineNet = $qty * (float) $material['net_price'];
            $lineVat = $lineNet * ((float) $material['vat_percent'] / 100.0);
            $materialNet += $lineNet;
            $materialVat += $lineVat;

            $selectedMaterialRows[] = [
                'name' => $material['name'],
                'brand' => $material['brand'],
                'unit' => $material['unit'],
                'quantity' => $qty,
                'unit_price' => (float) $material['net_price'],
                'line_net' => $lineNet
            ];
        }

        if ($error === '' && empty($selectedWorkRows) && empty($selectedMaterialRows)) {
            $error = 'Anna arvioitu tuntimaara tai tarvike­maara vahintaan yhdelle riville.';
        }

        if ($error === '') {
            $workNetForOffer = $workNet;
            $workVatForOffer = $workVat;
            $riskPercent = 0;
            $riskFactor = 1.0;
            $riskLabel = 'Ei riskiluokitusta';

            if ($offerType === 'urakka') {
                if ($totalWorkHours < 10) {
                    $riskPercent = 10;
                    $riskFactor = 1.1;
                    $riskLabel = 'Matalariskinen urakka';
                } elseif ($totalWorkHours <= 30) {
                    $riskPercent = 20;
                    $riskFactor = 1.2;
                    $riskLabel = 'Keskiriskinen urakka';
                } else {
                    $riskPercent = 30;
                    $riskFactor = 1.3;
                    $riskLabel = 'Suuren riskin urakka';
                }

                $workNetForOffer = $workNet * $riskFactor;
                $workVatForOffer = $workVat * $riskFactor;
            }

            $workGross = $workNetForOffer + $workVatForOffer;
            $materialGross = $materialNet + $materialVat;
            $workGrossForDeduction = $workNet + $workVat;

            $newEstimate = [
                'address' => $newAddress,
                'work_rows' => $selectedWorkRows,
                'material_rows' => $selectedMaterialRows,
                'work_net' => $workNetForOffer,
                'work_vat' => $workVatForOffer,
                'work_gross' => $workGross,
                'material_net' => $materialNet,
                'material_vat' => $materialVat,
                'material_gross' => $materialGross,
                'total_net' => $workNetForOffer + $materialNet,
                'total_gross' => $workGross + $materialGross,
                'household_deduction' => $workGrossForDeduction * 0.35,
                'offer_type' => $offerType,
                'total_work_hours' => $totalWorkHours,
                'risk_percent' => $riskPercent,
                'risk_factor' => $riskFactor,
                'risk_label' => $riskLabel,
                'base_work_net' => $workNet,
                'base_work_gross' => $workNet + $workVat
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tmi Sähkötärsky - Laskutusjärjestelmä</title>
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
                <li><a href="hinta_arvio.php" class="active">
                <i class="fa-solid fa-calculator"></i>Hinta-arvio</a></li>
                <li><a href="lasku.php">
                <i class="fa-solid fa-file-invoice"></i>Luo lasku</a></li>
                <li><a href="nayta_tiedot.php">
                <i class="fa-solid fa-database"></i>Näytä tiedot</a></li>
            </ul>
        </div>
        </nav>

        <main class="content">
            <h2>Hinta-arvio</h2>
            <p>
                Tee arvio joko tuntityönä tai urakkatarjouksena. Molemmat haarat kulkevat saman perusrungon kautta,
                mutta urakkatarjous siirtyy erikseen hyväksyttäväksi sopimusvaiheeseen.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo escapeInput($error); ?></div>
            <?php endif; ?>

            <div class="tabs">
                <button
                    type="button"
                    class="tab-btn <?php echo $mode === 'existing' ? 'active' : ''; ?>"
                    onclick="switchMode('existing', this)"
                >
                    Minulla on jo työkohde
                </button>
                <button
                    type="button"
                    class="tab-btn <?php echo $mode === 'new' ? 'active' : ''; ?>"
                    onclick="switchMode('new', this)"
                >
                    Haluan arvion uudelle työkohteelle
                </button>
            </div>

            <div id="existing-mode" class="tab-content <?php echo $mode === 'existing' ? 'active' : ''; ?>">
                <div class="form-container">
                    <form method="POST" action="">
                        <input type="hidden" name="mode" value="existing">
                        <input type="hidden" name="action" value="estimate_existing">

                        <div class="form-group">
                            <label for="existing_address_query">Työkohde osoitehaku *</label>
                            <input
                                type="text"
                                name="existing_address_query"
                                id="existing_address_query"
                                placeholder="Esim. Kotikatu 5"
                                value="<?php echo escapeInput($existingAddressQuery); ?>"
                                required
                            >
                        </div>

                        <button type="submit" class="btn btn-primary">Hae hinta-arvio</button>
                    </form>
                </div>

                <?php if (!empty($existingEstimateRows)): ?>
                    <h3>Löydetyt arviot</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Työkohde</th>
                                <th>Työn osuus (netto)</th>
                                <th>Tarvikkeet (netto)</th>
                                <th>Yhteensä (netto)</th>
                                <th>Arvio kotitalousvähennyksestä*</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existingEstimateRows as $row): ?>
                                <?php $workGross = (float) $row['work_net'] * 1.24; ?>
                                <tr>
                                    <td><?php echo escapeInput($row['address']); ?></td>
                                    <td><?php echo formatCurrency($row['work_net']); ?></td>
                                    <td><?php echo formatCurrency($row['materials_net']); ?></td>
                                    <td><?php echo formatCurrency($row['total_net']); ?></td>
                                    <td><?php echo formatCurrency($workGross * 0.35); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'existing' && $error === ''): ?>
                    <div class="alert alert-info">Osoitteella ei löytynyt hinta-arviota.</div>
                <?php endif; ?>
            </div>

            <div id="new-mode" class="tab-content <?php echo $mode === 'new' ? 'active' : ''; ?>">
                <div class="form-container">
                    <form method="POST" action="">
                        <input type="hidden" name="mode" value="new">
                        <input type="hidden" name="action" value="estimate_new">

                        <div class="form-group">
                            <label for="new_address">Uuden työkohde osoite *</label>
                            <input
                                type="text"
                                name="new_address"
                                id="new_address"
                                placeholder="Esim. Kotikatu 5, 33100 Tampere"
                                value="<?php echo escapeInput($newAddress); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="offer_type">Tarjoustyyppi *</label>
                            <select name="offer_type" id="offer_type" required>
                                <option value="tuntityö" <?php echo $offerType === 'tuntityö' ? 'selected' : ''; ?>>Tuntityö</option>
                                <option value="urakka" <?php echo $offerType === 'urakka' ? 'selected' : ''; ?>>Urakkatarjous</option>
                            </select>
                        </div>

                        <h3>Arvioidut tyotunnit</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Työn nimi</th>
                                    <th>Hinta netto / h</th>
                                    <th>ALV %</th>
                                    <th>Arvioitu tuntimäärä</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workTypes as $work): ?>
                                    <tr>
                                        <td><?php echo escapeInput($work['name']); ?></td>
                                        <td><?php echo formatCurrency($work['net_price']); ?></td>
                                        <td><?php echo escapeInput($work['vat_percent']); ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.5"
                                                min="0"
                                                name="work_qty[<?php echo $work['id']; ?>]"
                                                value="<?php echo escapeInput($selectedWorkQty[$work['id']] ?? '0'); ?>"
                                            >
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <h3>Arvioidut tarvikemäärät</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tarvike</th>
                                    <th>Merkki</th>
                                    <th>Hinta netto</th>
                                    <th>Yksikkö</th>
                                    <th>ALV %</th>
                                    <th>Arvioitu määrä</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials as $material): ?>
                                    <tr>
                                        <td><?php echo escapeInput($material['name']); ?></td>
                                        <td><?php echo escapeInput($material['brand']); ?></td>
                                        <td><?php echo formatCurrency($material['net_price']); ?></td>
                                        <td><?php echo escapeInput($material['unit']); ?></td>
                                        <td><?php echo escapeInput($material['vat_percent']); ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                name="material_qty[<?php echo $material['id']; ?>]"
                                                value="<?php echo escapeInput($selectedMaterialQty[$material['id']] ?? '0'); ?>"
                                            >
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <button type="submit" class="btn btn-primary">Laske hinta-arvio</button>
                    </form>
                </div>

                <?php if ($newEstimate): ?>
                    <section class="info-section">
                        <h3>Arvio uudelle työkohteelle</h3>
                        <p><strong>Kohde:</strong> <?php echo escapeInput($newEstimate['address']); ?></p>
                        <p><strong>Tarjoustyyppi:</strong> <?php echo escapeInput($newEstimate['offer_type']); ?></p>

                        <?php if ($newEstimate['offer_type'] === 'urakka'): ?>
                            <div class="alert alert-info">
                                <strong><?php echo escapeInput($newEstimate['risk_label']); ?></strong><br>
                                Työtunnit yhteensä: <strong><?php echo escapeInput(number_format((float) $newEstimate['total_work_hours'], 2, ',', ' ')); ?> h</strong><br>
                                Riskilisä: <strong><?php echo escapeInput((string) $newEstimate['risk_percent']); ?> %</strong>
                                (kerroin <?php echo escapeInput(number_format((float) $newEstimate['risk_factor'], 1, ',', '')); ?>)
                            </div>
                        <?php endif; ?>

                        <table class="estimate-table">
                            <thead>
                                <tr>
                                    <th>Erittely</th>
                                    <th>Summa (netto)</th>
                                    <th>Summa (sis. ALV)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo $newEstimate['offer_type'] === 'urakka' ? 'Työn osuus (riskikorjattu)' : 'Työn osuus'; ?></td>
                                    <td><?php echo formatCurrency($newEstimate['work_net']); ?></td>
                                    <td><?php echo formatCurrency($newEstimate['work_gross']); ?></td>
                                </tr>
                                <?php if ($newEstimate['offer_type'] === 'urakka'): ?>
                                    <tr>
                                        <td>Työn osuus ilman riskikerrointa</td>
                                        <td><?php echo formatCurrency($newEstimate['base_work_net']); ?></td>
                                        <td><?php echo formatCurrency($newEstimate['base_work_gross']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Tarvikkeiden osuus</td>
                                    <td><?php echo formatCurrency($newEstimate['material_net']); ?></td>
                                    <td><?php echo formatCurrency($newEstimate['material_gross']); ?></td>
                                </tr>
                                <tr class="total-row">
                                    <td><strong>Yhteensä</strong></td>
                                    <td><strong><?php echo formatCurrency($newEstimate['total_net']); ?></strong></td>
                                    <td><strong><?php echo formatCurrency($newEstimate['total_gross']); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="alert alert-info">
                            Arvio kotitalousvähennyksestä kelpaavasta osuudesta (35 % työn ALV-hinnasta):
                            <strong><?php echo formatCurrency($newEstimate['household_deduction']); ?></strong>
                        </div>

                        <h4>Huomautukset</h4>
                        <ul>
                            <li>Hinta-arvio perustuu arvioituihin tyotunteihin ja valittuihin tarvikkeisiin.</li>
                            <?php if ($newEstimate['offer_type'] === 'urakka'): ?>
                                <li>Urakkahinta lasketaan kaavalla: (työn netto * riskikerroin) + tarvikkeet, jonka jälkeen lisätään ALV.</li>
                                <li>Kotitalousvähennyksen arvio perustuu työn osuuteen ilman riskikerrointa.</li>
                            <?php else: ?>
                                <li>ALV lisätään lopulliseen laskuun hinnaston mukaan.</li>
                            <?php endif; ?>
                            <li>Alennukset voidaan myöntää neuvottelun jälkeen.</li>
                            <li>Hinta-arvio ei sido yritystä lopulliseen hintaan.</li>
                            <li>Kotitalousvähennyksestä lisätietoa: <a href="https://www.vero.fi/henkiloasiakkaat/vahennykset/kotitalousvahennys/" target="_blank" rel="noopener">vero.fi</a></li>
                        </ul>

                        <form method="POST" action="sopimus.php" class="top-gap">
                            <input type="hidden" name="source" value="estimate_new">
                            <input type="hidden" name="agreement_type" value="<?php echo escapeInput($newEstimate['offer_type']); ?>">
                            <input type="hidden" name="address" value="<?php echo escapeInput($newEstimate['address']); ?>">
                            <input type="hidden" name="work_net" value="<?php echo escapeInput((string) $newEstimate['work_net']); ?>">
                            <input type="hidden" name="work_gross" value="<?php echo escapeInput((string) $newEstimate['work_gross']); ?>">
                            <input type="hidden" name="material_net" value="<?php echo escapeInput((string) $newEstimate['material_net']); ?>">
                            <input type="hidden" name="material_gross" value="<?php echo escapeInput((string) $newEstimate['material_gross']); ?>">
                            <input type="hidden" name="total_net" value="<?php echo escapeInput((string) $newEstimate['total_net']); ?>">
                            <input type="hidden" name="total_gross" value="<?php echo escapeInput((string) $newEstimate['total_gross']); ?>">
                            <input type="hidden" name="household_deduction" value="<?php echo escapeInput((string) $newEstimate['household_deduction']); ?>">
                            <input type="hidden" name="risk_factor" value="<?php echo escapeInput((string) ($newEstimate['risk_factor'] ?? 1.0)); ?>">
                            <input type="hidden" name="base_work_net" value="<?php echo escapeInput((string) ($newEstimate['base_work_net'] ?? $newEstimate['work_net'])); ?>">
                            <input type="hidden" name="base_work_gross" value="<?php echo escapeInput((string) ($newEstimate['base_work_gross'] ?? $newEstimate['work_gross'])); ?>">
                            <button type="submit" class="btn btn-success">
                                <?php echo $newEstimate['offer_type'] === 'urakka' ? 'Siirry urakkasopimukseen' : 'Siirry sopimussivulle'; ?>
                            </button>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sahkotarsky - Laskutusjarjestelma</p>
        </footer>
    </div>

    <script>
        function switchMode(mode, button) {
            document.getElementById('existing-mode').classList.remove('active');
            document.getElementById('new-mode').classList.remove('active');

            const tabs = document.querySelectorAll('.tab-btn');
            tabs.forEach((btn) => btn.classList.remove('active'));

            document.getElementById(mode + '-mode').classList.add('active');
            if (button) {
                button.classList.add('active');
            }
        }
    </script>
</body>
</html>
