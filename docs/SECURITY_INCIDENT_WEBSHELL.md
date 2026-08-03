# Biztonsági incidens: PHP webshell / backdoor (`dat` dropper)

## Mi ez?

A támadók ilyen (vagy hasonló) kódot illesztettek be PHP fájlokba:

```php
if (array_key_exists("\x64a\x74", $_POST) ...) { /* dekódol + ír .element + include */ }
```

`\x64a\x74` = `dat`. A POST-ban érkező kódolt payloadot kiírják egy írható temp mappába (`.element`), majd `include`-dal futtatják → **távoli kódfuttatás (RCE)**.

## Fontos

- A **helyi Git / GitHub repo tiszta** (nincs benne ez a minta).
- A fertőzés az **éles szerveren** (pl. kataiattila.hu) van – FTP/cPanel fájlokban.
- **Ne commitolj** fertőzött szerverfájlokat a GitHubra.

---

## Azonnali lépések (sorrendben)

### 1) Zárd le / mentsd a helyzetet
1. Ha lehet: ideiglenes **karbantartási mód** / oldal levétele.
2. **Ne** jelentkezz be a fertőzött adminnal ugyanarról a gépről jelszómentéssel.
3. Jegyezd fel: mikor vetted észre, mely fájlokban láttad.

### 2) Keress rá a szerveren (FTP / fájlkezelő / SSH)

Keresés a CivicAI mappában ezekre:

- `\x64a\x74`
- `.element`
- `abcdefghijklmnopqrstuvwxyz0123456789` + `file_put_contents`
- `eval(base64_decode`
- gyanús új `.php` fájlok az `uploads/` alatt

Vagy töltsd fel egyszer: `tools/scan_webshell.php`, futtasd SSH-n:

```bash
php tools/scan_webshell.php /home/.../CivicAI
```

Majd **töröld** a scan scriptet a szerverről.

### 3) Tiszta kód visszaállítása (ajánlott)
1. Mentés: fertőzött fájlok másolata külön mappába (bizonyíték).
2. **Teljes újratelepítés Gitből** (tiszta `main`), **kivéve**:
   - `config.local.php` / `.env` (de jelszavakat cseréld!)
   - `uploads/` tartalma (képek) – de töröld belőle a `.php` fájlokat
   - adatbázis (külön ellenőrizd, lásd lent)
3. Másold fel az új védelmeket: `uploads/.htaccess`, `uploads/index.php`.

### 4) Titkok cseréje (kötelező)
Cseréld az összeset, amit a szerver ismert:

- [ ] Admin / superadmin jelszavak
- [ ] MySQL `DB_USER` / `DB_PASS`
- [ ] `ADMIN_PASS` / session secret / API tokenek
- [ ] Mistral / OpenAI / Gemini / FMS API kulcsok
- [ ] FTP / cPanel / hosting jelszó
- [ ] GitHub tokenek, ha a szerveren voltak

### 5) Adatbázis ellenőrzés
- `users` tábla: ismeretlen admin / superadmin
- `module_settings`: gyanús URL-ek, script-ek
- Új, ismeretlen táblák

### 6) Feltöltések
- `uploads/` alatt **ne legyen** `.php`, `.phtml`, `.phar`
- Maradjon: `uploads/.htaccess` (PHP tiltás)

### 7) Újraindítás után
- Futtasd újra a szkennert
- Teszteld: login, bejelentés, gov dashboard
- Figyeld a access logot: `POST ... dat=` gyanús

---

## Lehetséges belépési pontok (gyakori shared hoston)

1. Gyenge / kiszivárgott **FTP / cPanel** jelszó  
2. Régi / más projekt webshell a tárhelyen (szomszédos mappa)  
3. Írható könyvtár + sebezhető plugin / más PHP app  
4. Feltöltés, ha valaha PHP-t is elfogadott a szerver  
5. `check_db.php` vagy más diagnosztika bent maradt élesen (töröld!)

A CivicAI feltöltők alapból csak képet engednek – a dropper tipikusan **már meglévő PHP fájlba** lett beillesztve (FTP vagy meglévő backdoor).

---

## Mit NE tegyél

- Ne „javítsd” csak a látható sort, ha más fájlok is fertőzöttek – **tiszta deploy**.
- Ne hagyd a `scan_webshell.php` / `check_db.php` fájlt élesen.
- Ne pusholj fertőzött szerverkódot a GitHubra.

---

## Kapcsolódó fájlok a repóban

- `tools/scan_webshell.php` – szkenner
- `uploads/.htaccess` – PHP futtatás tiltása
- `uploads/index.php` – könyvtárvédelem
