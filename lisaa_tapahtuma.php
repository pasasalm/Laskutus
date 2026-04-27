<?php
session_start();
include 'config.php';

$query_contracts = 
"SELECT sopimus.sopimus_id, sopimus.tyokohde_id, tyokohde.kohde_osoite 
FROM sopimus JOIN laskutus.tyokohde ON sopimus.tyokohde_id = tyokohde.tyokohde_id 
WHERE sopimus.tila = 'kesken' AND sopimus.tyyppi = 'tuntityö'";
$contract_list = executeQuery($query_contracts);

$query_worktypes = "SELECT tuntityo_hinnasto.tuntityo_id, tuntityo_hinnasto.nimi FROM tuntityo_hinnasto";
$worktype_list = executeQuery($query_worktypes);

$query_supplies = "SELECT tarvikkeet.tarvike_id, tarvikkeet.tarvike_nimi FROM tarvikkeet";
$supply_list = executeQuery($query_supplies);


if (isset($_POST['tallenna'])) {

    if ( !empty($_POST['sopimus_id']) && !empty($_POST['tuntityot_json']) && !empty($_POST['pvm']) ) {
        $sopimus_id = $_POST['sopimus_id'];
        $pvm = $_POST['pvm'];

        pg_query($yhteys, "BEGIN");

        try {
          $tuntityot = json_decode($_POST['tuntityot_json'], true);

          foreach ($tuntityot as $t) {

            $query = "INSERT INTO tyo_suorite 
                    (sopimus_id, tuntityo_id, maara, pvm, alennusprosentti)
                    VALUES ($1, $2, $3, $4, $5)";

            $res = pg_query_params($yhteys, $query, [
                $sopimus_id,
                $t['tuntityo_id'],
                $t['tunnit'],
                $pvm,
                $t['alennus']
            ]);

            if (!$res) {
                throw new Exception("Tuntityön lisäys epäonnistui");
            }
          }

          if (!empty($_POST['tarvikkeet_json'])) {

            $tarvikkeet = json_decode($_POST['tarvikkeet_json'], true);

            foreach ($tarvikkeet as $t) {

              $query = "INSERT INTO kaytetyt_tarvikkeet
                        (tarvike_id, sopimus_id, pvm, maara, alennusprosentti)
                        VALUES ($1, $2, $3, $4, $5)";

              $res = pg_query_params($yhteys, $query, [
                  $t['tarvike_id'],
                  $sopimus_id,
                  $pvm,
                  $t['maara'],
                  $t['alennus']
              ]);

              if (!$res) {
                  throw new Exception("Tarvikkeen lisäys epäonnistui");
              }
            }
          }

        pg_query($yhteys, "COMMIT");
        $success_message = "Kaikki tiedot tallennettu";
      } catch (Exception $e) {
        pg_query($yhteys, "ROLLBACK");

        $error_message = "Tallennus epäonnistui: $e";
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
                <li><a href="lisaa_tapahtuma.php" class="active">
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
        <?php elseif (isset($success_message)): ?>
            <div id="alert" class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <h2>Lisää tapahtuma</h2>
                        
          <form action="lisaa_tapahtuma.php" method="POST" class="form-container">
            <div class="form-group">
              <label>Sopimus *</label>
              <select name="sopimus_id" required>
                <option value="" disabled selected>Valitse tuntityösopimus</option>
                <?php
                  while ($row = pg_fetch_assoc($contract_list)) {
                          $id = $row['sopimus_id'];
                          $address = htmlspecialchars($row['kohde_osoite']);
                          echo "<option value=\"$id\">$id $address</option>";
                  }
                ?>
              </select>
            </div>

            <div class="form-group">
              <label>Tuntityö *</label>
              <select name="tuntityo_id" required>
                <option value="" disabled selected>Valitse työ</option>
                <?php
                  while ($row = pg_fetch_assoc($worktype_list)) {
                          $id = $row['tuntityo_id'];
                          $name = htmlspecialchars($row['nimi']);
                          echo "<option value=\"$id\">$name</option>";
                  }
                ?>
              </select>

              <input type="number" step="0.5" name="tunnit" min=0.5 placeholder="Tunnit" required>
              <input type="number" step="0.5" name="tuntialennus" min=0 placeholder="Alennus %">
              <input type="hidden" name="tuntityot_json" id="tuntityot_json">
            </div>
            <button type="button" class="btn btn-secondary" onclick="lisaaTyo()">Lisää työ</button>
            
            <div class="info-section">
              <ul id="tuntityolista"></ul>
            </div>

            <div class="form-group">
              <label>Tarvikkeet</label>

                  <select name="tarvike_id">
                    <option value="" disabled selected>Valitse tarvike</option>
                    <?php
                      while ($row = pg_fetch_assoc($supply_list)) {
                              $id = $row['tarvike_id'];
                              $name = htmlspecialchars($row['tarvike_nimi']);
                              echo "<option value=\"$id\">$name</option>";
                      }
                    ?>
                  </select>
                  <input type="number" step="0.1" name="tarvike_maara" min=0.1 placeholder="Määrä">
                  <input type="number" step="0.5" name="tarvike_alennus" min=0 placeholder="Alennus %">
                  <input type="hidden" name="tarvikkeet_json" id="tarvikkeet_json">
            </div>
            <button type="button" class="btn btn-secondary" onclick="lisaaTarvike()">Lisää tarvike</button>
            
            <div class="info-section">
              <ul id="tarvikelista"></ul>
            </div>

            <div class="form-group">
              <label>Päivämäärä *</label>
              <input type="date" name="pvm" required>
            </div>

            <button type="submit" name="tallenna" class="btn btn-primary">Tallenna</button>

          </form>

        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä</p>
        </footer>
    </div>
    <script>
      let tuntityot = [];
      let tarvikkeet = [];

      document.querySelector('form').addEventListener('submit', function() {
          document.getElementById('tuntityot_json').value = JSON.stringify(tuntityot);
          document.getElementById('tarvikkeet_json').value = JSON.stringify(tarvikkeet);
      });

    function paivitaTyoLista() {
        const ul = document.getElementById('tuntityolista');
        ul.innerHTML = '';

        tuntityot.forEach((t, i) => {
            ul.innerHTML += `
                <li>
                    ${t.tuntityo_nimi} | ${t.tunnit} tuntia | Alennus ${t.alennus} %
                    <button type="button" onclick="poistaTyo(${i})">Poista</button>
                </li>
            `;
        });
    }

    function poistaTyo(index) {
        tuntityot.splice(index, 1);
        paivitaTyoLista();
    }

    function lisaaTyo() {
      const tuntityo = document.querySelector('[name="tuntityo_id"]');
      const tuntityo_id = tuntityo.value;
      const tuntityo_nimi = tuntityo.options[tuntityo.selectedIndex].text;
      const tunnit = document.querySelector('[name="tunnit"]').value;
      const alennus = document.querySelector('[name="tuntialennus"]').value || 0;

      if (!tuntityo_id || tunnit <= 0) {
          alert("Virheellinen syöte");
          return;
      }

      const duplikaatti = tuntityot.find((el) => el.tuntityo_id === tuntityo_id);

      if (duplikaatti) {
        if (duplikaatti.alennus != alennus) {
          alert("Tuntityö syötetty jo listaan");
          return;
        } else {
          duplikaatti.tunnit =  String(Number(duplikaatti.tunnit) + Number(tunnit));
          paivitaTyoLista();
          return;
        }
      }

      tuntityot.push({
          tuntityo_id,
          tuntityo_nimi,
          tunnit,
          alennus
      });

      paivitaTyoLista();
    }

      function paivitaTarvikeLista() {
        const ul = document.getElementById('tarvikelista');
        ul.innerHTML = '';

        tarvikkeet.forEach((t, i) => {
            ul.innerHTML += `
                <li>
                    ${t.tarvike_nimi} | Määrä ${t.maara} | Alennus ${t.alennus} %
                    <button onclick="poistaTarvike(${i})">Poista</button>
                </li>
            `;
        });
    }

    function poistaTarvike(index) {
        tarvikkeet.splice(index, 1);
        paivitaTarvikeLista();
    }

    function lisaaTarvike() {
      const tarvike = document.querySelector('[name="tarvike_id"]');
      const tarvike_id = tarvike.value;
      const tarvike_nimi = tarvike.options[tarvike.selectedIndex].text;
      const maara = document.querySelector('[name="tarvike_maara"]').value;
      const alennus = document.querySelector('[name="tarvike_alennus"]').value || 0;

      if (!tarvike_id || maara <= 0) {
          alert("Virheellinen syöte");
          return;
      }

      const duplikaatti = tarvikkeet.find((el) => el.tarvike_id === tarvike_id);

      if (duplikaatti) {
        if (duplikaatti.alennus != alennus) {
          alert("Tarvike sy�tetty jo listaan");
          return;
        } else {
          duplikaatti.maara =  String(Number(duplikaatti.maara) + Number(maara));
          paivitaTarvikeLista();
          return;
        }
      }

      tarvikkeet.push({
          tarvike_id,
          tarvike_nimi,
          maara,
          alennus
      });

      paivitaTarvikeLista();
    }
    </script>
</body>
</html>
