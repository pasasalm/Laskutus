-- ============================================================================
-- NÄKYMÄT LASKUTUSJÄRJESTELMÄÄN
-- ============================================================================

-- Pohjaa triggerille. Näyttää urakan kiinteän hinnan eriteltynä työhön ja tarvikkeisiin. R4
CREATE OR REPLACE VIEW urakka_tarjous AS
SELECT
    s.sopimus_id,
    s.tyokohde_id,
    tk.asiakas_id,
    ROUND(COALESCE(SUM(
        CASE WHEN s.tyyppi = 'urakka'
        THEN th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0)
        ELSE 0 END
    ),0), 2) AS tyon_osuus_netto,
    ROUND(COALESCE(SUM(
        kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0)
    ),0), 2) AS tarvikkeet_osuus_netto,
    ROUND(( COALESCE(SUM(
        CASE WHEN s.tyyppi = 'urakka'
        THEN th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0)
        ELSE 0 END
    ),0)
    + COALESCE(SUM(
        kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0)
    ),0)
    ), 2) AS yhteensa_netto
FROM sopimus s
LEFT JOIN tyokohde tk ON tk.tyokohde_id = s.tyokohde_id
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.sopimus_id = s.sopimus_id
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
GROUP BY s.sopimus_id, s.tyokohde_id, tk.asiakas_id;

-- Listaus laskuista joiden erapaiva on mennyt ja maksua ei ole saatu. Tarvitaan muistutuslaskujen automaatiossa. T3
CREATE OR REPLACE VIEW eraantyvat_laskut AS
SELECT
    l.lasku_id,
    l.laskun_nro,
    l.sopimus_id,
    tk.asiakas_id,
    a.as_nimi,
    l.erapaiva,
    (CURRENT_DATE - l.erapaiva) AS paivat_eraantyneet
FROM lasku l
LEFT JOIN sopimus s ON s.sopimus_id = l.sopimus_id
LEFT JOIN tyokohde tk ON tk.tyokohde_id = s.tyokohde_id
LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
WHERE l.erapaiva < CURRENT_DATE
    AND l.maksu_pvm IS NULL
    AND l.muistutus_nro = 0  -- peruslasku eli ei ole lähetetty muistutuslaskua tästä laskusta
    AND NOT EXISTS (
        SELECT 1 FROM lasku l2 WHERE l2.edellinen_lasku_id = l.lasku_id
    );

-- Lasketaan työkohteen X arvioidut kustannukset (tunnit + tarvikkeet) R1
CREATE OR REPLACE VIEW hinta_arvio AS
SELECT
    tk.tyokohde_id,
    tk.kohde_osoite,
    ROUND(COALESCE(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0)),0), 2) AS arvio_tyon_netto,
    ROUND(COALESCE(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0)),0), 2) AS arvio_tarvikkeet_netto,
    ROUND(( COALESCE(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0)),0)
    + COALESCE(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0)),0)
    ), 2) AS arvio_yhteensa_netto
FROM tyokohde tk
LEFT JOIN sopimus s ON s.tyokohde_id = tk.tyokohde_id
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.sopimus_id = s.sopimus_id
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
GROUP BY tk.tyokohde_id, tk.kohde_osoite;

-- Näyttää summattavat tunnit ja tarvikkeet per sopimus_id, voidaan hyödyntää laskussa.
CREATE OR REPLACE VIEW tyosuorite_yhteenveto AS
SELECT
    ts.sopimus_id,
    ts.tuntityo_id,
    th.nimi AS tuntityo_nimi,
    ROUND(SUM(ts.maara), 2) AS total_maara,
    ROUND(SUM( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) ), 2) AS total_netto
FROM tyo_suorite ts
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
GROUP BY ts.sopimus_id, ts.tuntityo_id, th.nimi;

-- voidaan näyttää sopimuksen tarvikkeet, voidaan hyödyntää laskussa.
CREATE OR REPLACE VIEW kaytetyt_tarvikkeet_yhteenveto AS
SELECT
    kt.sopimus_id,
    kt.tarvike_id,
    tr.tarvike_nimi,
    tr.yksikko,
    ROUND(SUM(kt.maara), 2) AS total_maara,
    ROUND(SUM( kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) ), 2) AS total_netto
FROM kaytetyt_tarvikkeet kt
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
GROUP BY kt.sopimus_id, kt.tarvike_id, tr.tarvike_nimi, tr.yksikko;

