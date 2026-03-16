-- Excelistä pystyy tallentamaan noi välilehdet .csv muotoon helposti
-- vaihdetaan pilkut pisteiksi vaikka Notepad++ avulla ennen vientiä
-- viedään tässä järjestyksessä

\copy yritys(osoite,puh_nro,sahkoposti)
FROM '/home/nfpasa/tiko_mapape/yritys.csv'
DELIMITER ';'
CSV HEADER;

\copy tavarantoimittaja(toimittaja_id,toimittaja_nimi,y_tunnus,osoite,puh_nro,sahkoposti)
FROM '/home/nfpasa/tiko_mapape/tavarantoimittaja.csv'
DELIMITER ';'
CSV HEADER;

\copy asiakas(as_id,as_nimi,as_osoite,puh_nro,sahkoposti)
FROM '/home/nfpasa/tiko_mapape/asiakas.csv'
DELIMITER ';'
CSV HEADER;

\copy tuntityo_hinnasto(tuntityo_id,nimi,hinta_netto,alv_prosentti)
FROM '/home/nfpasa/tiko_mapape/tuntityo_hinnasto.csv'
DELIMITER ';'
CSV HEADER;

\copy tarvikkeet(tarvike_id,toimittaja_id,tarvike_nimi,merkki,ostohinta,myyntihinta,yksikko,alv_prosentti,varasto)
FROM '/home/nfpasa/tiko_mapape/tarvikkeet.csv'
DELIMITER ';'
CSV HEADER;

\copy tyokohde(tyokohde_id,asiakas_id,kohde_osoite)
FROM '/home/nfpasa/tiko_mapape/tyokohde.csv'
DELIMITER ';'
CSV HEADER;

\copy sopimus(sopimus_id,tyokohde_id,tila,tyyppi,pvm,urakka_tyo_netto,urakka_tarvikkeet_netto)
FROM '/home/nfpasa/tiko_mapape/sopimus.csv'
DELIMITER ';'
CSV HEADER;

\copy tyo_suorite(tyo_suorite_id,sopimus_id,tuntityo_id,maara,pvm,alennusprosentti)
FROM '/home/nfpasa/tiko_mapape/tyo_suorite.csv'
DELIMITER ';'
CSV HEADER;

\copy kaytetyt_tarvikkeet(tarvike_id,sopimus_id,pvm,maara,alennusprosentti)
FROM '/home/nfpasa/tiko_mapape/kaytetyt_tarvikkeet.csv'
DELIMITER ';'
CSV HEADER;

\copy lasku(lasku_id,sopimus_id,edellinen_lasku_id,laskun_nro,muistutus_nro,pvm,erapaiva,maksu_pvm,laskutuslisa,viivastyskorko,viitenumero)
FROM '/home/nfpasa/tiko_mapape/lasku.csv'
DELIMITER ';'
CSV HEADER;
