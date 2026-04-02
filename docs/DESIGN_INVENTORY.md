# CivicAI – Design inventár (A1)

## A1.1 Belépési oldalak listája

| Oldal | Fájl | Megjegyzés |
|-------|------|------------|
| Térkép (főoldal) | index.php | Mobilon átirányít mobile/index.php |
| Ügykövetés (token) | case.php | Nyilvános, token alapú |
| Ranglista | leaderboard.php | |
| Bejelentkezés | user/login.php | |
| Regisztráció | user/register.php | |
| Saját ügyeim | user/my.php | Bejelentkezés kötelező |
| Beállítások | user/settings.php | Bejelentkezés kötelező |
| Profil (nyilvános) | user/profile.php | id= query |
| Barátok | user/friends.php | Bejelentkezés kötelező |
| Bejelentés létrehozás | user/report.php | Bejelentkezés kötelező |
| Saját ügy részlet | user/report.php?id= | Bejelentkezés kötelező |
| Admin | admin/index.php | AdminLTE, bejelentkezés kötelező |
| Gov (közigazgatás) | gov/index.php | AdminLTE, gov/admin/superadmin |
| Mobil webapp | mobile/index.php | Csak Mobilekit |

---

## A1.2 Desktop vs mobil megjelenés

| Oldal | Desktop layout | Mobil layout |
|-------|----------------|--------------|
| index.php | sima (style.css) + topbar + térkép | átirányítás → mobile/index.php |
| case.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| leaderboard.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/login.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/register.php | sima + topbar | Nincs mobil shell |
| user/settings.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/my.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/profile.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/friends.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| user/report.php | sima + topbar | Mobilekit (inc_mobile_header/footer) |
| admin/index.php | AdminLTE (dashboard/dist) | AdminLTE (reszponzív) |
| gov/index.php | AdminLTE (dashboard/dist) | AdminLTE (reszponzív) |
| mobile/index.php | — | Mobilekit (teljes shell) |

**Összegzés:**
- **Desktop:** „sima” = `assets/style.css` + közös desktop topbar (brand, téma, nyelv, navigáció). Admin/Gov = AdminLTE.
- **Mobil:** Mobilekit = `Mobilekit_v2-9-1/HTML/assets/css/style.css` + `assets/mobilekit_civicai.css`; ahol van mobil shell: `inc_mobile_header.php` + `inc_mobile_footer.php` (appHeader, appCapsule, appBottomMenu).
