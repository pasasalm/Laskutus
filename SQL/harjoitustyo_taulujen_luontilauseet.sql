-- ============================================================================
-- TIETOKANTAOHJELMOINTI 2026 - HARJOITUSTYÖ
-- Laskutusjärjestelmä
-- ============================================================================

-- ============================================================================
-- 1. YRITYS
-- ============================================================================
CREATE TABLE IF NOT EXISTS yritys (
    yritys_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    y_tunnus VARCHAR(25) NOT NULL UNIQUE,
    tilinumero VARCHAR(50),
    nimi VARCHAR(100) NOT NULL,
    osoite VARCHAR(150),
    puh_nro VARCHAR(25) NOT NULL,
    sahkoposti VARCHAR(100) CHECK (sahkoposti LIKE '%@%')
);

COMMENT ON TABLE yritys IS 'Yrityksen tiedot (käytännössä Seppo Tärskyn toiminimi). Sisältää laskutuksessa tarvittavat tiedot kuten y-tunnuksen ja tilinumeron, jotka vaaditaan laskun luontia varten';
COMMENT ON COLUMN yritys.yritys_id IS 'Yrityksen yksilöivä tunniste (identity).';
COMMENT ON COLUMN yritys.y_tunnus IS 'Yrityksen y-tunnus (uniikki), voi kuitenkin muuttua eikä siksi voi olla taulun id arvo';
COMMENT ON COLUMN yritys.tilinumero IS 'Yrityksen pankkitilinumero (esim. IBAN) laskutusta varten.';
COMMENT ON COLUMN yritys.nimi IS 'Yrityksen virallinen nimi.';
COMMENT ON COLUMN yritys.osoite IS 'Yrityksen postiosoite.';
COMMENT ON COLUMN yritys.puh_nro IS 'Yrityksen puhelinnumero VARCHAR-muodossa (säilyttää alkunollat ja kansainväliset muodot kuten +358).';
COMMENT ON COLUMN yritys.sahkoposti IS 'Yrityksen sähköpostiosoite. Tulee sisältää @ merkki.';


-- ============================================================================
-- 2. ASIAKAS
-- ============================================================================
CREATE TABLE IF NOT EXISTS asiakas (
    asiakas_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    as_nimi VARCHAR(100) NOT NULL,
    as_osoite VARCHAR(150) NOT NULL,
    puh_nro VARCHAR(25) NOT NULL,
    sahkoposti VARCHAR(100) CHECK (sahkoposti LIKE '%@%')
);

COMMENT ON TABLE asiakas IS 'Asiakkaiden perustiedot. Yhdellä asiakkaalla voi olla useita työkohteita (esim. koti, mökki). Taulu sisältää laskutuksessa tarvittavat yhteystiedot kuten osoitteen, puhelinnumeron ja sähköpostin. Taulu sisältää yksityishenkilöasiakkaita, ei yritysasiakkaita.';
COMMENT ON COLUMN asiakas.asiakas_id IS 'Asiakkaan yksilöivä tunniste (identity).';
COMMENT ON COLUMN asiakas.as_nimi IS 'Asiakkaan nimi (henkilö).';
COMMENT ON COLUMN asiakas.as_osoite IS 'Asiakkaan laskutusosoite (voi poiketa työkohteen osoitteesta).';
COMMENT ON COLUMN asiakas.puh_nro IS 'Asiakkaan puhelinnumero VARCHAR-muodossa.';
COMMENT ON COLUMN asiakas.sahkoposti IS 'Asiakkaan sähköpostiosoite. Tulee sisältää @ merkki.';

CREATE INDEX idx_asiakas_nimi ON asiakas(as_nimi); -- Usein haetaan asiakkaan nimellä, joten indeksi nopeuttaa näitä hakuja.


-- ============================================================================
-- 3. TYÖKOHDE
-- ============================================================================
CREATE TABLE IF NOT EXISTS tyokohde (
    tyokohde_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    asiakas_id INT NOT NULL REFERENCES asiakas(asiakas_id) ON DELETE CASCADE,
    kohde_osoite VARCHAR(150) NOT NULL
);

COMMENT ON TABLE tyokohde IS 'Asiakkaan työkohteet (esim. koti, kesämökki, isovanhempien asunto). Laskulla on eriteltävä työkohde.';
COMMENT ON COLUMN tyokohde.tyokohde_id IS 'Työkohteen yksilöivä tunniste (identity).';
COMMENT ON COLUMN tyokohde.asiakas_id IS 'Viite asiakkaaseen, jolle työkohde kuuluu.';
COMMENT ON COLUMN tyokohde.kohde_osoite IS 'Työkohteen täydellinen osoite (eritellään laskulla).';

