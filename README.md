# AURELLE — home décor store (PHP + Supabase, one Vercel project)

A home décor storefront with customer accounts, cart, wishlist, checkout and an admin console.

* **Frontend:** plain HTML/CSS/JS in `public/`
* **Backend:** PHP 8.3 files in `api/` and `lib/`. They run on Vercel as a serverless function (community runtime `vercel-php`), inside the **same project** as the website. **No Render, Railway or other server.**
* **Database + Auth + image storage:** Supabase. Only the PHP code talks to Supabase with the secret key.

```
Browser ──► Vercel ─┬─ /public   static site
                    └─ /api/*    PHP function (api/index.php + lib/*.php) ──► Supabase (Postgres · Auth · Storage)
```

## Deploy (about 10 minutes)

### 1. Supabase
1. Create a project at supabase.com.
2. **SQL Editor →** run `database/schema.sql`, then `database/seed.sql` (32 products, 8 categories, coupons, settings).
3. **Authentication → Providers → Email:** for an easy start, turn **off** "Confirm email". Otherwise new users must click an emailed link before signing in (Supabase's built-in mailer is rate-limited; add SMTP for production).
4. **Authentication → URL Configuration:** set *Site URL* to your Vercel address and add `https://YOUR-SITE.vercel.app/reset-password.html` under *Redirect URLs* (do this after step 2 once you know the address).
5. **Project Settings → API:** copy the Project URL, the `anon` key and the `service_role` key.

### 2. Vercel
1. Push this folder to GitHub (the `.env` file is git-ignored).
2. **Vercel → Add New → Project →** import the repo. Leave *Framework Preset* as **Other**, leave the build and output settings empty.
3. Add **Environment Variables** (same names as `.env.example`):

   | Name | Value |
   |---|---|
   | `SUPABASE_URL` | your project URL |
   | `SUPABASE_ANON_KEY` | the `anon` key |
   | `SUPABASE_SERVICE_ROLE_KEY` | the `service_role` key (secret) |

4. Deploy. Then open `https://YOUR-SITE.vercel.app/api/health`. You should see `{"status":"ok"}`. If you do, PHP is running.

Open the site, click the account icon, **Create account**, then sign in.

### 3. Make yourself an admin
Register on the site, then in the Supabase SQL Editor:
```sql
update public.profiles set role = 'admin' where email = 'you@example.com';
```
Sign in again; the account icon now opens `/admin/index.html` (products, orders, customers, coupons, reviews, settings, image upload).

## Run locally
Needs PHP 8.1+ with `curl`, `fileinfo`, `mbstring`.
```bash
cp .env.example .env        # fill in the 3 Supabase values
php -S localhost:3000 dev-server.php
```
Open http://localhost:3000 (the same `api/index.php` serves `/api/*`, as on Vercel).

## What changed in this version
* PHP lives in `api/` + `lib/` and deploys with the site. No CORS, no second host, no Dockerfile.
* **Search removed** (header icon and panel, shop-page box, `/search/suggest` endpoint).
* **New home-décor catalogue and imagery:** brass showpieces, wall art, mirrors, vases, lamps, cushions, rugs, urlis and more; each product has a studio view, a styled-at-home view and a close-up.
* **About page rebuilt** as a story: origin, four-step making process with illustrations, craft traditions, promises, gallery.
* Registration, login (with "remember me"), logout, forgot/reset password, profile and addresses all go through PHP → Supabase Auth.

## Images
All images in `public/assets/` are real photos (JPG). To change them:
* **Per product:** Admin → Products → edit → upload images (stored in Supabase Storage), or paste image URLs.
* **Site-wide** (hero, categories, collections, About): replace the files in `public/assets/` keeping the same names, or edit the `<img>` paths.

## Notes and limits
* **Vercel + PHP** uses the community runtime `vercel-php@0.7.4` (PHP 8.3), pinned in `vercel.json`. Request bodies are limited to ~4.5 MB on Vercel, so image uploads are capped at 4 MB.
* The login rate-limiter uses the function's temp folder, so it is per-instance (good against casual abuse, not a replacement for a WAF).
* **Payments are a labelled demo** (orders record `demo`, never "paid"). Add Razorpay/Stripe in a new PHP endpoint before taking real money.
* The About-page story, testimonials and contact details are **sample copy**. Replace them with your real ones (`public/about.html`, `public/index.html`, Admin → Settings).
* Seed `delete`s existing products/categories first, so run it only on a fresh project.
* Not run end-to-end here (no PHP runtime or Supabase project in the build environment). JavaScript syntax was checked; follow "Deploy" step 4 and the checks below on your first deploy.

## First-deploy checks
- [ ] `/api/health` returns ok · `/api/products` returns products
- [ ] Register (weak password rejected) → sign in → account page → sign out
- [ ] Forgot password → email → reset → sign in with new password
- [ ] Add to cart → checkout (COD) → order appears in My orders and in Admin
- [ ] Admin: non-admin gets 403 on `/api/admin/stats`; add a product with an uploaded image


## Photos
The whole site uses only the 27 supplied photos (no generated illustrations). The catalogue is 26 products in 6 categories; product names, prices and descriptions for the products added from the photos are placeholders. Edit them in Admin → Products.
