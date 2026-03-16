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

$error = '';
$mode = $_POST['mode'] ?? 'existing';
$existingAddressQuery = trim($_POST['existing_address_query'] ?? '');
$newAddress = trim($_POST['new_address'] ?? '');

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

        $workNet = 0.0;
        $workVat = 0.0;
        $materialNet = 0.0;
        $materialVat = 0.0;
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
            $workGross = $workNet + $workVat;
            $materialGross = $materialNet + $materialVat;

            $newEstimate = [
                'address' => $newAddress,
                'work_rows' => $selectedWorkRows,
                'material_rows' => $selectedMaterialRows,
                'work_net' => $workNet,
                'work_vat' => $workVat,
                'work_gross' => $workGross,
                'material_net' => $materialNet,
                'material_vat' => $materialVat,
                'material_gross' => $materialGross,
                'total_net' => $workNet + $materialNet,
                'total_gross' => $workGross + $materialGross,
                'household_deduction' => $workGross * 0.35
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
    <title>Hinta-arvio - Laskutusjarjestelma</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1>Tmi Sahkotarsky</h1>
                <p>Laskutusjarjestelma</p>
            </div>
        </header>

        <nav class="navbar">
            <ul>
                <li><a href="index.php">Etusivu</a></li>
                <li><a href="hinta_arvio.php" class="active">Hinta-arvio</a></li>
            </ul>
        </nav>

        <main class="content">
            <h2>Hinta-arvio</h2>
            <p>
                Tee nopea arvio tuntityönä tehtävistä töistä ja tarvikkeista.
                Hinta-arvioita ei ole mahdollista toteuttaa urakkamuotoisista töistä, koska niissä kustannukset voivat vaihdella suuresti työn laajuuden ja vaativuuden mukaan.
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
                                                step="0.01"
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
                                    <td>Työn osuus</td>
                                    <td><?php echo formatCurrency($newEstimate['work_net']); ?></td>
                                    <td><?php echo formatCurrency($newEstimate['work_gross']); ?></td>
                                </tr>
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
                            <li>ALV lisätään lopulliseen laskuun hinnaston mukaan.</li>
                            <li>Alennukset voidaan myöntää neuvottelun jälkeen.</li>
                            <li>Hinta-arvio ei sido yritystä lopulliseen hintaan.</li>
                            <li>Kotitalousvähennyksestä lisätietoa: <a href="https://www.vero.fi/henkiloasiakkaat/vahennykset/kotitalousvahennys/" target="_blank" rel="noopener">vero.fi</a></li>
                        </ul>
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
