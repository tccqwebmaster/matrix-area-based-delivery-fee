# Changelog

All notable changes to Matrix Area Based Delivery Fee Customizer will be documented in this file.

## [2.1.2] - 2026-07-22

### Fixed
- **CSV export downloaded the whole admin page instead of the CSV.** "Export
  Current Areas" produced a `delivery-areas-YYYY-MM-DD.csv` whose contents were
  the entire WordPress admin HTML page (`<!DOCTYPE html>…`) with the CSV rows
  buried at the very end. Cause: the export was triggered from inside
  `settings_page()` — the menu page's render callback — which only runs *after*
  WordPress has already output the full admin page, so the CSV was appended to
  all of that HTML. Moved the export to run early on `admin_init`
  (`maybe_export_csv()`), before any output, then `exit`; hardened
  `export_to_csv()` to discard any buffered output before sending the headers
  and to `exit` when done. The download is now the CSV alone (UTF-8 BOM intact
  for Arabic). Capability + nonce checks unchanged.

Both fixes found by testing 2.1.0 on staging in a real logged-in session.

### Fixed
- **The dropdown never appeared** (2.1.0 shipped it, nothing changed on the
  cart). Porto overrides `cart/shipping-calculator.php` with hardcoded
  `<input>` markup, so `woocommerce_form_field()` — and every server-side
  filter on it — never runs for this field. The dropdown is now built in
  `assets/js/cart-calculator.js` from options passed via `wp_localize_script`,
  which leaves Porto's template alone and survives theme updates. The
  `woocommerce_form_field_args` filter is kept for stock-template stores.
- **Returning customers were quoted the wrong delivery fee.** The fee is keyed
  on billing_city while the calculator writes the SHIPPING city, so a customer
  with a stored billing city kept that area's fee no matter which area they
  chose. Reproduced on staging: an account billing to Bani Hajer was quoted
  its 25 QR while the cart read "Shipping to Al Aziziya" — an area configured
  free. It could over- or under-charge. `mirror_area_to_billing()` now always
  copies a valid chosen area onto billing_city (2.1.0 only did so when billing
  was empty), so the customer's explicit choice wins and checkout opens on the
  same area.

## [2.1.0] - 2026-07-19

### Added
- **The cart's "Calculate shipping" now offers the Delivery Area dropdown.**
  It was a free-text city box: customers had to know an area's exact spelling,
  and a typo silently produced free shipping. It now lists the same 73
  configured areas as checkout, via the `woocommerce_form_field_args` filter
  (WooCommerce builds that field with `woocommerce_form_field()`, so no
  template override is needed).
- **Picking an area updates the totals immediately** — no second click on
  "Update totals". `assets/js/cart-calculator.js` submits the calculator on
  change, posting `calc_shipping` as a hidden field because a scripted
  `form.submit()` does not carry the submit button's value.

### Fixed
- `get_selected_area()` now falls back to the shipping city and to a posted
  `calc_shipping_city`, and `Matrix_Cart_Calculator::mirror_area_to_billing()`
  copies a calculator-chosen area onto billing_city when billing is empty.
  Both are guards for stores WITHOUT "force shipping to the customer billing
  address": there the calculator writes the shipping address only, and the fee
  — which is keyed on billing_city — would come out free. No effect on
  tccq.com, which has that setting enabled.

## [2.0.3] - 2026-07-18

### Fixed
- **Zero-fee areas showed a blank amount at checkout.** WooCommerce prints only
  the label for a zero-cost rate, so an area with a 0 fee rendered as
  "Delivery Fee" with an empty price and looked like the calculation had
  failed. It now reads "Delivery Fee: FREE" (Arabic: مجاناً). The wording only
  appears once an area is selected — before that, a 0 cost still means "not
  calculated yet", so nothing is added.

## [2.0.2] - 2026-07-16

### Fixed
- **Arabic pages showed English strings.** "Select Delivery Area" (dropdown
  placeholder), the invalid-area validation message, and the "Delivery Fee"
  shipping rate label now render in Arabic on `ar_*` locales (اختر منطقة
  التوصيل / رسوم التوصيل). The bilingual field label was already correct.

## [2.0.1] - 2026-07-16

### Removed
- The nested `matrix-area-delivery-fee/` folder — an accidental full copy
  of the plugin committed with the initial import (a zip-extract artifact).
  It never loaded as a plugin (WordPress scans only one level deep) but it
  duplicated every file on deploy and was a stale-version trap. The repo
  root IS the plugin; build release zips from the root when needed.
  No plugin code changes.

## [2.0.0] - 2026-07-16

### Changed (architecture refactor — deploy together with TCC Qatar Custom 1.10.0)
- **Scope narrowed to shipping/delivery only.** This plugin now owns: the
  shipping method, shipping calculation, the Delivery Area dropdown
  (`billing_city`) and surfacing the area on orders. All other checkout
  field customisation (removed fields, required phone, field order) moved
  to the TCC Qatar Custom plugin — one owner per field, so AJAX refreshes
  can no longer produce conflicting field states.
- Refactored into classes with a modular file layout:
  - `includes/class-matrix-delivery-area.php` — area repository (public API:
    `get_areas()`, `get_area()`, `get_fee()`, `get_label()`, `get_options()`,
    `get_selected_area()`), the billing_city dropdown, the QA locale `city`
    entry (keeps the Delivery Area label stable across `address-i18n.js`
    re-applies), package-hash busting, and order surfacing.
  - `includes/class-matrix-shipping-method.php` — the WC_Shipping_Method
    subclass, loaded on `woocommerce_shipping_init` (no more anonymous
    class-in-closure).
  - `includes/class-matrix-admin.php` — unchanged admin UI.