--jos halutaan näyttää myyntihinnan ja alv valmiiksi lasketut hinnat
CREATE OR REPLACE VIEW tarvike_hinnasto AS
SELECT
    t.tarvike_id,
    t.tarvike_nimi,
    t.myyntihinta AS netto_hinta,
    t.alv_prosentti,
    (t.myyntihinta * (1 + COALESCE(t.alv_prosentti,0)/100.0))::numeric(12,2) AS hinta_brutto
FROM tarvikkeet t;

-- Yhdistää sopimuksen, tyo_suoritten ja kaytetyt_tarvikkeet ja laskee rivikohtaiset alennukset, alv ja loppusumman. R2 ja R3
CREATE OR REPLACE VIEW tuntityo_lasku AS
SELECT
    l.lasku_id,
    l.laskun_nro,
    s.sopimus_id,
    ts.tyo_suorite_id,
    th.nimi AS tuntityo,
    ROUND(ts.maara, 2) AS maara,
    ROUND(th.hinta_netto, 2) AS yksikkohinta_netto,
    ROUND(COALESCE(ts.alennusprosentti,0), 2) AS alennusprosentti,
    ROUND(( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) ), 2) AS hinta_netto,
    ROUND(( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) * (th.alv_prosentti/100.0) ), 2) AS alv_summa,
    ROUND(( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) * (1 + th.alv_prosentti/100.0) ), 2) AS hinta_brutto
FROM lasku l
LEFT JOIN sopimus s ON s.sopimus_id = l.sopimus_id
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
WHERE s.tyyppi = 'tuntityö';

-- Raportti toteutuneesta urakasta. R5
CREATE OR REPLACE VIEW urakka_lasku AS
SELECT
    l.lasku_id,
    l.laskun_nro,
    s.sopimus_id,
    ROUND(COALESCE(SUM( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) ),0), 2) AS tyon_netto,
    ROUND(COALESCE(SUM( kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) ),0), 2) AS tarvikkeet_netto,
    ROUND(( COALESCE(SUM( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) ),0)
    + COALESCE(SUM( kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) ),0)
    ), 2) AS yhteensa_netto
FROM lasku l
LEFT JOIN sopimus s ON s.sopimus_id = l.sopimus_id
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.sopimus_id = s.sopimus_id
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
WHERE s.tyyppi = 'urakka'
GROUP BY l.lasku_id, l.laskun_nro, s.sopimus_id;

-- T4, Luodaan triggeri asiakkaan luotettavuudelle.
CREATE OR REPLACE VIEW asiakkaan_luotettavuus AS
SELECT
    a.asiakas_id,
    a.as_nimi,
    COUNT(CASE WHEN l.erapaiva < CURRENT_DATE AND l.maksu_pvm IS NULL THEN 1 END) AS maara_eraantyneita,
    COUNT(CASE WHEN l.muistutus_nro >= 2 AND l.pvm >= (CURRENT_DATE - INTERVAL '2 years') THEN 1 END) AS maara_karhuja_2v,
    COUNT(CASE WHEN l.maksu_pvm IS NOT NULL THEN 1 END) AS maara_maksettuja
FROM asiakas a
LEFT JOIN tyokohde tk ON tk.asiakas_id = a.asiakas_id
LEFT JOIN sopimus s ON s.tyokohde_id = tk.tyokohde_id
LEFT JOIN lasku l ON l.sopimus_id = s.sopimus_id
GROUP BY a.asiakas_id, a.as_nimi;

-- Raporttia R6 varten. Nähdään mihin kohteisiin tietyn toimittajan tavaroita on asennettu.
CREATE OR REPLACE VIEW toimitetut_tavarat_per_toimittaja AS
SELECT
    tt.toimittaja_id,
    tr.tarvike_id,
    tr.tarvike_nimi,
    tt.toimittaja_nimi,
    tk.tyokohde_id,
    tk.kohde_osoite,
    ROUND(SUM(kt.maara), 2) AS maara_toimitettu
FROM tarvikkeet tr
LEFT JOIN tavarantoimittaja tt ON tt.toimittaja_id = tr.toimittaja_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.tarvike_id = tr.tarvike_id
LEFT JOIN sopimus s ON s.sopimus_id = kt.sopimus_id
LEFT JOIN tyokohde tk ON tk.tyokohde_id = s.tyokohde_id
GROUP BY tt.toimittaja_id, tr.tarvike_id, tr.tarvike_nimi, tt.toimittaja_nimi, tk.tyokohde_id, tk.kohde_osoite;

