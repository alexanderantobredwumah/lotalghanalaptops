-- =====================================================================
--  LOTAL GHANA — PHP application schema (PostgreSQL / Supabase)
--
--  Applied automatically by visiting /install on a fresh database, or by hand:
--      psql "$DATABASE_URL" -f schema.sql
--
--  Deliberately free of functions and triggers so the file can also be applied
--  statement-by-statement by the built-in installer.
--  The Supabase hardening layer (RLS, Realtime, Storage, Auth sync) lives in
--  ../supabase/schema.sql and is optional for the PHP app.
-- =====================================================================

create extension if not exists pgcrypto;

-- ---------------------------------------------------------------- users
create table if not exists users (
  id            uuid primary key default gen_random_uuid(),
  email         text not null unique,
  full_name     text not null,
  phone         text not null default '',
  city          text not null default '',
  password_hash text not null default '',
  role          text not null default 'customer',
  created_at    timestamptz not null default now(),
  constraint users_role_check check (role in ('customer', 'admin'))
);
create index if not exists users_email_idx on users (lower(email));
create index if not exists users_role_idx on users (role);

-- -------------------------------------------------- sessions (serverless-safe)
create table if not exists php_sessions (
  token      text primary key,
  user_id    uuid references users (id) on delete set null,
  data       jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  expires_at timestamptz not null
);
create index if not exists php_sessions_expiry_idx on php_sessions (expires_at);

-- ------------------------------------------------------- password recovery
create table if not exists password_reset_tokens (
  id         uuid primary key default gen_random_uuid(),
  user_id    uuid not null references users (id) on delete cascade,
  token_hash text not null,
  expires_at timestamptz not null,
  used_at    timestamptz,
  created_at timestamptz not null default now()
);
create index if not exists password_reset_hash_idx on password_reset_tokens (token_hash);

-- ---------------------------------------------------------------- products
create table if not exists products (
  id             uuid primary key default gen_random_uuid(),
  slug           text not null unique,
  brand          text not null,
  name           text not null,
  tagline        text not null default '',
  price          integer not null,
  old_price      integer,
  stock          integer not null default 1,
  grade          text not null default 'A',
  grade_note     text not null default '',
  processor      text not null,
  ram            text not null,
  storage        text not null,
  screen         text not null,
  graphics       text not null default 'Integrated',
  battery_health integer not null default 90,
  os             text not null,
  ports          text not null default '',
  weight         text not null default '',
  image          text not null,
  gallery        jsonb not null default '[]'::jsonb,
  highlights     jsonb not null default '[]'::jsonb,
  featured       boolean not null default false,
  created_at     timestamptz not null default now(),
  constraint products_price_check check (price >= 0),
  constraint products_stock_check check (stock >= 0),
  constraint products_battery_check check (battery_health between 0 and 100)
);
create index if not exists products_brand_idx on products (brand);
create index if not exists products_featured_idx on products (featured);
create index if not exists products_price_idx on products (price);

-- ----------------------------------------------------------------- orders
create table if not exists orders (
  id                uuid primary key default gen_random_uuid(),
  code              text not null unique,
  user_id           uuid references users (id) on delete set null,
  status            text not null default 'placed',
  subtotal          integer not null,
  delivery          integer not null default 0,
  total             integer not null,
  payment_method    text not null default 'paystack',
  payment_reference text not null default '',
  full_name         text not null,
  phone             text not null,
  email             text not null,
  city              text not null,
  address           text not null,
  notes             text not null default '',
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  constraint orders_status_check check (status in
    ('placed', 'paid', 'packing', 'dispatched', 'out_for_delivery', 'delivered'))
);
create index if not exists orders_code_idx on orders (code);
create index if not exists orders_user_idx on orders (user_id);
create index if not exists orders_status_idx on orders (status);

create table if not exists order_items (
  id         uuid primary key default gen_random_uuid(),
  order_id   uuid not null references orders (id) on delete cascade,
  product_id uuid references products (id) on delete set null,
  title      text not null,
  brand      text not null default '',
  image      text not null default '',
  unit_price integer not null,
  qty        integer not null default 1
);
create index if not exists order_items_order_idx on order_items (order_id);

-- tracking timeline — one row per status change, shown on the public track page
create table if not exists order_events (
  id         uuid primary key default gen_random_uuid(),
  order_id   uuid not null references orders (id) on delete cascade,
  status     text not null,
  title      text not null,
  note       text not null default '',
  created_at timestamptz not null default now()
);
create index if not exists order_events_order_idx on order_events (order_id);

-- ------------------------------------------------------------ testimonials
create table if not exists testimonials (
  id         uuid primary key default gen_random_uuid(),
  name       text not null,
  role       text not null default '',
  city       text not null default '',
  quote      text not null,
  rating     integer not null default 5,
  created_at timestamptz not null default now(),
  constraint testimonials_rating_check check (rating between 1 and 5)
);