- WooCommerce detection now uses `class_exists( 'WooCommerce' )` on
  `plugins_loaded` (multisite/mu-plugin safe) instead of the
  `active_plugins` option.
- Shipping rate label now respects the per-zone "Method title" setting.

### Added
- **Delivery Area everywhere:** saved to order meta (`_matrix_delivery_area`
  + bilingual `_matrix_delivery_area_label`, HPOS-safe CRUD) and shown on
  the order edit screen (under the billing address), as an orders-list
  column (HPOS + legacy tables), in order emails, on the customer's
  My Account order view, and exposed via the REST API as order `meta_data`.
- Server-side validation: checkout rejects a `billing_city` value that is
  not a configured delivery area.
- The Delivery Area dropdown now also appears on the My Account billing
  address form (hooked via `woocommerce_billing_fields`).

### Removed
- The CyberSource re-init footer script (checkout/payment concern) — moved
  to TCC Qatar Custom `includes/checkout/class-tcc-checkout-gateway-compat.php`.

## [1.3.0] - 2026-07 (retroactive entry)

### Changed
- Replaced the cart-fee approach (`woocommerce_cart_calculate_fees`) with a
  **real WooCommerce shipping method** (`matrix_area_delivery`) registered
  on `woocommerce_shipping_init`, auto-attached to the Qatar shipping zone
  on activation.
- Folded the selected area into the shipping package so WooCommerce busts
  its cached rates when the customer switches areas at checkout.

### Added
- CyberSource Unified Checkout re-init script after `updated_checkout`
  (moved out again in 2.0.0).

## [1.2.0] - 2026-02-08

### Added
- 📤 **CSV Export**: Download all delivery areas with fees as CSV file
- 📥 **CSV Upload Import**: Import delivery areas with fees from uploaded CSV file
- 📝 **Template Export**: Export current areas to use as template for editing in Excel
- 🔄 **Three Import Options**: Upload CSV, Default area.csv, or Backup restore
- ✅ **Excel Compatibility**: Export/Import works seamlessly with Microsoft Excel and Google Sheets
- 👌 **User-Friendly UI**: Color-coded sections (green=export, blue=upload, orange=default)
- 🔒 **File Validation**: Checks file type, format, and required columns
- 🎯 **UTF-8 BOM**: Proper encoding for Arabic text in Excel

### Improved
- CSV import UI reorganized with three distinct sections
- Better error messages for failed imports
- Automatic sanitization of imported data
- Fee preservation during import/export cycle

### Technical
- Added `export_to_csv()` method with proper headers and UTF-8 BOM
- Added `import_from_uploaded_csv()` method with file validation
- Enhanced security with file type checking and nonce verification
- Improved data handling for international characters

## [1.1.0] - 2026-02-08

### Changed
- 🔄 **Major Code Refactoring**: Renamed all code from `tccq` to `matrix` for consistency
- 📂 Folder structure: `tccq-checkout-customizer` → `matrix-area-delivery-fee`
- 📝 Main file: `tccq-checkout-customizer.php` → `matrix-area-delivery-fee.php`
- 🏛 Class names: `TCCQ_Checkout_Admin` → `Matrix_Area_Delivery_Admin`
- 📊 Database options: `tccq_delivery_areas` → `matrix_delivery_areas`
- 🎨 CSS classes: All `tccq-*` → `matrix-*`
- ⚙️ Function names: `tccqAddArea()` → `matrixAddArea()`
- 🔐 Nonce names: All security tokens updated to `matrix_*`
- 📌 Menu slugs: `tccq-checkout-customizer` → `matrix-area-delivery-fee`

### Improved
- Code consistency across all files
- Professional naming convention
- Better code maintainability
- Cleaner codebase structure

**Note**: This is a breaking change if you have existing data. Plugin will start fresh with default areas.

## [1.0.2] - 2026-02-08

### Added
- Row numbering system for better area management
- Total areas count badge in admin interface
- Drag & drop functionality for manual area reordering
- Sort A-Z button for alphabetical sorting
- Backup & Restore functionality for data protection
- Auto-update of row numbers when reordering
- CSV import with duplicate detection
- Delivery fee column as first column for better visibility
- Column width optimization for better UX
- Zero fee display at checkout (shows "Delivery Fee: QAR 0")

### Changed
- Plugin name from "TCCQ Checkout Customizer" to "Matrix Area Based Delivery Fee Customizer"
- Author name to Mugamathu Bathusha
- Admin menu title to "Delivery Areas"
- Column order: Fee first, then English, Arabic, Value
- Column widths optimized: # (5%), Fee (10%), English (23%), Arabic (23%), Value (29%), Action (10%)
- Enhanced admin interface with better visual hierarchy
- Text domain changed to 'matrix-area-delivery-fee'

### Fixed
- Delivery fee now displays even when set to 0
- Row count updates properly on add/delete/reorder
- WooCommerce HPOS compatibility declared

### Removed
- Matrix Conditional Payments Pro plugin (separate development)
- Long CSV filename in favor of simpler "area.csv"

## [1.0.1] - 2026-02-07

### Added
- Initial release with Qatar delivery areas
- Admin interface for area management
- Automatic shipping fee calculation
- CSV import functionality
- WooCommerce checkout integration

## [1.0.0] - 2026-02-06

### Added
- Project initialization
- Basic structure and Git setup
- WordPress plugin framework

---

**Developer:** Mugamathu Bathusha  
**License:** GPL v2 or later  
**LinkedIn:** https://www.linkedin.com/in/mugamathubathusha/
