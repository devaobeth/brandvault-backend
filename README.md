# BrandVault API

Laravel backend for BrandVault (brand kit + asset library).

## Requirements

- PHP 8.2+ with extensions used by Laravel (`pdo_pgsql`, etc.)
- Composer
- Postgres (local or Supabase)

## Setup (local)

```bash
cd backend

cp .env.example .env
composer install
php artisan key:generate
```

Edit `.env` and set at least:

- `DB_CONNECTION=pgsql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `DB_SSLMODE=require` (needed for Supabase)
- `FRONTEND_URL=http://localhost:5173` (your frontend origin)

Optional:

- `GEMINI_API_KEY` — AI tag suggestions
- `N8N_WEBHOOK_URL` — optional webhooks (leave empty to disable)

Then:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan serve
```

API runs at **http://127.0.0.1:8000**

## Demo login

After seeding:

- Email: `demo@brandvault.dev`
- Password: `Demo1234!`

## Frontend

Run the frontend separately and set:

```env
VITE_API_URL=http://127.0.0.1:8000
```

See `.env.example` for all supported variables.

## Extras

### AI prompt
- Prompt file: `prompts/asset-tagging.md`
- Used by Gemini for tag / description / usage suggestions (review before save)

### n8n webhook (optional)
- Set `N8N_WEBHOOK_URL` to your n8n Production webhook URL (empty = disabled)
- Events: `ai.tag_suggestion.saved`, `asset.restored`, `brand.updated`
- Payload: `event`, `asset_id`, `brand_id`, `user_email`, `timestamp`
- Workflow export: `n8n/brandvault-webhook.json` (import into n8n)

### Postman
- Collection: `postman/BrandVault.postman_collection.json`
- Import into Postman and set the collection base URL to your API (e.g. `http://127.0.0.1:8000`)