-- Laskee jokaiselle sopimukselle / kohteelle työnsumman ja tarvikkeiden summan nettona
CREATE OR REPLACE VIEW sopimus_summat AS
SELECT
    s.sopimus_id,
    s.tyokohde_id,
    ROUND(COALESCE(SUM( th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) ),0), 2) AS tyon_summa_netto,
    ROUND(COALESCE(SUM( kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) ),0), 2) AS tarvikkeet_summa_netto
FROM sopimus s
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.sopimus_id = s.sopimus_id
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
GROUP BY s.sopimus_id, s.tyokohde_id;

-- Käyttää sopimus_summat, lisää siihen alv, asiakkaan tiedot ja yrityksen tiedot, yhdistää laskun kaikki tiedot
CREATE OR REPLACE VIEW lasku_data AS
SELECT
    l.lasku_id,
    l.laskun_nro,
    l.pvm AS lasku_pvm,
    l.erapaiva,
    l.maksu_pvm,
    s.sopimus_id,
    a.as_nimi,
    tk.kohde_osoite,
    ROUND(ss.tyon_summa_netto, 2) AS tyon_summa_netto,
    ROUND(ss.tarvikkeet_summa_netto, 2) AS tarvikkeet_summa_netto,
    ROUND((ss.tyon_summa_netto + ss.tarvikkeet_summa_netto), 2) AS yhteensa_netto,
    -- yhteensä ALV eriteltynä laskussa voidaan laskea tarvittaessa erikseen per rivit; tässä yksinkertainen summa
    ROUND((COALESCE(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) * (th.alv_prosentti/100.0)),0)
    + COALESCE(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) * (tr.alv_prosentti/100.0)),0)
    ), 2) AS yhteensa_alv,
    ROUND(( (ss.tyon_summa_netto + ss.tarvikkeet_summa_netto)
    + (COALESCE(SUM(th.hinta_netto * ts.maara * (1 - COALESCE(ts.alennusprosentti,0)/100.0) * (th.alv_prosentti/100.0)),0)
        + COALESCE(SUM(kt.maara * tr.myyntihinta * (1 - COALESCE(kt.alennusprosentti,0)/100.0) * (tr.alv_prosentti/100.0)),0)
        )
    ), 2) AS yhteensa_brutto
FROM lasku l
LEFT JOIN sopimus s ON s.sopimus_id = l.sopimus_id
LEFT JOIN tyokohde tk ON tk.tyokohde_id = s.tyokohde_id
LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
LEFT JOIN sopimus_summat ss ON ss.sopimus_id = s.sopimus_id
LEFT JOIN tyo_suorite ts ON ts.sopimus_id = s.sopimus_id
LEFT JOIN tuntityo_hinnasto th ON th.tuntityo_id = ts.tuntityo_id
LEFT JOIN kaytetyt_tarvikkeet kt ON kt.sopimus_id = s.sopimus_id
LEFT JOIN tarvikkeet tr ON tr.tarvike_id = kt.tarvike_id
GROUP BY l.lasku_id, l.laskun_nro, l.pvm, l.erapaiva, l.maksu_pvm, s.sopimus_id, a.as_nimi, tk.kohde_osoite, ss.tyon_summa_netto, ss.tarvikkeet_summa_netto;

-- laskee maksuhäiriöt triggeriä vasten
CREATE OR REPLACE VIEW asiakkaan_status AS
SELECT
    a.asiakas_id,
    a.as_nimi,
    CASE WHEN EXISTS (
        SELECT 1 FROM tyokohde tk2
        JOIN sopimus s2 ON s2.tyokohde_id = tk2.tyokohde_id
        JOIN lasku l2 ON l2.sopimus_id = s2.sopimus_id
        WHERE tk2.asiakas_id = a.asiakas_id AND l2.erapaiva < CURRENT_DATE AND l2.maksu_pvm IS NULL
    ) THEN 'on myohassa' ELSE 'ok' END AS status_overdue,
    CASE WHEN EXISTS (
        SELECT 1 FROM tyokohde tk3
        JOIN sopimus s3 ON s3.tyokohde_id = tk3.tyokohde_id
        JOIN lasku l3 ON l3.sopimus_id = s3.sopimus_id
        WHERE tk3.asiakas_id = a.asiakas_id AND l3.muistutus_nro >= 2 AND l3.pvm >= (CURRENT_DATE - INTERVAL '2 years')
    ) THEN true ELSE false END AS had_karhu_last_2y
