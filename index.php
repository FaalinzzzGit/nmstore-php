<?php
session_start(); // Mulai sesi PHP untuk autentikasi

// --- Konfigurasi ---
define('DATABASE_FILE_PATH', __DIR__ . '/data/products.sqlite');
define('UPLOAD_BASE_DIR', __DIR__ . '/public/images/products/'); // Folder khusus untuk media produk
define('ADMIN_USERNAME', 'adminnmstore'); // Username default
define('ADMIN_PASSWORD', 'n4HSrSfvHDKAnQNz'); // PASSWORD HARUS DIGANTI! Gunakan password yang kuat!

// --- Helper Functions ---

// Inisialisasi atau koneksi ke database SQLite
function getDbConnection() {
    try {
        $pdo = new PDO('sqlite:' . DATABASE_FILE_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Buat tabel jika belum ada
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price TEXT NOT NULL,
                description TEXT NOT NULL,
                media_urls TEXT DEFAULT '[]' -- Simpan sebagai JSON string dari array URL
            )
        ");
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        sendJsonResponse(['message' => 'Internal Server Error: Database issue.'], 500);
    }
}

// Mengambil semua produk dari database
function getProducts() {
    $db = getDbConnection();
    $stmt = $db->query("SELECT id, name, price, description, media_urls FROM products ORDER BY id DESC");
    $products = [];
    while ($row = $stmt->fetch()) {
        $row['mediaUrls'] = json_decode($row['media_urls'], true) ?: [];
        unset($row['media_urls']); // Hapus kolom asli setelah di-decode
        $products[] = $row;
    }
    return $products;
}

// Mengirim response JSON
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Melayani file HTML
function serveHtmlFile($filePath) {
    if (file_exists($filePath)) {
        header('Content-Type: text/html');
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo "404 Not Found";
        exit;
    }
}

// Cek apakah admin sudah login
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Fungsi untuk menghapus folder dan isinya (digunakan saat menghapus media produk)
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}


// --- Routing Logic ---

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Normalize URI: remove trailing slash and ensure leading slash
$requestUri = rtrim($requestUri, '/');
if (empty($requestUri)) {
    $requestUri = '/';
}

