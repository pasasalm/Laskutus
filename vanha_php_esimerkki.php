<?php
// aloitetaan sessio, jotta voidaan käyttää sessio muuttujia
session_start();

// luodaan tietokantayhteys ja ilmoitetaan mahdollisesta virheestä
$y_tiedot = "dbname="; 
if (!$yhteys = pg_connect($y_tiedot)) {
	die("Tietokantayhteyden luominen epäonnistui."); }

if (isset($_POST['Lähetä']))
{
	// transaktio, että voidaan perua
	pg_query('BEGIN')
		or die('Ei onnistuttu aloittamaan tapahtumaa:' . pg_last_error());	
	
    // suojataan merkkijonot
    $lahet_summa = floatval($_POST['lahet_raha_summa']);
    $veloit_tili = intval($_POST['veloitettava']);
    $vastot_tili = intval($_POST['vastaanottava']);

	// tilin omistajien nimet ja tilien rahojen summat tarvitaan, kutsutaan sql:llä tietokannasta, laitetaan myös rivikohtaiset lukot
	$veloit_tili_tulos = pg_query_params("SELECT omistaja, summa FROM TILIT WHERE tili_nro = $1 FOR UPDATE", array($veloit_tili));
	$vastot_tili_tulos = pg_query_params("SELECT omistaja FROM TILIT WHERE tili_nro = $1 FOR UPDATE", array($vastot_tili));
	
	if ($lahet_summa <= 0.00) {
		pg_query('ROLLBACK');
		$viesti = 'Summa ei voi olla 0 tai negatiivinen.';
	}
	// tarkistetaan, että tilit löytyy
	elseif (pg_num_rows($veloit_tili_tulos) > 0 && pg_num_rows($vastot_tili_tulos) > 0)
	{
		// kutsutaan tiedot sql:llä haetuista
		$rivi_veloit = pg_fetch_row($veloit_tili_tulos);
		$veloit_tili_omist = $rivi_veloit[0];
		$veloit_tili_summa = $rivi_veloit[1];
		
		$rivi_vastot = pg_fetch_row($vastot_tili_tulos);
		$vastot_tili_omist = $rivi_vastot[0];
		
		// veloitettavan tilin saldotarkistus, että on tarpeeksi rahaa maksamiseen
		if ($veloit_tili_summa >= $lahet_summa)
		{
			// pitää lisätä/vähentää oikeisiin tileihin uudet summat, vaarallinen kohta sql ja muutoksen takia
			$vahennys = pg_query_params("UPDATE TILIT SET summa = summa - $2 WHERE  tili_nro = $1", array($veloit_tili, $lahet_summa));
			$lisays = pg_query_params("UPDATE TILIT SET summa = summa + $2 WHERE tili_nro = $1", array($vastot_tili, $lahet_summa));
			
			// laitetaan arvot sessiomuuttujiin ainoastaan, jos on onnistunut
			if ($vahennys && $lisays && (pg_affected_rows($vahennys) > 0) && (pg_affected_rows($lisays) > 0)) 
			{
				pg_query('COMMIT'); // transaktio kiinni, kun on onnistunut muutos tietokantaan
				$_SESSION['lahet_summa'] = $lahet_summa;
				$_SESSION['veloit_tili_omist'] = $veloit_tili_omist;
				$_SESSION['vastot_tili_omist'] = $vastot_tili_omist;
				header('Location: tilisiirto_2.php'); // koska onnistui ohjaus toiselle lomakkeelle, jossa tulosteet
				exit(); // varmistetaan koodin suorituksen lopetus
			}
			else {
				pg_query('ROLLBACK'); // perutaan transaktio virheiden välttämiseksi
				$viesti = 'Tilien muuttaminen epäonnistui.';}
		}
		else {
			pg_query('ROLLBACK');
			$viesti = 'Tilillä ei tarpeeksi katetta.';}
	}	
	else {
		pg_query('ROLLBACK');
		$viesti = 'Tiliä/tilejä ei löytynyt - tarkista annetut tiedot.'; }
}

// suljetaan tietokantayhteys
pg_close($yhteys);
?>

<html>
<head>
	<title>Pankki - Banken</title>
</head>
<body>
	<form action="tilisiirto_1.php" method="post">
	<label>Rahan siirto<br></br></label>
	
	<?php if (isset($viesti)) echo '<p style="color:black">'.$viesti.'</p>'; ?>
	
	<table border="0" cellspacing="0" cellpadding="3">
	    <tr>
			<td>Siirrettävä summa:</td>
				<td><input type="number" name="lahet_raha_summa" step="0.01" /></td>
	    </tr>
		<tr>
			<td>Veloitettava tilinumero:</td>
					<td><input type="text" name="veloitettava" /></td>
		</tr>
		<tr>
			<td>Vastaanottava tilinumero:</td>
				<td><input type="text" name="vastaanottava" /></td>
		</tr>
	</table>
	<br>
	
	<input type="submit" name ="Lähetä" value="Lähetä"/>
	</form>
</body>
</html>