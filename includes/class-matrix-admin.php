<?php
/**
 * Admin Settings Page for Matrix Area Delivery Fee
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_Area_Delivery_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        // Handle the CSV export EARLY (before any admin HTML is rendered), so
        // the download is the CSV alone — not the whole admin page.
        add_action('admin_init', array($this, 'maybe_export_csv'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Stream the CSV export on admin_init and exit.
     *
     * Previously the export was triggered from inside settings_page() — the menu
     * page's render callback — which runs only AFTER WordPress has already output
     * the entire admin page (doctype, <head>, admin chrome). The CSV was then
     * appended to all of that, so the downloaded .csv file actually contained the
     * whole HTML page. Running here, before any output, fixes it.
     */
    public function maybe_export_csv() {
        if (!isset($_POST['matrix_export_csv'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        // check_admin_referer() dies on an invalid nonce (standard behaviour).
        check_admin_referer('matrix_export_csv_action', 'matrix_export_nonce');
        $this->export_to_csv();
        exit;
    }

    public function add_admin_menu() {
        add_menu_page(
            'Matrix Area Delivery Fee',
            'Delivery Areas',
            'manage_options',
            'matrix-area-delivery-fee',
            array($this, 'settings_page'),
            'dashicons-location-alt',
            56
        );
    }

    public function register_settings() {
        register_setting('matrix_delivery_settings', 'matrix_delivery_areas');
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook != 'toplevel_page_matrix-area-delivery-fee') {
            return;
        }
        ?>
        <style>
            .matrix-admin-wrap { max-width: 1200px; margin: 20px 0; }
            .matrix-areas-table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #fff; }
            .matrix-areas-table th, .matrix-areas-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            .matrix-areas-table th { background: #f9f9f9; font-weight: 600; }
            .matrix-areas-table tr:hover { background: #f5f5f5; cursor: move; }
            .matrix-areas-table input[type="number"] { width: 100px; }
            .matrix-add-area { display: inline-block; margin: 20px 0; }
            .matrix-delete-btn { color: #dc3232; cursor: pointer; text-decoration: none; }
            .matrix-delete-btn:hover { color: #a00; }
            .matrix-notice { padding: 10px 15px; margin: 15px 0; background: #d4edda; border-left: 4px solid #28a745; }
            .matrix-backup-section { background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; border-radius: 5px; }
            .matrix-dragging { opacity: 0.5; background: #e3f2fd; }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
        <?php
    }

    public function settings_page() {
        // CSV Export is handled earlier on admin_init (maybe_export_csv), before
        // any HTML is output — otherwise the download contains the whole page.

        // Handle form submissions
        if (isset($_POST['matrix_save_areas']) && check_admin_referer('matrix_save_areas_action', 'matrix_areas_nonce')) {
            $this->save_delivery_areas();
            echo '<div class="matrix-notice">Delivery areas saved successfully!</div>';
        }

        if (isset($_POST['matrix_reset_areas']) && check_admin_referer('matrix_reset_areas_action', 'matrix_reset_nonce')) {
            $this->reset_to_defaults();
            echo '<div class="matrix-notice">Delivery areas reset to default Qatar areas!</div>';
        }

        if (isset($_POST['matrix_import_csv']) && check_admin_referer('matrix_import_csv_action', 'matrix_import_nonce')) {
            $result = $this->import_from_csv();
            if ($result['success']) {
                echo '<div class="matrix-notice">✅ Successfully imported ' . $result['count'] . ' unique zones from CSV!</div>';
            } else {
                echo '<div class="error"><p>❌ ' . $result['message'] . '</p></div>';
            }
        }

        if (isset($_POST['matrix_upload_csv']) && check_admin_referer('matrix_upload_csv_action', 'matrix_upload_nonce')) {
            $result = $this->import_from_uploaded_csv();
            if ($result['success']) {
                echo '<div class="matrix-notice">✅ Successfully imported ' . $result['count'] . ' areas with fees from uploaded CSV!</div>';
            } else {
                echo '<div class="error"><p>❌ ' . $result['message'] . '</p></div>';
            }
        }

        if (isset($_POST['matrix_backup_areas']) && check_admin_referer('matrix_backup_areas_action', 'matrix_backup_nonce')) {
            $this->create_backup();
            echo '<div class="matrix-notice">✅ Backup created successfully!</div>';
        }

        if (isset($_POST['matrix_restore_backup']) && check_admin_referer('matrix_restore_backup_action', 'matrix_restore_nonce')) {
            $result = $this->restore_backup();
            if ($result) {
                echo '<div class="matrix-notice">✅ Backup restored successfully!</div>';
            } else {
                echo '<div class="error"><p>❌ No backup found to restore.</p></div>';
            }
        }

        if (isset($_POST['matrix_sort_areas']) && check_admin_referer('matrix_sort_areas_action', 'matrix_sort_nonce')) {
            $this->sort_areas_alphabetically();
            echo '<div class="matrix-notice">✅ Areas sorted alphabetically!</div>';
        }

        $areas = $this->get_delivery_areas();
        $backup_exists = get_option('matrix_delivery_areas_backup') ? true : false;
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>🚚 Matrix Area Based Delivery Fee Manager</h1>
            <p>Manage delivery areas and fees for WooCommerce checkout. Configure zone-based pricing with automated fee calculation.</p>
            <p style="background: #f0f8ff; padding: 10px; border-left: 4px solid #0073aa; margin: 10px 0;">
                <strong>👨‍💻 Developer:</strong> Mugamathu Bathusha | 
                <strong>� LinkedIn:</strong> <a href="https://www.linkedin.com/in/mugamathubathusha/" target="_blank">@mugamathubathusha</a>
            </p>

            <!-- CSV Export/Import Section -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
                <h2>📊 CSV Export / Import</h2>
                
                <!-- Export CSV -->
                <div style="margin-bottom: 20px; padding: 15px; background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 4px;">
                    <h3 style="margin-top: 0;">📤 Export Current Areas</h3>
                    <p>Download all your delivery areas with fees as a CSV file. Open in Excel, edit, and re-import.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('matrix_export_csv_action', 'matrix_export_nonce'); ?>
                        <button type="submit" name="matrix_export_csv" class="button button-primary">
                            📥 Download CSV Export
                        </button>
                        <span style="margin-left: 10px; color: #666;">💾 File: <strong>delivery-areas-<?php echo date('Y-m-d'); ?>.csv</strong></span>
                    </form>
                </div>

                <!-- Upload Import -->
                <div style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-left: 4px solid #2196f3; border-radius: 4px;">
                    <h3 style="margin-top: 0;">📥 Import from Your CSV File</h3>
                    <p><strong>CSV Format:</strong> Fee (QAR), Area Name (English), Area Name (Arabic), Value</p>
                    <p style="font-size: 12px; color: #666;">💡 Tip: Export first to get the correct format, or <a href="<?php echo plugin_dir_url(dirname(__FILE__)) . 'template-delivery-areas.csv'; ?>" download>📥 download template CSV</a>, edit in Excel, save as CSV, then upload.</p>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php wp_nonce_field('matrix_upload_csv_action', 'matrix_upload_nonce'); ?>
                        <input type="file" name="csv_file" accept=".csv" required style="margin-bottom: 10px;" />
                        <br>
                        <button type="submit" name="matrix_upload_csv" class="button button-primary">
                            📤 Upload & Import CSV
                        </button>
                    </form>
                </div>

                <!-- Default Area.csv Import -->
                <div style="padding: 15px; background: #fff9e6; border-left: 4px solid #ff9800; border-radius: 4px;">
                    <h3 style="margin-top: 0;">📋 Quick Import from area.csv</h3>
                    <p>Import Qatar zones from the default CSV file (554 zones, fees set to 0).</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('matrix_import_csv_action', 'matrix_import_nonce'); ?>
                        <button type="submit" name="matrix_import_csv" class="button">
                            📥 Import Default Qatar Zones
                        </button>
                        <span style="margin-left: 10px; color: #666;">File: <code>area.csv</code></span>
                    </form>
                </div>
            </div>

            <!-- Backup & Restore Section -->
            <div class="matrix-backup-section">
                <h2>💾 Backup & Restore</h2>
                <p>Create a backup before making changes. You can restore if something goes wrong.</p>
                <form method="post" action="" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('matrix_backup_areas_action', 'matrix_backup_nonce'); ?>
                    <button type="submit" name="matrix_backup_areas" class="button">
                        💾 Create Backup
                    </button>
                </form>
                <form method="post" action="" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('matrix_restore_backup_action', 'matrix_restore_nonce'); ?>
                    <button type="submit" name="matrix_restore_backup" class="button" 
                            <?php echo !$backup_exists ? 'disabled' : ''; ?>
                            onclick="return confirm('This will replace current areas with backup. Continue?');">
                        🔄 Restore Backup
                    </button>
                </form>
                <?php if ($backup_exists): ?>
                    <span style="color: #28a745;">✅ Backup available</span>
                <?php else: ?>
                    <span style="color: #6c757d;">No backup found</span>
                <?php endif; ?>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('matrix_save_areas_action', 'matrix_areas_nonce'); ?>
                
                <div style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <button type="button" onclick="sortAreasAlphabetically()" class="button">
                            🔤 Sort A-Z
                        </button>
                        <span style="margin-left: 10px; color: #666;">💡 Tip: You can also drag and drop rows to reorder</span>
                    </div>
                    <div style="background: #e3f2fd; padding: 8px 15px; border-radius: 4px; font-weight: bold;">
                        📊 Total Areas: <span id="matrix-row-count"><?php echo count($areas); ?></span>
                    </div>
                </div>
                
                <table class="matrix-areas-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 10%;">Fee (QAR)</th>
                            <th style="width: 23%;">Area Name (English)</th>
                            <th style="width: 23%;">Area Name (Arabic)</th>
                            <th style="width: 29%;">Value</th>
                            <th style="width: 10%;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="matrix-areas-tbody">
                        <?php if (!empty($areas)) : ?>
                            <?php foreach ($areas as $index => $area) : ?>
                                <tr>
                                    <td style="text-align: center; font-weight: bold; color: #666;"><?php echo ($index + 1); ?></td>
                                    <td>
                                        <input type="number" name="areas[<?php echo $index; ?>][fee]" 
                                               value="<?php echo esc_attr(isset($area['fee']) ? $area['fee'] : '0'); ?>" 
                                               step="0.01" min="0" required />
                                    </td>
                                    <td>
                                        <input type="text" name="areas[<?php echo $index; ?>][en]" 
                                               value="<?php echo esc_attr($area['en']); ?>" 
                                               class="regular-text" required />
                                    </td>
                                    <td>
                                        <input type="text" name="areas[<?php echo $index; ?>][ar]" 
                                               value="<?php echo esc_attr($area['ar']); ?>" 
                                               class="regular-text" required />
                                    </td>
                                    <td>
                                        <input type="text" name="areas[<?php echo $index; ?>][value]" 
                                               value="<?php echo esc_attr($area['value']); ?>" 
                                               class="regular-text" required />
                                    </td>
                                    <td>
                                        <a href="javascript:void(0);" class="matrix-delete-btn" onclick="deleteRow(this);">❌ Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <button type="button" class="button matrix-add-area" onclick="matrixAddArea()">➕ Add New Area</button>

                <p>
                    <button type="submit" name="matrix_save_areas" class="button button-primary button-large">💾 Save Delivery Areas</button>
                    <button type="submit" name="matrix_reset_areas" class="button button-secondary" 
                            onclick="return confirm('Are you sure? This will reset to default Qatar areas.');">🔄 Reset to Defaults</button>
                </p>
            </form>

            <hr>
            <h3>💡 Usage Tips</h3>
            <ul>
                <li><strong>Drag & Drop:</strong> Click and drag any row to reorder areas manually</li>
                <li><strong>Sort A-Z:</strong> Click the "Sort A-Z" button to automatically sort by English name</li>
                <li><strong>Backup:</strong> Create backup before major changes - backups are stored in database</li>
                <li><strong>Restore:</strong> Restore from backup if you accidentally delete or change areas</li>
                <li><strong>CSV Import:</strong> Import loads all zones with 0 fee - edit fees manually after import</li>
                <li><strong>Shipping Fee:</strong> Enter the delivery charge in QAR for each area (0 = Free delivery)</li>
                <li><strong>Automatic:</strong> Fees are automatically added to cart based on selected area</li>
                <li><strong>Value:</strong> This is what gets saved to billing_city (use English for compatibility)</li>
                <li><strong>Display:</strong> Format shown: "English - Arabic" (e.g., "Lusail - لوسيل")</li>
            </ul>
            
            <h3>📋 Example Fee Structure</h3>
            <ul>
                <li>QAR 0 (Free): Abu Hamour, Al Aziziya, Al Mansoura, Old Airport</li>
                <li>QAR 25: Al Duhail, West Bay</li>
                <li>QAR 45: Lusail, Al Wakrah</li>
                <li>QAR 75: Al Khor</li>
                <li>QAR 100: Dukhan</li>
            </ul>
        </div>

        <script>
            let areaIndex = <?php echo count($areas); ?>;
            
            // Initialize drag and drop
            document.addEventListener('DOMContentLoaded', function() {
                const tbody = document.getElementById('matrix-areas-tbody');
                if (tbody) {
                    new Sortable(tbody, {
                        animation: 150,
                        handle: 'tr',
                        ghostClass: 'matrix-dragging',
                        onEnd: function(evt) {
                            updateRowNumbers();
                            console.log('Row moved from', evt.oldIndex, 'to', evt.newIndex);
                        }
                    });
                }
            });
            
            function matrixAddArea() {
                const tbody = document.getElementById('matrix-areas-tbody');
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style="text-align: center; font-weight: bold; color: #666;">1</td>
                    <td>
                        <input type="number" name="areas[${areaIndex}][fee]" value="0" step="0.01" min="0" required />
                    </td>
                    <td>
                        <input type="text" name="areas[${areaIndex}][en]" class="regular-text" placeholder="e.g., Al Duhail" required />
                    </td>
                    <td>
                        <input type="text" name="areas[${areaIndex}][ar]" class="regular-text" placeholder="e.g., الدحيل" required />
                    </td>
                    <td>
                        <input type="text" name="areas[${areaIndex}][value]" class="regular-text" placeholder="e.g., Al Duhail" required />
                    </td>
                    <td>
                        <a href="javascript:void(0);" class="matrix-delete-btn" onclick="deleteRow(this);">❌ Delete</a>
                    </td>
                `;
                tbody.appendChild(row);
                areaIndex++;
                updateRowNumbers();
                updateRowCount();
            }
            
            function deleteRow(element) {
                element.closest('tr').remove();
                updateRowNumbers();
                updateRowCount();
            }
            
            function updateRowCount() {
                const tbody = document.getElementById('matrix-areas-tbody');
                const count = tbody.querySelectorAll('tr').length;
                document.getElementById('matrix-row-count').textContent = count;
            }
            
            function updateRowNumbers() {
                const tbody = document.getElementById('matrix-areas-tbody');
                const rows = tbody.querySelectorAll('tr');
                rows.forEach((row, index) => {
                    const firstCell = row.querySelector('td:first-child');
                    if (firstCell) {
                        firstCell.textContent = index + 1;
                    }
                });
            }
            
            function sortAreasAlphabetically() {
                const tbody = document.getElementById('matrix-areas-tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                rows.sort((a, b) => {
                    const aText = a.querySelector('input[name*="[en]"]').value.toLowerCase();
                    const bText = b.querySelector('input[name*="[en]"]').value.toLowerCase();
                    return aText.localeCompare(bText);
                });
                
                rows.forEach(row => tbody.appendChild(row));
                updateRowNumbers();
            }
        </script>
        <?php
    }

    public function save_delivery_areas() {
        $areas = array();
        
        if (isset($_POST['areas']) && is_array($_POST['areas'])) {
            foreach ($_POST['areas'] as $area) {
                if (!empty($area['en']) && !empty($area['ar']) && !empty($area['value'])) {
                    $areas[] = array(
                        'en' => sanitize_text_field($area['en']),
                        'ar' => sanitize_text_field($area['ar']),
                        'value' => sanitize_text_field($area['value']),
                        'fee' => floatval($area['fee'])
                    );
                }
            }
        }
        
        update_option('matrix_delivery_areas', $areas);
    }

    public function reset_to_defaults() {
        $default_areas = $this->get_default_areas();
        update_option('matrix_delivery_areas', $default_areas);
    }

    public function get_delivery_areas() {
        $areas = get_option('matrix_delivery_areas');
        
        if (empty($areas)) {
            $areas = $this->get_default_areas();
            update_option('matrix_delivery_areas', $areas);
        }
        
        return $areas;
    }

    public function get_default_areas() {
        return array(
            array('en' => 'Abu Hamour', 'ar' => 'ابو هامور', 'value' => 'Abu Hamour', 'fee' => 0),
            array('en' => 'Al Aziziya', 'ar' => 'العزيزية', 'value' => 'Al Aziziya', 'fee' => 0),
            array('en' => 'Al Mansoura', 'ar' => 'المنصورة', 'value' => 'Al Mansoura', 'fee' => 0),
            array('en' => 'Old Airport', 'ar' => 'مطار قديم', 'value' => 'Old Airport', 'fee' => 0),
            array('en' => 'Al Duhail', 'ar' => 'الدحيل', 'value' => 'Al Duhail', 'fee' => 25),
            array('en' => 'West Bay', 'ar' => 'الخليج الغربي', 'value' => 'West Bay', 'fee' => 25),
            array('en' => 'Lusail', 'ar' => 'لوسيل', 'value' => 'Lusail', 'fee' => 45),
            array('en' => 'Al Wakrah', 'ar' => 'الوكرة', 'value' => 'Al Wakrah', 'fee' => 45),
            array('en' => 'Al Khor', 'ar' => 'الخور', 'value' => 'Al Khor', 'fee' => 75),
            array('en' => 'Dukhan', 'ar' => 'دخان', 'value' => 'Dukhan', 'fee' => 100),
        );
    }

    public function import_from_csv() {
        $csv_file = plugin_dir_path(dirname(__FILE__)) . 'area.csv';
        
        if (!file_exists($csv_file)) {
            return array('success' => false, 'message' => 'CSV file not found. Please upload area.csv to the plugin directory.');
        }

        $handle = fopen($csv_file, 'r');
        if (!$handle) {
            return array('success' => false, 'message' => 'Unable to open CSV file.');
        }

        $zones = array();
        $header = fgetcsv($handle, 1000, ';'); // Read header row
        
        // Find column indices for Zone (English) and المنطقة (Arabic)
        $en_index = array_search('Zone', $header);
        $ar_index = array_search('المنطقة', $header);
        
        if ($en_index === false || $ar_index === false) {
            fclose($handle);
            return array('success' => false, 'message' => 'CSV file format incorrect. Missing Zone or المنطقة columns.');
        }

        // Read all rows and collect unique zones
        while (($row = fgetcsv($handle, 1000, ';')) !== false) {
            if (!empty($row[$en_index]) && !empty($row[$ar_index])) {
                $en_value = trim($row[$en_index]);
                $ar_value = trim($row[$ar_index]);
                
                // Use English name as key to avoid duplicates
                $zones[$en_value] = array(
                    'en' => $en_value,
                    'ar' => $ar_value,
                    'value' => $en_value,
                    'fee' => 0
                );
            }
        }
        
        fclose($handle);
        
        // Convert to indexed array and sort alphabetically
        $zones_array = array_values($zones);
        usort($zones_array, function($a, $b) {
            return strcmp($a['en'], $b['en']);
        });
        
        // Save to database
        update_option('matrix_delivery_areas', $zones_array);
        
        return array('success' => true, 'count' => count($zones_array));
    }

    public function export_to_csv() {
        $areas = get_option('matrix_delivery_areas');
        
        if (empty($areas)) {
            wp_die('No delivery areas to export.');
        }

        // Discard anything already buffered (stray notices / output) so the CSV
        // is the ONLY thing in the response body.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set headers for CSV download
        $filename = 'delivery-areas-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8 (helps Excel display Arabic correctly)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write header row
        fputcsv($output, array('Fee (QAR)', 'Area Name (English)', 'Area Name (Arabic)', 'Value'));

        // Write data rows
        foreach ($areas as $area) {
            fputcsv($output, array(
                isset($area['fee']) ? $area['fee'] : 0,
                $area['en'],
                $area['ar'],
                $area['value']
            ));
        }

        fclose($output);
        exit;
    }

    public function import_from_uploaded_csv() {
        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            return array('success' => false, 'message' => 'No file uploaded or upload error occurred.');
        }

        $file = $_FILES['csv_file'];
        
        // Validate file type
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'csv') {
            return array('success' => false, 'message' => 'Please upload a CSV file (.csv extension).');
        }

        // Read uploaded file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            return array('success' => false, 'message' => 'Unable to read uploaded file.');
        }

        $areas = array();
        $header = fgetcsv($handle); // Read header row
        $row_count = 0;

        // Read all data rows
        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row[0]) && empty($row[1]) && empty($row[2]) && empty($row[3])) {
                continue;
            }

            // Validate required fields
            if (!isset($row[1]) || !isset($row[2]) || !isset($row[3])) {
                continue;
            }

            $en_value = trim($row[1]);
            $ar_value = trim($row[2]);
            $value = trim($row[3]);
            $fee = isset($row[0]) ? floatval($row[0]) : 0;

            if (!empty($en_value) && !empty($ar_value) && !empty($value)) {
                $areas[] = array(
                    'en' => sanitize_text_field($en_value),
                    'ar' => sanitize_text_field($ar_value),
                    'value' => sanitize_text_field($value),
                    'fee' => $fee
                );
                $row_count++;
            }
        }

        fclose($handle);

        if (empty($areas)) {
            return array('success' => false, 'message' => 'No valid data found in CSV file. Expected format: Fee, English Name, Arabic Name, Value');
        }

        // Save to database
        update_option('matrix_delivery_areas', $areas);

        return array('success' => true, 'count' => $row_count);
    }

    public function create_backup() {
        $current_areas = get_option('matrix_delivery_areas');
        if (!empty($current_areas)) {
            update_option('matrix_delivery_areas_backup', $current_areas);
            update_option('matrix_delivery_areas_backup_date', current_time('mysql'));
        }
    }

    public function restore_backup() {
        $backup = get_option('matrix_delivery_areas_backup');
        if (!empty($backup)) {
            update_option('matrix_delivery_areas', $backup);
            return true;
        }
        return false;
    }

    public function sort_areas_alphabetically() {
        $areas = get_option('matrix_delivery_areas');
        if (!empty($areas)) {
            usort($areas, function($a, $b) {
                return strcmp($a['en'], $b['en']);
            });
            update_option('matrix_delivery_areas', $areas);
        }
    }
}

new Matrix_Area_Delivery_Admin();
