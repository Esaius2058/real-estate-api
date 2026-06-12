# Real Estate App — Backend API

A multi-tenant SaaS platform for real estate agencies built with Laravel. Handles property listings, CRM pipelines, secure document vaulting (KYC/Title Deeds), and escrow tracking.

---

## Stack

- **Runtime:** PHP 8.3
- **Framework:** Laravel 12
- **Database:** MySQL
- **Auth:** Laravel Sanctum (token-based, CSRF-protected)
- **Storage:** Supabase S3 (private bucket for KYC and title deeds)
- **Queue:** Laravel Jobs (notifications, matching algorithm)
- **Scheduler:** Laravel Cron (auto-expire listings)

---

## Architecture

Multi-tenancy is handled via **Logical Tenant Separation** — every model scoped to `agency_id` through a Laravel Global Scope. No agency can query another's data at the database level.

```
app/
├── Console/Commands/          # ExpireListings cron job
├── Events & Listeners/        # Domain events (match found, doc verified)
├── Http/
│   ├── Controllers/Api/V1/    # Versioned API controllers
│   ├── Middleware/             # Tenant resolution, MFA gate
│   ├── Requests/              # Form request validation
│   └── Resources/             # API response transformers
├── Models/                    # Agency, User, Property, Lead, SecureDocument, Transaction, ActivityLog
├── Notifications/             # Email/SMS alerts
├── Policies/                  # RBAC — admin vs agent permissions
├── Scopes/                    # AgencyScope (global tenant filter)
├── Services/
│   ├── Escrow/                # Fund tracking + webhook signature verification
│   ├── Matching/              # Property ↔ buyer matching algorithm
│   ├── Signature/             # Daraja/Stripe webhook verification
│   └── Vault/                 # Presigned S3 URLs, MIME validation
└── Traits/                    # BelongsToAgency (applied to all tenant models)
```

---

## Core Modules

| Module | Description |
|---|---|
| **Agency Workspace** | Onboarding, subscription tiers, RBAC (admin/agent roles) |
| **Property Engine** | CRUD listings, image galleries, contract lifecycle, auto-expiry |
| **CRM Pipeline** | Kanban lead tracking, activity timeline, automated alerts |
| **Secure Vault** | KYC uploads, title deed verification workflow, e-signature |
| **Escrow Ledger** | Fund tracking, payment gateway webhook verification |
| **Audit Trail** | Immutable `activity_logs` table — every sensitive action recorded |

---

## Security Highlights

- Global Scopes enforce tenant isolation at the query level
- MFA required for admins accessing Vault and Escrow modules
- KYC and title deeds stored in a **private S3 bucket** — never in `/public`
- Document access via **presigned URLs** (15-minute expiry)
- Strict MIME-type validation on all uploads
- Webhook signature verification for Stripe and Daraja (M-Pesa)
- Login throttling via Laravel rate limiting

---

## Getting Started

```bash
git clone git@github.com:Esaius2058/real-estate-api.git
cd real-estate-api

composer install
cp .env.example .env
php artisan key:generate

# Configure your .env (DB, S3, Sanctum, mail, SMS)

php artisan migrate --seed
php artisan serve
```

---

## Environment Variables

Key `.env` values to configure before running:

```
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_PRIVATE_BUCKET=

SANCTUM_STATEFUL_DOMAINS=
MFA_DRIVER=sms|authenticator

DARAJA_CONSUMER_KEY=
DARAJA_CONSUMER_SECRET=
```

---

## Related Repositories

- **Frontend (React):** `real-estate-web` 

---

## License

Private & Proprietary Saturn Group
