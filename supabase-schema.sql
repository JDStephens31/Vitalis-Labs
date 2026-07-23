-- ============================================================================
-- Vitalis Labs — orders schema
--
-- Two access tiers:
--   1. The WEBSITE uses the public "anon" key and may only INSERT orders.
--      It can never read, update, or delete — and a trigger forces every
--      anon-created order to status 'new' with a server-set timestamp, so a
--      tampered request can't self-mark an order 'delivered'.
--   2. The ELECTRON ADMIN APP signs in with Supabase Auth (email/password).
--      Only users listed in public.admins get full read/insert/update/delete,
--      so they can manage the order lifecycle. Even if someone self-signs-up
--      with the anon key, they see nothing until you add them to admins.
--
-- Run this whole file once in the Supabase SQL Editor.
-- ============================================================================

-- ── ORDERS ──────────────────────────────────────────────────────────────────
create table if not exists public.orders (
  id             uuid primary key default gen_random_uuid(),
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  order_number   text        not null,
  full_name      text        not null,
  email          text        not null,
  phone          text        not null,
  address        text        not null,
  city           text        not null,
  state          text        not null,
  zip            text        not null,
  items          jsonb       not null,   -- [{id,name,dose,purity,qty,unit_price_cents,line_cents}]
  subtotal_cents integer     not null,
  total_cents    integer     not null,
  status         text        not null default 'new'
                 check (status in ('new','in_progress','ready_for_delivery','delivered','cancelled'))
);

-- Indexes for the admin dashboard (filter by status, newest first).
create index if not exists orders_status_idx     on public.orders (status);
create index if not exists orders_created_at_idx  on public.orders (created_at desc);

-- ── ADMINS allowlist ────────────────────────────────────────────────────────
-- One row per person allowed to manage orders. Populate it AFTER creating your
-- account in the Electron app (see "Promote a user" at the bottom).
create table if not exists public.admins (
  user_id    uuid primary key references auth.users (id) on delete cascade,
  email      text,
  created_at timestamptz not null default now()
);

-- SECURITY DEFINER so it can read public.admins regardless of the caller's RLS.
-- This is what the orders policies use to decide "is the current user an admin?".
create or replace function public.is_admin()
  returns boolean
  language sql
  security definer
  stable
  set search_path = public
as $$
  select exists (select 1 from public.admins where user_id = auth.uid());
$$;

-- ── Triggers ────────────────────────────────────────────────────────────────
-- Keep updated_at fresh on every edit.
create or replace function public.set_updated_at()
  returns trigger language plpgsql as $$
begin
  new.updated_at := now();
  return new;
end $$;

drop trigger if exists orders_set_updated_at on public.orders;
create trigger orders_set_updated_at
  before update on public.orders
  for each row execute function public.set_updated_at();

-- Harden anon inserts: the website cannot choose the status or backdate an order.
-- SECURITY INVOKER (default) is required so current_user reflects the CALLER's role
-- ('anon' for the website). Under SECURITY DEFINER current_user would be the owner
-- and this check would never match.
create or replace function public.orders_sanitize_anon()
  returns trigger language plpgsql as $$
begin
  if current_user = 'anon' then
    new.status     := 'new';
    new.created_at := now();
    new.updated_at := now();
  end if;
  return new;
end $$;

drop trigger if exists orders_sanitize_anon_before_insert on public.orders;
create trigger orders_sanitize_anon_before_insert
  before insert on public.orders
  for each row execute function public.orders_sanitize_anon();

-- ── Row Level Security ──────────────────────────────────────────────────────
alter table public.orders enable row level security;
alter table public.admins enable row level security;

-- Orders: the website (anon) may INSERT only. No select/update/delete policy for
-- anon exists, so those are denied.
drop policy if exists "anon can insert orders" on public.orders;
create policy "anon can insert orders"
  on public.orders for insert to anon
  with check (true);

-- Orders: admins (signed-in + on the allowlist) get full access.
drop policy if exists "admins manage orders" on public.orders;
create policy "admins manage orders"
  on public.orders for all to authenticated
  using (public.is_admin())
  with check (public.is_admin());

-- Admins table: a signed-in user may read only their OWN row (so the app can ask
-- "am I an admin?"). Adding/removing admins is done here in the SQL editor.
drop policy if exists "read own admin row" on public.admins;
create policy "read own admin row"
  on public.admins for select to authenticated
  using (user_id = auth.uid());

-- ── Grants (RLS still gates the rows; grants just enable the verbs) ──────────
grant insert on public.orders to anon;
grant select, insert, update, delete on public.orders to authenticated;
grant select on public.admins to authenticated;
grant execute on function public.is_admin() to authenticated;

-- ============================================================================
-- Promote a user to admin (run AFTER they've signed up in the Electron app):
--
--   insert into public.admins (user_id, email)
--   select id, email from auth.users
--   where email = 'you@example.com'
--   on conflict (user_id) do nothing;
--
-- Recommended: in Supabase Dashboard → Authentication → Providers/Settings,
-- turn OFF public sign-ups once your admin account exists, so no one else can
-- create accounts. (The allowlist already blocks data access either way.)
-- ============================================================================
