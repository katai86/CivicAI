# MILESTONE 4 – User flows és terméklogika véglegesítése

## Megértés és cél

Minden fontos user flow trigger → validáció → tárolás → státusz/értesítés → jogosultság → edge case → visszajelzés. Ahol a jelenlegi logika összeér vagy ütközik, kiemeltem.

---

## 1. Vendég bejelentés

- **Trigger:** Térképen pont kijelölés vagy „Bejelentés” gomb → modál kitöltés (kategória, leírás, opcionális cím, anonim, értesítés nem/igen, GDPR).
- **Validáció:** Kategória engedett listából; leírás kötelező; lat/lng kötelező; terület (Orosháza bbox); ha értesítés: email kötelező; ha nem anonim: név; GDPR consent ha személyes adat.
- **Tárolás:** reports INSERT (user_id NULL, reporter_* opcionális, ip_hash, user_agent).
- **Státusz:** new.
- **Értesítés:** notify_token generálás ha kérték; email küldés jelenleg MVP szinten (opcionális).
- **Jogosultság:** Nincs session; rate limit IP hash alapján.
- **Edge:** Duplikátum 50 m; rate limit 5/10 perc, 20/nap; regisztráció a beküldéskor opcionális (create_account).
- **Visszajelzés:** JSON ok + id, vagy 409 duplikátum, 429 rate limit, 400 validáció.

**Összeérés:** Nincs; vendég flow tiszta.

---

## 2. Regisztrált user bejelentés

- **Trigger:** Ugyanaz, de session user_id van.
- **Validáció:** + civiluser/communityuser nem jogosult normál kategóriára (403).
- **Tárolás:** user_id kitöltve; reporter_* session/profilból lehet.
- **Státusz / XP:** new; XP és badge (report_create, first report, stb.).
- **Edge:** civiluser/communityuser 403 „Nincs jogosultság bejelentéshez”.

**Összeérés:** A 403 üzenet egyértelmű; a frontendnek lehet disablolni a bejelentés gombot ezeknek a role-oknak (opcionális UX).

---

## 3. Anonim bejelentés

- **Trigger:** Vendég bejelentés, reporter_is_anonymous = 1 (alap).
- **Validáció:** Ha nem kér értesítést és nem regisztrál, név nem kötelező.
- **Tárolás:** reporter_name NULL vagy üres, reporter_is_anonymous = 1.
- **Edge:** Értesítés kérés anonimként: email kell, GDPR consent kell.

**Összeérés:** Nincs.

---

## 4. Értesítést kérő user

- **Trigger:** notify_enabled = 1, reporter_email kitöltve (vendég) vagy profilból (belépett).
- **Validáció:** Email format; GDPR consent.
- **Tárolás:** notify_token egyedi, notify_enabled = 1; reporter_email tárolva.
- **Státuszváltozás:** Később report_set_status / gov státuszváltás → email (ha implementált).
- **Edge:** notify_unsubscribe tokennel le lehet mondani.

**Összeérés:** Email küldés kódja lehet hiányos (cron/queue); a tárolás és token rendben.

---

## 5. Civil esemény létrehozó (civiluser)

- **Trigger:** civil_event_create.php POST (title, description, start_date, end_date, lat, lng, address).
- **Validáció:** require_user(); role civil vagy civiluser vagy admin/superadmin; kötelező mezők.
- **Tárolás:** civil_events INSERT. Ha a tábla nincs: 503 és üzenet.
- **Jogosultság:** Csak civil/civiluser/admin/superadmin.

**Összeérés:** Nincs; civil_event_create és report_create (civil_event kategória) konzisztens.

---

## 6. Facility-t kezelő (communityuser)

- **Trigger:** facility_save.php POST (name, service_type, lat, lng, address, phone, email, hours_json, replacement_json).
- **Validáció:** require_user(); role communityuser vagy admin/superadmin; név, lat, lng.
- **Tárolás:** facilities UPSERT (user_id alapján egy sor/user). Ha facilities tábla nincs: 503.

**Összeérés:** Nincs.

---

## 7. Hatósági ügykezelő (govuser)

- **Trigger:** gov/index.php betöltés; státuszváltás POST (action=set_status, id, status, note).
- **Validáció:** Belépett; role govuser vagy admin/superadmin. Csak az authority_users-hoz rendelt hatóságok ügyei látszanak.
- **Tárolás:** reports.status UPDATE; report_status_log INSERT.
- **Értesítés:** Opcionális email a bejelentőnek (kódban van alap).
- **Edge:** authority_users tábla nincs → üres lista (nem 500).

**Összeérés:** Nincs.

---

## 8. Admin moderátor

- **Trigger:** admin/index.php; api/admin_reports, admin_set_status, admin_users, admin_authorities, admin_layers.
- **Validáció:** require_admin() (admin_logged_in vagy user_role admin/superadmin).
- **Státuszváltozás:** Tetszőleges report; user role/tiltás; authority CRUD; layer/point CRUD.
- **Edge:** authority_contacts/authority_users hiánya → GET try/catch, POST 503.

**Összeérés:** Két belépési pálya (config vs users) dokumentálva; egyesítés későbbi döntés.

---

## 9. Social / like / friend / leaderboard user

- **Like:** report_like.php POST report_id → report_likes INSERT (user_id session).
- **Friend:** friend_request.php (send/accept/decline); friends_list.php.
- **Leaderboard:** api/leaderboard.php, leaderboard.php oldal.
- **Validáció:** require_user() ahol kell; duplikált like tiltva (uniq constraint).
- **Visszajelzés:** JSON ok vagy hiba.

**Összeérés:** Nincs; a flow egyszerű.

---

## 10. Profil nyilvánosságot kezelő user

- **Trigger:** user/settings.php POST profile_public, egyéb mezők.
- **Tárolás:** users UPDATE (profile_public, first_name, last_name, stb.).
- **Hatás:** Nyilvános profil (user/profile.php?uid=…) profile_public értékétől függ.

**Összeérés:** Nincs.

---

## Összefoglaló ütközési pontok

- **Nincs kritikus ütközés.** A dokumentált „összeérés” inkább konzisztencia: civiluser/communityuser 403, hiányzó táblák 503 vagy üres lista, két admin auth pálya. Ezeket a M1–M3 és M9 prioritizációban kezeljük.
