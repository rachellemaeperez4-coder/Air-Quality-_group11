# AirSense API

Express API for the React frontend. Copy `.env.example` to `.env` for local development. Vercel should use `backend` as the project root and set `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, and `CORS_ORIGIN` in Project Settings.

The API uses the signed-in user's Supabase access token and database row-level security. It does not need a service-role key.

Main routes:

- `POST /api/auth/login`, `POST /api/auth/signup`, `GET /api/auth/me`
- `GET /api/public/latest-readings`
- `/api/user/*` for user dashboard, reading history, and alerts
- `/api/staff/*` for Staff overview, records, accounts, thresholds, alerts, and CSV export

Run `npm install` and `npm run dev` from this directory.
