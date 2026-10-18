# GestionCabinet — SaaS de gestion de cabinet dentaire

Plateforme multi-cabinets en **PHP 8.2 (XAMPP) + PostgreSQL hébergé sur Supabase**, sans framework ni dépendance externe.

| Module | Contenu |
|---|---|
| **Agenda** | Vue semaine, filtre par praticien, statuts (en attente, confirmé, terminé, absent, annulé), détection des conflits |
| **Patients** | Fiche complète, allergies en alerte, antécédents (dentiste/admin uniquement), historique des soins par dent (FDI) |
| **Facturation** | Factures numérotées, lignes depuis le catalogue d’actes, paiements partiels, reste dû, impression / PDF |
| **Tableau de bord** | RDV du jour, demandes en ligne à confirmer, encaissements sur 6 mois, impayés |
| **Portail patient** | `/p/<cabinet>` : inscription, créneaux libres en temps réel, demande de RDV, annulation |
| **Administration** | Équipe et rôles, catalogue d’actes & tarifs, horaires, fauteuils, journal de sécurité |

**Rôles** : `admin` (tout), `dentist` (agenda, patients, antécédents, soins), `secretary` (agenda, patients sans antécédents, factures), `patient` (ses propres RDV uniquement).

---

## Installation

### 1. Créer le projet Supabase
1. [supabase.com](https://supabase.com) → **New project** → région **Europe (Frankfurt / Paris)** → mot de passe de base **fort** (gardez-le).
2. Bouton **Connect** → onglet **Session pooler** : notez l’hôte (`aws-0-eu-….pooler.supabase.com`) et la référence du projet (après `postgres.` dans le nom d’utilisateur).
3. Dans `.env` (copie de `.env.example`), renseignez `DB_HOST`, `DB_PROJECT_REF` et `DB_ADMIN_PASS`.

> Plan gratuit : le projet est **mis en pause après 7 jours sans activité** et n’a **pas de sauvegarde automatique**. Pour un vrai cabinet, prévoyez le plan Pro — et gardez `scripts/backup.php` planifié dans tous les cas.

### 2. Préparer PHP (XAMPP)
Dans `C:
mpp\php\php.ini`, la ligne `extension=pdo_pgsql` doit être active (sans `;`), puis redémarrer Apache.

### 3. Installer
```bash
C:/xampp/php/php.exe database/install.php --seed
```
- crée le **schéma privé** `cabinet` (non exposé par l’API REST de Supabase),
- crée le rôle **`cabinet_app` à droits limités** (SELECT/INSERT/UPDATE/DELETE, ni superuser ni bypass RLS), mot de passe aléatoire dans `.env`,
- retire tout accès aux rôles publics de Supabase (`anon`, `authenticated`) et active la **Row Level Security** sur toutes les tables,
- `--seed` : ajoute un cabinet de démo. Identifiants : `storage/demo-credentials.txt`.

Venant de l’ancienne version MySQL ? Copiez vos données (identifiants conservés, 2FA compris) :
```bash
C:/xampp/php/php.exe database/migrate_from_mysql.php
```

### 4. Lancer
> Après avoir activé `pdo_pgsql`, **redémarrez Apache / le serveur PHP**, sinon l’application affiche « Extension PHP pdo_pgsql absente ».

```bash
C:/xampp/php/php.exe -S localhost:8080 -t public public/index.php
```
puis ouvrir http://localhost:8080 — portail patient de démo : http://localhost:8080/p/cabinet-demo

### Avec Apache (XAMPP)
Le **DocumentRoot doit pointer sur `public/`** (le reste du projet — `.env`, `app/`, `storage/` — ne doit jamais être servi). Ajouter à `C:\xampp\apache\conf\extra\httpd-vhosts.conf` :

```apache
Listen 8080
<VirtualHost *:8080>
    DocumentRoot "C:/Users/ASUS/Desktop/GestionCabinet/public"
    <Directory "C:/Users/ASUS/Desktop/GestionCabinet/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
puis redémarrer Apache. (Des fichiers `.htaccess` « Require all denied » protègent aussi les dossiers sensibles en cas d’erreur de configuration.)

---

## Performance
Chaque requête SQL fait un aller-retour vers Supabase (Irlande : ~130 ms depuis la Tunisie). Les requêtes partent en **un seul aller-retour** (`PGSQL_ATTR_DISABLE_PREPARES`, paramètres toujours liés côté serveur) : environ 0,6–0,8 s par page, 1,6 s pour le tableau de bord. En production, hébergez le PHP **dans la même région que la base** (eu-west-1) : les pages descendent sous 0,1 s.

## Tests

```bash
C:/xampp/php/php.exe tests/security_test.php http://localhost:8080
```
```bash
C:/xampp/php/php.exe tests/functional_test.php http://localhost:8080
```
- `security_test.php` — **69 vérifications** d’attaques réelles en HTTP : accès inter-cabinets, rôles, CSRF, XSS, injection SQL, brute-force, 2FA, fichiers sensibles, en-têtes, limites d’API, journalisation.
- `functional_test.php` — **29 vérifications** du parcours complet (inscription, 2FA, équipe, RDV, facture, paiements, portail).

Les deux créent leurs propres données de test et les suppriment ensuite.

---

## Sécurité — checklist

### ✅ 1. Authentification sécurisée
- Mots de passe hachés **Argon2id** (rehash automatique), 10 caractères min. avec lettres + chiffres.
- **Limitation des tentatives** : 5 échecs / 15 min par compte, 30 / 15 min par IP → verrouillage + alerte.
- Temps de réponse constant (pas d’énumération des comptes), message d’erreur générique.
- **MFA TOTP** (Google/Microsoft Authenticator, Aegis…) **obligatoire pour les administrateurs** (`MFA_REQUIRED_FOR_ADMIN`), proposée aux autres ; 8 codes de secours à usage unique ; anti-rejeu ; secret chiffré AES-256-GCM en base.
- Sessions : cookie `HttpOnly` + `SameSite=Lax` (+ `Secure`/`__Host-` en HTTPS), mode strict, ID régénéré à la connexion, expiration après 30 min d’inactivité et 10 h max.
- Changement de mot de passe, réinitialisation ou désactivation d’un compte ⇒ **toutes ses sessions sont fermées** (`session_epoch`).
- Mot de passe temporaire pour les nouveaux membres ⇒ changement obligatoire à la 1ʳᵉ connexion.

### ✅ 2. Autorisation et séparation des clients (cabinets)
- Le cabinet et le rôle viennent **uniquement de la session / base**, jamais de la requête.
- Toutes les requêtes filtrent `clinic_id` ; `findOwned()` centralise le chargement ; un ID d’un autre cabinet renvoie **404** (aucune fuite) et déclenche une **alerte critique**.
- **Clés étrangères composites** `(clinic_id, x_id)` : la base elle-même refuse de lier un RDV/une facture à un patient d’un autre cabinet.
- Patients : accès limité à leurs propres RDV, dans leur propre cabinet. L’inscription en ligne crée toujours un nouveau dossier (pas de rattachement automatique par e-mail à un dossier existant).
- Testé automatiquement (section 2 de `security_test.php`).

### ✅ 3. Validation des entrées
- Validation **côté serveur** de chaque champ (`Validator`) : types, longueurs, dates réelles, listes blanches.
- **100 % requêtes préparées** PDO, préparation native (`EMULATE_PREPARES=false`) ; contraintes `CHECK` et clés étrangères composites dans PostgreSQL.
- **Échappement systématique** des sorties (`e()`), **CSP stricte** sans script inline ni ressource externe.
- **Jeton CSRF** vérifié sur toutes les requêtes POST.
- Totaux de factures recalculés côté serveur (le navigateur n’est jamais cru).

### ✅ 4. Protection des secrets
- Secrets dans `.env` (hors du dossier web, **exclu de Git** via `.gitignore`) ; les variables d’environnement réelles sont prioritaires en production.
- Aucun secret dans le frontend ; l’application se connecte avec le rôle `cabinet_app` (**jamais `postgres`**), en TLS (`DB_SSLMODE`, `verify-full` possible avec le certificat Supabase).
- Tables dans un **schéma privé** + **Row Level Security** : la clé publique (`anon`) de Supabase ne donne accès à aucune donnée. La politique RLS autorise le seul rôle `cabinet_app` ; l’**isolation entre cabinets** est assurée par l’application (chaque requête filtrée par `clinic_id`, accès croisés journalisés).
- Mots de passe de sauvegarde passés par fichier temporaire, jamais en ligne de commande.

### ✅ 5. Protection de l’API
- Chaque endpoint vérifie l’authentification et le rôle ; données filtrées par cabinet.
- Limitation de débit : 120 req/min par utilisateur (API interne), 60 req/min par IP (créneaux publics), inscriptions, réservations, 2FA…
- Taille des requêtes limitée (`MAX_BODY_BYTES`, 1 Mo) ; plages de dates bornées.
- Erreurs propres en JSON/HTML avec un **numéro de référence** ; détails techniques uniquement dans `storage/logs/php-error.log`.

### ✅ 6. Sauvegardes et surveillance
- **Sauvegarde testée** : `scripts/backup.php` exporte toutes les tables dans un instantané cohérent (COPY, sans `pg_dump`), compresse, **chiffre (AES-256-GCM, clé `APP_KEY`)**, puis déchiffre et **restaure dans un schéma temporaire** (contraintes et clés étrangères comprises) et compare les données ; rotation sur 14 jours.
- **Journal de sécurité** (table `audit_log` + `storage/logs/security.log`) : connexions, échecs, verrouillages, 2FA, accès refusés, tentatives inter-cabinets, CSRF, limitations, changements d’équipe/paramètres, paiements.
- **Alertes** (`storage/logs/alerts.log` + e-mail si `ALERT_EMAIL` est configuré) : verrouillage de compte, tentative inter-cabinets, échecs 2FA répétés, désactivation 2FA, échec de sauvegarde.
- Écran **Journal de sécurité** pour l’administrateur de chaque cabinet.

#### Planifier la sauvegarde chaque nuit (Windows)
```bash
schtasks /Create /TN "GestionCabinet Backup" /SC DAILY /ST 02:00 /TR "C:\xampp\php\php.exe C:\Users\ASUS\Desktop\GestionCabinet\scripts\backup.php"
```
Copiez ensuite `storage/backups/` **hors de la machine** (disque externe, cloud) : une sauvegarde sur le même disque ne protège ni du vol ni d’une panne.

Les fichiers `*.json.gz.enc` sont chiffrés : **conservez `APP_KEY` à part** (gestionnaire de mots de passe), sans elle aucune sauvegarde n’est lisible. Pour relire une sauvegarde :
```bash
C:/xampp/php/php.exe scripts/decrypt_backup.php storage/backups/<fichier>.json.gz.enc
```

---

## ⚠ Avant la mise en production

1. **Supabase** : plan Pro (pas de mise en pause, sauvegardes), région UE, mot de passe de base fort ; n’exposez jamais le schéma `cabinet` dans *API Settings*.
2. **HTTPS obligatoire** (Let’s Encrypt) et `APP_URL=https://…` — active automatiquement les cookies `Secure`, le préfixe `__Host-` et HSTS.
3. `APP_DEBUG=false` (déjà par défaut).
4. Configurer un SMTP (php.ini ou service transactionnel) et `ALERT_EMAIL` pour recevoir les alertes.
5. XAMPP est un outil de **développement** : en production, utilisez un serveur Linux (Apache/Nginx + PHP-FPM + MySQL) ou un hébergeur PHP sérieux, idéalement dans la même région que la base Supabase (latence).
6. Données de santé : vérifiez vos obligations légales (en Tunisie : loi organique 2004-63 et INPDP ; en Europe : RGPD + hébergeur certifié HDS).
7. Supprimer le cabinet de démo et `storage/demo-credentials.txt`.
8. Laisser `ALLOW_CLINIC_REGISTRATION=false` (défaut) pour un cabinet unique : `/register` répond 404 et personne ne peut créer de cabinet dans votre base. Ne passer à `true` que pour une offre SaaS ouverte.

## Structure

```
app/Core/         noyau : Router, Database (PDO), Session, Auth (+MFA), Csrf, Validator, RateLimiter, Audit, Crypto…
app/Controllers/  un contrôleur par module
app/Views/        vues PHP (sorties échappées avec e())
public/           seul dossier exposé : index.php (contrôleur frontal) + assets
database/         schema.sql (PostgreSQL), install.php, seed.php, migrate_from_mysql.php
scripts/          backup.php (sauvegarde chiffrée + test de restauration), decrypt_backup.php
storage/          logs, sauvegardes (non versionnés)
tests/            tests de sécurité et fonctionnels de bout en bout
```

## Pistes d’évolution
Rappels de RDV par SMS/e-mail, schéma dentaire interactif, imagerie/radios (stockage chiffré), devis, export comptable, abonnement payant par cabinet (Stripe/Konnect), super-administrateur de la plateforme.
