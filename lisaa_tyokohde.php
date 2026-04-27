<?php
session_start();
include 'config.php';

$query_clients = "SELECT asiakas.asiakas_id, asiakas.as_nimi FROM asiakas";
$client_list = executeQuery($query_clients);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'];
    $address = trim($_POST['address']);

    if (!filter_var($client_id, FILTER_VALIDATE_INT)) {
        $error_message = "Virheellinen asiakas";
    }
    elseif (empty($address)) {
        $error_message = "Osoite ei voi olla tyhjä";
    } 
    elseif (strlen($address) > 150) {
        $error_message = "Osoite liian pitkä (max 150 merkkiä)";
    } else {
        $check = pg_query_params($yhteys, "SELECT 1 FROM tyokohde WHERE asiakas_id = $1 AND kohde_osoite = $2",
         array($client_id, $address));
        if (pg_num_rows($check) > 0) {
            $error_message = "Tämä osoite on jo lisätty asiakkaalle";
        } else {
            $query = "INSERT INTO tyokohde (asiakas_id, kohde_osoite) VALUES ($1, $2)";
            $result = pg_query_params($yhteys, $query, array($client_id, $address));

            if ($result && (pg_affected_rows($result) > 0)) {
                $success_message  = "Työkohde '$address' lisätty";
            } else {
                $error_message = "Työkohteen lisäys epäonnistui";
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
                <li><a href="lisaa_tyokohde.php"  class="active">
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

    <h2>Lisää työkohde</h2>

    <form action="lisaa_tyokohde.php" method="POST" class="form-container">
        <div class="form-group">
            <label>Asiakas *</label>
            <select name="client_id" required>
                <option value="" disabled selected>Valitse asiakas</option>
                <?php
                while ($row = pg_fetch_assoc($client_list)) {
                        $id = $row['asiakas_id'];
                        $name = htmlspecialchars($row['as_nimi']);
                        echo "<option value=\"$id\">$name</option>";
                }
                ?>
            </select>
        </div>
        <br>
        <div class="form-group">
            <label>Työkohteen osoite *</label>
            <input type="text" name="address" required>
        </div>
        <button type="submit" class="btn btn-primary">Lisää</button>
    </form>

</main>
        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>
</body>
</html>