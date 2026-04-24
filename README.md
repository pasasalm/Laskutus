# Laskutusjärjestelmä - Tmi Sähkötärsky

Lisenssi: katso [LICENSE](LICENSE).

Sähköalan laskutusjärjestelmä, jonka tarkoitus on helpottaa tuntitöiden ja urakoiden hallintaa, laskutusta ja raportointia.
Järjestelmä tukee koko ketjua työkohteen lisäyksestä laskujen muodostamiseen asti.

## Ohjelman tarkoitus

Ohjelmaa käytetään seuraaviin tarpeisiin:

- asiakkaiden työkohteiden hallinta
- päiväkohtaisten tuntitöiden ja tarvikkeiden kirjaaminen
- hinta-arvioiden muodostaminen
- sopimustarjousten käsittely (tuntityö/urakka)
- laskujen muodostaminen ja esikatselu
- muistutuslaskujen teko erääntyneistä laskuista
- raportointi (mm. toimittajakohtaiset toimitukset)

Lisäksi ohjelma laskee kotitalousvähennykseen kelpoisen osuuden sekä tukee alennusten käsittelyä laskentasääntöjen mukaisesti.

## Tech stack

- Backend: PHP (proseduraalinen)
- Tietokanta: PostgreSQL
- Tietokantayhteys: PHP:n pg_* API
- Frontend: HTML, CSS, JavaScript (vanilla)
- Ikonit: Font Awesome CDN
- Alusta: tie-tkannat.it.tuni.fi (TUNI)

## Käyttöön vaadittavat asiat

- eduVPN-yhteys päällä (TUNI-verkkoon pääsy)
- selain (Chrome, Edge, Firefox tms.)
- pääsy sovelluksen URL-osoitteeseen

Sovellus on saatavilla osoitteessa:

https://tie-tkannat.it.tuni.fi/~nfpasa/tiko_mapape/index.php

Ilman eduVPN-yhteyttä sivu ei välttämättä avaudu.

## Käynnistys ja valmistelu

### Vaihtoehto A: Käytä valmista palvelinversiota

1. Kytke eduVPN päälle.
2. Avaa selaimella URL: https://tie-tkannat.it.tuni.fi/~nfpasa/tiko_mapape/index.php
3. Aloita käyttö etusivulta.

### Vaihtoehto B: Aja omassa ympäristössä (kehityskäyttö)


1. git clone https://github.com/pasasalm/Laskutus.git 
2. Asenna PHP ja PostgreSQL. 
3. Luo tietokanta taulujen luontilauseilla tiedostosta SQL/harjoitustyo_taulujen_luontilauseet.sql. 
4. Luo näkymät tiedostosta SQL/nakymat_korjattu.sql. 
5. Lisää testidata tiedostosta  
SQL/csv_vientilauseet.sql joka hakee data kansiot csv tiedostot tietokantaan. 
Aja - psql -h localhost -U käyttäjätunnus -d tietokannan_nimi -f SQL/csv_vientilauseet.sql 
Mikäli haluat viedä datan suoraan tietokantaan INSERT lauseilla, aja: 
SQL/esimerkkidata_testaukseen.sql. 
6. Päivitä tietokantayhteys tiedostoon config.php oman ympäristön mukaiseksi. 
7. Käynnistä PHP-palvelin tai julkaise sovellus web-palvelimelle. 

## Käyttöohjeet

Tyypillinen käyttötapaus:

1. Lisää työkohde
	- Sivu: lisaa_tyokohde.php
	- Luo asiakkaalle uusi kohde (osoite)

2. Lisää tapahtuma
	- Sivu: lisaa_tapahtuma.php
	- Kirjaa sopimukselle tehdyt työtunnit ja käytetyt tarvikkeet päivämäärällä

3. Tee hinta-arvio
	- Sivu: hinta_arvio.php
	- Hae olemassa oleva arvio tai laske uusi arvio

4. Käsittele sopimustarjous
	- Sivu: sopimus.php
	- Muodosta urakka- tai tuntityösopimus ja vie se tarvittaessa odottamaan hyväksyntää

5. Luo lasku
	- Sivu: lasku.php
	- Valitse työkohde ja sopimus, päivitä mahdolliset alennukset, muodosta lasku

6. Tarkastele raportteja ja odottavia sopimuksia
	- Sivu: nayta_tiedot.php
	- Hyväksy odottavat sopimukset
	- Muodosta toimittajakohtainen raportti (esim. R6)

7. Luo muistutuslasku tarvittaessa
	- Sivu: luo_muistutuslasku.php
	- Muodosta muistutus erääntyneestä, maksamattomasta laskusta

## Tietoturva

- SQL-injektiosuojaus pääosin parametrisoiduilla kyselyillä (executeQuery + pg_query_params)
- XSS-suojaus tulosteissa escapeInput-funktiolla
- Syötteiden validointi useissa kohdissa (esim. ctype_digit, FILTER_VALIDATE_INT, tyyppimuunnokset)
- Transaktiot kriittisissä tallennuksissa (BEGIN/COMMIT/ROLLBACK)

## Tekijät

 - [pasasalm](https://github.com/pasasalm)
 - [petralpp](https://github.com/petralpp)
 - [lautakasakohu](https://github.com/lautakasakohu)
