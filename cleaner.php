<?php
/**
 * Ultra-Fast Low-RAM Bulk Data Cleaner & JSON Extractor
 * Designed for processing Millions of records on low-end laptops.
 * Supports: .csv, .txt, .sql, .xlsx, .xls (BIFF8), .log, .tsv
 * Features:
 *  - Persistent Local JSON Database (database/db.json) to track scanned files & skip duplicates.
 *  - Same-Date Multi-File Merging: Unifies data from multiple different files on the same date into the same country collections.
 *  - Zero-RAM Streaming Engine (< 20MB RAM usage).
 */

set_time_limit(0);
ini_set('memory_limit', '128M');

class BulkDataProcessor {
    private PDO $db;
    private string $inputDir;
    private string $outputBaseDir;
    private string $databaseDir;
    private string $jsonDbPath;
    private array $jsonDb = [];
    private bool $forceRescan = false;
    private string $runDate;
    private string $runTimestamp;
    private array $verifiedDomains = [];
    private array $disposableDomains = [];
    private int $totalLinesThisRun = 0;
    private int $validEmailsThisRun = 0;
    private int $validPhonesThisRun = 0;
    private float $startTime;

    // Dial codes mapping to country names (sorted by prefix length)
    private array $countryDialCodes = [
        '+971' => 'UAE',
        '+966' => 'Saudi_Arabia',
        '+880' => 'Bangladesh',
        '+353' => 'Ireland',
        '+234' => 'Nigeria',
        '+977' => 'Nepal',
        '+965' => 'Kuwait',
        '+974' => 'Qatar',
        '+968' => 'Oman',
        '+973' => 'Bahrain',
        '+964' => 'Iraq',
        '+962' => 'Jordan',
        '+91'  => 'India',
        '+44'  => 'United_Kingdom',
        '+92'  => 'Pakistan',
        '+61'  => 'Australia',
        '+49'  => 'Germany',
        '+33'  => 'France',
        '+65'  => 'Singapore',
        '+60'  => 'Malaysia',
        '+20'  => 'Egypt',
        '+27'  => 'South_Africa',
        '+81'  => 'Japan',
        '+82'  => 'South_Korea',
        '+86'  => 'China',
        '+55'  => 'Brazil',
        '+39'  => 'Italy',
        '+34'  => 'Spain',
        '+90'  => 'Turkey',
        '+62'  => 'Indonesia',
        '+63'  => 'Philippines',
        '+94'  => 'Sri_Lanka',
        '+41'  => 'Switzerland',
        '+31'  => 'Netherlands',
        '+46'  => 'Sweden',
        '+47'  => 'Norway',
        '+45'  => 'Denmark',
        '+48'  => 'Poland',
        '+64'  => 'New_Zealand',
        '+7'   => 'Russia_Kazakhstan',
        '+1'   => 'USA_Canada',
    ];

    // Country ccTLD mappings for email domain categorization
    private array $cctldCountryMap = [
        '.in'     => 'India',
        '.co.in'  => 'India',
        '.net.in' => 'India',
        '.org.in' => 'India',
        '.us'     => 'USA_Canada',
        '.ca'     => 'USA_Canada',
        '.uk'     => 'United_Kingdom',
        '.co.uk'  => 'United_Kingdom',
        '.ae'     => 'UAE',
        '.sa'     => 'Saudi_Arabia',
        '.pk'     => 'Pakistan',
        '.bd'     => 'Bangladesh',
        '.au'     => 'Australia',
        '.com.au' => 'Australia',
        '.de'     => 'Germany',
        '.fr'     => 'France',
        '.sg'     => 'Singapore',
        '.my'     => 'Malaysia',
        '.eg'     => 'Egypt',
        '.ng'     => 'Nigeria',
        '.za'     => 'South_Africa',
        '.ru'     => 'Russia_Kazakhstan',
        '.jp'     => 'Japan',
        '.kr'     => 'South_Korea',
        '.cn'     => 'China',
        '.br'     => 'Brazil',
        '.it'     => 'Italy',
        '.es'     => 'Spain',
        '.tr'     => 'Turkey',
        '.id'     => 'Indonesia',
        '.ph'     => 'Philippines',
        '.np'     => 'Nepal',
        '.lk'     => 'Sri_Lanka',
        '.kw'     => 'Kuwait',
        '.qa'     => 'Qatar',
        '.om'     => 'Oman',
        '.bh'     => 'Bahrain',
        '.nz'     => 'New_Zealand',
        '.ie'     => 'Ireland',
    ];

