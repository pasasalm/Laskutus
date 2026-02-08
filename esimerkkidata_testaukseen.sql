--Esimerkki dataa, jolla voi testailla laskutusjärjestelmän toiminnallisuuksia.
-- pyydetty LLM:ltä esimerkkidataa joka sopii taulujen luontilauseisiin ja joka sisältää erilaisia tilanteita, kuten useita kohteita yhdelle asiakkaalle, erilaisia sopimustyyppejä, alennuksia, muistutuslaskuja, jne.

-- 1. Yritys (Seppo Tärsky)
INSERT INTO yritys (y_tunnus, tilinumero, nimi, osoite, puh_nro, sahkoposti)
VALUES ('1234567-8', 'FI12 3456 7890 1234 56', 'Tmi Sähkötärsky', 'Sähkökuja 1, 33100 Tampere', '0401234567', 'seppo@sahkotarsky.fi');

-- 2. Asiakkaat
INSERT INTO asiakas (as_nimi, as_osoite, puh_nro, sahkoposti)
VALUES 
('Matti Meikäläinen', 'Kotikatu 5, 33200 Tampere', '0509876543', 'matti@email.fi'),
('Liisa Lukkari', 'Opintie 12, 33500 Tampere', '0451122334', 'liisa@lukkari.net');

-- 3. Työkohteet
INSERT INTO tyokohde (asiakas_id, kohde_osoite)
VALUES 
(1, 'Kotikatu 5, 33200 Tampere'),      -- Matin koti
(1, 'Mökkikuja 10, 34110 Lakiala'),    -- Matin mökki
(2, 'Opintie 12, 33500 Tampere');      -- Liisan koti

-- 4. Tavarantoimittajat
INSERT INTO tavarantoimittaja (toimittaja_nimi, y_tunnus, osoite, puh_nro, sahkoposti)
VALUES 
('Sähkötukku Oy', '9876543-2', 'Tukkutie 4, 00100 Helsinki', '09123456', 'myynti@sahkotukku.fi'),
('Junk Co', '5544332-1', 'Romukatu 9, 00500 Helsinki', '09998877', 'info@junkco.fi');

-- 5. Tarvikkeet (Hinnat ilman ALV)
INSERT INTO tarvikkeet (toimittaja_id, tarvike_nimi, merkki, ostohinta, myyntihinta, yksikko, alv_prosentti, varasto)
VALUES 
(1, 'Pistorasia 2-osainen', 'ABB', 5.50, 12.00, 'kpl', 24.00, 50),
(1, 'Sähköjohto MMJ 3x1.5', 'Prysmian', 0.40, 1.20, 'm', 24.00, 500),
(1, 'Valokatkaisin', 'Schneider', 4.20, 9.50, 'kpl', 24.00, 30),
(2, 'Opaskirja sähköasennuksiin', 'Sähköliitto', 8.00, 10.00, 'kpl', 10.00, 5); -- Eri ALV

-- 6. Tuntityö hinnasto (Hinnat ilman ALV)
-- Tehtävän hinnat (sis. alv 24%): Suunnittelu 55€, Työ 45€, Aputyö 35€
-- Nettohinnat: 55/1.24 = 44.35, 45/1.24 = 36.29, 35/1.24 = 28.23
INSERT INTO tuntityo_hinnasto (nimi, hinta_netto, alv_prosentti)
VALUES 
('suunnittelu', 44.35, 24.00),
('työ', 36.29, 24.00),
('aputyö', 28.23, 24.00);

-- 7. Sopimukset
INSERT INTO sopimus (tyokohde_id, tila, tyyppi, pvm)
VALUES 
(1, 'valmis', 'tuntityö', '2026-01-15'), -- Matin kotiremontti
(2, 'kesken', 'tuntityö', '2026-02-01'), -- Matin mökkityö
(3, 'valmis', 'urakka', '2026-01-10');   -- Liisan urakka

-- Päivitetään urakkasopimuksen hinnat (R4/R5 varten)
UPDATE sopimus SET urakka_tyo_netto = 500.00, urakka_tarvikkeet_netto = 300.00 
WHERE sopimus_id = 3;

-- 8. Työsuoritteet (T2)
INSERT INTO tyo_suorite (sopimus_id, tuntityo_id, maara, pvm, alennusprosentti)
VALUES 
(1, 1, 2.0, '2026-01-15', 0),    -- 2h suunnittelua
(1, 2, 5.0, '2026-01-16', 10.0), -- 5h työtä 10% alennuksella
(2, 2, 4.0, '2026-02-05', 0);    -- 4h työtä mökillä

