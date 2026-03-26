<?php
/**
 * CINÉMA NOIR — PHP Backend
 * Handles review submissions, ratings storage, and data retrieval
 * 
 * File: api.php
 * Requires: PHP 7.4+, MySQL 5.7+ or SQLite3
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// DATABASE SETUP (SQLite — zero-config, swap for MySQL easily)
// ============================================================
function getDB(): PDO {
    $db = new PDO('sqlite:' . __DIR__ . '/cinema_noir.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create tables if they don't exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS movies (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            director    TEXT,
            year        INTEGER,
            genre       TEXT,
            synopsis    TEXT,
            poster_url  TEXT,
            trailer_url TEXT,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS reviews (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            movie_id    INTEGER NOT NULL,
            movie_title TEXT NOT NULL,
            author_name TEXT NOT NULL DEFAULT 'Anonymous',
            author_email TEXT,
            rating      INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
            score       INTEGER GENERATED ALWAYS AS (rating * 20) VIRTUAL,
            review_text TEXT NOT NULL,
            is_approved INTEGER DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (movie_id) REFERENCES movies(id)
        );

        CREATE TABLE IF NOT EXISTS contacts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name  TEXT NOT NULL,
            last_name   TEXT,
            email       TEXT NOT NULL,
            subject     TEXT,
            message     TEXT NOT NULL,
            is_read     INTEGER DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS ratings_aggregate (
            movie_id    INTEGER PRIMARY KEY,
            total_votes INTEGER DEFAULT 0,
            total_score INTEGER DEFAULT 0,
            avg_rating  REAL DEFAULT 0,
            FOREIGN KEY (movie_id) REFERENCES movies(id)
        );
    ");

    // Seed movies if empty
    $count = $db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
    if ((int)$count === 0) {
        seedMovies($db);
    }

    return $db;
}

function seedMovies(PDO $db): void {
    $movies = [
        [1, 'The Godfather',           'Francis Ford Coppola', 1972, 'Drama',   'The aging patriarch of an organized crime dynasty transfers control to his reluctant son.',                                     'https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=400', 'https://www.youtube.com/embed/sY1S34973zA'],
        [2, 'Blade Runner 2049',        'Denis Villeneuve',     2017, 'Sci-Fi',  'A young blade runner discovers a long-buried secret that leads him to track down former blade runner Rick Deckard.',          'https://images.unsplash.com/photo-1518929458119-e5bf444c30f4?w=400', 'https://www.youtube.com/embed/gCcx85zbxz4'],
        [3, 'Parasite',                 'Bong Joon-ho',         2019, 'Thriller','Greed and class discrimination threaten the newly formed symbiotic relationship between two families.',                         'https://images.unsplash.com/photo-1524985069026-dd778a71c7b4?w=400', 'https://www.youtube.com/embed/5xH0HfJHsaY'],
        [4, 'Dune: Part Two',           'Denis Villeneuve',     2024, 'Sci-Fi',  'Paul Atreides unites with Chani and the Fremen while seeking revenge against the conspirators who destroyed his family.',     'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400', 'https://www.youtube.com/embed/Way9Dexny3w'],
        [5, 'Mulholland Drive',         'David Lynch',          2001, 'Mystery', 'After a car wreck on Mulholland Drive renders a woman amnesiac, she and a Hollywood-hopeful search for answers.',            'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?w=400', 'https://www.youtube.com/embed/x5iMtEKDtbk'],
        [6, 'Oppenheimer',              'Christopher Nolan',    2023, 'Drama',   'The story of J. Robert Oppenheimer\'s role in the development of the atomic bomb during World War II.',                      'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=400', 'https://www.youtube.com/embed/uYPbbksJxIg'],
        [7, 'The Zone of Interest',     'Jonathan Glazer',      2023, 'Drama',   'The commandant of Auschwitz and his wife attempt to build a dream life for their family next to the camp.',                  'https://images.unsplash.com/photo-1500622944204-b135684e99fd?w=400', 'https://www.youtube.com/embed/MijPH3ixqQQ'],
        [8, 'Killers of the Flower Moon','Martin Scorsese',     2023, 'Drama',   'The Osage Indians were murdered systematically after oil was discovered on their land.',                                      'https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?w=400', 'https://www.youtube.com/embed/EP34Yoxs3FQ'],
    ];

    $stmt = $db->prepare("INSERT INTO movies (id, title, director, year, genre, synopsis, poster_url, trailer_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($movies as $m) {
        $stmt->execute($m);
        $db->exec("INSERT OR IGNORE INTO ratings_aggregate (movie_id, total_votes, total_score, avg_rating) VALUES ({$m[0]}, 0, 0, 0)");
    }
}

// ============================================================
// INPUT SANITIZATION
// ============================================================
function sanitize(string $input, int $maxLen = 500): string {
    return htmlspecialchars(strip_tags(trim(substr($input, 0, $maxLen))), ENT_QUOTES, 'UTF-8');
}

function validateEmail(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

// ============================================================
// RESPONSE HELPERS
// ============================================================
function jsonSuccess(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// ============================================================
// ROUTER
// ============================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Support JSON body
$jsonBody = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $jsonBody = json_decode($raw, true) ?? [];
    }
    $_POST = array_merge($_POST, $jsonBody);
}

try {
    $db = getDB();

    switch ($action) {

        // ── GET: All movies ──────────────────────────────────────────
        case 'get_movies':
            $genre  = sanitize($_GET['genre'] ?? '');
            $search = sanitize($_GET['search'] ?? '');

            $sql  = "SELECT m.*, COALESCE(r.avg_rating, 0) AS community_rating, COALESCE(r.total_votes, 0) AS vote_count FROM movies m LEFT JOIN ratings_aggregate r ON m.id = r.movie_id WHERE 1=1";
            $params = [];

            if ($genre)  { $sql .= " AND m.genre = ?";               $params[] = $genre; }
            if ($search) { $sql .= " AND m.title LIKE ?";            $params[] = "%$search%"; }

            $sql .= " ORDER BY COALESCE(r.avg_rating, 0) DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonSuccess(['movies' => $stmt->fetchAll()]);

        // ── GET: Single movie ────────────────────────────────────────
        case 'get_movie':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('Movie ID required');

            $stmt = $db->prepare("SELECT m.*, COALESCE(r.avg_rating, 0) AS community_rating, COALESCE(r.total_votes, 0) AS vote_count FROM movies m LEFT JOIN ratings_aggregate r ON m.id = r.movie_id WHERE m.id = ?");
            $stmt->execute([$id]);
            $movie = $stmt->fetch();

            if (!$movie) jsonError('Movie not found', 404);
            jsonSuccess(['movie' => $movie]);

        // ── POST: Submit review ──────────────────────────────────────
        case 'submit_review':
            if ($method !== 'POST') jsonError('POST required', 405);

            $movieId   = (int)($_POST['movie_id'] ?? 0);
            $movieTitle= sanitize($_POST['movie_title'] ?? '', 200);
            $author    = sanitize($_POST['author_name'] ?? 'Anonymous', 100);
            $email     = sanitize($_POST['author_email'] ?? '', 200);
            $rating    = (int)($_POST['rating'] ?? 0);
            $text      = sanitize($_POST['review_text'] ?? '', 2000);

            // Validate
            if (!$movieId)             jsonError('Movie ID is required');
            if ($rating < 1 || $rating > 5) jsonError('Rating must be 1–5');
            if (strlen($text) < 20)    jsonError('Review must be at least 20 characters');
            if ($email && !validateEmail($email)) jsonError('Invalid email address');

            // Check movie exists
            $check = $db->prepare("SELECT id, title FROM movies WHERE id = ?");
            $check->execute([$movieId]);
            $movie = $check->fetch();
            if (!$movie) jsonError('Movie not found', 404);

            $movieTitle = $movieTitle ?: $movie['title'];

            // Prevent spam: limit 3 reviews per IP per movie per day
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            // (In production, store IP hash in reviews table and check here)

            // Insert review
            $stmt = $db->prepare("INSERT INTO reviews (movie_id, movie_title, author_name, author_email, rating, review_text) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$movieId, $movieTitle, $author, $email, $rating, $text]);
            $reviewId = $db->lastInsertId();

            // Update aggregate ratings
            $db->prepare("INSERT INTO ratings_aggregate (movie_id, total_votes, total_score, avg_rating)
                          VALUES (?, 1, ?, ?)
                          ON CONFLICT(movie_id) DO UPDATE SET
                            total_votes = total_votes + 1,
                            total_score = total_score + excluded.total_score,
                            avg_rating  = ROUND((total_score + excluded.total_score) * 1.0 / (total_votes + 1), 2)")
                ->execute([$movieId, $rating, $rating / 5.0 * 5]);

            jsonSuccess([
                'review_id'  => $reviewId,
                'message'    => 'Review submitted successfully!',
                'score'      => $rating * 20,
            ], 201);

        // ── GET: Reviews for a movie (or all) ────────────────────────
        case 'get_reviews':
            $movieId = (int)($_GET['movie_id'] ?? 0);
            $limit   = min((int)($_GET['limit'] ?? 20), 100);
            $offset  = (int)($_GET['offset'] ?? 0);

            $sql    = "SELECT * FROM reviews WHERE is_approved = 1";
            $params = [];

            if ($movieId) { $sql .= " AND movie_id = ?"; $params[] = $movieId; }
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $reviews = $stmt->fetchAll();

            // Count total
            $countSql = "SELECT COUNT(*) FROM reviews WHERE is_approved = 1" . ($movieId ? " AND movie_id = $movieId" : "");
            $total = $db->query($countSql)->fetchColumn();

            jsonSuccess(['reviews' => $reviews, 'total' => (int)$total, 'limit' => $limit, 'offset' => $offset]);

        // ── GET: Top rated movies ────────────────────────────────────
        case 'get_top_rated':
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            $stmt  = $db->prepare("
                SELECT m.*, r.avg_rating AS community_rating, r.total_votes AS vote_count,
                       ROUND(r.avg_rating * 20) AS score
                FROM movies m
                JOIN ratings_aggregate r ON m.id = r.movie_id
                WHERE r.total_votes > 0
                ORDER BY r.avg_rating DESC, r.total_votes DESC
                LIMIT ?");
            $stmt->execute([$limit]);
            jsonSuccess(['movies' => $stmt->fetchAll()]);

        // ── POST: Contact form submission ────────────────────────────
        case 'submit_contact':
            if ($method !== 'POST') jsonError('POST required', 405);

            $first   = sanitize($_POST['first_name'] ?? '', 100);
            $last    = sanitize($_POST['last_name']  ?? '', 100);
            $email   = sanitize($_POST['email']      ?? '', 200);
            $subject = sanitize($_POST['subject']    ?? '', 200);
            $message = sanitize($_POST['message']    ?? '', 3000);

            if (!$first)              jsonError('First name is required');
            if (!$email)              jsonError('Email is required');
            if (!validateEmail($email)) jsonError('Invalid email address');
            if (strlen($message) < 10) jsonError('Message too short');

            $stmt = $db->prepare("INSERT INTO contacts (first_name, last_name, email, subject, message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$first, $last, $email, $subject, $message]);
            $contactId = $db->lastInsertId();

            // Optional: send email notification (configure your SMTP)
            // mail('admin@cinemanoir.com', "New Contact: $subject", $message, "From: $email");

            jsonSuccess(['contact_id' => $contactId, 'message' => "Message received! We'll be in touch."], 201);

        // ── GET: Stats / summary ─────────────────────────────────────
        case 'get_stats':
            $stats = [
                'total_movies'  => (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn(),
                'total_reviews' => (int)$db->query("SELECT COUNT(*) FROM reviews WHERE is_approved = 1")->fetchColumn(),
                'avg_site_rating' => (float)$db->query("SELECT ROUND(AVG(avg_rating), 2) FROM ratings_aggregate WHERE total_votes > 0")->fetchColumn(),
                'genres'        => $db->query("SELECT genre, COUNT(*) as count FROM movies GROUP BY genre ORDER BY count DESC")->fetchAll(),
            ];
            jsonSuccess($stats);

        // ── GET: Search movies ───────────────────────────────────────
        case 'search':
            $q = sanitize($_GET['q'] ?? '', 100);
            if (strlen($q) < 2) jsonError('Query too short');

            $stmt = $db->prepare("SELECT id, title, director, year, genre FROM movies WHERE title LIKE ? OR director LIKE ? ORDER BY title LIMIT 10");
            $stmt->execute(["%$q%", "%$q%"]);
            jsonSuccess(['results' => $stmt->fetchAll()]);

        default:
            jsonError('Unknown action. Valid actions: get_movies, get_movie, submit_review, get_reviews, get_top_rated, submit_contact, get_stats, search', 404);
    }

} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    jsonError('Database error. Please try again.', 500);
} catch (Exception $e) {
    error_log('App Error: ' . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}

/*
═══════════════════════════════════════════════════════
  USAGE EXAMPLES
═══════════════════════════════════════════════════════

1. GET all movies
   GET /api.php?action=get_movies

2. GET movies by genre
   GET /api.php?action=get_movies&genre=Drama

3. GET single movie
   GET /api.php?action=get_movie&id=1

4. POST a review
   POST /api.php?action=submit_review
   Body (JSON or form-data):
   {
     "movie_id": 1,
     "author_name": "Jane Doe",
     "author_email": "jane@example.com",
     "rating": 5,
     "review_text": "An absolute masterpiece of American cinema..."
   }

5. GET reviews
   GET /api.php?action=get_reviews
   GET /api.php?action=get_reviews&movie_id=1&limit=10&offset=0

6. GET top rated
   GET /api.php?action=get_top_rated&limit=10

7. POST contact form
   POST /api.php?action=submit_contact
   Body: { "first_name": "John", "email": "john@example.com", "message": "Hello!" }

8. GET site stats
   GET /api.php?action=get_stats

9. Search movies
   GET /api.php?action=search&q=nolan

═══════════════════════════════════════════════════════
  MYSQL MIGRATION
═══════════════════════════════════════════════════════
  Replace getDB() SQLite DSN with:
  $db = new PDO(
      'mysql:host=localhost;dbname=cinema_noir;charset=utf8mb4',
      'db_user',
      'db_password'
  );
  Then run the CREATE TABLE statements in your MySQL client.
  Replace "ON CONFLICT" with "ON DUPLICATE KEY UPDATE" for MySQL.
═══════════════════════════════════════════════════════
*/
