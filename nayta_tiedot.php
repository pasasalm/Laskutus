<?php
session_start();
include 'config.php';

function formatQuantity($value) {
    return number_format((float) $value, 2, ',', ' ');
}

$selectedSupplierId = (string) ($_GET['toimittaja_id'] ?? '');
$error = '';

$suppliersResult = executeQuery(
    "SELECT DISTINCT toimittaja_id, toimittaja_nimi
     FROM toimitetut_tavarat_per_toimittaja
     ORDER BY toimittaja_nimi ASC"
);
$suppliers = fetchAll($suppliersResult);

$deliveryRows = [];
$summary = null;
$selectedSupplierName = '';
$approvalMessage = '';
$approvalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_pending_contract') {
        $contractId = trim((string) ($_POST['sopimus_id'] ?? ''));
        $contractType = trim((string) ($_POST['sopimus_tyyppi'] ?? ''));
        $worksiteId = trim((string) ($_POST['tyokohde_id'] ?? ''));

        if ($contractId === '' || !ctype_digit($contractId)) {
            $approvalError = 'Virheellinen sopimuksen tunniste.';
        } else {
            $approvalResult = executeQuery(
                "UPDATE sopimus
                 SET tila = 'kesken'
                 WHERE sopimus_id = $1
                   AND tila = 'odottaa_hyväksyntää'
                 RETURNING sopimus_id, tila",
                [$contractId]
            );
            $approvedContract = fetchOne($approvalResult);

            if ($approvedContract) {
                if ($contractType === 'urakka' && ctype_digit($worksiteId)) {
                    $queryString = http_build_query([
                        'tyokohde_id' => $worksiteId,
                        'sopimus_id' => $contractId,
                        'message' => 'Urakkasopimus hyväksytty. Luo urakkalaskutus erissä.'
                    ]);
                    header('Location: lasku.php?' . $queryString);
                    exit;
                }

                $approvalMessage = 'Sopimus hyväksytty ja siirretty tilaan kesken.';
            } else {
                $approvalError = 'Sopimusta ei löytynyt tai se ei enää odottanut hyväksyntää.';
            }
        }
    }
}

$pendingContractsResult = executeQuery(
    "SELECT
        sopimus_id,
        tyyppi,
        tila,
        pvm,
        asiakas_id,
        as_nimi,
        as_osoite,
        puh_nro,
        sahkoposti,
        tyokohde_id,
        kohde_osoite,
        tyon_osuus_netto,
        tarvikkeet_osuus_netto,
        yhteensa_netto
     FROM odottavat_sopimukset
     ORDER BY pvm ASC, sopimus_id ASC"
);
$pendingContracts = fetchAll($pendingContractsResult);
$pendingContractCount = count($pendingContracts);

