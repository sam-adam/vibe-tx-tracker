<?php
// CSV Configuration
define('CSV_FILE_PATH', './transactions.csv');

// CSV Column Indices
define('CSV_COL_ID', 0);
define('CSV_COL_CLIENT', 1);
define('CSV_COL_DATE', 2);
define('CSV_COL_AMOUNT', 3);
define('CSV_COL_TYPE', 4);
define('CSV_COL_LABEL', 5);

// Client Manager Class
class ClientManager {
    private $csvFile;

    public function __construct() {
        $this->csvFile = CSV_FILE_PATH;
    }

    public function listClients() {
        $clients = [];
        if (($handle = fopen($this->csvFile, 'r')) !== FALSE) {
            fgetcsv($handle, 0, ',', '"', '"'); // skip header
            while (($data = fgetcsv($handle, 0, ',', '"', '"')) !== FALSE) {
                if (!empty($data[CSV_COL_CLIENT])) { // Check if client name exists
                    $clientName = trim($data[CSV_COL_CLIENT]);
                    $clients[] = $clientName; // Add client name to array
                }
            }
            fclose($handle);
            // Remove duplicates while preserving case of first occurrence
            $clients = array_unique($clients);
            sort($clients); // Sort alphabetically
        }
        return $clients;
    }

    public function isClientUnique($clientName) {
        $clients = $this->listClients();
        $clientName = trim($clientName);
        // Check if client name exists (case-insensitive)
        foreach ($clients as $existingClient) {
            if (strcasecmp($existingClient, $clientName) === 0) {
                return false;
            }
        }
        return true;
    }

    public function createClient($clientName) {
        if (empty($clientName)) {
            throw new Exception("Client name cannot be empty");
        }

        if (($handle = fopen($this->csvFile, 'r')) !== FALSE) {
            $header = fgetcsv($handle, 0, ',', '"', '"');
            fclose($handle);

            if (($writeHandle = fopen($this->csvFile, 'a')) !== FALSE) {
                // Add a dummy transaction to create the client
                $newRow = array_fill(0, 6, '');
                $newRow[CSV_COL_CLIENT] = $clientName;
                $newRow[CSV_COL_DATE] = date('Y-m-d');
                $newRow[CSV_COL_AMOUNT] = 0;
                $newRow[CSV_COL_TYPE] = 'initial';
                fputcsv($writeHandle, $newRow, ',', '"', '\\');
                fclose($writeHandle);
                return true;
            }
        }
        throw new Exception("Failed to create client");
    }

    public function updateClient($oldName, $newName) {
        if (empty($oldName) || empty($newName)) {
            throw new Exception("Client names cannot be empty");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'txtrckr');

        if (($readHandle = fopen($this->csvFile, 'r')) !== FALSE && ($writeHandle = fopen($tempFile, 'w')) !== FALSE) {
            $header = fgetcsv($readHandle, 0, ',', '"', '"');
            fputcsv($writeHandle, $header, ',', '"', '\\'); // write header

            while (($data = fgetcsv($readHandle, 0, ',', '"', '"')) !== FALSE) {
                if (count($data) !== 4) continue;
                if ($data[0] === $oldName) {
                    $data[0] = $newName;
                }
                fputcsv($writeHandle, $data, ',', '"', '\\');
            }

            fclose($readHandle);
            fclose($writeHandle);

            if (!rename($tempFile, $this->csvFile)) {
                throw new Exception("Failed to update client name");
            }
            return true;
        }
        throw new Exception("Failed to update client");
    }
}

// CSV Handler Class
class CsvHandler {
    private $filePath;
    private $delimiter = ',';
    private $enclosure = '"';
    private $escape = '\\';

    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->ensureFileExists();
    }

    private function ensureFileExists() {
        if (!file_exists($this->filePath)) {
            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            touch($this->filePath);
        }
    }

    public function readAll() {
        $rows = [];
        if (($handle = fopen($this->filePath, 'r')) !== FALSE) {
            // Read header
            $header = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, $this->escape);

            // Read data rows
            while (($data = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== FALSE) {
                if (count($header) === count($data)) { // Only add if row matches header structure
                    $rows[] = array_combine($header, $data);
                }
            }
            fclose($handle);
        }
        return $rows;
    }

    public function writeAll($header, $data) {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        if ($tempFile === false) {
            error_log('Failed to create temporary file');
            return false;
        }

        $success = false;
        $handle = @fopen($tempFile, 'w');
        
        if ($handle === false) {
            error_log('Failed to open temporary file for writing: ' . $tempFile);
            @unlink($tempFile);
            return false;
        }

        try {
            // Write header
            if (fputcsv($handle, $header, $this->delimiter, $this->enclosure, $this->escape) === false) {
                throw new Exception('Failed to write CSV header');
            }

            // Write data rows
            foreach ($data as $row) {
                $rowData = [];
                foreach ($header as $column) {
                    $rowData[] = $row[$column] ?? '';
                }
                if (fputcsv($handle, $rowData, $this->delimiter, $this->enclosure, $this->escape) === false) {
                    throw new Exception('Failed to write CSV row');
                }
            }

            if (fflush($handle) === false) {
                throw new Exception('Failed to flush buffer to file');
            }
            
            if (fclose($handle) === false) {
                throw new Exception('Failed to close file handle');
            }
            $handle = null;

            // Only attempt to rename if the target file is writable or doesn't exist
            if (!file_exists($this->filePath) || is_writable($this->filePath)) {
                $success = @rename($tempFile, $this->filePath);
                if (!$success) {
                    throw new Exception('Failed to move temporary file to destination');
                }
            } else {
                throw new Exception('Destination file is not writable: ' . $this->filePath);
            }
        } catch (Exception $e) {
            error_log('Error writing CSV: ' . $e->getMessage());
            $success = false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        return $success;
    }

    public function appendRow($row) {
        $mode = file_exists($this->filePath) && filesize($this->filePath) > 0 ? 'a' : 'w';

        if (($handle = fopen($this->filePath, $mode)) !== FALSE) {
            // If file is empty, write header first
            if ($mode === 'w') {
                fputcsv($handle, array_keys($row), $this->delimiter, $this->enclosure, $this->escape);
            }

            fputcsv($handle, $row, $this->delimiter, $this->enclosure, $this->escape);
            fclose($handle);
            return true;
        }
        return false;
    }
}