-- 9. Käytetyt tarvikkeet (T2)
INSERT INTO kaytetyt_tarvikkeet (tarvike_id, sopimus_id, pvm, maara, alennusprosentti)
VALUES 
(1, 1, '2026-01-16', 3, 0),      -- 3 pistorasiaa
(2, 1, '2026-01-16', 15.5, 5.0), -- 15.5m johtoa 5% alennuksella
(4, 1, '2026-01-16', 1, 0);      -- 1 opaskirja

-- 10. Laskut (T3/R2/R3)
-- Alkuperäinen lasku Matille (erääntynyt)
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
VALUES (1, 1001, 0, '2026-01-20', '2026-02-03', '10016');

-- Muistutuslasku Matille (T3 esimerkki)
INSERT INTO lasku (sopimus_id, edellinen_lasku_id, laskun_nro, muistutus_nro, pvm, erapaiva, laskutuslisa, viitenumero)
VALUES (1, 1, 1002, 1, '2026-02-05', '2026-02-19', 5.00, '10029');

-- Urakkalaskut Liisalle (R5 esimerkki: jaettu kahteen osaan)
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
VALUES 
(3, 1003, 0, '2026-01-10', '2026-01-24', '10032'),
(3, 1004, 0, '2026-01-10', '2027-01-01', '10045'); -- Ensi vuoden lasku



-- 1. Uusi asiakas, jolla on useita kohteita (R1 testaus)
INSERT INTO asiakas (as_nimi, as_osoite, puh_nro, sahkoposti)
VALUES ('Antti Asiakas', 'Esimerkkitie 1, 00100 Helsinki', '0400000001', 'antti@testi.fi');

INSERT INTO tyokohde (asiakas_id, kohde_osoite)
VALUES 
(3, 'Esimerkkitie 1, 00100 Helsinki'), -- Antin koti
(3, 'Kesämökkitie 55, 06100 Porvoo'),   -- Antin mökki
(3, 'Sijoitusasunto 4 B, 00200 Helsinki'); -- Antin sijoitusasunto

-- 2. Lisää tarvikkeita varastoon (T2 testaus)
-- Lisätään pieniä määriä, jotta voidaan testata varaston hupenemista
INSERT INTO tarvikkeet (toimittaja_id, tarvike_nimi, merkki, ostohinta, myyntihinta, yksikko, alv_prosentti, varasto)
VALUES 
(1, 'Vikavirtasuojakytkin', 'Ensto', 25.00, 45.00, 'kpl', 24.00, 5),
(1, 'Asennusputki 20mm', 'Pipelife', 0.30, 0.90, 'm', 24.00, 100),
(2, 'Käytetty sähkökaappi', 'Generic', 50.00, 120.00, 'kpl', 24.00, 1);

-- 3. Uusia sopimuksia eri tiloissa
INSERT INTO sopimus (tyokohde_id, tila, tyyppi, pvm)
VALUES 
(4, 'kesken', 'tuntityö', '2026-02-01'), -- Antin kotiremontti (kesken)
(5, 'valmis', 'urakka', '2026-01-05'),   -- Antin mökki (valmis urakka)
(6, 'kesken', 'tuntityö', '2026-02-07'); -- Sijoitusasunto (juuri aloitettu)

-- 4. Työsuoritteita eri päiville (T2: "päivän päätteeksi kirjattavat")
-- Antin kotiremonttiin on tehty useana päivänä töitä
INSERT INTO tyo_suorite (sopimus_id, tuntityo_id, maara, pvm, alennusprosentti)
VALUES 
(4, 1, 4.0, '2026-02-02', 0),    -- Suunnittelu maanantaina
(4, 2, 8.0, '2026-02-03', 0),    -- Täysi työpäivä tiistaina
(4, 2, 8.0, '2026-02-04', 5.0),  -- Keskiviikkona 5% alennus työlle
(4, 3, 2.0, '2026-02-04', 0);    -- Aputyötä keskiviikkona

-- 5. Tarvikekirjauksia (T2)
INSERT INTO kaytetyt_tarvikkeet (tarvike_id, sopimus_id, pvm, maara, alennusprosentti)
VALUES 
(5, 4, '2026-02-03', 1, 0),      -- 1 vikavirtasuoja
(6, 4, '2026-02-03', 20.0, 10.0),-- 20m putkea 10% alennuksella
(2, 4, '2026-02-04', 50.0, 0);   -- 50m johtoa

-- 6. Laskuhistoriaa: Perintään asti mennyt ketju (T3 testaus)
-- Alkuperäinen lasku (laskun_nro 2001)
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
VALUES (5, 2001, 0, '2026-01-05', '2026-01-19', '20019');

