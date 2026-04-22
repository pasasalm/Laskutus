<?php
session_start();
include 'config.php';

if(isset($_POST['paivita'])) {

  $file=$_POST['xmlfile'];
  $allowedFiles=scandir("hinnastot");

  if(!in_array($file,$allowedFiles)) {
    throw new Exception(
        "Valittua hinnastotiedostoa ei löydy."
    );
  }

  $xml=simplexml_load_file("hinnastot/".$file);

  if(!$xml) {
    throw new Exception(
        "Hinnastotiedoston lukeminen epäonnistui."
    );
  }

  pg_query($yhteys,"BEGIN");

  try {

    pg_query($yhteys,"
    CREATE TEMP TABLE hinnasto_import(
    tarvike_id INT,
    ostohinta DECIMAL(10,2),
    myyntihinta DECIMAL(10,2),
    lahdetiedosto VARCHAR(25)
    )
    ");

    foreach($xml->tarvike as $t) {
      $id=(int)$t->ttiedot->id;
      $ostohinta=(float)$t->ttiedot->hinta;
      $myyntihinta= $ostohinta * 1.25;

      pg_query_params(
      $yhteys,
      "INSERT INTO hinnasto_import
      (tarvike_id,ostohinta,myyntihinta,lahdetiedosto)
      VALUES($1,$2,$3,$4)",
      array($id,$ostohinta,$myyntihinta,$file)
      );
    }

    $changed_prices = pg_query($yhteys,"
    SELECT
    t.tarvike_id,
    t.tarvike_nimi,
    t.ostohinta vanha_ostohinta,
    i.ostohinta uusi_ostohinta,
    t.myyntihinta vanha_myyntihinta,
    i.myyntihinta uusi_myyntihinta
    FROM tarvikkeet t
    JOIN hinnasto_import i
    ON t.tarvike_id=i.tarvike_id
    WHERE t.ostohinta<>i.ostohinta
    ORDER BY t.tarvike_nimi
    ");

    $rows = pg_num_rows($changed_prices);

    if ($rows == 0) {
      $success_message = "Hinnasto on jo ajan tasalla.";
      $changed_prices = NULL;
      pg_query($yhteys,"ROLLBACK");
    }
    else {
      pg_query($yhteys,"
      INSERT INTO tarvikkeet_hinta_historia (
      tarvike_id,
      toimittaja_id,
      vanha_ostohinta,
      uusi_ostohinta,
      vanha_myyntihinta,
      uusi_myyntihinta,
      muutos_aika,
      lahdetiedosto)
      SELECT
      t.tarvike_id,
      t.toimittaja_id,
      t.ostohinta,
      i.ostohinta,
      t.myyntihinta,
      i.myyntihinta,
      NOW(),
      i.lahdetiedosto
      FROM tarvikkeet t
      JOIN hinnasto_import i
      ON t.tarvike_id=i.tarvike_id
      WHERE t.ostohinta<>i.ostohinta");

      pg_query($yhteys,"
      UPDATE tarvikkeet t
      SET ostohinta=i.ostohinta, myyntihinta=i.myyntihinta
      FROM hinnasto_import i
      WHERE t.tarvike_id=i.tarvike_id
      AND t.ostohinta<>i.ostohinta");

      pg_query($yhteys,"COMMIT");

      $success_message = "Hinnat päivitetty onnistuneesti.";
    }

  } catch(Exception $e) {
    pg_query($yhteys,"ROLLBACK");

    $error_message = "Virhe: ".$e->getMessage();
  }

}

  $current_prices = pg_query($yhteys,"
    SELECT
    t.tarvike_id,
    t.tarvike_nimi,
    t.ostohinta,
    t.myyntihinta
    FROM tarvikkeet t
    ORDER BY t.tarvike_nimi
    ");

  $files = array();

  foreach(scandir("hinnastot") as $f) {
    if(pathinfo($f,PATHINFO_EXTENSION)=="XML") {
      $files[]=$f;
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

        <?php if (isset($changed_prices)): ?>
          <h2>Päivitetyt hinnat</h2>
          <table class="data-table">
          <tr>
            <th>ID</th><th>Tuote</th>
            <th>Uusi ostohinta €</th><th>Vanha ostohinta €</th>
            <th>Uusi myyntihinta €</th><th>Vanha myyntihinta €</th>
          </tr>
          <?php
              while($r=pg_fetch_assoc($changed_prices)) {
              echo "<tr>";
              echo "<td>".$r['tarvike_id']."</td>";
              echo "<td>".$r['tarvike_nimi']."</td>";
              echo "<td>".$r['uusi_ostohinta']."</td>";
              echo "<td>".$r['vanha_ostohinta']."</td>";
              echo "<td>".$r['uusi_myyntihinta']."</td>";
              echo "<td>".$r['vanha_myyntihinta']."</td>";
              echo "</tr>";
            }
            ?>
        </table>
        <?php endif; ?>

        <h2>Uusi hinnasto</h2>
        <p>Päivitä uudet tarvikehinnat</p>

        <form method="post" action="uusi_hinnasto.php" class="form-container">
          <div class="form-group">
          <select name="xmlfile">
            <option value="" disabled selected>Valitse hinnasto</option>
          <?php
            foreach($files as $f) {
              echo "<option value='$f'>$f</option>";
            }
          ?>
          </select>
          </div>
          <button type="submit" name="paivita" class="btn btn-primary">Päivitä hinnat</button>
        </form>
                
        <h2>Nykyinen hinnasto</h2>
        <table class="data-table">
          <tr>
            <th>ID</th><th>Tuote</th><th>Ostohinta €</th><th>Myyntihinta €</th>
          </tr>
          <?php
              while($r=pg_fetch_assoc($current_prices)) {
              echo "<tr>";
              echo "<td>".$r['tarvike_id']."</td>";
              echo "<td>".$r['tarvike_nimi']."</td>";
              echo "<td>".$r['ostohinta']."</td>";
              echo "<td>".$r['myyntihinta']."</td>";
              echo "</tr>";
            }
            ?>
        </table>


        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>

</body>
</html>
