<?php
session_start();
include 'config.php';
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
        <header>
            <div class="header-content">
                <h1>Tmi Sähkötärsky</h1>
                <p>Laskutusjärjestelmä</p>
            </div>
        </header>

        <nav class="navbar">
            <ul>
                <li><a href="index.php" class="active"> <!--lisättin ikonit-->
                <i class="fa-solid fa-house"></i>Etusivu</a></li>
                <li><a href="lisaa_tyokohde.php">
                <i class="fa-solid fa-building"></i>Lisää työkohde</a></li>
                <li><a href="file_name2.php">
                <i class="fa-solid fa-hammer"></i>Lisää tapahtuma</a></li>
                <li><a href="hinta_arvio.php">
                <i class="fa-solid fa-calculator"></i>Hinta-arvio</a></li>
                <li><a href="file_name3.php">
                <i class="fa-solid fa-file-invoice"></i>Luo lasku</a></li>
                <li><a href="file_name4.php">
                <i class="fa-solid fa-database"></i>Näytä tiedot</a></li>
            </ul>
        </nav>

        <main class="content">
                        
            <div class="dashboard">
                <div class="card">
                    <h3>Järjestelmä</h3>
                    <p>Tämä järjestelmä mahdollistaa sähkötöiden laskutuksen hallinnon. Voit:</p>
                    <ul>
                        <li>Lisätä uusia työkohteita asiakkaille</li>
                        <li>Kirjata tehtyjä töitä ja käytettyjä tarvikkeita</li>
                        <li>Luoda hintatarjouksia</li>
                        <li>Muodostaa laskuja</li>
                    </ul>
                </div>

                <div class="card">
                    <h3>Perustoiminnot</h3>
                    <ul>
                        <li><strong>Lisää työkohde (T1):</strong> Asiakkaalle uusi työkohde</li>
                        <li><strong>Lisää tapahtuma (T2):</strong> Tuntityöt ja tarvikkeet</li>
                        <li><strong>Hinta-arvio (R1):</strong> Työkohteen arvioidut kustannukset</li>
                        <li><strong>Lasku (R2):</strong> Tuntityölasku tarvittavine erittelyineen</li>
                    </ul>
                </div>

                <div class="card">
                    <h3>Pika-ohjeet</h3>
                    <ol>
                        <li>Siirry "Lisää työkohde" -sivulle ja luo uusi kohde asiakkaalle</li>
                        <li>Valitse "Lisää tapahtuma" -sivulta työkohde ja kirjaa tehdyt työt</li>
                        <li>Luo hintatarvio "Hinta-arvio" -sivulla</li>
                        <li>Muodosta lasku "Luo lasku" -sivulla</li>
                    </ol>
                </div>
            </div>

            <section class="stats">
                <h3>Järjestelmän tilastot</h3>
                <div class="stats-grid">
                    <?php
                    // Get statistics
                    $result = executeQuery("SELECT COUNT(*) as count FROM asiakas");
                    $row = fetchOne($result);
                    echo "<div class='stat-box'><h4>Asiakkaita</h4><p class='stat-number'>" . $row['count'] . "</p></div>";

                    $result = executeQuery("SELECT COUNT(*) as count FROM tyokohde");
                    $row = fetchOne($result);
                    echo "<div class='stat-box'><h4>Työkohteita</h4><p class='stat-number'>" . $row['count'] . "</p></div>";

                    $result = executeQuery("SELECT COUNT(*) as count FROM sopimus");
                    $row = fetchOne($result);
                    echo "<div class='stat-box'><h4>Sopimuksia</h4><p class='stat-number'>" . $row['count'] . "</p></div>";

                    $result = executeQuery("SELECT COUNT(*) as count FROM lasku");
                    $row = fetchOne($result);
                    echo "<div class='stat-box'><h4>Laskuja</h4><p class='stat-number'>" . $row['count'] . "</p></div>";
                    ?>
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; 2026 Tmi Sähkötärsky - Laskutusjärjestelmä | PostgreSQL-tietokanta</p>
        </footer>
    </div>
</body>
</html>