// Transaction Manager Class
class TransactionManager {
    private $csvHandler;
    private $csvFile;

    public function __construct() {
        $this->csvFile = CSV_FILE_PATH;
        $this->csvHandler = new CsvHandler($this->csvFile);
        $this->ensureCsvFileExists();
    }

    private function ensureCsvFileExists() {
        if (!file_exists($this->csvFile)) {
            // Create file with header
            $header = ['id', 'client', 'date', 'amount', 'type', 'label', 'deleted'];
            $this->csvHandler->writeAll($header, []);
        }
    }

    public function listTransactions($includeDeleted = false) {
        $allData = $this->csvHandler->readAll();
        $transactions = [];

        foreach ($allData as $row) {
            // Skip if row doesn't have required fields
            if (!isset($row['id'], $row['client'], $row['date'], $row['amount'], $row['type'])) {
                continue;
            }
            
            // Skip deleted transactions unless explicitly included
            $deleted = isset($row['deleted']) && ($row['deleted'] === '1' || $row['deleted'] === true);
            if ($deleted && !$includeDeleted) {
                continue;
            }

            $transactions[] = [
                'id' => $row['id'],
                'client' => $row['client'],
                'date' => $row['date'],
                'amount' => (float)$row['amount'],
                'type' => $row['type'],
                'label' => $row['label'] ?? '',
                'deleted' => $deleted
            ];
        }

        return $transactions;
    }

    public function createTransaction($client, $date, $amount, $type, $label = '') {
        $this->writeTransaction($client, $date, $amount, $type, $label, false);
    }

    public function writeTransaction($client, $date, $amount, $type, $label, $deleted, $id = null) {
        $header = ['id', 'client', 'date', 'amount', 'type', 'label', 'deleted'];
        $allData = $this->csvHandler->readAll();
        $found = false;
        
        // If no ID provided, generate a new one by finding the max ID and incrementing
        if ($id === null) {
            $maxId = 0;
            foreach ($allData as $row) {
                if (isset($row['id']) && is_numeric($row['id']) && (int)$row['id'] > $maxId) {
                    $maxId = (int)$row['id'];
                }
            }
            $id = (string)($maxId + 1);
        }

        // Check if we're updating an existing transaction
        foreach ($allData as &$row) {
            if (isset($row['id']) && $row['id'] === $id) {
                $row = [
                    'id' => $id,
                    'client' => $client,
                    'date' => $date,
                    'amount' => (string)$amount,
                    'type' => $type,
                    'label' => $label,
                    'deleted' => $deleted ? '1' : '0'
                ];
                $found = true;
                break;
            }
        }

        // If not found, add as new transaction
        if (!$found) {
            $allData[] = [
                'id' => $id,
                'client' => $client,
                'date' => $date,
                'amount' => (string)$amount,
                'type' => $type,
                'label' => $label,
                'deleted' => $deleted ? '1' : '0'
            ];
        }

        // Write all data back to file
        $success = $this->csvHandler->writeAll($header, $allData);
        
        if (!$success) {
            Logger::error('Failed to write transaction data to CSV', 'ERROR');
            return false;
        }

        return $id;
    }

    public function updateTransaction($id, $client, $date, $amount, $type, $label = '') {
        $header = ['id', 'client', 'date', 'amount', 'type', 'label', 'deleted'];
        $allData = $this->csvHandler->readAll();
        $updated = false;

        foreach ($allData as &$row) {
            if (isset($row['id']) && $row['id'] == $id) {
                $row = [
                    'id' => $id,
                    'client' => $client,
                    'date' => $date,
                    'amount' => $amount,
                    'type' => $type,
                    'label' => $label,
                    'deleted' => $row['deleted'] ?? '0' // Preserve deleted status
                ];
                $updated = true;
                break;
            }
        }

        if ($updated) {
            return $this->csvHandler->writeAll($header, $allData);
        }

        throw new Exception("Transaction not found");
    }

