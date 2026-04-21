<?php
session_start();
include 'config.php';

if (isset($_POST['tallenna'])) {

    if ( !empty($_POST['lasku_id']) && !empty($_POST['erapvm']) ) {
        $vanha_lasku_id = $_POST['lasku_id'];
        $pvm = date('Y-m-d');
        $erapaiva = $_POST['erapvm'];
        $laskutuslisa = 5.00;
        $viitenumero = ($_POST['viitenumero'] === '') ? NULL : $_POST['viitenumero'];

        $query_contract_id = executeQuery("SELECT sopimus_id FROM laskutus.lasku WHERE lasku_id = $vanha_lasku_id");
        $contract_id_row = pg_fetch_assoc($query_contract_id);
        $contract_number = $contract_id_row['sopimus_id'];

        pg_query($yhteys, "BEGIN");
        try {
            $query_bill_number = executeQuery("SELECT MAX(laskun_nro) FROM laskutus.lasku");
            $bill_number_row = pg_fetch_assoc($query_bill_number);
            $bill_number = $bill_number_row['max'] + 1;

            $query = "INSERT INTO lasku 
            (sopimus_id, edellinen_lasku_id, laskun_nro, muistutus_nro, 
            pvm, erapaiva, laskutuslisa, viitenumero)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";

            $res = pg_query_params($yhteys, $query, [$contract_number, $vanha_lasku_id, $bill_number, 1,
            $pvm, $erapaiva, $laskutuslisa, $viitenumero]);

            if (!$res) {
                throw new Exception("Muistutuslaskun lisäys epäonnistui");
            }

            pg_query($yhteys, "COMMIT");
            $success_message = "Lasku $bill_number tallennettu";
        } catch (Exception $e) {
            pg_query($yhteys, "ROLLBACK");
            $error_message = "Tallennus epäonnistui: $e";
        }

    }
}

$query_expired = 
"SELECT el.lasku_id, el.as_nimi, el.erapaiva 
FROM laskutus.eraantyvat_laskut el JOIN laskutus.lasku l ON el.lasku_id = l.lasku_id
WHERE el.erapaiva < CURRENT_DATE AND l.muistutus_nro = 0";
$expired_list = executeQuery($query_expired);

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
                <li><a href="index.php" class="active">
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

         <?php if (isset($error_message)): ?>
          <div id="alert" class="alert alert-error">
              <?php echo htmlspecialchars($error_message); ?>
          </div>
          <?php endif; ?>
        <?php if (isset($success_message)): ?>
            <div id="alert" class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <h2>Luo muistutuslasku</h2>

        <form action="luo_muistutuslasku.php" method="POST" class="form-container">
            <div class="form-group">
                <label>Erääntyneet laskut *</label>
                    <select name="lasku_id" required>
                        <option value="" disabled selected>Valitse lasku</option>
                        <?php
                        while ($row = pg_fetch_assoc($expired_list)) {
                                $id = $row['lasku_id'];
                                $customer = htmlspecialchars($row['as_nimi']);
                                $expirationdate = $row['erapaiva'];
                                echo "<option value=\"$id\">Lasku nro $id $customer (erääntynyt $expirationdate)</option>";
                        }
                        ?>
                    </select>
            </div>

            <h3>Uusi muistutuslasku </h3>

            <div class="form-group">
              <label>Eräpäivä *</label>
              <input type="date" name="erapvm" min="<?= date('Y-m-d'); ?>" required>
            </div>

            <div class="form-group">
              <label>Laskutuslisä</label>
              <input type="text" name="laskutuslisa" value="5.00 €" required readonly>
            </div>

            <div class="form-group">
              <label>Viitenumero</label>
              <input type="number" name="viitenumero" min=0 placeholder="Viitenumero">
            </div>

            <button type="submit" name="tallenna" class="btn btn-primary">Tallenna</button>

        </form>
                        
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>

</body>
</html>