CREATE INDEX idx_tyokohde_asiakas ON tyokohde(asiakas_id); -- Usein haetaan työkohteita asiakkaan perusteella, joten indeksi nopeuttaa näitä hakuja.


-- ============================================================================
-- 4. TAVARANTOIMITTAJA
-- ============================================================================
CREATE TABLE IF NOT EXISTS tavarantoimittaja (
    toimittaja_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    toimittaja_nimi VARCHAR(100) NOT NULL,
    y_tunnus VARCHAR(25) NOT NULL,
    osoite VARCHAR(150),
    puh_nro VARCHAR(25),
    sahkoposti VARCHAR(100) CHECK (sahkoposti LIKE '%@%')
);

COMMENT ON TABLE tavarantoimittaja IS 'Tarvikkeiden toimittajien yhteystiedot.';
COMMENT ON COLUMN tavarantoimittaja.toimittaja_id IS 'Toimittajan yksilöivä tunniste (identity).';
COMMENT ON COLUMN tavarantoimittaja.toimittaja_nimi IS 'Toimittajan nimi.';
COMMENT ON COLUMN tavarantoimittaja.y_tunnus IS 'Toimittajan y-tunnus.';
COMMENT ON COLUMN tavarantoimittaja.osoite IS 'Toimittajan postiosoite.';
COMMENT ON COLUMN tavarantoimittaja.puh_nro IS 'Toimittajan puhelinnumero.';
COMMENT ON COLUMN tavarantoimittaja.sahkoposti IS 'Toimittajan sähköpostiosoite. Tulee sisältää @ merkki.';


-- ============================================================================
-- 5. TARVIKKEET
-- ============================================================================
CREATE TABLE IF NOT EXISTS tarvikkeet (
    tarvike_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    toimittaja_id INT NOT NULL REFERENCES tavarantoimittaja(toimittaja_id),
    tarvike_nimi VARCHAR(100) NOT NULL,
    merkki VARCHAR(50),
    ostohinta DECIMAL(10,2) NOT NULL CHECK (ostohinta >= 0),
    myyntihinta DECIMAL(10,2) NOT NULL CHECK (myyntihinta >= 0),
    yksikko VARCHAR(10) CHECK (yksikko IN ('kpl', 'm')), -- kpl = kappale, m = metri, mahdollista lisätä muita yksikköjä tarpeen mukaan
    alv_prosentti DECIMAL(4,2) DEFAULT 24.00 CHECK (alv_prosentti >= 0 AND alv_prosentti <= 100),
    varasto DECIMAL(10,2) DEFAULT 0 CHECK (varasto >= 0)
);

COMMENT ON TABLE tarvikkeet IS 'Tarvikerekisteri: toimittaja, osto- ja myyntihinnat, yksikkö, varastosaldo ja ALV-prosentti.';
COMMENT ON COLUMN tarvikkeet.tarvike_id IS 'Tarvikkeen yksilöivä tunniste (identity).';
COMMENT ON COLUMN tarvikkeet.toimittaja_id IS 'Viite tarvikkeen tavarantoimittajaan.';
COMMENT ON COLUMN tarvikkeet.tarvike_nimi IS 'Tarvikkeen nimi (esim. "Pistorasia ABB", "Sähköjohto MMJ 3x1.5").';
COMMENT ON COLUMN tarvikkeet.merkki IS 'Tarvikkeen merkki/valmistaja (esim. "ABB", "Ensto").';
COMMENT ON COLUMN tarvikkeet.ostohinta IS 'Tarvikkeen sisäänostohinta ilman ALV (€).';
COMMENT ON COLUMN tarvikkeet.myyntihinta IS 'Tarvikkeen myyntihinta (ovh) ilman ALV (€). Voidaan johtaa ostohinnasta, ostohinta + alv.';
COMMENT ON COLUMN tarvikkeet.yksikko IS 'Tarvikkeen yksikkö: "kpl" (kappale, esim. pistorasia) tai "m" (metri, esim. sähköjohto).';
COMMENT ON COLUMN tarvikkeet.alv_prosentti IS 'Tarvikkeen ALV-prosentti (oletus 24.00; esim. opaskirjalla 10.00).';
COMMENT ON COLUMN tarvikkeet.varasto IS 'Varastosaldo (kpl tai metriä yksiköstä riippuen). DECIMAL, koska johtoa voi olla 10.5 metriä.';