    public function deleteTransaction($id) {
        if (empty($id)) {
            throw new Exception("Transaction ID is required");
        }

        $header = ['id', 'client', 'date', 'amount', 'type', 'label', 'deleted'];
        $allData = $this->csvHandler->readAll();
        $deleted = false;

        foreach ($allData as &$row) {
            if (isset($row['id']) && $row['id'] == $id) {
                $row['deleted'] = '1';
                $deleted = true;
                break;
            }
        }

        if ($deleted) {
            return $this->csvHandler->writeAll($header, $allData);
        }

        throw new Exception("Transaction not found");
    }
}

// Handle API requests
$is_api_request = isset($_GET['api']);
$action = $_GET['api'] ?? '';

if ($is_api_request) {
    // Set headers at the very beginning
    header('Content-Type: application/json');

    $transactionManager = new TransactionManager();
    $clientManager = new ClientManager();

    try {
        if (empty($action)) {
            http_response_code(400);
            throw new Exception('Action parameter is required. Use ?api=ACTION');
        }

        switch ($action) {
            case 'get_clients':
                echo json_encode($clientManager->listClients());
                break;

            case 'list':
                echo json_encode($transactionManager->listTransactions());
                break;

            case 'create':
                // Get form data from JSON body
                $input = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    throw new Exception('Invalid JSON input');
                }

                $client = $input['client'] ?? '';
                $date = $input['date'] ?? '';
                $amount = $input['amount'] ?? '';
                $type = $input['type'] ?? '';
                $label = $input['label'] ?? '';

                // Validate required fields
                if (empty($client) || empty($date) || empty($amount) || empty($type)) {
                    throw new Exception('All required fields must be filled');
                }

                // Create the transaction
                $transactionId = $transactionManager->createTransaction(
                    $client,
                    $date,
                    $amount,
                    $type,
                    $label
                );

                if ($transactionId === false) {
                    Logger::error('Failed to write transaction to file', 'ERROR');
                    throw new Exception('Failed to write transaction to file. Check error log for details.');
                }

                // If we got here, the transaction was created successfully
                echo json_encode([
                    'success' => true, 
                    'message' => 'Transaction created successfully',
                    'transactionId' => $transactionId
                ]);
                break;

            case 'update':
                $data = json_decode(file_get_contents('php://input'), true);

                // Validate required fields
                $required = ['id', 'client', 'date', 'amount', 'type'];
                foreach ($required as $field) {
                    if (!isset($data[$field]) || $data[$field] === '') {
                        throw new Exception("Missing required field: $field");
                    }
                }

                // Convert amount to float
                $amount = (float)$data['amount'];

                // Update the transaction
                $transactionManager->updateTransaction(
                    $data['id'],
                    $data['client'],
                    $data['date'],
                    $amount,
                    $data['type'],
                    $data['label'] ?? ''
                );

                echo json_encode(['success' => true, 'message' => 'Transaction updated successfully']);
                break;

            case 'delete':
                $id = $_GET['id'] ?? null;
                if (!$id) {
                    throw new Exception('Transaction ID is required');
                }

                // Find the transaction by ID
                $transactions = $transactionManager->listTransactions(true);
                $transaction = null;

                foreach ($transactions as $t) {
                    if ($t['id'] == $id) {
                        $transaction = $t;
                        break;
                    }
                }

                if (!$transaction) {
                    throw new Exception('Transaction not found');
                }

                $transactionManager->deleteTransaction(
                    $id
                );
                echo json_encode(['success' => true]);
                break;

            case 'validate_client':
                $data = json_decode(file_get_contents('php://input'), true);
                $isUnique = $clientManager->isClientUnique($data['client_name']);
                echo json_encode(['is_unique' => $isUnique]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle client management
if (isset($_POST['client_name'])) {
    try {
        $manager = new ClientManager();
        $manager->createClient($_POST['client_name']);
        header('Location: ?success=Client+created+successfully');
        exit;
    } catch (Exception $e) {
        header('Location: ?error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (isset($_POST['old_name']) && isset($_POST['new_name'])) {
    try {
        $manager = new ClientManager();
        $manager->updateClient($_POST['old_name'], $_POST['new_name']);
        header('Location: ?success=Client+updated+successfully');
        exit;
    } catch (Exception $e) {
        header('Location: ?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$manager = new ClientManager();
$transactionManager = new TransactionManager();

// Initialize transactions array
$transactions = [];

// Get transactions using the TransactionManager which handles the new format
$transactionManager = new TransactionManager();
$allTransactions = $transactionManager->listTransactions();

// Group transactions by client for display
foreach ($allTransactions as $t) {
    if ($t['deleted']) continue; // Skip deleted transactions

    $transactions[$t['client']][] = [
        'id' => $t['id'],
        'date' => $t['date'],
        'amount' => (float)$t['amount'],
        'type' => $t['type'],
        'label' => $t['label'] ?? ''
    ];
}

// Get clients
$manager = new ClientManager();
$clients = $manager->listClients();

// Create a simple array of client names for the dropdown
$clientNames = array_values($clients);

// Debug output
// echo "<pre>Clients from listClients(): ";
// print_r($clients);
// echo "</pre>";

// Convert clients to JSON for JavaScript
$clientsJson = json_encode($clients); // Send the full id => name mapping

// Function to format date with day name
function formatDateWithDay($dateString) {
    $date = new DateTime($dateString);
    return $date->format('D, M j, Y'); // e.g., "Mon, Aug 10, 2025"
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        success: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                        },
                        danger: {
                            50: '#fef2f2',
                            100: '#fee2e2',
                            500: '#ef4444',
                            600: '#dc2626',
                        },
                        warning: {
                            50: '#fffbeb',
                            100: '#fef3c7',
                            500: '#f59e0b',
                            600: '#d97706',
                        },
                        gray: {
                            50: '#f9fafb',
                            100: '#f3f4f6',
                            200: '#e5e7eb',
                            300: '#d1d5db',
                            400: '#9ca3af',
                            500: '#6b7280',
                            600: '#4b5563',
                            700: '#374151',
                            800: '#1f2937',
                            900: '#111827',
                        }
                    },
                    boxShadow: {
                        'card': '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
                        'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.2s ease-in-out',
                        'slide-up': 'slideUp 0.2s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                    },
                },
            },
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        
        /* Base styles */
        body { 
            @apply bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors duration-200;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Dark mode overrides */
        /* Dark mode overrides */
        .dark .modal-content {
            @apply bg-gray-800 border-gray-700;
        }
        
        .dark .modal-content h2 {
            @apply text-white;
        }
        
        .dark .modal-content label,
        .dark .modal-content .text-gray-700 {
            @apply text-gray-300;
        }
        
        .dark .btn-outline {
            @apply border-gray-600 text-gray-300 hover:bg-gray-700;
        }
        
        /* Success background in dark mode */
        .dark .bg-success-100 {
            @apply bg-green-900/50 text-success-200;
        }
        
        /* Improve modal form inputs in dark mode */
        .dark .modal-content input[type="text"],
        .dark .modal-content input[type="date"],
        .dark .modal-content input[type="number"],
        .dark .modal-content select,
        .dark .modal-content textarea {
            @apply bg-gray-700 border-gray-600 text-white placeholder-gray-400 focus:ring-primary-500 focus:border-primary-500;
        }
        
        /* Style radio buttons in dark mode */
        .dark .modal-content input[type="radio"] {
            @apply bg-gray-700 border-gray-500 text-primary-600 focus:ring-primary-500;
        }
        
        .dark .form-input,
        .dark .form-select {
            @apply bg-gray-700 border-gray-600 text-white placeholder-gray-400 focus:ring-primary-500 focus:border-primary-500;
        }
        .transaction-item {
            transition: all 0.2s ease;
        }
        .transaction-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }
        .btn {
            @apply px-4 py-2 rounded-lg font-medium transition-all duration-200 ease-in-out flex items-center gap-2;
        }
        .btn-primary {
            @apply bg-primary-600 text-white hover:bg-primary-700;
        }
        .btn-outline {
            @apply border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-gray-200;
        }
        .btn-danger {
            @apply bg-danger-500 text-white hover:bg-danger-600;
        }
        .btn-warning {
            @apply bg-warning-500 text-white hover:bg-warning-600;
        }
        .form-input {
            @apply w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition duration-200 bg-white dark:bg-gray-700 dark:text-white dark:placeholder-gray-400;
        }
        .form-select {
            @apply w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition duration-200 bg-white dark:bg-gray-700 dark:text-white;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Header -->
        <header class="bg-white shadow-sm dark:bg-gray-800 transition-colors duration-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <svg class="h-8 w-8 text-primary-600 dark:text-primary-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                        </svg>
                    </div>
                    <h1 class="ml-3 text-2xl font-bold text-gray-900 dark:text-white">Transaction Tracker</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <button type="button" id="themeToggle" class="p-2 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" aria-label="Toggle dark mode">
                        <i class="fas fa-moon dark:hidden"></i>
                        <i class="fas fa-sun hidden dark:inline"></i>
                    </button>
                    <button id="createTransactionBtn" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span class="hidden sm:inline">New Transaction</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 transition-colors duration-200">
            <div class="space-y-6">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-12 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                        <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="mt-2 text-lg font-medium text-gray-900 dark:text-gray-100">No transactions yet</h3>
                        <p class="mt-1 text-gray-500 dark:text-gray-400">Get started by creating a new transaction.</p>
                        <div class="mt-6">
                            <button id="createFirstTransactionBtn" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                <span>New Transaction</span>
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($transactions as $client => $trans): ?>
                        <?php
                        $balance = 0;
                        foreach ($trans as $t) {
                            if ($t['type'] === 'debit') {
                                $balance += $t['amount'];
                            } else if ($t['type'] === 'credit') {
                                $balance -= $t['amount'];
                            }
                        }
                        $isPositiveBalance = $balance >= 0;
                        ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden border border-gray-100 dark:border-gray-700 transition-all duration-200 hover:shadow-md">
                            <!-- Client Header - More compact on mobile -->
                            <div class="px-4 sm:px-6 py-3 border-b border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center justify-between bg-gray-50 dark:bg-gray-700">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8 sm:h-10 sm:w-10 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                                        <span class="text-primary-600 dark:text-primary-400 font-medium text-sm sm:text-base"><?php echo strtoupper(substr($client, 0, 1)); ?></span>
                                    </div>
                                    <h3 class="ml-2 sm:ml-3 text-base sm:text-lg font-medium text-gray-900 dark:text-white truncate max-w-[150px] sm:max-w-none">
                                        <?php echo htmlspecialchars($client); ?>
                                    </h3>
                                </div>
                                <div class="mt-2 sm:mt-0 sm:ml-2">
                                    <span class="inline-block px-2 py-1 text-xs sm:text-sm rounded-full font-medium whitespace-nowrap <?php echo $isPositiveBalance ? 'bg-success-100 dark:bg-success-900 text-success-800 dark:text-success-200' : 'bg-danger-100 dark:bg-danger-900 text-danger-800 dark:text-danger-200'; ?>">
                                        <?php echo $isPositiveBalance ? 'Balance: +' : 'Balance: -'; ?><?php echo number_format(abs($balance), 2); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Transactions List - More compact on mobile -->
                            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach($trans as $t): 
                                    $isCredit = $t['type'] === 'credit';
                                ?>
                                    <li class="transaction-item px-3 sm:px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between space-y-2 sm:space-y-0">
                                            <div class="flex items-start sm:items-center space-x-3">
                                                <div class="flex-shrink-0 h-8 w-8 sm:h-10 sm:w-10 rounded-full flex items-center justify-center <?php echo $isCredit ? 'bg-red-100 dark:bg-red-900/50' : 'bg-green-100 dark:bg-green-900/50'; ?>">
                                                    <i class="fas <?php echo $isCredit ? 'fa-arrow-up text-red-600 dark:text-red-400' : 'fa-arrow-down text-green-600 dark:text-green-400'; ?> text-xs sm:text-sm"></i>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 break-words">
                                                        <?php echo htmlspecialchars(formatDateWithDay($t['date'])); ?>
                                                    </div>
                                                    <?php if (!empty($t['label'])): ?>
                                                        <div class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5 break-words">
                                                            <?php echo htmlspecialchars($t['label']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between sm:justify-end space-x-2 sm:space-x-3">
                                                <span class="text-sm sm:text-base font-semibold whitespace-nowrap <?php echo $isCredit ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'; ?>">
                                                    <?php echo $isCredit ? '-' : '+'; ?><?php echo number_format(abs($t['amount']), 2); ?>
                                                </span>
                                                <div class="flex space-x-0.5 sm:space-x-1">
                                                    <button type="button" 
                                                            class="p-1.5 sm:p-2 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors duration-200 editTransactionBtn"
                                                            data-id="<?php echo htmlspecialchars($t['id']); ?>"
                                                            data-client="<?php echo htmlspecialchars($client); ?>"
                                                            data-date="<?php echo htmlspecialchars($t['date']); ?>"
                                                            data-amount="<?php echo $t['amount']; ?>"
                                                            data-type="<?php echo htmlspecialchars($t['type']); ?>"
                                                            data-label="<?php echo !empty($t['label']) ? htmlspecialchars($t['label']) : ''; ?>"
                                                            aria-label="Edit">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="p-1.5 sm:p-2 text-gray-400 hover:text-warning-600 dark:hover:text-warning-400 transition-colors duration-200 duplicateTransactionBtn"
                                                            data-client="<?php echo htmlspecialchars($client); ?>"
                                                            data-date="<?php echo htmlspecialchars($t['date']); ?>"
                                                            data-amount="<?php echo $t['amount']; ?>"
                                                            data-type="<?php echo htmlspecialchars($t['type']); ?>"
                                                            data-label="<?php echo !empty($t['label']) ? htmlspecialchars($t['label']) : ''; ?>"
                                                            aria-label="Duplicate">
                                                        <i class="far fa-copy text-sm"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="p-1.5 sm:p-2 text-gray-400 hover:text-danger-600 dark:hover:text-danger-400 transition-colors duration-200 deleteTransactionBtn"
                                                            data-id="<?php echo htmlspecialchars($t['id']); ?>"
                                                            data-client="<?php echo htmlspecialchars($client); ?>"
                                                            aria-label="Delete">
                                                        <i class="far fa-trash-alt text-sm"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
    </div>

    <!-- Create Transaction Modal -->
    <dialog id="createTransactionModal" class="modal">
        <div class="modal-content bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Create Transaction</h2>
            <form id="createTransactionForm" class="space-y-4">
                <div class="client-group mb-4">
                    <div class="flex items-center mb-2">
                        <input type="radio" id="selectClient" name="clientType" value="existing" class="mr-2" onchange="toggleClientInput('select')" <?php echo !empty($clientNames) ? 'checked' : ''; ?>>
                        <label for="selectClient" class="block text-sm font-medium text-gray-700">Select Existing Client</label>
                    </div>
                    <select id="clientSelect" name="client"
                        class="client-select w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        <?php echo empty($clientNames) ? 'disabled' : ''; ?>>
                        <?php if (empty($clientNames)): ?>
                            <option value="">No clients available</option>
                        <?php else: ?>
                            <option value="">Select a client</option>
                            <?php foreach ($clientNames as $client): ?>
                                <option value="<?php echo htmlspecialchars($client); ?>"><?php echo htmlspecialchars($client); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <div class="mt-4">
                        <div class="flex items-center mb-2">
                            <input type="radio" id="newClient" name="clientType" value="new" class="mr-2" onchange="toggleClientInput('new')" <?php echo empty($clientNames) ? 'checked' : ''; ?>>
                            <label for="newClient" class="block text-sm font-medium text-gray-700">Add New Client</label>
                        </div>
                        <input type="text" id="newClientName" name="newClientName"
                            class="client-input w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter new client name"
                            <?php echo !empty($clients) ? 'style="display: none;"' : ''; ?>
                            <?php echo empty($clients) ? 'required' : ''; ?>>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                    <input type="number" step="0.01" name="amount" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                        <option value="debit">Debit (Owed)</option>
                        <option value="credit">Credit (Paid)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Label</label>
                    <input type="text" name="label" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional description or note">
                </div>

                <div class="flex justify-end">
                    <button type="button" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 mr-2" onclick="createModal.close()">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">Create</button>
        </div>
    </div>

    <!-- Edit Transaction Modal Template -->
    <template id="editTransactionModalTemplate">
        <dialog class="editTransactionModal modal">
            <div class="modal-content bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Edit Transaction</h2>
                <form class="editTransactionForm space-y-4">
                    <input type="hidden" name="id">
                    
                    <!-- Client Selection -->
                    <div class="space-y-2">
                        <label for="clientSelect" class="block text-sm font-medium text-gray-700">Client</label>
                        <div class="flex space-x-2">
                            <select id="clientSelect" class="form-select flex-1" aria-label="Select client">
                                <?php foreach($clients as $client): ?>
                                    <option value="<?php echo htmlspecialchars($client); ?>"><?php echo htmlspecialchars($client); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="newClientBtn" class="btn btn-outline whitespace-nowrap">
                                <i class="fas fa-plus"></i> New
                            </button>
                        </div>
                        <div id="newClientContainer" class="hidden mt-2">
                            <div class="relative">
                                <input type="text" id="newClientInput" class="form-input pl-10" placeholder="Enter new client name" aria-label="New client name">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-user text-gray-400"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Date -->
                    <div class="space-y-2">
                        <label for="date" class="block text-sm font-medium text-gray-700">Date</label>
                        <div class="relative">
                            <input type="date" id="date" name="date" class="form-input pl-10" required>
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="far fa-calendar text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Amount and Type -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label for="amount" class="block text-sm font-medium text-gray-700">Amount</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500">Rp</span>
                                </div>
                                <input type="number" id="amount" name="amount" step="0.01" class="form-input pl-12" required>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                            <div class="relative">
                                <select id="type" name="type" class="form-select" required>
                                    <option value="debit">Debit (Add)</option>
                                    <option value="credit">Credit (Subtract)</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                                    <i class="fas fa-chevron-down text-sm"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Label -->
                    <div class="space-y-2">
                        <label for="label" class="block text-sm font-medium text-gray-700">Label (Optional)</label>
                        <div class="relative">
                            <input type="text" id="label" name="label" class="form-input pl-10" placeholder="e.g., Anter jemput">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-tag text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" id="cancelFormBtn" class="btn btn-outline">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i>
                            <span>Save Transaction</span>
                        </button>
                    </div>
                </form>
            </div>
        </dialog>
    </template>
    <script>
        // Pass PHP clients to JavaScript
        const phpClients = <?php echo $clientsJson; ?>;

        // Initialize modals
        const createModal = document.getElementById('createTransactionModal');

        // Function to toggle between client select and new client input
        function toggleClientInput(type) {
            const clientSelect = document.getElementById('clientSelect');
            const newClientInput = document.getElementById('newClientName');

            if (type === 'new') {
                clientSelect.required = false;
                newClientInput.required = true;
                newClientInput.classList.remove('hidden');
            } else {
                clientSelect.required = true;
                newClientInput.required = false;
                newClientInput.classList.add('hidden');
            }
        }

        // Function to load clients into select elements
        async function loadClients() {
            const clientSelect = document.getElementById('clientSelect');
            const selectClientRadio = document.getElementById('selectClient');
            const newClientRadio = document.getElementById('newClient');
            const newClientInput = document.getElementById('newClientName');

            try {
                // First try to use the clients passed from PHP
                let clients = [];
                if (typeof phpClients !== 'undefined' && Array.isArray(phpClients)) {
                    clients = phpClients;
                } else {
                    // Fallback to API call if PHP clients not available
                    const response = await fetch('?api=get_clients', {
                        method: 'POST'
                    });
                    if (!response.ok) throw new Error('Failed to load clients');
                    clients = await response.json();
                }

                // Clear existing options
                clientSelect.innerHTML = '';

                if (clients && clients.length > 0) {
                    // Add default option
                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = 'Select a client';
                    clientSelect.appendChild(defaultOption);

                    // Add clients to the select element
                    clients.forEach(client => {
                        if (!client) return; // Skip empty client names
                        const option = document.createElement('option');
                        option.value = client;
                        option.textContent = client;
                        clientSelect.appendChild(option);
                    });

                    // Enable the select and show the radio button
                    clientSelect.disabled = false;
                    selectClientRadio.disabled = false;

                    // If we have clients, make the select the default option
                    selectClientRadio.checked = true;
                    toggleClientInput('select');
                } else {
                    // No clients available
                    const noClientsOption = document.createElement('option');
                    noClientsOption.value = '';
                    noClientsOption.textContent = 'No clients available';
                    clientSelect.appendChild(noClientsOption);

                    // Disable the select and its radio button
                    clientSelect.disabled = true;
                    selectClientRadio.disabled = true;

                    // Make sure new client is selected by default
                    newClientRadio.checked = true;
                    toggleClientInput('new');
                }
            } catch (error) {
                console.error('Error loading clients:', error);

                // On error, show error state
                clientSelect.innerHTML = '';
                const errorOption = document.createElement('option');
                errorOption.value = '';
                errorOption.textContent = 'Error loading clients';
                clientSelect.appendChild(errorOption);
                clientSelect.disabled = true;
                selectClientRadio.disabled = true;

                // Make sure new client is selected
                newClientRadio.checked = true;
                toggleClientInput('new');
            }
        }

        // Create Transaction
        document.getElementById('createTransactionBtn').addEventListener('click', () => {
            loadClients();
            document.getElementById('createTransactionForm').reset();
            createModal.showModal();
        });

        // Handle create transaction form submission
        document.getElementById('createTransactionForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const form = e.target;
            const clientType = form.querySelector('input[name="clientType"]:checked').value;

            // Use either the selected client or the new client name
            const client = clientType === 'new'
                ? form.newClientName.value.trim()
                : form.client.value;

            if (!client) {
                alert('Please select a client or enter a new client name');
                return;
            }

            // Create form data object
            const formData = new FormData();
            formData.append('client', client);
            formData.append('date', form.date.value);
            formData.append('amount', form.amount.value);
            formData.append('type', form.type.value);
            formData.append('label', form.label.value);

            try {
                const response = await fetch('?api=create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(Object.fromEntries(formData))
                });

                const result = await response.json();

                if (result.success) {
                    createModal.close();
                    window.location.reload();
                } else {
                    alert(result.message || 'Failed to create transaction');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while creating the transaction');
            }
        });

        // Edit Transaction
        function setupEditTransaction(button) {
            // Get transaction data from data attributes
            const transaction = {
                id: button.dataset.id,
                client: button.dataset.client,
                date: button.dataset.date,
                amount: Math.abs(parseFloat(button.dataset.amount)), // Get absolute value
                type: button.dataset.amount < 0 ? 'credit' : 'debit', // Determine type from amount sign
                label: button.dataset.label || ''
            };

            // Clone the template
            const template = document.getElementById('editTransactionModalTemplate');
            const modalElement = template.content.cloneNode(true);
            const modal = modalElement.querySelector('.editTransactionModal');
            const form = modal.querySelector('.editTransactionForm');

            // Fill form with transaction data
            form.querySelector('[name="id"]').value = transaction.id;
            form.querySelector('[name="date"]').value = transaction.date;
            form.querySelector('[name="amount"]').value = transaction.amount;
            form.querySelector('[name="type"]').value = transaction.type;

            // Set client selection
            const clientSelect = form.querySelector('[name="client"]');
            const clientOption = Array.from(clientSelect.options).find(
                opt => opt.value === transaction.client
            );

            if (clientOption) {
                clientOption.selected = true;
            } else {
                // If client doesn't exist in the list, add it
                const option = new Option(transaction.client, transaction.client, true, true);
                clientSelect.add(option);
            }

            // Set label if it exists
            if (transaction.label) {
                form.querySelector('[name="label"]').value = transaction.label;
            }

            // Set label field
            const labelInput = form.querySelector('[name="label"]');
            if (labelInput) {
                labelInput.value = transaction.label || '';
            }

            // Remove any existing submit event listeners to prevent duplicates
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);

            // Add new submit event listener
            newForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData(newForm);
                const data = {
                    id: formData.get('id'),
                    client: formData.get('client'),
                    date: formData.get('date'),
                    amount: parseFloat(formData.get('amount')),
                    type: formData.get('type'),
                    label: formData.get('label') || ''  // Ensure empty string if label is null
                };

                // Convert amount to negative if it's a credit
                if (data.type === 'credit') {
                    data.amount = -Math.abs(data.amount);
                } else {
                    data.amount = Math.abs(data.amount);
                }

                try {
                    const response = await fetch('?api=update', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });

                    const result = await response.json();

                    if (result.success) {
                        modal.close();
                        window.location.reload();
                    } else {
                        alert(result.message || 'Failed to update transaction');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An error occurred while updating the transaction');
                }
            });

            // Handle close button
            const closeButton = modal.querySelector('.editTransactionClose');
            closeButton.addEventListener('click', () => {
                modal.close();
                document.body.removeChild(modal);
            });

            // Show the modal
            document.body.appendChild(modal);
            modal.showModal();
        }

        // Set up event delegation for edit buttons
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('editTransactionBtn')) {
                e.preventDefault();
                setupEditTransaction(e.target);
            }
        });

        // Delete Transaction
        function deleteTransaction(button) {
            if (!confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
                return;
            }

            const id = button.getAttribute('data-id');
            if (!id) {
                console.error('Missing transaction ID');
                alert('Error: Missing transaction ID');
                return;
            }

            console.log('Deleting transaction with ID:', id);

            fetch(`?api&action=delete&id=${encodeURIComponent(id)}`, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Find and remove the transaction row
                    const transactionRow = button.closest('div.flex.justify-between.items-center');
                    if (transactionRow) {
                        transactionRow.remove();
                        // Optionally, you can show a success message or refresh the page
                        window.location.reload();
                    }
                } else {
                    alert(result.message || 'Failed to delete transaction');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the transaction');
            });
        }

        // Theme handling
        document.addEventListener('DOMContentLoaded', () => {
            const themeToggle = document.getElementById('themeToggle');
            const html = document.documentElement;
            
            // Check for saved theme preference or use system preference
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            const setDarkMode = (isDark) => {
                if (isDark) {
                    html.classList.add('dark');
                    document.documentElement.style.colorScheme = 'dark';
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                } else {
                    html.classList.remove('dark');
                    document.documentElement.style.colorScheme = 'light';
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                }
                // Dispatch event for any components that need to know about theme changes
                document.dispatchEvent(new CustomEvent('themeChange', { detail: { isDark } }));
            };

            // Initialize theme
            const isDark = savedTheme === 'dark' || (!savedTheme && prefersDark);
            setDarkMode(isDark);

            // Toggle theme on button click
            themeToggle.addEventListener('click', () => {
                const newIsDark = !html.classList.contains('dark');
                localStorage.setItem('theme', newIsDark ? 'dark' : 'light');
                setDarkMode(newIsDark);
            });

            // Listen for system theme changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                if (!localStorage.getItem('theme')) {
                    setDarkMode(e.matches);
                }
            });

            // Event delegation for delete and duplicate buttons
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('deleteTransactionBtn')) {
                    e.preventDefault();
                    deleteTransaction(e.target);
                } else if (e.target.classList.contains('duplicateTransactionBtn')) {
                    e.preventDefault();
                    duplicateTransaction(e.target);
                }
            });

            // Function to handle duplicate transaction
            function duplicateTransaction(button) {
                // Get transaction data from data attributes
                const transaction = {
                    client: button.dataset.client,
                    date: button.dataset.date,
                    amount: button.dataset.amount,
                    type: button.dataset.type,
                    label: button.dataset.label || ''
                };

                // Pre-fill the create transaction form
                const form = document.getElementById('createTransactionForm');

                // Set client (try to select existing client first)
                const clientSelect = form.querySelector('select[name="client"]');
                const clientInput = document.getElementById('newClientName');
                const clientTypeNew = document.getElementById('newClient');
                const clientTypeExisting = document.getElementById('selectClient');

                // Check if client exists in the dropdown
                const clientExists = Array.from(clientSelect.options).some(
                    option => option.value === transaction.client
                );

                if (clientExists) {
                    // Select existing client
                    clientTypeExisting.checked = true;
                    clientSelect.value = transaction.client;
                    clientSelect.disabled = false;
                    clientInput.style.display = 'none';
                    clientInput.required = false;
                } else {
                    // Use new client input
                    clientTypeNew.checked = true;
                    clientSelect.value = '';
                    clientSelect.disabled = true;
                    clientInput.style.display = 'block';
                    clientInput.required = true;
                    clientInput.value = transaction.client;
                }

                // Set other fields
                form.querySelector('input[name="date"]').value = transaction.date;
                form.querySelector('input[name="amount"]').value = Math.abs(transaction.amount);
                form.querySelector('select[name="type"]').value = transaction.type;
                form.querySelector('input[name="label"]').value = transaction.label;

                // Open the create transaction modal
                createModal.showModal();
            }
        });
    </script>
</body>
</html>
