-- ============================================================================
-- Vitalis Labs — stock functions
--
-- Run this AFTER supabase-products.sql.
--
-- When a new order is inserted, decrement the matching product's stock by the
-- quantity ordered. The order's `items` column is a JSON array of line items:
--   [{ "id": "reta30", "qty": 2, ... }, ...]
--
-- Design notes:
--   * SECURITY DEFINER so the decrement runs with owner rights — the website's
--     anon role can INSERT an order (and thus trigger this) without needing any
--     UPDATE grant on products.
--   * Stock is clamped at 0 (greatest(...)) so a race can never push it negative
--     and violate the products.stock >= 0 check. The catalog already caps the
--     quantity dropdown to available stock; this is the backstop.
--   * Runs AFTER INSERT so it only reduces stock for orders that actually landed.
-- ============================================================================

create or replace function public.orders_apply_stock()
  returns trigger
  language plpgsql
  security definer
  set search_path = public
as $$
declare
  line jsonb;
  line_id  text;
  line_qty integer;
begin
  -- items is a jsonb array; walk each line and decrement its product.
  if new.items is not null and jsonb_typeof(new.items) = 'array' then
    for line in select * from jsonb_array_elements(new.items)
    loop
      line_id  := line->>'id';
      line_qty := coalesce((line->>'qty')::integer, 0);
      if line_id is not null and line_qty > 0 then
        update public.products
          set stock = greatest(0, stock - line_qty)
          where id = line_id;
      end if;
    end loop;
  end if;
  return new;
end $$;

drop trigger if exists orders_apply_stock_after_insert on public.orders;
create trigger orders_apply_stock_after_insert
  after insert on public.orders
  for each row execute function public.orders_apply_stock();

-- ── Optional helper: restock / adjust a single product (admin convenience) ────
-- Positive delta adds stock, negative removes it (never below 0). Call from the
-- SQL editor or your admin app, e.g.  select public.adjust_stock('bpc', 50);
create or replace function public.adjust_stock(p_id text, p_delta integer)
  returns integer
  language plpgsql
  security definer
  set search_path = public
as $$
declare
  new_stock integer;
begin
  update public.products
    set stock = greatest(0, stock + p_delta)
    where id = p_id
    returning stock into new_stock;
  return new_stock;
end $$;

revoke all on function public.adjust_stock(text, integer) from public;
grant execute on function public.adjust_stock(text, integer) to authenticated;
