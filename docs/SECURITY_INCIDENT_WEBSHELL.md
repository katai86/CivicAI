# Biztonsági incidens: PHP webshell dropperek (tömeges fertőzés)

## Verdikt

Ha **szinte minden PHP fájl** fertőzött → **ne soronként takaríts**.  
**Egyetlen biztonságos út:** tiszta kód a GitHub `main`-ről + összes titok cseréje.

A helyi / GitHub repo **tiszta**. A fertőzés **csak az éles tárhelyen** van.  
**SOHA ne töltsd vissza** a fertőzött szerverfájlokat GitHubra.

---

## Ismert variánsok (mind ugyanaz: RCE)

| POST/REQUEST kulcs | Temp fájl | Megjegyzés |
|--------------------|-----------|------------|
| `\x64a\x74` = `dat` | `.element` | első variáns |
| `\x64ata\x5F\x63h\x75n\x6B` = `data_chunk` | `.component` | hex2bin + XOR 35 |
| `\x69\x74m` = `itm` | `.token` | salt dekódolás |
| `fac` (REQUEST) | `.rec` | hex2bin + XOR 59 |
| `ent` (POST) | `.token` | salt + XOR 45 |

Mindegyik: dekódol → írható temp mappa → `include`/`require` → törli magát → `die`.

---

## HELYREÁLLÍTÁS – tömeges fertőzés (kötelező sorrend)

### A) Azonnal
1. **Állítsd le** a publikus forgalmat, ha tudod (karbantartás / DNS / hosting „pause”).
2. **FTP + cPanel + MySQL jelszó csere** (a támadó valószínűleg már bent van).
3. **Ne** szerkessz 50 fertőzött fájlt kézzel.

### B) Mentés (bizonyíték, 10 perc)
- Csomagold be a fertőzött CivicAI mappát: pl. `CivicAI_infected_YYYYMMDD.zip`
- Töltsd le a gépedre (ne a Git repóba).

### C) Tiszta telepítés
1. Élesen **ne a fertőzött mappát javítsd**, hanem:
   - nevezd át: `CivicAI` → `CivicAI_old_infected`
   - hozz létre új üres `CivicAI`
2. Töltsd fel a **tiszta** kódot:
   - GitHub: https://github.com/katai86/CivicAI (`main`)
   - vagy helyi tiszta klón ZIP-elve
3. Másold vissza **csak**:
   - `config.local.php` / `.env` → **utána jelszavakat cseréld a fájlban is**
   - `uploads/` **képek** (jpg/png/webp) – **NE** másolj `.php`-t az uploads-ból
4. Feltöltésvédelem az éles `uploads/` alá:
   - `deploy/uploads.htaccess` → `uploads/.htaccess`
   - `deploy/uploads.index.php` → `uploads/index.php`
5. Futtasd a migrációkat / ellenőrizd DB kapcsolatot.

### D) Titkok (mind)
- [ ] FTP / cPanel / hosting
- [ ] MySQL user + jelszó
- [ ] Admin / superadmin userek jelszava (DB-ben is)
- [ ] AI API kulcsok (Mistral, OpenAI, Gemini)
- [ ] FMS / egyéb modul kulcsok
- [ ] Session / ADMIN_PASS / tokenek

### E) Adatbázis
- `users`: ismeretlen `admin` / `superadmin` → töröld / tiltsd
- `module_settings`: gyanús URL / script
- Új, idegen táblák

### F) Ellenőrzés
SSH / hosting terminal:

```bash
php tools/purge_webshells.php /path/to/CivicAI
# elvárt: Infected: 0
```

Keresés FTP-ben is: `data_chunk`, `\x64a\x74`, `.component`, `.token`, `.rec`, `.element`

### G) Takarítás
- Töröld: `CivicAI_old_infected` (miután biztosan megy az új)
- Töröld élesről: `check_db.php`, `tools/purge_webshells.php` (használat után), `tools/scan_webshell.php`

---

## Ha mégis sebészi tisztítást akarsz (csak <30% fertőzés)

```bash
php tools/purge_webshells.php /path/to/CivicAI --fix --dry-run
php tools/purge_webshells.php /path/to/CivicAI --fix
```

Backup: `*.malwarebak.*` fájlok.  
**Ha fertőzés ≥ 30% → ezt hagyd, menj a C) tiszta telepítésre.**

---

## Hogyan kerülhetett be?

Tipikus shared hoston:
1. Ellopott / gyenge **FTP vagy cPanel** jelszó  
2. Más, régi projekt webshellje ugyanazon a tárhelyen  
3. Már meglévő backdoor, ami végigfertőzte a `.php` fájlokat  

A CivicAI feltöltő önmagában (kép MIME) ritkán indít ilyen tömeges PHP-injectet – inkább **tárhely / FTP kompromittálás**.

---

## Eszközök a repóban

| Fájl | Cél |
|------|-----|
| `tools/purge_webshells.php` | detektálás + opcionális tisztítás |
| `tools/scan_webshell.php` | korábbi szkenner |
| `deploy/uploads.htaccess` | PHP tiltás uploads alatt |
| `deploy/uploads.index.php` | 403 az uploads gyökérre |

---

## Mit NE

- Ne commitolj fertőzött szerverkódot.
- Ne hagyd bent a `CivicAI_old_infected` mappát weben elérhetően.
- Ne használd a régi FTP jelszót az új deployhoz.
