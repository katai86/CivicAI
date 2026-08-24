# Domain költöztetés: kataiattila.hu/CivicAI → civicai.hu

## Célállapot

| Régi | Új |
|------|-----|
| `https://kataiattila.hu/CivicAI/` | `https://civicai.hu/` |
| Alkonyvtár (`/CivicAI`) | Domain **gyökere** (document root) |

A kód `APP_BASE_URL`-t használ minden linkhez. Ezt kell átírni; a PHP fájlokban nem kell domain-keresés–csere.

**Biztonság:** az új domainre **csak tiszta GitHub `main` kódot** tegyél (webshell után ne a fertőzött régi mappát másold).

---

## 1) Domain és tárhely

1. **DNS** (domain regisztrátornál / Cloudflare):
   - `A` rekord: `civicai.hu` → tárhely IP
   - `A` vagy `CNAME`: `www.civicai.hu` → ugyanoda / `civicai.hu`
2. **Tárhely panel** (cPanel / DirectAdmin / stb.):
   - Add hozzá a `civicai.hu` domaint (addon / main domain)
   - Állítsd be a **document root**-ot (pl. `public_html` vagy `public_html/civicai.hu`)
   - Kapcsold be az **SSL**-t (Let's Encrypt) – `https://`
3. Döntés: **ugyanaz a tárhely** (ajánlott, egyszerűbb) vagy új szerver (akkor DB export/import is kell).

---

## 2) Tiszta kód az új gyökérbe

1. Document rootba töltsd fel a **tiszta** projektet (Git clone / ZIP a GitHub `main`-ről).
2. Struktúra példa:
   ```text
   public_html/          ← civicai.hu ide mutat
     index.php
     config.php
     gov/
     api/
     uploads/
     .env                ← NE a Gitből
     config.local.php    ← NE a Gitből
   ```
3. **Ne** legyen `/CivicAI` alkönyvtár az új domainen, ha a gyökeret akarod.

Másold át a régiből (ha tiszta / átnézted):

- `uploads/` – csak képek (jpg/png/webp), **semmilyen `.php`**
- `deploy/uploads.htaccess` → `uploads/.htaccess`
- `deploy/uploads.index.php` → `uploads/index.php`

---

## 3) Konfig – a legfontosabb változás

`.env` vagy `config.local.php`:

```env
APP_BASE_URL=https://civicai.hu
```

```php
'APP_BASE_URL' => 'https://civicai.hu',
```

**Nincs** `/CivicAI` a végén, ha a site a domain gyökerén fut.

Egyéb ajánlott frissítések:

```env
MAIL_FROM=no-reply@civicai.hu
MAIL_FROM_NAME=CivicAI
```

`config.php`-ben a Nominatim User-Agent contact e-mailjét is érdemes `civicai.hu`-ra cserélni (kód vagy későbbi patch).

DB: ha ugyanaz a MySQL marad, `DB_*` változatlan.  
Ha új adatbázis: exportáld a régit (`mysqldump`), importáld az újba, írd át a `DB_*` értékeket.

---

## 4) Jogosultságok / PHP

- `uploads/` írható a webusernek (pl. 755/775)
- PHP 8.x + PDO MySQL
- Ellenőrzés: `https://civicai.hu/api/health.php` → `"ok": true`

---

## 5) Régi domain átirányítás (kötelező a linkekhez)

A régi URL-ek (e-mail, könyvjelző, Open311) még `kataiattila.hu/CivicAI/...` lehetnek.

**cPanel „Redirects”** vagy a régi `CivicAI/.htaccess`:

```apache
RewriteEngine On
RewriteRule ^(.*)$ https://civicai.hu/$1 [R=301,L]
```

Így pl.:
- `…/CivicAI/gov/index.php` → `https://civicai.hu/gov/index.php`
- `…/CivicAI/case.php?token=…` → `https://civicai.hu/case.php?token=…`

Ha a régi mappa fertőzött volt: átirányítás után **töröld / ürítsd** a régi PHP fájlokat, csak az `.htaccess` redirect maradjon.

---

## 6) Átállás napja (checklist)

- [ ] DNS propagálódott (`civicai.hu` a helyes IP-re megy)
- [ ] SSL zöld (https)
- [ ] `APP_BASE_URL=https://civicai.hu`
- [ ] Login (user + gov + admin) működik
- [ ] Térkép, feltöltés, gov dashboard
- [ ] `api/health.php` OK
- [ ] 301 a régi `/CivicAI` alól
- [ ] Régi fertőzött PHP-k eltávolítva
- [ ] FTP / DB / admin / API kulcsok frissek (webshell után kötelező)

---

## 7) Gyakori hibák

| Tünet | Ok |
|-------|-----|
| CSS/JS 404, törött linkek | `APP_BASE_URL` még a régi, vagy `/CivicAI` van benne feleslegesen |
| Bejelentkezés nem marad meg | Cookie domain / HTTPS keveredés – csak `https://civicai.hu` |
| Képek nem jelennek meg | `uploads/` nincs átmásolva, vagy `.htaccess` túl szigorú |
| DB hiba | Rossz `DB_*` az új tárhelyen |

---

## 8) Opcionális: www → apex

```apache
RewriteEngine On
RewriteCond %{HTTP_HOST} ^www\.civicai\.hu$ [NC]
RewriteRule ^(.*)$ https://civicai.hu/$1 [R=301,L]
```

És `APP_BASE_URL` legyen kanonikus: `https://civicai.hu` (www nélkül).