CREATE INDEX idx_tarvikkeet_toimittaja ON tarvikkeet(toimittaja_id); -- Usein haetaan tarvikkeita toimittajan perusteella, joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_tarvikkeet_nimi ON tarvikkeet(tarvike_nimi); -- Usein haetaan tarvikkeita nimen perusteella, joten indeksi nopeuttaa näitä hakuja.


-- ============================================================================
-- 6. TUNTITYÖ HINNASTO
-- ============================================================================
CREATE TABLE IF NOT EXISTS tuntityo_hinnasto (
    tuntityo_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nimi VARCHAR(50) NOT NULL UNIQUE,
    hinta_netto DECIMAL(10,2) NOT NULL CHECK (hinta_netto >= 0),
    alv_prosentti DECIMAL(4,2) DEFAULT 24.00 CHECK (alv_prosentti >= 0 AND alv_prosentti <= 100)
);

COMMENT ON TABLE tuntityo_hinnasto IS 'Tuntityön hinnoittelu eri työlajille (esim. suunnittelu, työ, aputyö). Hinnat tallennetaan nettohintana (ilman ALV).';
COMMENT ON COLUMN tuntityo_hinnasto.tuntityo_id IS 'Hinnastorivin yksilöivä tunniste (identity).';
COMMENT ON COLUMN tuntityo_hinnasto.nimi IS 'Työlaji (esim. "suunnittelu", "työ", "aputyö"). Uniikki.';
COMMENT ON COLUMN tuntityo_hinnasto.hinta_netto IS 'Tuntihinta ilman ALV (€/h). Bruttohinta voidaan johtaa ALV:n avulla.';
COMMENT ON COLUMN tuntityo_hinnasto.alv_prosentti IS 'Työlajin ALV-prosentti (oletus 24.00).';


-- ============================================================================
-- 7. SOPIMUS
-- ============================================================================
CREATE TABLE IF NOT EXISTS sopimus (
    sopimus_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tyokohde_id INT NOT NULL REFERENCES tyokohde(tyokohde_id) ON DELETE CASCADE,
    tila VARCHAR(25) DEFAULT 'odottaa_hyväksyntää' CHECK (tila IN ('odottaa_hyväksyntää','kesken','valmis')),
    tyyppi VARCHAR(25) NOT NULL CHECK (tyyppi IN ('tuntityö', 'urakka')),
    pvm DATE NOT NULL DEFAULT CURRENT_DATE,
    urakka_tyo_netto DECIMAL(10,2) CHECK (urakka_tyo_netto >= 0),
    urakka_tarvikkeet_netto DECIMAL(10,2) CHECK (urakka_tarvikkeet_netto >= 0)
);

COMMENT ON TABLE sopimus IS 'Työkohteeseen liittyvä sopimus/työkokonaisuus. Tyyppi: tuntityö tai urakka. Tila: odottaa_hyväksyntää, kesken (työ käynnissä) tai valmis (laskutusvalmis/päätetty).';
COMMENT ON COLUMN sopimus.sopimus_id IS 'Sopimuksen yksilöivä tunniste (identity).';
COMMENT ON COLUMN sopimus.tyokohde_id IS 'Viite työkohteeseen, johon sopimus liittyy. Työkohteen kautta saadaan asiakas.';
COMMENT ON COLUMN sopimus.tila IS 'Sopimuksen tila: "odottaa_hyväksyntää" (yrityksen käsiteltävä), "kesken" (työ käynnissä) tai "valmis" (laskutusvalmis/päätetty).';
COMMENT ON COLUMN sopimus.tyyppi IS 'Sopimustyyppi: "tuntityö" (tuntiperusteinen laskutus) tai "urakka" (kiinteähintainen urakkasopimus).';
COMMENT ON COLUMN sopimus.pvm IS 'Sopimuksen luonti- tai aloituspäivämäärä.';
COMMENT ON COLUMN sopimus.urakka_tyo_netto IS 'Urakan työn osuus ilman ALV (€). Käytetään vain kun tyyppi = "urakka". Tarvitaan kotitalousvähennyksen laskentaan (R4).';
COMMENT ON COLUMN sopimus.urakka_tarvikkeet_netto IS 'Urakan tarvikkeiden osuus ilman ALV (€). Käytetään vain kun tyyppi = "urakka" (R4).';

