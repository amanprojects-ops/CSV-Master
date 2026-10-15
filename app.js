/**
 * CSV Master Studio - Frontend Application Controller
 * Handles dynamic file inspection, preview toggling, column configuration, cleaning, and downloads.
 */

document.addEventListener('DOMContentLoaded', () => {
    // --- State Variables ---
    let allFiles = [];
    let selectedFile = null;
    let inspectedFileData = null;
    let isProcessing = false;

    // --- DOM Elements ---
    const statsRibbon = {
        inputFiles: document.getElementById('statInputFiles'),
        phones: document.getElementById('statUniquePhones'),
        emails: document.getElementById('statUniqueEmails'),
        ram: document.getElementById('statRamUsage')
    };

    const filesCountBadge = document.getElementById('filesCountBadge');
    const filesListContainer = document.getElementById('filesListContainer');
    const fileSearchInput = document.getElementById('fileSearchInput');
    const btnCleanAll = document.getElementById('btnCleanAll');
    const btnRefreshAll = document.getElementById('btnRefreshAll');
    const fileUploadInput = document.getElementById('fileUploadInput');

    const workbenchEmptyState = document.getElementById('workbenchEmptyState');
    const workbenchContent = document.getElementById('workbenchContent');

    const activeFileName = document.getElementById('activeFileName');
    const activeFileExt = document.getElementById('activeFileExt');
    const activeFileSize = document.getElementById('activeFileSize');
    const activeFileScanStatus = document.getElementById('activeFileScanStatus');
    const activeFileDate = document.getElementById('activeFileDate');

    const togglePreviewCheckbox = document.getElementById('togglePreviewCheckbox');
    const previewSection = document.getElementById('previewSection');
    const previewRowsCount = document.getElementById('previewRowsCount');
    const previewTableHead = document.getElementById('previewTableHead');
    const previewTableBody = document.getElementById('previewTableBody');

    const columnsGrid = document.getElementById('columnsGrid');
    const btnSelectAllCols = document.getElementById('btnSelectAllCols');
    const btnDeselectAllCols = document.getElementById('btnDeselectAllCols');

    const selectExportFormat = document.getElementById('selectExportFormat');
    const selectChunkSize = document.getElementById('selectChunkSize');
    const btnRunCleanSelected = document.getElementById('btnRunCleanSelected');

    const vaultContent = document.getElementById('vaultContent');
    const btnRefreshOutputs = document.getElementById('btnRefreshOutputs');
    const toastContainer = document.getElementById('toastContainer');

    // --- Initialization ---
    init();

    function init() {
        fetchStats();
        loadFilesList();
        loadOutputsVault();
        bindEvents();
    }

    function bindEvents() {
        btnRefreshAll.addEventListener('click', () => {
            fetchStats();
            loadFilesList();
            loadOutputsVault();
            showToast('Refreshed data & collections', 'info');
        });

        btnRefreshOutputs.addEventListener('click', loadOutputsVault);

        fileSearchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            renderFilesList(allFiles.filter(f => f.name.toLowerCase().includes(query)));
        });

        // Toggle Raw Preview Button
        togglePreviewCheckbox.addEventListener('change', (e) => {
            if (e.target.checked) {
                previewSection.classList.remove('collapsed');
            } else {
                previewSection.classList.add('collapsed');
            }
        });

        // Select / Deselect All Columns
        btnSelectAllCols.addEventListener('click', () => {
            document.querySelectorAll('.col-chk').forEach(chk => {
                chk.checked = true;
                chk.closest('.column-card').classList.add('active-col');
            });
        });

        btnDeselectAllCols.addEventListener('click', () => {
            document.querySelectorAll('.col-chk').forEach(chk => {
                chk.checked = false;
                chk.closest('.column-card').classList.remove('active-col');
            });
        });

        // Run Clean Selected File
        btnRunCleanSelected.addEventListener('click', runCleanSelectedFile);

        // Process All Unscanned Files
        btnCleanAll.addEventListener('click', runCleanAllUnscanned);

        // File Upload
        fileUploadInput.addEventListener('change', handleFileUpload);
    }

    // --- API Calls ---

    async function fetchStats() {
        try {
            const res = await fetch('api.php?action=get_stats');
            const data = await res.json();
            if (data.success) {
                statsRibbon.inputFiles.textContent = Number(data.total_input_files).toLocaleString();
                statsRibbon.phones.textContent = Number(data.total_unique_phones).toLocaleString();
                statsRibbon.emails.textContent = Number(data.total_unique_emails).toLocaleString();
                statsRibbon.ram.textContent = `${data.memory_usage_mb} MB`;
            }
        } catch (err) {
            console.error('Error fetching stats:', err);
        }
    }

    async function loadFilesList() {
        filesListContainer.innerHTML = `
            <div class="loading-state">
                <i class="ph ph-spinner ph-spin"></i> Loading input files...
            </div>
        `;

        try {
            const res = await fetch('api.php?action=list_files');
            const data = await res.json();
            if (data.success) {
                allFiles = data.files || [];
                filesCountBadge.textContent = allFiles.length;
                renderFilesList(allFiles);

                // Auto-select first file if none selected
                if (!selectedFile && allFiles.length > 0) {
                    selectFile(allFiles[0].name);
                }
            } else {
                filesListContainer.innerHTML = `<div class="loading-state error">Error: ${data.error}</div>`;
            }
        } catch (err) {
            filesListContainer.innerHTML = `<div class="loading-state error">Cannot connect to API backend.</div>`;
        }
    }

    function renderFilesList(files) {
        if (!files || files.length === 0) {
            filesListContainer.innerHTML = `
                <div class="loading-state">
                    No files found in input/ folder.
                </div>
            `;
            return;
        }

        filesListContainer.innerHTML = '';
        files.forEach(file => {
            const card = document.createElement('div');
            card.className = `file-item-card ${selectedFile === file.name ? 'active' : ''}`;
            card.dataset.filename = file.name;

            const iconClass = file.extension === 'XLS' || file.extension === 'XLSX' ? 'ph-file-xls' : 'ph-file-csv';
            const statusBadge = file.is_scanned 
                ? `<span class="status-tag scanned"><i class="ph ph-check"></i> Scanned</span>`
                : `<span class="status-tag unscanned"><i class="ph ph-clock"></i> New</span>`;

            card.innerHTML = `
                <div class="file-icon-box">
                    <i class="ph ${iconClass}"></i>
                </div>
                <div class="file-item-info">
                    <div class="file-item-name" title="${file.name}">${file.name}</div>
                    <div class="file-item-submeta">
                        <span>${file.size_formatted}</span>
                        <span>•</span>
                        ${statusBadge}
                    </div>
                </div>
            `;

            card.addEventListener('click', () => selectFile(file.name));
            filesListContainer.appendChild(card);
        });
    }

    async function selectFile(fileName) {
        selectedFile = fileName;

        // Update active highlight in left list
        document.querySelectorAll('.file-item-card').forEach(c => {
            c.classList.toggle('active', c.dataset.filename === fileName);
        });

        // Show workbench
        workbenchEmptyState.classList.add('hidden');
        workbenchContent.classList.remove('hidden');

        // Reset and show loading in columns grid
        columnsGrid.innerHTML = `
            <div class="loading-state" style="grid-column: 1 / -1;">
                <i class="ph ph-spinner ph-spin"></i> Inspecting file columns and structure...
            </div>
        `;

        try {
            const res = await fetch(`api.php?action=inspect_file&file=${encodeURIComponent(fileName)}`);
            const data = await res.json();

            if (data.success) {
                inspectedFileData = data;
                renderActiveFileHeader(data);
                renderPreviewTable(data.columns, data.preview_rows);
                renderDynamicColumns(data.columns, data.column_types);
            } else {
                showToast(`Error inspecting file: ${data.error}`, 'error');
            }
        } catch (err) {
            showToast('Network error while inspecting file', 'error');
        }
    }

    function renderActiveFileHeader(data) {
        activeFileName.textContent = data.file_name;
        activeFileExt.textContent = data.extension;
        activeFileSize.innerHTML = `<i class="ph ph-hard-drive"></i> ${data.size_formatted}`;

        // Find file info from list
        const info = allFiles.find(f => f.name === data.file_name);
        if (info && info.is_scanned) {
            activeFileScanStatus.className = 'status-badge scanned';
            activeFileScanStatus.innerHTML = `<i class="ph ph-check-circle"></i> Scanned (${info.phones_count || 0} phones, ${info.emails_count || 0} emails)`;
            activeFileDate.innerHTML = `<i class="ph ph-clock"></i> ${info.scanned_at || ''}`;
        } else {
            activeFileScanStatus.className = 'status-badge unscanned';
            activeFileScanStatus.innerHTML = `<i class="ph ph-warning-circle"></i> Unscanned`;
            activeFileDate.innerHTML = `<i class="ph ph-clock"></i> Ready for cleaning`;
        }

        // Keep preview closed by default for maximum speed and clean view
        togglePreviewCheckbox.checked = false;
        previewSection.classList.add('collapsed');
    }

    function renderPreviewTable(columns, rows) {
        previewRowsCount.textContent = rows ? rows.length : 0;
        previewTableHead.innerHTML = '';
        previewTableBody.innerHTML = '';

        if (!columns || columns.length === 0) return;

        // Render Head
        const headerRow = document.createElement('tr');
        columns.forEach(col => {
            const th = document.createElement('th');
            th.textContent = col;
            headerRow.appendChild(th);
        });
        previewTableHead.appendChild(headerRow);

        // Render Rows
        if (rows && rows.length > 0) {
            rows.forEach(row => {
                const tr = document.createElement('tr');
                columns.forEach(col => {
                    const td = document.createElement('td');
                    td.textContent = row[col] || '';
                    tr.appendChild(td);
                });
                previewTableBody.appendChild(tr);
            });
        }
    }

    function renderDynamicColumns(columns, columnTypes) {
        columnsGrid.innerHTML = '';

        if (!columns || columns.length === 0) {
            columnsGrid.innerHTML = `<div class="loading-state">No dynamic columns detected.</div>`;
            return;
        }

        columns.forEach((colName, idx) => {
            const type = columnTypes[colName] || 'text';
            const card = document.createElement('div');
            card.className = 'column-card active-col';

            card.innerHTML = `
                <div class="col-header-row">
                    <label class="col-checkbox-label" title="${colName}">
                        <input type="checkbox" class="col-chk" checked data-col="${colName}">
                        <span>${colName}</span>
                    </label>
                    <span class="col-type-tag type-${type}">${type}</span>
                </div>
                <input type="text" class="col-rename-input" placeholder="Rename field..." value="${colName}">
            `;

            // Toggle card highlight on checkbox change
            const chk = card.querySelector('.col-chk');
            chk.addEventListener('change', (e) => {
                card.classList.toggle('active-col', e.target.checked);
            });

            columnsGrid.appendChild(card);
        });
    }

    async function runCleanSelectedFile() {
        if (!selectedFile) return;
        if (isProcessing) return;

        // Collect checked columns
        const checkedCols = [];
        const renameMap = {};

        document.querySelectorAll('.column-card').forEach(card => {
            const chk = card.querySelector('.col-chk');
            const renameInput = card.querySelector('.col-rename-input');
            if (chk && chk.checked) {
                const colName = chk.dataset.col;
                checkedCols.push(colName);
                renameMap[colName] = renameInput.value.trim() || colName;
            }
        });

        if (checkedCols.length === 0) {
            showToast('Please select at least one column to export!', 'error');
            return;
        }

        isProcessing = true;
        btnRunCleanSelected.disabled = true;
        btnRunCleanSelected.innerHTML = `<i class="ph ph-spinner ph-spin"></i> Processing & Cleaning...`;

        try {
            const payload = {
                file_name: selectedFile,
                selected_columns: checkedCols,
                column_mappings: renameMap,
                export_format: selectExportFormat.value,
                chunk_size: parseInt(selectChunkSize.value)
            };

            const res = await fetch('api.php?action=clean_selected', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json();

            if (data.success) {
                showToast(`Cleaned & Exported: ${selectedFile}!`, 'success');
                fetchStats();
                loadFilesList();
                loadOutputsVault();
            } else {
                showToast(`Failed: ${data.error}`, 'error');
            }
        } catch (err) {
            showToast('Error during cleaning execution', 'error');
        } finally {
            isProcessing = false;
            btnRunCleanSelected.disabled = false;
            btnRunCleanSelected.innerHTML = `<i class="ph-bold ph-play-circle"></i> Clean & Export Selected File`;
        }
    }

    async function runCleanAllUnscanned() {
        if (isProcessing) return;

        isProcessing = true;
        btnCleanAll.disabled = true;
        btnCleanAll.innerHTML = `<i class="ph ph-spinner ph-spin"></i> Processing All Files...`;

        try {
            const res = await fetch('api.php?action=clean_all', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                showToast('Bulk cleaning completed successfully!', 'success');
                fetchStats();
                loadFilesList();
                loadOutputsVault();
            } else {
                showToast(`Error: ${data.error}`, 'error');
            }
        } catch (err) {
            showToast('Network error during bulk cleaning', 'error');
        } finally {
            isProcessing = false;
            btnCleanAll.disabled = false;
            btnCleanAll.innerHTML = `<i class="ph-bold ph-play"></i> Process All Unscanned Files`;
        }
    }

    async function loadOutputsVault() {
        vaultContent.innerHTML = `
            <div class="loading-state">
                <i class="ph ph-spinner ph-spin"></i> Loading download collections...
            </div>
        `;

        try {
            const res = await fetch('api.php?action=list_outputs');
            const data = await res.json();

            if (data.success && data.collections && data.collections.length > 0) {
                renderOutputsVault(data.collections);
            } else {
                vaultContent.innerHTML = `
                    <div class="loading-state">
                        No cleaned collections yet. Select a file above and click Clean & Export!
                    </div>
                `;
            }
        } catch (err) {
            vaultContent.innerHTML = `<div class="loading-state error">Cannot load output collections.</div>`;
        }
    }

    function renderOutputsVault(collections) {
        vaultContent.innerHTML = '';

        collections.forEach(col => {
            const card = document.createElement('div');
            card.className = 'date-group-card';

            const summary = col.summary || {};
            const filesCount = summary.files_included_for_this_date ? summary.files_included_for_this_date.length : 0;
            const totalPhones = (summary.total_date_unique_phones || 0).toLocaleString();
            const totalEmails = (summary.total_date_unique_emails || 0).toLocaleString();

            let filesHtml = '';

            // Render Phones
            if (col.phones && col.phones.length > 0) {
                col.phones.forEach(item => {
                    filesHtml += `
                        <div class="download-card type-phone">
                            <div class="download-info">
                                <div class="flag-icon"><i class="ph-bold ph-phone-call"></i></div>
                                <div class="download-text">
                                    <h5>${item.country} Phones</h5>
                                    <span>${item.filename} • ${item.size_formatted}</span>
                                </div>
                            </div>
                            <a href="${item.download_url}" download class="download-btn">
                                <i class="ph-bold ph-download-simple"></i> Download
                            </a>
                        </div>
                    `;
                });
            }

            // Render Emails
            if (col.emails && col.emails.length > 0) {
                col.emails.forEach(item => {
                    filesHtml += `
                        <div class="download-card type-email">
                            <div class="download-info">
                                <div class="flag-icon"><i class="ph-bold ph-envelope-simple"></i></div>
                                <div class="download-text">
                                    <h5>${item.country} Emails</h5>
                                    <span>${item.filename} • ${item.size_formatted}</span>
                                </div>
                            </div>
                            <a href="${item.download_url}" download class="download-btn">
                                <i class="ph-bold ph-download-simple"></i> Download
                            </a>
                        </div>
                    `;
                });
            }

            card.innerHTML = `
                <div class="date-group-header">
                    <span class="date-badge"><i class="ph ph-calendar"></i> Date: ${col.date}</span>
                    <span style="font-size: 12px; color: var(--text-dim);">
                        Combined from ${filesCount} files • <strong>${totalPhones} Phones</strong> • <strong>${totalEmails} Emails</strong>
                    </span>
                </div>
                <div class="files-grid-wrap">
                    ${filesHtml || '<p style="color:var(--text-dim);font-size:12px;">No files generated for this date.</p>'}
                </div>
            `;

            vaultContent.appendChild(card);
        });
    }

    async function handleFileUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);

        showToast(`Uploading ${file.name}...`, 'info');

        try {
            const res = await fetch('api.php?action=upload_file', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                showToast(`Uploaded ${file.name} successfully!`, 'success');
                fileUploadInput.value = '';
                loadFilesList();
                fetchStats();
            } else {
                showToast(`Upload failed: ${data.error}`, 'error');
            }
        } catch (err) {
            showToast('Error uploading file', 'error');
        }
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let icon = 'ph-info';
        if (type === 'success') icon = 'ph-check-circle';
        if (type === 'error') icon = 'ph-warning-circle';

        toast.innerHTML = `<i class="ph ${icon}"></i> <span>${message}</span>`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
});
