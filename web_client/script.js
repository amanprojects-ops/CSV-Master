document.addEventListener('DOMContentLoaded', () => {
    const uploadSection = document.getElementById('uploadSection');
    const processingSection = document.getElementById('processingSection');
    const dropZone = document.getElementById('dropZone');
    const csvFileInput = document.getElementById('csvFileInput');
    const fieldsContainer = document.getElementById('fieldsContainer');
    const resetBtn = document.getElementById('resetBtn');
    const exportBtn = document.getElementById('exportBtn');
    const selectAllCheckbox = document.getElementById('selectAll');

    let parsedData = null;
    let originalHeaders = [];

    // --- Drag and Drop Handling ---
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            handleFile(e.dataTransfer.files[0]);
        }
    });

    csvFileInput.addEventListener('change', (e) => {
        if (e.target.files.length) {
            handleFile(e.target.files[0]);
        }
    });

    // --- File Processing ---
    function handleFile(file) {
        if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
            alert('Please upload a valid CSV file.');
            return;
        }

        Papa.parse(file, {
            header: true,
            skipEmptyLines: true,
            complete: function(results) {
                if (results.meta.fields && results.meta.fields.length > 0) {
                    originalHeaders = results.meta.fields;
                    parsedData = results.data;
                    renderFields(originalHeaders);
                    showProcessing();
                } else {
                    alert('Could not read fields from the CSV.');
                }
            },
            error: function(error) {
                alert('Error parsing CSV: ' + error.message);
            }
        });
    }

    // --- UI State Management ---
    function showProcessing() {
        uploadSection.classList.add('hidden');
        processingSection.classList.remove('hidden');
    }

    function resetApp() {
        csvFileInput.value = '';
        parsedData = null;
        originalHeaders = [];
        processingSection.classList.add('hidden');
        uploadSection.classList.remove('hidden');
        fieldsContainer.innerHTML = '';
        selectAllCheckbox.checked = true;
    }

    resetBtn.addEventListener('click', resetApp);

    // --- Dynamic Fields Rendering ---
    function renderFields(headers) {
        fieldsContainer.innerHTML = '';
        headers.forEach((header, index) => {
            const row = document.createElement('div');
            row.className = 'field-row';
            
            row.innerHTML = `
                <div class="col-checkbox">
                    <input type="checkbox" class="field-checkbox" data-index="${index}" checked>
                </div>
                <div class="col-original" title="${header}">${header}</div>
                <div class="col-arrow"><i class="ph ph-arrow-right"></i></div>
                <div class="col-new">
                    <input type="text" class="field-rename" data-index="${index}" value="${header}" placeholder="Rename field...">
                </div>
            `;
            fieldsContainer.appendChild(row);
        });

        // Add event listeners to newly created checkboxes
        const checkboxes = document.querySelectorAll('.field-checkbox');
        checkboxes.forEach(cb => {
            cb.addEventListener('change', (e) => {
                const row = e.target.closest('.field-row');
                if (e.target.checked) {
                    row.classList.remove('disabled');
                } else {
                    row.classList.add('disabled');
                }
                updateSelectAllState();
            });
        });
    }

    // --- Select All Logic ---
    selectAllCheckbox.addEventListener('change', (e) => {
        const isChecked = e.target.checked;
        const checkboxes = document.querySelectorAll('.field-checkbox');
        const rows = document.querySelectorAll('.field-row');
        
        checkboxes.forEach(cb => cb.checked = isChecked);
        rows.forEach(row => {
            if (isChecked) row.classList.remove('disabled');
            else row.classList.add('disabled');
        });
    });

    function updateSelectAllState() {
        const checkboxes = document.querySelectorAll('.field-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        const someChecked = Array.from(checkboxes).some(cb => cb.checked);
        
        selectAllCheckbox.checked = allChecked;
        selectAllCheckbox.indeterminate = !allChecked && someChecked;
    }

    // --- Export Logic ---
    exportBtn.addEventListener('click', async () => {
        if (!parsedData || parsedData.length === 0) return;

        // Gather selected fields and their new names
        const fieldMappings = [];
        const checkboxes = document.querySelectorAll('.field-checkbox');
        const renameInputs = document.querySelectorAll('.field-rename');

        checkboxes.forEach((cb, i) => {
            if (cb.checked) {
                fieldMappings.push({
                    original: originalHeaders[i],
                    new: renameInputs[i].value.trim() || originalHeaders[i] // fallback to original if empty
                });
            }
        });

        if (fieldMappings.length === 0) {
            alert('Please select at least one field to export.');
            return;
        }

        // Transform data
        const transformedData = parsedData.map(row => {
            const newRow = {};
            fieldMappings.forEach(mapping => {
                newRow[mapping.new] = row[mapping.original];
            });
            return newRow;
        });

        const chunkSizeInput = document.getElementById('chunkSize').value;
        const chunkSize = parseInt(chunkSizeInput);
        let groupName = document.getElementById('groupName').value.trim();
        if (!groupName) groupName = 'exported_data';

        if (chunkSize && chunkSize > 0 && chunkSize < transformedData.length) {
            // Multipart Export - Zip it!
            const zip = new JSZip();
            
            for (let i = 0; i < transformedData.length; i += chunkSize) {
                const chunk = transformedData.slice(i, i + chunkSize);
                const csv = Papa.unparse(chunk);
                const partNumber = Math.floor(i / chunkSize) + 1;
                zip.file(`${groupName}_part${partNumber}.csv`, csv);
            }
            
            zip.generateAsync({type:"blob"}).then(function(content) {
                downloadBlob(content, `${groupName}.zip`);
            });
        } else {
            // Single Export
            const csv = Papa.unparse(transformedData);
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            downloadBlob(blob, `${groupName}.csv`);
        }
    });

    function downloadBlob(blob, filename) {
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
});
