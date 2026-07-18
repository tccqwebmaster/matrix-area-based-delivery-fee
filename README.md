# Matrix Area Based Delivery Fee Customizer

**Version:** 2.0.3  
**Author:** Mugamathu Bathusha  
**License:** GPL v2 or later

A professional WordPress plugin for managing WooCommerce area-based delivery fees with advanced features for Qatar and international markets.

---

## Scope & architecture (2.0.0)

This plugin is responsible **only** for shipping/delivery:

- A real WooCommerce **shipping method** (`matrix_area_delivery`, zone-aware,
  auto-attached to the Qatar zone) whose cost comes from the selected area
- The **Delivery Area dropdown** (the `billing_city` field — kept on
  billing_city for compatibility with WooCommerce addresses, shipping zones,
  Conditional Payments, HPOS and checkout AJAX)
- Surfacing the area on orders: order edit screen, orders-list column
  (HPOS + legacy), emails, My Account order view, and REST API order
  `meta_data` (`_matrix_delivery_area`, `_matrix_delivery_area_label`)

All other checkout field customisation (removing Company/State/Postcode,
required Phone, field order, labels) lives in the **TCC Qatar Custom**
plugin. One owner per field — that contract is what keeps the checkout
stable across AJAX refreshes. Do not add checkout-field code here.

```
matrix-area-delivery-fee.php            Bootstrap: constants, HPOS declare,
                                        shipping method registration, Qatar
                                        zone auto-setup
includes/class-matrix-delivery-area.php Area repository + dropdown + order
                                        meta/columns/emails/REST surfacing
includes/class-matrix-shipping-method.php  WC_Shipping_Method subclass
                                        (loaded on woocommerce_shipping_init)
includes/class-matrix-admin.php         Admin UI (areas, CSV, backup)
```

---

## Features

✅ Converts billing_city field to dropdown select  
✅ Admin interface to manage delivery areas  
✅ **Real WooCommerce shipping method — fee calculated from the selected delivery area**  
✅ **Delivery Area shown on orders list, order edit, emails, My Account & REST API**  
✅ **CSV Export - Download all areas with fees**  
✅ **CSV Import - Upload your own CSV files with fees**  
✅ **Excel Compatible - Edit in Excel, save as CSV, upload**  
✅ **Drag & drop to reorder areas**  
✅ **Alphabetical sorting (A-Z)**  
✅ **Backup & Restore functionality**  
✅ Qatar delivery areas with Arabic translations  
✅ Compatible with WooCommerce Conditional Payments plugin  
✅ Add, edit, delete areas from WordPress admin  
✅ Set custom delivery fees for each area  
✅ No code editing required  
✅ Theme-independent

## Installation

1. Upload to `wp-content/plugins/matrix-area-delivery-fee/`
2. Activate from WordPress Admin → Plugins
3. Go to **Delivery Areas** menu to manage delivery areas

## Admin Management

After activation, you'll find a new menu: **Delivery Areas** 🏙️

From there you can:
- � **Export to CSV** - Download all areas with fees (opens in Excel)
- 📥 **Import from CSV** - Upload your edited CSV file with fees
- 📋 **Quick Import** - Load 554 default Qatar zones from area.csv
- 💾 **Create Backup** - Save current areas configuration
- 🔄 **Restore Backup** - Recover from accidental changes
- 🔤 **Sort A-Z** - Automatically sort areas alphabetically
- 🖱️ **Drag & Drop** - Manually reorder areas by dragging rows
- ➕ Add new delivery areas
- ✏️ Edit existing areas (English & Arabic names)
- 💰 Set delivery fees for each area
- ❌ Delete unwanted areas
- 🔄 Reset to default Qatar areas
- 💾 Save changes instantly

### CSV Export/Import Feature
The plugin includes a professional CSV export/import system:

**Export:**
- Download all current areas with fees as CSV
- Opens perfectly in Microsoft Excel and Google Sheets
- Includes UTF-8 BOM for proper Arabic text display
- Filename: `delivery-areas-YYYY-MM-DD.csv`

**Import:**
- Upload your own CSV file with custom areas and fees
- CSV Format: `Fee (QAR), Area Name (English), Area Name (Arabic), Value`
- Edit exported CSV in Excel, save, and re-upload
- Automatic validation and error checking

**Quick Import:**
- One-click import from included `area.csv` (554 Qatar zones)
- Fees set to 0 by default - edit manually after import

## Compatibility

- WooCommerce 5.0+ (tested up to 10.9, HPOS-compatible)
- WordPress 5.0+
- PHP 7.4+
- Works with Matrix Conditional Payments Pro plugin
- Works with the Porto theme and the TCC Qatar Custom plugin (checkout field
  ownership contract — see "Scope & architecture")

## Usage

### Managing Areas
1. Go to WordPress Admin → **Checkout Areas**
2. Add/Edit/Delete areas as needed
3. **Set shipping fee (QAR)** for each area
4. Click **Save Delivery Areas**

### Setting Shipping Fees
- Enter the delivery charge in QAR for each area
- Use `0` for free delivery areas  
- Fees are automatically added to cart at checkout
- Customer sees fee before completing purchase

**Example fee structure for Qatar:**
- QAR 0 (Free): Abu Hamour, Al Aziziya, Al Mansoura, Old Airport
- QAR 25: Al Duhail, West Bay  
- QAR 45: Lusail, Al Wakrah
- QAR 75: Al Khor
- QAR 100: Dukhan

### CSV Export/Import Workflow

**Perfect for bulk editing:**

1. **Export** your current areas:
   - Click "Download CSV Export" button
   - File opens in Excel/Google Sheets
   - Edit fees, names, or add new areas

2. **Edit in Excel:**
   - Keep the header row: `Fee (QAR), Area Name (English), Area Name (Arabic), Value`
   - Enter fees in column A (numbers only)
   - Enter English names in column B
   - Enter Arabic names in column C
   - Enter value (usually same as English) in column D
   - Save as CSV format

3. **Import back:**
   - Click "Choose File" under CSV Upload Import
   - Select your edited CSV file
   - Click "Upload & Import CSV"
   - All areas with fees are loaded instantly

**Quick Import from area.csv:**
- One-click import of 554 Qatar zones
- All fees set to 0 - edit manually after

### Backup & Restore
**Protect your data:**
- Click **Create Backup** before major changes
- Backup is stored in WordPress database
- **Restore Backup** button appears when backup exists
- One-click restore if something goes wrong
- Backups include all areas, names, fees, and order

### Reordering Areas
Two ways to organize your delivery areas:
1. **Drag & Drop**: Click and drag any row to reorder manually
2. **Sort A-Z**: Click button to automatically sort by English name alphabetically

The order you set determines how areas appear in the checkout dropdown.

## Default Areas Included

The plugin comes with 10 Qatar delivery areas pre-configured. You can modify, add more, or remove any as needed from the admin panel.

---

## Commercial Use & Licensing

This plugin is available under GPL v2 or later license. For commercial support, custom development, or white-label versions, please contact the developer.

**Developer:** Mugamathu Bathusha  
**LinkedIn:** https://www.linkedin.com/in/mugamathubathusha/

### Premium Features (Roadmap)
- Multi-currency support
- Distance-based automatic fee calculation
- Integration with Google Maps API
- Advanced conditional rules engine
- Priority support & updates

---

## Support

For issues, feature requests, or customization inquiries, contact:

**Developer:** Mugamathu Bathusha  
**Project:** Matrix Area Based Delivery Fee Customizer  
**LinkedIn:** https://www.linkedin.com/in/mugamathubathusha/
