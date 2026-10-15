<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Master - Bulk Data Cleaner & Studio</title>
    <meta name="description" content="High performance Zero-RAM Bulk Data Cleaner and Dynamic Column Extractor for Millions of Records">
    
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Modern Stylesheet -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-layout">
        <!-- Top Navigation Bar -->
        <header class="top-nav">
            <div class="brand">
                <div class="brand-icon">
                    <i class="ph-bold ph-lightning"></i>
                </div>
                <div>
                    <h1 class="brand-title">CSV Master <span>Studio</span></h1>
                    <p class="brand-subtitle">Zero-RAM High Speed Bulk Data Processor</p>
                </div>
            </div>

            <!-- Global Live Stats Pills -->
            <div class="stats-ribbon" id="statsRibbon">
                <div class="stat-pill" title="Total raw files in input folder">
                    <i class="ph ph-folder-open"></i>
                    <div>
                        <span class="stat-value" id="statInputFiles">--</span>
                        <span class="stat-label">Files</span>
                    </div>
                </div>
                <div class="stat-pill accent-emerald" title="Total unique valid phone numbers">
                    <i class="ph ph-phone-call"></i>
                    <div>
                        <span class="stat-value" id="statUniquePhones">--</span>
                        <span class="stat-label">Phones</span>
                    </div>
                </div>
                <div class="stat-pill accent-cyan" title="Total unique valid email addresses">
                    <i class="ph ph-envelope-simple"></i>
                    <div>
                        <span class="stat-value" id="statUniqueEmails">--</span>
                        <span class="stat-label">Emails</span>
                    </div>
                </div>
                <div class="stat-pill accent-violet" title="Current memory usage">
                    <i class="ph ph-cpu"></i>
                    <div>
                        <span class="stat-value" id="statRamUsage">-- MB</span>
                        <span class="stat-label">Peak RAM</span>
                    </div>
                </div>
            </div>

            <!-- Top Actions -->
            <div class="top-actions">
                <button class="btn secondary" id="btnRefreshAll" title="Refresh files and stats">
                    <i class="ph ph-arrows-clockwise"></i> Refresh
                </button>
                <button class="btn primary" id="btnUploadModal" onclick="document.getElementById('fileUploadInput').click()">
                    <i class="ph-bold ph-upload-simple"></i> Upload File
                </button>
                <input type="file" id="fileUploadInput" style="display: none;" accept=".csv,.xls,.xlsx,.txt,.sql,.tsv">
            </div>
        </header>

        <!-- Main Studio Grid -->
        <main class="studio-grid">
            <!-- Left Panel: Input Files Hub -->
            <aside class="panel files-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="ph-bold ph-database"></i>
                        <h2>Input Files Hub</h2>
                        <span class="badge count-badge" id="filesCountBadge">0</span>
                    </div>
                    <div class="panel-tools">
                        <input type="text" id="fileSearchInput" placeholder="Filter files..." class="search-input">
                    </div>
                </div>

                <div class="files-list-container" id="filesListContainer">
                    <div class="loading-state">
                        <i class="ph ph-spinner ph-spin"></i> Loading input files...
                    </div>
                </div>

                <div class="panel-footer">
                    <button class="btn accent-btn full-width" id="btnCleanAll">
                        <i class="ph-bold ph-play"></i> Process All Unscanned Files
                    </button>
                </div>
            </aside>

            <!-- Right Panel: Dynamic File Workbench -->
            <section class="panel workbench-panel" id="workbenchPanel">
                <!-- Empty Placeholder State -->
                <div class="workbench-empty-state" id="workbenchEmptyState">
                    <div class="empty-icon-wrap">
                        <i class="ph ph-cursor-click"></i>
                    </div>
                    <h3>Select an Input File to Begin</h3>
                    <p>Click any file from the left sidebar to dynamically inspect columns, preview raw records, customize column exports, or start cleaning.</p>
                </div>

                <!-- Active File Workbench Content (Hidden until file selected) -->
                <div class="workbench-content hidden" id="workbenchContent">
                    <!-- File Active Banner -->
                    <div class="active-file-header">
                        <div class="file-meta-group">
                            <span class="file-ext-tag" id="activeFileExt">XLS</span>
                            <div>
                                <h3 id="activeFileName">file_name.xls</h3>
                                <div class="file-submeta">
                                    <span id="activeFileSize"><i class="ph ph-hard-drive"></i> -- MB</span>
                                    <span id="activeFileScanStatus" class="status-badge unscanned">Unscanned</span>
                                    <span id="activeFileDate"><i class="ph ph-clock"></i> --</span>
                                </div>
                            </div>
                        </div>

                        <!-- Raw View Toggle Button -->
                        <div class="view-toggle-box">
                            <span class="toggle-label"><i class="ph ph-table"></i> View Raw File Data</span>
                            <label class="switch-toggle" title="Toggle to show or hide raw data preview table">
                                <input type="checkbox" id="togglePreviewCheckbox">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Collapsible Raw Data Table Preview Section -->
                    <div class="preview-section collapsed" id="previewSection">
                        <div class="preview-header">
                            <div class="preview-title">
                                <i class="ph-bold ph-eye"></i>
                                <span>Raw Data Preview (First <span id="previewRowsCount">0</span> Rows)</span>
                            </div>
                            <span class="preview-note">Preview loaded safely with zero RAM impact</span>
                        </div>
                        <div class="table-scroll-wrap" id="previewTableWrap">
                            <table class="preview-table" id="previewTable">
                                <thead id="previewTableHead"></thead>
                                <tbody id="previewTableBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Dynamic Column Selector & Configurator -->
                    <div class="columns-config-section">
                        <div class="section-top-bar">
                            <div>
                                <h4><i class="ph-bold ph-columns"></i> Dynamic Column Selection & Mapping</h4>
                                <p>Select which dynamic columns to include, clean, or rename in your export collection.</p>
                            </div>
                            <div class="column-actions">
                                <button type="button" class="btn text-btn" id="btnSelectAllCols"><i class="ph ph-check-square"></i> Select All</button>
                                <button type="button" class="btn text-btn" id="btnDeselectAllCols"><i class="ph ph-square"></i> Deselect All</button>
                            </div>
                        </div>

                        <!-- Dynamic Columns Grid -->
                        <div class="columns-grid" id="columnsGrid">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <!-- Execution & Export Action Footer -->
                    <div class="workbench-action-footer">
                        <div class="export-options">
                            <div class="input-group">
                                <label><i class="ph ph-file-code"></i> Output Format:</label>
                                <select id="selectExportFormat" class="custom-select">
                                    <option value="json" selected>Country-Wise JSON Collections (Zero-RAM Streamed)</option>
                                    <option value="csv">Standard Clean CSV</option>
                                </select>
                            </div>
                            <div class="input-group">
                                <label><i class="ph ph-stack"></i> Chunk Size:</label>
                                <select id="selectChunkSize" class="custom-select">
                                    <option value="100000" selected>100,000 Rows per part</option>
                                    <option value="50000">50,000 Rows per part</option>
                                    <option value="250000">250,000 Rows per part</option>
                                </select>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn primary-lg pulse" id="btnRunCleanSelected">
                                <i class="ph-bold ph-play-circle"></i> Clean & Export Selected File
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Bottom Section: Cleaned Output Collections & Downloads Vault -->
        <section class="downloads-vault-section">
            <div class="vault-header">
                <div class="vault-title">
                    <i class="ph-bold ph-download-simple"></i>
                    <div>
                        <h2>Cleaned Collections & Downloads Vault</h2>
                        <p>Download your verified, deduplicated phone and email collections by country.</p>
                    </div>
                </div>
                <button class="btn secondary" id="btnRefreshOutputs">
                    <i class="ph ph-arrows-clockwise"></i> Refresh Collections
                </button>
            </div>

            <div class="vault-content" id="vaultContent">
                <div class="loading-state">
                    <i class="ph ph-spinner ph-spin"></i> Loading download collections...
                </div>
            </div>
        </section>
    </div>

    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Modern App Logic -->
    <script src="app.js"></script>
</body>
</html>
