-- AURELLE — Supabase PostgreSQL schema (run in Supabase SQL Editor, then seed.sql)
-- Architecture note: the PHP API uses the service-role key and enforces authorization itself;
-- RLS is still enabled on every table so direct client access can never leak private data.

create extension if not exists "pgcrypto";

-- ───────────── Tables ─────────────
create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  full_name text not null default '',
  email text not null,
  phone text,
  avatar_url text,  
  role text not null default 'customer' check (role in ('customer','admin')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table public.categories (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  slug text not null unique,
  description text not null default '',
  image_url text,
  sort_order int not null default 0,
  created_at timestamptz not null default now()
);

create table public.products (
  id uuid primary key default gen_random_uuid(),
  sku text not null unique,
  slug text not null unique,
  name text not null,
  description text not null default '',
  category_id uuid not null references public.categories(id) on delete restrict,
  collection text,
  price numeric(10,2) not null check (price >= 0),
  sale_price numeric(10,2) check (sale_price is null or (sale_price >= 0 and sale_price < price)),
  effective_price numeric(10,2) generated always as (coalesce(sale_price, price)) stored,
  stock int not null default 0 check (stock >= 0),
  materials text,
  dimensions text,
  colors text[] not null default '{}',
  specs jsonb not null default '{}'::jsonb,
  is_featured boolean not null default false,
  is_bestseller boolean not null default false,
  is_published boolean not null default true,
  sold_count int not null default 0,
  rating_avg numeric(3,2) not null default 0,
  rating_count int not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table public.product_images (
  id uuid primary key default gen_random_uuid(),
  product_id uuid not null references public.products(id) on delete cascade,
  url text not null,
  alt text not null default '',
  sort_order int not null default 0
);

create table public.carts (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null unique references auth.users(id) on delete cascade,
  updated_at timestamptz not null default now()
);
create table public.cart_items (
  id uuid primary key default gen_random_uuid(),
  cart_id uuid not null references public.carts(id) on delete cascade,
  product_id uuid not null references public.products(id) on delete cascade,
  quantity int not null check (quantity > 0 and quantity <= 99),
  unique (cart_id, product_id)
);

create table public.wishlists (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null unique references auth.users(id) on delete cascade
);
create table public.wishlist_items (
  id uuid primary key default gen_random_uuid(),
  wishlist_id uuid not null references public.wishlists(id) on delete cascade,
  product_id uuid not null references public.products(id) on delete cascade,
  created_at timestamptz not null default now(),
  unique (wishlist_id, product_id)
);

create table public.addresses (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references auth.users(id) on delete cascade,
  label text not null default 'Home',
  full_name text not null,
  phone text not null,
  line1 text not null,
  city text not null,
  state text not null,
  postal_code text not null,
  country text not null default 'India',
  is_default boolean not null default false,
  created_at timestamptz not null default now()
);

create table public.coupons (
  id uuid primary key default gen_random_uuid(),
  code text not null unique check (code = upper(code)),
  discount_type text not null check (discount_type in ('percent','fixed')),
  discount_value numeric(10,2) not null check (discount_value > 0),
  min_subtotal numeric(10,2) not null default 0,
  max_uses int,
  expires_at timestamptz,
  is_active boolean not null default true,
  created_at timestamptz not null default now()
);

create table public.orders (
  id uuid primary key default gen_random_uuid(),
  order_number text not null unique,
  user_id uuid not null references auth.users(id) on delete restrict,
  status text not null default 'pending'
    check (status in ('pending','confirmed','processing','shipped','delivered','cancelled')),
  payment_method text not null check (payment_method in ('cod','demo_online')),
  payment_status text not null default 'pending' check (payment_status in ('pending','demo','paid','refunded')),
  subtotal numeric(12,2) not null,
  discount numeric(12,2) not null default 0,
  shipping numeric(12,2) not null default 0,
  total numeric(12,2) not null,
  coupon_code text,
  ship_name text not null, ship_email text not null, ship_phone text not null,
  ship_address text not null, ship_city text not null, ship_state text not null,
  ship_postal_code text not null, ship_country text not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create table public.order_items (
  id uuid primary key default gen_random_uuid(),
  order_id uuid not null references public.orders(id) on delete cascade,
  product_id uuid references public.products(id) on delete set null,
  product_name text not null,
  sku text not null,
  image_url text,
  unit_price numeric(10,2) not null,
  quantity int not null check (quantity > 0)
);

create table public.coupon_usage (
  id uuid primary key default gen_random_uuid(),
  coupon_id uuid not null references public.coupons(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  order_id uuid not null references public.orders(id) on delete cascade,
  used_at timestamptz not null default now(),
  unique (coupon_id, user_id)
);

create table public.reviews (
  id uuid primary key default gen_random_uuid(),
  product_id uuid not null references public.products(id) on delete cascade,
  user_id uuid not null references public.profiles(id) on delete cascade,
  rating int not null check (rating between 1 and 5),
  title text not null default '',
  body text not null default '',
  created_at timestamptz not null default now(),
  unique (product_id, user_id)
);

create table public.newsletter_subscribers (
  id uuid primary key default gen_random_uuid(),
  email text not null unique,
  created_at timestamptz not null default now()
);

create table public.store_settings (
  key text primary key,
  value jsonb not null
);

-- ───────────── Indexes ─────────────
create index idx_products_category on public.products(category_id);
create index idx_products_collection on public.products(collection);
create index idx_products_published_created on public.products(is_published, created_at desc);
create index idx_products_effective_price on public.products(effective_price);
create index idx_product_images_product on public.product_images(product_id, sort_order);
create index idx_cart_items_cart on public.cart_items(cart_id);
create index idx_wishlist_items_wl on public.wishlist_items(wishlist_id);
create index idx_addresses_user on public.addresses(user_id);
create index idx_orders_user_created on public.orders(user_id, created_at desc);
create index idx_orders_status on public.orders(status);
create index idx_order_items_order on public.order_items(order_id);
create index idx_reviews_product on public.reviews(product_id, created_at desc);

-- ───────────── Helper functions & triggers ─────────────
create or replace function public.is_admin() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from public.profiles where id = auth.uid() and role = 'admin');
$$;

create or replace function public.touch_updated_at() returns trigger language plpgsql as $$
begin new.updated_at = now(); return new; end $$;
create trigger trg_profiles_touch before update on public.profiles for each row execute function public.touch_updated_at();
create trigger trg_products_touch before update on public.products for each row execute function public.touch_updated_at();
create trigger trg_orders_touch before update on public.orders for each row execute function public.touch_updated_at();

-- Create a profile whenever someone signs up through Supabase Auth.
create or replace function public.handle_new_user() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  insert into public.profiles (id, email, full_name)
  values (new.id, new.email, coalesce(new.raw_user_meta_data->>'full_name', ''));
  return new;
end $$;
create trigger on_auth_user_created after insert on auth.users for each row execute function public.handle_new_user();

-- Keep product rating aggregates in sync with reviews.
create or replace function public.refresh_product_rating() returns trigger
language plpgsql security definer set search_path = public as $$
declare pid uuid := coalesce(new.product_id, old.product_id);
begin
  update public.products p set
    rating_avg = coalesce((select round(avg(rating)::numeric, 2) from public.reviews where product_id = pid), 0),
    rating_count = (select count(*) from public.reviews where product_id = pid)
  where p.id = pid;
  return null;
end $$;
create trigger trg_reviews_rating after insert or update or delete on public.reviews
  for each row execute function public.refresh_product_rating();

-- ───────────── Pricing: single source of truth ─────────────
-- Used by the cart page AND by place_order, so displayed totals always equal charged totals.
create or replace function public.quote_cart(p_items jsonb, p_coupon text default null, p_user uuid default null)
returns jsonb language plpgsql stable security definer set search_path = public as $$
declare
  r record; p public.products%rowtype; v_lines jsonb := '[]'::jsonb;
  v_subtotal numeric(12,2) := 0; v_discount numeric(12,2) := 0; v_shipping numeric(12,2) := 0;
  v_ok boolean := true; v_issue text; v_img text; v_ship jsonb; v_flat numeric; v_free numeric;
  c public.coupons%rowtype; v_coupon_error text; v_coupon_applied text;
begin
  for r in
    select (e->>'product_id')::uuid as pid, sum(greatest((e->>'quantity')::int, 1))::int as qty
    from jsonb_array_elements(coalesce(p_items, '[]'::jsonb)) e group by 1
  loop
    select * into p from public.products where id = r.pid;
    if not found or not p.is_published then
      v_ok := false;
      v_lines := v_lines || jsonb_build_object('product_id', r.pid, 'issue', 'unavailable', 'quantity', r.qty);
      continue;
    end if;
    v_issue := null;
    if p.stock = 0 then v_issue := 'out_of_stock'; v_ok := false;
    elsif r.qty > p.stock then v_issue := 'insufficient_stock'; v_ok := false; end if;
    select url into v_img from public.product_images where product_id = p.id order by sort_order limit 1;
    v_lines := v_lines || jsonb_build_object(
      'product_id', p.id, 'slug', p.slug, 'name', p.name, 'sku', p.sku, 'image', v_img,
      'unit_price', p.effective_price, 'compare_at', case when p.sale_price is not null then p.price end,
      'quantity', r.qty, 'stock', p.stock, 'line_total', p.effective_price * r.qty, 'issue', v_issue);
    v_subtotal := v_subtotal + p.effective_price * r.qty;
  end loop;

  if p_coupon is not null and length(trim(p_coupon)) > 0 then
    select * into c from public.coupons where code = upper(trim(p_coupon));
    if not found or not c.is_active then v_coupon_error := 'This coupon code is not valid.';
    elsif c.expires_at is not null and c.expires_at < now() then v_coupon_error := 'This coupon has expired.';
    elsif v_subtotal < c.min_subtotal then v_coupon_error := 'Spend at least ' || c.min_subtotal || ' to use this coupon.';
    elsif c.max_uses is not null and (select count(*) from public.coupon_usage where coupon_id = c.id) >= c.max_uses then
      v_coupon_error := 'This coupon has reached its usage limit.';
    elsif p_user is not null and exists (select 1 from public.coupon_usage where coupon_id = c.id and user_id = p_user) then
      v_coupon_error := 'You have already used this coupon.';
    else
      v_discount := case c.discount_type when 'percent' then round(v_subtotal * c.discount_value / 100, 2)
                    else least(c.discount_value, v_subtotal) end;
      v_coupon_applied := c.code;
    end if;
  end if;

  select value into v_ship from public.store_settings where key = 'shipping';
  v_flat := coalesce((v_ship->>'flat_rate')::numeric, 0);
  v_free := coalesce((v_ship->>'free_threshold')::numeric, 0);
  if v_subtotal > 0 and not (v_free > 0 and v_subtotal - v_discount >= v_free) then v_shipping := v_flat; end if;

  return jsonb_build_object('ok', v_ok and jsonb_array_length(v_lines) > 0, 'lines', v_lines, 'subtotal', v_subtotal,
    'discount', v_discount, 'shipping', v_shipping, 'total', v_subtotal - v_discount + v_shipping,
    'coupon', v_coupon_applied, 'coupon_error', v_coupon_error, 'free_shipping_threshold', v_free);
end $$;

create or replace function public.place_order(p_user uuid, p_items jsonb, p_coupon text, p_ship jsonb, p_payment text)
returns jsonb language plpgsql security definer set search_path = public as $$
declare q jsonb; v_order public.orders%rowtype; l jsonb; v_num text;
begin
  if p_payment not in ('cod','demo_online') then raise exception 'Unsupported payment method'; end if;
  -- Lock the products being bought (stable order avoids deadlocks) so stock checks are race-free.
  perform 1 from public.products where id in (select (e->>'product_id')::uuid from jsonb_array_elements(p_items) e) order by id for update;
  q := public.quote_cart(p_items, p_coupon, p_user);
  if not (q->>'ok')::boolean then raise exception 'CART_INVALID: some items are unavailable or exceed stock'; end if;
  if p_coupon is not null and length(trim(p_coupon)) > 0 and q->>'coupon' is null then
    raise exception 'COUPON_INVALID: %', q->>'coupon_error';
  end if;
  v_num := 'AUR-' || to_char(now(), 'YYMMDD') || '-' || upper(substr(encode(gen_random_bytes(3), 'hex'), 1, 5));
  insert into public.orders (order_number, user_id, payment_method, payment_status, subtotal, discount, shipping, total, coupon_code,
    ship_name, ship_email, ship_phone, ship_address, ship_city, ship_state, ship_postal_code, ship_country)
  values (v_num, p_user, p_payment, case when p_payment = 'demo_online' then 'demo' else 'pending' end,
    (q->>'subtotal')::numeric, (q->>'discount')::numeric, (q->>'shipping')::numeric, (q->>'total')::numeric, q->>'coupon',
    p_ship->>'name', p_ship->>'email', p_ship->>'phone', p_ship->>'address', p_ship->>'city', p_ship->>'state',
    p_ship->>'postal_code', p_ship->>'country')
  returning * into v_order;
  for l in select * from jsonb_array_elements(q->'lines') loop
    insert into public.order_items (order_id, product_id, product_name, sku, image_url, unit_price, quantity)
    values (v_order.id, (l->>'product_id')::uuid, l->>'name', l->>'sku', l->>'image', (l->>'unit_price')::numeric, (l->>'quantity')::int);
    update public.products set stock = stock - (l->>'quantity')::int, sold_count = sold_count + (l->>'quantity')::int
      where id = (l->>'product_id')::uuid;
  end loop;
  if q->>'coupon' is not null then
    insert into public.coupon_usage (coupon_id, user_id, order_id)
    select id, p_user, v_order.id from public.coupons where code = q->>'coupon';
  end if;
  delete from public.cart_items where cart_id in (select id from public.carts where user_id = p_user);
  return jsonb_build_object('id', v_order.id, 'order_number', v_order.order_number, 'total', v_order.total);
end $$;

create or replace function public.cancel_order(p_order uuid, p_user uuid, p_as_admin boolean default false)
returns void language plpgsql security definer set search_path = public as $$
declare o public.orders%rowtype;
begin
  select * into o from public.orders where id = p_order for update;
  if not found or (not p_as_admin and o.user_id <> p_user) then raise exception 'ORDER_NOT_FOUND'; end if;
  if o.status not in ('pending','confirmed') and not (p_as_admin and o.status = 'processing') then
    raise exception 'NOT_CANCELLABLE: % orders can no longer be cancelled', o.status;
  end if;
  update public.products p set stock = p.stock + oi.quantity, sold_count = greatest(p.sold_count - oi.quantity, 0)
    from public.order_items oi where oi.order_id = o.id and oi.product_id = p.id;
  update public.orders set status = 'cancelled', payment_status = case when payment_status = 'paid' then 'refunded' else payment_status end
    where id = o.id;
  delete from public.coupon_usage where order_id = o.id;
end $$;

-- Dashboard numbers for the admin overview.
create or replace function public.admin_stats() returns jsonb
language sql stable security definer set search_path = public as $$
  select jsonb_build_object(
    'total_sales', coalesce((select sum(total) from orders where status <> 'cancelled'), 0),
    'total_orders', (select count(*) from orders),
    'pending_orders', (select count(*) from orders where status = 'pending'),
    'total_products', (select count(*) from products),
    'total_customers', (select count(*) from profiles where role = 'customer'),
    'sales_by_day', coalesce((select jsonb_agg(jsonb_build_object('day', d, 'total', t) order by d) from (
        select g::date as d, coalesce((select sum(total) from orders o where o.created_at::date = g::date and o.status <> 'cancelled'), 0) as t
        from generate_series(current_date - 29, current_date, '1 day') g) s), '[]'::jsonb),
    'best_sellers', coalesce((select jsonb_agg(x) from (select name, slug, sold_count from products order by sold_count desc limit 5) x), '[]'::jsonb)
  );
$$;

-- These functions take a user id as a parameter, so only the PHP API (service role) may call them.
revoke all on function public.quote_cart(jsonb, text, uuid) from public, anon, authenticated;
revoke all on function public.place_order(uuid, jsonb, text, jsonb, text) from public, anon, authenticated;
revoke all on function public.cancel_order(uuid, uuid, boolean) from public, anon, authenticated;
revoke all on function public.admin_stats() from public, anon, authenticated;

-- ───────────── Row Level Security ─────────────
alter table public.profiles enable row level security;
alter table public.categories enable row level security;
alter table public.products enable row level security;
alter table public.product_images enable row level security;
alter table public.carts enable row level security;
alter table public.cart_items enable row level security;
alter table public.wishlists enable row level security;
alter table public.wishlist_items enable row level security;
alter table public.addresses enable row level security;
alter table public.coupons enable row level security;
alter table public.orders enable row level security;
alter table public.order_items enable row level security;
alter table public.coupon_usage enable row level security;
alter table public.reviews enable row level security;
alter table public.newsletter_subscribers enable row level security;
alter table public.store_settings enable row level security;

-- Public catalogue
create policy "categories readable" on public.categories for select using (true);
create policy "published products readable" on public.products for select using (is_published or public.is_admin());
create policy "images of visible products readable" on public.product_images for select
  using (exists (select 1 from public.products p where p.id = product_id and (p.is_published or public.is_admin())));
create policy "reviews readable" on public.reviews for select using (true);
create policy "settings readable" on public.store_settings for select using (true);
create policy "anyone can subscribe" on public.newsletter_subscribers for insert with check (true);

-- Customer-owned data
create policy "own profile read" on public.profiles for select using (id = auth.uid() or public.is_admin());
create policy "own profile update" on public.profiles for update using (id = auth.uid())
  with check (id = auth.uid() and role = (select role from public.profiles where id = auth.uid()));  -- no self-promotion
create policy "own cart" on public.carts for all using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy "own cart items" on public.cart_items for all
  using (exists (select 1 from public.carts c where c.id = cart_id and c.user_id = auth.uid()))
  with check (exists (select 1 from public.carts c where c.id = cart_id and c.user_id = auth.uid()));
create policy "own wishlist" on public.wishlists for all using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy "own wishlist items" on public.wishlist_items for all
  using (exists (select 1 from public.wishlists w where w.id = wishlist_id and w.user_id = auth.uid()))
  with check (exists (select 1 from public.wishlists w where w.id = wishlist_id and w.user_id = auth.uid()));
create policy "own addresses" on public.addresses for all using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy "own orders read" on public.orders for select using (user_id = auth.uid() or public.is_admin());
create policy "own order items read" on public.order_items for select
  using (exists (select 1 from public.orders o where o.id = order_id and (o.user_id = auth.uid() or public.is_admin())));
create policy "own reviews write" on public.reviews for insert with check (user_id = auth.uid());
create policy "own reviews delete" on public.reviews for delete using (user_id = auth.uid() or public.is_admin());

-- Admin (writes to catalogue, coupons, settings, order status…)
create policy "admin products" on public.products for all using (public.is_admin()) with check (public.is_admin());
create policy "admin images" on public.product_images for all using (public.is_admin()) with check (public.is_admin());
create policy "admin categories" on public.categories for all using (public.is_admin()) with check (public.is_admin());
create policy "admin coupons" on public.coupons for all using (public.is_admin()) with check (public.is_admin());
create policy "admin settings" on public.store_settings for all using (public.is_admin()) with check (public.is_admin());
create policy "admin orders update" on public.orders for update using (public.is_admin()) with check (public.is_admin());
create policy "admin newsletter read" on public.newsletter_subscribers for select using (public.is_admin());
create policy "admin coupon usage read" on public.coupon_usage for select using (public.is_admin());
-- (orders/order_items/coupon_usage inserts happen only through place_order(), never directly by clients)

-- ───────────── Storage buckets (public read; writes only via the PHP API's service role) ─────────────
insert into storage.buckets (id, name, public) values ('product-images','product-images', true), ('avatars','avatars', true)
on conflict (id) do nothing;