if ($selectedSupplierId !== '') {
    if (!ctype_digit($selectedSupplierId)) {
        $error = 'Virheellinen toimittajan valinta.';
    } else {
        $selectedSupplierQuery = executeQuery(
            "SELECT DISTINCT toimittaja_nimi
             FROM toimitetut_tavarat_per_toimittaja
             WHERE toimittaja_id = $1
             LIMIT 1",
            [$selectedSupplierId]
        );
        $selectedSupplierRow = fetchOne($selectedSupplierQuery);

        if (!$selectedSupplierRow) {
            $error = 'Valittua toimittajaa ei löytynyt näkymästä.';
        } else {
            $selectedSupplierName = $selectedSupplierRow['toimittaja_nimi'];

            $deliveryResult = executeQuery(
                "SELECT
                    toimittaja_id,
                    toimittaja_nimi,
                    tyokohde_id,
                    kohde_osoite,
                    tarvike_id,
                    tarvike_nimi,
                    SUM(maara_toimitettu) AS maara_toimitettu
                 FROM toimitetut_tavarat_per_toimittaja
                 WHERE toimittaja_id = $1
                 GROUP BY toimittaja_id, toimittaja_nimi, tyokohde_id, kohde_osoite, tarvike_id, tarvike_nimi
                 ORDER BY kohde_osoite, tarvike_nimi",
                [$selectedSupplierId]
            );
            $deliveryRows = fetchAll($deliveryResult);

            $summaryResult = executeQuery(
                "SELECT
                    COUNT(DISTINCT tyokohde_id) AS kohteita,
                    COUNT(DISTINCT tarvike_id) AS tuotteita,
                    COALESCE(SUM(maara_toimitettu), 0) AS yhteensa_maara
                 FROM toimitetut_tavarat_per_toimittaja
                 WHERE toimittaja_id = $1",
                [$selectedSupplierId]
            );
            $summary = fetchOne($summaryResult);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Näytä tiedot - Laskutusjärjestelmä</title>
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
                    <li><a href="nayta_tiedot.php" class="active">
                    <i class="fa-solid fa-database"></i>Näytä tiedot</a></li>
                </ul>
            </div>
        </nav>

        <main class="content">
            <section class="card">
                <h2>Näytä tiedot</h2>
                <p class="lead">
                    Muodosta raportti toimitetuista tuotteista ja käsittele odottavat sopimukset.
                    Valitse toimittaja ja näe, kenelle tuotteita on toimitettu, mihin työkohteisiin ja mitä tuotteita sekä määriä on käytetty.
                    Odottavat sopimukset perustuvat näkymään odottavat_sopimukset.
                </p>
            </section>
                <div class="form-container">
                    <h3>Hinnaston muutos</h3>
                    <p>
                        Päivitä uusi hinnasto hinnasto sivulla.
                    </p>
                    <a class="btn btn-secondary" href="uusi_hinnasto.php">Siirry hinnastoon</a>
                </div>

            <section class="card">
                <h3>Odotettavat sopimukset</h3>
                <p class="muted">
                    Näitä sopimuksia ei voi vielä laskuttaa. Hyväksyntä siirtää sopimuksen tilaan kesken.
                </p>

                <?php if ($approvalMessage !== ''): ?>
                    <p class="alert alert-success"><?php echo escapeInput($approvalMessage); ?></p>
                <?php endif; ?>

                <?php if ($approvalError !== ''): ?>
                    <p class="alert alert-warning"><?php echo escapeInput($approvalError); ?></p>
                <?php endif; ?>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="label">Odotettavia sopimuksia</div>
                        <div class="value"><?php echo escapeInput((string) $pendingContractCount); ?></div>
                    </div>
                </div>

                <?php if (!empty($pendingContracts)): ?>
                    <div class="table-wrap top-gap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Sopimus</th>
                                    <th>Asiakas ja kohde</th>
                                    <th>Erittely</th>
                                    <th>Toimi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingContracts as $contract): ?>
                                    <tr>
                                        <td>
                                            <?php echo escapeInput((string) $contract['sopimus_id']); ?><br>
                                            <span class="muted"><?php echo escapeInput($contract['tyyppi']); ?></span><br>
                                            <span class="muted"><?php echo escapeInput($contract['pvm']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo escapeInput($contract['as_nimi']); ?><br>
                                            <span class="muted"><?php echo escapeInput($contract['as_osoite']); ?></span><br>
                                            <span class="muted"><?php echo escapeInput($contract['kohde_osoite']); ?></span>
                                        </td>
                                        <td>
                                            Työ: <?php echo escapeInput(formatQuantity($contract['tyon_osuus_netto'])); ?> €<br>
                                            Tarvikkeet: <?php echo escapeInput(formatQuantity($contract['tarvikkeet_osuus_netto'])); ?> €<br>
                                            Yhteensä: <?php echo escapeInput(formatQuantity($contract['yhteensa_netto'])); ?> €
                                        </td>
                                        <td>
                                            <form method="POST" action="" class="inline-form">
                                                <input type="hidden" name="action" value="approve_pending_contract">
                                                <input type="hidden" name="sopimus_id" value="<?php echo escapeInput((string) $contract['sopimus_id']); ?>">
                                                <input type="hidden" name="sopimus_tyyppi" value="<?php echo escapeInput((string) $contract['tyyppi']); ?>">
                                                <input type="hidden" name="tyokohde_id" value="<?php echo escapeInput((string) $contract['tyokohde_id']); ?>">
                                                <button type="submit" class="btn btn-primary">Hyväksy sopimus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="alert alert-info top-gap">Ei odottavia sopimuksia juuri nyt.</p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Toimittajan toimittamat tuotteet</h3>
                <p class="muted">
                    Valitse toimittaja ja tarkastele toimitettuja tuotteita, kohteita ja määriä.
                </p>
            </section>

            <section class="form-container">
                <form class="filter-form" method="GET" action="">
                    <div class="form-group">
                        <label for="toimittaja_id">Valitse toimittaja</label>
                        <select name="toimittaja_id" id="toimittaja_id" required>
                            <option value="">-- Valitse toimittaja --</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo escapeInput((string) $supplier['toimittaja_id']); ?>" <?php echo $selectedSupplierId === (string) $supplier['toimittaja_id'] ? 'selected' : ''; ?>>
                                    <?php echo escapeInput($supplier['toimittaja_nimi']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group actions">
                        <button type="submit">Muodosta raportti</button>
                    </div>
                </form>
            </section>

            <?php if ($error !== ''): ?>
                <section class="card">
                    <p class="alert alert-warning"><?php echo escapeInput($error); ?></p>
                </section>
            <?php endif; ?>

            <?php if ($selectedSupplierId !== '' && $error === ''): ?>
                <section class="card">
                    <p class="supplier-name"><?php echo escapeInput($selectedSupplierName); ?></p>
                    <p class="muted">Toimitukset, kohteet ja tuotteet valitulta toimittajalta.</p>

                    <?php if ($summary): ?>
                        <div class="summary-grid">
                            <div class="summary-card">
                                <div class="label">Työkohteita</div>
                                <div class="value"><?php echo escapeInput((string) $summary['kohteita']); ?></div>
                            </div>
                            <div class="summary-card">
                                <div class="label">Eri tuotteita</div>
                                <div class="value"><?php echo escapeInput((string) $summary['tuotteita']); ?></div>
                            </div>
                            <div class="summary-card">
                                <div class="label">Toimitettu määrä yhteensä</div>
                                <div class="value"><?php echo escapeInput(formatQuantity($summary['yhteensa_maara'])); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <?php if (!empty($deliveryRows)): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Työkohde</th>
                                        <th>Tuote</th>
                                        <th>Määrä</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deliveryRows as $row): ?>
                                        <tr>
                                            <td>
                                                <?php echo escapeInput($row['kohde_osoite']); ?><br>
                                                <span class="muted">Kohde ID: <?php echo escapeInput((string) $row['tyokohde_id']); ?></span>
                                            </td>
                                            <td>
                                                <?php echo escapeInput($row['tarvike_nimi']); ?><br>
                                                <span class="muted">Tuote ID: <?php echo escapeInput((string) $row['tarvike_id']); ?></span>
                                            </td>
                                            <td><?php echo escapeInput(formatQuantity($row['maara_toimitettu'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="alert alert-warning">Valitulle toimittajalle ei löytynyt toimituksia näkymästä.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>
</body>
</html>