// API Routes
if (strpos($requestUri, '/api/') === 0) {

    // API untuk login admin
    if ($requestUri === '/api/admin/login') {
        if ($requestMethod === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $username = $input['username'] ?? null;
            $password = $input['password'] ?? null;

            if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
                $_SESSION['admin_logged_in'] = true; // Set sesi login
                sendJsonResponse(['success' => true, 'message' => 'Login berhasil!']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Username atau password salah.'], 401);
            }
        } else {
            sendJsonResponse(['message' => 'Method Not Allowed'], 405);
        }
    }
    // API untuk logout admin
    elseif ($requestUri === '/api/admin/logout') {
        if ($requestMethod === 'POST') {
            session_destroy(); // Hancurkan sesi
            sendJsonResponse(['success' => true, 'message' => 'Anda telah logout.']);
        } else {
            sendJsonResponse(['message' => 'Method Not Allowed'], 405);
        }
    }
    // API untuk cek autentikasi admin
    elseif ($requestUri === '/api/admin/check-auth') {
        if ($requestMethod === 'GET') {
            if (isAdminLoggedIn()) {
                sendJsonResponse(['authenticated' => true]);
            } else {
                sendJsonResponse(['authenticated' => false, 'message' => 'Unauthorized'], 401);
            }
        } else {
            sendJsonResponse(['message' => 'Method Not Allowed'], 405);
        }
    }
    // API Produk (memerlukan autentikasi admin untuk POST/DELETE)
    elseif ($requestUri === '/api/products') {
        if ($requestMethod === 'GET') {
            sendJsonResponse(getProducts());
        } elseif ($requestMethod === 'POST') {
            if (!isAdminLoggedIn()) {
                sendJsonResponse(['message' => 'Unauthorized'], 401);
            }

            $db = getDbConnection();
            $name = $_POST['name'] ?? null;
            $price = $_POST['price'] ?? null;
            $description = $_POST['description'] ?? null;

            if (!$name || !$price || !$description) {
                sendJsonResponse(['message' => 'Data produk tidak lengkap.'], 400);
            }

            $db->beginTransaction();
            try {
                // Masukkan produk terlebih dahulu untuk mendapatkan ID
                $stmt = $db->prepare("INSERT INTO products (name, price, description, media_urls) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $price, $description, '[]']); // media_urls akan diupdate nanti
                $newProductId = $db->lastInsertId();

                $mediaUrls = [];
                $productMediaDir = UPLOAD_BASE_DIR . $newProductId . '/';

                if (!is_dir(UPLOAD_BASE_DIR)) {
                    mkdir(UPLOAD_BASE_DIR, 0777, true); // Pastikan folder 'products' ada
                }
                if (!is_dir($productMediaDir)) {
                    mkdir($productMediaDir, 0777, true); // Buat folder untuk ID produk ini
                }

                if (isset($_FILES['productMedia'])) {
                    $allowedMimeTypes = [
                        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                        'video/mp4', 'video/webm', 'video/ogg'
                    ];
                    $maxFileSize = 10 * 1024 * 1024; // 10 MB

                    foreach ($_FILES['productMedia']['name'] as $key => $fileName) {
                        $tmp_name = $_FILES['productMedia']['tmp_name'][$key];
                        $error = $_FILES['productMedia']['error'][$key];
                        $fileSize = $_FILES['productMedia']['size'][$key];
                        $fileType = mime_content_type($tmp_name); // Dapatkan MIME type asli

                        if ($error === UPLOAD_ERR_OK) {
                            if ($fileSize > $maxFileSize) {
                                throw new Exception("Ukuran file '{$fileName}' terlalu besar. Maksimal 10MB.");
                            }
                            if (!in_array($fileType, $allowedMimeTypes)) {
                                throw new Exception("Tipe file '{$fileName}' tidak diizinkan. Hanya gambar dan video tertentu.");
                            }

                            // Hasilkan nama file yang unik dan aman
                            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                            $uniqueFileName = uniqid() . '.' . $extension; // uniqid() lebih baik
                            $targetPath = $productMediaDir . $uniqueFileName;

                            if (move_uploaded_file($tmp_name, $targetPath)) {
                                $mediaUrls[] = '/images/products/' . $newProductId . '/' . $uniqueFileName;
                            } else {
                                throw new Exception("Gagal memindahkan file '{$fileName}'.");
                            }
                        } else {
                            throw new Exception("Error upload file '{$fileName}': " . $error);
                        }
                    }
                }

                // Update URL media di database
                $stmt = $db->prepare("UPDATE products SET media_urls = ? WHERE id = ?");
                $stmt->execute([json_encode($mediaUrls), $newProductId]);

                $db->commit();
                sendJsonResponse(['id' => $newProductId, 'name' => $name, 'price' => $price, 'description' => $description, 'mediaUrls' => $mediaUrls], 201);

            } catch (Exception $e) {
                $db->rollBack();
                error_log("Error adding product: " . $e->getMessage());
                // Bersihkan folder media jika terjadi error setelah folder dibuat
                if (isset($productMediaDir) && is_dir($productMediaDir)) {
                    deleteDirectory($productMediaDir);
                }
                sendJsonResponse(['message' => 'Gagal menambahkan produk: ' . $e->getMessage()], 500);
            }
        } else {
            sendJsonResponse(['message' => 'Method Not Allowed'], 405);
        }
    } elseif (preg_match('/^\/api\/products\/(\d+)$/', $requestUri, $matches)) {
        $productId = (int)$matches[1];
        if ($requestMethod === 'DELETE') {
            if (!isAdminLoggedIn()) {
                sendJsonResponse(['message' => 'Unauthorized'], 401);
            }

            $db = getDbConnection();
            $db->beginTransaction();
            try {
                // Ambil media URLs sebelum menghapus dari DB
                $stmt = $db->prepare("SELECT media_urls FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                $mediaToDelete = [];
                if ($product && $product['media_urls']) {
                    $mediaToDelete = json_decode($product['media_urls'], true);
                }

                $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                $result = $stmt->execute([$productId]);

                if ($result) {
                    $db->commit();

                    // Hapus folder media produk dan semua isinya
                    $productMediaDir = UPLOAD_BASE_DIR . $productId . '/';
                    if (is_dir($productMediaDir)) {
                        deleteDirectory($productMediaDir);
                    }
                    sendJsonResponse(['message' => 'Produk dan media terkait berhasil dihapus.']);
                } else {
                    $db->rollBack();
                    sendJsonResponse(['message' => 'Produk tidak ditemukan atau gagal dihapus.'], 404);
                }
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Error deleting product: " . $e->getMessage());
                sendJsonResponse(['message' => 'Terjadi kesalahan saat menghapus produk: ' . $e->getMessage()], 500);
            }
        } else {
            sendJsonResponse(['message' => 'Method Not Allowed'], 405);
        }
    } else {
        sendJsonResponse(['message' => 'API Endpoint Not Found'], 404);
    }
}
// HTML Page Routes (tetap sama)
elseif ($requestUri === '/admin') {
    serveHtmlFile(__DIR__ . '/public/admin.html');
} elseif ($requestUri === '/admin-panel') {
    // Pastikan admin sudah login sebelum menampilkan admin-panel
    if (!isAdminLoggedIn()) {
        header('Location: /admin'); // Redirect ke halaman login jika belum login
        exit;
    }
    serveHtmlFile(__DIR__ . '/public/admin-panel.html');
}
// Serve static files from public directory or default to index.html for root
elseif (file_exists(__DIR__ . '/public' . $requestUri) && !is_dir(__DIR__ . '/public' . $requestUri)) {
    // Serve the requested static file
    $filePath = __DIR__ . '/public' . $requestUri;
    $mimeType = mime_content_type($filePath);
    header('Content-Type: ' . $mimeType);
    readfile($filePath);
    exit;
} elseif ($requestUri === '/') {
    serveHtmlFile(__DIR__ . '/public/index.html');
}
// If no route matches, return 404
else {
    http_response_code(404);
    echo "404 Not Found";
}
?>