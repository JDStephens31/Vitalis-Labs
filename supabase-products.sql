-- ============================================================================
-- Vitalis Labs — products + storage schema
--
-- Run this AFTER supabase-schema.sql (which creates orders/admins/is_admin()).
--
-- What this adds:
--   1. public.products — one row per catalog item, including live stock and a
--      pointer to its image in Supabase Storage. The WEBSITE (anon key) may only
--      SELECT active products; admins get full read/write.
--   2. A public "product-images" storage bucket the catalog images live in.
--   3. Two columns on public.orders: `referral` and `shipping_cents`.
--
-- Stock is decremented automatically when an order is placed — see
-- supabase-stock-functions.sql (run that after this file).
-- ============================================================================

-- ── PRODUCTS ────────────────────────────────────────────────────────────────
create table if not exists public.products (
  id           text        primary key,          -- 'bpc', 'ghk', 'reta10', 'reta30'
  name         text        not null,
  sub          text        not null,             -- subtitle under the name
  dose         text        not null,             -- e.g. '10 mg'
  purity       text        not null,             -- e.g. '99.9%'
  price_cents  integer     not null check (price_cents >= 0),
  cap          text        not null default '#8b5cf6',  -- vial glow / accent color
  image_path   text,                             -- filename inside the storage bucket, e.g. 'bpc-157.png'
  stock        integer     not null default 0 check (stock >= 0),
  sort_order   integer     not null default 0,   -- display order in the catalog
  active       boolean     not null default true,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create index if not exists products_active_sort_idx on public.products (active, sort_order);

-- keep updated_at fresh (reuses the helper from supabase-schema.sql)
drop trigger if exists products_set_updated_at on public.products;
create trigger products_set_updated_at
  before update on public.products
  for each row execute function public.set_updated_at();

-- ── RLS ──────────────────────────────────────────────────────────────────────
alter table public.products enable row level security;

-- Website (anon) can read only active products.
drop policy if exists "anon reads active products" on public.products;
create policy "anon reads active products"
  on public.products for select to anon
  using (active = true);

-- Signed-in admins get full read/write to manage the catalog and stock.
drop policy if exists "admins manage products" on public.products;
create policy "admins manage products"
  on public.products for all to authenticated
  using (public.is_admin())
  with check (public.is_admin());

grant select on public.products to anon;
grant select, insert, update, delete on public.products to authenticated;

-- ── STORAGE: product-images bucket ───────────────────────────────────────────
-- Public bucket so the catalog <img> tags can load images directly. Upload your
-- vial PNGs here (Storage → product-images), using the same filenames referenced
-- by products.image_path below.
insert into storage.buckets (id, name, public)
values ('product-images', 'product-images', true)
on conflict (id) do update set public = true;

-- Public read of objects in this bucket.
drop policy if exists "public read product images" on storage.objects;
create policy "public read product images"
  on storage.objects for select to public
  using (bucket_id = 'product-images');

-- Only admins may add/replace/remove images.
drop policy if exists "admins write product images" on storage.objects;
create policy "admins write product images"
  on storage.objects for all to authenticated
  using (bucket_id = 'product-images' and public.is_admin())
  with check (bucket_id = 'product-images' and public.is_admin());

-- ── ORDERS: referral + shipping columns ──────────────────────────────────────
alter table public.orders add column if not exists referral       text;
alter table public.orders add column if not exists shipping_cents integer not null default 0;

-- ── SEED the catalog (matches the site's current items) ──────────────────────
-- Adjust prices/stock here; the website reads these live. image_path points at a
-- file you upload to the product-images bucket.
insert into public.products (id, name, sub, dose, purity, price_cents, cap, image_path, stock, sort_order) values
  ('bpc',    'BPC-157', '15 Amino-Acid Synthetic',           '10 mg',  '99.9%', 10000, '#37a24a', 'bpc-157.png', 25, 1),
  ('ghk',    'GHK-Cu',  'Copper Tripeptide-1',               '100 mg', '99.9%', 10000, '#8b5cf6', 'ghk-cu.png',  25, 2),
  ('reta10', 'Reta',    'Triple-Agonist Research Compound',  '10 mg',  '99.9%', 10000, '#e8622a', 'reta-10.png', 25, 3),
  ('reta30', 'Reta',    'Triple-Agonist Research Compound',  '30 mg',  '99.9%', 20000, '#e8622a', 'reta-30.png', 25, 4)
on conflict (id) do update set
  name        = excluded.name,
  sub         = excluded.sub,
  dose        = excluded.dose,
  purity      = excluded.purity,
  price_cents = excluded.price_cents,
  cap         = excluded.cap,
  image_path  = excluded.image_path,
  sort_order  = excluded.sort_order;
