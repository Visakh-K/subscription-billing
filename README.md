# SaaS Subscription Billing & Usage-Metering Backend

A multi-tenant Laravel backend for tracking metered usage, handling two-layer idempotency, nightly chunked aggregations, and mid-cycle plan proration.

---

## 🚀 Tech Stack
* **Framework:** Laravel (PHP 8.4)
* **Database:** MySQL / SQLite

---

## ✨ Key Features
1. **Usage Ingestion (`POST /api/usage`)**: Rate-limited (120 req/min) with two-layer idempotency (Redis fast-path + MySQL unique constraint).
2. **Chunked Aggregation**: Nightly queued jobs process usage events in batches of 15,000 rows to maintain constant memory usage.
3. **Mid-Cycle Proration (Requirement 8)**: Supports upgrades and downgrades, slicing billing cycles into chronological segments with exact time-ratio proration.
4. **Merchant Dashboard (`GET /api/merchants/{id}/dashboard`)**: Returns real-time usage, active plans, top 5 customers, MoM churn risk (>50% drop), and 30-day trends.

---

## 🛠️ Quick Start Setup

### 1. Clone & Install
```bash
git clone <repository-url>
cd <project-directory>
composer install
cp .env.example .env
php artisan key:generate