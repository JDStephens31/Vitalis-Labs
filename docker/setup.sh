#!/bin/sh
# ============================================================================
# Vitalis Labs — one-shot WordPress + WooCommerce provisioning
#
# Run once (after `docker compose up -d`) with:
#   docker compose run --rm wpcli sh /setup.sh
#
# Or against a real host over SSH (see DEPLOY-CPANEL.md), where WordPress lives
# somewhere else and the seed images come from the repo instead of a bind mount:
#   WP_PATH=$HOME/public_html \
#   SEED_DIR=$HOME/repositories/Vitalis-Labs/assets \
#   WP_URL=https://vitalislabs.com sh docker/setup.sh
#
# Idempotent: safe to re-run. It will skip anything already done.
# ============================================================================
set -e

# In Docker, wp-cli runs as www-data (uid 33) which has no home dir, so point
# HOME at a writable location for its cache and `wp media import` staging. On a
# real host the user already has a home dir — keep it.
if [ ! -w "${HOME:-/nonexistent}" ]; then
  export HOME=/tmp
fi
export WP_CLI_CACHE_DIR="${WP_CLI_CACHE_DIR:-$HOME/.wp-cli-cache}"

# Where WordPress is installed, and where the catalog images come from. The
# defaults are the Docker layout; override both when running on real hosting.
WP_PATH="${WP_PATH:-/var/www/html}"
SEED_DIR="${SEED_DIR:-/seed-assets}"

WP="wp --path=$WP_PATH"

echo "==> Waiting for the database + WordPress files to be ready..."
i=0
until $WP db query "SELECT 1" >/dev/null 2>&1; do
  i=$((i+1))
  if [ "$i" -gt 60 ]; then echo "!! Timed out waiting for the database."; exit 1; fi
  sleep 2
done

# ── 1. Install WordPress core ────────────────────────────────────────────────
if $WP core is-installed >/dev/null 2>&1; then
  echo "==> WordPress already installed."
else
  echo "==> Installing WordPress core..."
  $WP core install \
    --url="${WP_URL:-http://localhost:8080}" \
    --title="${WP_TITLE:-Vitalis Labs}" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASSWORD:-vitalis-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
    --skip-email
fi

# General site tweaks
$WP option update blogdescription "Research-grade peptides · Made & tested in the USA"
$WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true

# ── 2. WooCommerce ───────────────────────────────────────────────────────────
if $WP plugin is-installed woocommerce >/dev/null 2>&1; then
  echo "==> WooCommerce already installed."
else
  echo "==> Installing WooCommerce..."
  $WP plugin install woocommerce --activate
fi
$WP plugin activate woocommerce >/dev/null 2>&1 || true

# Store address / currency (USA, USD). Skips the setup wizard.
$WP option update woocommerce_store_address    "Research Blvd"
$WP option update woocommerce_store_city        "Austin"
$WP option update woocommerce_default_country   "US:TX"
$WP option update woocommerce_store_postcode    "78701"
$WP option update woocommerce_currency          "USD"
$WP option update woocommerce_currency_pos      "left"
$WP option update woocommerce_price_num_decimals "2"
$WP option update woocommerce_allow_tracking    "no"
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1 || true
# Mark WooCommerce onboarding as complete so the wizard doesn't nag.
$WP option update woocommerce_task_list_hidden 'yes' >/dev/null 2>&1 || true
$WP option update woocommerce_task_list_complete 'yes' >/dev/null 2>&1 || true

# Manage stock at the store level so quantities show live like the old site.
$WP option update woocommerce_manage_stock "yes"

# Newer WooCommerce ships "Coming soon" mode ON by default, which hides the
# shop/cart/checkout/account pages behind a placeholder. Turn it off so the
# store is live.
$WP option update woocommerce_coming_soon "no" >/dev/null 2>&1 || true
$WP option update woocommerce_store_pages_only "no" >/dev/null 2>&1 || true

