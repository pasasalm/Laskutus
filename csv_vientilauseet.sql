-- Excelistä pystyy tallentamaan noi välilehdet .csv muotoon helposti
-- vaihdetaan pilkut pisteiksi vaikka Notepad++ avulla ennen vientiä

\copy asiakas(as_id,as_nimi,as_osoite,puh_nro,sahkoposti)
FROM '/home/nfpasa/tiko_mapape/asiakas.csv'
DELIMITER ';'
CSV HEADER;

\copy lasku(lasku_id,sopimus_id,edellinen_lasku_id,laskun_nro,muistutus_nro,pvm,erapaiva,maksu_pvm,laskutuslisa,viivastyskorko,viitenumero)
FROM '/home/nfpasa/tiko_mapape/lasku.csv'
DELIMITER ';'
CSV HEADER;

\copy tyokohde(tyokohde_id,asiakas_id,kohde_osoite)
FROM '/home/nfpasa/tiko_mapape/tyokohde.csv'
DELIMITER ';'
CSV HEADER;

\copy tyo_suorite(tyo_suorite_id,sopimus_id,tuntityo_id,maara,pvm,alennusprosentti)
FROM '/home/nfpasa/tiko_mapape/tyo_suorite.csv'
DELIMITER ';'
CSV HEADER;

yms...