FROM asiakas a;

COMMENT ON VIEW urakka_tarjous IS 'Pohjaa triggerille T4. Näyttää urakan kiinteän hinnan eriteltynä työhön ja tarvikkeisiin.';
COMMENT ON VIEW eraantyvat_laskut IS 'Listaus laskuista joiden erapaiva on mennyt ja maksua ei ole saatu. Tarvitaan muistutuslaskujen automaatiossa (T3).';
COMMENT ON VIEW hinta_arvio IS 'Lasketaan työkohteen arvioidut kustannukset (tunnit + tarvikkeet). raporttia R1 varten.';
COMMENT ON VIEW tyosuorite_yhteenveto IS 'Näyttää summattavat tunnit per sopimus_id, voidaan hyödyntää laskussa.';
COMMENT ON VIEW kaytetyt_tarvikkeet_yhteenveto IS 'Näyttää sopimuksen tarvikkeet yhteenvetona, voidaan hyödyntää laskussa.';
COMMENT ON VIEW tarvike_hinnasto IS 'Näyttää tarvikkeiden myyntihinnan ja alv:n valmiiksi laskettuina.';
COMMENT ON VIEW tuntityo_lasku IS 'Yhdistää sopimuksen tyo_suoritteet laskuun. Laskee rivikohtaiset alennukset, alv ja loppusumman. R2 ja R3 varten.';
COMMENT ON VIEW urakka_lasku IS 'Raportti toteutuneesta urakasta. R5 varten.';
COMMENT ON VIEW asiakkaan_luotettavuus IS 'Asiakkaan luotettavuustiedot triggeriä T4 varten.';
COMMENT ON VIEW toimitetut_tavarat_per_toimittaja IS 'Raporttia R6 varten. Näyttää mihin kohteisiin tietyn toimittajan tavaroita on asennettu.';
COMMENT ON VIEW sopimus_summat IS 'Laskee jokaiselle sopimukselle työnsumman ja tarvikkeiden summan nettona.';
COMMENT ON VIEW lasku_data IS 'Käyttää sopimus_summat-näkymää, lisää siihen alv:n, asiakkaan tiedot ja yrityksen tiedot.';
COMMENT ON VIEW asiakkaan_status IS 'Laskee asiakkaan maksuhäiriöt triggeriä vasten.';






-- Lisätyt näkymät ensimmäisen vaiheen jälkene

-- Odottavat sopimukset, joista yrityksen edustaja voi tehdä hyväksynnän ennen laskutusta.
CREATE OR REPLACE VIEW odottavat_sopimukset AS
SELECT
    s.sopimus_id,
    s.tyokohde_id,
    s.tyyppi,
    s.tila,
    s.pvm,
    tk.asiakas_id,
    a.as_nimi,
    a.as_osoite,
    a.puh_nro,
    a.sahkoposti,
    tk.kohde_osoite,
    ROUND(COALESCE(s.urakka_tyo_netto, ut.tyon_osuus_netto, ss.tyon_summa_netto, 0), 2) AS tyon_osuus_netto,
    ROUND(COALESCE(s.urakka_tarvikkeet_netto, ut.tarvikkeet_osuus_netto, ss.tarvikkeet_summa_netto, 0), 2) AS tarvikkeet_osuus_netto,
    ROUND((
        COALESCE(s.urakka_tyo_netto, ut.tyon_osuus_netto, ss.tyon_summa_netto, 0)
        + COALESCE(s.urakka_tarvikkeet_netto, ut.tarvikkeet_osuus_netto, ss.tarvikkeet_summa_netto, 0)
    ), 2) AS yhteensa_netto
FROM sopimus s
LEFT JOIN tyokohde tk ON tk.tyokohde_id = s.tyokohde_id
LEFT JOIN asiakas a ON a.asiakas_id = tk.asiakas_id
LEFT JOIN urakka_tarjous ut ON ut.sopimus_id = s.sopimus_id
LEFT JOIN sopimus_summat ss ON ss.sopimus_id = s.sopimus_id
WHERE s.tila = 'odottaa_hyväksyntää';

COMMENT ON VIEW odottavat_sopimukset IS 'Listaa hyväksyntää odottavat sopimukset asiakkaan, työkohteen ja hintojen kanssa.';