    public function __construct(string $inputDir = 'input', string $outputDir = 'output', bool $forceRescan = false) {
        $this->inputDir = rtrim($inputDir, '/\\');
        $this->outputBaseDir = rtrim($outputDir, '/\\');
        $this->databaseDir = __DIR__ . DIRECTORY_SEPARATOR . 'database';
        $this->jsonDbPath = $this->databaseDir . DIRECTORY_SEPARATOR . 'db.json';
        $this->forceRescan = $forceRescan;
        $this->runDate = date('Y-m-d');
        $this->runTimestamp = date('Y-m-d H:i:s');

        if (!is_dir($this->inputDir)) {
            mkdir($this->inputDir, 0777, true);
        }
        if (!is_dir($this->outputBaseDir)) {
            mkdir($this->outputBaseDir, 0777, true);
        }
        if (!is_dir($this->databaseDir)) {
            mkdir($this->databaseDir, 0777, true);
        }

        $this->loadJsonDatabase();
        $this->initPersistentDatabase();
        $this->loadDisposableBlacklist();
        $this->startTime = microtime(true);
    }

    private function loadJsonDatabase(): void {
        if (file_exists($this->jsonDbPath)) {
            $content = file_get_contents($this->jsonDbPath);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $this->jsonDb = $decoded;
            }
        }