CREATE INDEX idx_sopimus_tyokohde ON sopimus(tyokohde_id); -- Usein haetaan sopimuksia työkohteen perusteella, joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_sopimus_tila ON sopimus(tila); -- Usein haetaan sopimuksia tilan perusteella, joten indeksi nopeuttaa näitä hakuja.

-- Ensimmäisen vaiheen jälkeen lisätty sopimus tauluun uusi tila "odottaa_hyväksyntää"


-- ============================================================================
-- 8. LASKU
-- ============================================================================
CREATE TABLE IF NOT EXISTS lasku (
    lasku_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sopimus_id INT NOT NULL REFERENCES sopimus(sopimus_id) ON DELETE CASCADE,
    edellinen_lasku_id INT REFERENCES lasku(lasku_id),
    laskun_nro INT UNIQUE NOT NULL,
    muistutus_nro INT DEFAULT 0 CHECK (muistutus_nro >= 0),
    pvm DATE NOT NULL DEFAULT CURRENT_DATE,
    erapaiva DATE NOT NULL,
    maksu_pvm DATE,
    laskutuslisa DECIMAL(10,2) DEFAULT 0 CHECK (laskutuslisa >= 0), -- Laskutuslisä euroina (esim. 5 €/lasku muistutus/karhu -tilanteissa).
    viivastyskorko DECIMAL(10,2) DEFAULT 0 CHECK (viivastyskorko >= 0),
    viitenumero VARCHAR(50),
    CONSTRAINT check_erapaiva CHECK (erapaiva >= pvm),
    CONSTRAINT check_maksu_pvm CHECK (maksu_pvm IS NULL OR maksu_pvm >= pvm)
);

COMMENT ON TABLE lasku IS 'Laskut sopimukselle. Tukee muistutus- ja karhulaskuja linkittämällä edelliseen laskuun ja ylläpitämällä muistutusnumeroa.';
COMMENT ON COLUMN lasku.lasku_id IS 'Laskun yksilöivä tunniste (identity).';
COMMENT ON COLUMN lasku.sopimus_id IS 'Viite sopimukseen, jota lasku koskee.';
COMMENT ON COLUMN lasku.edellinen_lasku_id IS 'Viite edelliseen laskuun ketjussa (muistutus/karhu). NULL = alkuperäinen peruslasku.';
COMMENT ON COLUMN lasku.laskun_nro IS 'Laskun juokseva numero (yrityksen laskunumerointi), uniikki koko järjestelmässä.';
COMMENT ON COLUMN lasku.muistutus_nro IS 'Kuinka mones lasku samasta asiasta: 0=peruslasku, 1=muistutuslasku, 2=karhulasku (ja siitä eteenpäin).';
COMMENT ON COLUMN lasku.pvm IS 'Laskun päiväys (luontipäivä, oletuksena CURRENT_DATE).';
COMMENT ON COLUMN lasku.erapaiva IS 'Laskun eräpäivä (ei voi olla ennen laskun päiväystä).';
COMMENT ON COLUMN lasku.maksu_pvm IS 'Maksupäivä, jos maksettu; muuten NULL.';
COMMENT ON COLUMN lasku.laskutuslisa IS 'Laskutuslisä euroina (esim. 5 €/lasku muistutus/karhu -tilanteissa).';
COMMENT ON COLUMN lasku.viivastyskorko IS 'Viivästyskorko euroina (esim. 16% vuosikorko laskettuna; voidaan tallentaa tai johtaa).';
COMMENT ON COLUMN lasku.viitenumero IS 'Maksun viitenumero (esim. kotimainen viite tai RF-viite).';

CREATE INDEX idx_lasku_sopimus ON lasku(sopimus_id); -- Usein haetaan laskuja sopimuksen perusteella, joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_lasku_erapaiva ON lasku(erapaiva); -- Usein haetaan laskuja eräpäivän perusteella (esim. erääntyneet laskut), joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_lasku_maksu_pvm ON lasku(maksu_pvm); -- Usein haetaan laskuja maksupäivän perusteella (esim. maksetut laskut), joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_lasku_muistutus_nro ON lasku(muistutus_nro); -- Usein haetaan laskuja muistutusnumeron perusteella (esim. kaikki muistutuslaskut), joten indeksi nopeuttaa näitä hakuja.