-- ------------------------------------------------------------- audit trail
create table if not exists admin_audit (
  id         uuid primary key default gen_random_uuid(),
  admin_id   uuid references users (id) on delete set null,
  action     text not null,
  target     text not null default '',
  detail     text not null default '',
  ip         text not null default '',
  created_at timestamptz not null default now()
);

-- =====================================================================
--  Seed data
-- =====================================================================

insert into products
  (slug, brand, name, tagline, price, old_price, stock, grade, grade_note, processor, ram, storage,
   screen, graphics, battery_health, os, ports, weight, image, highlights, featured)
values
 ('macbook-pro-14-m1-pro','Apple','MacBook Pro 14" M1 Pro','2021 · Space Grey · 10-core CPU / 16-core GPU',1145000,1320000,3,'A',
  'Light lid scuff on the left edge. Screen flawless, keyboard barely used.','Apple M1 Pro · 10-core CPU','16GB Unified Memory','512GB SSD',
  '14.2" Liquid Retina XDR · 3024×1964 · 120Hz','16-core GPU',94,'macOS Sequoia','3× Thunderbolt 4, HDMI, SDXC, MagSafe 3','1.60 kg',
  '/assets/img/laptop-macbook.jpg','["XDR display — 1000 nits sustained","Cycle count 187 of 1000","Original 67W USB-C charger included"]',true),
 ('macbook-air-m1','Apple','MacBook Air 13" M1','2020 · Gold · Fanless, silent in class',690000,790000,5,'A-',
  'Two faint palm-rest marks, invisible in normal light.','Apple M1 · 8-core CPU','8GB Unified Memory','256GB SSD',
  '13.3" Retina · 2560×1600 · P3','8-core GPU',91,'macOS Sequoia','2× Thunderbolt / USB 4, 3.5mm','1.29 kg',
  '/assets/img/laptop-macbook-gold.jpg','["All-day battery — 14 hours tested","Silent fanless design","Touch ID"]',true),
 ('dell-xps-13-9310','Dell','XPS 13 9310','2021 · Platinum Silver · InfinityEdge',625000,715000,2,'A',
  'Near mint. Tiny paint wear on the front lip only.','Intel Core i7-1165G7 · 11th Gen','16GB LPDDR4x','512GB NVMe SSD',
  '13.4" FHD+ InfinityEdge · 1920×1200','Intel Iris Xe',89,'Windows 11 Pro','2× Thunderbolt 4, microSD, 3.5mm','1.20 kg',
  '/assets/img/laptop-silver.jpg','["CNC machined aluminium body","Backlit keyboard","Windows Hello IR camera"]',true),
 ('thinkpad-x1-carbon-gen9','Lenovo','ThinkPad X1 Carbon Gen 9','2021 · Black · The business standard',645000,760000,2,'A-',
  'Soft-touch lid shows faint sheen from a sleeve. Hinges tight.','Intel Core i7-1165G7 · 11th Gen','16GB LPDDR4x','1TB NVMe SSD',
  '14" WUXGA IPS · 1920×1200 · 400 nits','Intel Iris Xe',92,'Windows 11 Pro','2× Thunderbolt 4, 2× USB-A, HDMI 2.0','1.13 kg',
  '/assets/img/laptop-black.jpg','["MIL-SPEC tested chassis","Best-in-class keyboard","Fingerprint reader"]',true),
 ('hp-spectre-x360-14','HP','Spectre x360 14','2021 · Nightfall Black · 2-in-1 convertible',590000,680000,1,'A',
  'Gem-cut edges unmarked. Includes HP Tilt Pen.','Intel Core i7-1165G7 · 11th Gen','16GB LPDDR4x','1TB NVMe SSD',
  '13.5" OLED 3K2K touch · 3000×2000','Intel Iris Xe',88,'Windows 11 Home','2× Thunderbolt 4, USB-A, microSD','1.34 kg',
  '/assets/img/laptop-convertible.jpg','["OLED touch display","360° hinge, tablet mode","Quad speakers by Bang & Olufsen"]',false),
 ('surface-laptop-4','Microsoft','Surface Laptop 4 13.5','2021 · Platinum · Alcantara keyboard',560000,640000,2,'B+',
  'Light scratches on the lid, screen and keyboard perfect.','Intel Core i5-1135G7 · 11th Gen','8GB LPDDR4x','256GB SSD',
  '13.5" PixelSense touch · 2256×1504 · 3:2','Intel Iris Xe',86,'Windows 11 Pro','USB-C, USB-A, Surface Connect','1.27 kg',
  '/assets/img/laptop-silver.jpg','["3:2 touch display","Alcantara fabric deck","Windows Hello face sign-in"]',false),
 ('hp-elitebook-840-g7','HP','EliteBook 840 G7','2020 · Silver · Corporate fleet unit',375000,440000,4,'B+',
  'Fleet unit with a small sticker residue patch on the lid base.','Intel Core i5-10210U · 10th Gen','16GB DDR4','512GB NVMe SSD',
  '14" FHD IPS · 1920×1080 · 250 nits','Intel UHD',84,'Windows 11 Pro','2× USB-A, USB-C, HDMI 1.4','1.32 kg',
  '/assets/img/laptop-silver.jpg','["Bang & Olufsen audio","Sure Start BIOS protection","Spill-resistant keyboard"]',false),
 ('dell-latitude-5420','Dell','Latitude 5420','2021 · Grey · Reliable daily driver',395000,465000,3,'A-',
  'Clean chassis. Only the charging port shows normal use.','Intel Core i5-1145G7 · 11th Gen','16GB DDR4','512GB NVMe SSD',
  '14" FHD IPS · 1920×1080','Intel Iris Xe',90,'Windows 11 Pro','2× Thunderbolt 4, HDMI, USB-A, RJ-45','1.40 kg',
  '/assets/img/laptop-black.jpg','["ExpressCharge — 80% in one hour","Thunderbolt dock support","Carbon fibre palm rest"]',false),
 ('thinkpad-t14-gen2','Lenovo','ThinkPad T14 Gen 2','2021 · Black · Workhorse with a great keyboard',420000,495000,2,'A-',
  'Minor shine on the trackpad. Battery replaced this year.','Intel Core i5-1145G7 · 11th Gen','16GB DDR4 (upgradable)','512GB NVMe SSD',
  '14" FHD IPS · 1920×1080 · 300 nits','Intel Iris Xe',96,'Windows 11 Pro','2× Thunderbolt 4, 2× USB-A, HDMI 2.0','1.42 kg',
  '/assets/img/laptop-black.jpg','["New battery fitted","Backlit keyboard","dTPM 2.0 security chip"]',false),
 ('asus-zenbook-14-oled','ASUS','ZenBook 14 OLED','2022 · Ponder Blue · OLED panel',510000,590000,1,'A',
  'Screen tested with no burn-in. Chassis unmarked.','AMD Ryzen 7 5825U','16GB LPDDR4x','512GB NVMe SSD',
  '14" 2.8K OLED · 2880×1800 · 90Hz','AMD Radeon',93,'Windows 11 Home','USB-C, USB-A, HDMI 2.0, microSD','1.39 kg',
  '/assets/img/laptop-convertible.jpg','["2.8K OLED, 100% DCI-P3","NumberPad 2.0 trackpad","US military-grade durability"]',false),
 ('acer-aspire-5-a515','Acer','Aspire 5 A515-57','2022 · Charcoal · Best value for school',285000,335000,6,'B+',
  'Small scuff on the base cover. Everything works as it should.','Intel Core i5-1240P · 12th Gen','8GB DDR4 (upgradable)','512GB NVMe SSD',
  '15.6" FHD IPS · 1920×1080','Intel Iris Xe',87,'Windows 11 Home','USB-C, 2× USB-A, HDMI 2.0','1.76 kg',
  '/assets/img/laptop-silver.jpg','["Upgradeable RAM + second SSD slot","Full-size keyboard with numpad","Acer PurifiedVoice"]',false),
 ('macbook-pro-13-m1','Apple','MacBook Pro 13" M1','2020 · Space Grey · Touch Bar',745000,845000,2,'B+',
  'Hairline mark on the lid, Retina display perfect. Cycle count 342.','Apple M1 · 8-core CPU','8GB Unified Memory','512GB SSD',
  '13.3" Retina · 2560×1600 · P3','8-core GPU',85,'macOS Sequoia','2× Thunderbolt / USB 4, 3.5mm','1.40 kg',
  '/assets/img/laptop-macbook.jpg','["Touch Bar with Touch ID","Active cooling for sustained load","Studio-quality triple mic array"]',false)
on conflict (slug) do nothing;

insert into testimonials (name, role, city, quote, rating) values
 ('Nana Ama Serwaa','Level 300 student, KNUST','Kumasi',
  'I ordered the MacBook Air on Tuesday and it was in Kumasi on Thursday. The battery health on the site matched what I got — 91% exactly. That honesty is rare here.',5),
 ('Kwesi Boateng','Graphic designer','Accra',
  'The diagnostics report they send before delivery saved me. I compared the cycle count, tested the ports, and the machine has been flawless for months now.',5),
 ('Yaw Mensah','Startup founder','Tema',
  'Bought three Latitude 5420s for my team on mobile money. Tracking codes for each one, invoices, and the warranty. This is how used laptops should be sold.',5),
 ('Efua Danquah','Accountant','Takoradi',
  'I was scammed before buying a laptop on social media, so I was nervous. LOTAL let me pay on delivery and the machine was exactly as graded. Very professional.',5)
on conflict do nothing;