# ── 3. Offline / manual payment gateways ─────────────────────────────────────
# Enable WooCommerce's three built-in offline methods. Every order placed with
# these sits as "on-hold" (bank transfer / cheque) or "processing" (COD) until
# you confirm payment and update the status yourself — exactly the manual flow
# you asked for. No card processor, no fees.
echo "==> Enabling offline payment methods..."

# Direct bank transfer (BACS)
$WP option patch update woocommerce_bacs_settings enabled yes    >/dev/null 2>&1 || \
  $WP option update woocommerce_bacs_settings '{"enabled":"yes","title":"Direct bank transfer","description":"Make your payment directly into our bank account. Please use your Order ID as the payment reference. Your order will not ship until the funds have cleared.","instructions":"Please use your Order ID as the payment reference. Your order will be held until payment is received."}' --format=json
$WP option patch update woocommerce_bacs_settings title "Direct bank transfer" >/dev/null 2>&1 || true

# Cheque payments
$WP option patch update woocommerce_cheque_settings enabled yes  >/dev/null 2>&1 || \
  $WP option update woocommerce_cheque_settings '{"enabled":"yes","title":"Check payment","description":"Please send a check to our store address. Your order will be held until it arrives and clears.","instructions":"Mail your check to the store address. Include your Order ID."}' --format=json

# Cash on delivery
$WP option patch update woocommerce_cod_settings enabled yes     >/dev/null 2>&1 || \
  $WP option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","description":"Pay with cash upon delivery.","instructions":"Pay with cash upon delivery.","enable_for_methods":[],"enable_for_virtual":"no"}' --format=json

# ── 4. Theme ─────────────────────────────────────────────────────────────────
if $WP theme is-installed vitalis >/dev/null 2>&1; then
  echo "==> Activating Vitalis theme..."
  $WP theme activate vitalis
else
  echo "!! Vitalis theme not found in wp-content/themes/vitalis — skipping activation."
fi

# ── 5. Catalog images → media library ────────────────────────────────────────
# Import the vial PNGs from $SEED_DIR (./assets, bind-mounted in Docker) and remember
# the attachment IDs so we can attach them to products.
import_img() {
  # $1 = filename in $SEED_DIR ; echoes the attachment ID.
  # Idempotent: media import stores the title without the extension, so we
  # dedup against that to avoid re-importing on every run.
  file="$SEED_DIR/$1"
  [ -f "$file" ] || { echo ""; return; }
  title=$(echo "$1" | sed 's/\.[^.]*$//')
  existing=$($WP post list --post_type=attachment --title="$title" --field=ID --format=ids 2>/dev/null | awk '{print $1}')
  if [ -n "$existing" ]; then echo "$existing"; return; fi
  $WP media import "$file" --porcelain 2>/dev/null
}

echo "==> Importing catalog images..."
BPC_IMG=$(import_img "bpc-157.png")
GHK_IMG=$(import_img "ghk-cu.png")
RETA10_IMG=$(import_img "reta-10.png")
RETA30_IMG=$(import_img "reta-30.png")
LOGO_IMG=$(import_img "long_logo.png")

# Set the site logo (custom-logo theme mod) if we have it.
[ -n "$LOGO_IMG" ] && $WP theme mod set custom_logo "$LOGO_IMG" >/dev/null 2>&1 || true

# ── 6. Products ──────────────────────────────────────────────────────────────
# Create the four catalog items (idempotent by SKU). Prices/stock mirror the
# old Supabase seed. Edit freely in wp-admin afterwards.
make_product() {
  # $1 sku  $2 name  $3 price  $4 stock  $5 img_id  $6 short_desc  $7 desc
  sku="$1"
  existing=$($WP wc product list --sku="$sku" --field=id --user="${WP_ADMIN_USER:-admin}" 2>/dev/null | head -1)
  if [ -n "$existing" ]; then
    echo "    - $2 already exists (id $existing), skipping."
    return
  fi
  imgarg=""
  [ -n "$5" ] && imgarg="--image_id=$5"
  id=$($WP wc product create \
    --name="$2" \
    --sku="$sku" \
    --type=simple \
    --regular_price="$3" \
    --manage_stock=true \
    --stock_quantity="$4" \
    --status=publish \
    --short_description="$6" \
    --description="$7" \
    --user="${WP_ADMIN_USER:-admin}" \
    --porcelain 2>/dev/null) || true
  # Attach the featured image via _thumbnail_id (the reliable path — the WC CLI
  # --image_id flag does not set the product image).
  if [ -n "$id" ] && [ -n "$5" ]; then
    $WP post meta update "$id" _thumbnail_id "$5" >/dev/null 2>&1 || true
  fi
  echo "    - created $2 (id ${id:-?})"
}

