<?php
// ============================================================================
// TIETOKANTAYHTEYS - TUNI-palvelin
// ============================================================================

$y_tiedot = "dbname= user= password=";

if (!$yhteys = pg_connect($y_tiedot))
    die("Tietokantayhteyden luominen epäonnistui.");

// Aseta UTF-8 merkistökoodaus
pg_set_client_encoding($yhteys, 'UTF8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Turvallinen kysely prepared statementeilla
function executeQuery($query, $params = []) {
    global $yhteys;
    
    if (empty($params)) {
        $result = pg_query($yhteys, $query);
    } else {
        $result = pg_query_params($yhteys, $query, $params);
    }
    
    if (!$result) {
        die("Kysely epäonnistui: " . pg_last_error($yhteys));
    }
    
    return $result;
}

// Hae kaikki rivit
function fetchAll($result) {
    $rows = [];
    while ($row = pg_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

// Hae yksi rivi
function fetchOne($result) {
    return pg_fetch_assoc($result);
}

// Suojaa syöte XSS:ältä
function escapeInput($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Suomalainen valuuttamuotoilu
function formatCurrency($value) {
    return number_format($value, 2, ',', ' ') . ' €';
}
?>
