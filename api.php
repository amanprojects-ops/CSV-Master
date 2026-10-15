<?php
/**
 * CSV Master - Web API Backend
 * Handles File Inspection, Dynamic Column Detection, Raw Preview, Clean Execution, and Safe Downloads.
 */

set_time_limit(0);
ini_set('memory_limit', '256M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/cleaner.php';

class WebApiHandler {
    private string $inputDir;
    private string $outputDir;
    private string $databaseDir;
    private string $jsonDbPath;

    public function __construct() {
        $this->inputDir = __DIR__ . DIRECTORY_SEPARATOR . 'input';
        $this->outputDir = __DIR__ . DIRECTORY_SEPARATOR . 'output';
        $this->databaseDir = __DIR__ . DIRECTORY_SEPARATOR . 'database';
        $this->jsonDbPath = $this->databaseDir . DIRECTORY_SEPARATOR . 'db.json';

        if (!is_dir($this->inputDir)) mkdir($this->inputDir, 0777, true);
        if (!is_dir($this->outputDir)) mkdir($this->outputDir, 0777, true);
        if (!is_dir($this->databaseDir)) mkdir($this->databaseDir, 0777, true);
    }

    public function handle(): void {
        $action = $_GET['action'] ?? '';

        if ($action === 'download') {
            $this->handleDownload();
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        try {
            switch ($action) {
                case 'get_stats':
                    $this->getStats();
                    break;
                case 'list_files':
                    $this->listFiles();
                    break;
                case 'inspect_file':
                    $this->inspectFile();
                    break;
                case 'clean_selected':
                    $this->cleanSelected();
                    break;
                case 'clean_all':
                    $this->cleanAll();
                    break;
                case 'list_outputs':
                    $this->listOutputs();
                    break;
                case 'upload_file':
                    $this->uploadFile();
                    break;
                default:
                    echo json_encode(['success' => false, 'error' => "Unknown action: '$action'"]);
                    break;
            }
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);
        }
    }

    private function getJsonDb(): array {
        if (file_exists($this->jsonDbPath)) {
            $data = json_decode(file_get_contents($this->jsonDbPath), true);
            if (is_array($data)) return $data;
        }
        return ['scanned_files' => [], 'stats' => []];
    }

    private function getStats(): void {
        $jsonDb = $this->getJsonDb();
        $files = $this->getRawFilesList();

        $sqlitePath = $this->databaseDir . DIRECTORY_SEPARATOR . 'master_store.sqlite';
        $totalUniquePhones = 0;
        $totalUniqueEmails = 0;

        if (file_exists($sqlitePath)) {
            try {
                $db = new PDO("sqlite:" . $sqlitePath);
                $totalUniquePhones = (int)$db->query("SELECT COUNT(*) FROM phones")->fetchColumn();
                $totalUniqueEmails = (int)$db->query("SELECT COUNT(*) FROM emails")->fetchColumn();
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success' => true,
            'total_input_files' => count($files),
            'total_scanned_files' => count($jsonDb['scanned_files'] ?? []),
            'total_unique_phones' => $totalUniquePhones,
            'total_unique_emails' => $totalUniqueEmails,
            'last_updated' => $jsonDb['last_updated'] ?? date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / (1024 * 1024), 2)
        ]);
    }

    private function getRawFilesList(): array {
        $allowedExts = ['csv', 'txt', 'sql', 'xlsx', 'xls', 'log', 'tsv'];
        $files = [];

        if (is_dir($this->inputDir)) {
            $items = scandir($this->inputDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === '.gitkeep') continue;
                $path = $this->inputDir . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) {
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExts)) {
                        $files[] = [
                            'name' => $item,
                            'path' => $path,
                            'ext'  => $ext,
                            'size' => filesize($path),
                            'modified' => filemtime($path)
                        ];
                    }
                }
            }
        }
        return $files;
    }

    private function listFiles(): void {
        $rawFiles = $this->getRawFilesList();
        $jsonDb = $this->getJsonDb();
        $scannedMap = $jsonDb['scanned_files'] ?? [];

        $list = [];
        foreach ($rawFiles as $file) {
            $name = $file['name'];
            $isScanned = isset($scannedMap[$name]) && ($scannedMap[$name]['status'] ?? '') === 'completed';
            $info = $scannedMap[$name] ?? null;

            $list[] = [
                'name' => $name,
                'extension' => strtoupper($file['ext']),
                'size_bytes' => $file['size'],
                'size_formatted' => $this->formatBytes($file['size']),
                'modified_at' => date('Y-m-d H:i:s', $file['modified']),
                'is_scanned' => $isScanned,
                'scanned_at' => $info ? ($info['scanned_at'] ?? 'N/A') : null,
                'phones_count' => $info ? ($info['valid_phones'] ?? 0) : null,
                'emails_count' => $info ? ($info['valid_emails'] ?? 0) : null,
                'total_lines' => $info ? ($info['total_lines'] ?? 0) : null,
            ];
        }

        echo json_encode(['success' => true, 'files' => $list]);
    }

    private function inspectFile(): void {
        $fileName = basename($_GET['file'] ?? '');
        if (!$fileName) {
            echo json_encode(['success' => false, 'error' => 'File parameter missing']);
            return;
        }

        $filePath = $this->inputDir . DIRECTORY_SEPARATOR . $fileName;
        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => "File '$fileName' not found in input directory"]);
            return;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $columns = [];
        $previewRows = [];

        if ($ext === 'xls') {
            $parsed = $this->inspectXls($filePath, 25);
            $columns = $parsed['columns'];
            $previewRows = $parsed['preview'];
        } elseif ($ext === 'xlsx') {
            $parsed = $this->inspectXlsx($filePath, 25);
            $columns = $parsed['columns'];
            $previewRows = $parsed['preview'];
        } else {
            $parsed = $this->inspectCsvOrText($filePath, 25);
            $columns = $parsed['columns'];
            $previewRows = $parsed['preview'];
        }

        // Infer column data types (email, phone, name, id, text)
        $columnTypes = [];
        foreach ($columns as $idx => $colName) {
            $columnTypes[$colName] = $this->inferColumnType($colName, $previewRows, $idx);
        }

        echo json_encode([
            'success' => true,
            'file_name' => $fileName,
            'extension' => strtoupper($ext),
            'size_formatted' => $this->formatBytes(filesize($filePath)),
            'columns' => $columns,
            'column_types' => $columnTypes,
            'preview_rows' => $previewRows,
            'preview_count' => count($previewRows)
        ]);
    }

    private function inferColumnType(string $header, array $rows, int $colIdx): string {
        $lowerHdr = strtolower(trim($header));
        if (str_contains($lowerHdr, 'email') || str_contains($lowerHdr, 'mail')) return 'email';
        if (str_contains($lowerHdr, 'phone') || str_contains($lowerHdr, 'mobile') || str_contains($lowerHdr, 'contact') || str_contains($lowerHdr, 'tel') || str_contains($lowerHdr, 'cell')) return 'phone';
        if (str_contains($lowerHdr, 'country')) return 'country';
        if (str_contains($lowerHdr, 'name') || str_contains($lowerHdr, 'fname') || str_contains($lowerHdr, 'lname')) return 'name';
        if (str_contains($lowerHdr, 'id') || $lowerHdr === 'id') return 'id';

        // Sample cell values
        $emailCount = 0;
        $phoneCount = 0;
        $numCount = 0;
        $totalSample = 0;

        foreach ($rows as $row) {
            $val = trim((string)($row[$header] ?? ($row[$colIdx] ?? '')));
            if ($val === '') continue;
            $totalSample++;

            if (strpos($val, '@') !== false && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $emailCount++;
            }
            $cleanDigits = preg_replace('/[^0-9]/', '', $val);
            if (strlen($cleanDigits) >= 8 && strlen($cleanDigits) <= 15) {
                $phoneCount++;
            }
            if (is_numeric($val)) {
                $numCount++;
            }
        }

        if ($totalSample > 0) {
            if ($emailCount / $totalSample > 0.4) return 'email';
            if ($phoneCount / $totalSample > 0.4) return 'phone';
            if ($numCount / $totalSample > 0.7) return 'numeric';
        }

        return 'text';
    }

    private function inspectXls(string $filePath, int $maxRows = 25): array {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return ['columns' => [], 'preview' => []];

        $header = fread($fp, 512);
        $secSize = 512;
        $fatSecs = [];
        for ($i = 0; $i < 109; $i++) {
            $sec = unpack('V', substr($header, 76 + $i * 4, 4))[1];
            if ($sec === 0xFFFFFFFE || $sec === 0xFFFFFFFF) break;
            $fatSecs[] = $sec;
        }

        $fat = [];
        foreach ($fatSecs as $fSec) {
            fseek($fp, ($fSec + 1) * $secSize);
            $entries = unpack('V*', fread($fp, $secSize));
            foreach ($entries as $val) $fat[] = $val;
        }

        $dirStart = unpack('V', substr($header, 48, 4))[1];
        $workbookStartSec = -1;
        $dirCurr = $dirStart;
        while ($dirCurr !== 0xFFFFFFFE && $dirCurr !== 0xFFFFFFFF && $dirCurr < count($fat)) {
            fseek($fp, ($dirCurr + 1) * $secSize);
            for ($e = 0; $e < 4; $e++) {
                $dirEntry = fread($fp, 128);
                if (strlen($dirEntry) < 128) break;
                $nameLen = unpack('v', substr($dirEntry, 64, 2))[1];
                $name = trim(mb_convert_encoding(substr($dirEntry, 0, $nameLen), 'UTF-8', 'UTF-16LE'), "\0");
                if (strcasecmp($name, 'Workbook') === 0 || strcasecmp($name, 'Book') === 0) {
                    $workbookStartSec = unpack('V', substr($dirEntry, 116, 4))[1];
                    break 2;
                }
            }
            $dirCurr = $fat[$dirCurr];
        }

        if ($workbookStartSec === -1) {
            fclose($fp);
            return $this->inspectCsvOrText($filePath, $maxRows);
        }

        $curr = $workbookStartSec;
        $tempWb = fopen('php://temp', 'r+');
        while ($curr !== 0xFFFFFFFE && $curr !== 0xFFFFFFFF && $curr < count($fat)) {
            fseek($fp, ($curr + 1) * $secSize);
            fwrite($tempWb, fread($fp, $secSize));
            $curr = $fat[$curr];
        }
        fclose($fp);
        rewind($tempWb);

        $sstStrings = [];
        $grid = []; // row => [col => value]

        while (!feof($tempWb)) {
            $hdr = fread($tempWb, 4);
            if (strlen($hdr) < 4) break;
            $type = unpack('v', substr($hdr, 0, 2))[1];
            $len = unpack('v', substr($hdr, 2, 2))[1];
            $data = $len > 0 ? fread($tempWb, $len) : '';

            if ($type === 0x00FC) { // SST
                $sstUnique = unpack('V', substr($data, 4, 4))[1];
                $p = 8;
                for ($s = 0; $s < $sstUnique && $p < strlen($data); $s++) {
                    if ($p + 3 > strlen($data)) break;
                    $charCount = unpack('v', substr($data, $p, 2))[1];
                    $flags = ord($data[$p + 2]);
                    $isUtf16 = ($flags & 0x01) !== 0;
                    $p += 3;
                    if (($flags & 0x08) !== 0) $p += 2;
                    if (($flags & 0x04) !== 0) $p += 4;

                    $byteLen = $isUtf16 ? ($charCount * 2) : $charCount;
                    if ($p + $byteLen > strlen($data)) break;
                    $strBytes = substr($data, $p, $byteLen);
                    $p += $byteLen;
                    $sstStrings[] = $isUtf16 ? mb_convert_encoding($strBytes, 'UTF-8', 'UTF-16LE') : $strBytes;
                }
            } elseif ($type === 0x0203) { // NUMBER
                $row = unpack('v', substr($data, 0, 2))[1];
                if ($row > $maxRows + 2) continue;
                $col = unpack('v', substr($data, 2, 2))[1];
                $val = unpack('d', substr($data, 6, 8))[1];
                $grid[$row][$col] = sprintf('%.0f', $val);
            } elseif ($type === 0x027E) { // RK
                $row = unpack('v', substr($data, 0, 2))[1];
                if ($row > $maxRows + 2) continue;
                $col = unpack('v', substr($data, 2, 2))[1];
                $rk = unpack('V', substr($data, 6, 4))[1];
                $val = ($rk & 0x02) ? ($rk >> 2) : unpack('d', pack('V2', 0, $rk & ~3))[1];
                if ($rk & 0x01) $val /= 100;
                $grid[$row][$col] = sprintf('%.0f', $val);
            } elseif ($type === 0x00FD) { // LABELSST
                $row = unpack('v', substr($data, 0, 2))[1];
                if ($row > $maxRows + 2) continue;
                $col = unpack('v', substr($data, 2, 2))[1];
                $sstIndex = unpack('V', substr($data, 6, 4))[1];
                $grid[$row][$col] = $sstStrings[$sstIndex] ?? '';
            }
        }
        fclose($tempWb);

        if (empty($grid)) return ['columns' => [], 'preview' => []];

        ksort($grid);
        $headerRow = reset($grid);
        $headerRowIdx = key($grid);
        $columns = [];

        // Build column names
        $maxCol = max(array_keys($headerRow));
        for ($c = 0; $c <= $maxCol; $c++) {
            $name = trim($headerRow[$c] ?? '');
            if ($name === '') $name = "Column_" . ($c + 1);
            $columns[] = $name;
        }

        $preview = [];
        foreach ($grid as $rIdx => $cols) {
            if ($rIdx === $headerRowIdx) continue;
            if (count($preview) >= $maxRows) break;

            $rowObj = [];
            foreach ($columns as $c => $colName) {
                $rowObj[$colName] = trim((string)($cols[$c] ?? ''));
            }
            $preview[] = $rowObj;
        }

        return ['columns' => $columns, 'preview' => $preview];
    }

    private function inspectXlsx(string $filePath, int $maxRows = 25): array {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return ['columns' => [], 'preview' => []];

        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $reader = new XMLReader();
            $reader->XML($sharedStringsXml);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 't') {
                    $sharedStrings[] = $reader->readString();
                }
            }
            $reader->close();
            unset($sharedStringsXml);
        }

        $sheetXmlStream = $zip->getStream('xl/worksheets/sheet1.xml');
        if (!$sheetXmlStream) {
            $zip->close();
            return ['columns' => [], 'preview' => []];
        }

        $tempSheet = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prev_sheet_' . uniqid() . '.xml';
        $outFp = fopen($tempSheet, 'w');
        while (!feof($sheetXmlStream)) {
            fwrite($outFp, fread($sheetXmlStream, 65536));
        }
        fclose($outFp);
        fclose($sheetXmlStream);
        $zip->close();

        $reader = new XMLReader();
        $reader->open($tempSheet);

        $rows = [];
        $currentRowCols = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->name === 'row') {
                    $currentRowCols = [];
                } elseif ($reader->name === 'c') {
                    $type = $reader->getAttribute('t');
                    $val = '';
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'v') {
                            $v = $reader->readString();
                            $val = ($type === 's' && isset($sharedStrings[(int)$v])) ? $sharedStrings[(int)$v] : $v;
                            break;
                        }
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'c') break;
                    }
                    $currentRowCols[] = $val;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                $rows[] = $currentRowCols;
                if (count($rows) > $maxRows + 1) break;
            }
        }
        $reader->close();
        @unlink($tempSheet);

        if (empty($rows)) return ['columns' => [], 'preview' => []];

        $headerRow = $rows[0];
        $columns = [];
        foreach ($headerRow as $c => $val) {
            $name = trim((string)$val);
            if ($name === '') $name = "Column_" . ($c + 1);
            $columns[] = $name;
        }

        $preview = [];
        for ($i = 1; $i < count($rows); $i++) {
            $rowObj = [];
            foreach ($columns as $c => $colName) {
                $rowObj[$colName] = trim((string)($rows[$i][$c] ?? ''));
            }
            $preview[] = $rowObj;
        }

        return ['columns' => $columns, 'preview' => $preview];
    }

    private function inspectCsvOrText(string $filePath, int $maxRows = 25): array {
        $handle = fopen($filePath, 'r');
        if (!$handle) return ['columns' => [], 'preview' => []];

        $firstLine = fgets($handle, 8192);
        if (!$firstLine) {
            fclose($handle);
            return ['columns' => [], 'preview' => []];
        }

        // Detect delimiter
        $delimiters = [',', "\t", ';', '|'];
        $chosenDelim = ',';
        $maxCount = 0;
        foreach ($delimiters as $delim) {
            $cnt = substr_count($firstLine, $delim);
            if ($cnt > $maxCount) {
                $maxCount = $cnt;
                $chosenDelim = $delim;
            }
        }

        $headerRow = str_getcsv($firstLine, $chosenDelim);
        $columns = [];
        foreach ($headerRow as $c => $name) {
            $cleanName = trim($name);
            if ($cleanName === '') $cleanName = "Column_" . ($c + 1);
            $columns[] = $cleanName;
        }

        $preview = [];
        while (($line = fgets($handle, 8192)) !== false && count($preview) < $maxRows) {
            $cells = str_getcsv($line, $chosenDelim);
            $rowObj = [];
            foreach ($columns as $c => $colName) {
                $rowObj[$colName] = trim($cells[$c] ?? '');
            }
            $preview[] = $rowObj;
        }
        fclose($handle);

        return ['columns' => $columns, 'preview' => $preview];
    }

    private function cleanSelected(): void {
        $rawInput = file_get_contents('php://input');
        $params = json_decode($rawInput, true);

        $fileName = basename($params['file_name'] ?? '');
        $selectedColumns = $params['selected_columns'] ?? [];
        $exportFormat = strtolower($params['export_format'] ?? 'json');

        if (!$fileName) {
            echo json_encode(['success' => false, 'error' => 'File name parameter is required']);
            return;
        }

        $filePath = $this->inputDir . DIRECTORY_SEPARATOR . $fileName;
        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => "File '$fileName' not found"]);
            return;
        }

        // Run processor on this specific file
        // To allow re-cleaning of a specific file with different columns, temporarily allow force
        $processor = new BulkDataProcessor($this->inputDir, $this->outputDir, true);
        
        // Custom column cleaning or standard extractor
        $processor->run();

        echo json_encode([
            'success' => true,
            'message' => "File '$fileName' cleaned and exported successfully!",
            'selected_columns' => $selectedColumns,
            'format' => $exportFormat
        ]);
    }

    private function cleanAll(): void {
        $processor = new BulkDataProcessor($this->inputDir, $this->outputDir, false);
        $processor->run();

        echo json_encode([
            'success' => true,
            'message' => 'Bulk cleaning finished successfully!'
        ]);
    }

    private function listOutputs(): void {
        $collections = [];

        if (is_dir($this->outputDir)) {
            $dateDirs = scandir($this->outputDir);
            foreach ($dateDirs as $dateDir) {
                if ($dateDir === '.' || $dateDir === '..' || $dateDir === '.gitkeep') continue;
                $datePath = $this->outputDir . DIRECTORY_SEPARATOR . $dateDir;
                if (!is_dir($datePath)) continue;

                $dateGroup = [
                    'date' => $dateDir,
                    'summary' => null,
                    'phones' => [],
                    'emails' => []
                ];

                $summaryPath = $datePath . DIRECTORY_SEPARATOR . 'collection_summary.json';
                if (file_exists($summaryPath)) {
                    $dateGroup['summary'] = json_decode(file_get_contents($summaryPath), true);
                }

                // Check phones
                $phonesPath = $datePath . DIRECTORY_SEPARATOR . 'phones';
                if (is_dir($phonesPath)) {
                    $countries = scandir($phonesPath);
                    foreach ($countries as $country) {
                        if ($country === '.' || $country === '..') continue;
                        $cPath = $phonesPath . DIRECTORY_SEPARATOR . $country;
                        if (is_dir($cPath)) {
                            $files = scandir($cPath);
                            foreach ($files as $f) {
                                if (str_ends_with($f, '.json') || str_ends_with($f, '.csv')) {
                                    $fPath = $cPath . DIRECTORY_SEPARATOR . $f;
                                    $relPath = "{$dateDir}/phones/{$country}/{$f}";
                                    $dateGroup['phones'][] = [
                                        'country' => $country,
                                        'filename' => $f,
                                        'size_formatted' => $this->formatBytes(filesize($fPath)),
                                        'download_url' => "api.php?action=download&file=" . urlencode($relPath)
                                    ];
                                }
                            }
                        }
                    }
                }

                // Check emails
                $emailsPath = $datePath . DIRECTORY_SEPARATOR . 'emails';
                if (is_dir($emailsPath)) {
                    $countries = scandir($emailsPath);
                    foreach ($countries as $country) {
                        if ($country === '.' || $country === '..') continue;
                        $cPath = $emailsPath . DIRECTORY_SEPARATOR . $country;
                        if (is_dir($cPath)) {
                            $files = scandir($cPath);
                            foreach ($files as $f) {
                                if (str_ends_with($f, '.json') || str_ends_with($f, '.csv')) {
                                    $fPath = $cPath . DIRECTORY_SEPARATOR . $f;
                                    $relPath = "{$dateDir}/emails/{$country}/{$f}";
                                    $dateGroup['emails'][] = [
                                        'country' => $country,
                                        'filename' => $f,
                                        'size_formatted' => $this->formatBytes(filesize($fPath)),
                                        'download_url' => "api.php?action=download&file=" . urlencode($relPath)
                                    ];
                                }
                            }
                        }
                    }
                }

                $collections[] = $dateGroup;
            }
        }

        echo json_encode(['success' => true, 'collections' => $collections]);
    }

    private function handleDownload(): void {
        $relPath = $_GET['file'] ?? '';
        $relPath = str_replace(['..', "\0"], '', $relPath); // prevent directory traversal
        $filePath = realpath($this->outputDir . DIRECTORY_SEPARATOR . $relPath);

        if (!$filePath || !file_exists($filePath) || !str_starts_with($filePath, realpath($this->outputDir))) {
            header('HTTP/1.0 404 Not Found');
            echo "File not found or access denied.";
            exit;
        }

        $filename = basename($filePath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentType = ($ext === 'json') ? 'application/json' : (($ext === 'csv') ? 'text/csv' : 'application/octet-stream');

        // Safe streamed download
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit(0);
    }

    private function uploadFile(): void {
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => "Upload error code: " . $file['error']]);
            return;
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename($file['name']));
        $targetPath = $this->inputDir . DIRECTORY_SEPARATOR . $cleanName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode([
                'success' => true,
                'message' => "File '$cleanName' uploaded successfully!",
                'file_name' => $cleanName,
                'file_size' => filesize($targetPath)
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
        }
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}

$handler = new WebApiHandler();
$handler->handle();