echo "==> Creating products..."
DISCLAIMER="<p><strong>RESEARCH USE ONLY — NOT FOR HUMAN CONSUMPTION.</strong> For laboratory research and development only.</p>"
make_product "VL-BPC-10"  "BPC-157 10mg"   "100" "25" "$BPC_IMG"    "15 Amino-Acid Synthetic · 10 mg · 99.9% purity" "$DISCLAIMER<p>BPC-157, a 15 amino-acid synthetic research peptide. Lyophilized, 99.9% purity.</p>"
make_product "VL-GHK-100" "GHK-Cu 100mg"   "100" "25" "$GHK_IMG"    "Copper Tripeptide-1 · 100 mg · 99.9% purity" "$DISCLAIMER<p>GHK-Cu (Copper Tripeptide-1) research peptide. Lyophilized, 99.9% purity.</p>"
make_product "VL-RETA-10" "Retatrutide 10mg" "100" "25" "$RETA10_IMG" "Triple-Agonist Research Compound · 10 mg · 99.9% purity" "$DISCLAIMER<p>Retatrutide, triple-agonist research compound. Lyophilized, 99.9% purity.</p>"
make_product "VL-RETA-30" "Retatrutide 30mg" "200" "25" "$RETA30_IMG" "Triple-Agonist Research Compound · 30 mg · 99.9% purity" "$DISCLAIMER<p>Retatrutide, triple-agonist research compound. Lyophilized, 99.9% purity.</p>"

# ── Product display meta (cap glow color, dose, purity, subtitle) ────────────
# The Vitalis catalog card reads these to render the dose badge, purity line,
# and the cap-colored glow behind each vial — matching the original site.
set_vmeta() {
  # $1 sku  $2 cap  $3 dose  $4 purity  $5 sub
  pid=$($WP wc product list --sku="$1" --field=id --user="${WP_ADMIN_USER:-admin}" 2>/dev/null | head -1)
  [ -z "$pid" ] && return
  $WP post meta update "$pid" _vitalis_cap    "$2" >/dev/null 2>&1 || true
  $WP post meta update "$pid" _vitalis_dose   "$3" >/dev/null 2>&1 || true
  $WP post meta update "$pid" _vitalis_purity "$4" >/dev/null 2>&1 || true
  $WP post meta update "$pid" _vitalis_sub    "$5" >/dev/null 2>&1 || true
}
echo "==> Setting catalog display meta..."
set_vmeta "VL-BPC-10"  "#37a24a" "10 mg"  "99.9%" "15 Amino-Acid Synthetic"
set_vmeta "VL-GHK-100" "#8b5cf6" "100 mg" "99.9%" "Copper Tripeptide-1"
set_vmeta "VL-RETA-10" "#e8622a" "10 mg"  "99.9%" "Triple-Agonist Research Compound"
set_vmeta "VL-RETA-30" "#e8622a" "30 mg"  "99.9%" "Triple-Agonist Research Compound"

echo ""
echo "============================================================"
echo " Vitalis Labs store is ready."
echo "   Storefront : ${WP_URL:-http://localhost:8080}/shop/"
echo "   Admin      : ${WP_URL:-http://localhost:8080}/wp-admin/"
echo "   Orders     : wp-admin → WooCommerce → Orders"
echo "   Login      : ${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASSWORD:-vitalis-admin}"
echo "============================================================"
