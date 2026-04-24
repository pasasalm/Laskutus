-- Excelistä pystyy tallentamaan noi välilehdet .csv muotoon helposti
-- vaihdetaan pilkut pisteiksi vaikka Notepad++ avulla ennen vientiä
-- viedään tässä järjestyksessä

\set data_dir ./data

\copy yritys(osoite,puh_nro,sahkoposti)
FROM :data_dir/yritys.csv
DELIMITER ';'
CSV HEADER;

\copy tavarantoimittaja(toimittaja_id,toimittaja_nimi,y_tunnus,osoite,puh_nro,sahkoposti)
FROM :data_dir/tavarantoimittaja.csv
DELIMITER ';'
CSV HEADER;

\copy asiakas(as_id,as_nimi,as_osoite,puh_nro,sahkoposti)
FROM :data_dir/asiakas.csv
DELIMITER ';'
CSV HEADER;

\copy tuntityo_hinnasto(tuntityo_id,nimi,hinta_netto,alv_prosentti)
FROM :data_dir/tuntityo_hinnasto.csv
DELIMITER ';'
CSV HEADER;

\copy tarvikkeet(tarvike_id,toimittaja_id,tarvike_nimi,merkki,ostohinta,myyntihinta,yksikko,alv_prosentti,varasto)
FROM :data_dir/tarvikkeet.csv
DELIMITER ';'
CSV HEADER;

\copy tyokohde(tyokohde_id,asiakas_id,kohde_osoite)
FROM :data_dir/tyokohde.csv
DELIMITER ';'
CSV HEADER;

\copy sopimus(sopimus_id,tyokohde_id,tila,tyyppi,pvm,urakka_tyo_netto,urakka_tarvikkeet_netto)
FROM :data_dir/sopimus.csv
DELIMITER ';'
CSV HEADER;

\copy tyo_suorite(tyo_suorite_id,sopimus_id,tuntityo_id,maara,pvm,alennusprosentti)
FROM :data_dir/tyo_suorite.csv
DELIMITER ';'
CSV HEADER;

\copy kaytetyt_tarvikkeet(tarvike_id,sopimus_id,pvm,maara,alennusprosentti)
FROM :data_dir/kaytetyt_tarvikkeet.csv
DELIMITER ';'
CSV HEADER;

\copy lasku(lasku_id,sopimus_id,edellinen_lasku_id,laskun_nro,muistutus_nro,pvm,erapaiva,maksu_pvm,laskutuslisa,viivastyskorko,viitenumero)
FROM :data_dir/lasku.csv
DELIMITER ';'
CSV HEADER;