-- Ensimmäinen muistutus (laskun_nro 2002)
INSERT INTO lasku (sopimus_id, edellinen_lasku_id, laskun_nro, muistutus_nro, pvm, erapaiva, laskutuslisa, viitenumero)
VALUES (5, 3, 2002, 1, '2026-01-21', '2026-02-04', 5.00, '20022');

-- Toinen muistutus / Karhu (laskun_nro 2003)
INSERT INTO lasku (sopimus_id, edellinen_lasku_id, laskun_nro, muistutus_nro, pvm, erapaiva, laskutuslisa, viivastyskorko, viitenumero)
VALUES (5, 4, 2003, 2, '2026-02-06', '2026-02-20', 10.00, 12.45, '20035');

-- 7. Maksettu lasku (testataan raportteja, joissa näkyy vain maksamattomat tai maksetut)
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, maksu_pvm, viitenumero)
VALUES (3, 3001, 0, '2026-01-01', '2026-01-15', '2026-01-12', '30011');



-- 1. Uusi suuri asiakas (esim. taloyhtiön edustaja tai usean asunnon omistaja)
INSERT INTO asiakas (as_nimi, as_osoite, puh_nro, sahkoposti)
VALUES ('Isännöitsijä Ilkka', 'Hallituskatu 10, 33100 Tampere', '0501112223', 'ilkka@isannointi.fi');

INSERT INTO tyokohde (asiakas_id, kohde_osoite)
VALUES 
(4, 'As Oy Tampereen Torni, Huoneisto A1'),
(4, 'As Oy Tampereen Torni, Huoneisto A2'),
(4, 'As Oy Tampereen Torni, Yleiset tilat');

-- 2. Lisää tarvikkeita (erityisesti tukkupakkauksia)
INSERT INTO tarvikkeet (toimittaja_id, tarvike_nimi, merkki, ostohinta, myyntihinta, yksikko, alv_prosentti, varasto)
VALUES 
(1, 'Kaapelikela MMJ 5x2.5', 'Reka', 150.00, 250.00, 'kpl', 24.00, 10),
(1, 'LED-paneeli 60x60', 'Philips', 35.00, 75.00, 'kpl', 24.00, 40),
(1, 'Sulakeautomaatti 16A', 'GE', 3.50, 8.50, 'kpl', 24.00, 100);

-- 3. Sopimukset: Suuri tuntityöprojekti ja nopeasti valmistunut urakka
INSERT INTO sopimus (tyokohde_id, tila, tyyppi, pvm)
VALUES 
(7, 'kesken', 'tuntityö', '2026-02-01'), -- Huoneisto A1 remontti
(9, 'valmis', 'urakka', '2026-01-20');   -- Yleisten tilojen valaistuspäivitys

-- Päivitetään valaistusurakan hinnat
UPDATE sopimus SET urakka_tyo_netto = 1200.00, urakka_tarvikkeet_netto = 2500.00 
WHERE sopimus_id = 8;

-- 4. Työsuoritteet: Useita työlajeja samana päivänä (R2/R3 testaus)
-- Huoneistossa A1 on tehty tiimityötä 10.2.2026
INSERT INTO tyo_suorite (sopimus_id, tuntityo_id, maara, pvm, alennusprosentti)
VALUES 
(7, 1, 4.0, '2026-02-10', 0),    -- Suunnittelija (4h)
(7, 2, 8.0, '2026-02-10', 0),    -- Sähköasentaja 1 (8h)
(7, 2, 8.0, '2026-02-10', 0),    -- Sähköasentaja 2 (8h)
(7, 3, 6.0, '2026-02-10', 20.0); -- Apupoika (6h) 20% alennuksella

-- 5. Käytetyt tarvikkeet: Suuret määrät (T2)
INSERT INTO kaytetyt_tarvikkeet (tarvike_id, sopimus_id, pvm, maara, alennusprosentti)
VALUES 
(7, 7, '2026-02-10', 2, 10.0),   -- 2 kelaa kaapelia (10% alennus tukkualennuksena)
(8, 7, '2026-02-10', 12, 0),     -- 12 LED-paneelia
(9, 7, '2026-02-10', 24, 5.0);   -- 24 sulaketta

-- 6. Laskut: Heti maksettu urakkalasku
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, maksu_pvm, viitenumero)
VALUES (8, 4001, 0, '2026-01-25', '2026-02-08', '2026-01-26', '40014');

-- 7. Lasku, joka on juuri erääntymässä (testaa "erääntyy tänään" raportteja)
INSERT INTO lasku (sopimus_id, laskun_nro, muistutus_nro, pvm, erapaiva, viitenumero)
VALUES (7, 4002, 0, '2026-02-01', '2026-02-08', '40027'); -- Eräpäivä on tänään (jos tänään on 8.2.2026)