-- ============================================================================
-- 9. KÄYTETYT TARVIKKEET
-- ============================================================================
CREATE TABLE IF NOT EXISTS kaytetyt_tarvikkeet (
    tarvike_id INT NOT NULL REFERENCES tarvikkeet(tarvike_id),
    sopimus_id INT NOT NULL REFERENCES sopimus(sopimus_id) ON DELETE CASCADE,
    pvm DATE NOT NULL,
    maara DECIMAL(10,2) NOT NULL CHECK (maara > 0),
    alennusprosentti DECIMAL(5,2) DEFAULT 0 CHECK (alennusprosentti >= 0 AND alennusprosentti <= 100),
    PRIMARY KEY (tarvike_id, sopimus_id, pvm)
);

COMMENT ON TABLE kaytetyt_tarvikkeet IS 'Sopimuksella käytetyt tarvikkeet päiväkohtaisesti (T2). Sisältää määrän ja mahdollisen alennusprosentin (kohdistuu nettohintaan).';
COMMENT ON COLUMN kaytetyt_tarvikkeet.tarvike_id IS 'Viite käytettyyn tarvikkeeseen.';
COMMENT ON COLUMN kaytetyt_tarvikkeet.sopimus_id IS 'Viite sopimukseen, jossa tarviketta käytettiin.';
COMMENT ON COLUMN kaytetyt_tarvikkeet.pvm IS 'Päivä, jolloin tarviketta kirjattiin käytetyksi (päivän päätteeksi, T2).';
COMMENT ON COLUMN kaytetyt_tarvikkeet.maara IS 'Käytetty määrä (voi olla desimaali, esim. 2.5 m sähköjohtoa).';
COMMENT ON COLUMN kaytetyt_tarvikkeet.alennusprosentti IS 'Alennusprosentti tarvikkeelle (0-100). Kohdistuu nettohintaan (ilman ALV).';

CREATE INDEX idx_kaytetyt_tarvikkeet_sopimus ON kaytetyt_tarvikkeet(sopimus_id); -- Usein haetaan käytettyjä tarvikkeita sopimuksen perusteella, joten indeksi nopeuttaa näitä hakuja.


-- ============================================================================
-- 10. TYÖ SUORITE
-- ============================================================================
CREATE TABLE IF NOT EXISTS tyo_suorite (
    tyo_suorite_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sopimus_id INT NOT NULL REFERENCES sopimus(sopimus_id) ON DELETE CASCADE,
    tuntityo_id INT NOT NULL REFERENCES tuntityo_hinnasto(tuntityo_id),
    maara DECIMAL(10,2) NOT NULL CHECK (maara > 0),
    pvm DATE NOT NULL,
    alennusprosentti DECIMAL(5,2) DEFAULT 0 CHECK (alennusprosentti >= 0 AND alennusprosentti <= 100)
);

COMMENT ON TABLE tyo_suorite IS 'Sopimukselle tehdyt tuntityöt päiväkohtaisesti (T2). Viittaa hinnastoriviin ja sisältää määrän (tunnit) ja alennuksen.';
COMMENT ON COLUMN tyo_suorite.tyo_suorite_id IS 'Työsuoritteen yksilöivä tunniste (identity).';
COMMENT ON COLUMN tyo_suorite.sopimus_id IS 'Viite sopimukseen, jossa työ tehtiin.';
COMMENT ON COLUMN tyo_suorite.tuntityo_id IS 'Viite tuntityöhinnastoon (määrittää työlajin ja nettohinnan).';
COMMENT ON COLUMN tyo_suorite.maara IS 'Työmäärä tunteina (voi olla desimaali, esim. 1.5 h).';
COMMENT ON COLUMN tyo_suorite.pvm IS 'Päivä, jolloin tuntityö kirjattiin (T2).';
COMMENT ON COLUMN tyo_suorite.alennusprosentti IS 'Alennusprosentti tuntityölle (0-100). Kohdistuu nettohintaan (ilman ALV).';

CREATE INDEX idx_tyo_suorite_sopimus ON tyo_suorite(sopimus_id); -- Usein haetaan työsuorituksia sopimuksen perusteella, joten indeksi nopeuttaa näitä hakuja.
CREATE INDEX idx_tyo_suorite_pvm ON tyo_suorite(pvm); -- Usein haetaan työsuorituksia päivämäärän perusteella, joten indeksi nopeuttaa näitä hakuja.