        if (empty($this->jsonDb)) {
            $this->jsonDb = [
                'created_at' => $this->runTimestamp,
                'last_updated' => $this->runTimestamp,
                'total_files_scanned' => 0,
                'scanned_files' => [],
                'stats' => [
                    'total_unique_phones' => 0,
                    'total_unique_emails' => 0,
                ]
            ];
            $this->saveJsonDatabase();
        }
    }

    private function saveJsonDatabase(): void {
        $this->jsonDb['last_updated'] = date('Y-m-d H:i:s');
        $this->jsonDb['total_files_scanned'] = count($this->jsonDb['scanned_files']);
        
        file_put_contents(
            $this->jsonDbPath,
            json_encode($this->jsonDb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function initPersistentDatabase(): void {
        $sqlitePath = $this->databaseDir . DIRECTORY_SEPARATOR . 'master_store.sqlite';

        $this->db = new PDO("sqlite:" . $sqlitePath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // High-speed SQLite PRAGMAs (Zero-RAM, Maximum throughput)
        $this->db->exec("PRAGMA synchronous = OFF;");
        $this->db->exec("PRAGMA journal_mode = WAL;");
        $this->db->exec("PRAGMA temp_store = MEMORY;");
        $this->db->exec("PRAGMA cache_size = 10000;");

        // Unique indexes ensure automatic deduplication across files
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS emails (
                email TEXT PRIMARY KEY,
                domain TEXT,
                country TEXT,
                input_file_name TEXT,
                extracted_date TEXT,
                run_date TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_email_country ON emails(country);
            CREATE INDEX IF NOT EXISTS idx_email_rundate ON emails(run_date);

            CREATE TABLE IF NOT EXISTS phones (
                phone TEXT PRIMARY KEY,
                country TEXT,
                country_code TEXT,
                input_file_name TEXT,
                extracted_date TEXT,
                run_date TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_phone_country ON phones(country);
            CREATE INDEX IF NOT EXISTS idx_phone_rundate ON phones(run_date);
        ");
    }

    private function loadDisposableBlacklist(): void {
        $list = [
            'mailinator.com', 'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'trashmail.com', 'throwawaymail.com', 'sharklasers.com', 'getairmail.com',
            'yopmail.com', 'dispostable.com', 'fakemailgenerator.com', 'generator.email',
            'emailondeck.com', 'crazymailing.com', 'temp-mail.org', 'nada.ltd'
        ];
        $this->disposableDomains = array_flip($list);
    }

    public function run(): void {
        echo "\n======================================================\n";
        echo "   CSV MASTER - HIGH SPEED BULK DATA PROCESSOR        \n";
        echo "   Zero-RAM Engine | Local JSON DB | Smart Merging    \n";
        echo "======================================================\n\n";

        $allFiles = $this->scanInputFiles();
        if (empty($allFiles)) {
            echo "[INFO] Input folder '{$this->inputDir}' is empty!\n";
            echo "Put your .csv, .txt, .xlsx, .xls, or .sql files in '{$this->inputDir}' and run again.\n\n";
            return;
        }

        // Determine which files to process vs skip
        $filesToProcess = [];
        $skippedFiles = [];

        foreach ($allFiles as $filePath) {
            $filename = basename($filePath);
            $fileSize = filesize($filePath);

            if (!$this->forceRescan && isset($this->jsonDb['scanned_files'][$filename])) {
                $info = $this->jsonDb['scanned_files'][$filename];
                if (($info['status'] ?? '') === 'completed' && ($info['file_size'] ?? 0) === $fileSize) {
                    $skippedFiles[] = [
                        'name' => $filename,
                        'size' => $fileSize,
                        'scanned_at' => $info['scanned_at'] ?? 'Previously',
                        'phones' => $info['valid_phones'] ?? 0,
                        'emails' => $info['valid_emails'] ?? 0
                    ];
                    continue;
                }
            }
            $filesToProcess[] = $filePath;
        }

        if (!empty($skippedFiles)) {
            echo "Already Scanned Files (Skipping to avoid duplicate work):\n";
            foreach ($skippedFiles as $sf) {
                $sizeMb = round($sf['size'] / (1024 * 1024), 2);
                echo "  [SKIPPED] {$sf['name']} ({$sizeMb} MB) -> Scanned: {$sf['scanned_at']} | Phones: " . number_format($sf['phones']) . " | Emails: " . number_format($sf['emails']) . "\n";
            }
            echo "------------------------------------------------------\n";
        }

        if (empty($filesToProcess)) {
            echo "\n[INFO] All " . count($allFiles) . " file(s) in '{$this->inputDir}' have ALREADY been scanned!\n";
            echo "Local JSON Database ('{$this->jsonDbPath}') is fully up to date.\n";
            echo "Tip: Put new files into '{$this->inputDir}' to process them, or run 'php cleaner.php --rescan' to re-process all.\n\n";
            $this->printCurrentStats();
            return;
        }

        echo "\nFound " . count($filesToProcess) . " NEW file(s) to process:\n";
        foreach ($filesToProcess as $file) {
            $sizeMb = round(filesize($file) / (1024 * 1024), 2);
            echo "  + " . basename($file) . " ({$sizeMb} MB)\n";
        }
        echo "\nStarting extraction & cleaning...\n";
        echo "------------------------------------------------------\n";

        foreach ($filesToProcess as $file) {
            $this->processFile($file);
        }

        echo "\n------------------------------------------------------\n";
        echo "Extraction completed! Updating Local JSON DB & Merging Country Collections...\n";

        // Update overall stats in JSON DB
        $totalUniqueEmails = (int)$this->db->query("SELECT COUNT(*) FROM emails")->fetchColumn();
        $totalUniquePhones = (int)$this->db->query("SELECT COUNT(*) FROM phones")->fetchColumn();
        $this->jsonDb['stats']['total_unique_phones'] = $totalUniquePhones;
        $this->jsonDb['stats']['total_unique_emails'] = $totalUniqueEmails;
        $this->saveJsonDatabase();

        $outputDateDir = "{$this->outputBaseDir}/{$this->runDate}";
        if (!is_dir($outputDateDir)) {
            mkdir($outputDateDir, 0777, true);
        }

        $this->exportJsonResults($outputDateDir);
        $this->printSummary($outputDateDir);
    }

    private function scanInputFiles(): array {
        $allowedExts = ['csv', 'txt', 'sql', 'xlsx', 'xls', 'log', 'tsv'];
        $files = [];

        $dirIterator = new RecursiveDirectoryIterator($this->inputDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($dirIterator);

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts)) {
                    $files[] = $item->getPathname();
                }
            }
        }
        return $files;
    }

    private function processFile(string $filePath): void {
        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        echo "\nProcessing: $filename ...\n";

        $fileStartLines = $this->totalLinesThisRun;
        $fileStartEmails = $this->validEmailsThisRun;
        $fileStartPhones = $this->validPhonesThisRun;

        if ($ext === 'xlsx') {
            $this->processXlsxFile($filePath, $filename);
        } elseif ($ext === 'xls') {
            $this->processXlsFile($filePath, $filename);
        } else {
            $this->processTextOrCsvFile($filePath, $filename);
        }

        $fileLines = $this->totalLinesThisRun - $fileStartLines;
        $fileEmails = $this->validEmailsThisRun - $fileStartEmails;
        $filePhones = $this->validPhonesThisRun - $fileStartPhones;

        // Record in Local JSON DB
        $this->jsonDb['scanned_files'][$filename] = [
            'file_name'    => $filename,
            'file_size'    => $fileSize,
            'file_hash'    => md5_file($filePath),
            'scanned_at'   => date('Y-m-d H:i:s'),
            'run_date'     => $this->runDate,
            'total_lines'  => $fileLines,
            'valid_emails' => $fileEmails,
            'valid_phones' => $filePhones,
            'status'       => 'completed'
        ];
        $this->saveJsonDatabase();
    }

    /**
     * Line-by-line stream parser for .txt, .csv, .sql, .log, .tsv
     */
    private function processTextOrCsvFile(string $filePath, string $filename): void {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            echo "[ERROR] Cannot open file: $filename\n";
            return;
        }

        $emailStmt = $this->db->prepare("INSERT OR IGNORE INTO emails (email, domain, country, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");
        $phoneStmt = $this->db->prepare("INSERT OR IGNORE INTO phones (phone, country, country_code, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");

        $this->db->beginTransaction();
        $batchCount = 0;

        while (($line = fgets($handle, 8192)) !== false) {
            $this->totalLinesThisRun++;

            $this->extractDataFromLine($line, $filename, $emailStmt, $phoneStmt);

            $batchCount++;
            if ($batchCount >= 5000) {
                $this->db->commit();
                $this->db->beginTransaction();
                $batchCount = 0;
                $this->printLiveProgress();
            }
        }

        $this->db->commit();
        fclose($handle);
        $this->printLiveProgress(true);
    }

    /**
     * Native High-Speed Low-RAM Parser for Binary Excel (.xls / BIFF8)
     */
    private function processXlsFile(string $filePath, string $filename): void {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return;

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
            $this->processTextOrCsvFile($filePath, $filename);
            return;
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

        $emailStmt = $this->db->prepare("INSERT OR IGNORE INTO emails (email, domain, country, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");
        $phoneStmt = $this->db->prepare("INSERT OR IGNORE INTO phones (phone, country, country_code, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");

        $this->db->beginTransaction();
        $batchCount = 0;
        $sstStrings = [];
        $currentRow = -1;
        $rowLine = '';

        while (!feof($tempWb)) {
            $hdr = fread($tempWb, 4);
            if (strlen($hdr) < 4) break;
            $type = unpack('v', substr($hdr, 0, 2))[1];
            $len = unpack('v', substr($hdr, 2, 2))[1];
            $data = $len > 0 ? fread($tempWb, $len) : '';

            if ($type === 0x00FC) { // SST
                $sstTotal = unpack('V', substr($data, 0, 4))[1];
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
                $val = unpack('d', substr($data, 6, 8))[1];
                $numStr = sprintf('%.0f', $val);

                if ($row !== $currentRow) {
                    if ($rowLine !== '') {
                        $this->totalLinesThisRun++;
                        $this->extractDataFromLine($rowLine, $filename, $emailStmt, $phoneStmt);
                        $batchCount++;
                    }
                    $currentRow = $row;
                    $rowLine = $numStr;
                } else {
                    $rowLine .= ' ' . $numStr;
                }
            } elseif ($type === 0x027E) { // RK
                $row = unpack('v', substr($data, 0, 2))[1];
                $rk = unpack('V', substr($data, 6, 4))[1];
                $val = ($rk & 0x02) ? ($rk >> 2) : unpack('d', pack('V2', 0, $rk & ~3))[1];
                if ($rk & 0x01) $val /= 100;
                $numStr = sprintf('%.0f', $val);

                if ($row !== $currentRow) {
                    if ($rowLine !== '') {
                        $this->totalLinesThisRun++;
                        $this->extractDataFromLine($rowLine, $filename, $emailStmt, $phoneStmt);
                        $batchCount++;
                    }
                    $currentRow = $row;
                    $rowLine = $numStr;
                } else {
                    $rowLine .= ' ' . $numStr;
                }
            } elseif ($type === 0x00FD) { // LABELSST
                $row = unpack('v', substr($data, 0, 2))[1];
                $sstIndex = unpack('V', substr($data, 6, 4))[1];
                $str = $sstStrings[$sstIndex] ?? '';

                if ($row !== $currentRow) {
                    if ($rowLine !== '') {
                        $this->totalLinesThisRun++;
                        $this->extractDataFromLine($rowLine, $filename, $emailStmt, $phoneStmt);
                        $batchCount++;
                    }
                    $currentRow = $row;
                    $rowLine = $str;
                } else {
                    $rowLine .= ' ' . $str;
                }
            }

            if ($batchCount >= 5000) {
                $this->db->commit();
                $this->db->beginTransaction();
                $batchCount = 0;
                $this->printLiveProgress();
            }
        }

        if ($rowLine !== '') {
            $this->totalLinesThisRun++;
            $this->extractDataFromLine($rowLine, $filename, $emailStmt, $phoneStmt);
        }

        $this->db->commit();
        fclose($tempWb);
        unset($sstStrings);
        $this->printLiveProgress(true);
    }

    /**
     * Ultra-fast XMLReader streaming for .xlsx files
     */
    private function processXlsxFile(string $filePath, string $filename): void {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            echo "[ERROR] Cannot open XLSX archive: $filename\n";
            return;
        }

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
            echo "[WARN] No sheet1.xml found in $filename\n";
            $zip->close();
            return;
        }

        $emailStmt = $this->db->prepare("INSERT OR IGNORE INTO emails (email, domain, country, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");
        $phoneStmt = $this->db->prepare("INSERT OR IGNORE INTO phones (phone, country, country_code, input_file_name, extracted_date, run_date) VALUES (?, ?, ?, ?, ?, ?)");

        $this->db->beginTransaction();
        $batchCount = 0;

        $tempSheet = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'temp_sheet_' . uniqid() . '.xml';
        $outFp = fopen($tempSheet, 'w');
        while (!feof($sheetXmlStream)) {
            fwrite($outFp, fread($sheetXmlStream, 65536));
        }
        fclose($outFp);
        fclose($sheetXmlStream);
        $zip->close();

        $xmlReader = new XMLReader();
        $xmlReader->open($tempSheet);

        $rowText = '';
        while ($xmlReader->read()) {
            if ($xmlReader->nodeType === XMLReader::ELEMENT) {
                if ($xmlReader->name === 'row') {
                    $rowText = '';
                } elseif ($xmlReader->name === 'c') {
                    $type = $xmlReader->getAttribute('t');
                    $value = '';
                    while ($xmlReader->read()) {
                        if ($xmlReader->nodeType === XMLReader::ELEMENT && $xmlReader->name === 'v') {
                            $val = $xmlReader->readString();
                            if ($type === 's' && isset($sharedStrings[(int)$val])) {
                                $value = $sharedStrings[(int)$val];
                            } else {
                                $value = $val;
                            }
                            break;
                        }
                        if ($xmlReader->nodeType === XMLReader::END_ELEMENT && $xmlReader->name === 'c') {
                            break;
                        }
                    }
                    $rowText .= ' ' . $value;
                }
            } elseif ($xmlReader->nodeType === XMLReader::END_ELEMENT && $xmlReader->name === 'row') {
                $this->totalLinesThisRun++;
                $this->extractDataFromLine($rowText, $filename, $emailStmt, $phoneStmt);

                $batchCount++;
                if ($batchCount >= 5000) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    $batchCount = 0;
                    $this->printLiveProgress();
                }
            }
        }

        $this->db->commit();
        $xmlReader->close();
        @unlink($tempSheet);
        unset($sharedStrings);
        $this->printLiveProgress(true);
    }

    private function extractDataFromLine(string $line, string $filename, PDOStatement $emailStmt, PDOStatement $phoneStmt): void {
        $extractedPhonesInLine = [];

        // 1. PHONE NUMBER EXTRACTION
        if (preg_match_all('/(?:\+|00)?([0-9]{1,4})?[\s.-]?\(?([0-9]{2,4})\)?[\s.-]?([0-9]{3,4})[\s.-]?([0-9]{3,5})/', $line, $phoneMatches)) {
            foreach ($phoneMatches[0] as $rawCandidate) {
                $phone = $this->cleanAndValidatePhone($rawCandidate);
                if ($phone !== null) {
                    $phoneStmt->execute([
                        $phone['number'],
                        $phone['country'],
                        $phone['code'],
                        $filename,
                        $this->runTimestamp,
                        $this->runDate
                    ]);
                    $this->validPhonesThisRun++;
                    $extractedPhonesInLine[] = $phone;
                }
            }
        }

        // 2. EMAIL EXTRACTION
        if (strpos($line, '@') !== false) {
            if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $matches)) {
                $lineCountry = !empty($extractedPhonesInLine) ? $extractedPhonesInLine[0]['country'] : null;

                foreach ($matches[0] as $candidate) {
                    $email = strtolower(trim($candidate));
                    if ($this->isValidEmail($email)) {
                        $domain = substr(strrchr($email, "@"), 1);
                        $country = $this->detectEmailCountry($domain, $lineCountry);

                        $emailStmt->execute([
                            $email,
                            $domain,
                            $country,
                            $filename,
                            $this->runTimestamp,
                            $this->runDate
                        ]);
                        $this->validEmailsThisRun++;
                    }
                }
            }
        }
    }

    private function detectEmailCountry(string $domain, ?string $fallbackCountry): string {
        foreach ($this->cctldCountryMap as $tld => $country) {
            if (str_ends_with($domain, $tld)) {
                return $country;
            }
        }

        if (!empty($fallbackCountry) && $fallbackCountry !== 'Other_Countries') {
            return $fallbackCountry;
        }

        return 'Global_International';
    }

    private function isValidEmail(string $email): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (strpos($email, 'example.com') !== false || strpos($email, 'test.com') !== false) {
            return false;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) return false;
        $domain = $parts[1];

        if (isset($this->disposableDomains[$domain])) {
            return false;
        }

        // Strict Domain Syntax Validation (offline, 0ms latency)
        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,24}$/', $domain)) {
            return false;
        }

        // Reject consecutive dots or invalid hyphens in domain
        if (strpos($domain, '..') !== false || strpos($domain, '.-') !== false || strpos($domain, '-.') !== false) {
            return false;
        }

        return true;
    }

    private function cleanAndValidatePhone(string $raw): ?array {
        $clean = preg_replace('/[^0-9+]/', '', $raw);

        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }

        $digitsOnly = preg_replace('/[^0-9]/', '', $clean);
        $len = strlen($digitsOnly);

        if ($len < 8 || $len > 15) {
            return null;
        }

        $detectedCountry = 'Other_Countries';
        $detectedCode = 'Unknown';

        foreach ($this->countryDialCodes as $code => $country) {
            if (str_starts_with($clean, $code)) {
                $detectedCountry = $country;
                $detectedCode = $code;
                break;
            }
        }

        if ($detectedCountry === 'Other_Countries' && $len === 10 && in_array(substr($digitsOnly, 0, 1), ['6', '7', '8', '9'])) {
            $clean = '+91' . $digitsOnly;
            $detectedCountry = 'India';
            $detectedCode = '+91';
        }

        if ($detectedCountry === 'Other_Countries' && $len === 10 && in_array(substr($digitsOnly, 0, 1), ['2', '3', '4', '5', '6', '7', '8', '9'])) {
            $detectedCountry = 'USA_Canada';
            $detectedCode = '+1';
        }

        return [
            'number'  => $clean,
            'country' => $detectedCountry,
            'code'    => $detectedCode
        ];
    }

    private function printLiveProgress(bool $force = false): void {
        static $lastPrint = 0;
        $now = microtime(true);
        if (!$force && ($now - $lastPrint) < 0.25) return;
        $lastPrint = $now;

        $elapsed = max(0.001, $now - $this->startTime);
        $speed = round($this->totalLinesThisRun / $elapsed);
        $memory = round(memory_get_usage(true) / (1024 * 1024), 2);

        $msg = sprintf(
            "\r>> Lines: %s | Speed: %s lines/sec | RAM: %s MB | Emails: %s | Phones: %s ",
            number_format($this->totalLinesThisRun),
            number_format($speed),
            $memory,
            number_format($this->validEmailsThisRun),
            number_format($this->validPhonesThisRun)
        );
        echo $msg;
    }

    /**
     * Export / Merge Results as Country-Wise Streamed JSON Collections for current date
     * Merges all data from multiple files processed on the same date together
     */
    private function exportJsonResults(string $outputDateDir, int $chunkSize = 100000): void {
        $phonesDir = "{$outputDateDir}/phones";
        $emailsDir = "{$outputDateDir}/emails";

        if (!is_dir($phonesDir)) mkdir($phonesDir, 0777, true);
        if (!is_dir($emailsDir)) mkdir($emailsDir, 0777, true);

        // --- 1. EXPORT/MERGE PHONES FOR RUN DATE ---
        echo "\n1. Exporting & Merging Country-Wise Phones to JSON for Date: {$this->runDate}...\n";
        $phoneCountriesStmt = $this->db->prepare("SELECT country, COUNT(*) as cnt FROM phones WHERE run_date = ? GROUP BY country ORDER BY cnt DESC");
        $phoneCountriesStmt->execute([$this->runDate]);
        $phoneCountries = $phoneCountriesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($phoneCountries as $item) {
            $country = $item['country'];
            $totalCount = (int)$item['cnt'];
            $countryDir = "{$phonesDir}/{$country}";
            if (!is_dir($countryDir)) mkdir($countryDir, 0777, true);

            echo "   -> [{$country}] Total Unique for Date: " . number_format($totalCount) . "\n";

            $part = 1;
            $offset = 0;

            while ($offset < $totalCount) {
                $filePath = "{$countryDir}/{$country}_phones_part{$part}.json";
                $fp = fopen($filePath, 'w');

                fwrite($fp, "[\n");
                $isFirst = true;

                $stmt = $this->db->prepare("SELECT phone, country, country_code, input_file_name, extracted_date FROM phones WHERE country = ? AND run_date = ? LIMIT ? OFFSET ?");
                $stmt->execute([$country, $this->runDate, $chunkSize, $offset]);

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!$isFirst) {
                        fwrite($fp, ",\n");
                    }
                    fwrite($fp, "  " . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $isFirst = false;
                }

                fwrite($fp, "\n]\n");
                fclose($fp);

                echo "      Saved: {$filePath}\n";
                $offset += $chunkSize;
                $part++;
            }
        }

        // --- 2. EXPORT/MERGE EMAILS FOR RUN DATE ---
        echo "\n2. Exporting & Merging Country-Wise Emails to JSON for Date: {$this->runDate}...\n";
        $emailCountriesStmt = $this->db->prepare("SELECT country, COUNT(*) as cnt FROM emails WHERE run_date = ? GROUP BY country ORDER BY cnt DESC");
        $emailCountriesStmt->execute([$this->runDate]);
        $emailCountries = $emailCountriesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($emailCountries as $item) {
            $country = $item['country'];
            $totalCount = (int)$item['cnt'];
            $countryDir = "{$emailsDir}/{$country}";
            if (!is_dir($countryDir)) mkdir($countryDir, 0777, true);

            echo "   -> [{$country}] Total Unique for Date: " . number_format($totalCount) . "\n";

            $part = 1;
            $offset = 0;

            while ($offset < $totalCount) {
                $filePath = "{$countryDir}/{$country}_emails_part{$part}.json";
                $fp = fopen($filePath, 'w');

                fwrite($fp, "[\n");
                $isFirst = true;

                $stmt = $this->db->prepare("SELECT email, domain, country, input_file_name, extracted_date FROM emails WHERE country = ? AND run_date = ? LIMIT ? OFFSET ?");
                $stmt->execute([$country, $this->runDate, $chunkSize, $offset]);

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!$isFirst) {
                        fwrite($fp, ",\n");
                    }
                    fwrite($fp, "  " . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $isFirst = false;
                }

                fwrite($fp, "\n]\n");
                fclose($fp);

                echo "      Saved: {$filePath}\n";
                $offset += $chunkSize;
                $part++;
            }
        }

        // Save metadata summary JSON for today's collection
        $summary = [
            'run_date' => $this->runDate,
            'timestamp' => $this->runTimestamp,
            'files_included_for_this_date' => array_keys($this->jsonDb['scanned_files']),
            'total_date_unique_emails' => (int)$this->db->query("SELECT COUNT(*) FROM emails WHERE run_date = '{$this->runDate}'")->fetchColumn(),
            'total_date_unique_phones' => (int)$this->db->query("SELECT COUNT(*) FROM phones WHERE run_date = '{$this->runDate}'")->fetchColumn(),
            'all_time_total_unique_emails' => (int)$this->db->query("SELECT COUNT(*) FROM emails")->fetchColumn(),
            'all_time_total_unique_phones' => (int)$this->db->query("SELECT COUNT(*) FROM phones")->fetchColumn(),
            'email_breakdown_by_country' => $emailCountries,
            'phone_breakdown_by_country' => $phoneCountries
        ];

        file_put_contents(
            "{$outputDateDir}/collection_summary.json",
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function printSummary(string $outputDateDir): void {
        $totalTime = round(microtime(true) - $this->startTime, 2);
        $peakMem = round(memory_get_peak_usage(true) / (1024 * 1024), 2);
        $uniqueEmails = (int)$this->db->query("SELECT COUNT(*) FROM emails WHERE run_date = '{$this->runDate}'")->fetchColumn();
        $uniquePhones = (int)$this->db->query("SELECT COUNT(*) FROM phones WHERE run_date = '{$this->runDate}'")->fetchColumn();
        $allTimeEmails = (int)$this->db->query("SELECT COUNT(*) FROM emails")->fetchColumn();
        $allTimePhones = (int)$this->db->query("SELECT COUNT(*) FROM phones")->fetchColumn();

        echo "\n======================================================\n";
        echo "                PROCESSING SUMMARY                    \n";
        echo "======================================================\n";
        echo " Lines Scanned in this Run: " . number_format($this->totalLinesThisRun) . "\n";
        echo " Date ({$this->runDate}) Unique Emails : " . number_format($uniqueEmails) . " (All files combined)\n";
        echo " Date ({$this->runDate}) Unique Phones : " . number_format($uniquePhones) . " (All files combined)\n";
        echo " All-Time Master Unique Emails: " . number_format($allTimeEmails) . "\n";
        echo " All-Time Master Unique Phones: " . number_format($allTimePhones) . "\n";
        echo " Peak Memory Used (RAM)   : {$peakMem} MB (Zero-RAM Streaming)\n";
        echo " Total Time Taken         : {$totalTime} seconds\n";
        echo " Local JSON Database      : {$this->jsonDbPath}\n";
        echo " Organized Output Folder  : {$outputDateDir}/\n";
        echo "======================================================\n\n";
    }

    private function printCurrentStats(): void {
        $totalFiles = count($this->jsonDb['scanned_files']);
        $allTimeEmails = (int)$this->db->query("SELECT COUNT(*) FROM emails")->fetchColumn();
        $allTimePhones = (int)$this->db->query("SELECT COUNT(*) FROM phones")->fetchColumn();

        echo "======================================================\n";
        echo "             LOCAL DATABASE CURRENT STATUS            \n";
        echo "======================================================\n";
        echo " Total Files Scanned   : " . number_format($totalFiles) . "\n";
        echo " Master Unique Emails  : " . number_format($allTimeEmails) . "\n";
        echo " Master Unique Phones  : " . number_format($allTimePhones) . "\n";
        echo " Local DB File         : {$this->jsonDbPath}\n";
        echo "======================================================\n\n";
    }
}

// CLI Execution entry point (only runs if executed directly in terminal)
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $forceRescan = false;
    $cliArgs = $argv ?? [];
    foreach ($cliArgs as $arg) {
        if ($arg === '--rescan' || $arg === '--force') {
            $forceRescan = true;
        }
    }

    $inputDir = (isset($cliArgs[1]) && !str_starts_with($cliArgs[1], '--')) ? $cliArgs[1] : __DIR__ . DIRECTORY_SEPARATOR . 'input';
    $outputDir = (isset($cliArgs[2]) && !str_starts_with($cliArgs[2], '--')) ? $cliArgs[2] : __DIR__ . DIRECTORY_SEPARATOR . 'output';

    $processor = new BulkDataProcessor($inputDir, $outputDir, $forceRescan);
    $processor->run();
}
